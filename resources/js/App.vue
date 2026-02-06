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

// ★URLクエリ保険：/today?from=push&task_id=... で開かれたらモーダルを開く
function openFromQueryIfNeeded() {
  if (route.query.from !== "push") return;

  const taskId = normalizeTaskId(route.query.task_id ?? null);

  openReminderModal(
    normalizePayload({
      title: "Habit Reminder",
      body: "",
      task_id: taskId,
      // SW 側は常に /today?from=push... に寄せる想定なので、ここも同じ方針で OK
      url: route.fullPath,
    }),
  );

  // 連続発火防止：クエリ掃除（描画と競合させない）
  const q = { ...route.query };
  delete q.from;
  delete q.task_id;
  setTimeout(() => router.replace({ query: q }).catch(() => {}), 0);
}

// ★SW message handler（removeできるように関数化）
function onSwMessage(event) {
  const data = event?.data;
  if (data?.type !== "REMINDER_CLICK") return;

  // SW→postMessage payload をここで正規化して事故率を下げる
  openReminderModal(normalizePayload(data.payload));
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