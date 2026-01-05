// resources/js/state/authUserCache.js
//------------------------------------------------------------
// /api/user キャッシュ（TTL + inflight共有）
// - router/axios どちらからでも使う（循環import回避のためここに集約）
// - API呼び出しは “素のaxios” で行う（apiインスタンスのinterceptorを踏まない）
//------------------------------------------------------------

import axios from "axios";

// 安全寄りなら短め推奨：5〜15秒
export const USER_TTL_MS = 10_000;

// ★/api/user の確認が「失敗しただけ」で未認証扱いにしないための sentinel
export const AUTH_UNKNOWN = Symbol("AUTH_UNKNOWN");
export function isAuthUnknown(v) {
    return v === AUTH_UNKNOWN;
}

let cachedUser = null; // {id:...} or null
let cachedAt = 0;
let inflight = null;

async function fetchUserFromApi() {
    try {
        const res = await axios.get("/api/user", {
            withCredentials: true,
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                Accept: "application/json",
            },
        });

        const user = res.data && res.data.id ? res.data : null;
        cachedUser = user;
        cachedAt = Date.now();
        return user;
    } catch (e) {
        const status = e?.response?.status ?? 0;

        // ★未認証が「確定」するのは401だけ
        if (status === 401) {
            cachedUser = null;
            cachedAt = Date.now();
            return null;
        }

        // ★それ以外は未確定（ネットワーク/5xx等）
        // - cachedUser/cachedAt は壊さない（誤爆ログアウト防止）
        return AUTH_UNKNOWN;
    }
}

/**
 * TTL内はキャッシュ返却。TTL切れは /api/user を取りに行く。
 * force=true なら必ず取りに行く（401後の再確認用）
 *
 * return:
 * - {id:...} : 認証OK
 * - null     : 未認証確定（/api/userが401）
 * - AUTH_UNKNOWN : 未確定（ネットワーク/5xx等）
 */
export async function getUser({ force = false } = {}) {
    const now = Date.now();

    if (!force && cachedAt && now - cachedAt < USER_TTL_MS) {
        return cachedUser;
    }

    if (!force && inflight) return inflight;

    inflight = fetchUserFromApi().finally(() => {
        inflight = null;
    });

    return inflight;
}

// 呼び出し側が「直近の状態」を見る用（ネットワークしない）
export function getCachedUser() {
    return cachedUser;
}

export function clearUserCache() {
    cachedUser = null;
    cachedAt = 0;
    inflight = null;
}

export function getUserCacheAgeMs() {
    if (!cachedAt) return Infinity;
    return Date.now() - cachedAt;
}
