// resources/js/router/index.js
//------------------------------------------------------------
// Vue Router（Sanctum + SPA）— 高速 + 安全（401で即無効化）
//------------------------------------------------------------

import { createRouter, createWebHistory } from "vue-router";
import api from "@/axios";

// ★ Today/Week eager
import TodayPage from "@/Pages/Today.vue";
import WeeklyPage from "@/Pages/Weekly.vue";

// ------------------------------------------------------------
//  /api/user キャッシュ（TTL + inflight共有）
// ------------------------------------------------------------
// 安全寄りなら短め推奨：5〜15秒
const USER_TTL_MS = 10_000;

let cachedUser = null; // {id:...} or null
let cachedAt = 0;
let inflight = null;

async function fetchUserFromApi() {
    try {
        const res = await api.get("/user");
        const user = res.data && res.data.id ? res.data : null;

        cachedUser = user;
        cachedAt = Date.now();
        return user;
    } catch {
        cachedUser = null;
        cachedAt = Date.now();
        return null;
    }
}

async function getUser({ force = false } = {}) {
    const now = Date.now();

    // TTL内なら即返す（遷移を止めない）
    if (!force && cachedAt && now - cachedAt < USER_TTL_MS) {
        return cachedUser;
    }

    // inflight共有
    if (inflight) return inflight;

    inflight = fetchUserFromApi().finally(() => {
        inflight = null;
    });
    return inflight;
}

// ★ axios interceptor から呼べるよう export
export function clearUserCache() {
    cachedUser = null;
    cachedAt = 0;
    inflight = null;
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
// - TTL切れたら /user を await
router.beforeEach(async (to) => {
    if (to.meta.public) return true;

    const user = await getUser({ force: false });
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
// - TTLの半分を過ぎたら裏で強制更新
// - 401が出たら axios interceptor がキャッシュ破棄＆ログインへ戻す
router.afterEach((to) => {
    if (to.meta.public) return;

    const now = Date.now();
    const age = cachedAt ? now - cachedAt : Infinity;

    if (age > USER_TTL_MS / 2) {
        getUser({ force: true }).catch(() => {});
    }
});

export default router;
