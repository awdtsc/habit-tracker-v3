// resources/js/state/uiSync.js
//------------------------------------------------------------
// Today / Week の「どっちかでログが変わった」を通知するだけの軽量バス
//------------------------------------------------------------
import { ref } from "vue";

export const syncEvent = ref({
    seq: 0,
    source: null, // "today" | "week"
    date: null, // "YYYY-MM-DD"（変化が起きた日）
});

export function emitSync(source, payload = {}) {
    syncEvent.value = {
        seq: syncEvent.value.seq + 1,
        source,
        date: payload.date ?? null,
    };
}
