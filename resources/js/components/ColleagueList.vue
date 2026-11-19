<script setup lang="ts">
import { team } from '@/lib/team';
</script>

<template>
    <ul class="divide-y divide-border">
        <li
            v-for="colleague in team"
            :key="colleague.name"
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
