<!-- resources/js/Pages/Settings/Notifications.vue -->
<template>
  <div class="p-6 space-y-4">
    <h1 class="text-xl font-bold">通知設定</h1>

    <!-- Status -->
    <div class="rounded-xl border border-gray-200 bg-white p-4 space-y-2">
      <div>Notification.permission: <b>{{ permission }}</b></div>
      <div>ServiceWorker: <b>{{ swScope || "(none)" }}</b></div>
      <div>Subscription: <b>{{ hasSub ? "yes" : "no" }}</b></div>
      <div>VAPID key: <b>{{ vapidOk ? "ok" : "missing" }}</b></div>
      <div>Login (api/v1/auth/me): <b>{{ authOk ? "ok" : "unknown/unauth" }}</b></div>
    </div>

    <!-- Actions -->
    <div class="flex flex-wrap gap-2">
      <button
        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        @click="doSubscribe"
        :disabled="busy"
      >
        購読する
      </button>

      <button
        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        @click="doUnsubscribe"
        :disabled="!hasSub || busy"
      >
        購読解除
      </button>

      <button
        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        @click="doTest"
        :disabled="!hasSub || busy"
      >
        テスト送信
      </button>

      <!-- ★運用導線：update check → waiting があれば SKIP_WAITING（＋CLAIM）で有効化 -->
      <button
        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        @click="doApplySwUpdate"
        :disabled="busy"
        title="更新チェックを走らせ、waiting が出たら SKIP_WAITING で有効化します"
      >
        SW更新を適用
      </button>

      <button
        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        @click="refreshState"
        :disabled="busy"
      >
        再読み込み
      </button>
    </div>

    <!-- Help -->
    <div class="rounded-xl border border-gray-200 bg-white p-4 space-y-2">
      <div class="text-sm text-gray-600">
        使い方:
        <ol class="list-decimal ml-5 mt-1 space-y-1">
          <li>「購読する」→ブラウザ通知を許可</li>
          <li>成功するとDBにsubscriptionが保存され、/api/v1/push/test が通るようになります</li>
          <li>SW更新が反映されない/挙動が古い場合は「SW更新を適用」→必要なら手動リロード</li>
        </ol>
      </div>
      <div class="text-xs text-gray-500 leading-relaxed">
        ※ 419(Page Expired) が出る場合は CSRF cookie が未取得です。ここでは自動で /sanctum/csrf-cookie を取得します。<br />
        ※ 401 が出る場合は未ログインです（/today 等でログインしてから戻ってきてください）。<br />
        ※ 「購読できない」場合、ここに必ず原因（SW登録/ready/権限/認証等）が表示されます。
      </div>
    </div>

    <!-- Logs -->
    <pre
      class="rounded-xl bg-gray-50 p-4 text-xs leading-relaxed whitespace-pre-wrap overflow-auto border border-gray-200"
    >{{ logs.join("\n") }}</pre>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import { ensureServiceWorkerRegistered, requestWaitingSwActivation } from "@/registerSw";

const permission = ref(typeof Notification !== "undefined" ? Notification.permission : "unsupported");
const swScope = ref("");
const hasSub = ref(false);
const vapidOk = ref(false);
const authOk = ref(false);
const busy = ref(false);
const logs = ref([]);

function log(...a) {
  logs.value.push(
    a
      .map((x) => (typeof x === "string" ? x : JSON.stringify(x, null, 2)))
      .join(" ")
  );
}

function formatSwFailure(sw) {
  const head = `[sw:${sw?.step || "unknown"}] ${sw?.reason || "failed"}`;
  const detailMsg =
    sw?.detail?.message
      ? ` detail=${sw.detail.message}`
      : sw?.detail
      ? ` detail=${JSON.stringify(sw.detail)}`
      : "";
  return head + detailMsg;
}

function getCookie(name) {
  return document.cookie
    .split("; ")
    .find((row) => row.startsWith(name + "="))
    ?.split("=")[1];
}

function urlBase64ToUint8Array(base64String) {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(base64);
  const out = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

// /sw.js を指す registration を優先的に取る（regs[0]依存をやめる）
async function findSwRegistration() {
  if (!("serviceWorker" in navigator)) return null;
  const regs = await navigator.serviceWorker.getRegistrations().catch(() => []);
  if (!Array.isArray(regs) || regs.length === 0) return null;

  const match = regs.find((r) => {
    const url =
      r?.active?.scriptURL ||
      r?.installing?.scriptURL ||
      r?.waiting?.scriptURL ||
      "";
    return typeof url === "string" && url.includes("/sw.js");
  });

  return match || regs[0] || null;
}

async function ensurePermission() {
  if (!("Notification" in window)) throw new Error("Notification not supported");
  const p = await Notification.requestPermission();
  permission.value = p;
  if (p !== "granted") throw new Error("permission not granted");
}

async function ensureCsrfCookie() {
  const res = await fetch("/sanctum/csrf-cookie", { credentials: "same-origin" });
  log("[csrf-cookie]", res.status);
  if (!res.ok) throw new Error("csrf-cookie failed: " + res.status);
}

async function fetchJson(url, init) {
  const res = await fetch(url, init);
  const text = await res.text();
  const ct = res.headers.get("content-type") || "";
  if (!res.ok) throw new Error(`${url} failed: ${res.status} ${text.slice(0, 300)}`);
  if (!ct.includes("application/json")) {
    throw new Error(`${url} unexpected content-type: ${ct} body=${text.slice(0, 200)}`);
  }
  return JSON.parse(text);
}

async function apiGetMe() {
  const xsrf = getCookie("XSRF-TOKEN");
  const headers = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
    ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
  };

  try {
    await fetchJson("/api/v1/auth/me", { method: "GET", credentials: "same-origin", headers });
    authOk.value = true;
  } catch (e) {
    authOk.value = false;
    log("[auth/me]", String(e?.message ?? e));
  }
}

async function refreshState() {
  try {
    permission.value = typeof Notification !== "undefined" ? Notification.permission : "unsupported";
    vapidOk.value = !!import.meta.env.VITE_VAPID_PUBLIC_KEY;

    if (!("serviceWorker" in navigator)) {
      swScope.value = "";
      hasSub.value = false;
      return;
    }

    const reg = await findSwRegistration();
    swScope.value = reg?.scope || "";

    if (!reg) {
      hasSub.value = false;
      return;
    }

    const sub = await reg.pushManager.getSubscription();
    hasSub.value = !!sub;
  } catch (e) {
    log("[refresh error]", String(e?.message ?? e));
  } finally {
    await apiGetMe();
  }
}

async function postJson(url, bodyObj) {
  const xsrf = getCookie("XSRF-TOKEN");
  const headers = {
    "Content-Type": "application/json",
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
    ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
  };

  return await fetchJson(url, {
    method: "POST",
    credentials: "same-origin",
    headers,
    body: JSON.stringify(bodyObj),
  });
}

async function getJson(url) {
  const xsrf = getCookie("XSRF-TOKEN");
  const headers = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
    ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
  };

  return await fetchJson(url, {
    method: "GET",
    credentials: "same-origin",
    headers,
  });
}

async function doApplySwUpdate() {
  if (busy.value) return;
  busy.value = true;
  logs.value = [];

  try {
    log("[sw-activate]", "checking update → waiting? → SKIP_WAITING (→ controllerchange → CLAIM)");

    // registerSw.js の新仕様に合わせて「引数無し」で呼ぶ（受け取っても無視される）
    const res = await requestWaitingSwActivation();
    log("[sw-activate]", res);

    // 失敗系（UIに理由を残す）
    if (!res?.ok) {
      const r = res?.reason || "unknown";
      if (r === "unsupported") {
        log("[hint]", "このブラウザは Service Worker に対応していません。");
      } else if (r === "not-registered") {
        log("[hint]", "Service Worker の登録/ready に失敗しました。詳細:", res?.detail || "(none)");
      } else if (r === "not-our-sw") {
        log("[hint]", "このページで /sw.js の registration が見つかりませんでした（scope/登録状態を確認してください）。");
      } else {
        log("[hint]", "予期せぬエラーです。詳細:", res?.detail || "(none)");
      }

      await refreshState();
      return;
    }

    // 成功系（ok:true）
    if (res?.reason === "no-waiting-after-update") {
      log("[ok]", "更新チェックを走らせましたが、waiting SW は見つかりませんでした。");
      log("[hint]", "すでに最新の可能性が高いです（今回のSW更新適用は不要）。必要なら手動で再読み込みしてください。");
      await refreshState();
      return;
    }

    if (res?.reason === "activated") {
      log("[ok]", "waiting SW を有効化しました（SKIP_WAITING 済み）。");
      if (res?.controller_changed) {
        log("[sw]", "controllerchange detected（新しいSWが支配した可能性が高い）");
      } else {
        log("[sw]", "controllerchange not detected（必要なら手動リロードしてください）");
      }

      // 安全策：適用後は一度リロード推奨（キャッシュ/ハンドラ混在を避ける）
      log("[hint]", "安全のためページを再読み込みします。");
      try { location.reload(); } catch {}
      return;
    }

    // 想定外（将来 reason が増えた時の安全弁）
    log("[hint]", "想定外の応答です。状態を再取得します。");
    await refreshState();
  } catch (e) {
    log("[sw-activate error]", String(e?.message ?? e));
  } finally {
    busy.value = false;
  }
}

async function doSubscribe() {
  if (busy.value) return;
  busy.value = true;
  logs.value = [];

  try {
    const frontendKey = import.meta.env.VITE_VAPID_PUBLIC_KEY;
    vapidOk.value = !!frontendKey;
    if (!frontendKey) {
      log("[fatal] Missing VITE_VAPID_PUBLIC_KEY in .env");
      throw new Error("Missing VITE_VAPID_PUBLIC_KEY in .env");
    }

    // 先にCSRF+認証（401/419を分かりやすく）
    await ensureCsrfCookie();
    await apiGetMe();
    if (!authOk.value) {
      log("[subscribe blocked]", "Not authenticated. Please login first.");
      throw new Error("Not authenticated. Please login first.");
    }

    // ★VAPIDパリティ（backendと一致しないなら購読を止める）
    const backendVapid = await getJson("/api/v1/push/vapid-public");
    const backendKey = String(backendVapid?.public_key || "");
    if (!backendKey) throw new Error("Backend VAPID public key is missing.");
    if (backendKey !== String(frontendKey)) throw new Error("VAPID key mismatch between frontend and backend.");

    // ★ SW登録/ready を「絶対に理由つきでUIへ」出す
    const sw = await ensureServiceWorkerRegistered();
    if (!sw?.ok) {
      const msg = formatSwFailure(sw);
      log("[subscribe blocked]", msg);
      throw new Error(msg);
    }

    // registration 取得（scope表示・pushManager用）
    const reg = sw.reg || (await findSwRegistration());
    if (!reg) throw new Error("service worker registration not found after ensure");
    swScope.value = reg.scope;

    await ensurePermission();

    // 既存を再利用（重複防止）
    const existing = await reg.pushManager.getSubscription();
    const sub =
      existing ||
      (await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(frontendKey),
      }));

    log("[ok] subscribed in browser");

    const api = await postJson("/api/v1/push/subscribe", sub.toJSON());
    log("[ok] subscribed in server", api);

    await refreshState();
  } catch (e) {
    log("[subscribe error]", String(e?.message ?? e));
  } finally {
    busy.value = false;
  }
}

async function doUnsubscribe() {
  if (busy.value) return;
  busy.value = true;
  logs.value = [];

  try {
    const reg = await findSwRegistration();
    if (!reg) throw new Error("no service worker registration");

    const sub = await reg.pushManager.getSubscription();
    if (!sub) throw new Error("no subscription");

    await ensureCsrfCookie();
    await apiGetMe();
    if (!authOk.value) throw new Error("Not authenticated. Please login first.");

    await postJson("/api/v1/push/unsubscribe", { endpoint: sub.endpoint });
    log("[ok] unsubscribed in server");

    await sub.unsubscribe();
    log("[ok] unsubscribed in browser");

    await refreshState();
  } catch (e) {
    log("[unsubscribe error]", String(e?.message ?? e));
  } finally {
    busy.value = false;
  }
}

async function doTest() {
  if (busy.value) return;
  busy.value = true;
  logs.value = [];

  try {
    await ensureCsrfCookie();
    await apiGetMe();
    if (!authOk.value) throw new Error("Not authenticated. Please login first.");

    const res = await postJson("/api/v1/push/test", {
      title: "Habit Tracker",
      body: "test push",
      url: "/today?from=push",
      ts: new Date().toISOString(),
    });

    log("[ok] test sent", res);
    await refreshState();
  } catch (e) {
    log("[test error]", String(e?.message ?? e));
  } finally {
    busy.value = false;
  }
}

onMounted(async () => {
  await refreshState();
});
</script>