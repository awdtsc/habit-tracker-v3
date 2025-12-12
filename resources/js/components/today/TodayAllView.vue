<!-- resources/js/components/today/TodayAllView.vue -->
<template>
  <div class="space-y-6">

    <!-- ▼ タイトル -->
    <h2 class="text-xl font-semibold">すべての習慣</h2>

    <!-- ▼ ロード中 / エラー -->
    <div v-if="loading" class="text-sm text-gray-500">読み込み中…</div>
    <div v-else-if="errorMessage" class="text-sm text-red-500">{{ errorMessage }}</div>

    <template v-else>

      <!-- ▼ 未完了（優先順位順） -->
      <section>
        <h3 class="text-lg font-semibold mb-2">未完了</h3>

        <ul class="space-y-2">
          <li
            v-for="h in sortedUndone"
            :key="h.id"
            class="p-3 border rounded bg-white flex items-center justify-between"
          >
            <div>
              <div class="font-medium">{{ h.title }}</div>
              <div class="text-xs text-gray-500">
                {{ slotLabel(h.time_slot) }}
              </div>
            </div>
          </li>
        </ul>

        <p v-if="sortedUndone.length === 0" class="text-sm text-gray-500">
          未完了の習慣はありません。
        </p>
      </section>

      <!-- ▼ 完了 -->
      <section>
        <h3 class="text-lg font-semibold mb-2">完了</h3>

        <ul class="space-y-2">
          <li
            v-for="h in done"
            :key="h.id"
            class="p-3 border rounded bg-gray-50 flex items-center justify-between"
          >
            <div>
              <div class="font-medium">{{ h.title }}</div>
              <div class="text-xs text-gray-500">
                {{ slotLabel(h.time_slot) }}
              </div>
            </div>

            <span class="text-green-600 text-sm font-semibold">完了</span>
          </li>
        </ul>

        <p v-if="done.length === 0" class="text-sm text-gray-500">
          完了した習慣はありません。
        </p>
      </section>

    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useTodayLoader } from '@/composables/useTodayLoader'

const {
  habits,
  loading,
  errorMessage,
} = useTodayLoader()

// ▼ スロット名
const slotLabel = (slot) => {
  if (!slot || slot === 0) return 'いつでも'
  return { 1: '朝', 2: '昼', 3: '夕方', 4: '夜' }[slot] ?? '不明'
}

// ▼ 完了
const done = computed(() =>
  habits.value.filter((h) => h.log?.status === 'done')
)

// ▼ 未完了（優先順位順の仮実装：slot → id）
const sortedUndone = computed(() =>
  habits.value
    .filter((h) => h.log?.status !== 'done')
    .sort((a, b) => {
      const slotA = a.time_slot ?? 0
      const slotB = b.time_slot ?? 0
      if (slotA !== slotB) return slotA - slotB
      return a.id - b.id
    })
)
</script>