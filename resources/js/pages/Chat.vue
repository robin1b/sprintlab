<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ColleagueList from '@/components/ColleagueList.vue';
import { chat } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Chat', href: chat() }],
    },
});
</script>

<template>
    <Head title="Chat" />

    <div class="flex flex-1 flex-col md:flex-row">
        <aside
            class="border-b border-border bg-card md:w-72 md:shrink-0 md:border-r md:border-b-0"
        >
            <h2
                class="border-b border-border px-4 py-3 font-mono text-xs text-muted-foreground"
            >
                colleagues
            </h2>
            <ColleagueList />
        </aside>

        <div class="flex flex-1 items-center justify-center p-6">
            <p class="max-w-xs text-center text-muted-foreground">
                No messages yet. Your team will reach out when your sprint
                starts.
            </p>
        </div>
    </div>
</template>
