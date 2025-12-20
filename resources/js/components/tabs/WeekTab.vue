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
      <WeekProgressCard
        :days="week.days"
        :weekly-progress="week.weekly_progress"
      />
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, watch, ref } from "vue";
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

/* ------------------------------
  Range Label
------------------------------ */
const rangeLabel = computed(() => {
  if (!week.value.week_start || !week.value.week_end) return "—";
  return `${formatMMDD(week.value.week_start)} 〜 ${formatMMDD(week.value.week_end)}`;
});

/* ------------------------------
  週ロード（単一入口 + 二重fetch防止）
------------------------------ */
const lastRequestedWeek = ref(null);
const inflight = ref(false);

async function loadWeek(weekStart) {
  if (!weekStart) return;

  // 同じ週を連打しても fetch しない
  if (lastRequestedWeek.value === weekStart) return;

  // in-flight中は「最後に要求された週」だけに寄せる
  lastRequestedWeek.value = weekStart;
  if (inflight.value) return;

  inflight.value = true;
  try {
    // ここで lastRequestedWeek が変わる可能性があるのでループで吸収
    while (true) {
      const target = lastRequestedWeek.value;
      await fetchWeek(target);

      // fetch中に別週が要求されていなければ終了
      if (lastRequestedWeek.value === target) break;
    }
  } finally {
    inflight.value = false;
  }
}

async function syncQuery(weekStart) {
  // query更新だけ。fetchはwatch側の単一入口で行う
  await router.replace({ query: { ...route.query, week: weekStart } });
}

async function goPrevWeek() {
  const base = week.value.week_start || getWeekStartISO();
  const prev = addDaysISO(base, -7);
  await syncQuery(prev);
}

async function goNextWeek() {
  const base = week.value.week_start || getWeekStartISO();
  const next = addDaysISO(base, 7);
  await syncQuery(next);
}

/* ------------------------------
  Progress（ローカル計算）
  ★SELFは rating が真実（rating===4 のみ done）
------------------------------ */
function isDoneForCount(h) {
  const type = h.evaluation_type;
  const status = h.log?.status ?? "none";
  const rating = h.log?.rating ?? null;

  if (type === "self") return Number(rating ?? 0) === 4;
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
  ★SELFは rating 主導で status を決める（真実を壊さない入口）
------------------------------ */
async function onToggle({ date, habit }) {
  const isSelf = habit.evaluation_type === "self";

  let nextStatus = "none";
  let nextRating = null;

  if (isSelf) {
    const curRating = Number(habit.log?.rating ?? 0);
    nextRating = curRating === 4 ? 0 : 4;
    nextStatus = nextRating === 4 ? "done" : "none";
  } else {
    const curStatus = habit.log?.status ?? "none";
    nextStatus = curStatus === "done" ? "none" : "done";
    nextRating = null;
  }

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
  } finally {
    // 成功でも失敗でも、最終状態で再計算（store側ロールバックも吸収）
    recalcProgress();
  }
}

/* ------------------------------
  初回ロード & 週切替（fetchの単一入口）
------------------------------ */
onMounted(async () => {
  const qsWeek = typeof route.query.week === "string" ? route.query.week : null;
  const initial = qsWeek || getWeekStartISO();
  await loadWeek(initial);

  // 初回に query.week が無いなら、URLも揃える（任意だけどデバッグが楽）
  if (!qsWeek) {
    await syncQuery(initial);
  }
});

watch(
  () => route.query.week,
  async (v) => {
    const next = typeof v === "string" && v ? v : getWeekStartISO();
    if (next !== week.value.week_start) {
      await loadWeek(next);
    }
  }
);
</script>
