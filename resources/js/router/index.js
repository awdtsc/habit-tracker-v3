// resources/js/router/index.js
//------------------------------------------------------------
// Vue Router（Cookie + Bearer 共存）— 高速 + 安全（/api/v1/auth/me TTL）
//
// ポイント:
// - beforeEach: 認証が必要なページだけ判定
// - AUTH_UNKNOWN(ネットワーク/5xx等)は誤爆ログアウトを避けて通す
// - /me 連打を抑制（hasConfirmedAuthState + getUserCacheAgeMs）
//
// M1: Open-redirect 対策:
// - login/register の redirect query は「内部パスのみ」許可
// - 不正なら query を掃除してから画面表示
// - guard が付与する redirect も内部パスに正規化
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

// ★ 共通 sanitizer
import { safeRedirect } from "@/utils/safeRedirect";

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

    // ★通知購読ページ（Vueで完結）
    {
        path: "/settings/notifications",
        name: "settings.notifications",
        component: () => import("../Pages/Settings/Notifications.vue"),
        meta: { requiresAuth: true },
    },

    {
        path: "/logout",
        name: "logout",
        component: () => import("../Pages/Auth/Logout.vue"),
        meta: { public: true }, // logoutはpublic
    },

    {
        path: "/:pathMatch(.*)*",
        name: "not-found",
        component: () => import("../Pages/NotFound.vue"),
        meta: { public: true },
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
router.beforeEach(async (to) => {
    // --------------------------------------------------------
    // M1: login/register の redirect query を先に掃除
    // --------------------------------------------------------
    if (to.name === "login" || to.name === "register") {
        const raw = to.query?.redirect;
        if (raw != null) {
            const safe = safeRedirect(String(raw), null);
            if (!safe) {
                // 不正redirectは削除（無限ループ回避のためreplace）
                return { name: to.name, query: {}, replace: true };
            }
        }
    }

    // --------------------------------------------------------
    // public route
    // --------------------------------------------------------
    if (to.meta.public) {
        // ログイン済みで login/register に来たら追い返す（体験改善）
        // ただし /login 上で /me を強制fetchすると「ログアウト直後の401」が目立つので、
        // キャッシュがある時だけ追い返す（ノイズ削減）
        if (to.name === "login" || to.name === "register") {
            const cached = getCachedUser();
            // AUTH_UNKNOWN(Symbol) を truthy 扱いして誤爆させない
            if (cached && !isAuthUnknown(cached)) return { name: "today" };
        }
        return true;
    }

    // 認証不要ルート（保険）
    if (!to.meta.requiresAuth) return true;

    // /me 連打抑制
    if (!hasConfirmedAuthState() && getUserCacheAgeMs() < USER_TTL_MS) {
        return true;
    }

    const user = await getUser({ force: false });

    if (isAuthUnknown(user)) return true;
    if (user) return true;

    const redirect = safeRedirect(to.fullPath, "/today");

    return {
        name: "login",
        query: { redirect },
    };
});

// ------------------------------------------------------------
//  背景でサイレント再検証（安全性）
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
