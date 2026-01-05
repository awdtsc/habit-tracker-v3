// resources/js/router/index.js
//------------------------------------------------------------
// Vue Router（Sanctum + SPA）— 高速 + 安全（/api/user TTL）
//------------------------------------------------------------

import { createRouter, createWebHistory } from "vue-router";
import {
    getUser,
    getUserCacheAgeMs,
    USER_TTL_MS,
    isAuthUnknown,
} from "@/state/authUserCache";

// ★ Today/Week eager
import TodayPage from "@/Pages/Today.vue";
import WeeklyPage from "@/Pages/Weekly.vue";

// ------------------------------------------------------------
//  Routes
// ------------------------------------------------------------
const routes = [
    {
        path: "/login",
        name: "login",
        component: () => import("../Pages/Auth/Login.vue"),
        meta: { public: true },
    },
    {
        path: "/register",
        name: "register",
        component: () => import("../Pages/Auth/Register.vue"),
        meta: { public: true },
    },

    { path: "/", redirect: "/today" },

    {
        path: "/today",
        name: "today",
        component: TodayPage,
        meta: { requiresAuth: true },
    },
    {
        path: "/week",
        name: "week",
        component: WeeklyPage,
        meta: { requiresAuth: true },
    },

    {
        path: "/habits/new",
        name: "habits.new",
        component: () => import("../Pages/Habits/New.vue"),
        meta: { requiresAuth: true },
    },

    {
        path: "/logout",
        name: "logout",
        component: () => import("../Pages/Auth/Logout.vue"),
        meta: { requiresAuth: true },
    },

    {
        path: "/:pathMatch(.*)*",
        name: "not-found",
        component: () => import("../Pages/NotFound.vue"),
    },
];

// ------------------------------------------------------------
//  Router
// ------------------------------------------------------------
const router = createRouter({
    history: createWebHistory(),
    routes,
});

// ------------------------------------------------------------
//  Auth Guard
// ------------------------------------------------------------
// - TTL内は即OK（爆速）
// - TTL切れたら /api/user を await
// - /api/user がネットワーク/5xx等で失敗した場合は AUTH_UNKNOWN を返す
//   → 誤爆ログアウトを避けるため、ここでは通す（本当に切れていれば後続APIが401になる）
// ------------------------------------------------------------
router.beforeEach(async (to) => {
    if (to.meta.public) return true;

    const user = await getUser({ force: false });

    if (isAuthUnknown(user)) {
        // ネットワーク不調など：誤爆ログアウトを避けるため通す
        return true;
    }

    if (user) return true;

    return {
        name: "login",
        query: { redirect: to.fullPath },
    };
});

// ------------------------------------------------------------
//  背景でサイレント再検証（安全性）
// ------------------------------------------------------------
// - 遷移は止めない
// - TTLの半分を過ぎたら裏で更新
router.afterEach((to) => {
    if (to.meta.public) return;

    const age = getUserCacheAgeMs();
    if (age > USER_TTL_MS / 2) {
        getUser({ force: true }).catch(() => {});
    }
});

export default router;
