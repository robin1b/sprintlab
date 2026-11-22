<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import captionsUrl from '../../../media/intro.vtt';
import posterUrl from '../../../media/intro-poster.jpg';
import videoUrl from '../../../media/intro.mp4';

const props = defineProps<{
    autoplay: boolean;
    // True when the visitor asked for the video: it then starts with sound.
    sound?: boolean;
}>();

const emit = defineEmits<{
    done: [];
}>();

const video = ref<HTMLVideoElement | null>(null);
const playing = ref(false);
const started = ref(false);
const muted = ref(true);
const hint = ref(false);
const currentTime = ref(0);
const duration = ref(0);

const percent = computed(() =>
    duration.value > 0
        ? Math.min(100, Math.round((currentTime.value / duration.value) * 100))
        : 0,
);

function format(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

const timeText = computed(
    () => `${format(currentTime.value)} / ${format(duration.value)}`,
);

function play(): void {
    video.value?.play().catch(() => {});
}

// A deliberate Play press starts with sound.
function playWithSound(): void {
    if (video.value) {
        video.value.muted = false;
    }

    hint.value = false;
    play();
}

function toggle(): void {
    const el = video.value;

    if (!el) {
        return;
    }

    if (el.paused) {
        if (!started.value) {
            playWithSound();
        } else {
            play();
        }
    } else {
        el.pause();
    }
}

function toggleSound(): void {
    if (video.value) {
        video.value.muted = !video.value.muted;
    }

    hint.value = false;
}

function skip(): void {
    video.value?.pause();
    emit('done');
}

function onEnded(): void {
    emit('done');
}

onMounted(() => {
    const el = video.value;

    if (!el) {
        return;
    }

    duration.value = Number.isFinite(el.duration) ? el.duration : 0;

    const reduced =
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!props.autoplay || reduced) {
        return;
    }

    const playMuted = (): void => {
        el.muted = true;
        hint.value = true;
        el.play().catch(() => {
            hint.value = false;
        });
    };

    if (props.sound) {
        // The browser may still refuse sound without a click: fall back.
        el.muted = false;
        el.play().catch(playMuted);
    } else {
        playMuted();
    }
});
</script>

<template>
    <div class="overflow-hidden rounded-md border border-border bg-card">
        <div class="relative aspect-video bg-background">
            <video
                ref="video"
                class="size-full cursor-pointer object-cover"
                aria-label="Introduction to Sprintlab"
                playsinline
                preload="metadata"
                :poster="posterUrl"
                :src="videoUrl"
                @click="toggle"
                @play="
                    playing = true;
                    started = true;
                "
                @pause="playing = false"
                @ended="onEnded"
                @timeupdate="currentTime = video?.currentTime ?? 0"
                @loadedmetadata="duration = video?.duration ?? 0"
                @durationchange="duration = video?.duration ?? 0"
                @volumechange="muted = video?.muted ?? true"
            >
                <track
                    kind="captions"
                    srclang="en"
                    label="English"
                    :src="captionsUrl"
                    default
                />
            </video>

            <div
                v-if="!started"
                class="absolute inset-0 flex items-center justify-center bg-background/40"
            >
                <Button size="lg" @click="toggle">Play intro</Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 px-3 py-2">
            <Button size="sm" variant="secondary" @click="toggle">
                {{ playing ? 'Pause' : 'Play' }}
            </Button>
            <Button size="sm" variant="secondary" @click="toggleSound">
                {{ muted ? 'Sound on' : 'Sound off' }}
            </Button>
            <Button size="sm" variant="ghost" @click="skip">Skip intro</Button>

            <div class="flex min-w-40 flex-1 items-center gap-3">
                <div
                    class="h-1 flex-1 overflow-hidden rounded-full bg-border"
                    role="progressbar"
                    aria-label="Video progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="percent"
                >
                    <div
                        class="h-full bg-primary"
                        :style="{ width: `${percent}%` }"
                    />
                </div>
                <span
                    class="font-mono text-xs whitespace-nowrap text-muted-foreground"
                >
                    {{ timeText }}
                </span>
            </div>
        </div>

        <p
            v-if="hint && muted && playing"
            class="px-3 pb-2 text-xs text-muted-foreground"
        >
            Playing without sound. Press Sound on to hear the voice.
        </p>
    </div>
</template>
