// resources/js/router/index.js
import { createRouter, createWebHistory } from 'vue-router'
import axios from '@/axios'

/* ======================================================
 *  認証状態の唯一のソース /api/user
 * ====================================================== */
async function getAuthUser() {
  try {
    const res = await axios.get('/api/user')
    return res.data
  } catch {
    return null
  }
}

/* ======================================================
 *  Routes
 * ====================================================== */
const routes = [
  { path: '/login',    name: 'login',    component: () => import('../Pages/Auth/Login.vue'),    meta: { public: true } },
  { path: '/register', name: 'register', component: () => import('../Pages/Auth/Register.vue'), meta: { public: true } },

  { path: '/', redirect: '/today' },

  { path: '/today', name: 'today', component: () => import('../Pages/Today.vue'),   meta: { requiresAuth: true } },
  { path: '/week',  name: 'week',  component: () => import('../Pages/Weekly.vue'),  meta: { requiresAuth: true } },

  { path: '/logout', name: 'logout', component: () => import('../Pages/Auth/Logout.vue'), meta: { requiresAuth: true } },

  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../Pages/NotFound.vue') },
]

/* ======================================================
 *  Router
 * ====================================================== */
const router = createRouter({
  history: createWebHistory(),
  routes,
})

/* ======================================================
 *  Auth Guard：Sanctum SPA の最強・最安定版
 * ====================================================== */
router.beforeEach(async (to) => {
  // 公開ページ → 通過
  if (to.meta.public) return true

  // 認証が必要 → 毎回 /api/user を確認する
  const user = await getAuthUser()

  if (user) return true

  // 未認証 → login へ
  return {
    name: 'login',
    query: { redirect: to.fullPath },
  }
})

export default router