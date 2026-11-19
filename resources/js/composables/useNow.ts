import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

// The current time as a ref that refreshes itself, so "3d 4h left" stays true.
export function useNow(intervalMs = 60_000): Ref<number> {
    const now = ref(Date.now());
    let timer: ReturnType<typeof setInterval> | undefined;

    onMounted(() => {
        now.value = Date.now();
        timer = setInterval(() => (now.value = Date.now()), intervalMs);
    });

    onBeforeUnmount(() => clearInterval(timer));

    return now;
}
