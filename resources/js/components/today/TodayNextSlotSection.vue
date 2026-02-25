<!-- resources/js/components/today/TodayNextSlotSection.vue -->
<script setup>
import HabitRow from '@/components/today/HabitRow.vue'

const props = defineProps({
  habits: { type: Array, required: true },
  today: { type: String, required: true },
})

const emit = defineEmits(['update'])

function onUpdate(payload) {
  emit('update', payload)
}
</script>

<template>
  <section class="today-section next-slot">
    <HabitRow
      v-for="h in habits"
      :key="h.habit_time_id ?? h.id"
      :habit="h"
      :log="h.log"
      :today="today"
      @update="onUpdate"
    />
  </section>
</template>
