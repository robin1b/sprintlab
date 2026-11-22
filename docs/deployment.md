# Deployment (shared hosting, no SSH)

Sprintlab runs on shared hosting (Hostinger) where nobody logs in on the
server. Everything happens through the control panel (hPanel): Git, one cron
job and the file manager. This page explains how that works, how to set it up
the first time and what to do when something fails.

## How it works

1. **Files.** hPanel → Advanced → Git pulls the `main` branch into the folder
   of the subdomain. That only copies files.
2. **One cron job**, every minute, runs `scripts/cron.php`. That script:
    - installs the Composer packages when `composer.lock` changed, or on the
      very first run when `vendor/` does not exist yet. This happens _before_
      Laravel starts, because Laravel cannot start without its packages
      (`App\Support\Deploy\PackageInstaller`, plain PHP);
    - then starts Laravel's scheduler (`artisan schedule:run`).
3. **The scheduler** (`routes/console.php`) runs every minute:
    - `site:deploy`: when the migrations, the scenarios, the build or the
      packages changed, it runs `migrate --force`, `sprintlab:import-scenarios`
      and `optimize:clear`. Nothing changed: it stops at once;
    - `queue:work --stop-when-empty`: webhooks, AI reviews, chat replies;
    - `sprintlab:tick`: scenario events with a delay;
    - `model:prune` (daily): removes old webhook messages.
4. **The result** is shown on the admin page (AI usage → Deployment) and
   written to `storage/logs/deploy.log`.

So a deployment is: merge to `main` → hPanel → Git → **Deploy** → within one or
two minutes the cron job has done the rest.

## The project root is the web root

The host cannot point a domain at `public/`, so the whole project sits in the
web folder. That is made safe like this:

- `index.php` in the root starts Laravel (locally the web server uses
  `public/index.php`).
- `.htaccess` in the root refuses everything that is not meant to be public:
  dotfiles (`.env`, `.git`), every `.json`, `.md`, `.log`, `.sql`, `.sqlite`
  and similar file, the folders `app`, `bootstrap`, `config`, `database`,
  `docs`, `resources`, `routes`, `scripts`, `storage`, `tests`, `vendor`,
  `node_modules` and `public`, and every PHP file except `index.php`.
- Links in the root, also in git, make the public files reachable:
  `build → public/build`, `favicon.ico`, `favicon.svg`,
  `apple-touch-icon.png` and `robots.txt`. A new public folder in `public/`
  needs a link in the root too.
- A route can never start with one of the blocked folder names (`/app`,
  `/config`, `/docs`, `/storage`, …). A test guards this.
- `public/build` is in git, because there is no npm on the server. **Run
  `npm run build` and commit `public/build` with every change in CSS,
  TypeScript or Vue.** A test fails when a page is missing from the build.

The folder of a subdomain usually sits _inside_ the folder of the main domain
(`public_html/sprintlab`). The same files can then be reached through
`https://robinbroos.com/sprintlab/...` as well. Two things cover that:

- the blocking rules in `.htaccess` do not depend on the address the folder is
  reached through;
- Laravel only answers on the host of `APP_URL` (trusted hosts), so the
  application itself does not run under the main domain.

## First time

Replace `u000000000` with your own hosting user and check every path in the
file manager: one wrong character and nothing runs.

1. **Subdomain.** hPanel → Domains → Subdomains → create `sprintlab`. Note the
   folder hPanel shows (for example
   `/home/u000000000/domains/robinbroos.com/public_html/sprintlab`). Delete the
   placeholder file hPanel puts in it (`default.php` or `index.html`): Git needs
   an empty folder.
2. **PHP 8.3.** hPanel → Advanced → PHP Configuration → choose PHP 8.3 for the
   subdomain.
3. **SSL.** hPanel → Security → SSL → install the free certificate for the
   subdomain.
4. **Database.** hPanel → Databases → MySQL → create a database and a user.
   Note the database name, the user and the password.
5. **Git.** hPanel → Advanced → Git → repository
   `https://github.com/robin1b/sprintlab.git`, branch `main`, folder = the
   folder of step 1 → Create, then **Deploy**.
6. **`.env`.** File manager → the folder of step 1 → new file `.env` with the
   content from [Production .env](#production-env). The app key is made on
   your own computer, in the project folder:

    ```
    php artisan key:generate --show
    ```

    Paste the line that starts with `base64:` after `APP_KEY=`.

7. **Cron job.** hPanel → Advanced → Cron Jobs → type **Custom**, every minute
   (`* * * * *`):

    ```
    /opt/alt/php83/usr/bin/php /home/u000000000/domains/robinbroos.com/public_html/sprintlab/scripts/cron.php
    ```

    The standard `php` of the host is older than 8.3, so the full path matters.

8. **Wait a few minutes.** The first run installs the packages (this can take
   two or three minutes), the next one migrates the database and imports the
   demo scenario. Follow it in the file manager: `storage/logs/deploy.log`
   should end with `Packages installed.` and then
   `New version ready: migrate, sprintlab:import-scenarios, optimize:clear.`
9. **GitHub App.** Follow [github-app.md](github-app.md) with the live
   address, and put the keys in `.env`.
10. **Open the site** and sign in. Put your own GitHub id in
    `SPRINTLAB_ADMIN_GITHUB_IDS` to see the admin page.
11. **Check from outside** with the list in [Checks after going live](#checks-after-going-live).

## Production .env

```
APP_NAME=Sprintlab
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://sprintlab.robinbroos.com

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=720
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
GITHUB_REDIRECT_URI=https://sprintlab.robinbroos.com/auth/github/callback
GITHUB_APP_ID=
GITHUB_APP_SLUG=
GITHUB_APP_PRIVATE_KEY=
GITHUB_WEBHOOK_SECRET=

ANTHROPIC_API_KEY=
SPRINTLAB_AI_ENABLED=true
SPRINTLAB_AI_MONTHLY_BUDGET=10
SPRINTLAB_ADMIN_GITHUB_IDS=
SPRINTLAB_CONTACT_EMAIL=
```

- `APP_URL` must be exactly the address of the site, with `https://` and
  without a slash at the end. Laravel refuses every other host.
- `APP_DEBUG` stays `false`: with `true` every visitor sees error details.
- `.env` only exists on the server. It is never committed.
- Config and routes are not cached, so a change in `.env` works at once.

## Every next deployment

1. Locally: all checks green, `npm run build`, commit (with `public/build`),
   pull request, merge to `main`.
2. hPanel → Advanced → Git → **Deploy**.
3. After one or two minutes: admin page → Deployment shows `Release: OK` with
   the current time. (Changed nothing but PHP or Vue code? Then the release
   time only moves when the build, a migration, a scenario or a package
   changed. That is normal.)

## Private scenarios

The repository is public, so it only holds a demo scenario. Real scenarios
contain the answers (planted issues, rubrics) and are uploaded by hand: file
manager → `storage/app/private/scenarios/` → upload the `.json` file. Within a
minute `site:deploy` notices the new or changed file and imports it. Files of
a colleague's pull request go in the same folder, as the scenario describes.

## Checks after going live

The quickest way is one command, on your own computer in the project folder:

```
php artisan sprintlab:check-live
php artisan sprintlab:check-live --main-domain=https://robinbroos.com/sprintlab
```

It does the same as the list of addresses below and more: it also checks the
security headers, the redirect from `http://` to `https://` and `/up`. It
prints a table with OK or FAIL per check and ends with an error when anything
fails. The first line checks `APP_URL` of your local `.env`, so set that to
the live address first or give the address after the command. With
`--main-domain` it also checks the same files through the main domain.

Or open these in a browser by hand. Each one must give "403 Forbidden" or "404 Not
Found", never the content of a file:

```
https://sprintlab.robinbroos.com/.env
https://sprintlab.robinbroos.com/composer.json
https://sprintlab.robinbroos.com/storage/logs/deploy.log
https://sprintlab.robinbroos.com/storage/app/private/deploy-state.json
https://sprintlab.robinbroos.com/vendor/autoload.php
https://sprintlab.robinbroos.com/public/index.php
https://sprintlab.robinbroos.com/scripts/cron.php
```

If the folder sits inside the folder of the main domain, also check through
the main domain (same expectation):

```
https://robinbroos.com/sprintlab/.env
https://robinbroos.com/sprintlab/storage/logs/deploy.log
https://robinbroos.com/sprintlab/storage/app/private/deploy-state.json
https://robinbroos.com/sprintlab/vendor/autoload.php
https://robinbroos.com/sprintlab/
```

The last one must not show Sprintlab (a "400 Bad Request" is right). To close
that door completely, add one line to the `.htaccess` of the main site, next
to its other `RedirectMatch` lines:

```
RedirectMatch 404 ^/sprintlab(/|$)
```

These rules were written for this host but could only be tested here against
a model of them, not on the real server. Do the checks once after the first
deployment.

## When something fails

| What you see                                                              | Cause and fix                                                                                                                                                                                                         |
| ------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The site shows a plain 500 page right after the first deployment          | The packages are not installed yet, or `.env` is missing. Look at `storage/logs/deploy.log` and wait for `Packages installed.`                                                                                        |
| `deploy.log` stays empty or does not exist                                | The cron job does not run or its path is wrong. Compare the command character by character with the folder in the file manager. The cron output in hPanel says "Could not open input file" for a wrong path.          |
| `Packages not installed: Composer was not found on the server.`           | The host has no Composer for the cron user. Upload `composer.phar` (from getcomposer.org) to the project root with the file manager: the script looks there first.                                                    |
| `Packages not installed: composer install: … requires php >= 8.3`         | The cron job runs with the old PHP. Use `/opt/alt/php83/usr/bin/php` in the command.                                                                                                                                  |
| `Packages not installed: this host does not allow PHP to start a process` | Composer cannot run from a cron job on this host. Upload the `vendor` folder by hand (zip it locally after `composer install --no-dev`, upload, extract). The script then sees that the packages match and continues. |
| `Failed at step 1: migrate --force: SQLSTATE … Access denied`             | Wrong database name, user or password in `.env`. After fixing it the next try comes within ten minutes, or at once after deleting `storage/app/private/deploy-state.json`.                                            |
| `Failed at step 2: sprintlab:import-scenarios: …`                         | A scenario file is not valid; the message names the file and the reason. Fix or remove the file.                                                                                                                      |
| Every page gives "400 Bad Request"                                        | `APP_URL` in `.env` is not the address in the browser.                                                                                                                                                                |
| Signing in with GitHub ends on an error page from GitHub                  | The callback URL in the GitHub App is not `APP_URL` + `/auth/github/callback`, or `GITHUB_REDIRECT_URI` differs from it.                                                                                              |
| A page is blank and the browser console mentions Content Security Policy  | The policy blocks something. Set `SPRINTLAB_CSP_REPORT_ONLY=true` in `.env` (works at once, the browser then only reports), then report which address was blocked (the console names it).                             |
| A page looks unstyled or a new page is blank                              | The build was not committed. Locally: `npm run build`, commit `public/build`, merge, Deploy.                                                                                                                          |
| Reviews or chat replies never arrive                                      | The cron job is the queue. Check that it still runs (admin page → Deployment, `deploy.log`), then `ANTHROPIC_API_KEY`, `SPRINTLAB_AI_ENABLED` and the budget on the admin page.                                       |
| An error page of Sprintlab ("Something went wrong")                       | The details are in `storage/logs/laravel-YYYY-MM-DD.log`.                                                                                                                                                             |

**Run everything again:** delete `storage/app/private/deploy-state.json` in the
file manager (and `deploy-packages.json` next to it to force Composer too).
The next cron run does all steps again.

**Stop the AI at once:** set `SPRINTLAB_AI_ENABLED=false` in `.env`.

## Not tested yet

- The migrations only ran on SQLite (the tests). The first deployment is their
  first run on MySQL.
- `scripts/cron.php` and the Composer step were tested with a fake Composer,
  not on the host itself.
- The `.htaccess` rules were not tried on the real web server (see the checks
  above).
