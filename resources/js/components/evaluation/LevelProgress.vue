<script setup lang="ts">
import { computed } from 'vue';
import type { LevelProgressData } from '@/types/evaluation';

const props = defineProps<{ progress: LevelProgressData }>();

const text = computed(() => {
    const { level, passed, needed, next } = props.progress;

    if (next === null) {
        return `Level: ${level}. You are at the highest level.`;
    }

    return `Level: ${level}. ${Math.min(passed, needed)} of ${needed} passed sprints towards ${next}.`;
});
</script>

<template>
    <p
        class="font-mono text-xs text-muted-foreground"
        data-test="level-progress"
    >
        {{ text }}
    </p>
</template>
