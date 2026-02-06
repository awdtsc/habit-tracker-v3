<!-- resources/js/App.vue -->
<template>
  <RouterView v-slot="{ Component, route }">
    <KeepAlive :include="keepAliveNames">
      <component v-if="Component" :is="Component" :key="route.name" />
    </KeepAlive>
  </RouterView>

  <!-- ★通知クリック用モーダル（KeepAlive外に常駐） -->
  <ReminderModal
    :open="reminderModalState.open"
    :title="reminderModalState.payload?.title ?? 'Habit Reminder'"
    :payload="reminderModalState.payload"
    @close="closeReminderModal"
  />
</template>

<script setup>
import { onMounted, onUnmounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";

import ReminderModal from "@/components/ReminderModal.vue";
import {
  reminderModalState,
  openReminderModal,
  closeReminderModal,
} from "@/state/reminderModal";

import { ensureServiceWorkerRegistered } from "@/registerSw.js";

const route = useRoute();
const router = useRouter();

const keepAliveNames = ["TodayPage", "WeeklyPage"];
const recentReminderOpenKeys = new Map();
const REMINDER_OPEN_DEDUPE_MS = 3000;

// プリフェッチ対象（反対側のページを先読み）
const prefetchWeek = () => import("@/Pages/Weekly.vue");
const prefetchToday = () => import("@/Pages/Today.vue");

function schedulePrefetch(fn) {
  const run = () => fn().catch(() => {});
  if ("requestIdleCallback" in window) window.requestIdleCallback(run);
  else setTimeout(run, 250);
}

function prefetchOpposite(name) {
  // ルーター定義次第で name が揺れるので両対応
  if (name === "today" || name === "TodayPage") schedulePrefetch(prefetchWeek);
  if (name === "week" || name === "WeeklyPage") schedulePrefetch(prefetchToday);
}

/**
 * task_id を数値にできるなら数値で、無理なら文字列で保持
 * - 0 は不正扱いに寄せる（Number("0") は 0 なので弾く）
 */
function normalizeTaskId(v) {
  if (v == null) return null;

  const s = String(v).trim();
  if (!s) return null;

  const n = Number(s);
  if (Number.isFinite(n) && n > 0) return n;

  // 数値化できないが何かしら値がある場合は文字列として保持
  return s;
}

function normalizePayload(input) {
  const p =
    input && typeof input === "object" && !Array.isArray(input) ? input : {};

  return {
    title: typeof p.title === "string" && p.title ? p.title : "Habit Reminder",
    body: typeof p.body === "string" ? p.body : String(p.body ?? ""),
    task_id: normalizeTaskId(p.task_id ?? p.taskId ?? p.task?.id ?? p.task ?? null),
    habit_time_id: p.habit_time_id ?? null,
    date: p.date ?? null,
    evaluation_type: p.evaluation_type ?? null,
    url: typeof p.url === "string" ? p.url : null,
  };
}

function buildReminderEventKey(payload) {
  const p = payload && typeof payload === "object" ? payload : {};
  const task = p.task_id ?? "none";
  const habitTime = p.habit_time_id ?? "none";
  const date = p.date ?? "none";
  const evalType = p.evaluation_type ?? "none";
  return `${task}|${habitTime}|${date}|${evalType}`;
}

function shouldOpenReminder(payload) {
  const now = Date.now();

  for (const [key, ts] of recentReminderOpenKeys.entries()) {
    if (now - ts > REMINDER_OPEN_DEDUPE_MS) recentReminderOpenKeys.delete(key);
  }

  const key = buildReminderEventKey(payload);
  const prev = recentReminderOpenKeys.get(key);
  if (typeof prev === "number" && now - prev <= REMINDER_OPEN_DEDUPE_MS) {
    return false;
  }

  recentReminderOpenKeys.set(key, now);
  return true;
}

// ★URLクエリ保険：/today?from=push&task_id=... で開かれたらモーダルを開く
function openFromQueryIfNeeded() {
  if (route.path !== "/today") return;
  if (route.query.from !== "push") return;

  const payload = normalizePayload({
    title: "Habit Reminder",
    body: "",
    task_id: route.query.task_id ?? null,
    habit_time_id: route.query.habit_time_id ?? null,
    date: route.query.date ?? null,
    evaluation_type: route.query.evaluation_type ?? null,
    time_slot: route.query.time_slot ?? null,
    url: route.fullPath,
  });

  if (shouldOpenReminder(payload)) {
    openReminderModal(payload);
  }

  // 連続発火防止：クエリ掃除（描画と競合させない）
  const q = { ...route.query };
  delete q.from;
  delete q.task_id;
  delete q.habit_time_id;
  delete q.date;
  delete q.evaluation_type;
  delete q.time_slot;
  setTimeout(() => router.replace({ query: q }).catch(() => {}), 0);
}

// ★SW message handler（removeできるように関数化）
function onSwMessage(event) {
  const data = event?.data;
  if (data?.type !== "REMINDER_CLICK") return;

  // SW→postMessage payload をここで正規化して事故率を下げる
  const payload = normalizePayload(data.payload);
  if (!shouldOpenReminder(payload)) return;
  openReminderModal(payload);
}

onMounted(() => {
  prefetchOpposite(route.name);

  // ★SWを起動時に保証（設定ページ依存を排除）
  ensureServiceWorkerRegistered();

  // ★SWからの postMessage を受けてモーダルを開く
  if (navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener("message", onSwMessage);
  }

  // ★クエリ起動（保険）
  openFromQueryIfNeeded();
});

onUnmounted(() => {
  if (navigator.serviceWorker) {
    navigator.serviceWorker.removeEventListener("message", onSwMessage);
  }
});

watch(
  () => route.name,
  (name) => prefetchOpposite(name),
);

// ルート変更時にも push クエリを拾う（openWindowで遷移したケース）
watch(
  () => route.fullPath,
  () => openFromQueryIfNeeded(),
);
</script>
