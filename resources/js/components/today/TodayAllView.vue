<!-- resources/js/components/today/TodayAllView.vue -->
<template>
    <div class="space-y-10">
        <!-- ========================================================= -->
        <!-- 未完了 -->
        <!-- ========================================================= -->
        <section class="space-y-3">
            <h2 class="text-lg font-semibold">未完了</h2>

            <TodayActionableSection
                v-if="undone.length > 0"
                :habits="undone"
                :today="today"
                @update="onRowUpdate"
            />

            <p v-else class="text-sm text-gray-500 pl-1">
                未完了の習慣はありません。
            </p>
        </section>

        <!-- ========================================================= -->
        <!-- 完了 -->
        <!-- ========================================================= -->
        <section v-if="done.length > 0" class="space-y-3">
            <h2 class="text-lg font-semibold text-gray-700">完了</h2>

            <TodayDoneSection
                :habits="done"
                :today="today"
                @update="onRowUpdate"
            />
        </section>
    </div>
</template>

<script setup>
import { computed } from "vue";

import TodayActionableSection from "@/components/today/TodayActionableSection.vue";
import TodayDoneSection from "@/components/today/TodayDoneSection.vue";

import { useHabitToggle } from "@/composables/useHabitToggle";
import { sortHabitsByPriority } from "@/utils/habitPriority";

/* ----------------------------------------------------------
   props（TodayTab から注入）
---------------------------------------------------------- */
const props = defineProps({
    today: {
        type: String,
        required: true,
    },
    habits: {
        type: Array,
        required: true,
    },
    nowSlot: {
        type: Number,
        required: true,
    },
});

/* ----------------------------------------------------------
   判定
---------------------------------------------------------- */
const isDone = (h) => h?.log?.status === "done";

/* ----------------------------------------------------------
   未完了（優先順位ロジック共通）
---------------------------------------------------------- */
const undone = computed(() =>
    sortHabitsByPriority(
        props.habits.filter((h) => !isDone(h)),
        props.nowSlot
    )
);

/* ----------------------------------------------------------
   完了（順序は気にしない／必要なら後で調整）
---------------------------------------------------------- */
const done = computed(() => props.habits.filter((h) => isDone(h)));

/* ----------------------------------------------------------
   Toggle（他タブと完全共通）
---------------------------------------------------------- */
const { toggle } = useHabitToggle(computed(() => props.today));

async function onRowUpdate({ habit, payload }) {
    const { log } = await toggle(habit, payload);
    habit.log = { ...log };
}
</script>
