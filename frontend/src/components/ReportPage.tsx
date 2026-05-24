import React, { useState, useEffect, useCallback } from 'react';

const BFF_URL = 'http://localhost:8081';

interface User {
  sub: string | null;
  name: string | null;
  email: string | null;
  roles: string[];
}

interface ReportRow {
  keycloak_id: string;
  first_name: string;
  last_name: string;
  email: string;
  serial_number: string;
  issued_at: string;
  signal_type: string;
  avg_signal: number;
  signal_count: number;
  last_recorded: string;
  report_date: string;
}

interface ReportMeta {
  report_url: string;
  cached: boolean;
  etl_date: string;
}

const ReportPage: React.FC = () => {
  const [user, setUser] = useState<User | null>(null);
  const [authLoading, setAuthLoading] = useState(true);
  const [reportData, setReportData] = useState<ReportRow[] | null>(null);
  const [reportMeta, setReportMeta] = useState<ReportMeta | null>(null);
  const [reportLoading, setReportLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetch(`${BFF_URL}/auth/me`, { credentials: 'include' })
      .then(res => (res.ok ? res.json() : Promise.reject()))
      .then(data => setUser(data.user))
      .catch(() => setUser(null))
      .finally(() => setAuthLoading(false));
  }, []);

  const fetchReport = useCallback(async () => {
    try {
      setReportLoading(true);
      setError(null);

      // Шаг 1: получить CDN URL из BFF (bionicpro-auth → bionicpro-reports)
      const metaRes = await fetch(`${BFF_URL}/api/reports`, { credentials: 'include' });
      const meta: ReportMeta & { error?: string } = await metaRes.json();

      if (!metaRes.ok) {
        throw new Error(meta.error ?? `Ошибка ${metaRes.status}`);
      }

      setReportMeta(meta);

      // Шаг 2: загрузить JSON-файл напрямую с CDN (Nginx → MinIO)
      const cdnRes = await fetch(meta.report_url);
      if (!cdnRes.ok) {
        throw new Error(`CDN вернул ${cdnRes.status}`);
      }

      const data: ReportRow[] = await cdnRes.json();
      setReportData(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Ошибка загрузки отчёта');
    } finally {
      setReportLoading(false);
    }
  }, []);

  // Автозагрузка при входе
  useEffect(() => {
    if (user) fetchReport();
  }, [user, fetchReport]);

  const login = () => {
    fetch(`${BFF_URL}/auth/login`, { credentials: 'include' })
      .then(res => res.json())
      .then(data => { window.location.href = data.authorization_url; });
  };

  const logout = () => {
    fetch(`${BFF_URL}/auth/session`, { method: 'DELETE', credentials: 'include' })
      .finally(() => { setUser(null); setReportData(null); setReportMeta(null); });
  };

  if (authLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-gray-100">
        <p className="text-gray-600">Загрузка...</p>
      </div>
    );
  }

  if (!user) {
    return (
      <div className="flex flex-col items-center justify-center min-h-screen bg-gray-100">
        <button
          onClick={login}
          className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
        >
          Войти
        </button>
        {error && <p className="mt-4 text-red-600">{error}</p>}
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-6xl mx-auto bg-white rounded-lg shadow-md p-8">

        {/* Шапка */}
        <div className="flex justify-between items-center mb-6">
          <div>
            <h1 className="text-2xl font-bold">Отчёт по устройствам</h1>
            <p className="text-gray-500 text-sm mt-1">{user.name ?? user.email ?? user.sub}</p>
          </div>
          <div className="flex gap-3">
            <button
              onClick={fetchReport}
              disabled={reportLoading}
              className={`px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 ${
                reportLoading ? 'opacity-50 cursor-not-allowed' : ''
              }`}
            >
              {reportLoading ? 'Загрузка...' : 'Обновить'}
            </button>
            <button
              onClick={logout}
              className="px-3 py-2 text-sm bg-gray-500 text-white rounded hover:bg-gray-600"
            >
              Выйти
            </button>
          </div>
        </div>

        {/* CDN-метаданные */}
        {reportMeta && (
          <div className="mb-4 p-3 bg-gray-50 rounded text-xs text-gray-500 flex items-center gap-4">
            <span>
              <span className={`font-semibold ${reportMeta.cached ? 'text-green-600' : 'text-orange-500'}`}>
                {reportMeta.cached ? '● CDN cache hit' : '● CDN cache miss'}
              </span>
              {' '}— данные ETL от {reportMeta.etl_date}
            </span>
            <a
              href={reportMeta.report_url}
              target="_blank"
              rel="noreferrer"
              className="text-blue-500 hover:underline truncate max-w-xs"
            >
              {reportMeta.report_url}
            </a>
          </div>
        )}

        {/* Ошибка */}
        {error && (
          <div className="mb-6 p-4 bg-red-100 text-red-700 rounded">{error}</div>
        )}

        {/* Скелетон */}
        {reportLoading && reportData === null && (
          <div className="flex items-center justify-center py-16 text-gray-400">
            Загрузка данных...
          </div>
        )}

        {/* Нет данных */}
        {!reportLoading && reportData !== null && reportData.length === 0 && (
          <div className="flex items-center justify-center py-16 text-gray-400">
            Данных отчёта не найдено
          </div>
        )}

        {/* Таблица */}
        {reportData !== null && reportData.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left border-collapse">
              <thead>
                <tr className="bg-gray-50 border-b">
                  <th className="px-4 py-3 font-semibold text-gray-600">Пользователь</th>
                  <th className="px-4 py-3 font-semibold text-gray-600">Серийный №</th>
                  <th className="px-4 py-3 font-semibold text-gray-600">Выдан</th>
                  <th className="px-4 py-3 font-semibold text-gray-600">Тип сигнала</th>
                  <th className="px-4 py-3 font-semibold text-gray-600 text-right">Ср. значение</th>
                  <th className="px-4 py-3 font-semibold text-gray-600 text-right">Измерений</th>
                  <th className="px-4 py-3 font-semibold text-gray-600">Последнее измерение</th>
                  <th className="px-4 py-3 font-semibold text-gray-600">Дата отчёта</th>
                </tr>
              </thead>
              <tbody>
                {reportData.map((row, i) => (
                  <tr key={i} className="border-b hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <div>{row.first_name} {row.last_name}</div>
                      <div className="text-gray-400 text-xs">{row.email}</div>
                    </td>
                    <td className="px-4 py-3 font-mono text-xs">{row.serial_number}</td>
                    <td className="px-4 py-3 text-gray-600">{row.issued_at}</td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-1 bg-blue-50 text-blue-700 rounded text-xs">
                        {row.signal_type}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right font-mono">{row.avg_signal.toFixed(4)}</td>
                    <td className="px-4 py-3 text-right">{row.signal_count}</td>
                    <td className="px-4 py-3 text-gray-600 text-xs">{row.last_recorded}</td>
                    <td className="px-4 py-3 text-gray-600">{row.report_date}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

      </div>
    </div>
  );
};

export default ReportPage;
