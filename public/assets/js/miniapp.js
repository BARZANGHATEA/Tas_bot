/*
 * Mini App client. Plain JavaScript, no build step.
 *
 * Trust model: this file only renders what the server returns. It never
 * decides dice values, outcomes, balances, rewards or withdrawal approval;
 * every action is an authenticated API call and the server's answer is shown.
 */
(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('app-config').textContent);
    var tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;

    var state = {
        token: null,
        tab: 'home',
        sub: null,          // 'referrals' | 'match' | 'transactions'
        gameMode: 'single',
        home: null,
        games: null,
        matches: null,
        missions: null,
        withdrawals: null,
        referrals: null,
        match: null,
        matchCode: null,
        lastRound: null,
        rolling: false,
        cooldownUntil: 0,
        pollTimer: null,
        cooldownTimer: null,
        proofOpen: {},
        playKey: null,
        errors: {},         // per-screen load errors, shown with a retry button
    };

    var view = document.getElementById('view');

    // ------------------------------------------------------------------ utils

    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function newKey() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        var bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    function icon(name, cls) {
        return '<svg class="icon' + (cls ? ' ' + cls : '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }

    function busy(el, on) {
        if (!el) return;
        el.disabled = on;
        el.classList.toggle('is-loading', on);
        el.setAttribute('aria-busy', String(on));
    }

    function fmtDate(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' ' +
            d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    function haptic(kind) {
        try {
            if (!tg || !tg.HapticFeedback) return;
            if (kind === 'success' || kind === 'error' || kind === 'warning') tg.HapticFeedback.notificationOccurred(kind);
            else tg.HapticFeedback.impactOccurred(kind || 'light');
        } catch (e) { /* not supported */ }
    }

    var toastTimer = null;
    function toast(message, kind) {
        var el = document.getElementById('toast');
        var name = { success: 'check-circle', error: 'x-circle' }[kind] || 'info';
        el.innerHTML = icon(name) + '<span></span>';
        el.lastChild.textContent = message;
        el.className = 'toast' + (kind ? ' is-' + kind : '');
        el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        el.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.hidden = true; }, kind === 'error' ? 5000 : 3200);
    }

    function openLink(url) {
        if (!url) return;
        if (tg && /^https:\/\/t\.me\//.test(url) && tg.openTelegramLink) return tg.openTelegramLink(url);
        if (tg && tg.openLink) return tg.openLink(url);
        window.open(url, '_blank', 'noopener');
    }

    function copy(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { toast('Copied to clipboard', 'success'); }, function () { toast(text); });
        } else {
            toast(text);
        }
    }

    // Decimal helpers in integer micro-units (6 decimals) – display only.
    function toMicro(str) {
        var m = /^(\d+)(?:\.(\d{0,6}))?/.exec(String(str || '').trim());
        if (!m) return null;
        return BigInt(m[1]) * 1000000n + BigInt(((m[2] || '') + '000000').slice(0, 6));
    }
    function fromMicro(v) {
        var neg = v < 0n; if (neg) v = -v;
        var whole = v / 1000000n, frac = (v % 1000000n).toString().padStart(6, '0').slice(0, 2);
        return (neg ? '-' : '') + whole.toString() + '.' + frac;
    }

    // "0.100000" -> "0.10", "0.005000" -> "0.005": two decimals minimum, no trailing zeros beyond that.
    function trimUsdt(v) {
        var m = /^(-?\d+)\.(\d+)$/.exec(String(v));
        if (!m) return String(v === null || v === undefined ? '' : v);
        var frac = m[2].replace(/0+$/, '');
        return m[1] + '.' + (frac.length < 2 ? (frac + '00').slice(0, 2) : frac);
    }

    // ------------------------------------------------------------------ API

    function ApiError(message, code, status, data) {
        this.message = message; this.code = code; this.status = status; this.data = data;
    }

    function api(method, path, body, opts) {
        opts = opts || {};
        var headers = { Accept: 'application/json' };
        if (state.token) headers.Authorization = 'Bearer ' + state.token;
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        if (opts.key) headers['Idempotency-Key'] = opts.key;

        return fetch(cfg.api + path, {
            method: method,
            headers: headers,
            body: body !== undefined ? JSON.stringify(body) : undefined,
            credentials: 'omit',
        }).catch(function () {
            throw new ApiError('Network error. Please check your connection.', 'network', 0);
        }).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (data) {
                if (res.status === 401 && !opts.noRetry && path !== '/auth') {
                    return authenticate().then(function () {
                        return api(method, path, body, Object.assign({}, opts, { noRetry: true }));
                    });
                }
                if (!res.ok) {
                    var msg = (data && data.message) || 'Something went wrong. Please try again.';
                    if (data && data.errors) {
                        var first = Object.keys(data.errors)[0];
                        if (first) msg = data.errors[first][0];
                    }
                    if (res.status === 429 && !(data && data.code)) msg = 'Slow down a little and try again.';
                    throw new ApiError(msg, (data && data.code) || 'error', res.status, data);
                }
                return data;
            });
        });
    }

    function authenticate() {
        var body;
        if (tg && tg.initData) {
            body = { init_data: tg.initData };
        } else if (cfg.dev_auth) {
            body = { dev_user: devUser(), start_param: new URLSearchParams(location.search).get('start') };
        } else {
            return Promise.reject(new ApiError('Please open this app from Telegram.', 'no_telegram', 401));
        }

        return api('POST', '/auth', body, { noRetry: true }).then(function (res) {
            state.token = res.token;
            state.startParam = res.start_param;
            state.newUser = res.new_user;
        });
    }

    function devUser() {
        // Local development only (enabled server-side with APP_ENV=local + TELEGRAM_DEV_AUTH=true).
        var id = Number(localStorage.getItem('dev_tg_id'));
        if (!id) { id = Math.floor(100000 + Math.random() * 900000); localStorage.setItem('dev_tg_id', String(id)); }
        return { id: id, first_name: 'Dev', username: 'dev_' + id };
    }

    function showError(err) {
        haptic('error');
        toast(err && err.message ? err.message : 'Something went wrong.', 'error');
    }

    // ------------------------------------------------------------------ dice

    function dieHtml(value, extra) {
        var pips = '';
        for (var i = 1; i <= 9; i++) pips += '<span class="pip p' + i + '"></span>';
        var face = value >= 1 && value <= 6 ? ' f' + value : ' die-blank';
        return '<div class="die' + face + (extra ? ' ' + extra : '') + '" role="img" aria-label="' + (value ? 'Die showing ' + value : 'Die') + '">' + pips + '</div>';
    }

    function diceInline(values) {
        return '<span class="dice-inline">' + values.map(function (v) { return dieHtml(v, 'die-sm'); }).join('') + '</span>';
    }

    // ------------------------------------------------------------------ navigation

    function setTab(tab) {
        stopPolling();
        state.tab = tab;
        state.sub = null;
        state.errors[tab] = null;
        updateChrome();
        render();
        load(tab);
        window.scrollTo(0, 0);
    }

    function openSub(sub) {
        state.sub = sub;
        updateChrome();
        render();
        window.scrollTo(0, 0);
    }

    function closeSub() {
        stopPolling();
        state.sub = null;
        state.match = null;
        state.matchCode = null;
        updateChrome();
        render();
        load(state.tab);
    }

    function updateChrome() {
        document.querySelectorAll('.tab').forEach(function (b) {
            if (b.dataset.tab === state.tab) b.setAttribute('aria-current', 'page');
            else b.removeAttribute('aria-current');
        });
        if (tg && tg.BackButton) {
            if (state.sub) tg.BackButton.show(); else tg.BackButton.hide();
        }
    }

    function load(what) {
        var loaders = {
            home: function () { return api('GET', '/home').then(function (d) { state.home = d; updateTopbar(); }); },
            games: function () {
                return Promise.all([api('GET', '/games'), api('GET', '/matches')]).then(function (r) {
                    state.games = r[0]; state.matches = r[1];
                    state.cooldownUntil = Date.now() + (r[0].single.cooldown_remaining || 0) * 1000;
                    startCooldownTicker();
                });
            },
            missions: function () { return api('GET', '/missions').then(function (d) { state.missions = d; }); },
            withdraw: function () { return api('GET', '/withdrawals').then(function (d) { state.withdrawals = d; }); },
            referrals: function () { return api('GET', '/referrals').then(function (d) { state.referrals = d; }); },
        };
        if (!loaders[what]) return Promise.resolve();
        state.errors[what] = null;
        return loaders[what]().then(render).catch(function (err) {
            if (err.code === 'maintenance') return renderMaintenance(err.message);
            state.errors[what] = err.message || 'This page could not be loaded.';
            render(); // screens without data switch from the skeleton to an error state
            showError(err);
        });
    }

    function updateTopbar() {
        if (!state.home) return;
        var u = state.home.user;
        document.getElementById('topbar-name').textContent = u.first_name;
        document.getElementById('topbar-balance').textContent = state.home.stats.available;
        document.getElementById('topbar-avatar').innerHTML = avatarInner(u);
    }

    function avatarInner(u) {
        if (u.photo_url) return '<img src="' + esc(u.photo_url) + '" alt="" referrerpolicy="no-referrer">';
        return esc(u.initials || '?');
    }

    // ------------------------------------------------------------------ render

    function render() {
        var html;
        if (state.sub === 'referrals') html = renderReferrals();
        else if (state.sub === 'match') html = renderMatch();
        else if (state.sub === 'transactions') html = renderTransactions();
        else html = ({ home: renderHome, games: renderGames, missions: renderMissions, withdraw: renderWithdraw })[state.tab]();
        view.innerHTML = html;
        afterRender();
    }

    // Loading placeholder shaped like the content it stands in for.
    function skeleton(n) {
        var s = '<p class="sr-only" role="status">Loading…</p>';
        for (var i = 0; i < (n || 3); i++) {
            s += '<div class="skeleton" aria-hidden="true"><span class="sk sk-sm"></span><span class="sk ' + (i === 0 ? 'sk-lg' : 'sk-md') + '"></span><span class="sk"></span></div>';
        }
        return s;
    }

    function emptyState(iconName, title, text, actionHtml) {
        return '<div class="empty"><div class="empty-icon">' + icon(iconName) + '</div>' +
            '<p class="empty-title">' + esc(title) + '</p>' + (text ? '<p>' + esc(text) + '</p>' : '') + (actionHtml || '') + '</div>';
    }

    function errorState(what) {
        return '<div class="card state-error" role="alert">' + emptyState('alert', 'Could not load this page', state.errors[what],
            '<button type="button" class="btn btn-ghost btn-sm" data-action="retry" data-what="' + esc(what) + '">' + icon('refresh', 'icon-sm') + 'Try again</button>') + '</div>';
    }

    function loadingOr(what, n) {
        return state.errors[what] ? errorState(what) : skeleton(n);
    }

    function banner(text, kind) {
        var name = kind === 'danger' ? 'alert' : (kind === 'warn' ? 'alert' : 'info');
        return '<div class="banner' + (kind ? ' banner-' + kind : '') + '" role="' + (kind ? 'alert' : 'note') + '">' + icon(name) + '<div>' + esc(text) + '</div></div>';
    }

    function footer() {
        return '<p class="footer-note">' + esc(cfg.disclaimer) + '<br>' +
            '<a href="#" data-action="link" data-url="' + esc(cfg.terms_url) + '">Terms</a> · ' +
            '<a href="#" data-action="link" data-url="' + esc(cfg.privacy_url) + '">Privacy</a>' +
            (cfg.support_url ? ' · <a href="#" data-action="link" data-url="' + esc(cfg.support_url) + '">Support</a>' : '') +
            '</p>';
    }

    function renderMaintenance(message) {
        view.innerHTML = '<div class="card mt-3">' + emptyState('wrench', 'Down for maintenance', message,
            '<button type="button" class="btn btn-ghost btn-sm" data-action="retry" data-what="' + esc(state.tab) + '">' + icon('refresh', 'icon-sm') + 'Try again</button>') + '</div>';
    }

    // ---- Home

    function renderHome() {
        var d = state.home;
        if (!d) return loadingOr('home', 3);
        var u = d.user, s = d.stats;

        var html = '';
        if (d.home.announcement) html += banner(d.home.announcement);
        if (d.restricted) html += banner('Your account is restricted: playing, claiming and withdrawing are paused. Please contact support.', 'warn');

        html += '<section class="card card-hero" aria-label="Your balance">' +
            '<div class="profile"><div class="avatar">' + avatarInner(u) + '</div><div style="min-width:0">' +
            '<p class="profile-greeting">' + esc(d.home.headline || 'Welcome back') + '</p>' +
            '<h1 class="profile-name">' + esc(u.name) + '</h1>' +
            '<div class="profile-meta">' + (u.username ? '<span>@' + esc(u.username) + '</span>' : '') +
            '<button class="id-pill" type="button" data-action="copy" data-text="' + esc(u.id) + '" aria-label="Copy your ID ' + esc(u.id) + '">ID ' + esc(u.id) + icon('copy') + '</button></div>' +
            '</div></div>' +
            '<div class="balance"><div class="balance-label">Available balance</div>' +
            '<div class="balance-value">' + esc(s.available) + ' <small>USDT</small></div>' +
            '<div class="balance-row">' +
            '<div><span>Withdrawing</span><strong>' + esc(s.reserved) + '</strong></div>' +
            '<div><span>Pending</span><strong>' + esc(s.pending_rewards) + '</strong></div>' +
            '<div><span>Total earned</span><strong>' + esc(s.total_earned) + '</strong></div>' +
            '</div></div></section>';

        html += '<div class="quick">' +
            '<button type="button" data-action="go" data-tab="games"><span class="q-icon">' + icon('dice') + '</span>Play</button>' +
            '<button type="button" data-action="go" data-tab="missions"><span class="q-icon">' + icon('target') + '</span>Missions</button>' +
            '<button type="button" data-action="sub" data-sub="referrals"><span class="q-icon">' + icon('users') + '</span>Invite</button>' +
            '<button type="button" data-action="go" data-tab="withdraw"><span class="q-icon">' + icon('wallet') + '</span>Withdraw</button>' +
            '</div>';

        html += '<div class="stats">' +
            '<div class="stat"><strong>' + esc(s.games_played) + '</strong><span>Games played</span></div>' +
            '<div class="stat"><strong>' + esc(s.games_won) + '</strong><span>Wins</span></div>' +
            '<div class="stat"><strong>' + esc(s.referrals) + '</strong><span>Referrals</span></div>' +
            '</div>';

        html += '<h2 class="section-title">Recent activity <button type="button" class="link-btn" data-action="sub" data-sub="transactions">See all</button></h2>';
        html += '<div class="card card-flat">' + txList(d.transactions) + '</div>';

        return html + footer();
    }

    function txList(items) {
        if (!items || !items.length) return emptyState('activity', 'No activity yet', 'Rewards, withdrawals and adjustments will appear here.',
            '<button type="button" class="btn btn-primary btn-sm" data-action="go" data-tab="games">' + icon('dice', 'icon-sm') + 'Play a round</button>');
        var icons = { game_reward: 'dice', match_reward: 'swords', referral_reward: 'users', mission_reward: 'target', withdrawal_reserve: 'clock', withdrawal_release: 'undo', withdrawal_payout: 'arrow-out', reward_reversal: 'x-circle', admin_credit: 'plus', admin_debit: 'minus' };
        return '<div class="list">' + items.map(function (t) {
            var dir = t.direction === 'in' ? 'in' : 'out';
            var sub = [t.description, fmtDate(t.created_at)].filter(Boolean).join(' · ');
            return '<div class="row"><div class="row-icon is-' + dir + '">' + icon(icons[t.type] || 'activity') + '</div>' +
                '<div class="row-main"><div class="row-title">' + esc(t.label) + '</div><div class="row-sub">' + esc(sub) + '</div></div>' +
                '<div class="amount amount-' + dir + '">' + esc(t.amount) + '</div></div>';
        }).join('') + '</div>';
    }

    function renderTransactions() {
        var html = subHeader('All transactions');
        if (!state.allTx) {
            if (state.errors.transactions) return html + errorState('transactions');
            api('GET', '/transactions').then(function (d) { state.allTx = d; render(); }).catch(function (err) {
                state.errors.transactions = err.message; render(); showError(err);
            });
            return html + skeleton(2);
        }
        html += '<div class="card card-flat">' + txList(state.allTx.data) + '</div>';
        if (state.allTx.next_page) html += '<button class="btn btn-ghost btn-block" type="button" data-action="more-tx">Load more</button>';
        return html;
    }

    function subHeader(title) {
        return '<div class="subheader"><button type="button" class="back-btn" data-action="back" aria-label="Back">' + icon('chevron-left') + '</button><h2>' + esc(title) + '</h2></div>';
    }

    // ---- Games

    function renderGames() {
        var g = state.games;
        var html = '<h1 class="screen-title">' + esc(cfg.nav.games) + '</h1>' +
            '<div class="segmented" role="tablist" aria-label="Game mode">' +
            '<button type="button" role="tab" aria-selected="' + (state.gameMode === 'single') + '" data-action="mode" data-mode="single">' + icon('dice', 'icon-sm') + 'Solo</button>' +
            '<button type="button" role="tab" aria-selected="' + (state.gameMode === 'duel') + '" data-action="mode" data-mode="duel">' + icon('swords', 'icon-sm') + 'Duel</button>' +
            '</div>';
        if (!g) return html + loadingOr('games', 2);
        return html + (state.gameMode === 'single' ? renderSingle(g) : renderDuel(g));
    }

    function renderSingle(g) {
        var s = g.single;
        var last = state.lastRound;
        var dice = last ? last.dice : [0, 0];
        var cls = last && last.is_win ? 'is-win is-landing' : (last ? 'is-landing' : '');
        if (state.rolling) cls = 'is-rolling';

        var result = '';
        if (state.rolling) result = '<p class="result-text idle" role="status">Rolling on the server…</p>';
        else if (last && last.is_win) result = '<p class="result-text win" role="status">Doubles! ' + (last.reward_status === 'credited' ? '+' + esc(last.reward) + ' USDT' : 'Daily reward limit reached') + '</p>';
        else if (last) result = '<p class="result-text loss" role="status">' + esc(last.dice[0]) + ' and ' + esc(last.dice[1]) + ' – no doubles this time</p>';
        else result = '<p class="result-text idle">Roll doubles to win ' + esc(s.reward_display) + ' USDT</p>';

        var disabled = !s.enabled || state.rolling;
        var html = '<section class="card">' +
            '<div class="dice-stage">' + dieHtml(dice[0], cls) + dieHtml(dice[1], cls) + '</div>' + result;

        if (!s.enabled) {
            html += banner(s.maintenance ? 'Games are under maintenance.' : 'This game is currently disabled.', 'warn') + '<div class="mt-3"></div>';
        }
        html += '<button type="button" id="play-btn" class="btn btn-primary btn-block btn-xl" data-action="play"' + (disabled ? ' disabled' : '') + '>' + (state.rolling ? 'Rolling…' : 'Play') + '</button>' +
            '<p class="server-note">' + icon('lock') + '<span>Dice are rolled with a secure random generator on our server. The animation only reveals the result.</span></p>' +
            '</section>';

        html += '<section class="card" aria-label="Rules"><dl class="kv">' +
            '<dt>Reward for doubles</dt><dd>' + esc(s.reward_display) + ' USDT</dd>' +
            '<dt>Chance to win</dt><dd>1 in 6</dd>' +
            (s.cooldown_seconds ? '<dt>Cooldown</dt><dd>' + esc(s.cooldown_seconds) + 's</dd>' : '') +
            (s.daily_limit ? '<dt>Played today</dt><dd>' + esc(s.played_today) + ' / ' + esc(s.daily_limit) + '</dd>' : '') +
            '</dl>' + (s.rules_text ? '<hr class="divider"><p class="rules">' + esc(s.rules_text) + '</p>' : '') + '</section>';

        html += '<h2 class="section-title">Your last rounds</h2><div class="card card-flat">';
        if (!g.recent_rounds.length) html += emptyState('dice', 'No rounds yet', 'Your results will show up here.');
        else html += '<div class="list">' + g.recent_rounds.map(function (r) {
            return '<div class="row">' + diceInline(r.dice) + '<div class="row-main"><div class="row-sub">' + esc(fmtDate(r.created_at)) + '</div></div>' +
                (r.is_win ? '<span class="badge badge-ok">' + (r.reward_status === 'credited' ? '+' + esc(r.reward) + ' USDT' : 'Win') + '</span>' : '<span class="badge">No doubles</span>') + '</div>';
        }).join('') + '</div>';
        return html + '</div>';
    }

    function renderDuel(g) {
        var m = g.multi;
        var html = '';
        if (!m.enabled) return html + banner('Two-player matches are currently disabled.', 'warn');

        var reward = m.reward_mode === 'fixed' ? 'Winner earns ' + trimUsdt(m.reward) + ' USDT' : (m.reward_mode === 'pooled' ? 'Pool of ' + trimUsdt(m.pool) + ' USDT split by rounds won' : 'Just for fun – no reward');
        html += '<section class="card"><h2 class="card-title">Challenge a player</h2>' +
            '<p class="card-sub">' + esc(m.rounds) + ' rounds · ' + esc(m.dice_count) + ' dice · ' + esc(reward) + ' · free to play</p>' +
            '<div class="btn-row"><button type="button" class="btn btn-primary" data-action="create-match" data-visibility="public">Public match</button>' +
            '<button type="button" class="btn btn-ghost" data-action="create-match" data-visibility="private">' + icon('lock', 'icon-sm') + 'Private link</button></div>' +
            '<form id="invite-form" class="mt-3" autocomplete="off" novalidate><label class="field-label" for="invite-input">Or invite by username</label>' +
            '<div class="input-group"><input class="input" id="invite-input" name="invite" placeholder="@username" maxlength="64" autocapitalize="off" spellcheck="false">' +
            '<button class="btn btn-ghost" type="submit">' + icon('send', 'icon-sm') + 'Invite</button></div></form>' +
            (m.rules_text ? '<hr class="divider"><p class="rules small">' + esc(m.rules_text) + '</p>' : '') + '</section>';

        var mine = (state.matches && state.matches.mine) || [];
        var active = mine.filter(function (x) { return ['waiting', 'ready', 'playing'].indexOf(x.status) !== -1; });
        var done = mine.filter(function (x) { return ['completed', 'cancelled'].indexOf(x.status) !== -1; }).slice(0, 10);

        html += '<h2 class="section-title">Your active matches</h2><div class="card card-flat">';
        html += active.length ? '<div class="list">' + active.map(matchRow).join('') + '</div>' : emptyState('swords', 'No active matches', 'Create a match or join an open one below.');
        html += '</div>';

        var open = (state.matches && state.matches.open) || [];
        html += '<h2 class="section-title">Open public matches <button type="button" class="link-btn" data-action="refresh-matches">Refresh</button></h2><div class="card card-flat">';
        html += open.length ? '<div class="list">' + open.map(function (o) {
            return '<div class="row"><div class="row-icon">' + icon('swords') + '</div><div class="row-main"><div class="row-title">' + esc(o.creator) + '</div>' +
                '<div class="row-sub">' + esc(o.rounds) + ' rounds · ' + esc(o.dice_count) + ' dice</div></div>' +
                '<button class="btn btn-sm btn-primary" type="button" data-action="join-match" data-uuid="' + esc(o.uuid) + '">Join</button></div>';
        }).join('') + '</div>' : emptyState('users', 'No open matches', 'Start a public match and other players can join it.');
        html += '</div>';

        if (done.length) {
            html += '<h2 class="section-title">History</h2><div class="card card-flat"><div class="list">' + done.map(matchRow).join('') + '</div></div>';
        }
        return html;
    }

    function matchRow(m) {
        var label = { waiting: 'Waiting for opponent', ready: 'Ready', playing: 'In progress', completed: 'Finished', cancelled: 'Cancelled' }[m.status] || m.status;
        var badge = m.status === 'completed'
            ? (m.result === 'won' ? '<span class="badge badge-ok">Won</span>' : (m.result === 'tie' ? '<span class="badge">Draw</span>' : '<span class="badge badge-danger">Lost</span>'))
            : (m.can_roll ? '<span class="badge badge-brand">Your turn</span>' : '<span class="badge">' + esc(label) + '</span>');
        return '<button type="button" class="row" data-action="open-match" data-uuid="' + esc(m.uuid) + '">' +
            '<span class="row-icon">' + icon(m.visibility === 'private' ? 'lock' : 'swords') + '</span>' +
            '<span class="row-main"><span class="row-title" style="display:block">vs ' + esc(m.opponent ? m.opponent.name : 'waiting…') + '</span>' +
            '<span class="row-sub num" style="display:block">' + esc(m.score.you) + '–' + esc(m.score.them) + ' · ' + esc(fmtDate(m.created_at)) + '</span></span>' + badge +
            icon('chevron-right', 'icon-sm row-chevron') + '</button>';
    }

    function renderMatch() {
        var m = state.match;
        var html = subHeader('Dice duel');
        if (!m) return html + skeleton(2);

        var you = m.you || { name: 'You', initials: '?' };
        var them = m.opponent;
        html += '<section class="card card-hero" aria-label="Score"><div class="versus">' +
            '<div><div class="avatar avatar-lg">' + esc(you.initials) + '</div><div class="versus-name">' + esc(m.is_participant ? 'You' : you.name) + '</div></div>' +
            '<div class="versus-score" aria-label="Score ' + esc(m.score.you) + ' to ' + esc(m.score.them) + '">' + esc(m.score.you) + ' : ' + esc(m.score.them) + '</div>' +
            '<div><div class="avatar avatar-lg' + (them ? '' : ' is-empty') + '">' + (them ? esc(them.initials) : icon('user')) + '</div><div class="versus-name">' + esc(them ? them.name : 'Waiting…') + '</div></div>' +
            '</div>';

        var status = '', statusIcon = 'clock', statusCls = '';
        if (m.status === 'waiting') status = m.is_creator ? 'Waiting for an opponent to join…' : 'Join this match to start playing.';
        else if (m.status === 'completed') {
            status = m.result === 'won' ? 'You won!' : (m.result === 'tie' ? 'Draw' : (m.result === 'lost' ? 'You lost this one' : 'Match finished'));
            statusIcon = m.result === 'won' ? 'trophy' : 'check-circle';
            if (m.result === 'won') statusCls = ' is-win';
        }
        else if (m.status === 'cancelled') { status = m.cancel_reason === 'expired' ? 'This match expired.' : 'This match was cancelled.'; statusIcon = 'x-circle'; }
        else if (m.can_roll) { status = 'Round ' + m.current_round + ' of ' + m.total_rounds + ' – your roll'; statusIcon = 'dice'; }
        else if (m.waiting_for_opponent) status = 'Waiting for ' + (them ? them.name : 'your opponent') + ' to roll…';
        html += '<p class="status-line' + statusCls + '" role="status">' + icon(statusIcon) + '<span>' + esc(status) + '</span></p>';

        if (m.status === 'completed' && m.reward_status === 'unfunded') html += '<p class="small muted center">Rewards were unavailable when this match ended (daily limit or budget).</p>';

        if (m.can_roll) html += '<button type="button" class="btn btn-primary btn-block btn-xl" data-action="roll-match"' + (state.rolling ? ' disabled' : '') + '>' + (state.rolling ? 'Rolling…' : 'Roll ' + m.dice_count + ' dice') + '</button>';
        if (m.can_join) html += '<button type="button" class="btn btn-primary btn-block btn-xl" data-action="join-current">Join match</button>';
        html += '</section>';

        if (m.invite_link) {
            html += '<section class="card"><h2 class="card-title">Invite your opponent</h2><p class="card-sub">Anyone with this link can join the match.</p>' +
                '<div class="link-box"><code>' + esc(m.invite_link) + '</code><button class="btn btn-sm btn-ghost" type="button" data-action="copy" data-text="' + esc(m.invite_link) + '">' + icon('copy', 'icon-sm') + 'Copy</button></div>' +
                '<div class="btn-row mt-3"><button class="btn btn-primary" type="button" data-action="share" data-url="' + esc(m.invite_link) + '" data-text="Let\'s roll some dice! ⚔️🎲">' + icon('share', 'icon-sm') + 'Share</button>' +
                (m.can_cancel ? '<button class="btn btn-ghost" type="button" data-action="cancel-match">Cancel match</button>' : '') + '</div></section>';
        }

        if (m.rounds.length) {
            html += '<h2 class="section-title">Rounds</h2><div class="card card-flat">' +
                '<div class="round-row round-head"><div>#</div><div class="center">You</div><div class="center">' + esc(them ? them.name : 'Opponent') + '</div></div>' +
                m.rounds.map(function (r) {
                    var youWin = r.you && r.them && r.you.total > r.them.total;
                    var themWin = r.you && r.them && r.them.total > r.you.total;
                    return '<div class="round-row"><div class="muted">R' + esc(r.round) + '</div>' +
                        '<div class="round-cell' + (youWin ? ' win' : '') + '">' + (r.you ? diceInline(r.you.dice) + '<strong>' + esc(r.you.total) + '</strong>' : '<span class="muted">—</span>') + '</div>' +
                        '<div class="round-cell' + (themWin ? ' win' : '') + '">' + (r.them ? diceInline(r.them.dice) + '<strong>' + esc(r.them.total) + '</strong>' : '<span class="muted">—</span>') + '</div></div>';
                }).join('') + '</div>';
        }

        var rewardText = m.rules.reward_mode === 'fixed' ? 'Winner earns ' + trimUsdt(m.rules.reward) + ' USDT' : (m.rules.reward_mode === 'pooled' ? trimUsdt(m.rules.pool) + ' USDT pool split by rounds won' : 'No reward – just for fun');
        var tieText = { extra_round: 'extra rounds on a tie', draw: 'ties end in a draw', split: 'ties split the reward' }[m.rules.tie_rule] || '';
        html += '<p class="small muted center">' + esc(m.base_rounds) + ' rounds · ' + esc(m.dice_count) + ' dice · higher total wins the round · ' + esc(tieText) + '<br>' + esc(rewardText) + '</p>';
        return html;
    }

    // ---- Missions

    function renderMissions() {
        var html = '<h1 class="screen-title">' + esc(cfg.nav.missions) + '</h1>';
        var d = state.missions;
        if (!d) return html + loadingOr('missions', 3);
        if (!d.enabled) return html + banner('Missions are currently unavailable.', 'warn');
        if (!d.missions.length) return html + '<div class="card">' + emptyState('target', 'No missions right now', 'New tasks are added regularly – check back soon.') + '</div>';

        return html + d.missions.map(function (m) {
            var status = {
                available: '', started: '<span class="badge badge-brand">Started</span>',
                pending_review: '<span class="badge badge-warn">In review</span>',
                rewarded: '<span class="badge badge-ok">' + icon('check') + 'Completed</span>',
                rejected: '<span class="badge badge-danger">Rejected</span>',
            }[m.status] || '';

            var progress = '';
            if (m.progress) {
                var pct = m.progress.target ? Math.min(100, Math.round(m.progress.current / m.progress.target * 100)) : 0;
                progress = '<div class="progress" role="progressbar" aria-label="Progress" aria-valuemin="0" aria-valuemax="' + esc(m.progress.target) + '" aria-valuenow="' + esc(m.progress.current) + '"><span style="width:' + pct + '%"></span></div><div class="small muted num">' + esc(m.progress.current) + ' / ' + esc(m.progress.target) + '</div>';
            }

            var actions = '';
            var done = m.status === 'rewarded' || m.status === 'pending_review';
            if (!done) {
                if (m.action_url) actions += '<button class="btn btn-sm btn-ghost" type="button" data-action="mission-open" data-id="' + m.id + '">' + icon('external', 'icon-sm') + 'Open</button>';
                if (m.needs_proof) {
                    actions += state.proofOpen[m.id]
                        ? ''
                        : '<button class="btn btn-sm btn-primary" type="button" data-action="mission-proof" data-id="' + m.id + '">Submit</button>';
                } else {
                    actions += '<button class="btn btn-sm btn-primary" type="button" data-action="mission-claim" data-id="' + m.id + '">' + (m.verification === 'telegram_api' ? 'Verify' : 'Claim') + '</button>';
                }
            }

            var proof = '';
            if (m.needs_proof && state.proofOpen[m.id] && !done) {
                proof = '<form class="proof-form mt-3" data-id="' + m.id + '" novalidate>' +
                    '<label class="field-label" for="proof-' + m.id + '">Proof for the moderator</label>' +
                    '<textarea class="input" id="proof-' + m.id + '" name="proof" maxlength="500" required placeholder="Your username or a link so a moderator can verify"></textarea>' +
                    '<div class="btn-row mt-2"><button class="btn btn-ghost" type="button" data-action="mission-proof-cancel" data-id="' + m.id + '">Cancel</button>' +
                    '<button class="btn btn-primary" type="submit">Send for review</button></div></form>';
            }

            return '<article class="card mission"><div class="mission-icon" aria-hidden="true">' + (m.image_url ? '<img src="' + esc(m.image_url) + '" alt="">' : (m.icon ? esc(m.icon) : icon('target'))) + '</div>' +
                '<div style="min-width:0"><div class="mission-head"><h2 class="mission-title">' + esc(m.title) + '</h2>' + status + '</div>' +
                (m.description ? '<p class="mission-desc">' + esc(m.description) + '</p>' : '') + progress +
                (m.reject_reason ? '<p class="small" style="color:var(--danger)">Not approved: ' + esc(m.reject_reason) + '</p>' : '') +
                '<div class="mission-footer mt-2"><span class="reward-chip">+' + esc(m.reward) + ' USDT</span>' +
                '<span class="btn-row" style="flex:0 0 auto">' + actions + '</span></div>' + proof +
                '<p class="mission-meta">' + esc(m.verification_label) + (m.repeat === 'daily' ? ' · resets daily' : '') + '</p></div></article>';
        }).join('') + footer();
    }

    // ---- Withdraw

    function renderWithdraw() {
        var html = '<h1 class="screen-title">' + esc(cfg.nav.withdraw) + '</h1>';
        var d = state.withdrawals;
        if (!d) return html + loadingOr('withdraw', 2);
        var s = d.summary;

        html += '<section class="card card-hero" aria-label="Withdrawable balance"><div class="balance-label">Available to withdraw</div>' +
            '<div class="balance-value">' + esc(s.available) + ' <small>USDT</small></div>' +
            '<div class="balance-meta"><span>In progress <strong>' + esc(s.reserved) + '</strong></span><span>Min <strong>' + esc(s.min) + '</strong></span><span>Max <strong>' + esc(s.max) + '</strong></span></div></section>';

        if (!s.enabled) {
            html += banner('Withdrawals are temporarily unavailable.', 'warn');
        } else if (s.eligibility) {
            html += banner(s.eligibility, 'warn');
        } else if (!s.networks.length) {
            html += banner('No payout network is available right now.', 'warn');
        } else {
            html += '<section class="card" aria-labelledby="wd-title"><h2 class="card-title" id="wd-title">New withdrawal</h2><p class="card-sub">Reviewed by our team, then paid manually to your wallet.</p>' +
                '<form id="withdraw-form" autocomplete="off" novalidate>' +
                '<div class="field"><label class="field-label" for="wd-network">Network</label><select class="input" name="network" id="wd-network" aria-describedby="wd-network-hint">' +
                s.networks.map(function (n) { return '<option value="' + esc(n.code) + '">' + esc(n.name) + '</option>'; }).join('') + '</select>' +
                '<div class="field-hint" id="wd-network-hint"></div></div>' +
                '<div class="field"><label class="field-label" for="wd-name">Recipient full name</label><input class="input" id="wd-name" name="full_name" maxlength="120" required autocomplete="name"></div>' +
                '<div class="field"><label class="field-label" for="wd-address">USDT wallet address</label><input class="input mono" id="wd-address" name="address" maxlength="128" required spellcheck="false" autocapitalize="off" autocorrect="off"></div>' +
                '<div class="field"><label class="field-label" for="wd-amount">Amount (USDT)</label><div class="input-group"><input class="input num" id="wd-amount" name="amount" inputmode="decimal" placeholder="0.00" required>' +
                '<button class="btn btn-ghost" type="button" data-action="wd-max">Max</button></div></div>' +
                '<div class="summary-box" id="wd-summary" aria-live="polite"></div>' +
                '<div class="field"><label class="field-label" for="wd-note">Note <span class="opt">(optional)</span></label><textarea class="input" id="wd-note" name="note" maxlength="500"></textarea></div>' +
                banner(s.warning, 'danger') +
                '<label class="check mt-3"><input type="checkbox" name="confirm" value="1"> <span>I confirm the network and address are correct and compatible with USDT.</span></label>' +
                '<button class="btn btn-primary btn-block btn-xl" type="submit">Review withdrawal</button>' +
                '<p class="server-note">' + icon('lock') + '<span>The amount is reserved until the request is paid or returned to your balance.</span></p>' +
                '</form></section>';
        }

        html += '<h2 class="section-title">History</h2><div class="card card-flat">';
        if (!d.history.length) html += emptyState('wallet', 'No withdrawals yet', 'Your requests and their status will appear here.');
        else html += '<div class="list">' + d.history.map(function (w) {
            var cls = { paid: 'badge-ok', rejected: 'badge-danger', cancelled: '', pending: 'badge-warn', approved: 'badge-brand', processing: 'badge-brand' }[w.status] || '';
            return '<div class="wd-item"><div class="wd-top"><div style="min-width:0">' +
                '<div class="wd-amount">' + esc(w.amount) + ' USDT · ' + esc(w.network) + '</div>' +
                '<div class="row-sub">' + esc(w.reference) + ' · ' + esc(fmtDate(w.created_at)) + '</div>' +
                '<div class="row-sub mono">' + esc(w.address) + '</div></div>' +
                '<span class="badge ' + cls + '">' + esc(w.status_label) + '</span></div>' +
                wdSteps(w.status) +
                (w.reject_reason ? '<p class="wd-note is-danger">' + esc(w.reject_reason) + '</p>' : '') +
                (w.tx_hash ? '<p class="wd-note muted">Transaction <span class="mono">' + esc(w.tx_hash.slice(0, 10)) + '…</span> <button type="button" class="link-btn" data-action="copy" data-text="' + esc(w.tx_hash) + '">Copy</button></p>' : '') +
                (w.can_cancel ? '<button class="btn btn-sm btn-ghost mt-2" type="button" data-action="wd-cancel" data-ref="' + esc(w.reference) + '">Cancel request</button>' : '') +
                '</div>';
        }).join('') + '</div>';
        return html + '</div>' + footer();
    }

    // Progress of an open or paid request; rejected and cancelled ones show their badge only.
    function wdSteps(status) {
        var reached = { pending: 1, approved: 2, processing: 2, paid: 4 }[status];
        if (!reached) return '';
        var labels = ['Requested', 'Approved', 'Sending', 'Paid'];
        return '<ol class="steps" aria-label="Withdrawal progress">' + labels.map(function (label, i) {
            var cls = i < reached ? 'is-done' : (i === reached ? 'is-current' : '');
            return '<li class="step ' + cls + '"' + (i === reached ? ' aria-current="step"' : '') + '>' + label + '</li>';
        }).join('') + '</ol>';
    }

    // Client-side estimate only; the server calculates the real fee.
    function withdrawQuote(form) {
        var nets = state.withdrawals.summary.networks;
        var net = nets.filter(function (n) { return n.code === form.network.value; })[0];
        var amount = toMicro(form.amount.value.replace(',', '.'));
        if (!net || amount === null || amount === 0n) return { net: net, amount: null };
        var fee = toMicro(net.fee_fixed) + amount * toMicro(net.fee_percent || '0') / 100000000n;
        var receive = amount - fee;
        return { net: net, amount: amount, fee: fee, receive: receive > 0n ? receive : 0n };
    }

    function updateWithdrawSummary() {
        var form = document.getElementById('withdraw-form');
        if (!form || !state.withdrawals) return;
        var q = withdrawQuote(form);
        var hint = document.getElementById('wd-network-hint');
        if (q.net) hint.textContent = 'Fee ' + q.net.fee_fixed + ' USDT' + (Number(q.net.fee_percent) ? ' + ' + q.net.fee_percent + '%' : '') + ' · Min ' + q.net.min + (q.net.max ? ' · Max ' + q.net.max : '');

        var box = document.getElementById('wd-summary');
        if (q.amount === null) { box.innerHTML = '<span class="muted">Enter an amount to see the fee and what you receive.</span>'; return; }
        box.innerHTML = quoteHtml(q);
    }

    function quoteHtml(q, extra) {
        return '<dl class="kv"><dt>Requested</dt><dd>' + esc(fromMicro(q.amount)) + ' USDT</dd>' +
            '<dt>Network fee (estimate)</dt><dd>−' + esc(fromMicro(q.fee)) + ' USDT</dd>' +
            '<dt class="kv-total">You receive (estimate)</dt><dd class="kv-total">' + esc(fromMicro(q.receive)) + ' USDT</dd>' + (extra || '') + '</dl>';
    }

    function setFieldError(form, name, message) {
        var input = form.elements[name];
        if (!input) return;
        var wrap = input.closest('.field, .check');
        var id = 'err-' + name;
        var old = document.getElementById(id);
        if (old) old.remove();
        if (!message) { input.removeAttribute('aria-invalid'); input.removeAttribute('aria-errormessage'); return; }
        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('aria-errormessage', id);
        var el = document.createElement('div');
        el.className = 'field-error'; el.id = id; el.textContent = message;
        if (wrap.classList.contains('check')) wrap.insertAdjacentElement('afterend', el); else wrap.appendChild(el);
    }

    function validateWithdraw(form, payload) {
        var errors = {};
        if (!payload.full_name) errors.full_name = 'Enter the recipient\'s full name.';
        if (!payload.address) errors.address = 'Enter your USDT wallet address.';
        var amount = toMicro(payload.amount);
        var summary = state.withdrawals.summary;
        var net = summary.networks.filter(function (n) { return n.code === payload.network; })[0];
        if (amount === null || amount === 0n || !/^\d+(\.\d{0,6})?$/.test(payload.amount)) errors.amount = 'Enter an amount, for example 10 or 10.50.';
        else if (net && net.min && amount < toMicro(net.min)) errors.amount = 'The minimum for ' + net.name + ' is ' + net.min + ' USDT.';
        else if (net && net.max && amount > toMicro(net.max)) errors.amount = 'The maximum for ' + net.name + ' is ' + net.max + ' USDT.';
        else if (summary.available_exact && amount > toMicro(summary.available_exact)) errors.amount = 'This is more than your available balance.';
        if (!payload.confirm) errors.confirm = 'Please confirm the network and address.';
        ['full_name', 'address', 'amount', 'confirm'].forEach(function (f) { setFieldError(form, f, errors[f]); });
        var first = Object.keys(errors)[0];
        if (first) form.elements[first].focus();
        return !first;
    }

    // ---- Referrals

    function renderReferrals() {
        var html = subHeader('Invite friends');
        var r = state.referrals;
        if (!r) {
            if (state.errors.referrals) return html + errorState('referrals');
            load('referrals');
            return html + skeleton(3);
        }

        if (!r.active) html += banner('The referral campaign is currently paused – new rewards are not being paid.', 'warn');
        html += '<section class="card"><h2 class="card-title">Your invite link</h2><p class="card-sub">Friends who join with this link are added to your team.</p>' +
            '<div class="link-box"><code>' + esc(r.link) + '</code><button class="btn btn-sm btn-ghost" type="button" data-action="copy" data-text="' + esc(r.link) + '">' + icon('copy', 'icon-sm') + 'Copy</button></div>' +
            '<button class="btn btn-primary btn-block mt-3" type="button" data-action="share-url" data-url="' + esc(r.share_url) + '">' + icon('share', 'icon-sm') + 'Share with friends</button>' +
            '</section>';

        html += '<div class="stats"><div class="stat"><strong>' + esc(r.direct_count) + '</strong><span>Invited</span></div>' +
            '<div class="stat"><strong>' + esc(r.qualified_count) + '</strong><span>Qualified</span></div>' +
            '<div class="stat"><strong>' + esc(r.earned) + '</strong><span>Earned USDT</span></div></div>';

        html += '<h2 class="section-title">How rewards work</h2><section class="card">' +
            '<table class="levels"><thead><tr><th>Level</th><th>Bonus when qualified</th><th>Share of rewards</th></tr></thead><tbody>' +
            r.levels.map(function (l) { return '<tr><td>' + esc(l.level) + '</td><td>' + esc(l.fixed) + ' USDT</td><td>' + esc(l.percent) + '%</td></tr>'; }).join('') +
            '</tbody></table><p class="small muted mt-3">A friend qualifies after playing ' + esc(r.qualification.min_games) + ' games' +
            (r.qualification.min_age_hours ? ' and being a member for ' + esc(r.qualification.min_age_hours) + ' hours' : '') + '.</p>' +
            '<p class="small muted" style="margin:0">' + esc(r.disclosure) + '</p></section>';

        html += '<h2 class="section-title">Friends</h2><div class="card card-flat">';
        html += r.friends.length ? '<div class="list">' + r.friends.map(function (f) {
            return '<div class="row"><div class="row-icon">' + icon('user') + '</div><div class="row-main"><div class="row-title">' + esc(f.name) + '</div><div class="row-sub">Joined ' + esc(fmtDate(f.joined_at)) + '</div></div>' +
                (f.qualified ? '<span class="badge badge-ok">' + icon('check') + 'Qualified</span>' : '<span class="badge">Not yet</span>') + '</div>';
        }).join('') + '</div>' : emptyState('users', 'No friends yet', 'Share your link – friends appear here as soon as they join.');
        html += '</div>';

        html += '<h2 class="section-title">Referral rewards</h2><div class="card card-flat">';
        html += r.rewards.length ? '<div class="list">' + r.rewards.map(function (x) {
            var badge = x.status === 'credited' ? '<span class="amount amount-in">+' + esc(x.amount) + '</span>' : '<span class="badge">' + esc(x.status) + '</span>';
            return '<div class="row"><div class="row-icon" aria-label="Level ' + esc(x.level) + '">L' + esc(x.level) + '</div><div class="row-main"><div class="row-title">' + (x.event === 'qualification' ? 'Qualification bonus' : 'Reward share') + '</div>' +
                '<div class="row-sub">' + esc([x.from, fmtDate(x.created_at)].filter(Boolean).join(' · ')) + '</div></div>' + badge + '</div>';
        }).join('') + '</div>' : emptyState('gift', 'No referral rewards yet', 'You earn when invited friends qualify and play.');
        return html + '</div>';
    }

    // ------------------------------------------------------------------ actions

    var actions = {
        go: function (el) { setTab(el.dataset.tab); },
        sub: function (el) {
            if (el.dataset.sub === 'transactions') state.allTx = null;
            if (el.dataset.sub === 'referrals') state.referrals = null;
            openSub(el.dataset.sub);
        },
        back: function () { closeSub(); },
        link: function (el) { openLink(el.dataset.url); },
        copy: function (el) { copy(el.dataset.text); },
        share: function (el) { openLink('https://t.me/share/url?url=' + encodeURIComponent(el.dataset.url) + '&text=' + encodeURIComponent(el.dataset.text || '')); },
        'share-url': function (el) { openLink(el.dataset.url); },
        mode: function (el) { state.gameMode = el.dataset.mode; render(); },
        retry: function (el) {
            var what = el.dataset.what;
            state.errors[what] = null;
            if (what === 'transactions') { state.allTx = null; render(); return; }
            if (what === 'referrals') { state.referrals = null; render(); return; }
            render();
            load(what);
        },

        play: function () {
            if (state.rolling) return;
            var wait = Math.ceil((state.cooldownUntil - Date.now()) / 1000);
            if (wait > 0) { toast('Next roll in ' + wait + 's'); return; }

            state.rolling = true;
            state.playKey = state.playKey || newKey(); // reused if the request must be retried
            haptic('medium');
            render();

            Promise.all([api('POST', '/games/single/play', {}, { key: state.playKey }), sleep(900)]).then(function (r) {
                var res = r[0];
                state.playKey = null;
                state.rolling = false;
                state.lastRound = res.round;
                state.games.single.played_today = res.played_today;
                state.games.recent_rounds.unshift(res.round);
                state.games.recent_rounds = state.games.recent_rounds.slice(0, 10);
                state.cooldownUntil = Date.now() + (res.cooldown_remaining || 0) * 1000;
                if (state.home) { state.home.stats.available = res.balance; updateTopbar(); }
                else document.getElementById('topbar-balance').textContent = res.balance;
                haptic(res.round.is_win ? 'success' : 'light');
                render();
                startCooldownTicker();
            }).catch(function (err) {
                state.rolling = false;
                if (err.status !== 0) state.playKey = null; // keep the key only for network failures
                if (err.data && err.data.context && err.data.context.retry_after) state.cooldownUntil = Date.now() + err.data.context.retry_after * 1000;
                render();
                startCooldownTicker();
                showError(err);
            });
        },

        'create-match': function (el) {
            busy(el, true);
            api('POST', '/matches', { visibility: el.dataset.visibility }).then(function (res) {
                state.match = res.match;
                haptic('success');
                openSub('match');
                startPolling();
            }).catch(function (e) { busy(el, false); showError(e); });
        },
        'refresh-matches': function () { load('games'); },
        'open-match': function (el) { openMatch(el.dataset.uuid, null); },
        'join-match': function (el) { joinMatch(el.dataset.uuid, null, el); },
        'join-current': function (el) { joinMatch(state.match.uuid, state.matchCode, el); },
        'roll-match': function () {
            if (state.rolling) return;
            state.rolling = true;
            haptic('medium');
            render();
            Promise.all([api('POST', '/matches/' + state.match.uuid + '/roll', {}), sleep(700)]).then(function (r) {
                state.rolling = false;
                state.match = r[0].match;
                haptic(r[0].match.result === 'won' ? 'success' : 'light');
                render();
            }).catch(function (e) { state.rolling = false; render(); showError(e); refreshMatch(); });
        },
        'cancel-match': function () {
            confirmDialog({ title: 'Cancel this match?', message: 'Your opponent will no longer be able to join.', confirm: 'Cancel match', cancel: 'Keep', danger: true }, function () {
                api('POST', '/matches/' + state.match.uuid + '/cancel', {}).then(function (res) {
                    state.match = res.match; render(); toast('Match cancelled');
                }).catch(showError);
            });
        },

        'mission-open': function (el) {
            var m = findMission(el.dataset.id);
            api('POST', '/missions/' + m.id + '/start', {}).then(function (res) {
                if (m.status === 'available') m.status = 'started';
                render();
                openLink(res.action_url || m.action_url);
            }).catch(showError);
        },
        'mission-claim': function (el) {
            busy(el, true);
            api('POST', '/missions/' + el.dataset.id + '/claim', {}).then(function (res) {
                haptic('success'); toast(res.message, 'success');
                return load('missions').then(function () { return load('home'); });
            }).catch(function (e) { busy(el, false); showError(e); });
        },
        'mission-proof': function (el) {
            state.proofOpen[el.dataset.id] = true;
            render();
            var area = document.getElementById('proof-' + el.dataset.id);
            if (area) area.focus();
        },
        'mission-proof-cancel': function (el) { delete state.proofOpen[el.dataset.id]; render(); },

        'wd-max': function () {
            var f = document.getElementById('withdraw-form');
            f.amount.value = state.withdrawals.summary.available_exact.replace(/\.?0+$/, '');
            setFieldError(f, 'amount', null);
            updateWithdrawSummary();
        },
        'wd-cancel': function (el) {
            confirmDialog({ title: 'Cancel this request?', message: 'The reserved amount returns to your available balance.', confirm: 'Cancel request', cancel: 'Keep', danger: true }, function () {
                api('POST', '/withdrawals/' + encodeURIComponent(el.dataset.ref) + '/cancel', {}).then(function () {
                    toast('Request cancelled', 'success'); load('withdraw'); load('home');
                }).catch(showError);
            });
        },
        'more-tx': function (el) {
            var next = state.allTx.next_page;
            busy(el, true);
            api('GET', '/transactions?page=' + next).then(function (d) {
                state.allTx.data = state.allTx.data.concat(d.data);
                state.allTx.next_page = d.next_page;
                render();
            }).catch(function (e) { busy(el, false); showError(e); });
        },
    };

    function findMission(id) {
        return state.missions.missions.filter(function (m) { return String(m.id) === String(id); })[0];
    }

    // Bottom-sheet confirmation (native <dialog>: focus trap, Esc and the
    // Telegram back button close it). opts: title, message, html, confirm, cancel, danger.
    var sheet = document.getElementById('sheet');
    var sheetAction = null, sheetReturn = null;

    function confirmDialog(opts, onYes) {
        if (!sheet || typeof sheet.showModal !== 'function') {
            if (window.confirm(opts.title + (opts.message ? '\n\n' + opts.message : ''))) onYes();
            return;
        }
        document.getElementById('sheet-title').textContent = opts.title;
        var text = document.getElementById('sheet-text');
        text.textContent = opts.message || '';
        text.hidden = !opts.message;
        var body = document.getElementById('sheet-body');
        body.innerHTML = opts.html || '';
        body.hidden = !opts.html;
        var ok = sheet.querySelector('[data-sheet="ok"]'), cancel = sheet.querySelector('[data-sheet="cancel"]');
        ok.textContent = opts.confirm || 'Confirm';
        ok.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');
        cancel.textContent = opts.cancel || 'Cancel';
        sheetAction = onYes;
        sheetReturn = document.activeElement;
        sheet.showModal();
        (opts.danger ? cancel : ok).focus();
        haptic('light');
    }

    function closeSheet() { if (sheet && sheet.open) sheet.close(); }

    if (sheet) {
        sheet.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-sheet]');
            if (e.target === sheet) { closeSheet(); return; } // backdrop
            if (!btn) return;
            var action = btn.dataset.sheet === 'ok' ? sheetAction : null;
            closeSheet();
            if (action) action();
        });
        sheet.addEventListener('close', function () {
            sheetAction = null;
            if (sheetReturn && document.contains(sheetReturn)) sheetReturn.focus();
        });
    }

    function openMatch(uuid, code) {
        state.match = null;
        state.pendingMatchUuid = uuid;
        state.matchCode = code;
        openSub('match');
        refreshMatch().then(startPolling);
    }

    function refreshMatch() {
        var uuid = state.match ? state.match.uuid : state.pendingMatchUuid;
        if (!uuid) return Promise.resolve();
        return api('GET', '/matches/' + uuid + (state.matchCode ? '?code=' + encodeURIComponent(state.matchCode) : '')).then(function (res) {
            state.match = res.match;
            if (state.sub === 'match') render();
        }).catch(function (e) {
            if (e.status === 404) { toast('Match not found or no longer open.', 'error'); closeSub(); return; }
            showError(e);
        });
    }

    function joinMatch(uuid, code, el) {
        if (el) busy(el, true);
        api('POST', '/matches/' + uuid + '/join', { code: code }).then(function (res) {
            state.match = res.match;
            state.matchCode = null;
            haptic('success');
            if (state.sub !== 'match') openSub('match'); else render();
            startPolling();
        }).catch(function (e) { if (el) busy(el, false); showError(e); });
    }

    // Poll the match while it is open on screen (no WebSockets needed).
    function startPolling() {
        stopPolling();
        state.pollTimer = setInterval(function () {
            if (state.sub !== 'match' || !state.match || document.hidden || state.rolling) return;
            if (['completed', 'cancelled'].indexOf(state.match.status) !== -1) { stopPolling(); return; }
            api('GET', '/matches/' + state.match.uuid + (state.matchCode ? '?code=' + encodeURIComponent(state.matchCode) : '')).then(function (res) {
                var changed = JSON.stringify(res.match) !== JSON.stringify(state.match);
                state.match = res.match;
                if (changed && state.sub === 'match') {
                    if (res.match.can_roll) haptic('light');
                    render();
                }
            }).catch(function () { /* transient; next tick retries */ });
        }, 3000);
    }

    function stopPolling() {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = null;
    }

    function startCooldownTicker() {
        clearInterval(state.cooldownTimer);
        var tick = function () {
            var btn = document.getElementById('play-btn');
            var left = Math.ceil((state.cooldownUntil - Date.now()) / 1000);
            if (!btn || state.rolling) return;
            if (left > 0) { btn.disabled = true; btn.textContent = 'Next roll in ' + left + 's'; }
            else {
                clearInterval(state.cooldownTimer);
                btn.disabled = !(state.games && state.games.single.enabled);
                btn.textContent = 'Play';
            }
        };
        tick();
        state.cooldownTimer = setInterval(tick, 500);
    }

    function afterRender() {
        if (state.tab === 'withdraw' && !state.sub) updateWithdrawSummary();
        if (state.tab === 'games' && !state.sub && state.gameMode === 'single') startCooldownTicker();
    }

    // ------------------------------------------------------------------ events

    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-action]');
        if (!el || !actions[el.dataset.action]) return;
        e.preventDefault();
        actions[el.dataset.action](el, e);
    });

    document.addEventListener('input', function (e) {
        var form = e.target.closest('#withdraw-form');
        if (form) {
            if (e.target.getAttribute('aria-invalid') === 'true') setFieldError(form, e.target.name, null);
            updateWithdrawSummary();
        }
    });
    document.addEventListener('change', function (e) {
        var form = e.target.closest('#withdraw-form');
        if (form) {
            if (e.target.name === 'confirm' && e.target.checked) setFieldError(form, 'confirm', null);
            updateWithdrawSummary();
        }
    });

    // Hairline under the top bar once content scrolls beneath it.
    window.addEventListener('scroll', function () {
        var bar = document.getElementById('topbar');
        if (bar) bar.classList.toggle('is-scrolled', window.scrollY > 4);
    }, { passive: true });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        e.preventDefault();

        if (form.id === 'withdraw-form') {
            var payload = {
                network: form.network.value,
                full_name: form.full_name.value.trim(),
                address: form.address.value.trim(),
                amount: form.amount.value.trim().replace(',', '.'),
                note: form.note.value.trim() || null,
                confirm: form.confirm.checked,
            };
            if (!validateWithdraw(form, payload)) { haptic('error'); return; }
            var q = withdrawQuote(form);
            var details = '<dt>Network</dt><dd>' + esc(q.net ? q.net.name : payload.network) + '</dd>' +
                '<dt>Recipient</dt><dd>' + esc(payload.full_name) + '</dd>' +
                '<dt>Wallet address</dt><dd></dd><dd class="wrap">' + esc(payload.address) + '</dd>';
            confirmDialog({
                title: 'Confirm withdrawal',
                message: 'Check every detail. Payments sent to a wrong address or network cannot be recovered.',
                html: q.amount === null ? '' : quoteHtml(q, details),
                confirm: 'Request ' + payload.amount + ' USDT',
            }, function () {
                var btn = form.querySelector('[type=submit]');
                busy(btn, true);
                form.dataset.key = form.dataset.key || newKey();
                api('POST', '/withdrawals', payload, { key: form.dataset.key }).then(function (res) {
                    haptic('success');
                    toast('Request ' + res.withdrawal.reference + ' submitted', 'success');
                    load('withdraw'); load('home');
                }).catch(function (err) {
                    busy(btn, false);
                    if (err.status !== 0) delete form.dataset.key;
                    if (err.data && err.data.errors) {
                        Object.keys(err.data.errors).forEach(function (f) { setFieldError(form, f, err.data.errors[f][0]); });
                    }
                    showError(err);
                });
            });
        }

        if (form.id === 'invite-form') {
            var invite = form.invite.value.trim();
            if (!invite) { form.invite.focus(); toast('Enter the @username of a player.', 'error'); return; }
            var inviteBtn = form.querySelector('[type=submit]');
            busy(inviteBtn, true);
            api('POST', '/matches', { visibility: 'private', invite: invite }).then(function (res) {
                toast('Invitation sent', 'success');
                state.match = res.match;
                openSub('match');
                startPolling();
            }).catch(function (e) { busy(inviteBtn, false); showError(e); });
        }

        if (form.classList.contains('proof-form')) {
            var id = form.dataset.id;
            var proof = form.proof.value.trim();
            if (!proof) { form.proof.setAttribute('aria-invalid', 'true'); form.proof.focus(); toast('Add a username or link so we can verify.', 'error'); return; }
            var proofBtn = form.querySelector('[type=submit]');
            busy(proofBtn, true);
            api('POST', '/missions/' + id + '/claim', { proof: proof }).then(function (res) {
                delete state.proofOpen[id];
                toast(res.message, 'success');
                load('missions');
            }).catch(function (e) { busy(proofBtn, false); showError(e); });
        }
    });

    // ------------------------------------------------------------------ boot

    function applyTheme() {
        if (!tg) return;
        try {
            if (cfg.use_telegram_theme && tg.themeParams) {
                var p = tg.themeParams, root = document.documentElement.style;
                if (p.bg_color) root.setProperty('--bg', p.bg_color);
                if (p.secondary_bg_color) root.setProperty('--surface', p.secondary_bg_color);
                if (p.text_color) root.setProperty('--text', p.text_color);
                if (p.hint_color) root.setProperty('--muted', p.hint_color);
                if (p.button_color) root.setProperty('--brand', p.button_color);
                if (tg.colorScheme === 'light') document.body.classList.add('theme-light');
            }
            var bg = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim();
            if (tg.setHeaderColor && /^#[0-9a-f]{6}$/i.test(bg)) tg.setHeaderColor(bg);
            if (tg.setBackgroundColor && /^#[0-9a-f]{6}$/i.test(bg)) tg.setBackgroundColor(bg);
        } catch (e) { /* older clients */ }
    }

    function handleDeepLink() {
        var params = new URLSearchParams(location.search);
        var start = state.startParam || (tg && tg.initDataUnsafe && tg.initDataUnsafe.start_param) || '';
        var tab = params.get('tab');

        if (params.get('match')) {
            state.tab = 'games'; state.gameMode = 'duel';
            state.pendingMatchUuid = params.get('match');
            state.match = null;
            state.matchCode = params.get('code');
            state.sub = 'match';
            updateChrome(); render();
            api('GET', '/matches/' + encodeURIComponent(params.get('match')) + (state.matchCode ? '?code=' + encodeURIComponent(state.matchCode) : ''))
                .then(function (res) { state.match = res.match; render(); startPolling(); })
                .catch(function (e) { showError(e); closeSub(); });
            return true;
        }

        if (/^m_[A-Za-z0-9]{20}$/.test(start)) {
            var code = start.slice(2);
            state.tab = 'games'; state.gameMode = 'duel'; state.sub = 'match'; state.matchCode = code;
            updateChrome(); render();
            api('GET', '/matches/invite/' + code).then(function (res) { state.match = res.match; render(); startPolling(); })
                .catch(function (e) { showError(e); closeSub(); });
            return true;
        }

        if (tab && ['home', 'games', 'missions', 'withdraw'].indexOf(tab) !== -1) { setTab(tab); return true; }
        return false;
    }

    function boot() {
        if (tg) {
            tg.ready();
            try { tg.expand(); } catch (e) { /* ignore */ }
            applyTheme();
            if (tg.BackButton) tg.BackButton.onClick(function () { if (sheet && sheet.open) closeSheet(); else closeSub(); });
        }

        authenticate().then(function () {
            document.getElementById('app').classList.remove('is-booting');
            document.getElementById('boot').hidden = true;
            document.getElementById('topbar').hidden = false;
            document.getElementById('tabbar').hidden = false;

            return load('home').then(function () {
                if (!handleDeepLink()) { updateChrome(); render(); }
                if (state.newUser) toast('Welcome! Roll doubles to earn your first reward.', 'success');
            });
        }).catch(function (err) {
            document.getElementById('boot-text').textContent = err.message || 'Could not connect.';
            document.querySelector('.boot .spinner').hidden = true;
            var bootEl = document.getElementById('boot');
            if (cfg.bot_url && err.code === 'no_telegram') {
                var a = document.createElement('a');
                a.className = 'btn btn-primary'; a.href = cfg.bot_url; a.textContent = 'Open in Telegram';
                bootEl.appendChild(a);
            } else if (err.code !== 'no_telegram') {
                var retry = document.createElement('button');
                retry.type = 'button'; retry.className = 'btn btn-ghost'; retry.textContent = 'Try again';
                retry.addEventListener('click', function () { location.reload(); });
                bootEl.appendChild(retry);
            }
        });
    }

    boot();
})();
