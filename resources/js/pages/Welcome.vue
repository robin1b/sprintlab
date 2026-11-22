<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { nextTick, ref, useTemplateRef } from 'vue';
import IntroVideo from '@/components/site/IntroVideo.vue';
import SiteFooter from '@/components/site/SiteFooter.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const points = [
    'Work through tickets, like in a real sprint.',
    'Get pull request reviews from AI colleagues.',
    'Deal with changing requirements, on your own machine with real Git.',
];

const page = usePage();
const SEEN_KEY = 'sprintlab.intro.seen';

function introSeen(): boolean {
    try {
        return window.localStorage.getItem(SEEN_KEY) !== null;
    } catch {
        return false;
    }
}

function decideIntro(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    if (new URLSearchParams(window.location.search).get('intro') === '1') {
        return true;
    }

    return !page.props.auth.user && !introSeen();
}

const showIntro = ref<boolean>(decideIntro());

// A visitor who asks for the video hears it; a first visit starts silent.
const askedForIntro = ref<boolean>(
    typeof window !== 'undefined' &&
        new URLSearchParams(window.location.search).get('intro') === '1',
);

function watchIntro(): void {
    askedForIntro.value = true;
    showIntro.value = true;
}
const mainAction = useTemplateRef<HTMLElement>('mainAction');

function finishIntro(): void {
    showIntro.value = false;

    try {
        window.localStorage.setItem(SEEN_KEY, '1');
    } catch {
        // Storage unavailable: the intro simply shows again next time.
    }

    void nextTick(() => {
        const el = mainAction.value as unknown as
            | HTMLElement
            | { $el: HTMLElement }
            | null;
        const target = el && '$el' in el ? el.$el : el;

        target?.focus();
    });
}
</script>

<template>
    <Head title="Welcome" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="flex h-12 items-center justify-between border-b border-border px-6"
        >
            <span class="flex items-center gap-2 font-mono text-sm font-medium">
                <span class="text-primary">▸</span>
                sprintlab
            </span>
        </header>

        <main class="flex flex-1 items-center px-6 py-16">
            <div
                class="mx-auto w-full"
                :class="showIntro ? 'max-w-3xl' : 'max-w-2xl'"
            >
                <div v-if="showIntro" class="mb-10">
                    <p class="mb-2 text-sm text-muted-foreground">
                        New here? See how Sprintlab works.
                    </p>
                    <IntroVideo
                        :autoplay="true"
                        :sound="askedForIntro"
                        @done="finishIntro"
                    />
                </div>

                <p class="font-mono text-xs text-muted-foreground">
                    a simulated dev team
                </p>
                <h1
                    class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                >
                    Practise working in a real dev team.
                </h1>

                <ul class="mt-6 space-y-2 text-muted-foreground">
                    <li v-for="point in points" :key="point" class="flex gap-3">
                        <span class="font-mono text-muted-foreground">-</span>
                        {{ point }}
                    </li>
                </ul>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <Button v-if="$page.props.auth.user" as-child>
                        <Link ref="mainAction" :href="dashboard()"
                            >Open Sprintlab</Link
                        >
                    </Button>
                    <Button v-else as-child>
                        <Link ref="mainAction" :href="login()"
                            >Sign in with GitHub</Link
                        >
                    </Button>
                    <Button
                        v-if="!showIntro"
                        variant="ghost"
                        @click="watchIntro"
                    >
                        Watch the intro
                    </Button>
                </div>
            </div>
        </main>

        <SiteFooter />
    </div>
</template>
