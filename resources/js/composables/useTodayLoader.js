// resources/js/composables/useTodayLoader.js
//------------------------------------------------------------
// Today API（キャッシュ即描画 → silent revalidate）
//
// ★初回（キャッシュ無し）は silent にせず loading を立ててブロック
// ★キャッシュがある時は即描画して、裏で revalidate（ちらつき防止）
//------------------------------------------------------------
import { ref, watch } from "vue";
import api from "@/axios";
import { getCachedToday, setCachedToday } from "@/state/todayCache";

function normalizeScope(v) {
    // ここに入る値は TodayTab の apiScope から来る想定だが、
    // 想定外が来ても cache key を汚さないために正規化する
    const s = String(v || "all");
    if (["all", "morning", "day", "evening", "night"].includes(s)) return s;
    return "all";
}

export function useTodayLoader(scopeRef) {
    const loading = ref(false);
    const refreshing = ref(false);
    const hasLoaded = ref(false);
    const errorMessage = ref("");

    const today = ref("");
    const nowSlot = ref(null);
    const nextSlot = ref(null);
    const habits = ref([]);
    const topPick = ref(null);

    let controller = null;
    let reqNo = 0;

    function applyPayload(data) {
        today.value = data?.today ?? "";
        nowSlot.value = data?.now_slot ?? null;
        nextSlot.value = data?.next_slot ?? null;
        habits.value = data?.habits ?? [];
        topPick.value = data?.top_pick ?? null;
    }

    async function fetchToday(scope, opts = {}) {
        const { silent = false, preferCache = true, revalidate = true } = opts;

        errorMessage.value = "";
        const key = normalizeScope(scope);

        // 1) cache immediate paint
        const cached = preferCache ? getCachedToday(key) : null;
        if (cached) {
            applyPayload(cached);
            hasLoaded.value = true;
        }
        if (cached && !revalidate) return cached;

        // 2) API
        if (controller) controller.abort();
        controller = new AbortController();
        const myNo = ++reqNo;

        const isFirstColdLoad = !cached && !hasLoaded.value;
        const effectiveSilent = isFirstColdLoad
            ? false
            : silent || hasLoaded.value || !!cached;

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
            // axios の cancel / AbortController の cancel を両対応
            if (e?.name === "CanceledError" || e?.code === "ERR_CANCELED") {
                return null;
            }
            errorMessage.value = "今日データの取得に失敗しました";
            return null;
        } finally {
            // このリクエストが最新なら controller を掃除
            if (myNo === reqNo) controller = null;

            if (effectiveSilent) refreshing.value = false;
            else loading.value = false;
        }
    }

    watch(
        scopeRef,
        async (v) => {
            await fetchToday(v, {
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
        topPick,

        fetchToday,
    };
}
