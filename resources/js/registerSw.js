// resources/js/registerSw.js

/**
 * Ensure the service worker (/sw.js) is registered and ready.
 *
 * 要求仕様:
 * - 絶対に「購読できない原因」を上位(UI)へ返す（握りつぶさない）
 * - SPA をクラッシュさせない（throw しない）
 * - Idempotent（既に /sw.js が登録済みなら再登録しない）
 *
 * 返り値:
 * - { ok: true, reg } もしくは { ok:false, step, reason, detail? }
 */
export async function ensureServiceWorkerRegistered() {
    // サポート外
    if (!("serviceWorker" in navigator)) {
        return {
            ok: false,
            step: "precheck",
            reason: "Service Worker is not supported in this browser.",
        };
    }

    try {
        // 既存登録の確認（失敗しても継続できるようにする）
        const regs = await navigator.serviceWorker
            .getRegistrations()
            .catch(() => []);

        const hasSw = Array.isArray(regs)
            ? regs.some((r) => {
                  const url =
                      r?.active?.scriptURL ||
                      r?.installing?.scriptURL ||
                      r?.waiting?.scriptURL ||
                      "";
                  return typeof url === "string" && url.includes("/sw.js");
              })
            : false;

        // 未登録なら登録
        let reg = null;
        if (!hasSw) {
            try {
                reg = await navigator.serviceWorker.register("/sw.js");
            } catch (e) {
                return {
                    ok: false,
                    step: "register",
                    reason: "Failed to register /sw.js.",
                    detail: normalizeErr(e),
                };
            }
        }

        // ready 待ち
        try {
            const readyReg = await navigator.serviceWorker.ready;
            return {
                ok: true,
                reg: readyReg || reg,
            };
        } catch (e) {
            return {
                ok: false,
                step: "ready",
                reason: "Service worker registered but did not become ready.",
                detail: normalizeErr(e),
            };
        }
    } catch (e) {
        // ここは本当に想定外（ただしUIには理由を返す）
        return {
            ok: false,
            step: "unknown",
            reason: "Unexpected error while ensuring service worker.",
            detail: normalizeErr(e),
        };
    }
}

function normalizeErr(e) {
    try {
        if (!e) return { message: "unknown error" };
        if (typeof e === "string") return { message: e };
        if (e instanceof Error) {
            return {
                name: e.name,
                message: e.message,
                stack: e.stack,
            };
        }
        // DOMException など
        return {
            message: String(e?.message || e),
            name: String(e?.name || ""),
        };
    } catch {
        return { message: "failed to normalize error" };
    }
}
