# Courses

Short, public courses inside Sprintlab, at `/courses`. A visitor reads a course
without an account. A signed-in user also keeps progress (per lesson: understood
or not yet) and quiz results.

- One file per course: `resources/courses/{slug}.json`, committed, in English.
  The file name is the slug.
- The folder is `config('sprintlab.courses_path')`: `SPRINTLAB_COURSES_PATH` in
  `.env`, empty by default, which means `resources/courses`. Tests use
  `tests/Fixtures/courses`.
- `App\Services\Courses\CourseLibrary` reads the files and
  `App\Services\Courses\CourseValidator` checks them. A file that breaks the
  format is skipped and reported (see `storage/logs`); the pages never break.
- The quiz score is always worked out in PHP from the answers and the course
  file. The whole course, with the quiz answers and explanations, goes to the
  browser: it is a learning quiz and the feedback is instant.
- A new course needs no code and no deploy step: add the file and push. The
  test `CourseFilesTest` checks every file in `resources/courses`, including the
  counts below (6 lessons, 10 to 12 quiz questions).
- Stored data (`course_progress`, `course_quiz_attempts`) is described on the
  privacy page and removed with the account.

The content format is shared with the study page (which is Dutch). In Sprintlab
every file is English: `locale` is `en`, and the two fixed notes use their
English labels. The rules below are copied from the course spec.

## JSON shape

```json
{
    "slug": "n8n",
    "locale": "nl",
    "title": "n8n in een uur",
    "summary": "One or two sentences: what you can do after this course.",
    "minutes": 60,
    "updated": "2026-11-25",
    "lessons": [
        {
            "slug": "editor",
            "title": "Wat n8n is en hoe de editor werkt",
            "minutes": 10,
            "blocks": [/* see Blocks */],
            "check": { "question": "…", "answer": "…" },
            "deeper": [
                /* Blocks: a second, simpler explanation, shown when the learner marks "not understood" */
            ]
        }
    ],
    "exercise": {
        "title": "…",
        "minutes": 20,
        "intro": "…",
        "steps": ["…"],
        "starter": { "language": "json", "code": "…" },
        "solution": [/* Blocks */]
    },
    "quiz": [
        {
            "question": "…",
            "options": ["…", "…", "…", "…"],
            "answer": 0,
            "explanation": "…",
            "lesson": "editor"
        }
    ],
    "sources": [{ "title": "…", "url": "https://…" }]
}
```

`starter` may be `null`. `answer` is the zero-based index of the correct option.
`lesson` in a quiz item is the slug of the lesson to re-read. Exactly 6 lessons,
10 to 12 quiz questions with 4 options each, one clearly correct.

## Blocks

- `{"type":"text","text":"…"}` one paragraph
- `{"type":"heading","text":"…"}` a small heading inside a lesson
- `{"type":"list","items":["…"]}` bullets
- `{"type":"steps","items":["…"]}` numbered steps
- `{"type":"code","language":"json|js|php|vue|ts|bash|text","caption":"…","code":"…"}` (`caption` optional)
- `{"type":"table","headers":["…"],"rows":[["…","…"]]}`
- `{"type":"note","label":"…","text":"…"}` a labelled aside

Inline formatting inside any text, item or cell: only `` `code` ``, `**bold**` and
`[label](https://url)`. No HTML, no other Markdown.

## Writing rules

- Plain words, sentences under 25 words, paragraphs of at most 3 sentences.
- Each lesson: what it is and why it matters, one concrete example, then the check.
- Each lesson has two fixed notes: label "Wat je al kent uit Laravel" (EN: "What you
  already know from Laravel") mapping the idea to Laravel, and label "Zeg het zo op
  een gesprek" (EN: "Say it like this in an interview") with one honest sentence.
- `deeper` explains the same idea again in a different, simpler way (an everyday
  comparison), 2 to 4 blocks. It is not extra material.
- Facts about a product come from its official documentation, opened and read. Use
  the names the current docs use for buttons, nodes and settings. Never guess.
  Write everything in your own words; do not copy sentences from the docs.
- Every page you relied on goes in `sources`.
- No emoji. No marketing tone.
