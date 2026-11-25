<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import CourseBlock from '@/components/courses/CourseBlock.vue';
import CourseExercise from '@/components/courses/CourseExercise.vue';
import CourseMenu from '@/components/courses/CourseMenu.vue';
import CourseQuiz from '@/components/courses/CourseQuiz.vue';
import LessonCheck from '@/components/courses/LessonCheck.vue';
import SiteFooter from '@/components/site/SiteFooter.vue';
import { Button } from '@/components/ui/button';
import { useCourseProgress } from '@/composables/useCourseProgress';
import { dashboard, home, login } from '@/routes';
import { index, quiz as quizRoute } from '@/routes/courses';
import type { Course, CourseAttempt, CourseProgressMap } from '@/types';

const props = defineProps<{
    course: Course;
    progress: CourseProgressMap | null;
    lastAttempt: CourseAttempt | null;
}>();

const page = usePage();

const { statuses, understood, isGuest, mark } = useCourseProgress(
    props.course.slug,
    props.course.lessons.map((lesson) => lesson.slug),
    props.progress,
);

// What is open is kept in the address (#lesson-<slug>, #exercise, #quiz,
// #result), so back, forward and a reload land in the same place.
const hash = ref(window.location.hash);
const answers = ref<number[] | null>(null);

function onHashChange(): void {
    hash.value = window.location.hash;
}

onMounted(() => window.addEventListener('hashchange', onHashChange));
onBeforeUnmount(() => window.removeEventListener('hashchange', onHashChange));

type View =
    | { name: 'overview' }
    | { name: 'lesson'; index: number }
    | { name: 'exercise' }
    | { name: 'quiz' }
    | { name: 'result' };

const view = computed<View>(() => {
    const value = hash.value.replace(/^#/, '');

    if (value.startsWith('lesson-')) {
        const found = props.course.lessons.findIndex(
            (lesson) => lesson.slug === value.slice('lesson-'.length),
        );

        return found >= 0
            ? { name: 'lesson', index: found }
            : { name: 'overview' };
    }

    if (value === 'exercise') {
        return { name: 'exercise' };
    }

    if (value === 'quiz') {
        return { name: 'quiz' };
    }

    if (value === 'result') {
        // Without a finished quiz in this visit or an earlier try, there is nothing to show.
        return answers.value !== null || props.lastAttempt !== null
            ? { name: 'result' }
            : { name: 'quiz' };
    }

    return { name: 'overview' };
});

const viewKey = computed(() => {
    const current = view.value;

    return current.name === 'lesson' ? `lesson-${current.index}` : current.name;
});

watch(viewKey, async () => {
    window.scrollTo(0, 0);
    await nextTick();
    document.getElementById('view-title')?.focus({ preventScroll: true });
});

const lesson = computed(() =>
    view.value.name === 'lesson'
        ? props.course.lessons[view.value.index]
        : null,
);

const previous = computed<{ href: string; label: string } | null>(() => {
    const current = view.value;

    if (current.name === 'lesson') {
        return current.index === 0
            ? { href: '#overview', label: 'Contents' }
            : {
                  href: `#lesson-${props.course.lessons[current.index - 1].slug}`,
                  label: props.course.lessons[current.index - 1].title,
              };
    }

    if (current.name === 'exercise') {
        const last = props.course.lessons[props.course.lessons.length - 1];

        return { href: `#lesson-${last.slug}`, label: last.title };
    }

    if (current.name === 'quiz') {
        return { href: '#exercise', label: props.course.exercise.title };
    }

    return null;
});

const next = computed<{ href: string; label: string } | null>(() => {
    const current = view.value;

    if (current.name === 'lesson') {
        return current.index === props.course.lessons.length - 1
            ? { href: '#exercise', label: props.course.exercise.title }
            : {
                  href: `#lesson-${props.course.lessons[current.index + 1].slug}`,
                  label: props.course.lessons[current.index + 1].title,
              };
    }

    if (current.name === 'exercise') {
        return { href: '#quiz', label: 'Quiz' };
    }

    return null;
});

// Where "Start" or "Continue" leads: the first lesson not understood yet.
const resume = computed(() => {
    const open = props.course.lessons.find(
        (item) => statuses.value[item.slug] !== 'understood',
    );

    return open ? `#lesson-${open.slug}` : '#quiz';
});

const started = computed(() => Object.keys(statuses.value).length > 0);

const lessonCount = computed(() => props.course.lessons.length);

function finished(given: number[]): void {
    answers.value = given;
    window.location.hash = '#result';

    if (isGuest) {
        return;
    }

    // The hash is set first: the visit that follows keeps it (see the composable).
    const hashNow = window.location.hash;

    router.post(
        quizRoute.url(props.course.slug),
        { answers: given },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['lastAttempt'],
            onFinish: () => {
                if (window.location.hash !== hashNow) {
                    window.history.replaceState(
                        window.history.state,
                        '',
                        `${window.location.pathname}${window.location.search}${hashNow}`,
                    );
                }
            },
        },
    );
}

const score = computed(() => {
    if (answers.value !== null) {
        return {
            score: answers.value.filter(
                (given, i) => given === props.course.quiz[i]?.answer,
            ).length,
            total: props.course.quiz.length,
        };
    }

    return props.lastAttempt
        ? { score: props.lastAttempt.score, total: props.lastAttempt.total }
        : null;
});

// Lessons to read again: those of the questions answered wrongly, once each.
const reread = computed(() => {
    if (answers.value === null) {
        return [];
    }

    const slugs = new Set<string>();

    props.course.quiz.forEach((question, i) => {
        if (answers.value?.[i] !== question.answer) {
            slugs.add(question.lesson);
        }
    });

    return props.course.lessons.filter((item) => slugs.has(item.slug));
});

const lastQuiz = computed(() =>
    props.lastAttempt
        ? `${props.lastAttempt.score} of ${props.lastAttempt.total}`
        : null,
);
</script>

<template>
    <Head :title="course.title" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="flex h-12 items-center justify-between border-b border-border px-6"
        >
            <Link
                :href="home()"
                class="flex items-center gap-2 font-mono text-sm font-medium"
            >
                <span class="text-primary">▸</span>
                sprintlab
            </Link>
            <Link
                v-if="page.props.auth.user"
                :href="dashboard()"
                class="font-mono text-xs text-muted-foreground hover:text-foreground"
            >
                Back to overview
            </Link>
        </header>

        <main class="flex-1 px-4 py-8 sm:px-6 sm:py-12">
            <div class="mx-auto w-full max-w-[720px]">
                <nav
                    class="mb-6 flex flex-wrap items-center gap-x-2 gap-y-1 font-mono text-xs text-muted-foreground"
                    aria-label="Breadcrumb"
                >
                    <Link :href="index()" class="hover:text-foreground"
                        >Courses</Link
                    >
                    <span aria-hidden="true">/</span>
                    <a
                        v-if="view.name !== 'overview'"
                        href="#overview"
                        class="hover:text-foreground"
                        >{{ course.title }}</a
                    >
                    <span v-else class="text-foreground">{{
                        course.title
                    }}</span>
                </nav>

                <!-- Overview -->
                <section v-if="view.name === 'overview'" class="space-y-6">
                    <div>
                        <h1
                            id="view-title"
                            tabindex="-1"
                            class="text-3xl font-semibold tracking-tight text-foreground outline-none"
                        >
                            {{ course.title }}
                        </h1>
                        <p class="mt-3 leading-7 text-foreground/80">
                            {{ course.summary }}
                        </p>
                        <p
                            class="mt-3 flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-muted-foreground"
                        >
                            <span>{{ course.minutes }} min</span>
                            <span>{{ lessonCount }} lessons</span>
                            <span v-if="lastQuiz"
                                >Last quiz: {{ lastQuiz }}</span
                            >
                        </p>
                    </div>

                    <div>
                        <div class="flex gap-1" aria-hidden="true">
                            <span
                                v-for="item in course.lessons"
                                :key="item.slug"
                                :class="[
                                    'h-1.5 flex-1 rounded-full',
                                    statuses[item.slug] === 'understood'
                                        ? 'bg-primary'
                                        : 'bg-muted',
                                ]"
                            />
                        </div>
                        <p class="mt-2 font-mono text-xs text-muted-foreground">
                            {{ understood }} of {{ lessonCount }} lessons
                            understood
                        </p>
                        <p
                            v-if="isGuest"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            <Link
                                :href="login()"
                                class="underline underline-offset-4 hover:text-foreground"
                                >Sign in</Link
                            >
                            to keep your progress across devices.
                        </p>
                    </div>

                    <Button as-child>
                        <a :href="resume">{{
                            started ? 'Continue' : 'Start the course'
                        }}</a>
                    </Button>

                    <CourseMenu :course="course" :progress="statuses" />

                    <div v-if="course.sources.length > 0">
                        <h2 class="text-base font-semibold text-foreground">
                            Sources
                        </h2>
                        <ul class="mt-2 space-y-1 text-sm" role="list">
                            <li
                                v-for="source in course.sources"
                                :key="source.url"
                                class="break-words"
                            >
                                <a
                                    :href="source.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-primary underline underline-offset-4"
                                    >{{ source.title }}</a
                                >
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- A lesson -->
                <section
                    v-else-if="view.name === 'lesson' && lesson"
                    :key="lesson.slug"
                    class="space-y-5"
                >
                    <p class="font-mono text-xs text-muted-foreground">
                        Lesson {{ view.index + 1 }} of
                        {{ lessonCount }} &middot; {{ lesson.minutes }} min
                    </p>
                    <h1
                        id="view-title"
                        tabindex="-1"
                        class="text-2xl font-semibold tracking-tight text-foreground outline-none"
                    >
                        {{ lesson.title }}
                    </h1>
                    <CourseBlock
                        v-for="(block, i) in lesson.blocks"
                        :key="i"
                        :block="block"
                    />
                    <LessonCheck
                        :check="lesson.check"
                        :deeper="lesson.deeper"
                        :status="statuses[lesson.slug]"
                        @mark="(status) => mark(lesson!.slug, status)"
                    />
                    <p v-if="isGuest" class="text-xs text-muted-foreground">
                        Sign in to keep your progress across devices.
                    </p>
                </section>

                <!-- The exercise -->
                <section v-else-if="view.name === 'exercise'">
                    <CourseExercise :exercise="course.exercise" />
                </section>

                <!-- The quiz -->
                <section v-else-if="view.name === 'quiz'" class="space-y-5">
                    <h1
                        id="view-title"
                        tabindex="-1"
                        class="text-2xl font-semibold tracking-tight text-foreground outline-none"
                    >
                        Quiz
                    </h1>
                    <CourseQuiz
                        :questions="course.quiz"
                        :lessons="course.lessons"
                        @finished="finished"
                    />
                </section>

                <!-- The result -->
                <section
                    v-else-if="view.name === 'result' && score"
                    class="space-y-5"
                >
                    <h1
                        id="view-title"
                        tabindex="-1"
                        class="text-2xl font-semibold tracking-tight text-foreground outline-none"
                    >
                        Result
                    </h1>
                    <p class="font-mono text-4xl text-foreground">
                        {{ score.score }}
                        <span class="text-muted-foreground"
                            >of {{ score.total }}</span
                        >
                    </p>
                    <p class="leading-7 text-foreground/80">
                        <template v-if="score.score === score.total"
                            >Every answer was right.</template
                        >
                        <template v-else-if="reread.length > 0"
                            >Read these lessons again:</template
                        >
                        <template v-else>
                            This is the result of your last try.
                        </template>
                    </p>
                    <ul v-if="reread.length > 0" class="space-y-1" role="list">
                        <li v-for="item in reread" :key="item.slug">
                            <a
                                :href="`#lesson-${item.slug}`"
                                class="text-primary underline underline-offset-4"
                                >{{ item.title }}</a
                            >
                        </li>
                    </ul>
                    <div class="flex flex-wrap gap-2 pt-2">
                        <Button as-child>
                            <a href="#quiz">Try the quiz again</a>
                        </Button>
                        <Button as-child variant="outline">
                            <a href="#overview">Back to the contents</a>
                        </Button>
                    </div>
                    <p v-if="isGuest" class="text-xs text-muted-foreground">
                        Sign in to keep your results.
                    </p>
                </section>

                <!-- Previous and next -->
                <div
                    v-if="previous || next"
                    class="mt-10 flex flex-col gap-3 border-t border-border pt-6 sm:flex-row sm:justify-between"
                >
                    <Button
                        v-if="previous"
                        as-child
                        variant="outline"
                        class="h-auto min-h-9 justify-start py-2 text-left whitespace-normal"
                    >
                        <a :href="previous.href">
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >Previous</span
                            >
                            <span class="min-w-0 break-words">{{
                                previous.label
                            }}</span>
                        </a>
                    </Button>
                    <span v-else />
                    <Button
                        v-if="next"
                        as-child
                        class="h-auto min-h-9 justify-start py-2 text-left whitespace-normal sm:justify-end"
                    >
                        <a :href="next.href">
                            <span class="min-w-0 break-words">{{
                                next.label
                            }}</span>
                            <span class="font-mono text-xs opacity-70"
                                >Next</span
                            >
                        </a>
                    </Button>
                </div>
            </div>
        </main>

        <SiteFooter />
    </div>
</template>
