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
import {
  computed,
  onMounted,
  onUnmounted,
  onActivated,
  onDeactivated,
  watch,
  ref,
} from "vue";
import { useRoute, useRouter } from "vue-router";

import { useWeekLoader } from "@/composables/useWeekLoader";
import { useHabitLogStore } from "@/stores/habitLogStore";

import { addDaysISO, getWeekStartISO, formatMMDD } from "@/utils/dateIso";

import WeekHeader from "@/components/week/WeekHeader.vue";
import WeekGrid from "@/components/week/WeekGrid.vue";
import WeekProgressCard from "@/components/week/WeekProgressCard.vue";

/**
 * Weekly.vue から渡される
 * reminder-action → refreshKey++ → ここで同じ週でも強制再fetch
 */
const props = defineProps({
  refreshKey: { type: Number, default: 0 },
});

const route = useRoute();
const router = useRouter();

const isActive = ref(true);

const { loading, hasLoaded, errorMessage, week, fetchWeek } = useWeekLoader();

/* ------------------------------
  Range Label
------------------------------ */
const rangeLabel = computed(() => {
  if (!week.value?.week_start || !week.value?.week_end) return "—";
  return `${formatMMDD(week.value.week_start)} 〜 ${formatMMDD(
    week.value.week_end
  )}`;
});

/* ------------------------------
  週ロード（単一入口 + 二重fetch防止）
------------------------------ */
const lastRequestedWeek = ref(null);
const inflight = ref(false);

function resolveWeekTarget(raw) {
  if (typeof raw === "string" && raw) return raw;
  // URLにweekが無い復帰時は「前回表示してた週」を維持
  if (week.value?.week_start) return week.value.week_start;
  return getWeekStartISO();
}

async function loadWeek(weekStart) {
  if (!isActive.value) return;
  if (!weekStart) return;

  // 同一weekはスキップ
  if (lastRequestedWeek.value === weekStart) return;

  // “次に欲しい週” を記録
  lastRequestedWeek.value = weekStart;

  // 既に飛行中なら、飛行中ループが lastRequestedWeek を見て追従する
  if (inflight.value) return;

  inflight.value = true;
  try {
    while (true) {
      const target = lastRequestedWeek.value;
      await fetchWeek(target);
      if (lastRequestedWeek.value === target) break;
    }
  } finally {
    inflight.value = false;
  }
}

/**
 * ★refresh用：同じ週でも必ず再fetchしたい
 * loadWeekは同一weekを弾くので、lastRequestedWeekを一旦外してから呼ぶ
 */
async function forceReloadCurrentWeek(reason = "refreshKey") {
  if (!isActive.value) return;

  const target = week.value?.week_start ?? resolveWeekTarget(route.query.week);
  if (!target) return;

  // 同一weekスキップを無効化
  lastRequestedWeek.value = null;

  await loadWeek(target);
}

async function syncQuery(weekStart) {
  await router.replace({ query: { ...route.query, week: weekStart } });
}

async function goPrevWeek() {
  const base = week.value?.week_start ?? resolveWeekTarget(route.query.week);
  const prev = addDaysISO(base, -7);
  await syncQuery(prev);
  await loadWeek(prev);
}

async function goNextWeek() {
  const base = week.value?.week_start ?? resolveWeekTarget(route.query.week);
  const next = addDaysISO(base, 7);
  await syncQuery(next);
  await loadWeek(next);
}

/* ------------------------------
  Progress（ローカル計算）
  ★SELFは rating が真実（rating===4 のみ done）
------------------------------ */
function isDoneForCount(h) {
  const type = h?.evaluation_type ?? "simple";
  const status = h?.log?.status ?? "none";
  const rating = h?.log?.rating ?? null;

  if (type === "self") return Number(rating ?? 0) === 4;
  return status === "done";
}

function ensureWeekShape() {
  if (!week.value) {
    week.value = {
      days: [],
      weekly_progress: { done: 0, total: 0, percent: 0 },
    };
    return;
  }
  if (!Array.isArray(week.value.days)) week.value.days = [];
  if (!week.value.weekly_progress) {
    week.value.weekly_progress = { done: 0, total: 0, percent: 0 };
  }
}

function recalcProgress() {
  ensureWeekShape();

  let wDone = 0;
  let wTotal = 0;

  for (const d of week.value.days ?? []) {
    const list = Array.isArray(d.habits) ? d.habits : [];
    const total = list.length;
    const done = list.filter(isDoneForCount).length;

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

// recalc を1フレームにまとめる（連続発火の圧縮）
let rafId = null;
function scheduleRecalcProgress() {
  if (rafId) cancelAnimationFrame(rafId);
  rafId = requestAnimationFrame(() => {
    rafId = null;
    recalcProgress();
  });
}

/* ------------------------------
  store wiring
------------------------------ */
const logStore = useHabitLogStore();
const owner = logStore.createOwner();
let unsub = null;

function hasDateInWeek(date) {
  const days = week.value?.days ?? [];
  return Array.isArray(days) && days.some((d) => d.date === date);
}

watch(
  () => week.value?.days,
  (days) => {
    // 非アクティブ時は裏で attach/detach しない（タブ切替の体感改善）
    if (!isActive.value) return;

    logStore.detachOwner(owner);
    if (Array.isArray(days) && days.length) logStore.attachWeek(owner, days);
    scheduleRecalcProgress();
  },
  { immediate: true, flush: "post" }
);

onMounted(() => {
  // タブ切替で KeepAlive になると mount は1回だけ。
  // activeガードを入れて、非表示時に無駄なrecalcが走らないようにする。
  unsub = logStore.subscribe((evt) => {
    if (!isActive.value) return;
    if (!hasLoaded.value) return;
    if (!evt?.date) return;
    if (!hasDateInWeek(evt.date)) return;
    scheduleRecalcProgress();
  });
});

onUnmounted(() => {
  if (unsub) unsub();
  logStore.detachOwner(owner);
  if (rafId) cancelAnimationFrame(rafId);
});

/* ------------------------------
  Toggle（ユーザー操作）
  - WeekGrid/WeekDayColumn は { date, habit } を投げてくる前提
  - habit.habit_time_id / habit.time_slot をそのまま使う
  - ★SELFは rating 主導で status を決める（真実を壊さない入口）
------------------------------ */
function resolveSlotForToggle(habit) {
  // 優先: habit.time_slot -> log.time_slot -> 0
  if (Object.prototype.hasOwnProperty.call(habit ?? {}, "time_slot")) {
    return Number(habit?.time_slot ?? 0);
  }
  if (
    habit?.log &&
    Object.prototype.hasOwnProperty.call(habit.log, "time_slot")
  ) {
    return Number(habit.log.time_slot ?? 0);
  }
  return 0;
}

async function onToggle({ date, habit }) {
  if (!date || !habit) return;

  const isSelf = (habit?.evaluation_type ?? "simple") === "self";

  let nextStatus = "none";
  let nextRating = null;

  if (isSelf) {
    const curRating = Number(habit?.log?.rating ?? 0);
    nextRating = curRating === 4 ? 0 : 4;
    nextStatus = nextRating === 4 ? "done" : "none";
  } else {
    const curStatus = habit?.log?.status ?? "none";
    nextStatus = curStatus === "done" ? "none" : "done";
    nextRating = null;
  }

  const slot = resolveSlotForToggle(habit);

  // ★重要：store の optimistic が habitObj.time_slot を参照するので、slot を確定させて渡す
  const habitForToggle = { ...habit, time_slot: slot };

  const p = logStore.toggle(
    date,
    habitForToggle,
    { status: nextStatus, rating: nextRating },
    "all",
    { source: "week", time_slot: slot }
  );

  // 即時反映（optimistic後の値で再計算）
  scheduleRecalcProgress();

  try {
    await p;
  } finally {
    scheduleRecalcProgress();
  }
}

/* ------------------------------
  初回ロード & 週切替（fetchの単一入口）
  + KeepAlive対応（activated/deactivated）
------------------------------ */
onMounted(async () => {
  isActive.value = true;

  const initial = resolveWeekTarget(route.query.week);
  await loadWeek(initial);

  // URLにweekが無い場合は「実際にロードできた週」を同期（より安全）
  if (!route.query.week && week.value?.week_start) {
    await syncQuery(week.value.week_start);
  }
});

onActivated(async () => {
  isActive.value = true;

  // 復帰時にURLからweekが消えてたら、表示中の週で復元
  if (hasLoaded.value && week.value?.week_start && !route.query.week) {
    await syncQuery(week.value.week_start);
  }

  // 非表示中に week が変わっていた（URL操作等）ケースの追従
  const target = resolveWeekTarget(route.query.week);
  if (target && target !== week.value?.week_start) {
    await loadWeek(target);
  } else {
    // 既存表示でOKなら、attach/progressだけ整える
    const days = week.value?.days ?? [];
    logStore.detachOwner(owner);
    if (Array.isArray(days) && days.length) logStore.attachWeek(owner, days);
    scheduleRecalcProgress();
  }
});

onDeactivated(() => {
  isActive.value = false;
  // KeepAliveで残るので、非表示中は参照も外して完全に静かにする
  logStore.detachOwner(owner);
});

// 週ページ以外へ移動した時は watch を止めたいので route.name ガードを入れる
watch(
  () => (route.name === "week" ? route.query.week : null),
  async (v) => {
    if (!isActive.value) return;

    const next = resolveWeekTarget(v);
    if (!next) return;

    // 既にその週が表示されているなら、クエリだけ補完して終わり
    if (hasLoaded.value && week.value?.week_start === next) {
      if (!route.query.week) await syncQuery(week.value.week_start);
      return;
    }

    await loadWeek(next);
  }
);

/* ------------------------------
  ★refreshKey（モーダル操作同期）
------------------------------ */
watch(
  () => props.refreshKey,
  async (next, prev) => {
    if (next === prev) return;
    await forceReloadCurrentWeek("refreshKey");
  }
);
</script>
