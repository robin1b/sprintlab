<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { EvaluationState } from '@/types/evaluation';

const props = defineProps<{ evaluation: EvaluationState | null }>();

const status = computed(() => props.evaluation?.status ?? 'preparing');
</script>

<template>
    <section class="rounded-md border border-border bg-card">
        <h2 class="border-b border-border px-4 py-3 font-medium">
            Sprint evaluation
        </h2>
        <div
            class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
        >
            <template v-if="status === 'completed' && evaluation">
                <p class="flex items-baseline gap-3">
                    <span class="font-mono text-xl">
                        {{ evaluation.overall ?? '-' }}
                    </span>
                    <span
                        :class="
                            evaluation.passed ? 'text-success' : 'text-warning'
                        "
                    >
                        {{ evaluation.passed ? 'Passed' : 'Not passed yet' }}
                    </span>
                </p>
                <Link
                    :href="evaluation.url"
                    class="rounded-sm text-sm text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    View your evaluation
                </Link>
            </template>
            <template v-else-if="status === 'awaiting_answers' && evaluation">
                <p>Sarah has three questions for you.</p>
                <Button as-child size="sm">
                    <Link :href="evaluation.url">Answer the questions</Link>
                </Button>
            </template>
            <p v-else-if="status === 'writing'" class="text-muted-foreground">
                Sarah is writing your evaluation.
            </p>
            <p v-else-if="status === 'failed'" class="text-muted-foreground">
                Something went wrong while preparing your evaluation. It will be
                retried.
            </p>
            <p v-else class="text-muted-foreground">
                Your evaluation is being prepared.
            </p>
        </div>
    </section>
</template>
