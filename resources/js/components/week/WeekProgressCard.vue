<template>
    <section class="rounded-2xl border bg-white p-4 md:p-6 space-y-3">
        <div class="flex items-baseline justify-between">
            <h2 class="text-base font-semibold">今週の達成率</h2>
            <div class="text-sm text-gray-600 tabular-nums">
                {{ weeklyProgress?.percent ?? 0 }}%
                <span class="text-gray-400">
                    ({{ weeklyProgress?.done ?? 0 }}/{{ weeklyProgress?.total ?? 0 }})
                </span>
            </div>
        </div>

        <WeekChart :labels="labels" :values="values" />

        <div class="flex justify-between text-xs text-gray-400 tabular-nums">
            <span v-for="(l, i) in labels" :key="i">{{ l }}</span>
        </div>
    </section>
</template>

<script setup>
import { computed } from "vue";
import WeekChart from "./WeekChart.vue";

const props = defineProps({
    days: { type: Array, default: () => [] },
    weeklyProgress: { type: Object, default: () => ({ done: 0, total: 0, percent: 0 }) },
});

const labels = computed(() => (props.days || []).map((d) => d.label));
const values = computed(() => (props.days || []).map((d) => Number(d?.progress?.percent ?? 0)));
</script>
