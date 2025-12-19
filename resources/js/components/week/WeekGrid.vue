<template>
  <section class="rounded-2xl border bg-white overflow-hidden">
    <div class="grid grid-cols-1 md:grid-cols-7">
      <WeekDayColumn
        v-for="day in days"
        :key="day.date"
        :day="day"
        @toggle="forwardToggle"
      />
    </div>
  </section>
</template>

<script setup>
import WeekDayColumn from "./WeekDayColumn.vue";

const props = defineProps({
  days: { type: Array, default: () => [] },
});

const emit = defineEmits(["toggle"]);

function forwardToggle(payload) {
  // payload: { date, habit }
  emit("toggle", payload);
}
</script>
