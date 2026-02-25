// resources/js/axios.js
//------------------------------------------------------------
// Axios 設定（SPA: Cookie/Session を正、Mobile: Bearer token を別途運用）
//
// SPA（ブラウザ）:
// - Cookie/Session + CSRF を正とする（withCredentials: true）
// - initCsrf() で /sanctum/csrf-cookie を取得してPOST系を安定化
//
// Mobile（将来）:
// - Authorization: Bearer <token> を使う（Sanctum PAT）
// - token保存(get/set/clear)は残す（ただしSPAは使わない）
//
// 重要:
// - 401時の自動リダイレクトは「tokenがある時だけ」行う（モバイル想定）
//   SPA側はrouter/authUserCacheで制御する
//------------------------------------------------------------

import axios from "axios";

/**
 * Cookie(Session) 認証を有効にするフラグ（Vite .env）
 * - VITE_COOKIE_AUTH=true のときだけ Cookie 系の初期化を行う
 */
export const COOKIE_AUTH_ENABLED = import.meta.env.VITE_COOKIE_AUTH === "true";

// -------------------------------
// Token storage (Mobile / optional)
// -------------------------------
const TOKEN_KEY = "auth_token";

/**
 * localStorage -> sessionStorage へ一度だけ移行する（互換のため残す）
 */
function migrateLocalToSessionOnce() {
    try {
        const sessionToken = sessionStorage.getItem(TOKEN_KEY);
        if (sessionToken) return;

        const localToken = localStorage.getItem(TOKEN_KEY);
        if (!localToken) return;

        sessionStorage.setItem(TOKEN_KEY, localToken);
        localStorage.removeItem(TOKEN_KEY);
    } catch {
        // ignore
    }
}

export function getAuthToken() {
    try {
        migrateLocalToSessionOnce();
        return sessionStorage.getItem(TOKEN_KEY);
    } catch {
        return null;
    }
}

export function setAuthToken(token) {
    try {
        if (!token) {
            sessionStorage.removeItem(TOKEN_KEY);
            return;
        }
        sessionStorage.setItem(TOKEN_KEY, token);

        try {
            localStorage.removeItem(TOKEN_KEY);
        } catch {
            // ignore
        }
    } catch {
        // ignore
    }
}

export function clearAuthToken() {
    try {
        sessionStorage.removeItem(TOKEN_KEY);
        try {
            localStorage.removeItem(TOKEN_KEY);
        } catch {
            // ignore
        }
    } catch {
        // ignore
    }
}

// -------------------------------
// API client (SPA: Cookie is primary)
// -------------------------------
const api = axios.create({
    baseURL: "/api/v1",
    withCredentials: true, // ★SPAは常にCookieを送る（同一オリジン前提）
    headers: {
        "X-Requested-With": "XMLHttpRequest",
        Accept: "application/json",
    },
});

/**
 * CSRF cookie を取得（SPA起動時に1回呼ぶ）
 * - CookieモードOFFのときは何もしない（誤爆防止）
 */
export async function initCsrf() {
    if (!COOKIE_AUTH_ENABLED) return;

    await axios.get("/sanctum/csrf-cookie", {
        withCredentials: true,
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            Accept: "application/json",
        },
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

function isAuthApiPath(pathname) {
    return (
        pathname.startsWith("/api/v1/auth/") ||
        pathname.startsWith("/api/auth/")
    );
}

let redirecting = false;

// ------------------------------------------------------------
// Request Interceptor（Bearer付与：tokenがある場合のみ）
// - SPAはtokenを使わない想定。将来のMobile用途のために残す。
// ------------------------------------------------------------
api.interceptors.request.use(
    (config) => {
        const token = getAuthToken();
        if (token) {
            config.headers = config.headers ?? {};
            config.headers.Authorization = `Bearer ${token}`;
        } else if (config.headers && "Authorization" in config.headers) {
            delete config.headers.Authorization;
        }
        return config;
    },
    (error) => Promise.reject(error),
);

// ------------------------------------------------------------
// Response Interceptor（401処理）
// - tokenがある場合のみ「token失効→/loginへ」
// - tokenが無い場合は静かに返す（SPAはrouter側で制御）
// ------------------------------------------------------------
api.interceptors.response.use(
    (res) => res,
    async (error) => {
        const status = error?.response?.status ?? 0;

        if (status === 401) {
            const currentPath = window.location.pathname;

            if (redirecting || isAuthPagePath(currentPath)) {
                return Promise.reject(error);
            }

            const requestPath = normalizePath(error?.config?.url ?? "");

            if (isAuthApiPath(requestPath)) {
                return Promise.reject(error);
            }

            const token = getAuthToken();

            // token無し（SPA）なら、ここでは何もしない
            if (!token) {
                return Promise.reject(error);
            }

            // tokenあり（モバイル想定）なら、失効扱いでログインへ
            clearAuthToken();

            const redirect = window.location.pathname + window.location.search;

            console.warn(
                `[axios] 401 (req:${requestPath}) -> clear token and redirect to /login`,
            );

            redirecting = true;
            window.location.href =
                "/login?redirect=" + encodeURIComponent(redirect);

            return Promise.reject(error);
        }

        return Promise.reject(error);
    },
);

// ============================================================================
// Cookie(Session) Auth helpers (SPA)
// ============================================================================

function assertCookieModeEnabled(fnName) {
    if (COOKIE_AUTH_ENABLED) return;
    // 設定ミスを “419で気づく” より “即気づく” ほうが安全
    const msg = `[axios] ${fnName}() was called but COOKIE_AUTH_ENABLED is false. Set VITE_COOKIE_AUTH=true for browser cookie auth.`;
    throw new Error(msg);
}

// ============================================================================
// Unified JSON helpers (2-β向け：Subscribe/Unsubscribe/Test をUIで扱いやすく）
// - 失敗時は「投げる」(throw) けど、throwする中身を整形して UI でそのまま表示可能にする
// ============================================================================

function buildApiError(err, fallbackMessage = "Request failed.") {
    const status = err?.response?.status ?? 0;
    const data = err?.response?.data;

    const message =
        (data && typeof data === "object" && (data.message || data.error)) ||
        (typeof data === "string" ? data : "") ||
        err?.message ||
        fallbackMessage;

    const code =
        data && typeof data === "object" && data.code
            ? String(data.code)
            : null;

    return {
        ok: false,
        status,
        code,
        message: String(message),
        data,
    };
}

/**
 * 外からも使えるように export（Notifications.vue 等で POST 前に呼べる）
 * ★CSRF取得が失敗した場合も「原因つき」で UI に出せるように整形して throw
 */
export async function ensureCsrfCookie() {
    assertCookieModeEnabled("ensureCsrfCookie");
    try {
        await initCsrf();
    } catch (e) {
        // /sanctum/csrf-cookie は同一オリジンの前提。失敗は運用上クリティカルなので明示する。
        throw buildApiError(
            e,
            "Failed to fetch CSRF cookie (/sanctum/csrf-cookie).",
        );
    }
}

export async function cookieLogin({ email, password }) {
    assertCookieModeEnabled("cookieLogin");
    await ensureCsrfCookie();

    const res = await axios.post(
        "/auth/cookie/login",
        { email, password },
        {
            withCredentials: true,
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                Accept: "application/json",
            },
        },
    );
    return res.data;
}

export async function cookieRegister({ name, email, password }) {
    assertCookieModeEnabled("cookieRegister");
    await ensureCsrfCookie();

    const res = await axios.post(
        "/auth/cookie/register",
        { name, email, password },
        {
            withCredentials: true,
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                Accept: "application/json",
            },
        },
    );
    return res.data;
}

export async function cookieMe() {
    assertCookieModeEnabled("cookieMe");

    const res = await axios.get("/auth/cookie/me", {
        withCredentials: true,
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            Accept: "application/json",
        },
    });
    return res.data;
}

export async function cookieLogout() {
    assertCookieModeEnabled("cookieLogout");
    await ensureCsrfCookie();

    const res = await axios.post("/auth/cookie/logout", null, {
        withCredentials: true,
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            Accept: "application/json",
        },
    });
    return res.data;
}

export async function getJson(url, config = {}) {
    try {
        const res = await api.get(url, config);
        return res.data;
    } catch (e) {
        throw buildApiError(e, "GET request failed.");
    }
}

export async function postJson(url, payload = {}, config = {}) {
    try {
        // SPA（Cookie認証）で POST する前はCSRFを確実に取る
        if (COOKIE_AUTH_ENABLED) {
            await ensureCsrfCookie();
        }
        const res = await api.post(url, payload, config);
        return res.data;
    } catch (e) {
        throw buildApiError(e, "POST request failed.");
    }
}

export async function deleteJson(url, config = {}) {
    try {
        if (COOKIE_AUTH_ENABLED) {
            await ensureCsrfCookie();
        }
        const res = await api.delete(url, config);
        return res.data;
    } catch (e) {
        throw buildApiError(e, "DELETE request failed.");
    }
}

export default api;
