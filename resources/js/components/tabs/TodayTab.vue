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

            <section class="space-y-2 mb-6">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">今日の達成率</span>
                    <span class="text-sm font-semibold">{{ progressPct }}%</span>
                </div>

                <div class="w-full h-2 rounded-full bg-gray-200 overflow-hidden">
                    <div
                        class="h-full bg-green-500 transition-all"
                        :style="{ width: progressPct + '%' }"
                    />
                </div>

                <p class="text-xs text-gray-500">
                    {{ tabProgress.done }} / {{ tabProgress.total }} 件完了
                </p>
            </section>

            <TodaySlotView
                v-if="isSlotTab"
                :slot="resolvedTab"
                :today="today"
                :habits="habits"
                :now-slot="nowSlot"
                :next-slot="nextSlot"
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
import { ref, computed, watch, onMounted, onUnmounted } from "vue";

import TodaySlotView from "@/components/today/TodaySlotView.vue";
import TodayAllView from "@/components/today/TodayAllView.vue";

import { useTodayLoader } from "@/composables/useTodayLoader";
import { useHabitLogStore } from "@/stores/habitLogStore";

import { enumToSlotKey, isSlotKey } from "@/utils/slot";
import { computeTabProgress } from "@/utils/todayProgress";

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

const { today, nowSlot, nextSlot, habits, topPick, loading, errorMessage } = useTodayLoader();

const resolvedTab = computed(() => {
    if (activeTab.value === "auto") return enumToSlotKey(nowSlot.value) ?? "all";
    return activeTab.value;
});
const isSlotTab = computed(() => isSlotKey(resolvedTab.value));

const tabProgress = ref({ scope: "all", done: 0, total: 0, percent: 0 });
const progressPct = computed(() => tabProgress.value?.percent ?? 0);

function recomputeProgress() {
    const scope = resolvedTab.value;
    tabProgress.value = computeTabProgress(habits.value, scope);
}

watch([resolvedTab, habits], () => recomputeProgress(), { immediate: true });

/* ---- store wiring ---- */
const logStore = useHabitLogStore();
const owner = logStore.createOwner();
let unsub = null;

watch(
    [today, habits],
    ([d, hs]) => {
        // 付け替え（漏れ防止）
        logStore.detachOwner(owner);
        if (d && hs?.length) logStore.attachHabits(owner, d, hs);
        recomputeProgress();
    },
    { immediate: true }
);

onMounted(() => {
    unsub = logStore.subscribe((evt) => {
        // 今日の日付のlog更新だけ拾えばOK
        if (!today.value || evt.date !== today.value) return;
        recomputeProgress();
    });
});

onUnmounted(() => {
    if (unsub) unsub();
    logStore.detachOwner(owner);
});

/* ---- UI update ---- */
async function onRowUpdate({ habit, payload }) {
    const scope = resolvedTab.value;
    const date = today.value;

    // 次状態（WeekTabと同じルール）
    const curStatus = habit.log?.status ?? "none";
    const nextStatus = curStatus === "done" ? "none" : "done";
    const isSelf = habit.evaluation_type === "self";
    const nextRating = isSelf ? (nextStatus === "done" ? 4 : 0) : null;

    // store.toggle は内部で optimistic するので、直後に再計算すれば即反映になる
    const p = logStore.toggle(
        date,
        habit,
        { status: nextStatus, rating: nextRating },
        scope,
        { source: "today" }
    );

    // optimistic反映（同期でlogが書き換わってる）
    recomputeProgress();

    try {
        await p;
        recomputeProgress();
    } catch (e) {
        recomputeProgress();
        throw e;
    }
}
</script>
