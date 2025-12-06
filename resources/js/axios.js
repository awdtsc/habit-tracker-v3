// resources/js/axios.js
//------------------------------------------------------------
// Axios 設定（Laravel Sanctum + Cookie 認証用：v3最終版）
//------------------------------------------------------------
import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000',
  withCredentials: true,
  headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
})

// ------------------------------------------------------------
// CSRF Cookie 初期化
// ------------------------------------------------------------
let csrfInitialized = false

async function ensureCsrfCookie() {
  if (csrfInitialized) return

  try {
    await axios.get('/sanctum/csrf-cookie', {
      baseURL: api.defaults.baseURL,
      withCredentials: true,
    })
    csrfInitialized = true
    console.info('[axios] CSRF cookie initialized')
  } catch (e) {
    console.error('[axios] CSRF cookie FAILED')
    throw e
  }
}

// ------------------------------------------------------------
// Request Interceptor
// ------------------------------------------------------------
api.interceptors.request.use(async (config) => {
  if (!csrfInitialized && !config._skipCsrfInit) {
    await ensureCsrfCookie()
  }
  return config
})

// ------------------------------------------------------------
// Response Interceptor（401 / 419）
// ------------------------------------------------------------
api.interceptors.response.use(
  (res) => res,

  async (error) => {
    const status = error?.response?.status
    const config = error.config

    // --- 419: GET のみ CSRF refresh → retry
    if (status === 419 && config.method?.toUpperCase() === 'GET' && !config._retried) {
      console.warn('[axios] 419 → CSRF refresh / retry')
      csrfInitialized = false
      config._retried = true
      await ensureCsrfCookie()
      return api.request(config)
    }

    // --- 401: 認証切れ → login へ強制遷移（SPAの標準挙動）
    if (status === 401) {
      console.warn('[axios] 401 Unauthorized → redirect to /login')

      try {
        const router = (await import('@/router/index.js')).default
        router.push('/login')
      } catch (e) {
        console.error('[axios] router push failed', e)
      }
    }

    throw error
  }
)

export default api