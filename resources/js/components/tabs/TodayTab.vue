<!-- resources/js/components/tabs/TodayTab.vue -->
<template>
    <div class="p-4 md:p-6 space-y-6">
        <!-- ヘッダー -->
        <header>
            <h1 class="text-2xl font-semibold">今日</h1>
            <p class="text-sm text-gray-500">{{ today }}</p>
        </header>

        <!-- ロード中 / エラー -->
        <div v-if="loading" class="text-gray-500 text-sm">読み込み中…</div>

        <div v-else-if="errorMessage" class="text-red-500 text-sm">
            {{ errorMessage }}
        </div>

        <!-- メイン -->
        <div v-else>
            <!-- タブ -->
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

            <!-- 達成率（表示の真実：フロント算出） -->
            <section class="space-y-2 mb-6">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">今日の達成率</span>
                    <span class="text-sm font-semibold"
                        >{{ progressPct }}%</span
                    >
                </div>

                <div
                    class="w-full h-2 rounded-full bg-gray-200 overflow-hidden"
                >
                    <div
                        class="h-full bg-blue-500 transition-all"
                        :style="{ width: progressPct + '%' }"
                    />
                </div>

                <p class="text-xs text-gray-500">
                    {{ tabProgress.done }} / {{ tabProgress.total }} 件完了
                </p>
            </section>

            <!-- スロットタブ -->
            <TodaySlotView
                v-if="isSlotTab"
                :slot="resolvedTab"
                :today="today"
                :habits="habits"
                :now-slot="nowSlot"
                :next-slot="nextSlot"
                @update="onRowUpdate"
            />

            <!-- すべて -->
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

    <!-- デバッグ（任意） -->
    <!-- <pre class="text-xs bg-gray-100 p-2 mt-4">{{ tabProgress }}</pre> -->
</template>

<script setup>
import { ref, computed, watch } from "vue";

/* components */
import TodaySlotView from "@/components/today/TodaySlotView.vue";
import TodayAllView from "@/components/today/TodayAllView.vue";

/* composables */
import { useTodayLoader } from "@/composables/useTodayLoader";
import { useHabitToggle } from "@/composables/useHabitToggle";

/* utils */
import { enumToSlotKey, isSlotKey } from "@/utils/slot";
import {
    computeTabProgress,
    computeOptimisticProgress,
} from "@/utils/todayProgress";

/* constants */
const TAB_STORAGE_KEY = "today-current-tab";

/* tabs */
const tabs = [
    { key: "auto", label: "自動" },
    { key: "morning", label: "朝" },
    { key: "day", label: "昼" },
    { key: "evening", label: "夕" },
    { key: "night", label: "夜" },
    { key: "all", label: "すべて" },
];

/* activeTab（UI 状態） */
const activeTab = ref(localStorage.getItem(TAB_STORAGE_KEY) ?? "auto");
watch(activeTab, (v) => localStorage.setItem(TAB_STORAGE_KEY, v));

/* today data（初回のみfetch） */
const { today, nowSlot, nextSlot, habits, topPick, loading, errorMessage } = useTodayLoader();

/* resolvedTab（UI上の“今のスコープ”） */
const resolvedTab = computed(() => {
    if (activeTab.value === "auto") {
        return enumToSlotKey(nowSlot.value) ?? "all";
    }
    return activeTab.value;
});
const isSlotTab = computed(() => isSlotKey(resolvedTab.value));

/* 表示の真実：タブ別 progress（フロント算出） */
const tabProgress = ref({
    scope: "all",
    done: 0,
    total: 0,
    percent: 0,
});

// 初回ロード後 / タブ変更時に算出（ネスト変更までは追わない）
watch(
    [resolvedTab, habits],
    ([scope]) => {
        tabProgress.value = computeTabProgress(habits.value, scope);
    },
    { immediate: true }
);

const progressPct = computed(() => tabProgress.value?.percent ?? 0);

/* toggle */
const { toggle } = useHabitToggle();

async function onRowUpdate({ habit, payload }) {
    const scope = resolvedTab.value;

    const prev = { ...(tabProgress.value ?? {}) };

    // 1) optimistic：progressだけ即時
    const optimistic = computeOptimisticProgress(prev, habit, payload, scope);
    if (optimistic) tabProgress.value = optimistic;

    try {
        // 2) backend：toggle（log確定）
        const result = await toggle(habit, {
            ...payload,
            scope, // 互換のため残す（progressは無視するが、現状のAPI形を壊さない）
        });
        if (!result) return;

        if (result.log) {
            habit.log = { ...result.log };
        }

        // 3) 確定：habitsから再計算（表示の真実）
        tabProgress.value = computeTabProgress(habits.value, scope);

        // 任意：backend progress と差があるなら警告（調査用）
        if (result.progress && result.progress.scope === scope) {
            const a = tabProgress.value;
            const b = result.progress;
            if (a.done !== b.done || a.total !== b.total || a.percent !== b.percent) {
                console.warn("[progress mismatch]", { ui: a, api: b });
            }
        }
    } catch (e) {
        // 失敗したら rollback
        tabProgress.value = prev;
        throw e;
    }
}
</script>
