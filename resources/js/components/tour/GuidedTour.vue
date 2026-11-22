<script setup lang="ts">
import { ArrowRight } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import type { CSSProperties } from 'vue';
import { Button } from '@/components/ui/button';
import type { TourStep } from '@/tours';

const props = defineProps<{
    steps: TourStep[];
    open: boolean;
}>();

const emit = defineEmits<{
    finish: [];
    skip: [];
}>();

type Box = { top: number; left: number; width: number; height: number };
type Side = 'bottom' | 'top' | 'right' | 'left';

const PADDING = 8;
const MARGIN = 12;
const GAP = 18;
const ARROW = 12;

const uid = useId();
const titleId = `tour-title-${uid}`;
const bodyId = `tour-body-${uid}`;

const index = ref(0);
const step = computed(() => props.steps[index.value]);
const isLast = computed(() => index.value === props.steps.length - 1);

const card = ref<HTMLElement | null>(null);
const nextButton = ref<{ $el: HTMLElement } | null>(null);

const hole = ref<Box | null>(null);
const viewport = ref({ width: 0, height: 0 });
const cardPosition = ref<{ top: number; left: number } | null>(null);
const side = ref<Side | null>(null);
const arrowOffset = ref(0);

let target: HTMLElement | null = null;
let observer: ResizeObserver | null = null;
let frame = 0;
let timer = 0;
let previousFocus: HTMLElement | null = null;

const docked = computed(() => viewport.value.width < 640);
const reducedMotion = (): boolean =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function findTarget(): HTMLElement | null {
    const name = step.value?.target;

    if (!name) {
        return null;
    }

    const found = document.querySelector<HTMLElement>(
        `[data-tour="${CSS.escape(name)}"]`,
    );

    if (!found) {
        return null;
    }

    const rect = found.getBoundingClientRect();

    return found.getClientRects().length > 0 &&
        rect.width > 0 &&
        rect.height > 0
        ? found
        : null;
}

function observe(element: HTMLElement | null): void {
    if (element === target) {
        return;
    }

    observer?.disconnect();
    target = element;

    if (element && typeof ResizeObserver !== 'undefined') {
        observer ??= new ResizeObserver(schedule);
        observer.observe(element);
    }
}

function measure(): void {
    frame = 0;

    if (!props.open) {
        return;
    }

    viewport.value = {
        width: document.documentElement.clientWidth,
        height: document.documentElement.clientHeight,
    };

    const element = findTarget();

    observe(element);

    if (element) {
        const rect = element.getBoundingClientRect();

        hole.value = {
            top: rect.top - PADDING,
            left: rect.left - PADDING,
            width: rect.width + PADDING * 2,
            height: rect.height + PADDING * 2,
        };
    } else {
        hole.value = null;
    }

    place();
}

function place(): void {
    side.value = null;
    cardPosition.value = null;

    const element = card.value;

    if (!element || docked.value || !hole.value) {
        return;
    }

    const { width: vw, height: vh } = viewport.value;
    const cw = element.offsetWidth;
    const ch = element.offsetHeight;
    const h = hole.value;
    const clamp = (value: number, min: number, max: number): number =>
        Math.max(min, Math.min(value, max));
    const centerX = h.left + h.width / 2;
    const centerY = h.top + h.height / 2;

    const room: Record<Side, boolean> = {
        bottom: vh - (h.top + h.height) - MARGIN >= ch + GAP,
        top: h.top - MARGIN >= ch + GAP,
        right: vw - (h.left + h.width) - MARGIN >= cw + GAP,
        left: h.left - MARGIN >= cw + GAP,
    };
    // A step can prefer a side, e.g. the right of a sidebar item.
    const preferred = step.value?.side;
    const order: Side[] = preferred
        ? [
              preferred,
              ...(['bottom', 'top', 'right', 'left'] as Side[]).filter(
                  (s) => s !== preferred,
              ),
          ]
        : ['bottom', 'top', 'right', 'left'];
    const chosen = order.find((s) => room[s]);

    if (!chosen) {
        // The target fills the screen: sit at the bottom, no arrow.
        cardPosition.value = {
            top: Math.max(MARGIN, vh - ch - MARGIN),
            left: clamp(
                centerX - cw / 2,
                MARGIN,
                Math.max(MARGIN, vw - cw - MARGIN),
            ),
        };

        return;
    }

    let top: number;
    let left: number;

    if (chosen === 'bottom' || chosen === 'top') {
        left = clamp(
            centerX - cw / 2,
            MARGIN,
            Math.max(MARGIN, vw - cw - MARGIN),
        );
        top = chosen === 'bottom' ? h.top + h.height + GAP : h.top - GAP - ch;
        arrowOffset.value = clamp(centerX - left, 20, cw - 20) - ARROW / 2;
    } else {
        top = clamp(
            centerY - ch / 2,
            MARGIN,
            Math.max(MARGIN, vh - ch - MARGIN),
        );
        left = chosen === 'right' ? h.left + h.width + GAP : h.left - GAP - cw;
        arrowOffset.value = clamp(centerY - top, 20, ch - 20) - ARROW / 2;
    }

    side.value = chosen;
    cardPosition.value = { top, left };
}

function schedule(): void {
    if (!frame) {
        frame = requestAnimationFrame(measure);
    }
}

async function showStep(): Promise<void> {
    await nextTick();

    const element = findTarget();

    element?.scrollIntoView({
        block: 'center',
        behavior: reducedMotion() ? 'auto' : 'smooth',
    });
    measure();
    // The card has its real size after the first measure.
    await nextTick();
    measure();
    nextButton.value?.$el?.focus({ preventScroll: true });
}

function focusables(): HTMLElement[] {
    return Array.from(
        card.value?.querySelectorAll<HTMLElement>('button:not([disabled])') ??
            [],
    );
}

function next(): void {
    if (isLast.value) {
        emit('finish');
    } else {
        index.value += 1;
    }
}

function back(): void {
    if (index.value > 0) {
        index.value -= 1;
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        emit('skip');
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        next();
    } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        back();
    } else if (event.key === 'Tab') {
        const items = focusables();

        if (items.length === 0) {
            return;
        }

        const first = items[0];
        const last = items[items.length - 1];
        const active = document.activeElement;

        if (!card.value?.contains(active)) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
        } else if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }
}

function start(): void {
    previousFocus = document.activeElement as HTMLElement | null;
    index.value = 0;
    window.addEventListener('resize', schedule);
    window.addEventListener('scroll', schedule, true);
    window.addEventListener('keydown', onKeydown, true);
    // A poll can re-render the page without a resize or scroll.
    timer = window.setInterval(schedule, 500);
    void showStep();
}

function stop(): void {
    window.removeEventListener('resize', schedule);
    window.removeEventListener('scroll', schedule, true);
    window.removeEventListener('keydown', onKeydown, true);
    window.clearInterval(timer);
    cancelAnimationFrame(frame);
    frame = 0;
    observer?.disconnect();
    target = null;
    hole.value = null;
    previousFocus?.focus?.({ preventScroll: true });
    previousFocus = null;
}

watch(
    () => props.open,
    (value) => (value ? start() : stop()),
    { immediate: true, flush: 'post' },
);
watch(index, () => void showStep(), { flush: 'post' });

onBeforeUnmount(() => {
    if (props.open) {
        stop();
    }
});

const cardStyle = computed<CSSProperties>(() => {
    if (docked.value) {
        return {
            left: `${MARGIN}px`,
            right: `${MARGIN}px`,
            bottom: `${MARGIN}px`,
        };
    }

    if (cardPosition.value) {
        return {
            top: `${cardPosition.value.top}px`,
            left: `${cardPosition.value.left}px`,
        };
    }

    return { top: '50%', left: '50%', transform: 'translate(-50%, -50%)' };
});

const arrowStyle = computed<CSSProperties>(() => {
    const offset = `${arrowOffset.value}px`;
    const inset = `${-ARROW / 2 - 1}px`;

    switch (side.value) {
        case 'bottom':
            return { left: offset, top: inset };
        case 'top':
            return { left: offset, bottom: inset };
        case 'right':
            return { top: offset, left: inset };
        default:
            return { top: offset, right: inset };
    }
});

const arrowBorders = computed(() => {
    switch (side.value) {
        case 'bottom':
            return 'border-t border-l';
        case 'top':
            return 'border-b border-r';
        case 'right':
            return 'border-b border-l';
        default:
            return 'border-t border-r';
    }
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open && step" class="fixed inset-0 z-[100]">
            <svg
                class="absolute inset-0 size-full"
                aria-hidden="true"
                focusable="false"
            >
                <defs>
                    <mask :id="`tour-mask-${uid}`">
                        <rect width="100%" height="100%" fill="white" />
                        <rect
                            v-if="hole"
                            :x="hole.left"
                            :y="hole.top"
                            :width="hole.width"
                            :height="hole.height"
                            rx="8"
                            fill="black"
                        />
                    </mask>
                </defs>
                <rect
                    width="100%"
                    height="100%"
                    fill="black"
                    fill-opacity="0.55"
                    :mask="`url(#tour-mask-${uid})`"
                />
            </svg>

            <div
                v-if="hole"
                class="pointer-events-none absolute rounded-lg outline-2 outline-primary"
                :style="{
                    top: `${hole.top}px`,
                    left: `${hole.left}px`,
                    width: `${hole.width}px`,
                    height: `${hole.height}px`,
                }"
                aria-hidden="true"
            ></div>

            <div
                ref="card"
                :key="index"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                :aria-describedby="bodyId"
                class="tour-card absolute w-[min(22rem,calc(100vw-24px))] rounded-lg border border-border bg-card text-card-foreground"
                :class="docked ? 'w-auto' : ''"
                :style="cardStyle"
            >
                <span
                    v-if="side && !docked"
                    class="absolute block rotate-45 bg-card"
                    :class="arrowBorders"
                    :style="{
                        ...arrowStyle,
                        width: `${ARROW}px`,
                        height: `${ARROW}px`,
                    }"
                    aria-hidden="true"
                ></span>

                <div class="max-h-[calc(100svh-24px)] overflow-y-auto p-4">
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ index + 1 }} / {{ steps.length }}
                    </p>
                    <h2 :id="titleId" class="mt-1 font-medium">
                        {{ step.title }}
                    </h2>
                    <p :id="bodyId" class="mt-1 text-sm leading-relaxed">
                        {{ step.body }}
                    </p>

                    <ol
                        v-if="step.flow"
                        class="mt-3 flex flex-wrap items-center gap-x-1.5 gap-y-2"
                    >
                        <template v-for="(label, i) in step.flow" :key="label">
                            <li
                                class="rounded-md border border-border bg-background px-2 py-1 text-xs"
                            >
                                {{ label }}
                            </li>
                            <li
                                v-if="i < step.flow.length - 1"
                                class="text-muted-foreground"
                                aria-hidden="true"
                            >
                                <ArrowRight class="size-3.5" />
                            </li>
                        </template>
                    </ol>

                    <div class="mt-4 flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            class="mr-auto text-muted-foreground"
                            @click="emit('skip')"
                        >
                            Skip tour
                        </Button>
                        <Button
                            v-if="index > 0"
                            variant="outline"
                            size="sm"
                            @click="back"
                        >
                            Back
                        </Button>
                        <Button ref="nextButton" size="sm" @click="next">
                            {{ isLast ? 'Finish' : 'Next' }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.tour-card {
    animation: tour-fade 150ms ease-out;
}

@keyframes tour-fade {
    from {
        opacity: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .tour-card {
        animation: none;
    }
}
</style>
