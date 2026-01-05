// resources/js/state/authUserCache.js
//------------------------------------------------------------
// /api/user キャッシュ（TTL + inflight共有）
// - router/axios どちらからでも使う（循環import回避のためここに集約）
// - API呼び出しは “素のaxios” で行う（apiインスタンスのinterceptorを踏まない）
//------------------------------------------------------------

import axios from "axios";

// 安全寄りなら短め推奨：5〜15秒
export const USER_TTL_MS = 10_000;

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
    } catch {
        cachedUser = null;
        cachedAt = Date.now();
        return null;
    }
}

/**
 * TTL内はキャッシュ返却。TTL切れは /api/user を取りに行く。
 * force=true なら必ず取りに行く（401後の再確認用）
 */
export async function getUser({ force = false } = {}) {
    const now = Date.now();

    if (!force && cachedAt && now - cachedAt < USER_TTL_MS) {
        return cachedUser;
    }

    if (inflight) return inflight;

    inflight = fetchUserFromApi().finally(() => {
        inflight = null;
    });

    return inflight;
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
