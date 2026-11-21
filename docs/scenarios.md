# Writing a scenario

A scenario is one JSON file. It describes a sprint: the tickets, the events
(messages, new criteria, colleague pull requests) and some private notes for
the AI colleagues. The file is imported into the database. The running app
never reads the file again.

## Where files live

- `resources/scenarios`: the public demo only. This folder is in the public repository.
- `storage/app/private/scenarios`: real scenarios. Never committed, never served.
- `SPRINTLAB_SCENARIO_PATH` in `.env`: another folder for real scenarios, used instead of the private folder.

Import with:

```
php artisan sprintlab:import-scenarios
```

Then rehearse the script, without GitHub and without AI:

```
php artisan sprintlab:rehearse first-sprint
```

A stand-in user finishes every ticket in the order of the board while the
clock moves forward. The command prints what happens when (tickets, events,
messages) and fails when a ticket never comes on the board, an event never
fires or the sprint does not end. Nothing is kept afterwards.

`site:deploy` runs the import on every deploy. Importing again updates
scenarios by `slug`, tickets and events by `key`. Rows are never deleted: a
running sprint may still point at them. A private field that you remove from
the file becomes empty on the next import. A broken file is refused as a
whole, with a message that names the file, the ticket or event and the problem.

## What is secret

> **Secret scenario content.** These fields may reach the AI. They never
> reach the browser, and they never belong in the public repository.
>
> - `planted_issues` (of a review ticket)
> - `review_notes` (of a ticket)
> - `briefing` (of the scenario)
> - `colleague_notes` (of the scenario)
> - the files of a colleague pull request (`files` of an `open_pull_request` event)
>
> Keep real scenarios in the private folder. `tests/Feature/SecretScenarioContentTest.php` checks that the pages do not show them.

## The scenario

| Field             | Required | Meaning                                                                                                                                                         |
| ----------------- | -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `slug`            | yes      | Unique id of the scenario.                                                                                                                                      |
| `title`           | yes      | Name shown to the user.                                                                                                                                         |
| `level`           | yes      | `junior`, `medior` or `senior`.                                                                                                                                 |
| `duration_days`   | yes      | Whole number, at least 1.                                                                                                                                       |
| `sequence`        | no       | Whole number, at least 1. Place in the track of the level (junior sprint 1, 2, 3 ...). Without it the scenario is a demo or an extra that every level can play. |
| `summary`         | no       | Short text.                                                                                                                                                     |
| `briefing`        | no       | Secret. Background about the codebase and how the team works. All colleagues know it.                                                                           |
| `colleague_notes` | no       | Secret. An object: colleague value => text. Things only that colleague knows.                                                                                   |
| `tickets`         | yes      | List of tickets.                                                                                                                                                |
| `events`          | no       | List of events.                                                                                                                                                 |

Colleague values: `sarah` (team lead), `daniel` (project manager), `josh` and
`emily` (developers), `claire` (client).

```json
{
    "slug": "first-sprint",
    "title": "First sprint",
    "level": "junior",
    "duration_days": 5,
    "briefing": "The app is a Laravel monolith. Repairs and Sales share the Device model.",
    "colleague_notes": {
        "claire": "If asked, the client only needs the export once a week, not daily."
    },
    "tickets": [],
    "events": []
}
```

A colleague only shares a note when the user asks for it. The briefing is
background: a colleague gives one pointer at a time.

## A ticket

| Field                 | Required | Meaning                                                                                                              |
| --------------------- | -------- | -------------------------------------------------------------------------------------------------------------------- |
| `key`                 | yes      | Unique in the scenario, for example `WK-1`.                                                                          |
| `day`                 | yes      | The day it appears, from 1 to `duration_days`.                                                                       |
| `type`                | yes      | `bug`, `feature`, `review` or `hotfix`.                                                                              |
| `reporter`            | yes      | Colleague value.                                                                                                     |
| `title`               | yes      | Text.                                                                                                                |
| `description`         | yes      | Text.                                                                                                                |
| `acceptance_criteria` | yes      | List of texts. Can be empty.                                                                                         |
| `released_at_start`   | no       | `true` (default) or `false`. A hidden ticket needs a `release_ticket` event.                                         |
| `reviewer`            | no       | Colleague who reviews the pull request of this ticket. Default: Sarah. Not allowed on a `review` ticket.             |
| `review_notes`        | no       | Secret. What a good solution contains and typical mistakes. Only for the reviewer. Not allowed on a `review` ticket. |
| `planted_issues`      | no       | Secret. Only on a `review` ticket, where the user reviews a colleague's pull request.                                |

The reviewer checks a pull request against its ticket and the criteria as they
stand at that moment, including criteria added by an `update_ticket` event.

```json
{
    "key": "WK-1",
    "day": 1,
    "type": "bug",
    "reporter": "sarah",
    "title": "A lease request can be approved twice",
    "description": "Approving twice creates two contracts.",
    "acceptance_criteria": ["Approving twice never creates a second contract"],
    "reviewer": "emily",
    "review_notes": "A good solution checks the status inside a transaction. Typical mistake: only checking in the controller."
}
```

### Planted issues

A `review` ticket has a pull request from a colleague. Each planted issue is a
problem the user should find.

| Field         | Required | Meaning                    |
| ------------- | -------- | -------------------------- |
| `id`          | yes      | Unique in the ticket.      |
| `description` | yes      | What is wrong.             |
| `severity`    | yes      | `low`, `medium` or `high`. |
| `file`        | no       | The file where it is.      |

```json
"planted_issues": [
  {"id": "authorization", "file": "app/Http/Controllers/RepairController.php", "description": "Anyone can update any repair.", "severity": "high"}
]
```

## An event

| Field           | Required   | Meaning                                                                    |
| --------------- | ---------- | -------------------------------------------------------------------------- |
| `key`           | yes        | Unique in the scenario.                                                    |
| `on`            | yes        | The trigger, see below.                                                    |
| `action`        | yes        | What happens, see below.                                                   |
| `ticket`        | see action | A ticket key. For ticket triggers it also limits the event to that ticket. |
| `day`           | no         | Only fire on this day.                                                     |
| `delay_minutes` | no         | Wait this long after the trigger. Default 0.                               |
| `colleague`     | no         | Who sends the message. Needed when there is a `body`.                      |
| `body`          | see action | The message text.                                                          |

An event fires once per sprint.

### Triggers (`on`)

| Value              | When                           |
| ------------------ | ------------------------------ |
| `sprint_started`   | The user starts the sprint.    |
| `day_started`      | A new sprint day begins.       |
| `ticket_started`   | A ticket moves to in progress. |
| `ticket_in_review` | A ticket moves to in review.   |
| `ticket_done`      | A ticket moves to done.        |
| `sprint_completed` | The sprint is completed.       |

### Actions

| Value               | Needs                                                                             | What it does                                                                                             |
| ------------------- | --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `message`           | `body`, `colleague`                                                               | Sends a chat message.                                                                                    |
| `release_ticket`    | `ticket`                                                                          | Shows a hidden ticket on the board. A `body` is sent as a message.                                       |
| `update_ticket`     | `ticket`                                                                          | Changes the ticket. Fields: `add_criteria` (list of texts), `description_append` (text), `title` (text). |
| `open_pull_request` | `ticket` of type `review`, `colleague`, `branch`, `title`, `description`, `files` | A colleague opens a pull request in the user's repository.                                               |

```json
{
    "key": "new-criterion",
    "on": "ticket_in_review",
    "ticket": "WK-1",
    "action": "update_ticket",
    "colleague": "daniel",
    "add_criteria": ["The attempt is logged"],
    "body": "Hi {first_name}, the client added one more thing to WK-1."
}
```

### Placeholders in `body`

- `{first_name}`: the first word of the user's name.
- `{name}`: the full name.

### Pull request of a colleague

Extra fields of `open_pull_request`:

- `branch`: a valid branch name that contains the ticket key, so the pull request is linked to the ticket.
- `title` and `description`: text.
- `commit_message`: optional text.
- `files`: 1 to 20 files. Each has a relative `path` (no `..`, not in `.git`, no duplicates) and either `content` (text) or `from` (a file path inside the scenario folder). A file can be at most 100 KB. `from` files are read at import time.

Each file replaces the whole file at that path. The colleague's branch starts
from the first commit of the user's repository, which is the template. So write
the files against the template as it is: the pull request then shows the
colleague's changes and nothing else, also when the user changed the same file
in an earlier ticket. When a branch with that name is left over from an earlier
sprint in the same repository, the colleague takes the name with `-2` behind
it.

```json
{
    "key": "josh-pr",
    "on": "day_started",
    "day": 2,
    "ticket": "WK-4",
    "action": "open_pull_request",
    "colleague": "josh",
    "branch": "wk-4-repair-intake",
    "title": "WK-4: repair intake",
    "description": "Adds the intake form.",
    "files": [
        {
            "path": "app/Http/Controllers/RepairController.php",
            "from": "files/RepairController.php"
        }
    ]
}
```
