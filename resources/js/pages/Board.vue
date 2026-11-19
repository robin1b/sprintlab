<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { board } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Board', href: board() }],
    },
});

const columns = ['To do', 'In progress', 'In review', 'Done'];
</script>

<template>
    <Head title="Board" />

    <div class="flex flex-1 flex-col gap-4 p-6">
        <p class="text-muted-foreground">
            Tickets appear here when your first sprint starts.
        </p>

        <div class="grid flex-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <section
                v-for="column in columns"
                :key="column"
                class="flex min-h-48 flex-col rounded-md border border-border bg-card"
            >
                <h2
                    class="flex items-center justify-between border-b border-border px-3 py-2 text-sm font-medium"
                >
                    {{ column }}
                    <span class="font-mono text-xs text-muted-foreground">
                        0
                    </span>
                </h2>
            </section>
        </div>
    </div>
</template>
