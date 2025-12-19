<!-- resources/js/components/tabs/WeekTab.vue -->
<template>
  <div class="p-4 md:p-6 space-y-6">
    <WeekHeader
      :range-label="rangeLabel"
      :disabled="loading && !hasLoaded"
      @prev="goPrevWeek"
      @next="goNextWeek"
    />

    <div v-if="!hasLoaded && loading" class="text-gray-500 text-sm">
      読み込み中…
    </div>

    <div v-if="errorMessage" class="text-red-500 text-sm">
      {{ errorMessage }}
    </div>

    <template v-if="hasLoaded">
      <WeekGrid :days="week.days" @toggle="onToggle" />
      <WeekProgressCard :days="week.days" :weekly-progress="week.weekly_progress" />
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";

import { useWeekLoader } from "@/composables/useWeekLoader";
import { useHabitLogStore } from "@/stores/habitLogStore";

import { addDaysISO, getWeekStartISO, formatMMDD } from "@/utils/dateIso";

import WeekHeader from "@/components/week/WeekHeader.vue";
import WeekGrid from "@/components/week/WeekGrid.vue";
import WeekProgressCard from "@/components/week/WeekProgressCard.vue";

const route = useRoute();
const router = useRouter();

const { loading, hasLoaded, errorMessage, week, fetchWeek } = useWeekLoader();

const rangeLabel = computed(() => {
  if (!week.value.week_start || !week.value.week_end) return "—";
  return `${formatMMDD(week.value.week_start)} 〜 ${formatMMDD(week.value.week_end)}`;
});

function syncQuery(weekStart) {
  router.replace({ query: { ...route.query, week: weekStart } });
}

async function goPrevWeek() {
  const base = week.value.week_start || getWeekStartISO();
  const prev = addDaysISO(base, -7);
  syncQuery(prev);
  await fetchWeek(prev);
}

async function goNextWeek() {
  const base = week.value.week_start || getWeekStartISO();
  const next = addDaysISO(base, 7);
  syncQuery(next);
  await fetchWeek(next);
}

/* ------------------------------
  Progress（ローカル計算）
------------------------------ */
function isDoneForCount(h) {
  const type = h.evaluation_type;
  const status = h.log?.status ?? "none";
  const rating = h.log?.rating ?? null;
  if (type === "self") return rating === 4 || status === "done";
  return status === "done";
}

function recalcProgress() {
  let wDone = 0;
  let wTotal = 0;

  for (const d of week.value.days ?? []) {
    const total = d.habits?.length ?? 0;
    const done = (d.habits ?? []).filter(isDoneForCount).length;

    d.progress = {
      done,
      total,
      percent: total === 0 ? 0 : Math.round((done / total) * 100),
    };

    wDone += done;
    wTotal += total;
  }

  week.value.weekly_progress = {
    done: wDone,
    total: wTotal,
    percent: wTotal === 0 ? 0 : Math.round((wDone / wTotal) * 100),
  };
}

/* ------------------------------
  store wiring
------------------------------ */
const logStore = useHabitLogStore();
const owner = logStore.createOwner();
let unsub = null;

function hasDateInWeek(date) {
  return (week.value.days ?? []).some((d) => d.date === date);
}

watch(
  () => week.value.days,
  (days) => {
    logStore.detachOwner(owner);
    if (days?.length) logStore.attachWeek(owner, days);
    recalcProgress();
  },
  { immediate: true }
);

onMounted(() => {
  unsub = logStore.subscribe((evt) => {
    if (!hasLoaded.value) return;
    if (!hasDateInWeek(evt.date)) return;
    recalcProgress();
  });
});

onUnmounted(() => {
  if (unsub) unsub();
  logStore.detachOwner(owner);
});

/* ------------------------------
  Toggle（ユーザー操作）
------------------------------ */
async function onToggle({ date, habit }) {
  const curStatus = habit.log?.status ?? "none";
  const nextStatus = curStatus === "done" ? "none" : "done";
  const isSelf = habit.evaluation_type === "self";
  const nextRating = isSelf ? (nextStatus === "done" ? 4 : 0) : null;

  const p = logStore.toggle(
    date,
    habit,
    { status: nextStatus, rating: nextRating },
    "all",
    { source: "week" }
  );

  // optimistic反映済みなので即再計算
  recalcProgress();

  try {
    await p;
    recalcProgress();
  } catch {
    recalcProgress();
  }
}

/* ------------------------------
  初回ロード & 週切替
------------------------------ */
onMounted(async () => {
  const qsWeek = typeof route.query.week === "string" ? route.query.week : null;
  await fetchWeek(qsWeek || getWeekStartISO());
});

watch(
  () => route.query.week,
  async (v) => {
    if (typeof v === "string" && v && v !== week.value.week_start) {
      await fetchWeek(v);
    }
  }
);
</script>
