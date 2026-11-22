<script setup lang="ts">
import { computed } from 'vue';
import type { TicketStatus } from '@/types/sprint';

const props = defineProps<{ status: TicketStatus; label: string }>();

// Border and text colour only: the chip stays flat.
const tone = computed(
    () =>
        ({
            todo: 'border-border text-muted-foreground',
            in_progress: 'border-info text-info',
            in_review: 'border-warning text-warning',
            done: 'border-success text-success',
        })[props.status],
);
</script>

<template>
    <span
        class="shrink-0 rounded-sm border px-1.5 font-mono text-xs"
        :class="tone"
    >
        {{ label }}
    </span>
</template>
