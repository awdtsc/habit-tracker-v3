// resources/js/state/authUserCache.js
//------------------------------------------------------------
// /api/v1/auth/me キャッシュ（TTL + inflight共有）
// - Bearer token 前提（Cookie/CSRFに依存しない）
// - API呼び出しは “素のaxios” で行う（apiインスタンスのinterceptorを踏まない）
//
// return:
// - {id:...} : 認証OK
// - null     : 未認証確定（401）
// - AUTH_UNKNOWN : 未確定（ネットワーク/5xx等）
//------------------------------------------------------------

import axios from "axios";
import { getAuthToken } from "@/axios";

// 安全寄りなら短め推奨：5〜15秒
export const USER_TTL_MS = 10_000;

// ★ /me の確認が「失敗しただけ」で未認証扱いにしないための sentinel
export const AUTH_UNKNOWN = Symbol("AUTH_UNKNOWN");
export function isAuthUnknown(v) {
    return v === AUTH_UNKNOWN;
}

let cachedUser = null; // {id:...} or null
let cachedAt = 0; // 「認証状態が確定した」時刻（200/401のときだけ更新）
let inflight = null;

// ★「確認を試みた」時刻（200/401/ネットワーク/5xx すべてで更新）
let lastCheckAt = 0;

async function fetchUserFromApi() {
    lastCheckAt = Date.now();

    const token = getAuthToken();

    // token が無いなら、ここで未認証確定として扱ってよい（401を取りに行かない）
    if (!token) {
        cachedUser = null;
        cachedAt = Date.now();
        return null;
    }

    try {
        const res = await axios.get("/api/v1/auth/me", {
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                Accept: "application/json",
                Authorization: `Bearer ${token}`,
            },
        });

        const user = res.data && res.data.id ? res.data : null;

        cachedUser = user;
        cachedAt = Date.now();
        return user;
    } catch (e) {
        const status = e?.response?.status ?? 0;

        if (status === 401) {
            cachedUser = null;
            cachedAt = Date.now();
            return null;
        }

        return AUTH_UNKNOWN;
    }
}

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

export function getCachedUser() {
    return cachedUser;
}

export function hasConfirmedAuthState() {
    return !!cachedAt;
}

export function clearUserCache() {
    cachedUser = null;
    cachedAt = 0;
    inflight = null;
    lastCheckAt = 0;
}

export function getUserCacheAgeMs() {
    if (!lastCheckAt) return Infinity;
    return Date.now() - lastCheckAt;
}
