// resources/js/router/index.js
//------------------------------------------------------------
// Vue Router（Bearer Token）— 高速 + 安全（/api/v1/auth/me TTL）
//
// ポイント:
// - beforeEach: 認証が必要なページだけ判定
// - AUTH_UNKNOWN(ネットワーク/5xx等)は誤爆ログアウトを避けて通す
// - ただし AUTH_UNKNOWN 直後に /me を連打しない
//   → hasConfirmedAuthState() と getUserCacheAgeMs() で抑制
//
// 追加 (M1: Open-redirect 対策):
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

import { getAuthToken } from "@/axios";

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
// - AUTH_UNKNOWN直後に /me を連打しない（オフライン等）
// ------------------------------------------------------------
router.beforeEach(async (to) => {
    // --------------------------------------------------------
    // M1: login/register の redirect query を先に掃除
    // - 外部URL等が入っていたら query ごと落として同一画面へ
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

    // public route
    if (to.meta.public) {
        // ログイン済みで login/register に来たら追い返す（体験改善）
        if (to.name === "login" || to.name === "register") {
            // まず軽い判定：tokenが無ければ未ログイン
            const token = getAuthToken();
            if (!token) return true;

            // tokenがあるなら、キャッシュがあればそれで即判定
            const cached = getCachedUser();
            if (cached) return { name: "today" };

            // キャッシュが無い場合は、1回だけ強制確認してから判断（UX改善）
            const v = await getUser({ force: true });
            if (!isAuthUnknown(v) && v) {
                return { name: "today" };
            }
        }
        return true;
    }

    // 認証不要ルート（現状ほぼ無いけど保険）
    if (!to.meta.requiresAuth) return true;

    // ★ 「確定状態がまだ一度も取れていない」かつ
    // ★ 「直近で /me を確認しに行っている（=AUTH_UNKNOWN の可能性が高い）」
    // → beforeEach で /me を連打しないため、今回は叩かず通す
    if (!hasConfirmedAuthState() && getUserCacheAgeMs() < USER_TTL_MS) {
        return true;
    }

    const user = await getUser({ force: false });

    if (isAuthUnknown(user)) {
        // ネットワーク不調など：誤爆ログアウトを避けるため通す
        return true;
    }

    if (user) return true;

    // --------------------------------------------------------
    // 未ログイン → loginへ
    // redirect は内部パスのみ（念のため正規化）
    // --------------------------------------------------------
    const redirect = safeRedirect(to.fullPath, "/today");

    return {
        name: "login",
        query: { redirect },
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
