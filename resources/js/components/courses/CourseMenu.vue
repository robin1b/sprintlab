<script setup lang="ts">
import { Check, Circle, RotateCcw } from '@lucide/vue';
import type { Course, CourseProgressMap } from '@/types';

defineProps<{
    course: Course;
    progress: CourseProgressMap;
}>();
</script>

<template>
    <nav aria-label="Course contents">
        <ol class="divide-y divide-border rounded-md border border-border">
            <li v-for="(lesson, index) in course.lessons" :key="lesson.slug">
                <a
                    :href="`#lesson-${lesson.slug}`"
                    class="flex items-center gap-3 px-3 py-3 outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <span
                        class="w-5 shrink-0 font-mono text-xs text-muted-foreground"
                        >{{ index + 1 }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span class="block text-foreground">{{
                            lesson.title
                        }}</span>
                        <span
                            class="block font-mono text-xs text-muted-foreground"
                            >{{ lesson.minutes }} min</span
                        >
                    </span>
                    <span
                        v-if="progress[lesson.slug] === 'understood'"
                        class="flex shrink-0 items-center gap-1 font-mono text-xs text-primary"
                    >
                        <Check class="size-3.5" aria-hidden="true" />
                        Understood
                    </span>
                    <span
                        v-else-if="progress[lesson.slug] === 'not_understood'"
                        class="flex shrink-0 items-center gap-1 font-mono text-xs text-muted-foreground"
                    >
                        <RotateCcw class="size-3.5" aria-hidden="true" />
                        Not yet
                    </span>
                    <Circle
                        v-else
                        class="size-3.5 shrink-0 text-muted-foreground/50"
                        aria-hidden="true"
                    />
                </a>
            </li>
            <li>
                <a
                    href="#exercise"
                    class="flex items-center gap-3 px-3 py-3 outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <span
                        class="w-5 shrink-0 font-mono text-xs text-muted-foreground"
                        >&gt;_</span
                    >
                    <span class="min-w-0 flex-1">
                        <span class="block text-foreground">{{
                            course.exercise.title
                        }}</span>
                        <span
                            class="block font-mono text-xs text-muted-foreground"
                            >Exercise &middot;
                            {{ course.exercise.minutes }} min</span
                        >
                    </span>
                </a>
            </li>
            <li>
                <a
                    href="#quiz"
                    class="flex items-center gap-3 px-3 py-3 outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <span
                        class="w-5 shrink-0 font-mono text-xs text-muted-foreground"
                        >?</span
                    >
                    <span class="min-w-0 flex-1">
                        <span class="block text-foreground">Quiz</span>
                        <span
                            class="block font-mono text-xs text-muted-foreground"
                            >{{ course.quiz.length }} questions</span
                        >
                    </span>
                </a>
            </li>
        </ol>
    </nav>
</template>
