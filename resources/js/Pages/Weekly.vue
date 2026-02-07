<!-- resources/js/Pages/Weekly.vue -->
<template>
  <div class="min-h-screen bg-gray-50">
    <AppHeader />
    <main class="max-w-5xl mx-auto">
      <div v-if="!ready" class="p-4 md:p-6 text-sm text-gray-500">
        読み込み中…
      </div>

      <!-- ✅ refreshKey で確実に再生成（同期の最終手段） -->
      <WeekTab v-else :key="refreshKey" :refresh-key="refreshKey" />
    </main>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import AppHeader from "@/components/layout/AppHeader.vue";
import WeekTab from "@/components/tabs/WeekTab.vue";
import { onReminderAction } from "@/state/reminderActionBus";

defineOptions({ name: "WeeklyPage" });

const ready = ref(false);
const refreshKey = ref(0);

function bumpRefresh() {
  refreshKey.value++;
}

/**
 * ✅ reminderActionBus 経由で WeekTab を同期
 * - 要件: weekタブのときもモーダルで「完了(done)」なら同期
 * - 方針:
 *   - done だけ同期（cancel/snoozeは現状スルー）
 *   - 二重発火対策の dedupe
 */
const recent = new Map();
const DEDUPE_MS = 1500;

function pickActionType(d) {
  return d?.type ?? d?.action ?? d?.kind ?? d?.event ?? null;
}

function buildKey(d) {
  const type = pickActionType(d) ?? "unknown";
  const task = d?.task_id ?? "none";
  const date = d?.payload?.date ?? "none";
  const habitTime = d?.payload?.habit_time_id ?? "none";
  return `${type}|${task}|${date}|${habitTime}`;
}

function shouldBump(d) {
  const now = Date.now();

  for (const [k, ts] of recent.entries()) {
    if (now - ts > DEDUPE_MS) recent.delete(k);
  }

  const key = buildKey(d);
  const prev = recent.get(key);
  if (typeof prev === "number" && now - prev <= DEDUPE_MS) return false;

  recent.set(key, now);
  return true;
}

let offAction = null;

onMounted(() => {
  let painted = false;

  requestAnimationFrame(() => {
    painted = true;
    ready.value = true;
  });

  setTimeout(() => {
    if (!painted) ready.value = true;
  }, 80);

  offAction = onReminderAction((evt) => {
    const d = evt ?? null;
    if (!d) return;

    if (pickActionType(d) !== "done") return;
    if (!shouldBump(d)) return;

    bumpRefresh();
  });
});

onUnmounted(() => {
  if (typeof offAction === "function") offAction();
  offAction = null;
});
</script>