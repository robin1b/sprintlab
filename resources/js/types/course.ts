export type CodeLanguage =
    | 'json'
    | 'js'
    | 'php'
    | 'vue'
    | 'ts'
    | 'bash'
    | 'text';

// One block of lesson content. The text fields may hold `code`, **bold** and
// [label](https://url): see lib/inlineText.ts.
export type CourseBlock =
    | { type: 'text'; text: string }
    | { type: 'heading'; text: string }
    | { type: 'list'; items: string[] }
    | { type: 'steps'; items: string[] }
    | { type: 'code'; language: CodeLanguage; caption?: string; code: string }
    | { type: 'table'; headers: string[]; rows: string[][] }
    | { type: 'note'; label: string; text: string };

export type LessonStatus = 'understood' | 'not_understood';

export type CourseLesson = {
    slug: string;
    title: string;
    minutes: number;
    blocks: CourseBlock[];
    check: { question: string; answer: string };
    deeper: CourseBlock[];
};

export type CourseExercise = {
    title: string;
    minutes: number;
    intro: string;
    steps: string[];
    starter: { language: CodeLanguage; code: string } | null;
    solution: CourseBlock[];
};

export type CourseQuizQuestion = {
    question: string;
    options: string[];
    /** Zero-based index of the correct option. */
    answer: number;
    explanation: string;
    /** Slug of the lesson to read again. */
    lesson: string;
};

export type CourseSource = { title: string; url: string };

// The whole course file, as the show page gets it.
export type Course = {
    slug: string;
    locale: string;
    title: string;
    summary: string;
    minutes: number;
    updated: string;
    lessons: CourseLesson[];
    exercise: CourseExercise;
    quiz: CourseQuizQuestion[];
    sources: CourseSource[];
};

// One line of the courses overview. `understood` is null for a guest.
export type CourseSummary = {
    slug: string;
    title: string;
    summary: string;
    minutes: number;
    lessons: number;
    understood: number | null;
};

export type CourseProgressMap = Record<string, LessonStatus>;

export type CourseAttempt = { score: number; total: number; at: string };
