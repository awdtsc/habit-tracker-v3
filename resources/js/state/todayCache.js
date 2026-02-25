// resources/js/state/todayCache.js
//------------------------------------------------------------
// Today API のメモリキャッシュ（scope 別）
//------------------------------------------------------------
const cache = new Map();

export function getCachedToday(scope) {
    return cache.get(scope) ?? null;
}

export function setCachedToday(scope, payload) {
    cache.set(scope, payload);
}
