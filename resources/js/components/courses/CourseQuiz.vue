<script setup lang="ts">
import { computed, ref } from 'vue';
import InlineText from '@/components/courses/InlineText.vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { CourseLesson, CourseQuizQuestion } from '@/types';

const props = defineProps<{
    questions: CourseQuizQuestion[];
    lessons: CourseLesson[];
}>();

const emit = defineEmits<{ finished: [answers: number[]] }>();

const current = ref(0);
const answers = ref<number[]>([]);
const chosen = ref<number | null>(null);

const question = computed(() => props.questions[current.value]);
const isLast = computed(() => current.value === props.questions.length - 1);
const correct = computed(
    () => chosen.value !== null && chosen.value === question.value.answer,
);
const lessonTitle = computed(
    () =>
        props.lessons.find((lesson) => lesson.slug === question.value.lesson)
            ?.title ?? null,
);

function choose(index: number): void {
    if (chosen.value === null) {
        chosen.value = index;
    }
}

function next(): void {
    if (chosen.value === null) {
        return;
    }

    answers.value = [...answers.value, chosen.value];
    chosen.value = null;

    if (isLast.value) {
        emit('finished', answers.value);

        return;
    }

    current.value += 1;
}

const letters = ['A', 'B', 'C', 'D', 'E', 'F'];
</script>

<template>
    <div class="space-y-5">
        <p class="font-mono text-xs text-muted-foreground">
            Question {{ current + 1 }} of {{ questions.length }}
        </p>
        <h2
            class="text-xl leading-8 font-semibold tracking-tight text-foreground"
        >
            <InlineText :text="question.question" />
        </h2>

        <ul class="space-y-2" role="list">
            <li v-for="(option, index) in question.options" :key="index">
                <button
                    type="button"
                    :disabled="chosen !== null"
                    :aria-pressed="chosen === index"
                    :class="
                        cn(
                            'flex w-full items-start gap-3 rounded-md border border-border bg-card px-3 py-3 text-left leading-6 text-foreground/90 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            chosen === null && 'hover:bg-muted',
                            chosen !== null &&
                                index === question.answer &&
                                'border-primary text-foreground',
                            chosen === index &&
                                index !== question.answer &&
                                'border-destructive text-foreground',
                        )
                    "
                    @click="choose(index)"
                >
                    <span
                        class="shrink-0 font-mono text-xs leading-6 text-muted-foreground"
                        >{{ letters[index] }}</span
                    >
                    <span class="min-w-0 break-words"
                        ><InlineText :text="option"
                    /></span>
                </button>
            </li>
        </ul>

        <div
            v-if="chosen !== null"
            class="space-y-3 rounded-md border border-border bg-card p-4"
            role="status"
        >
            <p
                :class="
                    cn(
                        'font-mono text-xs',
                        correct ? 'text-primary' : 'text-destructive',
                    )
                "
            >
                {{ correct ? 'Correct' : 'Not quite' }}
            </p>
            <p class="leading-7 text-foreground/80">
                <InlineText :text="question.explanation" />
            </p>
            <p v-if="lessonTitle" class="text-sm">
                <a
                    :href="`#lesson-${question.lesson}`"
                    class="text-primary underline underline-offset-4"
                    >Read the lesson: {{ lessonTitle }}</a
                >
            </p>
        </div>

        <Button v-if="chosen !== null" type="button" @click="next">
            {{ isLast ? 'See result' : 'Next question' }}
        </Button>
    </div>
</template>
