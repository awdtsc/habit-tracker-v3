// resources/js/router/index.js
//------------------------------------------------------------
// Vue Router（Sanctum + SPA）— 高速 + 安全（/api/user TTL）
//
// ポイント:
// - beforeEach: 認証が必要なページだけ判定
// - AUTH_UNKNOWN(ネットワーク/5xx等)は誤爆ログアウトを避けて通す
// - ただし AUTH_UNKNOWN 直後に beforeEach で /api/user を連打しない
//   → hasConfirmedAuthState() と getUserCacheAgeMs() で抑制
//------------------------------------------------------------

import { createRouter, createWebHistory } from "vue-router";
import {
    getUser,
    getCachedUser,
    hasConfirmedAuthState,
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
        meta: { public: true }, // ★ 404はpublic扱いの方が事故が少ない
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
// - publicは常に通す
// - requiresAuth のみチェック
// - AUTH_UNKNOWN は通す（誤爆ログアウト回避）
// - AUTH_UNKNOWN直後に /api/user を連打しない（オフライン等）
// ------------------------------------------------------------
router.beforeEach(async (to) => {
    // public route
    if (to.meta.public) {
        // ログイン済みで login/register に来たら追い返す（体験改善）
        if (to.name === "login" || to.name === "register") {
            const cached = getCachedUser();
            if (cached) return { name: "today" };
        }
        return true;
    }

    // 認証不要ルート（現状ほぼ無いけど保険）
    if (!to.meta.requiresAuth) return true;

    // ★ 「確定状態がまだ一度も取れていない」かつ
    // ★ 「直近で /api/user を確認しに行っている（=AUTH_UNKNOWN の可能性が高い）」
    // → beforeEach で /api/user を連打しないため、今回は叩かず通す
    if (!hasConfirmedAuthState() && getUserCacheAgeMs() < USER_TTL_MS) {
        return true;
    }

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
// - TTLの半分を過ぎたら裏で更新（ただしpublic/非requiresAuthは除外）
// ------------------------------------------------------------
router.afterEach((to) => {
    if (to.meta.public) return;
    if (!to.meta.requiresAuth) return;

    const age = getUserCacheAgeMs();
    if (age > USER_TTL_MS / 2) {
        getUser({ force: true }).catch(() => {});
    }
});

export default router;
