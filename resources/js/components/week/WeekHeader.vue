<template>
    <header class="space-y-2">
        <div class="flex items-center justify-between">
            <button
                class="text-sm text-gray-600 hover:text-gray-900 disabled:opacity-50"
                @click="$emit('prev')"
                :disabled="disabled"
            >
                ← 前の週
            </button>

            <div class="text-xl font-semibold tabular-nums">
                {{ rangeLabel }}
            </div>

            <button
                class="text-sm text-gray-600 hover:text-gray-900 disabled:opacity-50"
                @click="$emit('next')"
                :disabled="disabled"
            >
                次の週 →
            </button>
        </div>

        <div class="text-sm text-gray-500">週間の一覧と達成率</div>
    </header>
</template>

<script setup>
defineProps({
    rangeLabel: { type: String, default: "—" },
    disabled: { type: Boolean, default: false },
});
defineEmits(["prev", "next"]);
</script>
