// resources/js/composables/useTodayLoader.js
//------------------------------------------------------------
// Today API（キャッシュ即描画 → silent revalidate）
//------------------------------------------------------------
import { ref, watch } from "vue";
import api from "@/axios";
import { getCachedToday, setCachedToday } from "@/state/todayCache";

export function useTodayLoader(scopeRef) {
    const loading = ref(false);
    const refreshing = ref(false);
    const hasLoaded = ref(false);
    const errorMessage = ref("");

    const today = ref("");
    const nowSlot = ref(null);
    const nextSlot = ref(null);
    const habits = ref([]);
    const progress = ref({ done: 0, total: 0, percent: 0 });
    const topPick = ref(null);

    let controller = null;
    let reqNo = 0;

    function applyPayload(data) {
        today.value = data?.today ?? "";
        nowSlot.value = data?.now_slot ?? null;
        nextSlot.value = data?.next_slot ?? null;
        habits.value = data?.habits ?? [];
        progress.value = data?.progress ?? { done: 0, total: 0, percent: 0 };
        topPick.value = data?.top_pick ?? null;
    }

    async function fetchToday(scope, opts = {}) {
        const { silent = false, preferCache = true, revalidate = true } = opts;

        errorMessage.value = "";
        const key = scope || "auto";

        // 1) キャッシュ即描画
        const cached = preferCache ? getCachedToday(key) : null;
        if (cached) {
            applyPayload(cached);
            hasLoaded.value = true;
        }

        if (cached && !revalidate) return cached;

        // 2) API（表示済みなら強制 silent）
        if (controller) controller.abort();
        controller = new AbortController();
        const myNo = ++reqNo;

        const effectiveSilent = silent || hasLoaded.value || !!cached;
        if (effectiveSilent) refreshing.value = true;
        else loading.value = true;

        try {
            const res = await api.get("/today", {
                params: { scope: key },
                signal: controller.signal,
            });

            if (myNo !== reqNo) return null;

            applyPayload(res.data);
            hasLoaded.value = true;
            setCachedToday(key, res.data);

            return res.data;
        } catch (e) {
            if (e?.name === "CanceledError" || e?.code === "ERR_CANCELED")
                return null;
            errorMessage.value = "今日データの取得に失敗しました";
            return null;
        } finally {
            if (effectiveSilent) refreshing.value = false;
            else loading.value = false;
        }
    }

    // scope 変更は silent 更新（画面は即残す）
    watch(
        scopeRef,
        async (v) => {
            const scope = v || "auto";
            await fetchToday(scope, {
                silent: true,
                preferCache: true,
                revalidate: true,
            });
        },
        { immediate: true }
    );

    return {
        loading,
        refreshing,
        hasLoaded,
        errorMessage,

        today,
        nowSlot,
        nextSlot,
        habits,
        progress,
        topPick,

        fetchToday,
    };
}
