<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="flex h-12 shrink-0 items-center justify-between gap-2 border-b border-border px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <Breadcrumbs
                v-if="breadcrumbs.length > 0"
                :breadcrumbs="breadcrumbs"
            />
        </div>
        <span
            class="rounded-md border border-border px-2 py-0.5 font-mono text-xs text-muted-foreground"
        >
            No active sprint
        </span>
    </header>
</template>
