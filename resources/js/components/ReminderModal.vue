<!-- resources/js/components/ReminderModal.vue -->
<template>
  <!-- open=true のときだけ DOM を出す -->
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[9999]">
      <!-- overlay -->
      <div class="absolute inset-0 bg-black/40" @click="emitClose" />

      <!-- panel -->
      <div class="absolute inset-0 flex items-center justify-center p-4">
        <div
          ref="panelRef"
          class="w-full max-w-md rounded-2xl bg-white shadow-xl border border-gray-200 transform-gpu"
          role="dialog"
          aria-modal="true"
          :aria-label="title"
          @click.stop
        >
          <div class="px-5 pt-5 pb-3 border-b border-gray-100">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <h2 class="text-base font-semibold text-gray-900 truncate">
                  {{ title }}
                </h2>
                <p class="text-xs text-gray-500 mt-1" v-if="taskId != null">
                  task_id: {{ taskId }}
                </p>
              </div>

              <button
                type="button"
                class="shrink-0 rounded-md px-2 py-1 text-sm text-gray-500 hover:bg-gray-100"
                :disabled="busy"
                @click="emitClose"
              >
                ✕
              </button>
            </div>
          </div>

          <div class="px-5 py-4 space-y-3">
            <p
              v-if="payload?.body"
              class="text-sm text-gray-800 whitespace-pre-wrap break-words"
            >
              {{ payload.body }}
            </p>

            <!-- fallback: payload が薄いときでも空白にしない -->
            <p v-else class="text-sm text-gray-500">
              リマインダー操作を選んでください。
            </p>

            <!-- messages -->
            <div
              v-if="errorMsg"
              class="text-sm text-red-600 whitespace-pre-wrap break-words"
            >
              {{ errorMsg }}
            </div>
            <div
              v-if="okMsg"
              class="text-sm text-green-700 whitespace-pre-wrap break-words"
            >
              {{ okMsg }}
            </div>

            <!-- snooze input -->
            <div class="flex items-center gap-2">
              <label class="text-xs text-gray-600">スヌーズ</label>
              <input
                type="number"
                min="1"
                max="1440"
                class="w-24 rounded-md border border-gray-300 px-2 py-1 text-sm"
                v-model="snoozeMinutes"
                :disabled="busy"
              />
              <span class="text-xs text-gray-500">分</span>
            </div>

            <!-- debug small -->
            <div class="text-[11px] text-gray-400 space-y-1">
              <div v-if="payload?.date">date: {{ payload.date }}</div>
              <div v-if="payload?.habit_time_id">
                habit_time_id: {{ payload.habit_time_id }}
              </div>
              <div v-if="payload?.evaluation_type">
                eval: {{ payload.evaluation_type }}
              </div>
              <div v-if="payload?.url">url: {{ payload.url }}</div>
            </div>
          </div>

          <div class="px-5 pb-5 pt-3 border-t border-gray-100">
            <div class="flex items-center justify-end gap-2">
              <button
                type="button"
                class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                :disabled="busy"
                @click="doCancel"
              >
                キャンセル
              </button>

              <button
                type="button"
                class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                :disabled="busy"
                @click="doSnooze"
              >
                スヌーズ
              </button>

              <button
                type="button"
                class="rounded-lg bg-gray-900 px-3 py-2 text-sm text-white hover:bg-gray-800 disabled:opacity-60"
                :disabled="busy"
                @click="doDone"
              >
                完了
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount, nextTick } from "vue";
import { emitReminderAction } from "@/state/reminderActionBus";

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: "Habit Reminder" },
  payload: { type: Object, default: null },
});
const emit = defineEmits(["close"]);

const busy = ref(false);
const errorMsg = ref("");
const okMsg = ref("");
const snoozeMinutes = ref(10);

const emitClose = () => emit("close");

const taskId = computed(() => {
  const p = props.payload ?? {};
  return p.task_id ?? p.taskId ?? null;
});

const panelRef = ref(null);

// open時に初期化 + “描画を促す”
watch(
  () => props.open,
  async (v) => {
    if (v) {
      errorMsg.value = "";
      okMsg.value = "";
      snoozeMinutes.value = 10;

      await nextTick();
      const el = panelRef.value;
      if (el && el.getBoundingClientRect) {
        void el.getBoundingClientRect(); // force reflow
      }
    }
  }
);

// ESCで閉じる（open中だけ有効）
function onKeydown(e) {
  if (!props.open) return;
  if (e.key === "Escape") emitClose();
}
onMounted(() => window.addEventListener("keydown", onKeydown));
onBeforeUnmount(() => window.removeEventListener("keydown", onKeydown));

function getCookie(name) {
  return document.cookie
    .split("; ")
    .find((row) => row.startsWith(name + "="))
    ?.split("=")[1];
}

async function ensureCsrfCookie() {
  const r = await fetch("/sanctum/csrf-cookie", { credentials: "same-origin" });
  if (!r.ok) throw new Error("csrf-cookie failed: " + r.status);
}

function parseErrorMessage(status, text, ct) {
  const head = String(text ?? "").slice(0, 200);

  if (status === 401) {
    return "未ログインの可能性があります。ログインし直してからもう一度お試しください。";
  }
  if (status === 419) {
    return "セッション期限切れの可能性があります。ページを再読み込みしてからもう一度お試しください。";
  }

  if (ct && ct.includes("application/json")) {
    try {
      const j = JSON.parse(text || "{}");
      const msg = j?.message || j?.error || j?.errors?.message || null;
      if (typeof msg === "string" && msg) return msg;
    } catch (_) {}
  }

  return `${status} ${head}`;
}

async function postJson(url, bodyObj = null) {
  const xsrf = getCookie("XSRF-TOKEN");
  const headers = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  if (bodyObj != null) headers["Content-Type"] = "application/json";
  if (xsrf) headers["X-XSRF-TOKEN"] = decodeURIComponent(xsrf);

  const res = await fetch(url, {
    method: "POST",
    credentials: "same-origin",
    headers,
    body: bodyObj != null ? JSON.stringify(bodyObj) : null,
  });

  const text = await res.text();
  const ct = res.headers.get("content-type") || "";

  if (!res.ok) {
    throw new Error(parseErrorMessage(res.status, text, ct));
  }
  if (!ct.includes("application/json")) {
    throw new Error(`unexpected content-type: ${ct}`);
  }
  return JSON.parse(text || "{}");
}

/**
 * ✅ 同期用：モーダル操作が成功したら bus に通知
 * - Pages は onReminderAction で購読する
 */
function emitAction(type, result) {
  emitReminderAction({
    type, // 'done' | 'cancel' | 'snooze'
    task_id: taskId.value,
    payload: props.payload ?? null,
    result: result ?? null,
    at: Date.now(),
  });
}

async function doDone() {
  errorMsg.value = "";
  okMsg.value = "";
  if (taskId.value == null) {
    errorMsg.value = "task_id が見つかりません";
    return;
  }

  busy.value = true;
  try {
    await ensureCsrfCookie();
    const res = await postJson(
      `/api/v1/reminders/${encodeURIComponent(String(taskId.value))}/done`
    );

    emitAction("done", res);

    okMsg.value = "完了しました";
    emitClose();
  } catch (e) {
    console.error(e);
    errorMsg.value = String(e?.message ?? e);
  } finally {
    busy.value = false;
  }
}

async function doCancel() {
  errorMsg.value = "";
  okMsg.value = "";
  if (taskId.value == null) {
    errorMsg.value = "task_id が見つかりません";
    return;
  }

  busy.value = true;
  try {
    await ensureCsrfCookie();
    const res = await postJson(
      `/api/v1/reminders/${encodeURIComponent(String(taskId.value))}/cancel`
    );

    emitAction("cancel", res);

    okMsg.value = "キャンセルしました";
    emitClose();
  } catch (e) {
    console.error(e);
    errorMsg.value = String(e?.message ?? e);
  } finally {
    busy.value = false;
  }
}

async function doSnooze() {
  errorMsg.value = "";
  okMsg.value = "";
  if (taskId.value == null) {
    errorMsg.value = "task_id が見つかりません";
    return;
  }

  const m = Number(snoozeMinutes.value);
  if (!Number.isFinite(m) || m < 1 || m > 1440) {
    errorMsg.value = "minutes は 1..1440 で指定して";
    return;
  }

  busy.value = true;
  try {
    await ensureCsrfCookie();
    const res = await postJson(
      `/api/v1/reminders/${encodeURIComponent(String(taskId.value))}/snooze`,
      { minutes: m }
    );

    emitAction("snooze", res);

    okMsg.value = `スヌーズしました（${m}分）`;
    emitClose();
  } catch (e) {
    console.error(e);
    errorMsg.value = String(e?.message ?? e);
  } finally {
    busy.value = false;
  }
}
</script>