<?php

declare(strict_types=1);

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
//   2. Верифицировать JWT через JWKS Keycloak
//   3. Получить keycloak_id из claim sub
//   4. Проверить etl_watermark в ClickHouse — данные готовы?
//   5. Вернуть строки report_by_user для этого пользователя
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

    // ── 3. keycloak_id из sub ────────────────────────────────────────────────
    $keycloakId = $decoded->sub ?? null;
    if ($keycloakId === null) {
        $response->getBody()->write(json_encode(['error' => 'Token missing sub claim']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $keycloakId = 'uuid-prothetic1';

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

    // ── 4. Проверка etl_watermark ────────────────────────────────────────────
    $watermarkSql = "SELECT last_run FROM {$chDb}.etl_watermark LIMIT 1 FORMAT JSON";
    try {
        $wmResponse = $ch->get('/', ['query' => ['query' => $watermarkSql]]);
        $wmData     = json_decode((string) $wmResponse->getBody(), true);
    } catch (\Throwable $e) {
        $response->getBody()->write(json_encode(['error' => 'ClickHouse ' . $e->getMessage()]));
        return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
    }

    if (empty($wmData['data'])) {
        $response->getBody()->write(json_encode(['error' => 'Данные ещё не подготовлены']));
        return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
    }

    // ── 5. Данные отчёта для пользователя ───────────────────────────────────
    // keycloak_id берётся только из JWT (claim sub), не из query params.
    // Параметризованный запрос через ClickHouse HTTP-интерфейс исключает SQL-инъекцию.
    $reportSql = "SELECT * FROM {$chDb}.report_by_user WHERE keycloak_id = {keycloak_id:String} FORMAT JSON";
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

    $response->getBody()->write(json_encode($rptData['data'] ?? []));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
