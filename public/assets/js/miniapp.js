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
        el.textContent = message;
        el.className = 'toast' + (kind ? ' is-' + kind : '');
        el.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.hidden = true; }, 3200);
    }

    function openLink(url) {
        if (!url) return;
        if (tg && /^https:\/\/t\.me\//.test(url) && tg.openTelegramLink) return tg.openTelegramLink(url);
        if (tg && tg.openLink) return tg.openLink(url);
        window.open(url, '_blank', 'noopener');
    }

    function copy(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { toast('Copied!', 'success'); }, function () { toast(text); });
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
            b.classList.toggle('is-active', b.dataset.tab === state.tab);
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
        return loaders[what]().then(render).catch(function (err) {
            if (err.code === 'maintenance') return renderMaintenance(err.message);
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

    function skeleton(n) {
        var s = '';
        for (var i = 0; i < (n || 3); i++) s += '<div class="skeleton"></div>';
        return s;
    }

    function footer() {
        return '<p class="footer-note">' + esc(cfg.disclaimer) + '<br>' +
            '<a href="#" data-action="link" data-url="' + esc(cfg.terms_url) + '">Terms</a> · ' +
            '<a href="#" data-action="link" data-url="' + esc(cfg.privacy_url) + '">Privacy</a>' +
            (cfg.support_url ? ' · <a href="#" data-action="link" data-url="' + esc(cfg.support_url) + '">Support</a>' : '') +
            '</p>';
    }

    function renderMaintenance(message) {
        view.innerHTML = '<div class="card center" style="margin-top:30px"><div style="font-size:42px">🛠️</div><h2>Maintenance</h2><p class="muted">' + esc(message) + '</p></div>';
    }

    // ---- Home

    function renderHome() {
        var d = state.home;
        if (!d) return skeleton(4);
        var u = d.user, s = d.stats;

        var html = '';
        if (d.home.announcement) html += '<div class="banner">' + esc(d.home.announcement) + '</div>';
        if (d.restricted) html += '<div class="banner banner-warn">Your account is restricted: playing, claiming and withdrawing are paused. Please contact support.</div>';

        html += '<section class="card card-hero">' +
            '<div class="profile"><div class="avatar">' + avatarInner(u) + '</div><div style="min-width:0">' +
            '<p class="muted small" style="margin:0">' + esc(d.home.headline || 'Welcome back') + '</p>' +
            '<h1 class="profile-name">' + esc(u.name) + '</h1>' +
            '<div class="profile-meta">' + (u.username ? '<span>@' + esc(u.username) + '</span>' : '') +
            '<button class="id-pill" type="button" data-action="copy" data-text="' + esc(u.id) + '" aria-label="Copy your ID">ID ' + esc(u.id) + '</button></div>' +
            '</div></div>' +
            '<div class="balance"><div class="balance-label">Available balance</div>' +
            '<div class="balance-value">' + esc(s.available) + ' <small>USDT</small></div>' +
            '<div class="balance-row">' +
            '<div><span>Pending withdrawal</span><strong>' + esc(s.reserved) + '</strong></div>' +
            '<div><span>Pending rewards</span><strong>' + esc(s.pending_rewards) + '</strong></div>' +
            '<div><span>Total earned</span><strong>' + esc(s.total_earned) + '</strong></div>' +
            '</div></div></section>';

        html += '<div class="stats">' +
            '<div class="stat"><strong>' + esc(s.games_played) + '</strong><span>Games played</span></div>' +
            '<div class="stat"><strong>' + esc(s.games_won) + '</strong><span>Wins</span></div>' +
            '<div class="stat"><strong>' + esc(s.referrals) + '</strong><span>Referrals</span></div>' +
            '</div>';

        html += '<div class="quick">' +
            '<button type="button" data-action="go" data-tab="games"><span class="q-icon">🎲</span>Play</button>' +
            '<button type="button" data-action="go" data-tab="missions"><span class="q-icon">🎯</span>Missions</button>' +
            '<button type="button" data-action="sub" data-sub="referrals"><span class="q-icon">👥</span>Invite</button>' +
            '<button type="button" data-action="go" data-tab="withdraw"><span class="q-icon">💸</span>Withdraw</button>' +
            '</div>';

        html += '<div class="section-title">Recent activity <a href="#" class="small" data-action="sub" data-sub="transactions">See all</a></div>';
        html += '<div class="card card-flat">' + txList(d.transactions) + '</div>';

        return html + footer();
    }

    function txList(items) {
        if (!items || !items.length) return '<div class="empty">No transactions yet. Roll the dice to get started!</div>';
        var icons = { game_reward: '🎲', match_reward: '⚔️', referral_reward: '👥', mission_reward: '🎯', withdrawal_reserve: '⏳', withdrawal_release: '↩️', withdrawal_payout: '💸', reward_reversal: '⛔', admin_credit: '➕', admin_debit: '➖' };
        return '<div class="list">' + items.map(function (t) {
            return '<div class="row"><div class="row-icon">' + (icons[t.type] || '•') + '</div>' +
                '<div class="row-main"><div class="row-title">' + esc(t.label) + '</div><div class="row-sub">' + esc(t.description || '') + ' · ' + esc(fmtDate(t.created_at)) + '</div></div>' +
                '<div class="' + (t.direction === 'in' ? 'amount-in' : 'amount-out') + ' nowrap">' + esc(t.amount) + '</div></div>';
        }).join('') + '</div>';
    }

    function renderTransactions() {
        var html = subHeader('All transactions');
        if (!state.allTx) {
            api('GET', '/transactions').then(function (d) { state.allTx = d; render(); }).catch(showError);
            return html + skeleton(3);
        }
        html += '<div class="card card-flat">' + txList(state.allTx.data) + '</div>';
        if (state.allTx.next_page) html += '<button class="btn btn-ghost btn-block" style="margin-top:12px" type="button" data-action="more-tx">Load more</button>';
        return html;
    }

    function subHeader(title) {
        return '<div class="subheader"><button type="button" class="back-btn" data-action="back" aria-label="Back">‹</button><h2>' + esc(title) + '</h2></div>';
    }

    // ---- Games

    function renderGames() {
        var g = state.games;
        var html = '<h1 class="screen-title">' + esc(cfg.nav.games) + '</h1>' +
            '<div class="segmented" role="tablist">' +
            '<button type="button" class="' + (state.gameMode === 'single' ? 'is-active' : '') + '" data-action="mode" data-mode="single">🎲 Solo</button>' +
            '<button type="button" class="' + (state.gameMode === 'duel' ? 'is-active' : '') + '" data-action="mode" data-mode="duel">⚔️ Duel</button>' +
            '</div>';
        if (!g) return html + skeleton(2);
        return html + (state.gameMode === 'single' ? renderSingle(g) : renderDuel(g));
    }

    function renderSingle(g) {
        var s = g.single;
        var last = state.lastRound;
        var dice = last ? last.dice : [0, 0];
        var cls = last && last.is_win ? 'is-win is-landing' : (last ? 'is-landing' : '');
        if (state.rolling) cls = 'is-rolling';

        var result = '';
        if (state.rolling) result = '<p class="result-text">Rolling on the server…</p>';
        else if (last && last.is_win) result = '<p class="result-text win">Doubles! ' + (last.reward_status === 'credited' ? '+' + esc(last.reward) + ' USDT' : 'Daily reward limit reached') + '</p>';
        else if (last) result = '<p class="result-text loss">' + esc(last.dice[0]) + ' + ' + esc(last.dice[1]) + ' – no doubles this time</p>';
        else result = '<p class="result-text muted">Roll doubles to win ' + esc(s.reward_display) + ' USDT</p>';

        var disabled = !s.enabled || state.rolling;
        var html = '<section class="card">' +
            '<div class="dice-stage">' + dieHtml(dice[0], cls) + dieHtml(dice[1], cls) + '</div>' + result;

        if (!s.enabled) {
            html += '<div class="banner banner-warn">' + (s.maintenance ? 'Games are under maintenance.' : 'This game is currently disabled.') + '</div>';
        }
        html += '<button type="button" id="play-btn" class="btn btn-primary btn-block btn-xl" data-action="play"' + (disabled ? ' disabled' : '') + '>Play</button>' +
            '<p class="server-note">Dice are rolled with a secure random generator on our server. The animation only reveals the result.</p>' +
            '</section>';

        html += '<section class="card"><dl class="kv">' +
            '<dt>Reward for doubles</dt><dd>' + esc(s.reward_display) + ' USDT</dd>' +
            '<dt>Chance to win</dt><dd>1 in 6</dd>' +
            (s.cooldown_seconds ? '<dt>Cooldown</dt><dd>' + esc(s.cooldown_seconds) + 's</dd>' : '') +
            (s.daily_limit ? '<dt>Played today</dt><dd>' + esc(s.played_today) + ' / ' + esc(s.daily_limit) + '</dd>' : '') +
            '</dl><hr style="border:0;border-top:1px solid var(--line);margin:14px 0"><p class="rules">' + esc(s.rules_text) + '</p></section>';

        html += '<div class="section-title">Your last rounds</div><div class="card card-flat">';
        if (!g.recent_rounds.length) html += '<div class="empty">No rounds yet.</div>';
        else html += '<div class="list">' + g.recent_rounds.map(function (r) {
            return '<div class="row">' + diceInline(r.dice) + '<div class="row-main"><div class="row-sub">' + esc(fmtDate(r.created_at)) + '</div></div>' +
                (r.is_win ? '<span class="badge badge-ok">' + (r.reward_status === 'credited' ? '+' + esc(r.reward) : 'Win') + '</span>' : '<span class="badge">Loss</span>') + '</div>';
        }).join('') + '</div>';
        return html + '</div>';
    }

    function renderDuel(g) {
        var m = g.multi;
        var html = '';
        if (!m.enabled) return html + '<div class="banner banner-warn">Two-player matches are currently disabled.</div>';

        var reward = m.reward_mode === 'fixed' ? 'Winner earns ' + m.reward + ' USDT' : (m.reward_mode === 'pooled' ? 'Pool of ' + m.pool + ' USDT split by rounds won' : 'Just for fun – no reward');
        html += '<section class="card card-hero"><h3 style="margin:0 0 4px">Challenge a friend</h3>' +
            '<p class="muted small" style="margin:0 0 12px">' + esc(m.rounds) + ' rounds · ' + esc(m.dice_count) + ' dice · ' + esc(reward) + ' · free to play</p>' +
            '<div class="btn-row"><button type="button" class="btn btn-primary" data-action="create-match" data-visibility="public">Public match</button>' +
            '<button type="button" class="btn btn-ghost" data-action="create-match" data-visibility="private">Private invite</button></div>' +
            '<form id="invite-form" class="input-group" style="margin-top:10px" autocomplete="off">' +
            '<input class="input" name="invite" placeholder="@username of a player" maxlength="64" aria-label="Invite by username">' +
            '<button class="btn btn-accent" type="submit">Invite</button></form>' +
            '<p class="rules small" style="margin-top:12px">' + esc(m.rules_text) + '</p></section>';

        var mine = (state.matches && state.matches.mine) || [];
        var active = mine.filter(function (x) { return ['waiting', 'ready', 'playing'].indexOf(x.status) !== -1; });
        var done = mine.filter(function (x) { return ['completed', 'cancelled'].indexOf(x.status) !== -1; }).slice(0, 10);

        html += '<div class="section-title">Your active matches</div><div class="card card-flat">';
        html += active.length ? '<div class="list">' + active.map(matchRow).join('') + '</div>' : '<div class="empty">No active matches.</div>';
        html += '</div>';

        var open = (state.matches && state.matches.open) || [];
        html += '<div class="section-title">Open public matches <a href="#" class="small" data-action="refresh-matches">Refresh</a></div><div class="card card-flat">';
        html += open.length ? '<div class="list">' + open.map(function (o) {
            return '<div class="row"><div class="row-icon">⚔️</div><div class="row-main"><div class="row-title">' + esc(o.creator) + '</div>' +
                '<div class="row-sub">' + esc(o.rounds) + ' rounds · ' + esc(o.dice_count) + ' dice</div></div>' +
                '<button class="btn btn-sm btn-primary" type="button" data-action="join-match" data-uuid="' + esc(o.uuid) + '">Join</button></div>';
        }).join('') + '</div>' : '<div class="empty">No open matches right now. Create one!</div>';
        html += '</div>';

        if (done.length) {
            html += '<div class="section-title">History</div><div class="card card-flat"><div class="list">' + done.map(matchRow).join('') + '</div></div>';
        }
        return html;
    }

    function matchRow(m) {
        var label = { waiting: 'Waiting for opponent', ready: 'Ready', playing: 'In progress', completed: 'Finished', cancelled: 'Cancelled' }[m.status] || m.status;
        var badge = m.status === 'completed'
            ? (m.result === 'won' ? '<span class="badge badge-ok">Won</span>' : (m.result === 'tie' ? '<span class="badge">Draw</span>' : '<span class="badge badge-danger">Lost</span>'))
            : (m.can_roll ? '<span class="badge badge-brand">Your turn</span>' : '<span class="badge">' + esc(label) + '</span>');
        return '<div class="row" data-action="open-match" data-uuid="' + esc(m.uuid) + '" style="cursor:pointer">' +
            '<div class="row-icon">' + (m.visibility === 'private' ? '🔒' : '⚔️') + '</div>' +
            '<div class="row-main"><div class="row-title">vs ' + esc(m.opponent ? m.opponent.name : '…') + '</div>' +
            '<div class="row-sub">' + esc(m.score.you) + '–' + esc(m.score.them) + ' · ' + esc(fmtDate(m.created_at)) + '</div></div>' + badge + '</div>';
    }

    function renderMatch() {
        var m = state.match;
        var html = subHeader('Dice duel');
        if (!m) return html + skeleton(2);

        var you = m.you || { name: 'You', initials: '?' };
        var them = m.opponent;
        html += '<section class="card card-hero"><div class="versus">' +
            '<div><div class="avatar">' + esc(you.initials) + '</div><div class="versus-name">' + esc(m.is_participant ? 'You' : you.name) + '</div></div>' +
            '<div class="versus-score">' + esc(m.score.you) + ' : ' + esc(m.score.them) + '</div>' +
            '<div><div class="avatar" style="opacity:' + (them ? 1 : .4) + '">' + esc(them ? them.initials : '?') + '</div><div class="versus-name">' + esc(them ? them.name : 'Waiting…') + '</div></div>' +
            '</div>';

        var status = '';
        if (m.status === 'waiting') status = m.is_creator ? 'Waiting for an opponent to join…' : 'Join this match to start playing.';
        else if (m.status === 'completed') status = m.result === 'won' ? '🏆 You won!' : (m.result === 'tie' ? '🤝 Draw' : (m.result === 'lost' ? 'You lost this one' : 'Match finished'));
        else if (m.status === 'cancelled') status = m.cancel_reason === 'expired' ? 'This match expired.' : 'This match was cancelled.';
        else if (m.can_roll) status = 'Round ' + m.current_round + ' of ' + m.total_rounds + ' – your roll!';
        else if (m.waiting_for_opponent) status = 'Waiting for ' + (them ? them.name : 'your opponent') + ' to roll…';
        html += '<p class="status-line">' + esc(status) + '</p>';

        if (m.status === 'completed' && m.reward_status === 'unfunded') html += '<p class="small muted center">Rewards were unavailable when this match ended (daily limit or budget).</p>';

        if (m.can_roll) html += '<button type="button" class="btn btn-primary btn-block btn-xl" data-action="roll-match"' + (state.rolling ? ' disabled' : '') + '>' + (state.rolling ? 'Rolling…' : 'Roll ' + m.dice_count + ' dice') + '</button>';
        if (m.can_join) html += '<button type="button" class="btn btn-primary btn-block btn-xl" data-action="join-current">Join match</button>';
        html += '</section>';

        if (m.invite_link) {
            html += '<section class="card"><h3 style="margin:0 0 8px">Invite your opponent</h3>' +
                '<div class="link-box"><code>' + esc(m.invite_link) + '</code><button class="btn btn-sm" type="button" data-action="copy" data-text="' + esc(m.invite_link) + '">Copy</button></div>' +
                '<div class="btn-row" style="margin-top:10px"><button class="btn btn-accent" type="button" data-action="share" data-url="' + esc(m.invite_link) + '" data-text="Let\'s roll some dice! ⚔️🎲">Share in Telegram</button>' +
                (m.can_cancel ? '<button class="btn btn-ghost" type="button" data-action="cancel-match">Cancel</button>' : '') + '</div></section>';
        }

        if (m.rounds.length) {
            html += '<div class="section-title">Rounds</div><div class="card card-flat">' +
                '<div class="round-row small muted"><div>#</div><div class="center">You</div><div class="center">' + esc(them ? them.name : 'Opponent') + '</div></div>' +
                m.rounds.map(function (r) {
                    var youWin = r.you && r.them && r.you.total > r.them.total;
                    var themWin = r.you && r.them && r.them.total > r.you.total;
                    return '<div class="round-row"><div class="muted">R' + esc(r.round) + '</div>' +
                        '<div class="round-cell' + (youWin ? ' win' : '') + '">' + (r.you ? diceInline(r.you.dice) + '<strong>' + esc(r.you.total) + '</strong>' : '<span class="muted">—</span>') + '</div>' +
                        '<div class="round-cell' + (themWin ? ' win' : '') + '">' + (r.them ? diceInline(r.them.dice) + '<strong>' + esc(r.them.total) + '</strong>' : '<span class="muted">—</span>') + '</div></div>';
                }).join('') + '</div>';
        }

        var rewardText = m.rules.reward_mode === 'fixed' ? 'Winner earns ' + m.rules.reward + ' USDT' : (m.rules.reward_mode === 'pooled' ? m.rules.pool + ' USDT pool split by rounds won' : 'No reward – just for fun');
        var tieText = { extra_round: 'extra rounds on a tie', draw: 'ties end in a draw', split: 'ties split the reward' }[m.rules.tie_rule] || '';
        html += '<p class="small muted center" style="margin-top:14px">' + esc(m.base_rounds) + ' rounds · ' + esc(m.dice_count) + ' dice · higher total wins the round · ' + esc(tieText) + '<br>' + esc(rewardText) + '</p>';
        return html;
    }

    // ---- Missions

    function renderMissions() {
        var html = '<h1 class="screen-title">' + esc(cfg.nav.missions) + '</h1>';
        var d = state.missions;
        if (!d) return html + skeleton(3);
        if (!d.enabled) return html + '<div class="banner banner-warn">Missions are currently unavailable.</div>';
        if (!d.missions.length) return html + '<div class="card empty">No missions right now. Check back soon!</div>';

        return html + d.missions.map(function (m) {
            var status = {
                available: '', started: '<span class="badge badge-brand">Started</span>',
                pending_review: '<span class="badge badge-warn">In review</span>',
                rewarded: '<span class="badge badge-ok">✓ Completed</span>',
                rejected: '<span class="badge badge-danger">Rejected</span>',
            }[m.status] || '';

            var progress = '';
            if (m.progress) {
                var pct = m.progress.target ? Math.min(100, Math.round(m.progress.current / m.progress.target * 100)) : 0;
                progress = '<div class="progress" aria-label="Progress"><span style="width:' + pct + '%"></span></div><div class="small muted">' + esc(m.progress.current) + ' / ' + esc(m.progress.target) + '</div>';
            }

            var actions = '';
            var done = m.status === 'rewarded' || m.status === 'pending_review';
            if (!done) {
                if (m.action_url) actions += '<button class="btn btn-sm btn-ghost" type="button" data-action="mission-open" data-id="' + m.id + '">Open</button>';
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
                proof = '<form class="proof-form" data-id="' + m.id + '" style="margin-top:10px">' +
                    '<textarea class="input" name="proof" maxlength="500" required placeholder="Your username / link so a moderator can verify"></textarea>' +
                    '<button class="btn btn-primary btn-block" style="margin-top:8px" type="submit">Send for review</button></form>';
            }

            return '<article class="card mission"><div class="mission-icon">' + (m.image_url ? '<img src="' + esc(m.image_url) + '" alt="">' : esc(m.icon || '🎯')) + '</div>' +
                '<div><p class="mission-title">' + esc(m.title) + '</p><p class="mission-desc">' + esc(m.description || '') + '</p>' + progress +
                (m.reject_reason ? '<p class="small" style="color:var(--danger)">Reason: ' + esc(m.reject_reason) + '</p>' : '') +
                '<div class="mission-footer"><span class="reward-chip">+' + esc(m.reward) + ' USDT</span>' + status +
                '<span class="btn-row" style="flex:0 0 auto">' + actions + '</span></div>' + proof +
                '<p class="small muted" style="margin:8px 0 0">' + esc(m.verification_label) + (m.repeat === 'daily' ? ' · resets daily' : '') + '</p></div></article>';
        }).join('') + footer();
    }

    // ---- Withdraw

    function renderWithdraw() {
        var html = '<h1 class="screen-title">' + esc(cfg.nav.withdraw) + '</h1>';
        var d = state.withdrawals;
        if (!d) return html + skeleton(3);
        var s = d.summary;

        html += '<section class="card card-hero"><div class="balance-label">Available to withdraw</div>' +
            '<div class="balance-value">' + esc(s.available) + ' <small>USDT</small></div>' +
            '<div class="small muted">In progress: ' + esc(s.reserved) + ' USDT · Min ' + esc(s.min) + ' · Max ' + esc(s.max) + '</div></section>';

        if (!s.enabled) {
            html += '<div class="banner banner-warn" style="margin-top:12px">Withdrawals are temporarily unavailable.</div>';
        } else if (s.eligibility) {
            html += '<div class="banner banner-warn" style="margin-top:12px">' + esc(s.eligibility) + '</div>';
        } else if (!s.networks.length) {
            html += '<div class="banner banner-warn" style="margin-top:12px">No payout network is available right now.</div>';
        } else {
            html += '<section class="card"><form id="withdraw-form" autocomplete="off" novalidate>' +
                '<label class="field"><span>Network</span><select class="input" name="network" id="wd-network">' +
                s.networks.map(function (n) { return '<option value="' + esc(n.code) + '">' + esc(n.name) + '</option>'; }).join('') + '</select>' +
                '<div class="field-hint" id="wd-network-hint"></div></label>' +
                '<label class="field"><span>Recipient full name</span><input class="input" name="full_name" maxlength="120" required autocomplete="name"></label>' +
                '<label class="field"><span>USDT wallet address</span><input class="input mono" name="address" maxlength="128" required spellcheck="false" autocapitalize="off"></label>' +
                '<label class="field"><span>Amount (USDT)</span><div class="input-group"><input class="input" name="amount" inputmode="decimal" placeholder="0.00" required>' +
                '<button class="btn btn-ghost" type="button" data-action="wd-max">Max</button></div></label>' +
                '<div class="summary-box small" id="wd-summary"></div>' +
                '<label class="field"><span>Note (optional)</span><textarea class="input" name="note" maxlength="500"></textarea></label>' +
                '<div class="banner banner-danger small">⚠️ ' + esc(s.warning) + '</div>' +
                '<label class="check"><input type="checkbox" name="confirm" value="1"> <span>I confirm the network and address are correct and compatible with USDT.</span></label>' +
                '<button class="btn btn-primary btn-block btn-xl" type="submit">Request withdrawal</button>' +
                '<p class="server-note">Requests are reviewed by our team. The amount is reserved until the request is paid or returned.</p>' +
                '</form></section>';
        }

        html += '<div class="section-title">History</div><div class="card card-flat">';
        if (!d.history.length) html += '<div class="empty">No withdrawals yet.</div>';
        else html += '<div class="list">' + d.history.map(function (w) {
            var cls = { paid: 'badge-ok', rejected: 'badge-danger', cancelled: '', pending: 'badge-warn', approved: 'badge-brand', processing: 'badge-brand' }[w.status] || '';
            return '<div class="row"><div class="row-icon">💸</div><div class="row-main">' +
                '<div class="row-title">' + esc(w.amount) + ' USDT · ' + esc(w.network) + '</div>' +
                '<div class="row-sub">' + esc(w.reference) + ' · ' + esc(w.address) + ' · ' + esc(fmtDate(w.created_at)) + '</div>' +
                (w.reject_reason ? '<div class="row-sub" style="color:var(--danger)">' + esc(w.reject_reason) + '</div>' : '') +
                (w.tx_hash ? '<div class="row-sub mono">Tx ' + esc(w.tx_hash.slice(0, 10)) + '…</div>' : '') +
                '</div><div style="text-align:right"><span class="badge ' + cls + '">' + esc(w.status_label) + '</span>' +
                (w.can_cancel ? '<br><button class="btn btn-sm btn-ghost" style="margin-top:6px" type="button" data-action="wd-cancel" data-ref="' + esc(w.reference) + '">Cancel</button>' : '') +
                '</div></div>';
        }).join('') + '</div>';
        return html + '</div>' + footer();
    }

    function updateWithdrawSummary() {
        var form = document.getElementById('withdraw-form');
        if (!form || !state.withdrawals) return;
        var nets = state.withdrawals.summary.networks;
        var net = nets.filter(function (n) { return n.code === form.network.value; })[0];
        var hint = document.getElementById('wd-network-hint');
        if (net) hint.textContent = 'Fee: ' + net.fee_fixed + ' USDT' + (Number(net.fee_percent) ? ' + ' + net.fee_percent + '%' : '') + ' · Min ' + net.min + (net.max ? ' · Max ' + net.max : '');

        var box = document.getElementById('wd-summary');
        var amount = toMicro(form.amount.value);
        if (!net || amount === null || amount === 0n) { box.innerHTML = '<span class="muted">Enter an amount to see the fee and the amount you receive.</span>'; return; }
        var fee = toMicro(net.fee_fixed) + amount * toMicro(net.fee_percent || '0') / 100000000n;
        var net_ = amount - fee;
        box.innerHTML = '<dl class="kv"><dt>Requested</dt><dd>' + esc(fromMicro(amount)) + ' USDT</dd><dt>Network fee (estimate)</dt><dd>' + esc(fromMicro(fee)) + ' USDT</dd>' +
            '<dt>You receive (estimate)</dt><dd>' + esc(net_ > 0n ? fromMicro(net_) : '0.00') + ' USDT</dd><dt>Network</dt><dd>' + esc(net.name) + '</dd></dl>';
    }

    // ---- Referrals

    function renderReferrals() {
        var html = subHeader('Invite friends');
        var r = state.referrals;
        if (!r) { load('referrals'); return html + skeleton(3); }

        html += '<section class="card card-hero"><h3 style="margin:0 0 6px">Your invite link</h3>' +
            '<div class="link-box"><code>' + esc(r.link) + '</code><button class="btn btn-sm" type="button" data-action="copy" data-text="' + esc(r.link) + '">Copy</button></div>' +
            '<button class="btn btn-primary btn-block" style="margin-top:12px" type="button" data-action="share-url" data-url="' + esc(r.share_url) + '">Share with friends</button>' +
            (r.active ? '' : '<p class="small" style="color:var(--warn)">The referral campaign is currently paused – new rewards are not being paid.</p>') +
            '</section>';

        html += '<div class="stats"><div class="stat"><strong>' + esc(r.direct_count) + '</strong><span>Invited</span></div>' +
            '<div class="stat"><strong>' + esc(r.qualified_count) + '</strong><span>Qualified</span></div>' +
            '<div class="stat"><strong>' + esc(r.earned) + '</strong><span>Earned USDT</span></div></div>';

        html += '<div class="section-title">How rewards work</div><section class="card">' +
            '<table class="levels"><thead><tr><th>Level</th><th>Bonus when qualified</th><th>Share of rewards</th></tr></thead><tbody>' +
            r.levels.map(function (l) { return '<tr><td>' + esc(l.level) + '</td><td>' + esc(l.fixed) + ' USDT</td><td>' + esc(l.percent) + '%</td></tr>'; }).join('') +
            '</tbody></table><p class="small muted">A friend qualifies after playing ' + esc(r.qualification.min_games) + ' games' +
            (r.qualification.min_age_hours ? ' and being a member for ' + esc(r.qualification.min_age_hours) + ' hours' : '') + '.</p>' +
            '<p class="small muted" style="margin:0">' + esc(r.disclosure) + '</p></section>';

        html += '<div class="section-title">Friends</div><div class="card card-flat">';
        html += r.friends.length ? '<div class="list">' + r.friends.map(function (f) {
            return '<div class="row"><div class="row-icon">👤</div><div class="row-main"><div class="row-title">' + esc(f.name) + '</div><div class="row-sub">Joined ' + esc(fmtDate(f.joined_at)) + '</div></div>' +
                (f.qualified ? '<span class="badge badge-ok">Qualified</span>' : '<span class="badge">Not yet</span>') + '</div>';
        }).join('') + '</div>' : '<div class="empty">Nobody yet – share your link!</div>';
        html += '</div>';

        html += '<div class="section-title">Referral rewards</div><div class="card card-flat">';
        html += r.rewards.length ? '<div class="list">' + r.rewards.map(function (x) {
            var badge = x.status === 'credited' ? '<span class="amount-in">+' + esc(x.amount) + '</span>' : '<span class="badge">' + esc(x.status) + '</span>';
            return '<div class="row"><div class="row-icon">L' + esc(x.level) + '</div><div class="row-main"><div class="row-title">' + (x.event === 'qualification' ? 'Qualification bonus' : 'Reward share') + '</div>' +
                '<div class="row-sub">' + esc(x.from || '') + ' · ' + esc(fmtDate(x.created_at)) + '</div></div>' + badge + '</div>';
        }).join('') + '</div>' : '<div class="empty">No referral rewards yet.</div>';
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
            el.disabled = true;
            api('POST', '/matches', { visibility: el.dataset.visibility }).then(function (res) {
                state.match = res.match;
                haptic('success');
                openSub('match');
                startPolling();
            }).catch(function (e) { el.disabled = false; showError(e); });
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
            confirmDialog('Cancel this match?', function () {
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
            el.disabled = true;
            api('POST', '/missions/' + el.dataset.id + '/claim', {}).then(function (res) {
                haptic('success'); toast(res.message, 'success');
                return load('missions').then(function () { return load('home'); });
            }).catch(function (e) { el.disabled = false; showError(e); });
        },
        'mission-proof': function (el) { state.proofOpen[el.dataset.id] = true; render(); },

        'wd-max': function () {
            var f = document.getElementById('withdraw-form');
            f.amount.value = state.withdrawals.summary.available_exact.replace(/\.?0+$/, '');
            updateWithdrawSummary();
        },
        'wd-cancel': function (el) {
            confirmDialog('Cancel this withdrawal request? The amount returns to your balance.', function () {
                api('POST', '/withdrawals/' + encodeURIComponent(el.dataset.ref) + '/cancel', {}).then(function () {
                    toast('Request cancelled', 'success'); load('withdraw'); load('home');
                }).catch(showError);
            });
        },
        'more-tx': function () {
            var next = state.allTx.next_page;
            api('GET', '/transactions?page=' + next).then(function (d) {
                state.allTx.data = state.allTx.data.concat(d.data);
                state.allTx.next_page = d.next_page;
                render();
            }).catch(showError);
        },
    };

    function findMission(id) {
        return state.missions.missions.filter(function (m) { return String(m.id) === String(id); })[0];
    }

    function confirmDialog(message, onYes) {
        if (tg && tg.showConfirm && tg.platform !== 'unknown') {
            tg.showConfirm(message, function (ok) { if (ok) onYes(); });
        } else if (window.confirm(message)) {
            onYes();
        }
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
        if (el) el.disabled = true;
        api('POST', '/matches/' + uuid + '/join', { code: code }).then(function (res) {
            state.match = res.match;
            state.matchCode = null;
            haptic('success');
            if (state.sub !== 'match') openSub('match'); else render();
            startPolling();
        }).catch(function (e) { if (el) el.disabled = false; showError(e); });
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
        if (e.target.closest('#withdraw-form')) updateWithdrawSummary();
    });
    document.addEventListener('change', function (e) {
        if (e.target.closest('#withdraw-form')) updateWithdrawSummary();
    });

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
            if (!payload.confirm) { toast('Please confirm the network and address.', 'error'); return; }
            confirmDialog('Withdraw ' + payload.amount + ' USDT via ' + payload.network + ' to ' + payload.address.slice(0, 6) + '…' + payload.address.slice(-6) + '?', function () {
                var btn = form.querySelector('[type=submit]');
                btn.disabled = true;
                form.dataset.key = form.dataset.key || newKey();
                api('POST', '/withdrawals', payload, { key: form.dataset.key }).then(function (res) {
                    haptic('success');
                    toast('Request ' + res.withdrawal.reference + ' submitted', 'success');
                    load('withdraw'); load('home');
                }).catch(function (err) {
                    btn.disabled = false;
                    if (err.status !== 0) delete form.dataset.key;
                    showError(err);
                });
            });
        }

        if (form.id === 'invite-form') {
            var invite = form.invite.value.trim();
            if (!invite) return;
            api('POST', '/matches', { visibility: 'private', invite: invite }).then(function (res) {
                toast('Invitation sent!', 'success');
                state.match = res.match;
                openSub('match');
                startPolling();
            }).catch(showError);
        }

        if (form.classList.contains('proof-form')) {
            var id = form.dataset.id;
            api('POST', '/missions/' + id + '/claim', { proof: form.proof.value.trim() }).then(function (res) {
                delete state.proofOpen[id];
                toast(res.message, 'success');
                load('missions');
            }).catch(showError);
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
            if (tg.BackButton) tg.BackButton.onClick(function () { closeSub(); });
        }

        authenticate().then(function () {
            document.getElementById('app').classList.remove('is-booting');
            document.getElementById('boot').hidden = true;
            document.getElementById('topbar').hidden = false;
            document.getElementById('tabbar').hidden = false;

            return load('home').then(function () {
                if (!handleDeepLink()) { updateChrome(); render(); }
                if (state.newUser) toast('Welcome! 🎲 Roll doubles to earn your first reward.', 'success');
            });
        }).catch(function (err) {
            document.getElementById('boot-text').textContent = err.message || 'Could not connect.';
            document.querySelector('.boot .spinner').hidden = true;
            if (cfg.bot_url && err.code === 'no_telegram') {
                var a = document.createElement('a');
                a.className = 'btn btn-primary'; a.href = cfg.bot_url; a.textContent = 'Open in Telegram';
                document.getElementById('boot').appendChild(a);
            }
        });
    }

    boot();
})();
