// resources/js/axios.js
//------------------------------------------------------------
// Axios 設定（Laravel Sanctum + Cookie 認証用）
// 目的：高速化した auth cache と整合する「安全な 401 一括処理」
//   - 401 を見たら user cache を即破棄（TTLの“通っちゃう”を潰す）
//   - /api/user だけに限定せず「認証必須ページ滞在中なら」ログインへ戻す
//   - ただし無限リダイレクト防止・二重遷移防止を入れる
//------------------------------------------------------------

import axios from "axios";
import { clearUserCache } from "@/router/index";

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
//   ※ baseURL=/api を無視し、絶対パスで取得するのが重要
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
        // baseURL=/api でも相対が来るので origin を補う
        return new URL(url, window.location.origin).pathname;
    } catch {
        // 例: "user" みたいな形
        if (url.startsWith("/")) return url;
        return "/" + url;
    }
}

function isAuthPagePath(pathname) {
    // publicページ（ここにいる時は強制遷移しない）
    if (pathname === "/login") return true;
    if (pathname === "/register") return true;
    return false;
}

let redirecting = false;

// ------------------------------------------------------------
// Response Interceptor（401 → user cache破棄 → 必要なら/loginへ）
// ------------------------------------------------------------
api.interceptors.response.use(
    (res) => res,
    async (error) => {
        const status = error?.response?.status ?? 0;

        // 何度も飛ばないように一度だけ
        if (status === 401) {
            // ★どの401でも「安全のため user cache は即破棄」
            // これで router の TTL による “しばらく通る” を消せる
            try {
                clearUserCache();
            } catch {
                // router import 絡みで万一失敗しても落とさない
            }

            // どのAPIが401でも「認証必須ページにいるなら」ログインへ戻す方が安全。
            // ただし public ページや既に飛び中は除外。
            const currentPath = window.location.pathname;
            const requestPath = normalizePath(error?.config?.url ?? "");

            // 無限ループ防止（ログインページでは何もしない）
            if (!redirecting && !isAuthPagePath(currentPath)) {
                redirecting = true;

                // redirect 先は「本来行こうとしてた画面」に戻れるように保持
                // 例: /week?week=2026-01-05
                const redirect =
                    window.location.pathname + window.location.search;

                // /logout API 等で 401 の場合も同様に login へ戻す
                console.warn(
                    `[axios] 401 detected (req:${requestPath}) → clear auth cache + redirect to /login`
                );

                // window.location.href を使うのは、SPA状態が壊れていても確実に戻すため
                window.location.href =
                    "/login?redirect=" + encodeURIComponent(redirect);
                return;
            }
        }

        return Promise.reject(error);
    }
);

export default api;
