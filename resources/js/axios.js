// resources/js/axios.js
//------------------------------------------------------------
// Axios 設定（Laravel Sanctum + Cookie 認証用）
//------------------------------------------------------------

import axios from 'axios';

/**
 * フロント：localhost:5173
 * バック： localhost:8000
 *
 * Cookie 認証を有効化するには必ず:
 *   withCredentials: true
 * を設定する必要がある。
 */

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000',
  withCredentials: true,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'application/json',
  },
});

/**
 * CSRF Cookie 初期化フラグ
 */
let csrfInitialized = false;

/**
 * ------------------------------------------------------------
 * ⭐ ensureCsrfCookie()
 * 生（raw）axios で /sanctum/csrf-cookie を取得する
 * ------------------------------------------------------------
 * これが今回の改善の中で最も重要。
 */
export async function ensureCsrfCookie() {
  if (csrfInitialized) return;

  // interceptor の影響を受けない “生 axios”
  const rawClient = axios.create({
    baseURL: api.defaults.baseURL,
    withCredentials: true,
  });

  await rawClient.get('/sanctum/csrf-cookie');
  csrfInitialized = true;

  console.info('[axios] CSRF cookie initialized');
}

/**
 * ------------------------------------------------------------
 * Request Interceptor
 * すべてのリクエスト前に CSRF 初期化（ensureCsrfCookie）
 * ------------------------------------------------------------
 */
api.interceptors.request.use(async (config) => {
  // CSRF 取得をスキップしたい場合の内部フラグ
  if (config._skipCsrfInit) return config;

  if (!csrfInitialized) {
    try {
      await ensureCsrfCookie();
    } catch (e) {
      console.error('[axios] CSRF init failed', e);
    }
  }
  return config;
});

/**
 * ------------------------------------------------------------
 * Response Interceptor
 * 419（CSRF Token Mismatch）なら再初期化してリトライ
 * ------------------------------------------------------------
 */
api.interceptors.response.use(
  (response) => response,

  async (error) => {
    const status = error?.response?.status;

    if (status === 419) {
      console.warn('[axios] 419 detected — refreshing CSRF cookie');

      csrfInitialized = false;
      await ensureCsrfCookie();

      return api.request(error.config);
    }

    throw error;
  }
);

export default api;