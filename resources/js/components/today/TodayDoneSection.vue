<!-- resources/js/components/today/TodayDoneSection.vue -->
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
  <section class="today-section done">
    <HabitRow
      v-for="h in habits"
      :key="h.id"
      :habit="h"
      :log="h.log"
      :today="today"
      @update="onUpdate"
    />
  </section>
</template>