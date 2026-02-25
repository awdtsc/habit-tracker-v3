<!-- resources/js/components/tabs/TodayTab.vue -->
<template>
  <div class="p-4 md:p-6 space-y-6">
    <header>
      <h1 class="text-2xl font-semibold">今日</h1>
      <p class="text-sm text-gray-500">{{ today }}</p>
    </header>

    <div v-if="loading" class="text-gray-500 text-sm">読み込み中…</div>

    <div v-else-if="errorMessage" class="text-red-500 text-sm">
      {{ errorMessage }}
    </div>

    <div v-else>
      <!-- tabs -->
      <div class="flex items-center gap-2 border-b pb-2 mb-4">
        <button
          v-for="t in tabs"
          :key="t.key"
          :class="[
            'px-3 py-1 rounded-full text-sm border transition',
            activeTab === t.key
              ? 'bg-blue-600 text-white border-blue-600'
              : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100',
          ]"
          @click="activeTab = t.key"
        >
          {{ t.label }}
        </button>
      </div>

      <!-- progress -->
      <section class="space-y-2 mb-6">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-600">今日の達成率</span>
          <span class="text-sm font-semibold">{{ progressPct }}%</span>
        </div>

        <div class="w-full h-2 rounded-full bg-gray-200 overflow-hidden">
          <div class="h-full bg-green-500 transition-all" :style="{ width: progressPct + '%' }" />
        </div>

        <p class="text-xs text-gray-500">
          {{ uiProgress.done }} / {{ uiProgress.total }} 件完了
        </p>
      </section>

      <!-- content -->
      <TodaySlotView
        v-if="isSlotTab"
        :slot="resolvedTab"
        :today="today"
        :habits="habits"
        :now-slot="nowSlot"
        :next-slot="nextSlot"
        :show-next="showNext"
        @update="onRowUpdate"
      />

      <TodayAllView
        v-if="activeTab === 'all'"
        :today="today"
        :habits="habits"
        :now-slot="nowSlot"
        :top-pick="topPick"
        @update="onRowUpdate"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";

import TodaySlotView from "@/components/today/TodaySlotView.vue";
import TodayAllView from "@/components/today/TodayAllView.vue";

import { useHabitLogStore } from "@/stores/habitLogStore";
import { enumToSlotKey, isSlotKey } from "@/utils/slot";

const props = defineProps({
  // Pages/Today.vue から渡される：reminder-action → refreshKey++
  refreshKey: { type: Number, default: 0 },
});

const TAB_STORAGE_KEY = "today-current-tab";

const tabs = [
  { key: "auto", label: "自動" },
  { key: "morning", label: "朝" },
  { key: "day", label: "昼" },
  { key: "evening", label: "夕" },
  { key: "night", label: "夜" },
  { key: "all", label: "すべて" },
];

const activeTab = ref(localStorage.getItem(TAB_STORAGE_KEY) ?? "auto");
watch(activeTab, (v) => localStorage.setItem(TAB_STORAGE_KEY, v));

/**
 * resolvedTab: 表示用（auto は nowSlot に解決）
 */
const resolvedTab = computed(() => {
  if (activeTab.value === "auto") return enumToSlotKey(nowSlot.value) ?? "all";
  return activeTab.value;
});
const isSlotTab = computed(() => isSlotKey(resolvedTab.value));

/**
 * ★重要：Next表示のため、Today API は常に scope=all で取る
 */
const apiScope = computed(() => "all");

/* ==============================
   Today API loader（refreshKey対応）
   Route: GET api/v1/today
================================ */
const today = ref("");
const nowSlot = ref(0);
const nextSlot = ref(0);
const habits = ref([]);
const topPick = ref(null);

const loading = ref(false);
const errorMessage = ref("");

let aborter = null;

async function fetchTodayAll() {
  if (aborter) aborter.abort();
  aborter = new AbortController();

  const url = `/api/v1/today?scope=${encodeURIComponent(apiScope.value)}`;

  const res = await fetch(url, {
    method: "GET",
    credentials: "same-origin",
    headers: { Accept: "application/json" },
    signal: aborter.signal,
  });

  if (res.status === 401) {
    throw new Error("未ログインです（401）。ログイン後に再読み込みしてください。");
  }
  if (!res.ok) {
    const txt = await res.text().catch(() => "");
    throw new Error(`HTTP ${res.status}\n${txt || "response body is empty"}`);
  }

  return await res.json();
}

async function reloadToday(reason = "unknown") {
  errorMessage.value = "";
  loading.value = true;

  try {
    const data = await fetchTodayAll();

    today.value = String(data?.today ?? "");
    nowSlot.value = Number(data?.now_slot ?? 0);
    nextSlot.value = Number(data?.next_slot ?? 0);

    habits.value = Array.isArray(data?.habits) ? data.habits : [];
    topPick.value = data?.top_pick ?? null;
  } catch (e) {
    if (e?.name === "AbortError") return;
    errorMessage.value = String(e?.message ?? e);
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  reloadToday("mount");
});

// ★モーダル操作 → Pages/Today.vue が refreshKey++ → ここで再取得
watch(
  () => props.refreshKey,
  (next, prev) => {
    if (next === prev) return;
    reloadToday("refreshKey");
  }
);

/**
 * ★仕様：Nextは「nowSlotのタブを見ているときだけ」出す
 */
const showNext = computed(() => {
  const nowKey = enumToSlotKey(nowSlot.value);
  if (!nowKey) return false;
  return resolvedTab.value === nowKey;
});

/* ==============================
   Progress（ローカル計算）
================================ */
const progressScopeKey = computed(() => {
  return activeTab.value === "auto" ? resolvedTab.value : activeTab.value;
});

function scopeKeyToSlot(scopeKey) {
  switch (scopeKey) {
    case "morning":
      return 1;
    case "day":
      return 2;
    case "evening":
      return 3;
    case "night":
      return 4;
    default:
      return 0; // all
  }
}

function isDoneForCount(habit) {
  const type = habit?.evaluation_type ?? "simple";
  const status = habit?.log?.status ?? "none";
  const rating = habit?.log?.rating ?? null;

  if (type === "self") return Number(rating ?? 0) === 4;
  return status === "done";
}

const uiProgress = computed(() => {
  const scopeKey = progressScopeKey.value ?? "all";
  const list = Array.isArray(habits.value) ? habits.value : [];

  if (scopeKey === "all") {
    const total = list.length;
    const done = list.filter(isDoneForCount).length;
    return {
      scope: "all",
      done,
      total,
      percent: total === 0 ? 0 : Math.round((done / total) * 100),
    };
  }

  const slot = scopeKeyToSlot(scopeKey);
  const filtered = list.filter((h) => Number(h.time_slot ?? 0) === slot);

  const total = filtered.length;
  const done = filtered.filter(isDoneForCount).length;

  return {
    scope: scopeKey,
    done,
    total,
    percent: total === 0 ? 0 : Math.round((done / total) * 100),
  };
});

const progressPct = computed(() => uiProgress.value.percent);

/* ==============================
   store wiring（log参照の共有）
================================ */
const logStore = useHabitLogStore();
const owner = logStore.createOwner();

watch(
  [today, habits],
  ([d, hs]) => {
    // 古い参照が積み上がらないよう必ず掃除→付け直し
    logStore.detachOwner(owner);
    if (d && hs?.length) logStore.attachHabits(owner, d, hs);
  },
  { immediate: true }
);

onBeforeUnmount(() => {
  logStore.detachOwner(owner);
  if (aborter) aborter.abort();
});

/* ==============================
   Toggle（ユーザー操作）
================================ */
async function onRowUpdate({ habit, payload }) {
  const scope = apiScope.value; // 常に all
  const date = today.value;
  const type = habit.evaluation_type;

  let nextStatus = payload?.status ?? null;
  let nextRating = Object.prototype.hasOwnProperty.call(payload ?? {}, "rating")
    ? payload.rating
    : null;

  if (type === "self") {
    if (nextRating !== null) {
      nextStatus = nextRating === 4 ? "done" : "none";
    } else if (nextStatus === null) {
      const cur = habit.log?.rating ?? 0;
      nextRating = Number(cur) === 4 ? 0 : 4;
      nextStatus = nextRating === 4 ? "done" : "none";
    }
  } else {
    if (nextStatus === null) {
      const cur = habit.log?.status ?? "none";
      nextStatus = cur === "done" ? "none" : "done";
    }
    nextRating = null;
  }

  return await logStore.toggle(
    date,
    habit,
    { status: nextStatus, rating: nextRating },
    scope,
    { source: "today" }
  );
}
</script>
