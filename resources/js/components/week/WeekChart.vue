<template>
    <div class="h-[220px]">
        <canvas ref="el" class="w-full h-full"></canvas>
    </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from "vue";
import { Chart, registerables } from "chart.js";

// chart.js/auto の代わりに明示登録（これが一番解決率高い）
Chart.register(...registerables);

const props = defineProps({
    labels: { type: Array, default: () => [] },
    values: { type: Array, default: () => [] },
});

const el = ref(null);
let chart = null;

function render() {
    if (!el.value) return;

    if (chart) {
        chart.data.labels = props.labels;
        chart.data.datasets[0].data = props.values;
        chart.update();
        return;
    }

    chart = new Chart(el.value, {
        type: "line",
        data: {
            labels: props.labels,
            datasets: [
                {
                    label: "今週の達成率(%)",
                    data: props.values,
                    tension: 0.25,
                    pointRadius: 4,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { min: 0, max: 100 },
            },
            plugins: {
                legend: { display: true },
                tooltip: { enabled: true },
            },
        },
    });
}

onMounted(() => render());

watch(
    () => [props.labels, props.values],
    () => render(),
    { deep: true }
);

onBeforeUnmount(() => {
    if (chart) chart.destroy();
    chart = null;
});
</script>
