<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import SiteFooter from '@/components/site/SiteFooter.vue';
import { dashboard, home } from '@/routes';
import { show } from '@/routes/courses';
import type { CourseSummary } from '@/types';

defineProps<{ courses: CourseSummary[] }>();

const page = usePage();
</script>

<template>
    <Head title="Courses" />

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
                <h1
                    class="text-3xl font-semibold tracking-tight text-foreground"
                >
                    Courses
                </h1>
                <p class="mt-3 leading-7 text-foreground/80">
                    Short courses you can read in an hour, with a check after
                    every lesson, an exercise and a quiz. You do not need an
                    account. Sign in to keep your progress.
                </p>

                <p
                    v-if="courses.length === 0"
                    class="mt-10 rounded-md border border-border bg-card p-4 text-muted-foreground"
                >
                    There are no courses yet.
                </p>

                <ul v-else class="mt-8 space-y-3" role="list">
                    <li v-for="course in courses" :key="course.slug">
                        <Link
                            :href="show(course.slug)"
                            class="block rounded-md border border-border bg-card p-4 outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:p-5"
                        >
                            <h2 class="text-lg font-semibold text-foreground">
                                {{ course.title }}
                            </h2>
                            <p class="mt-2 leading-7 text-foreground/80">
                                {{ course.summary }}
                            </p>
                            <p
                                class="mt-3 flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-muted-foreground"
                            >
                                <span>{{ course.minutes }} min</span>
                                <span>{{ course.lessons }} lessons</span>
                                <span
                                    v-if="course.understood !== null"
                                    class="text-primary"
                                    >{{ course.understood }} of
                                    {{ course.lessons }} understood</span
                                >
                            </p>
                        </Link>
                    </li>
                </ul>
            </div>
        </main>

        <SiteFooter />
    </div>
</template>
