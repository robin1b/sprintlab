import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { progress as progressRoute } from '@/routes/courses';
import type { CourseProgressMap, LessonStatus } from '@/types';

function storageKey(course: string): string {
    return `sprintlab.course.${course}.progress`;
}

function readLocal(course: string): CourseProgressMap {
    try {
        const raw = window.localStorage.getItem(storageKey(course));
        const data: unknown = raw === null ? null : JSON.parse(raw);

        if (typeof data !== 'object' || data === null || Array.isArray(data)) {
            return {};
        }

        const map: CourseProgressMap = {};

        for (const [lesson, status] of Object.entries(data)) {
            if (status === 'understood' || status === 'not_understood') {
                map[lesson] = status;
            }
        }

        return map;
    } catch {
        return {};
    }
}

function writeLocal(course: string, map: CourseProgressMap): void {
    try {
        window.localStorage.setItem(storageKey(course), JSON.stringify(map));
    } catch {
        // Storage is blocked or full: the progress lives until the page closes.
    }
}

/**
 * Progress of one course. Signed in, it is saved on the server; as a guest it
 * stays in this browser. The page sees one interface for both.
 */
export function useCourseProgress(
    course: string,
    lessons: string[],
    fromServer: CourseProgressMap | null,
) {
    const page = usePage();
    const isGuest = fromServer === null || !page.props.auth.user;
    const statuses = ref<CourseProgressMap>(
        isGuest ? readLocal(course) : { ...fromServer },
    );

    const understood = computed(
        () =>
            lessons.filter((lesson) => statuses.value[lesson] === 'understood')
                .length,
    );

    function mark(lesson: string, status: LessonStatus): void {
        const before = statuses.value[lesson];

        statuses.value = { ...statuses.value, [lesson]: status };

        if (isGuest) {
            writeLocal(course, statuses.value);

            return;
        }

        // The hash says which part of the course is open; a visit drops it.
        const hash = window.location.hash;

        router.post(
            progressRoute.url({ course, lesson }),
            { status },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    const next = { ...statuses.value };

                    if (before === undefined) {
                        delete next[lesson];
                    } else {
                        next[lesson] = before;
                    }

                    statuses.value = next;
                },
                onFinish: () => {
                    if (window.location.hash !== hash) {
                        window.history.replaceState(
                            window.history.state,
                            '',
                            `${window.location.pathname}${window.location.search}${hash}`,
                        );
                    }
                },
            },
        );
    }

    return { statuses, understood, isGuest, mark };
}
