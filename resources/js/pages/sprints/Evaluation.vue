<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AnswersForm from '@/components/evaluation/AnswersForm.vue';
import EvaluationResult from '@/components/evaluation/EvaluationResult.vue';
import LevelProgress from '@/components/evaluation/LevelProgress.vue';
import { dashboard } from '@/routes';
import type {
    Evaluation,
    EvaluationArea,
    LevelProgressData,
} from '@/types/evaluation';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Overview', href: dashboard() },
            { title: 'Evaluation', href: '#' },
        ],
    },
});

const props = defineProps<{
    evaluated_sprint: {
        id: number;
        scenario_title: string;
        completed_at: string | null;
    };
    evaluation: Evaluation | null;
    areas: EvaluationArea[];
    pass_score: number;
    answer_max_characters: number;
    progress: LevelProgressData;
}>();

const status = computed(() => props.evaluation?.status ?? 'preparing');
const waiting = computed(() =>
    ['preparing', 'writing', 'failed'].includes(status.value),
);

// Poll only while Sarah is working: a poll in the question state would add
// nothing, and the drafts stay untouched.
const { start, stop } = usePoll(
    5_000,
    { only: ['evaluation', 'progress'] },
    { autoStart: false },
);

watch(waiting, (value) => (value ? start() : stop()), { immediate: true });
</script>

<template>
    <Head title="Evaluation" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-6">
        <div>
            <p class="font-mono text-xs text-muted-foreground">evaluation</p>
            <h1 class="mt-1 text-xl font-semibold">
                {{ evaluated_sprint.scenario_title }}
            </h1>
        </div>

        <p
            v-if="status === 'preparing'"
            class="rounded-md border border-border bg-card px-4 py-3 text-muted-foreground"
        >
            Sarah is preparing your evaluation. This usually takes a minute or
            two.
        </p>

        <AnswersForm
            v-else-if="status === 'awaiting_answers' && evaluation?.questions"
            :sprint-id="evaluated_sprint.id"
            :questions="evaluation.questions"
            :max-characters="answer_max_characters"
        />

        <p
            v-else-if="status === 'writing'"
            class="rounded-md border border-border bg-card px-4 py-3 text-muted-foreground"
        >
            Sarah is writing your evaluation.
        </p>

        <EvaluationResult
            v-else-if="status === 'completed' && evaluation"
            :evaluation="evaluation"
            :areas="areas"
            :pass-score="pass_score"
        />

        <p
            v-else-if="status === 'failed'"
            class="rounded-md border border-border bg-card px-4 py-3 text-muted-foreground"
        >
            Something went wrong while preparing your evaluation. It will be
            retried.
        </p>

        <LevelProgress :progress="progress" />
    </div>
</template>
