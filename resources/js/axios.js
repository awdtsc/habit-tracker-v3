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
// ------------------------------------------------------------
api.interceptors.response.use(
  (res) => res,
  async (error) => {
    const status = error?.response?.status ?? 0

    if (status === 401) {
      console.warn('[axios] 401 → redirect to /login')
      window.location.href = '/login'
    }

    return Promise.reject(error)
  }
)

export default api