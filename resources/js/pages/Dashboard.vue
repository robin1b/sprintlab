<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import ColleagueList from '@/components/ColleagueList.vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Overview', href: dashboard() }],
    },
});

const page = usePage();
const firstName = computed(
    () => page.props.auth.user.name.trim().split(/\s+/u)[0],
);

const steps = [
    { title: 'Sign in with GitHub', done: true },
    { title: 'Create your Workkit repository from the template', done: false },
    {
        title: 'Install the Sprintlab GitHub app on that repository',
        done: false,
    },
    { title: 'Start your first sprint', done: false },
];
</script>

<template>
    <Head title="Overview" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-6">
        <div>
            <p class="font-mono text-xs text-muted-foreground">overview</p>
            <h1 class="mt-1 text-xl font-semibold">Hi {{ firstName }}</h1>
            <p class="mt-1 text-muted-foreground">
                Welcome to Workkit. Here is how to get your first sprint going.
            </p>
        </div>

        <section class="rounded-md border border-border bg-card">
            <h2 class="border-b border-border px-4 py-3 font-medium">
                Getting started
            </h2>
            <ol class="divide-y divide-border">
                <li
                    v-for="(step, index) in steps"
                    :key="step.title"
                    class="flex items-center gap-3 px-4 py-3"
                    :class="step.done ? '' : 'text-muted-foreground'"
                >
                    <span
                        class="flex size-5 shrink-0 items-center justify-center rounded-sm border font-mono text-xs"
                        :class="
                            step.done
                                ? 'border-primary text-primary'
                                : 'border-border'
                        "
                    >
                        <Check v-if="step.done" class="size-3.5" />
                        <template v-else>{{ index + 1 }}</template>
                    </span>
                    <span class="flex-1">{{ step.title }}</span>
                    <span
                        v-if="!step.done"
                        class="rounded-sm border border-border px-1.5 font-mono text-xs"
                    >
                        soon
                    </span>
                </li>
            </ol>
        </section>

        <section class="rounded-md border border-border bg-card">
            <h2 class="border-b border-border px-4 py-3 font-medium">
                Your team
            </h2>
            <ColleagueList />
        </section>
    </div>
</template>
