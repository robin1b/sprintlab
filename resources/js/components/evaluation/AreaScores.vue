<script setup lang="ts">
import type { AreaKey, EvaluationArea } from '@/types/evaluation';

defineProps<{
    areas: EvaluationArea[];
    scores: Record<AreaKey, number | null>;
    passScore: number;
}>();
</script>

<template>
    <div>
        <ul
            class="divide-y divide-border rounded-md border border-border bg-card"
        >
            <li
                v-for="area in areas"
                :key="area.key"
                class="flex items-center gap-4 px-4 py-3"
            >
                <span class="w-36 shrink-0 text-sm">{{ area.label }}</span>
                <template v-if="scores[area.key] !== null">
                    <div
                        class="h-1 min-w-0 flex-1 overflow-hidden rounded-sm bg-muted"
                        role="progressbar"
                        :aria-label="area.label"
                        :aria-valuenow="scores[area.key] ?? 0"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full"
                            :class="
                                (scores[area.key] ?? 0) >= passScore
                                    ? 'bg-primary'
                                    : 'bg-warning'
                            "
                            :style="{ width: `${scores[area.key]}%` }"
                        />
                    </div>
                    <span class="w-8 text-right font-mono text-sm">
                        {{ scores[area.key] }}
                    </span>
                </template>
                <span
                    v-else
                    class="flex-1 text-right font-mono text-xs text-muted-foreground"
                >
                    not judged
                </span>
            </li>
        </ul>
        <p class="mt-2 text-xs text-muted-foreground">
            Areas that could not be judged do not count.
        </p>
    </div>
</template>
