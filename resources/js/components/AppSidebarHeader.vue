<script setup lang="ts">
import { usePoll } from '@inertiajs/vue3';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import SprintChip from '@/components/SprintChip.vue';
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

// Keep the sprint chip and the unread badge fresh on every page.
usePoll(20_000, { only: ['sprint', 'unreadMessages'] });
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
        <SprintChip />
    </header>
</template>
