# Deployment guide (shared hosting / cPanel)

This guide assumes a typical cPanel account with PHP 8.3, MySQL/MariaDB, an
SSL certificate and (ideally) SSH and cron access. Alternatives are given for
hosts without SSH or cron.

## 1. Create the Telegram bot

1. Open **@BotFather** → `/newbot` → choose a name and a username. Copy the **token**.
2. Optional but recommended: `/newapp` to register a Mini App for direct links
   (`https://t.me/YourBot/app?startapp=...`). Use `https://your-domain/app` as the
   URL and remember the **short name**.
3. Optional: `/setdescription`, `/setabouttext`, `/setuserpic`.

The bot needs no privacy changes. To verify channel/group missions, add the bot
as an **administrator** of those chats. To publish payout confirmations and the
withdrawal review feed, add it as an administrator (with "Post messages") of the
public channel and the private review channel.

## 2. Prepare the database

cPanel → **MySQL Databases**: create a database (e.g. `user_dice`), a user with a
strong password, and add the user to the database with **ALL PRIVILEGES**.

## 3. Upload the code

The application must live **outside** `public_html`; only its `public` folder is
web-accessible.

**With SSH and Composer (recommended)**

```bash
cd ~
git clone https://github.com/<you>/<repo>.git dice
cd dice
composer install --no-dev --optimize-autoloader
```

**Without SSH**

On your computer run `deploy/build-release.sh` (needs PHP + Composer locally). It
produces a zip with the `vendor` folder included. Upload it with cPanel →
**File Manager** to your home directory (e.g. `/home/user/dice`) and extract it.

### Point the domain to `public/`

* Best: cPanel → **Domains** → set the document root of your (sub)domain to
  `/home/user/dice/public`.
* If the document root cannot be changed (main domain locked to `public_html`):
  copy the *contents* of `dice/public` into `public_html` and edit
  `public_html/index.php`, replacing every `__DIR__.'/../` path with
  `__DIR__.'/../dice/`. Never copy the whole project into `public_html`.
* Last resort: the repository root contains an `.htaccess` that rewrites every
  request into `public/` and blocks `.env`, `composer.*`, etc. Use it only if
  neither option above is possible.

Make sure HTTPS works (cPanel → **SSL/TLS Status** → AutoSSL).

## 4. Configure `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Without SSH: copy `.env.example` to `.env` in the File Manager and generate a key
locally with `php artisan key:generate --show`, then paste it as `APP_KEY`.

Edit at least:

| Key | Value |
|---|---|
| `APP_URL` | `https://your-domain` (no trailing slash) |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `DB_*` | the database, user and password from step 2 |
| `TELEGRAM_BOT_TOKEN` | token from BotFather |
| `TELEGRAM_BOT_USERNAME` | bot username without `@` |
| `TELEGRAM_WEBHOOK_SECRET` | 32+ random characters `[A-Za-z0-9_-]`, e.g. `php -r "echo bin2hex(random_bytes(24));"` |
| `TELEGRAM_MINI_APP_SHORT_NAME` | short name from `/newapp` (optional) |
| `TRUSTED_PROXIES` | `*` if your host or Cloudflare sits in front of PHP, otherwise keep the default |

Secrets live only in `.env`. The dashboard never stores or displays them.
Protect the file: `chmod 600 .env`.

## 5. Install

```bash
php artisan migrate --force
php artisan app:install --seed      # asks for name, e-mail and password of the super administrator
php artisan storage:link            # harmless; only needed if you later add uploads
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Directory permissions: `storage/` and `bootstrap/cache/` must be writable by PHP
(usually `755` directories / `644` files on cPanel, owned by your account).

**Without SSH**: cPanel often provides **Terminal** (use it like SSH). If not,
ask your host to run the commands above once, or run them from a local machine
connected to the remote database (`DB_HOST` = the server's remote MySQL host,
with your IP allowed under cPanel → **Remote MySQL**).

## 6. Connect Telegram

```bash
php artisan telegram:setup
```

This registers the webhook `https://your-domain/api/telegram/webhook` with the
secret token, the bot commands and the Mini App menu button. You can do the same
in **Admin → Telegram bot**, which also shows the webhook status, the last
update received and failed notifications.

Then send `/start` to your bot: you should get the welcome message with the
**Open app** button.

## 7. Scheduled tasks

Add **one** cron job (cPanel → **Cron Jobs**, every minute):

```
* * * * * /usr/local/bin/php /home/user/dice/artisan schedule:run >> /dev/null 2>&1
```

(Use the PHP 8.3 binary path your host documents, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`.)

It runs, without overlapping:

| Task | Frequency | Purpose |
|---|---|---|
| `telegram:dispatch` | every minute | deliver queued notifications, retry failures |
| `matches:expire` | every minute | cancel abandoned matches |
| `missions:close` | every 15 min | close missions past their end date/budget |
| `fraud:scan` | hourly | detect referral clusters |
| `wallet:reconcile` | daily | verify every wallet against the ledger (raises a high-severity flag on mismatch) |
| `maintenance:prune` | daily | delete expired sessions and old delivered notifications (never financial or audit data) |

Notifications are also sent right after each web request, so the bot stays
responsive even if cron runs less often.

### No cron available?

Pick one:

* **External cron service** (cron-job.org, EasyCron, UptimeRobot…): set a long
  random `CRON_TOKEN` in `.env` and have the service call
  `https://your-domain/cron/run/<CRON_TOKEN>` every minute. The endpoint is
  disabled when the token is empty and rate-limited.
* **Traffic-driven**: set `SCHEDULER_FALLBACK=true`. The scheduler then runs at
  most once per minute after a normal web request. Simple, but tasks only run
  when people use the app.

No queue worker is required: the queue connection is `sync` and all background
work is done by the short scheduled tasks above.

## 8. First configuration in the dashboard

1. **Admin → Reward budget**: add the amount of USDT you have set aside for
   rewards. Rewards stop automatically when the budget is empty.
2. **Settings → Single-player / Two-player / Reward budget controls**: rewards,
   cooldowns, limits and daily caps.
3. **Settings → Withdrawals**: minimum/maximum, limits, networks and fees.
4. **Settings → Telegram channels**: public payout channel and private review
   channel (`@name` or `-100…` id).
5. **Settings → Branding / Home / Notification templates / Legal**: your texts.
6. **Missions**: edit the example missions (channel names, links) and activate them.
7. **Administrators**: create accounts for your team with the least privileged role.

## Updating

```bash
php artisan down
git pull                                  # or upload the new release over the old files (keep .env and storage/)
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

## Backups

Back up the **database** daily (cPanel → **Backup** or a cron with `mysqldump`)
and keep `.env` somewhere safe. The ledger, withdrawals and audit log are the
source of truth for every balance; without them balances cannot be rebuilt.

## Troubleshooting

| Symptom | Check |
|---|---|
| "Please open this app from Telegram" | You opened `/app` in a normal browser. Use the bot's button. |
| "Telegram authentication failed" | `TELEGRAM_BOT_TOKEN` must belong to the bot that opens the Mini App; run `php artisan config:cache` after editing `.env`. |
| Bot does not answer `/start` | Admin → Telegram bot: webhook URL must match and show no last error; `APP_URL` must be HTTPS; `TELEGRAM_WEBHOOK_SECRET` must be set **before** `telegram:setup`. |
| Notifications arrive late | Cron not running – see step 7. Failed messages are listed in Admin → Telegram bot. |
| Channel mission says "could not verify" | The bot must be an administrator of that channel/group. |
| 500 error after editing `.env` | `php artisan config:clear`, then check `storage/logs/laravel-*.log`. |
