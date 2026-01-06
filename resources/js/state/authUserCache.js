// resources/js/state/authUserCache.js
//------------------------------------------------------------
// /api/user キャッシュ（TTL + inflight共有）
// - router/axios どちらからでも使う（循環import回避のためここに集約）
// - API呼び出しは “素のaxios” で行う（apiインスタンスのinterceptorを踏まない）
//
// 改善ポイント:
// - AUTH_UNKNOWN(ネットワーク/5xx等)でも「最後に確認しに行った時刻」は更新する
//   → router.afterEach の force 再検証がオフライン時に連打されるのを抑える
// - 「認証状態が確定しているか(cachedAt>0)」を外に出す
//   → beforeEach が AUTH_UNKNOWN 連発で /api/user を叩き直すのを抑える
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
let cachedAt = 0; // 「認証状態が確定した」時刻（200/401のときだけ更新）
let inflight = null;

// ★「確認を試みた」時刻（200/401/ネットワーク/5xx すべてで更新）
let lastCheckAt = 0;

async function fetchUserFromApi() {
    // ここに来た時点で「確認を試みた」扱いにする
    lastCheckAt = Date.now();

    try {
        const res = await axios.get("/api/user", {
            withCredentials: true,
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                Accept: "application/json",
            },
        });

        const user = res.data && res.data.id ? res.data : null;

        // 200系で「認証OK確定」
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
        // - lastCheckAt は更新済みなので、afterEach の連打は抑えられる
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

    // ★forceでも共有（同時多発の二重発火を防ぐ）
    if (inflight) return inflight;

    inflight = fetchUserFromApi().finally(() => {
        inflight = null;
    });

    return inflight;
}

// 呼び出し側が「直近の状態」を見る用（ネットワークしない）
export function getCachedUser() {
    return cachedUser;
}

// ★「認証状態が確定済みか？」（200/401を一度でも引けているか）
export function hasConfirmedAuthState() {
    return !!cachedAt;
}

export function clearUserCache() {
    cachedUser = null;
    cachedAt = 0;
    inflight = null;
    // lastCheckAt は「いつ確認したか」なので消すかは好み。
    // 401などで明示的に切るなら 0 に戻すのが分かりやすいので戻す。
    lastCheckAt = 0;
}

/**
 * “認証状態が確定した時刻” ではなく
 * “最後に /api/user を確認しに行った時刻” の経過を返す。
 *
 * router.afterEach の「背景再検証の頻度制御」用途に向いている。
 */
export function getUserCacheAgeMs() {
    if (!lastCheckAt) return Infinity;
    return Date.now() - lastCheckAt;
}
