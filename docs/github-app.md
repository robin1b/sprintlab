# The Sprintlab GitHub App

One GitHub App does three jobs for Sprintlab:

- **Sign-in**: users log in with their GitHub account.
- **API access**: Sprintlab reads the diff of a pull request and posts a review.
- **Webhooks**: GitHub tells Sprintlab when a pull request is opened or updated.

The app needs a public address for the webhook, so register it once the site is
online (or use a tunnel while developing).

## Register the app

On GitHub: **Settings → Developer settings → GitHub Apps → New GitHub App**.

| Field                                                  | Value                                                |
| ------------------------------------------------------ | ---------------------------------------------------- |
| GitHub App name                                        | `Sprintlab` (or `Sprintlab Dev` for a test app)      |
| Homepage URL                                           | `https://sprintlab.example.com`                      |
| Callback URL                                           | `https://sprintlab.example.com/auth/github/callback` |
| Request user authorization (OAuth) during installation | off                                                  |
| Webhook: Active                                        | on                                                   |
| Webhook URL                                            | `https://sprintlab.example.com/webhooks/github`      |
| Webhook secret                                         | a long random string (keep it, it goes in `.env`)    |
| Where can this GitHub App be installed?                | Only on this account, until the platform opens up    |

Repository permissions:

| Permission    | Access         | Why                                  |
| ------------- | -------------- | ------------------------------------ |
| Pull requests | Read and write | read the changed files, post reviews |
| Contents      | Read-only      | read the code of a pull request      |
| Metadata      | Read-only      | required by GitHub                   |

Account permissions: **Email addresses: Read-only** (optional, to store the
user's email at sign-in).

Subscribe to events: **Pull request** and **Pull request review**. GitHub sends the installation events to
every app without subscribing.

After creating the app, on its settings page:

1. Note the **App ID** and the **Client ID**.
2. **Generate a new client secret** and copy it.
3. **Generate a private key**. GitHub downloads a `.pem` file.

## Configure Sprintlab

In `.env` (never in the repository):

```
GITHUB_CLIENT_ID=           # Client ID of the app
GITHUB_CLIENT_SECRET=       # the client secret
GITHUB_APP_ID=              # App ID
GITHUB_APP_SLUG=sprintlab   # the name in the app's public URL
GITHUB_APP_PRIVATE_KEY=     # see below
GITHUB_WEBHOOK_SECRET=      # the webhook secret you chose

ANTHROPIC_API_KEY=          # for the AI colleagues
```

`GITHUB_APP_PRIVATE_KEY` holds the whole `.pem` file on one line, base64-encoded:

```
# macOS / Linux
base64 -w0 sprintlab.private-key.pem      # on macOS: base64 -i sprintlab.private-key.pem

# Windows PowerShell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("sprintlab.private-key.pem"))
```

## What happens when

| GitHub event                                                   | Sprintlab does                                                                                 |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `installation` created                                         | stores the installation and its repositories, links it to the user with that GitHub account    |
| `installation` deleted                                         | removes the installation and everything under it                                               |
| `installation_repositories`                                    | adds or removes repositories                                                                   |
| `pull_request` opened, reopened, synchronize, ready_for_review | stores the pull request and queues a review (not for drafts or pull requests opened by a bot)  |
| `pull_request` closed                                          | records closed or merged                                                                       |
| `pull_request_review` submitted                                | queues the assessment of the user's review of a colleague's pull request (not for bot reviews) |

Every call is stored in `webhook_deliveries` first. A job processes it, so
GitHub always gets a fast answer and a call that arrives twice is handled once.

## The queue

The production host has no queue worker running all the time. The scheduler
runs `queue:work --stop-when-empty` every minute, so a review appears within a
minute or two of a push. The server needs one cron job:

```
* * * * * php /path/to/sprintlab/artisan schedule:run
```

## Limits and switches

| `.env` key                  | Default             | Meaning                          |
| --------------------------- | ------------------- | -------------------------------- |
| `SPRINTLAB_AI_ENABLED`      | `true`              | `false` stops all AI reviews     |
| `SPRINTLAB_REVIEW_MODEL`    | `claude-sonnet-5-5` | the model that writes reviews    |
| `SPRINTLAB_REVIEWS_PER_DAY` | `20`                | reviews per installation per day |

## Troubleshooting

- **401 on the webhook**: the secret in `.env` differs from the one in the app settings.
- **503 on the webhook**: `GITHUB_WEBHOOK_SECRET` is empty.
- **No review appears**: check that the cron job runs, then look at the
  `webhook_deliveries` table (`error` column) and the `reviews` table (`status`
  and `error`). GitHub also shows every delivery under the app's
  **Advanced → Recent deliveries**.
