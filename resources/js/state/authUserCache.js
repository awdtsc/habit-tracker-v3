// resources/js/state/authUserCache.js
//------------------------------------------------------------
// /api/v1/auth/me キャッシュ（TTL + inflight共有）
// - BearerでもCookieでも「/api/v1/auth/me が200ならログイン済み」と判定する
// - tokenが無いだけで未ログイン確定にしない（Cookie運用で詰むため）
//
// return:
// - user object : 認証OK
// - null        : 未認証確定（401）
// - AUTH_UNKNOWN: 未確定（ネットワーク/5xx等）
//
// 改善:
// - AUTH_UNKNOWN も短時間だけキャッシュして /me 連打を抑止（回線断・一時障害対策）
//------------------------------------------------------------

import axios from "axios";
import { getAuthToken, clearAuthToken } from "@/axios";

// 確定（user / null）のTTL
export const USER_TTL_MS = 10_000;

// 未確定（AUTH_UNKNOWN）のバックオフTTL（短め）
export const UNKNOWN_TTL_MS = 2_000;

export const AUTH_UNKNOWN = Symbol("AUTH_UNKNOWN");
export function isAuthUnknown(v) {
    return v === AUTH_UNKNOWN;
}

let cachedUser = null; // user | null | AUTH_UNKNOWN
let cachedAt = 0; // 何らかの結果をキャッシュした時刻（unknown含む）
let confirmedAt = 0; // 確定結果（user/null）のみの時刻
let inflight = null;
let lastCheckAt = 0;

function normalizeUser(data) {
    const u = data?.user ?? data ?? null;
    if (!u) return null;
    return u?.id ? u : null;
}

function setCache(value, { confirmed = false } = {}) {
    cachedUser = value;
    cachedAt = Date.now();
    if (confirmed) confirmedAt = cachedAt;
}

function cacheFresh(now) {
    if (!cachedAt) return false;

    const ttl = isAuthUnknown(cachedUser) ? UNKNOWN_TTL_MS : USER_TTL_MS;
    return now - cachedAt < ttl;
}

async function fetchUserFromApi() {
    lastCheckAt = Date.now();

    const token = getAuthToken();
    const hasBearerToken = !!token;

    try {
        const res = await axios.get("/api/v1/auth/me", {
            withCredentials: true,
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                Accept: "application/json",
                ...(hasBearerToken ? { Authorization: `Bearer ${token}` } : {}),
            },
        });

        const user = normalizeUser(res.data);

        // 200でも user が取れないなら unknown 扱い（サーバ契約ズレ対策）
        if (!user) {
            setCache(AUTH_UNKNOWN, { confirmed: false });
            return AUTH_UNKNOWN;
        }

        setCache(user, { confirmed: true });
        return user;
    } catch (e) {
        const status = e?.response?.status ?? 0;

        if (status === 401) {
            // Bearerで401ならtoken無効確定なので破棄
            if (hasBearerToken) clearAuthToken();

            // 未認証確定としてキャッシュ
            setCache(null, { confirmed: true });
            return null;
        }

        // ネットワーク断/5xx等は未確定（ただし短時間キャッシュして連打を抑止）
        setCache(AUTH_UNKNOWN, { confirmed: false });
        return AUTH_UNKNOWN;
    }
}

export async function getUser({ force = false } = {}) {
    const now = Date.now();

    if (!force && cacheFresh(now)) {
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

// 「確定（user or null）を一度でも取れたか」だけを返す（unknownは含めない）
export function hasConfirmedAuthState() {
    return !!confirmedAt;
}

export function clearUserCache() {
    cachedUser = null;
    cachedAt = 0;
    confirmedAt = 0;
    inflight = null;
    lastCheckAt = 0;
}

export function getUserCacheAgeMs() {
    if (!lastCheckAt) return Infinity;
    return Date.now() - lastCheckAt;
}
