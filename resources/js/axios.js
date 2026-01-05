// resources/js/axios.js
//------------------------------------------------------------
// Axios 設定（Laravel Sanctum + Cookie 認証用）
//
// おすすめA:
// - 401 を見たら user cache は即破棄（TTLの“通っちゃう”を潰す）
// - ただし即ログインへ飛ばさない（誤爆ログアウト防止）
// - /api/user を “素のaxios” で確認して、未認証が確定したら/loginへ
//------------------------------------------------------------

import axios from "axios";
import { clearUserCache, getUser } from "@/state/authUserCache";

const api = axios.create({
    baseURL: "/api",
    withCredentials: true,
    headers: {
        "X-Requested-With": "XMLHttpRequest",
        Accept: "application/json",
    },
});

// ------------------------------------------------------------
// CSRF 初期化
//   login / register の前に必須
// ------------------------------------------------------------
export async function initCsrf() {
    await axios.get("/sanctum/csrf-cookie", {
        withCredentials: true,
    });
}

// ------------------------------------------------------------
// Helper: error.config.url を pathname に正規化
// ------------------------------------------------------------
function normalizePath(url) {
    if (!url) return "";
    try {
        return new URL(url, window.location.origin).pathname;
    } catch {
        if (url.startsWith("/")) return url;
        return "/" + url;
    }
}

function isAuthPagePath(pathname) {
    return pathname === "/login" || pathname === "/register";
}

let redirecting = false;
let confirmInflight = null;

// “本当に未認証か？” を確定する（401誤爆対策）
async function confirmUnauthenticated(requestPath) {
    // /api/user 自身が401なら確定で未認証
    if (requestPath === "/api/user" || requestPath === "/user") return true;

    // 連打で確認が走らないよう共有
    if (confirmInflight) return confirmInflight;

    confirmInflight = (async () => {
        // ★ authUserCache は “素のaxios” で /api/user を叩くので interceptor ループしない
        const user = await getUser({ force: true });
        return !user;
    })().finally(() => {
        confirmInflight = null;
    });

    return confirmInflight;
}

// ------------------------------------------------------------
// Response Interceptor（401処理）
// ------------------------------------------------------------
api.interceptors.response.use(
    (res) => res,
    async (error) => {
        const status = error?.response?.status ?? 0;

        if (status === 401) {
            // ★どの401でも user cache を即破棄（安全側）
            try {
                clearUserCache();
            } catch {
                // ignore
            }

            const currentPath = window.location.pathname;
            const requestPath = normalizePath(error?.config?.url ?? "");

            // publicページでは飛ばさない（無限ループ回避）
            if (redirecting || isAuthPagePath(currentPath)) {
                return Promise.reject(error);
            }

            // ★未認証が確定した時だけ /login へ
            const unauth = await confirmUnauthenticated(requestPath);
            if (unauth) {
                redirecting = true;

                const redirect =
                    window.location.pathname + window.location.search;
                console.warn(
                    `[axios] 401 confirmed unauth (req:${requestPath}) → redirect to /login`
                );

                window.location.href =
                    "/login?redirect=" + encodeURIComponent(redirect);

                // ここに到達したら SPA は遷移するので、以後の処理は止める
                return;
            }
        }

        return Promise.reject(error);
    }
);

export default api;
