// resources/js/utils/safeRedirect.js
//------------------------------------------------------------
// redirect sanitizer（内部パスのみ許可）
// - Router / Login / Register で同一ルールを共有してドリフトを防ぐ
// - length cap は decode 前後でかける（エンコード膨張対策）
//------------------------------------------------------------

export function safeRedirect(raw, fallback = null) {
    if (typeof raw !== "string" || raw.length === 0) return fallback;

    // pre-decode length cap
    if (raw.length > 1024) return fallback;

    let v = raw;
    try {
        v = decodeURIComponent(raw);
    } catch {
        v = raw;
    }

    // post-decode length cap
    if (v.length > 1024) return fallback;

    // internal path only
    if (!v.startsWith("/")) return fallback;

    // reject scheme-relative URL
    if (v.startsWith("//")) return fallback;

    // reject newline injection
    if (v.includes("\n") || v.includes("\r")) return fallback;

    // do not allow redirect to /logout
    if (v === "/logout" || v.startsWith("/logout/")) return fallback;

    return v;
}
