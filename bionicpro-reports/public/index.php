<?php

declare(strict_types=1);

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

// ─────────────────────────────────────────────────────────────────────────────
// GET /reports
//
// Stateless endpoint. Поток:
//   1. Достать Bearer-токен из Authorization header
//   2. Верифицировать JWT через JWKS Keycloak → получить keycloak_id (sub)
//   3. Проверить etl_watermark в ClickHouse → получить дату последнего ETL
//   4. Сформировать S3-ключ: reports/{keycloak_id}/{etl_date}.json
//   5. Если объект есть в S3 — вернуть CDN URL (cache hit)
//   6. Если нет — запросить ClickHouse, сохранить в S3, вернуть CDN URL
// ─────────────────────────────────────────────────────────────────────────────
$app->get('/reports', function (Request $request, Response $response): Response {

    // ── 1. Bearer-токен ──────────────────────────────────────────────────────
    $authHeader = $request->getHeaderLine('Authorization');
    if (!str_starts_with($authHeader, 'Bearer ')) {
        $response->getBody()->write(json_encode(['error' => 'Missing or invalid Authorization header']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
    $jwt = substr($authHeader, 7);

    // ── 2. Верификация JWT через JWKS ────────────────────────────────────────
    $keycloakUrl = rtrim($_ENV['KEYCLOAK_URL'], '/');
    $realm       = $_ENV['KEYCLOAK_REALM'];
    $jwksUrl     = "{$keycloakUrl}/realms/{$realm}/protocol/openid-connect/certs";

    try {
        $http         = new HttpClient(['timeout' => 5]);
        $jwksResponse = $http->get($jwksUrl);
        $jwks         = json_decode((string) $jwksResponse->getBody(), true);
        $keys         = JWK::parseKeySet($jwks);
        $decoded      = JWT::decode($jwt, $keys);
    } catch (\Throwable) {
        $response->getBody()->write(json_encode(['error' => 'Invalid or expired token']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $keycloakId = $decoded->sub ?? null;
    if ($keycloakId === null) {
        $response->getBody()->write(json_encode(['error' => 'Token missing sub claim']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    // ── ClickHouse HTTP-клиент ───────────────────────────────────────────────
    $chHost = $_ENV['CLICKHOUSE_HOST'] ?? 'clickhouse';
    $chPort = $_ENV['CLICKHOUSE_PORT'] ?? '8123';
    $chDb   = $_ENV['CLICKHOUSE_DB']   ?? 'bionicpro';
    $chUser = $_ENV['CLICKHOUSE_USER'] ?? 'default';
    $chPass = $_ENV['CLICKHOUSE_PASSWORD'] ?? '';
    $ch     = new HttpClient([
        'base_uri' => "http://{$chHost}:{$chPort}",
        'timeout'  => 10,
        'auth'     => [$chUser, $chPass],
    ]);

    // ── 3. Проверка etl_watermark — данные готовы? ───────────────────────────
    $watermarkSql = "SELECT last_run FROM {$chDb}.etl_watermark LIMIT 1 FORMAT JSON";
    try {
        $wmResponse = $ch->get('/', ['query' => ['query' => $watermarkSql]]);
        $wmData     = json_decode((string) $wmResponse->getBody(), true);
    } catch (\Throwable) {
        $response->getBody()->write(json_encode(['error' => 'ClickHouse unavailable']));
        return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
    }

    if (empty($wmData['data'])) {
        $response->getBody()->write(json_encode(['error' => 'Данные ещё не подготовлены']));
        return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
    }

    // Дата последнего ETL-прогона — часть S3-ключа.
    // Новый ETL = новая дата = новый ключ = автоматическая инвалидация кеша CDN.
    $etlDate = substr($wmData['data'][0]['last_run'], 0, 10); // "2026-05-24"

    // ── 4. S3-ключ и CDN URL ─────────────────────────────────────────────────
    $s3Key  = "reports/{$keycloakId}/{$etlDate}.json";
    $cdnUrl = rtrim($_ENV['CDN_URL'] ?? 'http://localhost:8083', '/') . '/' . $s3Key;

    // ── S3-клиент (MinIO, path-style) ────────────────────────────────────────
    $s3     = new S3Client([
        'version'                 => 'latest',
        'region'                  => 'us-east-1',
        'endpoint'                => $_ENV['S3_ENDPOINT'] ?? 'http://minio:9000',
        'use_path_style_endpoint' => true,
        'credentials'             => [
            'key'    => $_ENV['S3_ACCESS_KEY'] ?? 'minioadmin',
            'secret' => $_ENV['S3_SECRET_KEY'] ?? 'minioadmin',
        ],
    ]);
    $bucket = $_ENV['S3_BUCKET'] ?? 'bionicpro-reports';

    // ── 5. Cache hit: отчёт уже есть в S3 ───────────────────────────────────
    try {
        $s3->headObject(['Bucket' => $bucket, 'Key' => $s3Key]);

        $response->getBody()->write(json_encode([
            'report_url' => $cdnUrl,
            'cached'     => true,
            'etl_date'   => $etlDate,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (AwsException $e) {
        if ($e->getStatusCode() !== 404) {
            $response->getBody()->write(json_encode(['error' => 'S3 error: ' . $e->getAwsErrorMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        // 404 → объекта нет, генерируем
    }

    // ── 6. Cache miss: запрашиваем ClickHouse ───────────────────────────────
    // keycloak_id берётся только из JWT (sub), не из query params.
    // Параметризованный запрос исключает SQL-инъекцию.
    $reportSql = "SELECT * FROM {$chDb}.report_by_user_v2 FINAL WHERE keycloak_id = {keycloak_id:String} FORMAT JSON";
    try {
        $rptResponse = $ch->get('/', [
            'query' => [
                'query'             => $reportSql,
                'param_keycloak_id' => $keycloakId,
            ],
        ]);
        $rptData = json_decode((string) $rptResponse->getBody(), true);
    } catch (\Throwable) {
        $response->getBody()->write(json_encode(['error' => 'Failed to fetch report data']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    $reportJson = json_encode($rptData['data'] ?? []);

    // ── Сохраняем в S3 ───────────────────────────────────────────────────────
    try {
        $s3->putObject([
            'Bucket'      => $bucket,
            'Key'         => $s3Key,
            'Body'        => $reportJson,
            'ContentType' => 'application/json',
        ]);
    } catch (AwsException $e) {
        $response->getBody()->write(json_encode(['error' => 'Failed to save to S3: ' . $e->getAwsErrorMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write(json_encode([
        'report_url' => $cdnUrl,
        'cached'     => false,
        'etl_date'   => $etlDate,
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
