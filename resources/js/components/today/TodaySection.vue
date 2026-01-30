<!-- resources/js/components/today/TodaySection.vue -->
<template>
  <section v-if="items && items.length" class="space-y-2 mb-6">
    <!-- 見出し -->
    <h3 class="text-sm font-semibold text-gray-700 mb-1">
      {{ label }}
    </h3>

    <!-- セクションボックス -->
    <div
      class="rounded-2xl border bg-white divide-y"
      :class="done ? 'opacity-70' : ''"
    >
      <div
        v-for="h in items"
        :key="h.id"
        class="px-4 py-3"
      >
        <HabitRow
          :habit="h"
          :log="h.log || null"
          @update="(payload) => onUpdate(h, payload)"
          @detail="$emit('detail', h.id)"
        />
      </div>
    </div>
  </section>

  <!-- 空の場合 -->
  <section v-else class="text-gray-400 text-sm px-1 mb-6">
    {{ emptyText }}
  </section>
</template>

<script setup>
import HabitRow from './HabitRow.vue'

const props = defineProps({
  label: { type: String, required: true },   // TodayTab.vue と合わせた
  items: { type: Array, default: () => [] },
  emptyText: { type: String, default: '該当なし' },

  // 完了セクションだけ薄くしたい場合
  done: { type: Boolean, default: false },

  // TodayTab から渡す update ハンドラ
  onUpdate: { type: Function, required: true },
})

const emit = defineEmits(['detail'])

function onUpdate(habit, payload) {
  // TodayTab.vue 的には (habit, payload) がほしい
  props.onUpdate(habit, payload)
}
</script>