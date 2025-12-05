// resources/js/axios.js
//------------------------------------------------------------
// Axios 設定（Laravel Sanctum + Cookie 認証用）
//------------------------------------------------------------

import axios from 'axios'

/**
 * フロント：localhost:5173
 * バック： localhost:8000
 *
 * Cookie 認証を有効化するには必ず:
 *   withCredentials: true
 * を設定する必要がある。
 */

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000',
  withCredentials: true,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    'Accept': 'application/json',
  },
})

/**
 * すべてのリクエスト前に CSRF Cookie がなければ取得しておく
 *   → Vue SPA は Blade 経由で初期化されないため必要
 */
let csrfInitialized = false

api.interceptors.request.use(async (config) => {
  if (!csrfInitialized) {
    try {
      await api.get('/sanctum/csrf-cookie')
      csrfInitialized = true
      console.info('[axios] CSRF cookie initialized')
    } catch (e) {
      console.error('[axios] CSRF init failed', e)
    }
  }
  return config
})

/**
 * CSRF Token Mismatch (419) が出た場合は自動リトライ
 */
api.interceptors.response.use(
  (r) => r,
  async (error) => {
    const status = error?.response?.status

    if (status === 419) {
      console.warn('[axios] 419 detected — refreshing CSRF cookie')

      csrfInitialized = false
      await api.get('/sanctum/csrf-cookie')

      return api.request(error.config)
    }

    throw error
  }
)

export default api
