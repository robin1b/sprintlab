<script setup lang="ts">
import { computed } from 'vue';
import type { TicketType } from '@/types/sprint';

const props = defineProps<{ type: TicketType }>();

const tone = computed(
    () =>
        ({
            bug: 'border-danger text-danger',
            feature: 'border-info text-info',
            review: 'border-border text-muted-foreground',
            hotfix: 'border-warning text-warning',
        })[props.type],
);
</script>

<template>
    <span class="rounded-sm border px-1.5 font-mono text-xs" :class="tone">
        {{ type }}
    </span>
</template>
