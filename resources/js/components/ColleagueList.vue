<script setup lang="ts">
import type { Colleague } from '@/types/sprint';

defineProps<{
    colleagues: Colleague[];
}>();
</script>

<template>
    <ul class="divide-y divide-border">
        <li
            v-for="colleague in colleagues"
            :key="colleague.key"
            class="flex items-center gap-3 px-4 py-3"
        >
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-secondary font-mono text-xs"
            >
                {{ colleague.name.slice(0, 2).toUpperCase() }}
            </span>
            <div class="min-w-0">
                <p class="truncate font-medium">{{ colleague.name }}</p>
                <p class="truncate text-xs text-muted-foreground">
                    {{ colleague.role }}
                </p>
            </div>
        </li>
    </ul>
</template>
