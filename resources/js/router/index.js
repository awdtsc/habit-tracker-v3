// resources/js/router/index.js
import { createRouter, createWebHistory } from 'vue-router'
import api from '@/axios'

/* ======================================================
 * /api/user → 返却形式 { user: {...} }
 * ====================================================== */
async function fetchUser() {
  try {
    const res = await api.get('/api/user', { _skipCsrfInit: true })
    return res.data.user ?? null   // ← ★ v3 正式対応
  } catch {
    return null
  }
}

/* ======================================================
 * Routes
 * ====================================================== */
const routes = [
  { path: '/login',    name: 'login',    component: () => import('../Pages/Auth/Login.vue'),    meta: { public: true } },
  { path: '/register', name: 'register', component: () => import('../Pages/Auth/Register.vue'), meta: { public: true } },

  { path: '/', redirect: '/today' },

  { path: '/today',  name: 'today',  component: () => import('../Pages/Today.vue'),  meta: { requiresAuth: true } },
  { path: '/week',   name: 'week',   component: () => import('../Pages/Weekly.vue'), meta: { requiresAuth: true } },

  { path: '/logout', name: 'logout', component: () => import('../Pages/Auth/Logout.vue'), meta: { requiresAuth: true } },

  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../Pages/NotFound.vue') },
]

/* ======================================================
 * Router
 * ====================================================== */
const router = createRouter({
  history: createWebHistory(),
  routes,
})

/* ======================================================
 * Auth Guard（v3 最終仕様版）
 * ====================================================== */
router.beforeEach(async (to) => {
  if (to.meta.public) return true

  const user = await fetchUser()

  if (user) return true

  // 未認証 → Login に強制移動（axios 側の 401 と二重にならない）
  return { name: 'login', query: { redirect: to.fullPath } }
})

export default router