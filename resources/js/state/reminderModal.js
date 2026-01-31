// resources/js/state/reminderModal.js
import { reactive } from "vue";

export const reminderModalState = reactive({
    open: false,
    payload: null, // notification payload
});

export function openReminderModal(payload) {
    reminderModalState.open = true;
    reminderModalState.payload = payload ?? null;
}

export function closeReminderModal() {
    reminderModalState.open = false;
    reminderModalState.payload = null;
}
