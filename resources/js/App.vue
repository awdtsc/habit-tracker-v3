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

// ★URLクエリ保険：/today?from=push&task_id=... で開かれたらモーダルを開く
function openFromQueryIfNeeded() {
  if (route.query.from !== "push") return;

  const taskId = route.query.task_id ?? null;

  openReminderModal({
    title: "Habit Reminder",
    body: "",
    task_id: taskId != null ? Number(taskId) || String(taskId) : null,
    url: route.fullPath,
  });

  // 連続発火防止：クエリ掃除（描画と競合させない）
  const q = { ...route.query };
  delete q.from;
  delete q.task_id;
  setTimeout(() => router.replace({ query: q }).catch(() => {}), 0);
}

// ★SW message handler（removeできるように関数化）
function onSwMessage(event) {
  const data = event?.data;
  if (data?.type === "REMINDER_CLICK") {
    openReminderModal(data.payload ?? null);
  }
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

watch(() => route.name, (name) => prefetchOpposite(name));

// ルート変更時にも push クエリを拾う（openWindowで遷移したケース）
watch(() => route.fullPath, () => openFromQueryIfNeeded());
</script>
