<script setup lang="ts">
import { ref } from 'vue';
import CodeSample from '@/components/courses/CodeSample.vue';
import CourseBlock from '@/components/courses/CourseBlock.vue';
import InlineText from '@/components/courses/InlineText.vue';
import { Button } from '@/components/ui/button';
import type { CourseExercise } from '@/types';

defineProps<{ exercise: CourseExercise }>();

const solutionShown = ref(false);
</script>

<template>
    <div class="space-y-5">
        <p class="font-mono text-xs text-muted-foreground">
            Exercise &middot; {{ exercise.minutes }} min
        </p>
        <h1
            id="view-title"
            tabindex="-1"
            class="text-2xl font-semibold tracking-tight text-foreground outline-none"
        >
            {{ exercise.title }}
        </h1>
        <p class="leading-7 text-foreground/80">
            <InlineText :text="exercise.intro" />
        </p>

        <ol
            class="list-decimal space-y-2 pl-5 leading-7 text-foreground/80 marker:font-mono marker:text-muted-foreground"
        >
            <li
                v-for="(step, index) in exercise.steps"
                :key="index"
                class="pl-1"
            >
                <InlineText :text="step" />
            </li>
        </ol>

        <div v-if="exercise.starter" class="space-y-2">
            <h3 class="text-base font-semibold text-foreground">Starter</h3>
            <CodeSample
                :code="exercise.starter.code"
                :language="exercise.starter.language"
            />
        </div>

        <Button
            type="button"
            variant="outline"
            :aria-expanded="solutionShown"
            aria-controls="exercise-solution"
            @click="solutionShown = !solutionShown"
        >
            {{ solutionShown ? 'Hide solution' : 'Show solution' }}
        </Button>

        <div v-if="solutionShown" id="exercise-solution" class="space-y-4">
            <h3 class="text-base font-semibold text-foreground">Solution</h3>
            <CourseBlock
                v-for="(block, index) in exercise.solution"
                :key="index"
                :block="block"
            />
        </div>
    </div>
</template>
