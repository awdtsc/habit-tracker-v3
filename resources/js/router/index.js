// resources/js/router/index.js

import { createRouter, createWebHistory } from 'vue-router'
import axios from '@/axios'

const routes = [
  /* -------------------------------------------------------
   * Public Routes
   * ----------------------------------------------------- */
  {
    path: '/login',
    name: 'login',
    component: () => import('../Pages/Auth/Login.vue'),
  },

  /* -------------------------------------------------------
   * SPA Root → Today へリダイレクト
   * ----------------------------------------------------- */
  {
    path: '/',
    redirect: '/today',
  },

  /* -------------------------------------------------------
   * Auth Required Pages
   * ----------------------------------------------------- */
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

  /* -------------------------------------------------------
   * Not Found
   * ----------------------------------------------------- */
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('../Pages/NotFound.vue'),
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

/* -------------------------------------------------------
 * Auth Guard (Sanctum)
 * /api/user が 401 → login へリダイレクト
 * ----------------------------------------------------- */
router.beforeEach(async (to) => {
  if (!to.meta.requiresAuth) return true

  try {
    await axios.get('/api/user', { withCredentials: true })
    return true
  } catch {
    return { name: 'login' }
  }
})

export default router