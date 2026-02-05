// resources/js/registerSw.js

/**
 * Ensure the service worker (/sw.js) is registered and ready.
 * - Safe: never throws (so it won't crash the SPA)
 * - Idempotent: skips if /sw.js already registered
 */
export async function ensureServiceWorkerRegistered() {
    try {
        if (!("serviceWorker" in navigator)) return;

        // Already registered?
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

        if (!hasSw) {
            await navigator.serviceWorker.register("/sw.js");
        }

        await navigator.serviceWorker.ready.catch(() => {});
    } catch (e) {
        // Never crash the app because SW failed
    }
}
