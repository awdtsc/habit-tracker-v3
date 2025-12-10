<!-- resources/js/components/today/HabitRow.vue -->
<template>
  <div
    class="flex items-center justify-between gap-4 py-3 border-b border-gray-200"
    :class="isDone ? 'opacity-70' : ''"
  >
    <!-- 左側：タイトル + 目標表示 -->
    <div class="min-w-0">
      <div class="font-medium truncate" :class="isDone ? 'line-through' : ''">
        {{ habit.title }}
      </div>

      <div class="text-xs text-gray-500 mt-0.5">
        {{ goalText }}
      </div>

      <!-- 完了ステータスによる進捗バー -->
      <div class="mt-1 h-2 w-40 rounded-full bg-gray-200 overflow-hidden">
        <div
          class="h-full transition-all"
          :class="isDone ? 'bg-green-500' : 'bg-blue-400'"
          :style="{ width: isDone ? '100%' : '0%' }"
        ></div>
      </div>
    </div>

    <!-- 右側：操作ボタン -->
    <div class="flex items-center gap-3 shrink-0">
      <span
        class="px-2 py-0.5 rounded-full text-xs"
        :class="statusBadgeClass"
      >
        {{ statusLabel }}
      </span>

      <button
        class="px-3 py-1 text-sm rounded border hover:bg-gray-50"
        @click="toggleStatus"
      >
        {{ isDone ? '未完' : '完了' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  habit: { type: Object, required: true },
  log: { type: Object, default: null },
})

const emit = defineEmits(['update'])

/* -------------------------------------------
 * 判定: log.status === 'done'
 * ----------------------------------------- */
const isDone = computed(() => props.log?.status === 'done')

/* -------------------------------------------
 * 表示ラベル
 * ----------------------------------------- */
const statusLabel = computed(() =>
  isDone.value ? '完了' : '未完了'
)

const statusBadgeClass = computed(() =>
  isDone.value
    ? 'bg-green-100 text-green-700'
    : 'bg-blue-100 text-blue-700'
)

const goalText = computed(() => '1回')

/* -------------------------------------------
 * 状態トグル
 * ----------------------------------------- */
function toggleStatus() {
  emit('update', {
    status: isDone.value ? 'none' : 'done',
  })
}
</script>
