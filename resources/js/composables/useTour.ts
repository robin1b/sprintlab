import { router, usePage } from '@inertiajs/vue3';
import { nextTick, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { finish } from '@/routes/tour';
import type { TourStep } from '@/tours';
import type { TourPart } from '@/types/auth';

export type { TourStep } from '@/tours';

type Options = {
    steps: TourStep[];
    when: () => boolean;
};

export function useTour(
    part: TourPart,
    options: Options,
): {
    open: Ref<boolean>;
    start: () => void;
    close: (reason: 'finish' | 'skip') => void;
} {
    const page = usePage();
    const open = ref(false);
    // A tour opens by itself at most once per page load.
    let attempted = false;
    let mounted = false;

    function done(): boolean {
        return (page.props.auth?.user?.tours_done ?? []).includes(part);
    }

    function forced(): boolean {
        return new URLSearchParams(window.location.search).get('tour') === '1';
    }

    function maybeOpen(): void {
        if (!mounted || attempted || options.steps.length === 0) {
            return;
        }

        if (!options.when() || (done() && !forced())) {
            return;
        }

        attempted = true;
        open.value = true;
    }

    function start(): void {
        attempted = true;
        open.value = true;
    }

    function close(reason: 'finish' | 'skip'): void {
        void reason;
        open.value = false;
        router.post(
            finish.url(part),
            {},
            { preserveScroll: true, preserveState: true, only: ['auth'] },
        );
    }

    onMounted(async () => {
        await nextTick();
        requestAnimationFrame(() => {
            mounted = true;
            maybeOpen();
        });
    });

    watch(options.when, maybeOpen);

    return { open, start, close };
}
