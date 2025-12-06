// resources/js/axios.js
//------------------------------------------------------------
import axiosLib from 'axios'

const api = axiosLib.create({
  baseURL: 'http://localhost:8000',
  withCredentials: true,
  headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
})

// ------------------------------------------------------------
// CSRF Cookie 初期化
// ------------------------------------------------------------
let csrfInitialized = false

async function ensureCsrfCookie() {
  if (csrfInitialized) return

  await axiosLib.get('/sanctum/csrf-cookie', {
    baseURL: api.defaults.baseURL,
    withCredentials: true,
  })

  csrfInitialized = true
  console.info('[axios] CSRF cookie initialized')
}

// ------------------------------------------------------------
// Interceptors
// ------------------------------------------------------------
api.interceptors.request.use(async (config) => {
  if (!csrfInitialized && !config._skipCsrfInit) {
    await ensureCsrfCookie()
  }
  return config
})

api.interceptors.response.use(
  (res) => res,

  async (error) => {
    const status = error?.response?.status
    const config = error.config

    if (status === 419 && config.method?.toUpperCase() === 'GET' && !config._retried) {
      csrfInitialized = false
      config._retried = true
      await ensureCsrfCookie()
      return api.request(config)
    }

    if (status === 401) {
      try {
        const router = (await import('@/router/index.js')).default
        router.push('/login')
      } catch {}
    }

    throw error
  }
)

export { ensureCsrfCookie }
export default api