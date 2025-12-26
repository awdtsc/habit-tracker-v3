<!-- resources/js/components/today/TodayAllView.vue -->
<template>
  <div class="space-y-10">
    <!-- 未完了 -->
    <section class="space-y-3">
      <h2 class="text-lg font-semibold">未完了</h2>

      <TodayActionableSection
        v-if="undoneSorted.length > 0"
        :habits="undoneSorted"
        :today="today"
        @update="onRowUpdate"
      />

      <p v-else class="text-sm text-gray-500 pl-1">
        未完了の習慣はありません。
      </p>
    </section>

    <!-- 完了 -->
    <section v-if="doneSorted.length > 0" class="space-y-3">
      <h2 class="text-lg font-semibold text-gray-700">完了</h2>

      <TodayDoneSection
        :habits="doneSorted"
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

/* emits */
const emit = defineEmits(["update"]);

/* props */
const props = defineProps({
  today: { type: String, required: true },
  habits: { type: Array, required: true },
  nowSlot: { type: Number, required: true },

  // TodayService の top_pick をそのまま渡す（任意）
  topPick: { type: Object, required: false, default: null },
});

/* helpers */
function isDone(h) {
  const type = h?.evaluation_type ?? "simple";
  const rating = h?.log?.rating ?? null;
  const status = h?.log?.status ?? "none";

  if (type === "self") return Number(rating ?? 0) === 4;
  return status === "done";
}

const slotOf = (h) => Number(h?.time_slot ?? 0);
const rowId = (h) => Number(h?.habit_time_id ?? h?.id ?? 0);

function nowSlotNum() {
  const n = Number(props.nowSlot ?? 0);
  return Number.isFinite(n) ? n : 0;
}

/**
 * bucket:
 * 0: 現在スロット（slot === now）
 * 1: 過去（slot < now）
 * 2: 未来（slot > now）
 * 3: anytime（slot === 0）
 */
function bucketForUndone(h) {
  const slot = slotOf(h);
  if (slot === 0) return 3;

  const now = nowSlotNum();
  if (now >= 1 && now <= 4) {
    if (slot === now) return 0;
    if (slot < now) return 1;
    return 2;
  }

  // nowSlot が取れない異常時：とりあえず時間帯順（1→4）で並べたいので future扱いに寄せる
  return 2;
}

function compareUndone(a, b) {
  const aId = rowId(a);
  const bId = rowId(b);

  const ab = bucketForUndone(a);
  const bb = bucketForUndone(b);
  if (ab !== bb) return ab - bb;

  // 同じグループ内だけ top_pick を先頭に寄せる（順位ルールは壊さない）
  const pickId = Number(props.topPick?.habit_time_id ?? 0);
  const aPick = pickId && aId === pickId ? 1 : 0;
  const bPick = pickId && bId === pickId ? 1 : 0;
  if (aPick !== bPick) return bPick - aPick;

  const aSlot = slotOf(a);
  const bSlot = slotOf(b);

  // 過去グループは “今に近い順”（例: now=3 なら 2→1）
  if (ab === 1 && aSlot !== bSlot) return bSlot - aSlot;

  // 未来グループは早い順（例: 3→4）
  if (ab === 2 && aSlot !== bSlot) return aSlot - bSlot;

  // current / anytime は slot→id
  if (aSlot !== bSlot) return aSlot - bSlot;
  return aId - bId;
}

/* lists */
const undoneSorted = computed(() => {
  const list = (props.habits ?? []).filter((h) => !isDone(h));
  return [...list].sort(compareUndone);
});

const doneSorted = computed(() => {
  const list = (props.habits ?? []).filter((h) => isDone(h));
  return [...list].sort((a, b) => {
    const aSlot = slotOf(a);
    const bSlot = slotOf(b);
    if (aSlot !== bSlot) return aSlot - bSlot;
    return rowId(a) - rowId(b);
  });
});

/* child -> parent relay */
function onRowUpdate({ habit, payload }) {
  emit("update", { habit, payload });
}
</script>
