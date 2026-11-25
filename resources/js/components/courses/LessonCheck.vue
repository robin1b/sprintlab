<script setup lang="ts">
import { ref } from 'vue';
import CourseBlock from '@/components/courses/CourseBlock.vue';
import InlineText from '@/components/courses/InlineText.vue';
import { Button } from '@/components/ui/button';
import type { CourseBlock as CourseBlockData, LessonStatus } from '@/types';

const props = defineProps<{
    check: { question: string; answer: string };
    deeper: CourseBlockData[];
    status?: LessonStatus;
}>();

const emit = defineEmits<{ mark: [status: LessonStatus] }>();

const answerShown = ref(props.status !== undefined);
const deeperShown = ref(props.status === 'not_understood');

function mark(status: LessonStatus): void {
    deeperShown.value = status === 'not_understood';
    emit('mark', status);
}
</script>

<template>
    <section
        class="rounded-md border border-border bg-card p-4 sm:p-5"
        aria-labelledby="check-title"
    >
        <h2 id="check-title" class="font-mono text-xs text-primary">
            Check yourself
        </h2>
        <p class="mt-3 leading-7 font-medium text-foreground">
            <InlineText :text="check.question" />
        </p>

        <Button
            v-if="!answerShown"
            type="button"
            variant="outline"
            class="mt-4"
            @click="answerShown = true"
        >
            Show answer
        </Button>

        <template v-else>
            <p
                class="mt-3 rounded-md bg-muted p-3 leading-7 text-foreground/80"
            >
                <InlineText :text="check.answer" />
            </p>

            <p class="mt-4 text-sm text-muted-foreground">
                Did you understand this lesson?
            </p>
            <div class="mt-2 flex flex-wrap gap-2">
                <Button
                    type="button"
                    :variant="status === 'understood' ? 'default' : 'outline'"
                    :aria-pressed="status === 'understood'"
                    @click="mark('understood')"
                >
                    Got it
                </Button>
                <Button
                    type="button"
                    :variant="
                        status === 'not_understood' ? 'default' : 'outline'
                    "
                    :aria-pressed="status === 'not_understood'"
                    @click="mark('not_understood')"
                >
                    Not yet
                </Button>
            </div>
        </template>

        <div
            v-if="deeperShown"
            class="mt-6 space-y-4 border-t border-border pt-5"
        >
            <h3 class="text-base font-semibold text-foreground">
                Explained differently
            </h3>
            <CourseBlock
                v-for="(block, index) in deeper"
                :key="index"
                :block="block"
            />
        </div>
    </section>
</template>
