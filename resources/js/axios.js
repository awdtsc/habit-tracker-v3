// resources/js/axios.js
//------------------------------------------------------------
// Axios 設定（Laravel Sanctum + Cookie 認証用：安全版 v2）
//------------------------------------------------------------

import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000',
  withCredentials: true,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'application/json',
  },
});

// ------------------------------------------------------------
// CSRF 初期化フラグ
// ------------------------------------------------------------
let csrfInitialized = false;

// ------------------------------------------------------------
// 生 axios で /sanctum/csrf-cookie を取得
// （失敗したら即座に reject して後続処理を止める）
// ------------------------------------------------------------
export async function ensureCsrfCookie() {
  if (csrfInitialized) return;

  const raw = axios.create({
    baseURL: api.defaults.baseURL,
    withCredentials: true,
  });

  try {
    await raw.get('/sanctum/csrf-cookie');
    csrfInitialized = true;
    console.info('[axios] CSRF cookie initialized');
  } catch (e) {
    console.error('[axios] CSRF cookie FAILED to initialize');
    throw e; // ← ここが最重要（失敗を握りつぶさない）
  }
}

// ------------------------------------------------------------
// Request Interceptor
// ------------------------------------------------------------
api.interceptors.request.use(async (config) => {
  if (!csrfInitialized && !config._skipCsrfInit) {
    await ensureCsrfCookie(); // ← 失敗したら即停止
  }
  return config;
});

// ------------------------------------------------------------
// Response Interceptor：419 → GET のみ1回だけ retry
// ------------------------------------------------------------
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error?.response?.status;
    const config = error.config;

    // Retry は GET のみ許可（書き込み系は絶対禁止）
    const isSafeMethod = config.method?.toUpperCase() === 'GET';

    if (status === 419 && isSafeMethod && !config._retried) {
      console.warn('[axios] 419 → refreshing CSRF (GET only)');

      csrfInitialized = false;
      config._retried = true;

      await ensureCsrfCookie();
      return api.request(config);
    }

    throw error;
  }
);

export default api;
