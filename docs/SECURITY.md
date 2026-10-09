# Security model

This document lists the threats the platform is designed against and the
controls that implement each one, with pointers to the code and tests.

## Identity and sessions

| Threat | Control |
|---|---|
| Forged Telegram identity (editing the user id in JavaScript, URL or body) | Mini App login only accepts Telegram **init data**, validated with the official HMAC-SHA256 procedure (`secret = HMAC("WebAppData", bot_token)`), constant-time comparison, `auth_date` freshness (configurable max age) and required user fields. The user id is taken from the signed payload only. `App\Services\Telegram\InitDataValidator`, `tests/Unit/InitDataValidatorTest.php`. |
| Session theft / CSRF on the Mini App API | After login the server issues a random 64-character bearer token; only its SHA-256 hash is stored (`app_sessions`). Tokens expire (`MINIAPP_SESSION_TTL_HOURS`) and are deleted when an account is suspended. Bearer tokens are not sent automatically by browsers, so the API is not CSRF-exploitable and works inside Telegram Web's iframe without third-party cookies. |
| Forged webhook calls | `X-Telegram-Bot-Api-Secret-Token` compared in constant time; the webhook is disabled when no secret is configured. |
| Replayed / retried webhook updates | `telegram_updates.update_id` is unique: each update is processed once. |
| Admin account takeover | Separate `admins` table and session guard, bcrypt hashing, login rate-limited per IP and per e-mail, session regeneration, inactive accounts cannot sign in, strong password policy (12+ characters, letters and numbers). |
| Abuse of an unattended admin session | Financial and sensitive actions (withdrawal transitions, balance adjustments, reversals, budget changes, sensitive settings, Telegram setup, admin management) require the administrator's **password** again (`current_password` rule) plus a confirmation dialog. |
| Development shortcut left on | The development login works only when `APP_ENV=local` **and** `TELEGRAM_DEV_AUTH=true` (tested). |

## Game integrity

| Threat | Control |
|---|---|
| Client chooses dice or outcome | Dice come from `random_int()` (CSPRNG) in `SecureDiceRoller`; the play endpoint ignores the request body (`test_client_supplied_results_are_ignored`). |
| Double payout via repeated requests | Each round has a unique id; `(user_id, idempotency_key)` is unique; the reward ledger entry key is `game:<round uuid>` (unique). Retries return the original round. Verified against MariaDB with 20 concurrent identical requests → 1 round. |
| Racing the cooldown / daily limit | The player's row is locked (`SELECT … FOR UPDATE`) for the whole play, so checks and inserts are serialised. |
| Editing past results | `GameRound`, `MatchRoll`, `LedgerEntry`, `AuditLog` refuse updates and deletes at model level; the admin UI has no edit actions for results. |
| Two-player cheating | Players can only roll for themselves and only once per round (unique `(match, user, round)`); private matches need the invite code (and the invited user if one was named); creators cannot join their own match; every transition is validated by `MatchStatus::canTransitionTo()` under a row lock; settlement is guarded by `settled_at` and unique ledger keys. |

## Money

| Threat | Control |
|---|---|
| Rounding / float errors | Decimal(20,6) columns and exact arithmetic with `brick/math` (`App\Support\Money`); amounts truncate down. |
| Lost or duplicated balance updates | `WalletService` is the only code that changes balances. Each operation locks the wallet row, writes an immutable ledger entry with a unique idempotency key and updates the wallet in the same transaction. Balances can never become negative. |
| Silent balance tampering in the database | `wallet:reconcile` (daily) compares each wallet with the sum of its ledger entries and raises a high-severity flag on mismatch (tested). |
| Unlimited, unfunded rewards | Every reward draws from the locked reward budget; platform-wide and per-user daily caps; missions have their own budgets and limits. |
| Withdrawing more than available / double spending | Withdrawal requests lock the user, check limits and reserve funds atomically; one open request at a time by default. Verified with 10 concurrent requests against a balance for 2 → exactly 2 accepted. |
| Paying twice or releasing after payment | A reservation is settled once: either released (reject/cancel) or paid out, guarded by unique ledger keys and an explicit check; transaction hashes are unique across withdrawals. |
| Announcing unpaid withdrawals | Public channel messages are only sent from the `paid` transition (which requires a transaction hash and an explicit "verified on-chain" confirmation), once per withdrawal. |
| Leaking payout data | Public messages contain only an alias ("Alex M."), the net amount, network, a masked address (configurable visible characters, at least half always hidden) and the explorer link. Full names, notes and full addresses never leave the dashboard. |
| Sending funds to a wrong address | Per-network address patterns, TRON Base58Check checksum validation, explicit network warning and confirmation checkbox. |
| Private keys | The platform never holds private keys and never sends crypto. Payment is made by the operator outside the system and then recorded. If automatic payouts are added later they must be a separate, explicitly configured integration with proper key management. |

## Referral and mission fraud

| Threat | Control |
|---|---|
| Self-referral / reassignment | Referrer is set only when the account is created and never changed; codes resolving to the same Telegram id are ignored. |
| Duplicate referral rewards | One record per `(event, source, level)` with a unique key, shared with the ledger entry. |
| Fake accounts farming bonuses | Rewards require qualification (games played, account age, not flagged, active). Signals: sign-up velocity per inviter, many referrals from one network (weak signal, never decisive alone), clusters found by the hourly scan, the same payout wallet used by several accounts (strong signal). Flags are reviewed by humans; reversals are audited and return funds to the budget. |
| Claiming missions without doing them | Telegram membership is checked through the Bot API; Instagram and custom missions require a moderator's approval; website visits require opening the link through the app and a minimum time (documented as low assurance); invite and daily missions use platform data. `(mission, user, period)` is unique. |

## Web security

* CSRF protection on all admin forms (Laravel `web` middleware).
* Output escaping in Blade (`{{ }}`) and in the Mini App (`esc()` / `textContent`); notification templates escape every placeholder for Telegram HTML.
* All queries via Eloquent / query builder with bindings.
* Server-side validation with Form Requests and explicit rules; amounts are validated as decimal strings.
* Strict Content-Security-Policy: the admin allows only same-origin scripts (no inline JavaScript) and `frame-ancestors 'none'`; the Mini App allows `telegram.org` scripts and framing by Telegram Web only. `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, and HSTS on HTTPS.
* Rate limits (configurable): login, API, game actions, mission actions, withdrawals, webhook, admin actions.
* IP addresses are stored only as keyed HMAC hashes.
* CSV exports neutralise spreadsheet formulas and are written to the audit log.
* Secrets only in `.env`; the bot token is never sent to the browser or logged (it is redacted from HTTP errors).

## Operator checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, HTTPS everywhere, `SESSION_SECURE_COOKIE=true`
- [ ] Strong, unique `TELEGRAM_WEBHOOK_SECRET`; `.env` not web-accessible and `chmod 600`
- [ ] Document root points to `public/`
- [ ] Super administrator password stored in a password manager; least-privilege roles for staff
- [ ] Cron (or an alternative) configured; check **Admin → Telegram bot** and the daily reconciliation result
- [ ] Reward budget funded with money you actually hold for rewards; daily caps set
- [ ] Database backups scheduled
- [ ] Legal texts (terms, privacy) reviewed for your jurisdiction
