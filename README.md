# Sprintlab

Practise working in a real dev team.

Sprintlab puts a developer inside a simulated software company. You pick up
tickets, write the code on your own machine, push to a real GitHub repository
and open pull requests. AI colleagues review your work, change the requirements
halfway and ask you to review theirs, the way it goes on a real job.

The fictional company is **Workkit** (working name): a platform that leases
laptops and smartphones to employees through their employer, with a Sales and a
Repairs department.

> Status: early development. Sign-in, the app shell and the GitHub connection
> (webhook, pull request review by an AI colleague) exist. Sprints, the board
> and the chat are next.

## How a review happens

1. You push a branch and open a pull request on GitHub.
2. GitHub calls Sprintlab's webhook. The call is checked (signature), stored and
   handed to a queued job.
3. The job fetches the diff, asks the AI team lead for a review and posts it on
   the pull request: a verdict, a summary and comments on specific lines.

Sprintlab never touches your machine. Everything goes through GitHub.

## Stack

- Laravel 13 (PHP 8.3+), Inertia 3, Vue 3 with TypeScript, Tailwind CSS 4
- Laravel Socialite (GitHub sign-in) and the Laravel AI SDK (Claude)
- SQLite locally, MySQL in production
- PHPUnit, Pint, Larastan

## Run it locally

```
composer setup
php artisan serve
```

`composer setup` installs the PHP and Node packages, creates `.env` and the
SQLite database, and builds the frontend. Open the login page and use
**Dev login (local only)**: it signs you in as a demo user, so you do not need a
GitHub app to look around. That route only exists when `APP_ENV=local`.

To sign in with GitHub and receive webhooks, register a GitHub App as described
in [docs/github-app.md](docs/github-app.md).

## Checks

```
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse
npm run check
npm run types:check
npm run build
```

Working agreements for contributors (and coding agents) are in
[AGENTS.md](AGENTS.md).
