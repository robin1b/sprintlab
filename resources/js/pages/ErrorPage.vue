<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SiteFooter from '@/components/site/SiteFooter.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home } from '@/routes';

const props = defineProps<{
    status: number;
}>();

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const copy = computed(() => {
    switch (props.status) {
        case 403:
            return {
                title: "You can't open this page",
                text: 'Your account has no access to this page.',
            };
        case 404:
            return {
                title: 'Page not found',
                text: "This page doesn't exist, or it has moved.",
            };
        case 419:
            return {
                title: 'This page has expired',
                text: 'Reload the page and try again.',
            };
        case 429:
            return {
                title: 'Too many requests',
                text: 'Slow down a little and try again in a minute.',
            };
        case 503:
            return {
                title: 'Back in a moment',
                text: 'Sprintlab is being updated. Try again in a minute.',
            };
        default:
            return {
                title: 'Something went wrong',
                text: "That's on our side. Try again in a minute.",
            };
    }
});

function back() {
    window.history.back();
}
</script>

<template>
    <Head :title="copy.title" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="flex h-12 items-center justify-between border-b border-border px-6"
        >
            <Link
                :href="home()"
                class="flex items-center gap-2 font-mono text-sm font-medium"
            >
                <span class="text-primary">▸</span>
                sprintlab
            </Link>
        </header>

        <main class="flex flex-1 items-center px-6 py-16">
            <div class="mx-auto w-full max-w-2xl">
                <p class="font-mono text-xs text-muted-foreground">
                    error {{ status }}
                </p>
                <h1
                    class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                >
                    {{ copy.title }}
                </h1>
                <p class="mt-4 text-muted-foreground">{{ copy.text }}</p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <Button as-child>
                        <Link v-if="signedIn" :href="dashboard()"
                            >Go to the overview</Link
                        >
                        <Link v-else :href="home()">Go to the start page</Link>
                    </Button>
                    <Button variant="ghost" type="button" @click="back"
                        >Go back</Button
                    >
                </div>
            </div>
        </main>

        <SiteFooter />
    </div>
</template>
