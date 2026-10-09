# Operating the platform

## Roles

| Role | Typical use | Can |
|---|---|---|
| Super administrator | Owner | Everything, including sensitive settings, Telegram setup and administrators |
| Administrator | Manager | Users, withdrawals, missions, reviews, reversals, non-sensitive settings |
| Finance reviewer | Payouts | Withdrawals, balance adjustments, reversals, reward budget, exports |
| Support moderator | Community | Users (status, notes, flags), mission reviews, fraud review |
| Read-only analyst | Reporting | View dashboards and exports, no changes |

The exact matrix is shown in **Admin → Administrators** and defined in
`config/dicegame.php`.

## Reward budget

The budget is the amount of USDT you have set aside to back rewards. Every game,
match, mission and referral reward draws from it, so the total you can owe users
is always bounded.

* **Fund** it when you top up your payout wallet; **remove** funds if you reduce
  the programme. Each change needs a note and your password, and is logged.
* Daily caps (Settings → Reward budget controls) limit how fast it is consumed:
  a platform-wide cap and a per-user cap.
* When it is empty: the solo game pauses with a clear message, match and
  mission rewards are not paid, referral rewards are recorded as *skipped*.
* Reversals and manual debits return money to the budget.

## Withdrawals

1. A user requests a withdrawal. The amount (including the network fee) moves
   from *available* to *reserved*. The user gets a Telegram confirmation and the
   private review channel gets a notice with any fraud signals.
2. **Review** (Admin → Withdrawals → request): check the user's account age,
   games, ledger, previous withdrawals, open fraud signals and whether the address
   was used by other accounts.
3. **Approve** – queued for payment (nothing is sent automatically).
4. **Mark processing** when you start the transfer.
5. Send **the net amount** shown on the page from your own wallet on the
   indicated network to the indicated address.
6. **Mark as paid** with the transaction hash after checking it on the explorer.
   This consumes the reservation, notifies the user and posts a masked
   confirmation to the public channel.
7. If anything is wrong, **Reject** with a reason (pending, approved or
   processing): the reserved amount returns to the user's balance exactly once.

Users can cancel their own request while it is still pending.

## Missions

* Create missions in **Admin → Missions**. The verification method must fit the
  type: Telegram missions use the Bot API (the bot must be an admin of the chat),
  Instagram and custom missions are always reviewed manually, website missions
  use a visit timer or manual review, invite/daily missions are automatic.
* Use start/end dates, daily and total limits, a budget and eligibility (minimum
  games, account age) to control cost and abuse.
* **Mission reviews** lists submissions waiting for a decision. Approving pays
  the reward; rejecting notifies the user with your reason and lets them resubmit.
* Missions with history are soft-deleted to preserve records.

## Referrals

* Settings → Referral program: depth, per-level fixed bonus (paid once when the
  invited user qualifies) and percentage (platform-funded share of the invited
  user's game and mission rewards), qualification rules and campaign dates.
* Admin → Referrals shows rules, top inviters, suspicious activity and the full
  reward history. Reversing a reward needs a reason and your password.

## Fraud review

Signals are raised automatically (sign-up bursts, shared networks, referral
clusters, shared payout addresses, ledger mismatches) or manually. They do not
punish anyone by themselves. Investigate, then:

* **Restrict** the account (can view, cannot play, claim or withdraw) or
  **suspend** it (signed out, cannot use the app);
* reject pending withdrawals and reverse improper rewards;
* resolve or dismiss the signal with a note.

## Settings

Everything players see (name, colours, font, navigation labels, home texts, game
rules, notification templates, legal texts, time zone and date format) and all
business rules (rewards, cooldowns, limits, networks and fees, referral rules,
rate limits, maintenance switches) are editable in **Admin → Settings**. Groups
marked 🔒 are sensitive: only super administrators can change them and every
change asks for the password. All changes are recorded in the audit log with the
old and new values.

Credentials (bot token, webhook secret, database) are **not** settings – they
live in `.env` on the server.

## Maintenance

* **Settings → General → Maintenance mode** closes the Mini App for players
  (the dashboard stays available).
* **Settings → Single-player → Game maintenance** pauses only the games.
* `php artisan wallet:reconcile` can be run at any time; it is also run nightly.
