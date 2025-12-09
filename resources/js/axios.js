// resources/js/axios.js
//------------------------------------------------------------
// Axios 設定（Laravel Sanctum + SPA）
//------------------------------------------------------------
import axios from 'axios'

const api = axios.create({
  baseURL: '/api',              // すべての API 呼び出しを /api に統一
  withCredentials: true,        // ← セッション維持に必須
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'application/json',
  },
})

// ------------------------------------------------------------
// CSRF 初期化（login / register の前に必須）
// ------------------------------------------------------------
// ★ baseURL=/api を無視し、絶対パスで叩くのが重要
export async function initCsrf() {
  await axios.get('/sanctum/csrf-cookie', {
    withCredentials: true,
  })
}

// ------------------------------------------------------------
// Response Interceptor（401 → /login）
// ★ /api/user のときだけログインへ誘導
// ------------------------------------------------------------
api.interceptors.response.use(
  (res) => res,
  async (error) => {
    const status = error?.response?.status ?? 0
    const url = error?.config?.url ?? ''

    if (status === 401) {
      // /api/user（認証ガード用 API）のときだけ遷移
      if (url.includes('/api/user')) {
        console.warn('[axios] 401 (user check) → redirect to /login')
        window.location.href = '/login'
        return
      }
      // それ以外は遷移させない（エラーだけ返す）
    }

    return Promise.reject(error)
  }
)

export default api