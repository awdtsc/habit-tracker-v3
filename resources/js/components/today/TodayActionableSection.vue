<!-- resources/js/components/today/TodayActionableSection.vue -->
<script setup>
import HabitRow from "@/components/today/HabitRow.vue";

const props = defineProps({
  habits: { type: Array, required: true },
  today: { type: String, required: true },

  // ★追加：{ [habit_id]: { total, done } }
  counts: { type: Object, default: null },
});

const emit = defineEmits(["update"]);

function onUpdate(payload) {
  emit("update", payload);
}

function getCounts(h) {
  const id = Number(h?.id ?? 0);
  const c = props.counts?.[id] ?? null;
  return {
    total: c?.total ?? null,
    done: c?.done ?? null,
  };
}
</script>

<template>
  <section class="today-section actionable">
    <HabitRow
      v-for="h in habits"
      :key="h.habit_time_id ?? h.id"
      :habit="h"
      :log="h.log"
      :today="today"
      :daily-total="getCounts(h).total"
      :daily-done="getCounts(h).done"
      @update="onUpdate"
    />
  </section>
</template>
