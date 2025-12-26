// resources/js/router/index.js
//------------------------------------------------------------
// Vue Router（Sanctum + SPA）— 安全版（Today/WeekはEager）
//------------------------------------------------------------

import { createRouter, createWebHistory } from "vue-router";
import api from "@/axios";

// ★ Today/Week は “初回切り替えラグ” を消すため eager import
import TodayPage from "@/Pages/Today.vue";
import WeeklyPage from "@/Pages/Weekly.vue";

// ------------------------------------------------------------
//  毎回 /api/user を確認する
// ------------------------------------------------------------
async function fetchUser() {
    try {
        const res = await api.get("/user");
        return res.data && res.data.id ? res.data : null;
    } catch {
        return null;
    }
}

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

    // ★ eager
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

    // ★ NEW: habit create form (SPA)
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
//  Router Instance
// ------------------------------------------------------------
const router = createRouter({
    history: createWebHistory(),
    routes,
});

// ------------------------------------------------------------
//  Auth Guard（キャッシュなし・完全安全版）
// ------------------------------------------------------------
router.beforeEach(async (to) => {
    if (to.meta.public) return true;

    const user = await fetchUser();
    if (user) return true;

    return {
        name: "login",
        query: { redirect: to.fullPath },
    };
});

export default router;
