// resources/js/router/index.js

import { createRouter, createWebHistory } from 'vue-router'
import axios from '@/axios'

/* -------------------------------------------------------
 * SPA 内部で保持する「ログイン状態」
 * Pinia の代わりに、最軽量の擬似ストアとして運用
 * ----------------------------------------------------- */
let cachedUser = null
let checkedOnce = false

/**
 * 外部（Login.vue / Logout.vue）から呼べるように export
 * → logout 時に確実にセッション状態をリセットする
 */
export function clearAuthState() {
  cachedUser = null
  checkedOnce = false
}

/**
 * SPA 起動時の一度だけ /api/user を叩く
 */
async function fetchUserOnce() {
  if (checkedOnce) return cachedUser

  try {
    const res = await axios.get('/api/user')
    cachedUser = res.data
  } catch {
    cachedUser = null
  }

  checkedOnce = true
  return cachedUser
}

/* -------------------------------------------------------
 * Routes
 * ----------------------------------------------------- */
const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../Pages/Auth/Login.vue'),
    meta: { public: true },
  },

  {
    path: '/',
    redirect: '/today',
  },

  {
    path: '/today',
    name: 'today',
    component: () => import('../Pages/Today.vue'),
    meta: { requiresAuth: true },
  },

  {
    path: '/week',
    name: 'week',
    component: () => import('../Pages/Weekly.vue'),
    meta: { requiresAuth: true },
  },

  {
    path: '/logout',
    name: 'logout',
    component: () => import('../Pages/Auth/Logout.vue'),
    meta: { requiresAuth: true },
  },

  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('../Pages/NotFound.vue'),
  },

  {
    path: '/register',
    name: 'register',
    component: () => import('../Pages/Auth/Register.vue'),
    meta: { public: true },
  }
]

/* -------------------------------------------------------
 * Router Instance
 * ----------------------------------------------------- */
const router = createRouter({
  history: createWebHistory(),
  routes,
})

/* -------------------------------------------------------
 * Auth Guard
 * ----------------------------------------------------- */
router.beforeEach(async (to) => {
  // 公開ページはそのまま通す
  if (to.meta.public) return true

  // 認証が必要なページは状態チェック
  const user = await fetchUserOnce()

  if (user) return true

  // 認証されていなければ login へ
  return { name: 'login' }
})

export default router