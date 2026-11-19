<script setup lang="ts">
import { computed } from 'vue';
import AreaScores from '@/components/evaluation/AreaScores.vue';
import type { Evaluation, EvaluationArea } from '@/types/evaluation';

const props = defineProps<{
    evaluation: Evaluation;
    areas: EvaluationArea[];
    passScore: number;
}>();

const texts = computed(() =>
    [
        { label: 'what went well', body: props.evaluation.strengths },
        { label: 'what to work on', body: props.evaluation.growth },
        { label: 'next step', body: props.evaluation.advice },
        {
            label: 'communication',
            body: props.evaluation.communication_evidence,
        },
    ].filter((text) => (text.body ?? '').trim() !== ''),
);

const questions = computed(() => props.evaluation.questions ?? []);

// Without questions or texts the AI did not run: say so once.
const incomplete = computed(
    () => questions.value.length === 0 || texts.value.length === 0,
);

function note(index: number) {
    return props.evaluation.answer_notes?.[index] ?? null;
}
</script>

<template>
    <div class="flex flex-col gap-8">
        <section class="rounded-md border border-border bg-card px-4 py-4">
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <span class="font-mono text-5xl" data-test="overall">
                    {{ evaluation.overall ?? '-' }}
                </span>
                <span
                    class="text-lg font-medium"
                    :class="evaluation.passed ? 'text-success' : 'text-warning'"
                >
                    {{ evaluation.passed ? 'Passed' : 'Not passed yet' }}
                </span>
            </div>
            <p class="mt-2 text-sm text-muted-foreground">
                You need {{ passScore }} to pass.
            </p>
            <p
                v-if="evaluation.promoted_to"
                class="mt-3 border-t border-border pt-3 font-medium text-success"
            >
                You have been promoted to {{ evaluation.promoted_to }}.
            </p>
        </section>

        <AreaScores
            v-if="evaluation.scores"
            :areas="areas"
            :scores="evaluation.scores"
            :pass-score="passScore"
        />

        <section v-if="texts.length" class="flex flex-col gap-5">
            <div v-for="text in texts" :key="text.label">
                <p class="font-mono text-xs text-muted-foreground">
                    {{ text.label }}
                </p>
                <p class="mt-1 whitespace-pre-line">{{ text.body }}</p>
            </div>
        </section>

        <section v-if="questions.length">
            <h2 class="mb-3 font-medium">Your answers</h2>
            <ol
                class="divide-y divide-border rounded-md border border-border bg-card"
            >
                <li
                    v-for="(question, index) in questions"
                    :key="index"
                    class="px-4 py-4"
                >
                    <p class="flex gap-3 text-sm font-medium">
                        <span class="font-mono text-muted-foreground">
                            {{ index + 1 }}
                        </span>
                        <span>{{ question }}</span>
                    </p>
                    <p class="mt-2 whitespace-pre-line text-muted-foreground">
                        {{ evaluation.answers?.[index] }}
                    </p>
                    <div
                        v-if="note(index)"
                        class="mt-3 border-l border-border pl-3 text-sm"
                    >
                        <p class="font-mono text-xs text-muted-foreground">
                            Sarah · {{ note(index)?.understanding }}/5
                        </p>
                        <p class="mt-1">{{ note(index)?.note }}</p>
                    </div>
                </li>
            </ol>
        </section>

        <p v-if="incomplete" class="text-sm text-muted-foreground">
            Sarah could not write a full evaluation this time. The scores are
            based on your sprint.
        </p>
    </div>
</template>
