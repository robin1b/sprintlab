# Sprintlab: working agreements

Sprintlab is a learning platform where a developer works inside a simulated
software team. The user joins a fictional company (working name: **Workkit**,
a platform that leases laptops and smartphones to employees through their
employer, with a Sales and a Repairs department), picks up tickets, pushes code
to a real GitHub repository and gets pull request reviews and chat messages
from AI colleagues. Levels: junior, medior, senior.

## Stack

- Laravel 13 on **PHP 8.3** (the production host runs 8.3, so no 8.4-only syntax)
- Inertia 3 + Vue 3 + TypeScript, Tailwind CSS 4, shadcn-vue components (reka-ui)
- Laravel Wayfinder for typed routes (generated files are not committed)
- PHPUnit 12, Pint, Larastan (PHPStan)
- Laravel Socialite (GitHub sign-in), Laravel AI SDK (`laravel/ai`)
- SQLite locally and in tests, MySQL in production

## Product rules

- Everything the user sees is in **English**: UI, tickets, chat and reviews.
- The UI is **always dark**, in the style of a code editor. Calm and flat:
  no glows, no gradients, no lifting or bouncing hover effects. One accent
  colour, used sparingly. Inter for text, JetBrains Mono for code, ids and labels.
- AI colleagues (working names): Sarah (team lead), Daniel (project manager),
  Josh and Emily (developers), Claire (client, HR at a customer company).
- The scenario is scripted. AI only writes reviews and chat messages.

## Hosting constraints (shared hosting, no SSH)

- No long-running processes: no queue worker daemon, no websockets.
  Queued jobs are processed by the scheduler (`queue:work --stop-when-empty`
  every minute). "Real-time" UI uses polling.
- Webhook endpoints must answer fast and do the work in a queued job.
- Friendly error responses for the browser use status 200 with an error field;
  the host replaces 5xx bodies with its own page.
- The project root is the web root on the server (`index.php`, `.htaccess` and
  links to `public/` files in the root). A route can never start with a folder
  name that `.htaccess` blocks (`/app`, `/config`, `/docs`, `/storage`, ...).
- There is no npm on the server: `public/build` is in git. With every change
  in CSS, TypeScript or Vue, run `npm run build` and commit `public/build`.
- Whatever must happen on the server after new files arrive (migrations,
  imports, one-time fixes) belongs in `site:deploy`, never in a manual step.
  The whole setup is described in `docs/deployment.md`.
- Private files (real scenarios with answers, state files) live in
  `storage/app/private` and are never served or committed.

## Code conventions

- Thin controllers, Form Requests for validation, services in `app/Services`.
- Routes are split by area: `routes/auth.php`, `routes/app.php`,
  `routes/settings.php`, `routes/webhooks.php`.
- Every feature ships with tests. External HTTP calls are faked in tests.
- Secrets only in `.env`, never in the repository. New keys also go in
  `.env.example` (empty) and are read through `config()`, never `env()` in code.
- The privacy page (`resources/js/pages/Privacy.vue`) describes what is stored
  and what is sent to the AI. When that changes, change the page and the date
  in `PrivacyController::UPDATED_ON` in the same commit.

## Before calling something done

```
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse
npm run check
npm run types:check
npm run build
```

## Git

- Work on a feature branch. Robin opens the pull requests himself.
- Author and committer of every commit: `Robin Broos <robin1broos@gmail.com>`.
- No AI attribution anywhere: no `Co-Authored-By`, no "Generated with" lines,
  no session links, not in commits, pull requests or code.
