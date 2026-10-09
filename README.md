# Dice Rewards – Telegram dice game Mini App with a USDT rewards wallet

A complete Laravel 12 / PHP 8.3 platform: a Telegram bot, a Telegram Mini App
(web app) with single-player and two-player dice games, missions, a multi-level
referral program, an internal USDT rewards ledger, an administrator-controlled
withdrawal workflow and a role-based admin dashboard.

It is built for **standard PHP/MySQL shared hosting (cPanel)**: no Node.js,
Docker, Redis, Supervisor, WebSockets or long-running process is needed in
production. The front-end is plain CSS/JS shipped in `public/assets` – there is
nothing to compile.

> **Important.** Rewards are promotional accounting credits funded from an
> operator-defined reward budget. They are not crypto held in a wallet. Payouts
> are made manually by the operator and then recorded in the dashboard. Games
> are free to play: there are no deposits, stakes or wagers. Make sure running
> a rewards promotion is lawful where you and your users are, and that it
> respects Telegram's terms.

---

## Features

| Area | What you get |
|---|---|
| **Telegram bot** | `/start` onboarding with referral deep links, inline main menu that opens the Mini App, `/menu`, `/help`, admin-only `/stats`, `/pending`, `/budget`; webhook with secret-token check and duplicate-update protection; notifications through a retrying outbox. |
| **Mini App** | Home (profile, internal ID, balances, stats, recent activity, quick actions), Games (solo + duel), Missions, Withdraw, Invite friends. Mobile-first, works in Telegram's in-app browser and Telegram Web. |
| **Solo dice** | Two server-side dice from PHP's CSPRNG; doubles win the configured reward. Idempotent requests, cooldown, daily limit, budget check, immutable round history. |
| **Two-player duel** | Public or private matches, invite by deep link or by @username, server-side state machine (`waiting → ready → playing → completed / cancelled`), configurable dice/rounds/tie rule/reward mode, expiry, polling-based live view. |
| **Wallet** | Available, reserved, pending, total earned/withdrawn. Append-only ledger, exact decimals (`brick/math`), row locks, idempotency keys, nightly reconciliation. |
| **Reward budget** | Every reward draws from an administrator-funded budget with platform-wide and per-user daily caps. Empty budget ⇒ no rewards. |
| **Referrals** | Unique codes, multi-level (configurable depth), one-time qualification bonus + platform-funded percentage per level, qualification rules, campaign dates, fraud signals, auditable reversals. |
| **Missions** | Telegram channel/group (Bot API membership check), Instagram & custom (manual review), website visit (timer), invite N friends, daily activity. Schedules, limits, budgets, review queue. |
| **Withdrawals** | Name, address, network (TRC20 checksum validated), amount, fee, note; atomic reservation; `pending → approved → processing → paid` or `rejected / cancelled`; exactly-once release/payout; masked public confirmation in your channel. |
| **Admin dashboard** | Real-time stats, users (search, profile, ledger, games, referrals, missions, withdrawals, status, notes, flags, adjustments), withdrawals, missions, reviews, games, referrals, ledger, budget, fraud review, settings (branding, texts, rules, limits, templates, legal), Telegram setup, administrators, audit log, CSV exports. |
| **Roles** | Super administrator, Administrator, Finance reviewer, Support moderator, Read-only analyst. Financial and sensitive actions require password re-confirmation. |

Screens of the admin and the Mini App can be produced locally with the
development login (see *Local development*).

## Architecture

```
app/
├── Services/                 ← all business logic lives here
│   ├── WalletService.php     single gateway for every balance change (ledger + locks + idempotency)
│   ├── BudgetService.php     reward budget and daily caps
│   ├── GameEngine.php        solo dice
│   ├── MatchService.php      two-player state machine and settlement
│   ├── ReferralService.php   attribution, qualification, commissions, reversals
│   ├── MissionService.php    verification and rewards
│   ├── WithdrawalService.php request → review → payment workflow, public confirmation
│   ├── FraudService.php      review signals (velocity, shared networks, shared payout wallets)
│   ├── MiniAppAuth.php       init-data login → server-side session token
│   ├── Settings.php          editable settings (config/settings.php schema + DB overrides)
│   └── Telegram/             Bot API client, init-data validator, update handler, outbox, deep links
├── Http/Controllers/MiniApp  thin JSON controllers for the Mini App
├── Http/Controllers/Admin    dashboard controllers
├── Models/                   Eloquent models (ledger, rounds, rolls, audit log are immutable)
└── Console/Commands/         scheduler tasks, install, Telegram setup
config/dicegame.php           secrets from .env, roles & permissions
config/settings.php           editable settings: schema, validation, defaults
public/assets/                hand-written CSS/JS (no build step)
resources/views/              Blade: Mini App shell, admin dashboard
routes/api.php                Mini App API + Telegram webhook
routes/admin.php              admin dashboard
routes/console.php            schedule
tests/                        PHPUnit unit & feature tests
```

Key rules enforced in code:

* The browser never decides anything that matters. Dice, outcomes, rewards,
  balances and eligibility are computed in PHP; request bodies that try to
  supply them are ignored (there is a test for that).
* Every balance change goes through `WalletService`, which writes an immutable
  ledger entry in the same database transaction, locks the wallet row and uses
  a unique idempotency key. `php artisan wallet:reconcile` proves wallets equal
  the ledger.
* Every reward goes through `BudgetService`, which locks the budget row.
* State machines (`MatchStatus`, `WithdrawalStatus`) reject invalid transitions;
  settlements are guarded by row locks and unique ledger keys, so they happen
  exactly once even when requests are retried or raced.

## Requirements

* PHP **8.3+** with the usual Laravel extensions (`pdo_mysql`, `mbstring`,
  `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`). `bcmath`/`gmp` are not
  required: exact money arithmetic uses `brick/math` in pure PHP.
* MySQL **8.0+** or MariaDB **10.6+**
* HTTPS on your domain (Telegram requires it)
* Cron (recommended) – alternatives are provided if your host has none

## Quick start (production)

The full, click-by-click cPanel guide is in **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**. In short:

```bash
composer install --no-dev --optimize-autoloader   # or upload a build from deploy/build-release.sh
cp .env.example .env && php artisan key:generate
# edit .env: APP_URL, database, TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME, TELEGRAM_WEBHOOK_SECRET
php artisan migrate --force
php artisan app:install --seed                    # first super administrator + example missions
php artisan telegram:setup                        # webhook (with secret), commands, menu button
php artisan config:cache && php artisan route:cache && php artisan view:cache
# cron: * * * * * php /home/USER/dice/artisan schedule:run >> /dev/null 2>&1
```

Then sign in at `https://your-domain/admin`, **fund the reward budget**, review
the settings (rewards, limits, networks, channels, texts) and activate missions.

## Local development

```bash
composer install
cp .env.example .env && php artisan key:generate
# .env: APP_ENV=local, APP_DEBUG=true, DB_CONNECTION=sqlite, TELEGRAM_DEV_AUTH=true
touch database/database.sqlite
php artisan migrate
php artisan app:install --name=Dev --email=dev@example.com --password=ChangeMe-123456 --seed
php artisan serve
```

Open `http://127.0.0.1:8000/app` in a normal browser: with `APP_ENV=local` and
`TELEGRAM_DEV_AUTH=true` the Mini App signs you in as a random development
user (this path is disabled in every other environment and covered by a test).
To test inside Telegram, expose your machine over HTTPS (e.g. a tunnel) and set
`APP_URL` accordingly.

## Tests

```bash
php artisan test            # 81 tests, SQLite in memory
vendor/bin/pint --test      # code style
```

The suite covers Telegram init-data validation (valid, tampered, foreign bot,
expired), webhook secret and duplicate updates, wallet invariants
(idempotency, budget, caps, reservation, exactly-once settlement, reversal,
immutability, reconciliation), solo game rules and abuse cases, the match state
machine (joins, private invites, double rolls, ties, expiry, settlement),
referrals (attribution, no reassignment, self-referral, multi-level, commission,
reversal, velocity flags), missions (Bot API membership, manual review,
resubmission, visit timer, daily repeat, budgets), withdrawals (validation,
limits, reservation, rejection, payment, masked publication, cancellation,
shared-address flags) and the admin dashboard (login, every page rendering,
role restrictions, password re-confirmation, audit trail, settings validation).
CI (`.github/workflows/tests.yml`) runs the suite on SQLite and MySQL 8.

## Documentation

* [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) – shared hosting / cPanel installation, cron and no-cron options, updates, backups
* [docs/SECURITY.md](docs/SECURITY.md) – security model, anti-cheat and anti-fraud controls, operator checklist
* [docs/OPERATIONS.md](docs/OPERATIONS.md) – running the platform day to day: budget, withdrawals, missions, referrals, roles

## License

MIT
