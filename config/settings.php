<?php

/*
|--------------------------------------------------------------------------
| Editable platform settings (schema + defaults)
|--------------------------------------------------------------------------
|
| Administrators edit these values in Admin → Settings. Overrides are stored
| in the `settings` table; anything not overridden falls back to the default
| below. Groups marked `sensitive` need the `settings.sensitive` permission
| and password re-confirmation. Never put credentials in this file.
|
| Field types: string, text, int, decimal, bool, select, json, color, datetime
|
*/

$template = fn (string $text) => $text;

return [

    'general' => [
        'label' => 'General',
        'fields' => [
            'app.name' => ['type' => 'string', 'label' => 'Application name', 'default' => 'Dice Rewards', 'rules' => 'required|string|max:60'],
            'app.tagline' => ['type' => 'string', 'label' => 'Tagline', 'default' => 'Roll doubles. Earn USDT rewards.', 'rules' => 'nullable|string|max:120'],
            'app.support_url' => ['type' => 'string', 'label' => 'Support link (URL or t.me link)', 'default' => '', 'rules' => 'nullable|url|max:255'],
            'app.timezone' => ['type' => 'string', 'label' => 'Business time zone (daily limits reset at midnight here)', 'default' => 'UTC', 'rules' => 'required|timezone'],
            'app.date_format' => ['type' => 'string', 'label' => 'Date format (PHP)', 'default' => 'Y-m-d H:i', 'rules' => 'required|string|max:32'],
            'app.maintenance' => ['type' => 'bool', 'label' => 'Maintenance mode (Mini App unavailable for players)', 'default' => false],
            'app.maintenance_message' => ['type' => 'text', 'label' => 'Maintenance message', 'default' => 'We are performing maintenance. Please check back soon.', 'rules' => 'nullable|string|max:500'],
        ],
    ],

    'branding' => [
        'label' => 'Branding & navigation',
        'fields' => [
            'brand.logo_url' => ['type' => 'string', 'label' => 'Logo URL (leave empty for the default dice icon)', 'default' => '', 'rules' => 'nullable|url|max:512'],
            'brand.primary_color' => ['type' => 'color', 'label' => 'Primary color', 'default' => '#5b5bd6', 'rules' => 'required|regex:/^#[0-9a-fA-F]{6}$/'],
            'brand.accent_color' => ['type' => 'color', 'label' => 'Accent color', 'default' => '#16b981', 'rules' => 'required|regex:/^#[0-9a-fA-F]{6}$/'],
            'brand.background_color' => ['type' => 'color', 'label' => 'Background color', 'default' => '#0e1116', 'rules' => 'required|regex:/^#[0-9a-fA-F]{6}$/'],
            'brand.surface_color' => ['type' => 'color', 'label' => 'Card color', 'default' => '#171b22', 'rules' => 'required|regex:/^#[0-9a-fA-F]{6}$/'],
            'brand.font_family' => ['type' => 'select', 'label' => 'Font', 'default' => 'system', 'options' => ['system' => 'System UI', 'rounded' => 'Rounded (ui-rounded)', 'serif' => 'Serif', 'mono' => 'Monospace']],
            'brand.use_telegram_theme' => ['type' => 'bool', 'label' => 'Follow the user\'s Telegram light/dark theme', 'default' => false],
            'nav.home' => ['type' => 'string', 'label' => 'Navigation label: Home', 'default' => 'Home', 'rules' => 'required|string|max:16'],
            'nav.games' => ['type' => 'string', 'label' => 'Navigation label: Games', 'default' => 'Games', 'rules' => 'required|string|max:16'],
            'nav.missions' => ['type' => 'string', 'label' => 'Navigation label: Missions', 'default' => 'Missions', 'rules' => 'required|string|max:16'],
            'nav.withdraw' => ['type' => 'string', 'label' => 'Navigation label: Withdraw', 'default' => 'Withdraw', 'rules' => 'required|string|max:16'],
        ],
    ],

    'home' => [
        'label' => 'Home page',
        'fields' => [
            'home.headline' => ['type' => 'string', 'label' => 'Headline', 'default' => 'Welcome back', 'rules' => 'nullable|string|max:80'],
            'home.announcement' => ['type' => 'text', 'label' => 'Announcement banner (plain text, empty to hide)', 'default' => '', 'rules' => 'nullable|string|max:600'],
            'home.recent_transactions' => ['type' => 'int', 'label' => 'Recent transactions shown', 'default' => 8, 'rules' => 'required|integer|min:0|max:30'],
        ],
    ],

    'game' => [
        'label' => 'Single-player game',
        'sensitive' => true,
        'fields' => [
            'game.maintenance' => ['type' => 'bool', 'label' => 'Game maintenance (all games paused)', 'default' => false],
            'game.single.enabled' => ['type' => 'bool', 'label' => 'Single-player enabled', 'default' => true],
            'game.single.reward' => ['type' => 'decimal', 'label' => 'Reward for doubles (USDT)', 'default' => '0.05', 'rules' => 'required|numeric|min:0|max:1000'],
            'game.single.cooldown_seconds' => ['type' => 'int', 'label' => 'Cooldown between rounds (seconds)', 'default' => 10, 'rules' => 'required|integer|min:0|max:86400'],
            'game.single.daily_limit' => ['type' => 'int', 'label' => 'Rounds per user per day (0 = unlimited)', 'default' => 50, 'rules' => 'required|integer|min:0|max:100000'],
            'game.single.min_account_age_minutes' => ['type' => 'int', 'label' => 'Minimum account age to play (minutes)', 'default' => 0, 'rules' => 'required|integer|min:0|max:525600'],
            'game.single.rules_text' => ['type' => 'text', 'label' => 'Rules shown to players', 'default' => "Press Play to roll two dice on our server.\nIf both dice show the same number (doubles) you win the reward.\nAny other result is a loss and pays nothing.\nThe odds of doubles are 1 in 6.", 'rules' => 'nullable|string|max:2000'],
        ],
    ],

    'multiplayer' => [
        'label' => 'Two-player game',
        'sensitive' => true,
        'fields' => [
            'game.multi.enabled' => ['type' => 'bool', 'label' => 'Two-player matches enabled', 'default' => true],
            'game.multi.dice_count' => ['type' => 'int', 'label' => 'Dice per roll', 'default' => 2, 'rules' => 'required|integer|min:1|max:5'],
            'game.multi.rounds' => ['type' => 'int', 'label' => 'Rounds per match', 'default' => 3, 'rules' => 'required|integer|min:1|max:9'],
            'game.multi.tie_rule' => ['type' => 'select', 'label' => 'When the match is tied', 'default' => 'extra_round', 'options' => ['extra_round' => 'Play extra rounds (sudden death)', 'draw' => 'Draw – nobody is rewarded', 'split' => 'Draw – reward is split']],
            'game.multi.max_extra_rounds' => ['type' => 'int', 'label' => 'Maximum extra rounds before a draw', 'default' => 3, 'rules' => 'required|integer|min:0|max:10'],
            'game.multi.reward_mode' => ['type' => 'select', 'label' => 'Reward mode', 'default' => 'fixed', 'options' => ['none' => 'Free to play, no reward', 'fixed' => 'Fixed reward for the winner (platform sponsored)', 'pooled' => 'Sponsored pool split by rounds won']],
            'game.multi.reward' => ['type' => 'decimal', 'label' => 'Winner reward (USDT, fixed mode)', 'default' => '0.10', 'rules' => 'required|numeric|min:0|max:1000'],
            'game.multi.pool' => ['type' => 'decimal', 'label' => 'Sponsored pool per match (USDT, pooled mode)', 'default' => '0.10', 'rules' => 'required|numeric|min:0|max:1000'],
            'game.multi.expire_minutes' => ['type' => 'int', 'label' => 'Match expires after inactivity (minutes)', 'default' => 30, 'rules' => 'required|integer|min:1|max:10080'],
            'game.multi.cooldown_seconds' => ['type' => 'int', 'label' => 'Cooldown between creating matches (seconds)', 'default' => 30, 'rules' => 'required|integer|min:0|max:86400'],
            'game.multi.daily_limit' => ['type' => 'int', 'label' => 'Matches per user per day (0 = unlimited)', 'default' => 20, 'rules' => 'required|integer|min:0|max:10000'],
            'game.multi.max_open' => ['type' => 'int', 'label' => 'Open matches per user at a time', 'default' => 2, 'rules' => 'required|integer|min:1|max:20'],
            'game.multi.rules_text' => ['type' => 'text', 'label' => 'Rules shown to players', 'default' => "Create a match or join one. Each round both players roll on our server.\nThe higher total wins the round. Win the most rounds to win the match.\nMatches are free to play – no deposits or stakes.", 'rules' => 'nullable|string|max:2000'],
        ],
    ],

    'rewards' => [
        'label' => 'Reward budget controls',
        'sensitive' => true,
        'fields' => [
            'rewards.platform_daily_cap' => ['type' => 'decimal', 'label' => 'Maximum rewards issued per day, all users (0 = no cap)', 'default' => '100', 'rules' => 'required|numeric|min:0'],
            'rewards.user_daily_cap' => ['type' => 'decimal', 'label' => 'Maximum rewards per user per day (0 = no cap)', 'default' => '5', 'rules' => 'required|numeric|min:0'],
            'rewards.low_budget_alert' => ['type' => 'decimal', 'label' => 'Alert when the budget falls below (USDT)', 'default' => '20', 'rules' => 'required|numeric|min:0'],
            'admin.max_adjustment' => ['type' => 'decimal', 'label' => 'Maximum manual balance adjustment per action (USDT)', 'default' => '100', 'rules' => 'required|numeric|min:0'],
        ],
    ],

    'withdrawals' => [
        'label' => 'Withdrawals',
        'sensitive' => true,
        'fields' => [
            'withdraw.enabled' => ['type' => 'bool', 'label' => 'Withdrawals enabled', 'default' => true],
            'withdraw.min' => ['type' => 'decimal', 'label' => 'Minimum withdrawal (USDT)', 'default' => '5', 'rules' => 'required|numeric|min:0'],
            'withdraw.max' => ['type' => 'decimal', 'label' => 'Maximum withdrawal (USDT)', 'default' => '500', 'rules' => 'required|numeric|min:0'],
            'withdraw.daily_max_amount' => ['type' => 'decimal', 'label' => 'Maximum total requested per user per day (0 = no limit)', 'default' => '500', 'rules' => 'required|numeric|min:0'],
            'withdraw.daily_max_count' => ['type' => 'int', 'label' => 'Maximum requests per user per day (0 = no limit)', 'default' => 2, 'rules' => 'required|integer|min:0'],
            'withdraw.max_pending' => ['type' => 'int', 'label' => 'Open (unfinished) requests allowed per user', 'default' => 1, 'rules' => 'required|integer|min:1|max:10'],
            'withdraw.min_account_age_hours' => ['type' => 'int', 'label' => 'Minimum account age (hours)', 'default' => 24, 'rules' => 'required|integer|min:0'],
            'withdraw.min_games' => ['type' => 'int', 'label' => 'Minimum games played before withdrawing', 'default' => 5, 'rules' => 'required|integer|min:0'],
            'withdraw.networks' => [
                'type' => 'json',
                'label' => 'Supported networks (JSON list)',
                'help' => 'Fields: code, name, enabled, fee_fixed, fee_percent, min, max, address_pattern (regex), explorer_url (use {hash}).',
                'default' => [
                    [
                        'code' => 'TRC20', 'name' => 'TRON (TRC20)', 'enabled' => true,
                        'fee_fixed' => '1', 'fee_percent' => '0', 'min' => '5', 'max' => '500',
                        'address_pattern' => '^T[1-9A-HJ-NP-Za-km-z]{33}$',
                        'explorer_url' => 'https://tronscan.org/#/transaction/{hash}',
                    ],
                    [
                        'code' => 'BEP20', 'name' => 'BNB Smart Chain (BEP20)', 'enabled' => false,
                        'fee_fixed' => '0.5', 'fee_percent' => '0', 'min' => '5', 'max' => '500',
                        'address_pattern' => '^0x[a-fA-F0-9]{40}$',
                        'explorer_url' => 'https://bscscan.com/tx/{hash}',
                    ],
                ],
            ],
            'withdraw.warning_text' => ['type' => 'text', 'label' => 'Warning shown on the withdrawal form', 'default' => 'Double-check the network and address. Funds sent to an incompatible network or a wrong address are lost permanently and cannot be recovered.', 'rules' => 'nullable|string|max:600'],
        ],
    ],

    'referral' => [
        'label' => 'Referral program',
        'sensitive' => true,
        'fields' => [
            'referral.enabled' => ['type' => 'bool', 'label' => 'Referral rewards enabled', 'default' => true],
            'referral.max_depth' => ['type' => 'int', 'label' => 'Maximum referral depth (levels)', 'default' => 3, 'rules' => 'required|integer|min:1|max:10'],
            'referral.levels' => [
                'type' => 'json',
                'label' => 'Rewards per level (JSON list, first item = level 1)',
                'help' => '"fixed": paid once when the referred user qualifies. "percent": share of the referred user\'s game and mission rewards (platform funded, never deducted from the user).',
                'default' => [
                    ['fixed' => '0.10', 'percent' => '5'],
                    ['fixed' => '0.03', 'percent' => '2'],
                    ['fixed' => '0.01', 'percent' => '1'],
                ],
            ],
            'referral.qualify_min_games' => ['type' => 'int', 'label' => 'Games the referred user must play to qualify', 'default' => 10, 'rules' => 'required|integer|min:0'],
            'referral.qualify_min_age_hours' => ['type' => 'int', 'label' => 'Account age required to qualify (hours)', 'default' => 24, 'rules' => 'required|integer|min:0'],
            'referral.campaign_starts_at' => ['type' => 'datetime', 'label' => 'Campaign start (empty = always)', 'default' => null, 'rules' => 'nullable|date'],
            'referral.campaign_ends_at' => ['type' => 'datetime', 'label' => 'Campaign end (empty = never)', 'default' => null, 'rules' => 'nullable|date'],
            'referral.fraud_max_per_hour' => ['type' => 'int', 'label' => 'Flag inviters with more sign-ups per hour than', 'default' => 15, 'rules' => 'required|integer|min:1'],
            'referral.fraud_shared_ip_threshold' => ['type' => 'int', 'label' => 'Flag when this many referred accounts share a network (24h)', 'default' => 4, 'rules' => 'required|integer|min:2'],
            'referral.disclosure' => ['type' => 'text', 'label' => 'Referral terms shown to users', 'default' => 'You earn a one-time bonus when a friend you invited qualifies (plays enough games and their account is old enough), plus a small platform-funded share of their rewards. Fake or duplicate accounts are not eligible and rewards can be reversed.', 'rules' => 'nullable|string|max:1000'],
        ],
    ],

    'missions' => [
        'label' => 'Missions',
        'fields' => [
            'missions.enabled' => ['type' => 'bool', 'label' => 'Missions enabled', 'default' => true],
            'missions.visit_min_seconds' => ['type' => 'int', 'label' => 'Website missions: seconds between opening and claiming', 'default' => 15, 'rules' => 'required|integer|min:0|max:3600'],
        ],
    ],

    'notifications' => [
        'label' => 'Notification templates',
        'help' => 'Telegram HTML is allowed in templates. Placeholders in {braces} are filled in and escaped automatically.',
        'fields' => [
            'tpl.welcome' => ['type' => 'text', 'label' => 'Welcome (/start) – {name} {app}', 'default' => $template("🎲 Welcome to <b>{app}</b>, {name}!\n\nRoll two dice – doubles win USDT rewards. Complete missions and invite friends to earn more.\n\nTap <b>Open app</b> to start playing.")],
            'tpl.reward' => ['type' => 'text', 'label' => 'Referral reward credited – {amount} {friend} {level}', 'default' => $template('🎉 You earned <b>{amount} USDT</b> from your level {level} referral {friend}.')],
            'tpl.mission_approved' => ['type' => 'text', 'label' => 'Mission approved – {mission} {amount}', 'default' => $template('✅ Mission <b>{mission}</b> approved. <b>{amount} USDT</b> was added to your balance.')],
            'tpl.mission_rejected' => ['type' => 'text', 'label' => 'Mission rejected – {mission} {reason}', 'default' => $template("❌ Mission <b>{mission}</b> was not approved.\nReason: {reason}")],
            'tpl.match_joined' => ['type' => 'text', 'label' => 'Opponent joined – {opponent}', 'default' => $template('⚔️ {opponent} joined your dice match. Open the app to roll!')],
            'tpl.match_invite' => ['type' => 'text', 'label' => 'Match invitation – {inviter}', 'default' => $template('🎲 {inviter} invited you to a dice match. Tap below to join.')],
            'tpl.match_finished' => ['type' => 'text', 'label' => 'Match finished – {result} {score}', 'default' => $template('🏁 Your dice match finished: <b>{result}</b> ({score}).')],
            'tpl.withdraw_submitted' => ['type' => 'text', 'label' => 'Withdrawal received – {reference} {amount} {network}', 'default' => $template("📝 Withdrawal request <b>{reference}</b> received.\nAmount: {amount} USDT ({network})\nStatus: pending review.")],
            'tpl.withdraw_approved' => ['type' => 'text', 'label' => 'Withdrawal approved – {reference} {amount}', 'default' => $template('👍 Withdrawal <b>{reference}</b> ({amount} USDT) was approved and is queued for payment.')],
            'tpl.withdraw_rejected' => ['type' => 'text', 'label' => 'Withdrawal rejected – {reference} {amount} {reason}', 'default' => $template("⚠️ Withdrawal <b>{reference}</b> was rejected. {amount} USDT was returned to your balance.\nReason: {reason}")],
            'tpl.withdraw_paid' => ['type' => 'text', 'label' => 'Withdrawal paid – {reference} {net} {network} {tx}', 'default' => $template("💸 Withdrawal <b>{reference}</b> has been paid: {net} USDT via {network}.\nTransaction: {tx}")],
            'tpl.withdraw_review' => ['type' => 'text', 'label' => 'Review channel – new request – {reference} {amount} {network} {user} {flags}', 'default' => $template("🆕 Withdrawal <b>{reference}</b>\nUser: {user}\nAmount: {amount} USDT ({network})\nFlags: {flags}")],
            'tpl.withdraw_public' => ['type' => 'text', 'label' => 'Public channel – payment completed – {name} {amount} {network} {wallet} {tx}', 'default' => $template("✅ <b>USDT Withdrawal Completed</b>\n\nName: {name}\nAmount: {amount} USDT\nNetwork: {network}\nWallet: <code>{wallet}</code>\nTransaction: {tx}")],
        ],
    ],

    'telegram' => [
        'label' => 'Telegram channels',
        'sensitive' => true,
        'help' => 'The bot token and webhook secret are configured in .env and are never stored here.',
        'fields' => [
            'telegram.public_channel_id' => ['type' => 'string', 'label' => 'Public withdrawal confirmation channel (@name or -100… id)', 'default' => '', 'rules' => 'nullable|string|max:64'],
            'telegram.review_channel_id' => ['type' => 'string', 'label' => 'Private withdrawal review channel (@name or -100… id)', 'default' => '', 'rules' => 'nullable|string|max:64'],
            'telegram.public_show_network' => ['type' => 'bool', 'label' => 'Show the network in public confirmations', 'default' => true],
            'telegram.mask_head' => ['type' => 'int', 'label' => 'Wallet characters shown at the start', 'default' => 4, 'rules' => 'required|integer|min:0|max:10'],
            'telegram.mask_tail' => ['type' => 'int', 'label' => 'Wallet characters shown at the end', 'default' => 4, 'rules' => 'required|integer|min:0|max:10'],
            'telegram.open_button' => ['type' => 'string', 'label' => 'Open-app button label', 'default' => '🎲 Open app', 'rules' => 'required|string|max:32'],
        ],
    ],

    'security' => [
        'label' => 'Security & rate limits',
        'sensitive' => true,
        'fields' => [
            'auth.init_data_max_age' => ['type' => 'int', 'label' => 'Maximum age of Telegram login data (seconds)', 'default' => 86400, 'rules' => 'required|integer|min:60|max:604800'],
            'rate.api_per_minute' => ['type' => 'int', 'label' => 'API requests per user per minute', 'default' => 120, 'rules' => 'required|integer|min:10'],
            'rate.game_per_minute' => ['type' => 'int', 'label' => 'Game actions per user per minute', 'default' => 30, 'rules' => 'required|integer|min:1'],
            'rate.mission_per_minute' => ['type' => 'int', 'label' => 'Mission actions per user per minute', 'default' => 10, 'rules' => 'required|integer|min:1'],
            'rate.withdraw_per_hour' => ['type' => 'int', 'label' => 'Withdrawal submissions per user per hour', 'default' => 5, 'rules' => 'required|integer|min:1'],
            'rate.auth_per_minute' => ['type' => 'int', 'label' => 'Login attempts per IP per minute', 'default' => 20, 'rules' => 'required|integer|min:1'],
        ],
    ],

    'legal' => [
        'label' => 'Legal',
        'fields' => [
            'legal.terms' => ['type' => 'text', 'label' => 'Terms of use', 'default' => "Rewards are promotional credits funded by the platform. Games are free to play; no deposit or stake is ever required. Rewards may be limited, changed or reversed in case of abuse. Withdrawals are reviewed manually and paid at the platform's discretion within the published limits.", 'rules' => 'nullable|string|max:20000'],
            'legal.privacy' => ['type' => 'text', 'label' => 'Privacy policy', 'default' => 'We store your Telegram ID, name, username and activity in this app to operate the service, prevent abuse and process withdrawals. Wallet details are used only to pay withdrawals. Public payment confirmations show only a masked wallet address and a short name.', 'rules' => 'nullable|string|max:20000'],
            'legal.disclaimer' => ['type' => 'text', 'label' => 'Short disclaimer (footer)', 'default' => 'Free-to-play promotional game. No purchase or deposit necessary.', 'rules' => 'nullable|string|max:300'],
        ],
    ],
];
