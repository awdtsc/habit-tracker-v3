// resources/js/router/index.js
//------------------------------------------------------------
// Vue Router（Cookie + Bearer 共存）— 高速 + 安全（/api/v1/auth/me TTL）
//
// ポイント:
// - beforeEach: 認証が必要なページだけ判定
// - AUTH_UNKNOWN(ネットワーク/5xx等)は誤爆ログアウトを避けて通す
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
    getUserCacheAgeMs,
    USER_TTL_MS,
    isAuthUnknown,
} from "@/state/authUserCache";

// ★ Today/Week eager
import TodayPage from "@/Pages/Today.vue";
import WeeklyPage from "@/Pages/Weekly.vue";

// ------------------------------------------------------------
//  M1: redirect query sanitizer（内部パスのみ許可）
// ------------------------------------------------------------
function safeRedirect(raw, fallback = null) {
    if (typeof raw !== "string" || raw.length === 0) return fallback;

    // 過剰な長さは拒否（ログインリンク悪用/メモリ圧迫の保険）
    if (raw.length > 1024) return fallback;

    let v = raw;
    try {
        v = decodeURIComponent(raw);
    } catch {
        v = raw;
    }

    // 内部パスのみ
    if (!v.startsWith("/")) return fallback;

    // スキーム相対URL（//evil.com）拒否
    if (v.startsWith("//")) return fallback;

    // 改行等の混入拒否
    if (v.includes("\n") || v.includes("\r")) return fallback;

    // ★事故防止：login後の redirect に /logout を許可しない
    if (v === "/logout" || v.startsWith("/logout/")) return fallback;

    return v;
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
        // ※ AUTH_UNKNOWN(Symbol) を truthy 判定で誤爆させない
        if (to.name === "login" || to.name === "register") {
            const cached = getCachedUser();
            if (cached && !isAuthUnknown(cached)) {
                return { name: "today" };
            }
        }
        return true;
    }

    // 認証不要ルート（保険）
    if (!to.meta.requiresAuth) return true;

    // ここは getUser() が TTL + inflight + unknownバックオフ を持っているので
    // 余計な「/me 連打抑制」は不要（シンプルにする）
    const user = await getUser({ force: false });

    if (isAuthUnknown(user)) return true; // ネットワーク不調等は通す（誤爆防止）
    if (user) return true;

    const redirect = safeRedirect(to.fullPath, "/today");

    return {
        name: "login",
        query: { redirect },
    };
});

// ------------------------------------------------------------
//  背景でサイレント再検証（安全性）
//  - “ログイン済みっぽい時だけ” 更新（未ログイン/unknownで連打しない）
// ------------------------------------------------------------
router.afterEach((to) => {
    if (to.meta.public) return;
    if (!to.meta.requiresAuth) return;

    const cached = getCachedUser();
    if (!cached || isAuthUnknown(cached)) return;

    const age = getUserCacheAgeMs();
    if (age > USER_TTL_MS / 2) {
        getUser({ force: true }).catch(() => {});
    }
});

export default router;
