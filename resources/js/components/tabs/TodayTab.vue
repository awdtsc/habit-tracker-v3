<!-- resources/js/components/tabs/TodayTab.vue -->
<template>
    <div class="p-4 md:p-6 space-y-6">
        <!-- ============================================================= -->
        <!-- ヘッダー -->
        <!-- ============================================================= -->
        <header>
            <h1 class="text-2xl font-semibold">今日</h1>
            <p class="text-sm text-gray-500">{{ today }}</p>
        </header>

        <!-- ============================================================= -->
        <!-- ロード中 / エラー -->
        <!-- ============================================================= -->
        <div v-if="loading" class="text-gray-500 text-sm">読み込み中…</div>

        <div v-if="!loading && errorMessage" class="text-red-500 text-sm">
            {{ errorMessage }}
        </div>

        <!-- ============================================================= -->
        <!-- メイン -->
        <!-- ============================================================= -->
        <div v-if="!loading && !errorMessage">
            <!-- --------------------------------------------------------- -->
            <!-- タブ -->
            <!-- --------------------------------------------------------- -->
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

            <!-- --------------------------------------------------------- -->
            <!-- 達成率 -->
            <!-- --------------------------------------------------------- -->
            <section class="space-y-2 mb-6">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">今日の達成率</span>
                    <span class="text-sm font-semibold">
                        {{ progressPct }}%
                    </span>
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
                    {{ progress.done_count }} /
                    {{ progress.planned_count }} 件完了
                </p>
            </section>

            <!-- --------------------------------------------------------- -->
            <!-- スロットタブ（自動 / 朝 / 昼 / 夕 / 夜） -->
            <!-- --------------------------------------------------------- -->
            <TodaySlotView
                v-if="isSlotTab"
                :slot="resolvedTab"
                :today="today"
                :habits="habits"
                :progress="progress"
                :now-slot="nowSlot"
                @update="onRowUpdate"
            />

            <!-- --------------------------------------------------------- -->
            <!-- すべて -->
            <!-- --------------------------------------------------------- -->
            <TodayAllView
                v-if="activeTab === 'all'"
                :today="today"
                :habits="habits"
                :now-slot="nowSlot"
            />
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from "vue";

/* ---------------------------------------------------------
   components
--------------------------------------------------------- */
import TodaySlotView from "@/components/today/TodaySlotView.vue";
import TodayAllView from "@/components/today/TodayAllView.vue";

/* ---------------------------------------------------------
   composables
--------------------------------------------------------- */
import { useTodayLoader } from "@/composables/useTodayLoader";
import { useTodayFilters } from "@/composables/useTodayFilters";
import { useHabitToggle } from "@/composables/useHabitToggle";

/* ---------------------------------------------------------
   utils
--------------------------------------------------------- */
import { enumToSlotKey, isSlotKey } from "@/utils/slot";

/* ---------------------------------------------------------
   constants
--------------------------------------------------------- */
const TAB_STORAGE_KEY = "today-current-tab";

/* ---------------------------------------------------------
   tabs
--------------------------------------------------------- */
const tabs = [
    { key: "auto", label: "自動" },
    { key: "morning", label: "朝" },
    { key: "day", label: "昼" },
    { key: "evening", label: "夕" },
    { key: "night", label: "夜" },
    { key: "all", label: "すべて" },
];

/* ---------------------------------------------------------
   activeTab
--------------------------------------------------------- */
const activeTab = ref(localStorage.getItem(TAB_STORAGE_KEY) ?? "auto");

watch(activeTab, (v) => {
    localStorage.setItem(TAB_STORAGE_KEY, v);
});

/* ---------------------------------------------------------
   today data
--------------------------------------------------------- */
const { today, nowSlot, habits, progress, loading, errorMessage } =
    useTodayLoader();

/* ---------------------------------------------------------
   slot resolve
--------------------------------------------------------- */
const resolvedTab = computed(() => {
    if (activeTab.value === "auto") {
        return enumToSlotKey(nowSlot.value);
    }
    return activeTab.value;
});

const isSlotTab = computed(() => isSlotKey(resolvedTab.value));

/* ---------------------------------------------------------
   progress
--------------------------------------------------------- */
const { progressPct } = useTodayFilters(habits, progress, nowSlot);

/* ---------------------------------------------------------
   toggle
--------------------------------------------------------- */
const { toggle } = useHabitToggle(today);

async function onRowUpdate({ habit, payload }) {
    const { log } = await toggle(habit, payload);
    habit.log = { ...log };
}
</script>
