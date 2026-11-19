<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const points = [
    'Work through tickets, like in a real sprint.',
    'Get pull request reviews from AI colleagues.',
    'Deal with changing requirements, on your own machine with real Git.',
];
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
            <div class="mx-auto w-full max-w-2xl">
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

                <div class="mt-8">
                    <Button v-if="$page.props.auth.user" as-child>
                        <Link :href="dashboard()">Open Sprintlab</Link>
                    </Button>
                    <Button v-else as-child>
                        <Link :href="login()">Sign in with GitHub</Link>
                    </Button>
                </div>
            </div>
        </main>
    </div>
</template>
