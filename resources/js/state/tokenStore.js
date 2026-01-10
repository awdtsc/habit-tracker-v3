// resources/js/state/tokenStore.js
const KEY = "auth_token";

export function setToken(token) {
    if (token) sessionStorage.setItem(KEY, token);
    else sessionStorage.removeItem(KEY);
}

export function getToken() {
    return sessionStorage.getItem(KEY);
}

export function clearToken() {
    sessionStorage.removeItem(KEY);
}
