// resources/js/state/reminderModal.js
import { reactive } from "vue";

/**
 * 通知クリック等で開くモーダルのグローバル状態（Pinia無し）
 * payload は SW から来た notification.data をそのまま受ける想定
 */
export const reminderModalState = reactive({
    open: false,
    payload: null, // { title, body, task_id, habit_time_id, date, evaluation_type, url, ... }
});

export function openReminderModal(payload) {
    reminderModalState.open = true;
    reminderModalState.payload = payload ?? null;
}

export function closeReminderModal() {
    reminderModalState.open = false;
    reminderModalState.payload = null;
}
