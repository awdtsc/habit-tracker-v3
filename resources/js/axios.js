// resources/js/axios.js
//------------------------------------------------------------
// Axios 設定（Bearer Token 認証用）
//
// - Cookie/Session/CSRF に依存しない（withCredentials しない）
// - Authorization: Bearer <token> を自動付与
// - 401 は token を破棄して /login へ（必要なら redirect パラメータ付き）
//
// 重要:
// - 旧Cookie方式の互換のため initCsrf() は no-op で残す
//------------------------------------------------------------

import axios from "axios";

// -------------------------------
// Token storage
// -------------------------------
const TOKEN_KEY = "auth_token";

export function getAuthToken() {
    try {
        return localStorage.getItem(TOKEN_KEY);
    } catch {
        return null;
    }
}

export function setAuthToken(token) {
    try {
        if (!token) return;
        localStorage.setItem(TOKEN_KEY, token);
    } catch {
        // ignore
    }
}

export function clearAuthToken() {
    try {
        localStorage.removeItem(TOKEN_KEY);
    } catch {
        // ignore
    }
}

// -------------------------------
// API client
// -------------------------------
const api = axios.create({
    // Bearer統一なら v1 を基本にする
    baseURL: "/api/v1",
    withCredentials: false,
    headers: {
        "X-Requested-With": "XMLHttpRequest",
        Accept: "application/json",
    },
});

// 旧Cookie方式で必要だったが、Bearer方式では不要（互換のため残す）
export async function initCsrf() {
    return;
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

// ------------------------------------------------------------
// Request Interceptor（Bearer付与）
// ------------------------------------------------------------
api.interceptors.request.use(
    (config) => {
        const token = getAuthToken();
        if (token) {
            config.headers = config.headers ?? {};
            config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
    },
    (error) => Promise.reject(error)
);

// ------------------------------------------------------------
// Response Interceptor（401処理）
// ------------------------------------------------------------
api.interceptors.response.use(
    (res) => res,
    async (error) => {
        const status = error?.response?.status ?? 0;

        if (status === 401) {
            const currentPath = window.location.pathname;

            // authページでは飛ばさない（無限ループ回避）
            if (redirecting || isAuthPagePath(currentPath)) {
                return Promise.reject(error);
            }

            const requestPath = normalizePath(error?.config?.url ?? "");
            const token = getAuthToken();

            // token が無いなら「未ログイン」なので、静かに401を返す（画面側で判断させる）
            if (!token) {
                return Promise.reject(error);
            }

            // token があるのに401 => 期限切れ/失効/無効。tokenを破棄してログインへ。
            clearAuthToken();

            redirecting = true;
            const redirect = window.location.pathname + window.location.search;

            console.warn(
                `[axios] 401 (req:${requestPath}) -> clear token and redirect to /login`
            );

            window.location.href =
                "/login?redirect=" + encodeURIComponent(redirect);

            return;
        }

        return Promise.reject(error);
    }
);

export default api;
