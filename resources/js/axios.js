// resources/js/axios.js
//------------------------------------------------------------
// Axios 設定（Laravel Sanctum + Cookie 認証用）
//------------------------------------------------------------

import axios from 'axios';

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'application/json',
  },
});

// ------------------------------------------------------------
// CSRF 初期化
//   login / register の前に必須
//   ※ baseURL=/api を無視し、絶対パスで取得するのが重要
// ------------------------------------------------------------
export async function initCsrf() {
  await axios.get('/sanctum/csrf-cookie', {
    withCredentials: true,
  });
}

// ------------------------------------------------------------
// Response Interceptor（401 → /login）
//   ★ 特に /api/user のときのみ SPA をログインへ誘導する
// ------------------------------------------------------------
api.interceptors.response.use(
  (res) => res,
  async (error) => {
    const status = error?.response?.status ?? 0;
    const url = error?.config?.url ?? '';

    // ------------------------------------------------------------
    // baseURL = /api の場合
    //   config.url は「/user」「/habits」「user」など相対 URL に化ける
    //   → URL(url, origin) で絶対パス化し、pathname を抽出する
    // ------------------------------------------------------------
    let path = url;
    try {
      path = new URL(url, window.location.origin).pathname;
    } catch (e) {
      // URL 化に失敗してもそのまま path を使う
    }

    if (status === 401) {
      // "認証切れチェック API" にだけ redirect を許可する
      if (path === '/api/user' || path === '/user') {
        console.warn('[axios] 401 (user check) → redirect to /login');
        window.location.href = '/login';
        return;
      }
      // 他は redirect せず、エラーだけ返す
    }

    return Promise.reject(error);
  }
);

export default api;