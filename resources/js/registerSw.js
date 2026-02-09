// resources/js/registerSw.js

/**
 * Ensure the service worker (/sw.js) is registered and ready.
 *
 * 返り値:
 * - { ok: true, reg } もしくは { ok:false, step, reason, detail? }
 */
export async function ensureServiceWorkerRegistered() {
    if (!("serviceWorker" in navigator)) {
        return {
            ok: false,
            step: "precheck",
            reason: "Service Worker is not supported in this browser.",
        };
    }

    try {
        const regs = await navigator.serviceWorker
            .getRegistrations()
            .catch(() => []);
        const hasSw = Array.isArray(regs)
            ? regs.some((r) => isOurSwRegistration(r))
            : false;

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

        try {
            const readyReg = await navigator.serviceWorker.ready;
            return { ok: true, reg: readyReg || reg };
        } catch (e) {
            return {
                ok: false,
                step: "ready",
                reason: "Service worker registered but did not become ready.",
                detail: normalizeErr(e),
            };
        }
    } catch (e) {
        return {
            ok: false,
            step: "unknown",
            reason: "Unexpected error while ensuring service worker.",
            detail: normalizeErr(e),
        };
    }
}

/**
 * Operator-controlled SW activation:
 * 1) update() で更新チェック
 * 2) waiting が出たら SKIP_WAITING
 * 3) controllerchange 後に CLAIM_CLIENTS（B対策：claimを“切替時だけ”に限定）
 *
 * 返り値:
 * - { ok:true, reason:"activated", had_waiting:true }
 * - { ok:true, reason:"no-waiting-after-update", had_waiting:false }
 * - { ok:false, reason:"unsupported" | "not-registered" | "not-our-sw" | "unexpected", detail? }
 */
export async function requestWaitingSwActivation() {
    if (!("serviceWorker" in navigator))
        return { ok: false, reason: "unsupported" };

    try {
        const ensured = await ensureServiceWorkerRegistered();
        if (!ensured.ok)
            return { ok: false, reason: "not-registered", detail: ensured };

        const regs = await navigator.serviceWorker
            .getRegistrations()
            .catch(() => []);
        const ourReg = Array.isArray(regs)
            ? regs.find((r) => isOurSwRegistration(r))
            : null;
        if (!ourReg) return { ok: false, reason: "not-our-sw" };

        // ★まず update check
        try {
            await ourReg.update();
        } catch (_) {
            // update失敗は致命ではない（この後waiting確認で判断する）
        }

        // 少し待って waiting を再評価
        await sleep(250);

        if (!ourReg.waiting) {
            return {
                ok: true,
                reason: "no-waiting-after-update",
                had_waiting: false,
            };
        }

        // waiting に SKIP_WAITING
        try {
            ourReg.waiting.postMessage({ type: "SKIP_WAITING" });
        } catch (e) {
            return { ok: false, reason: "unexpected", detail: normalizeErr(e) };
        }

        // controllerchange待ち（短時間）
        const changed = await waitControllerChange(1500);

        // ★B対策：切替が起きた瞬間だけ claim を明示
        try {
            if (changed && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({
                    type: "CLAIM_CLIENTS",
                });
            }
        } catch (_) {}

        return {
            ok: true,
            reason: "activated",
            had_waiting: true,
            controller_changed: changed,
        };
    } catch (e) {
        return { ok: false, reason: "unexpected", detail: normalizeErr(e) };
    }
}

function isOurSwRegistration(reg) {
    try {
        const url =
            reg?.active?.scriptURL ||
            reg?.installing?.scriptURL ||
            reg?.waiting?.scriptURL ||
            "";
        return typeof url === "string" && url.includes("/sw.js");
    } catch {
        return false;
    }
}

function normalizeErr(e) {
    try {
        if (!e) return { message: "unknown error" };
        if (typeof e === "string") return { message: e };
        if (e instanceof Error)
            return { name: e.name, message: e.message, stack: e.stack };
        return {
            message: String(e?.message || e),
            name: String(e?.name || ""),
        };
    } catch {
        return { message: "failed to normalize error" };
    }
}

function sleep(ms) {
    return new Promise((r) => setTimeout(r, Math.max(0, ms)));
}

function waitControllerChange(timeoutMs = 1200) {
    return new Promise((resolve) => {
        if (!("serviceWorker" in navigator)) return resolve(false);

        let done = false;
        const onChange = () => {
            if (done) return;
            done = true;
            navigator.serviceWorker.removeEventListener(
                "controllerchange",
                onChange,
            );
            resolve(true);
        };

        navigator.serviceWorker.addEventListener("controllerchange", onChange);

        setTimeout(
            () => {
                if (done) return;
                done = true;
                navigator.serviceWorker.removeEventListener(
                    "controllerchange",
                    onChange,
                );
                resolve(false);
            },
            Math.max(200, timeoutMs),
        );
    });
}
