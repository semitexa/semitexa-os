/*
 * Semitexa OS shell runtime.
 *
 * The whole desktop shell — screens (awakening/snapshot/OS), Ambient/Focus/
 * Chill modes, intent bar, chat dock, launcher, weave graph, window manager
 * bridge — extracted verbatim from the former 1.6k-line inline <script> in
 * shell.html.twig so the browser can cache it (ETag via the static asset
 * handler) and tooling can lint/test it.
 *
 * Boot contract: shell.html.twig renders a <script type="application/json"
 * id="os-boot"> tag with the server-projected boot payload BEFORE this file's
 * deferred include; the IIFE reads it at startup. No Twig interpolation may
 * ever live in this file — everything server-side goes through #os-boot.
 */
    (function () {
        const boot = JSON.parse(document.getElementById('os-boot').textContent || '{}');
        if (!boot.assistantName) boot.assistantName = 'Semi'; // the name the user calls the assistant

        // i18n: server-resolved UI strings for the OS locale ride in on the
        // boot payload (#os-boot .strings); the inline English literal is the
        // fallback so a missing key/pack can never blank the UI.
        const STR = (boot.strings && typeof boot.strings === 'object') ? boot.strings : {};
        function t(key, fallback) { return typeof STR[key] === 'string' ? STR[key] : fallback; }

        // ---------- window-hosting mode ----------
        // Two independent facts decide whether a Window-kind dialog is hosted
        // in-page (an iframe) or promoted to a REAL native window:
        //   1. the install PERMITS os mode  — SEMITEXA_WINDOW_MODE=os (boot.windowMode)
        //   2. this client IS the desktop    — ?desktop=1, the flag semitexa-wm
        //      launches the shell with (it keys off the title to keep the window
        //      frameless/fullscreen). A plain browser never has it.
        // Only when BOTH hold do we even consider promoting — and even then not
        // until the local bridge actually answers (probeBridge). Until proven,
        // S.windowMode stays 'web', so a browser hitting an os-mode install (or a
        // desktop whose bridge is down) degrades gracefully to iframes.
        const DESKTOP = new URLSearchParams(location.search).has('desktop');
        if (DESKTOP) document.title = 'SemitexaDesktop';
        const OS_PERMITTED = boot.windowMode === 'os' && DESKTOP;
        const BRIDGE = boot.bridgeUrl || 'http://127.0.0.1:8777';
        // The bridge requires a shared token on every side-effecting call
        // (blind cross-origin GETs fire regardless of CORS; the token is the
        // one thing a remote page can't obtain). /token only answers loopback
        // origins like this shell. Fetched once, then attached as a header.
        let bridgeTokenPromise = null;
        const bridgeToken = () => bridgeTokenPromise ??= fetch(BRIDGE + '/token')
            // A 403 carries a parseable JSON error body — only r.ok separates
            // it from a real grant, so reject it into the catch below.
            .then(r => { if (!r.ok) throw new Error('token refused'); return r.json(); })
            .then(d => {
                if (typeof d?.token !== 'string' || !d.token) throw new Error('no token');
                return d.token;
            })
            // A failure (bridge not up yet, old bridge without /token,
            // refused) must not be cached forever — drop the memo so the
            // next call retries.
            .catch(() => { bridgeTokenPromise = null; return ''; });
        async function bridgeFetch(path, opts = {}) {
            const t = await bridgeToken();
            opts.headers = Object.assign({}, opts.headers, t ? { 'X-Bridge-Token': t } : {});
            return fetch(BRIDGE + path, opts);
        }
        const isOsMode = () => S.windowMode === 'os';
        const root = document.getElementById('root');
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
        const ico = (n, w) => '<i data-lucide="' + n + '" style="width:' + (w||18) + 'px;height:' + (w||18) + 'px;stroke-width:1.5"></i>';
        const fmtTime = (d) => d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const fmtDate = (d) => d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });

        // Once the user has awakened into a session, a reload should return them
        // straight to their conversation — not back through the Awakening ritual.
        const RESUMED = (() => { try { return localStorage.getItem('semitexa_os_session') === '1'; } catch (e) { return false; } })();
        const markSession = () => { try { localStorage.setItem('semitexa_os_session', '1'); } catch (e) {} };
        // The compact chat dock (Focus/Chill) remembers open/collapsed across reloads.
        const CHATDOCK = (() => { try { return localStorage.getItem('semitexa_os_chatdock') === 'collapsed' ? 'collapsed' : 'open'; } catch (e) { return 'open'; } })();
        const setChatDock = (v) => { S.chatDock = v; try { localStorage.setItem('semitexa_os_chatdock', v); } catch (e) {} };
        // Light/dark preference: 'auto' | 'dark' | 'light'. localStorage gives an
        // instant (no-flash) value on boot; the server (/os/preferences) reconciles.
        const THEMEMODE = (() => { try { const v = localStorage.getItem('semitexa_os_theme_mode'); return (v === 'dark' || v === 'light' || v === 'auto') ? v : 'auto'; } catch (e) { return 'auto'; } })();
        // Ambient sub-view: 'chat' (Планування — the intent conversation) or
        // 'workspace' (the 3D Weave force-graph). Persisted across reloads.
        const AMBVIEW = (() => { try { return localStorage.getItem('semitexa_os_ambient_view') === 'workspace' ? 'workspace' : 'chat'; } catch (e) { return 'chat'; } })();
        // Cursor for the proactive-message poll — the last-seen turn id, so the
        // assistant announces each unprompted message (e.g. a task auto-completed) once.
        let PROACTIVE_CURSOR = (() => { try { return localStorage.getItem('semitexa_os_proactive') || ''; } catch (e) { return ''; } })();
        const setProactiveCursor = (v) => { PROACTIVE_CURSOR = v; try { localStorage.setItem('semitexa_os_proactive', v); } catch (e) {} };
        // Input layouts: enabled layouts (with keymaps), active code, switch
        // hotkey — server-owned (chat skills mutate it), shape from
        // InputLayoutStore::state(). Normalise defensively so a missing/partial
        // boot payload still leaves a working pass-through layout.
        function normalizeInputLayouts(raw) {
            const d = (raw && typeof raw === 'object') ? raw : {};
            let layouts = Array.isArray(d.layouts) ? d.layouts.filter(l => l && l.code && l.keymap) : [];
            if (!layouts.length) layouts = [{ code: 'en', label: 'English', short: 'EN', keymap: { normal: {}, shift: {}, altgr: {} } }];
            const active = layouts.some(l => l.code === d.active) ? d.active : layouts[0].code;
            const hk = (d.hotkey && Array.isArray(d.hotkey.modifiers) && d.hotkey.modifiers.length) ? d.hotkey : { modifiers: ['alt', 'shift'], key: null };
            return { layouts, active, hotkey: { modifiers: hk.modifiers, key: hk.key || null }, hotkeyLabel: d.hotkey_label || 'Alt+Shift' };
        }

        const S = {
            screen: RESUMED ? 'os' : 'awakening', awake: RESUMED ? 'snapshot' : 'idle',
            mode: 'ambient', xray: false, themeMode: THEMEMODE, ambientView: AMBVIEW,
            run: null,            // { intent, stage, decision, skill, policy, reason, message, surfaces:[] }
            surfaces: [],
            units: [],            // real skills in flight for the current/last run: {name,status,busy,exit}
            log: [{ t: 'os:session · ready', tone: 'mute' }],
            cue: null, busy: false, dialogs: [], pos: {}, recents: [], webApps: [],
            inputLayouts: normalizeInputLayouts(boot.inputLayouts), // see normalizeInputLayouts
            layoutMenu: false, // the topbar layout dropdown
            chillApps: {}, // remembered leisure apps: activity => skill (synced from /os/preferences)
            launchQuery: '',      // Focus launcher type-to-filter text
            windowMode: 'web', // resolved live: 'os' only once the bridge answers (see probeBridge)
            chatDock: CHATDOCK, // 'open' | 'collapsed' — the Focus/Chill side chat
            proactiveUnseen: 0, // proactive messages arrived while the dock was collapsed
            processes: [], // the OS process registry (/os/process/list) — anything reporting work, any origin
            input: '', clock: new Date(), snapshot: null,
            history: [],          // persisted dialog transcript (both sides) shown as the Ambient chat
            queue: [],            // messages sent but not yet answered — shown instantly, processed one at a time
        };
        let sid = 1;
        // Set light/dark on <html> before the first paint (no flash), and react
        // live to OS colour-scheme changes while in auto.
        applyThemeMode();
        try { window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (S.themeMode === 'auto') applyThemeMode(); }); } catch (e) {}

        function blip(f) { try { const C = window.AudioContext||window.webkitAudioContext; if(!C) return; const a = blip._a||(blip._a=new C()); const o=a.createOscillator(),g=a.createGain(); o.type='sine'; o.frequency.value=f||660; o.connect(g); g.connect(a.destination); const t=a.currentTime; g.gain.setValueAtTime(.0001,t); g.gain.exponentialRampToValueAtTime(.05,t+.03); g.gain.exponentialRampToValueAtTime(.0001,t+.42); o.start(t); o.stop(t+.44);} catch(e){} }
        function log(t, tone) { S.log = [{ t, tone: tone||'mute' }, ...S.log].slice(0, 9); }
        function drawIcons() { let n=0; (function r(){ if(window.lucide&&window.lucide.createIcons) window.lucide.createIcons(); else if(n++<40) setTimeout(r,120); })(); }

        // ---------- light / dark mode ----------
        // Resolve the preference to a concrete 'light'|'dark'. 'auto' follows the OS
        // colour-scheme; where the environment expresses no dark preference (e.g. the
        // native desktop's bare chromium), fall back to time-of-day so day/night still
        // works. The whole shell themes off data-skin-mode + color-scheme on <html>.
        function resolveThemeMode(mode) {
            if (mode === 'light' || mode === 'dark') return mode;
            try { if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark'; } catch (e) {}
            const h = new Date().getHours();
            return (h >= 19 || h < 7) ? 'dark' : 'light';
        }
        function applyThemeMode() {
            const eff = resolveThemeMode(S.themeMode);
            const el = document.documentElement;
            if (el.getAttribute('data-skin-mode') !== eff) el.setAttribute('data-skin-mode', eff);
            if (el.style.colorScheme !== eff) el.style.colorScheme = eff;
        }
        // Set + persist the preference (localStorage for instant boot, the server so
        // it survives / syncs and the chat command shares one source of truth).
        function setThemeMode(mode) {
            if (mode !== 'auto' && mode !== 'dark' && mode !== 'light') return;
            S.themeMode = mode;
            try { localStorage.setItem('semitexa_os_theme_mode', mode); } catch (e) {}
            applyThemeMode();
            post('/os/preferences', { theme_mode: mode });
        }

        // ---------- input layouts ----------
        // The active layout remaps physical keys (KeyboardEvent.code) to the
        // language's characters while typing in shell inputs — on the VM the
        // browser IS the desktop, so this is the OS's layout mechanism. Layouts
        // are enabled via chat ("додай німецьку розкладку"); switching is the
        // topbar badge (mouse) or the configurable hotkey (set-layout-hotkey).
        const activeLayout = () => S.inputLayouts.layouts.find(l => l.code === S.inputLayouts.active) || S.inputLayouts.layouts[0];
        function setInputLayout(code) {
            if (!S.inputLayouts.layouts.some(l => l.code === code) || code === S.inputLayouts.active) return;
            S.inputLayouts.active = code;
            post('/os/preferences', { input_layout: code });
        }
        function cycleInputLayout() {
            const ls = S.inputLayouts.layouts;
            if (ls.length < 2) return;
            const i = ls.findIndex(l => l.code === S.inputLayouts.active);
            setInputLayout(ls[(i + 1) % ls.length].code);
            blip(700);
            // Re-render only outside a text field: a re-render would steal focus
            // mid-typing. The badge is patched in place instead.
            const el = document.activeElement;
            const typing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
            if (typing) { const b = root.querySelector('.os-layoutbtn__code'); if (b) b.textContent = activeLayout().short; }
            else render();
        }
        // Does this keydown complete the switch hotkey? Modifier-only combos
        // (Alt+Shift) fire when the last modifier goes down; key combos
        // (Ctrl+Space) fire on the trigger key with exactly those modifiers.
        function isLayoutHotkey(e) {
            const hk = S.inputLayouts.hotkey;
            const want = { ctrl: hk.modifiers.indexOf('ctrl') !== -1, alt: hk.modifiers.indexOf('alt') !== -1, shift: hk.modifiers.indexOf('shift') !== -1, meta: hk.modifiers.indexOf('meta') !== -1 };
            const have = { ctrl: e.ctrlKey, alt: e.altKey, shift: e.shiftKey, meta: e.metaKey };
            if (hk.key) {
                if (e.code !== hk.key) return false;
                return want.ctrl === have.ctrl && want.alt === have.alt && want.shift === have.shift && want.meta === have.meta;
            }
            const MOD = { Control: 'ctrl', Alt: 'alt', Shift: 'shift', Meta: 'meta' };
            const pressed = MOD[e.key];
            if (!pressed || !want[pressed]) return false;
            return want.ctrl === have.ctrl && want.alt === have.alt && want.shift === have.shift && want.meta === have.meta;
        }
        // Remap a printable keydown through the active layout's keymap. Returns
        // the mapped character or null (pass through untouched).
        function mapKey(e) {
            const km = activeLayout().keymap;
            if (!km) return null;
            const altgr = (e.getModifierState && e.getModifierState('AltGraph')) || (e.altKey && !e.ctrlKey);
            let ch = null, letterBase = false;
            if (altgr) {
                ch = (km.altgr || {})[e.code] || null;
                if (ch && e.shiftKey) letterBase = true;
            } else if (e.ctrlKey || e.metaKey) {
                return null; // never eat shortcuts
            } else if (e.shiftKey) {
                ch = (km.shift || {})[e.code] || null;
                if (!ch) { ch = (km.normal || {})[e.code] || null; letterBase = !!ch; }
            } else {
                ch = (km.normal || {})[e.code] || null;
            }
            if (!ch) return null;
            // Case: shift uppercases a letter taken from a lower level; CapsLock
            // inverts letter case on top of that.
            let upper = letterBase;
            try { if (e.getModifierState && e.getModifierState('CapsLock') && ch.toLocaleLowerCase() !== ch.toLocaleUpperCase()) upper = !upper; } catch (err) {}
            return upper ? ch.toLocaleUpperCase() : ch;
        }
        // One global keydown does both jobs: switch on the hotkey, remap
        // printable keys while focus is in an editable field. Capture phase so
        // the remap wins before any scoped Enter/Escape handlers see the event
        // (those keys are never remapped, so they are unaffected).
        document.addEventListener('keydown', (e) => {
            if (isLayoutHotkey(e)) { e.preventDefault(); cycleInputLayout(); return; }
            const el = e.target;
            const editable = el && (((el.tagName === 'INPUT' && /^(text|search|url|email|password)?$/.test(el.type || 'text')) || el.tagName === 'TEXTAREA') || el.isContentEditable);
            if (!editable) return;
            const ch = mapKey(e);
            if (ch == null) return;
            e.preventDefault();
            if (el.isContentEditable) {
                try { document.execCommand('insertText', false, ch); } catch (err) {}
                return;
            }
            const s = el.selectionStart == null ? el.value.length : el.selectionStart;
            const en = el.selectionEnd == null ? s : el.selectionEnd;
            el.setRangeText(ch, s, en, 'end');
            el.dispatchEvent(new Event('input', { bubbles: true })); // setRangeText fires no input event
        }, true);
        // The topbar layout badge — hidden with a single layout, a dropdown of
        // enabled layouts when clicked (the mouse path to switching).
        function layoutSwitchHTML() {
            const ls = S.inputLayouts.layouts;
            if (ls.length < 2) return '';
            const cur = activeLayout();
            const items = ls.map(l => '<button class="os-layoutitem' + (l.code === cur.code ? ' active' : '') + '" data-act="set-layout" data-code="' + esc(l.code) + '">'
                + '<span class="os-layoutitem__code">' + esc(l.short) + '</span>' + esc(l.label)
                + (l.code === cur.code ? ico('check', 14) : '') + '</button>').join('');
            return '<div class="os-layoutswitch">'
                + '<button class="os-layoutbtn" data-act="layout-menu" title="' + t('switch_layout', 'Switch input layout') + ' · ' + esc(S.inputLayouts.hotkeyLabel) + '">'
                + ico('keyboard', 15) + '<span class="os-layoutbtn__code">' + esc(cur.short) + '</span></button>'
                + (S.layoutMenu
                    ? '<div class="os-layoutmenu">' + items
                        + '<div class="os-layoutmenu__hint">' + esc(S.inputLayouts.hotkeyLabel) + '</div></div>'
                    : '')
                + '</div>';
        }

        // ---------- screens ----------
        function awakeningHTML() {
            let cap = '';
            if (S.awake === 'idle') cap = '<div class="os-awaken__cap"><h1>' + t('hi', '"Hi, ') + esc(boot.assistantName) + '"</h1><p>' + t('touch_to_begin', 'Touch to begin.') + '</p></div>';
            else if (S.awake === 'auth') cap = '<div class="os-awaken__auth">' + t('recognising_voice', 'Recognising voice biometrics…') + '</div>';
            else if (S.awake === 'snapshot') cap = snapshotHTML();
            return '<div class="os-awaken">'
                + '<div class="os-awaken__grid"></div>'
                + '<div class="os-orb" data-act="awaken"><div class="os-orb__ring"></div><div class="os-orb__ring delay"></div>'
                + '<div class="os-orb__core">' + ico('mic', 46) + '</div></div>'
                + cap + '</div>';
        }
        function snapshotHTML() {
            const s = S.snapshot || {};
            const has = (s.count || 0) > 0;
            const trim = (t) => (t && t.length > 48) ? t.slice(0, 46) + '…' : (t || '');
            let rows = '<div class="os-snapshot__row"><span class="dot"></span> ' + t('local_llm_deployment', 'Local LLM deployment') + ' · <span class="sub">' + esc(boot.providerName) + ' · ' + esc(boot.providerModel) + '</span></div>';
            if (has) {
                if (s.last_skill) rows += '<div class="os-snapshot__row"><span class="dot"></span> ' + t('last_skill', 'Last skill') + ' · <span class="sub">' + esc(s.last_skill) + '</span></div>';
                if (s.last_intent) rows += '<div class="os-snapshot__row"><span class="dot"></span> ' + t('last_intent', 'Last intent') + ' · <span class="sub">"' + esc(trim(s.last_intent)) + '"</span></div>';
                rows += '<div class="os-snapshot__row"><span class="dot mute"></span> os:session · <span class="sub">' + s.count + ' ' + t('actions_on_device', 'actions on this device') + '</span></div>';
            } else {
                rows += '<div class="os-snapshot__row"><span class="dot mute"></span> os:session · <span class="sub">' + t('no_prior_actions', 'no prior actions yet') + '</span></div>';
            }
            return '<div class="os-snapshot">'
                + '<div class="os-snapshot__eyebrow">' + ico('history') + ' ' + t('state_snapshot', 'State Snapshot') + ' · ' + (has ? t('snapshot_restored', 'restored from var/os/session') : t('new_device', 'new device')) + '</div>'
                + '<div class="os-snapshot__title">' + (has ? t('continue_where_you_were', 'Continue where you were') : t('a_clean_slate', 'A clean slate')) + '</div>'
                + '<div class="os-snapshot__rows">' + rows + '</div>'
                + '<div class="os-snapshot__actions">'
                + '<button class="os-btn-primary grow" data-act="enter-continue">' + (has ? t('continue_session', 'Continue session') : t('enter', 'Enter')) + ' ' + ico('arrow-right') + '</button>'
                + '<button class="os-btn-ghost" data-act="enter-fresh">' + t('fresh_start', 'Fresh start') + '</button>'
                + '</div></div>';
        }

        // A three-way light/dark switch: Light · Dark · Auto (follows the system).
        function themeSwitchHTML() {
            const opt = (m, icon, label) => '<button class="os-themebtn' + (S.themeMode === m ? ' active' : '') + '" data-act="theme-mode" data-mode="' + m + '" title="' + label + t('theme_suffix', ' theme') + '" aria-label="' + label + t('theme_suffix', ' theme') + '">' + ico(icon, 15) + '</button>';
            return '<div class="os-themeswitch">'
                + opt('light', 'sun', t('theme_light', 'Light'))
                + opt('dark', 'moon', t('theme_dark', 'Dark'))
                + opt('auto', 'monitor', t('theme_auto', 'Auto'))
                + '</div>';
        }
        function topbarHTML() {
            const modes = ['ambient','focus','chill'];
            const labels = { ambient: t('mode_ambient', 'Ambient'), focus: t('mode_focus', 'Focus'), chill: t('mode_chill', 'Chill') };
            const btns = modes.map(m => '<button class="os-modebtn' + (S.mode===m?' active':'') + '" data-act="mode" data-mode="' + m + '">' + labels[m] + '</button>').join('');
            return '<header class="os-topbar">'
                + '<button class="os-topbar__clock" data-act="open-calendar" title="' + t('open_calendar', 'Open calendar') + '">'
                + '<span class="os-clock__ico">' + ico('calendar-days', 20) + '</span>'
                + '<span class="os-clock__text"><span class="os-clock__time">' + fmtTime(S.clock) + '</span>'
                + '<span class="os-clock__date">' + fmtDate(S.clock) + '</span></span>'
                + '</button>'
                + '<div class="os-modeswitch">' + btns + '</div>'
                + '<div class="os-topbar__right">' + layoutSwitchHTML() + themeSwitchHTML()
                + '<button class="os-xray-toggle' + (S.xray?' active':'') + '" data-act="xray">' + ico('scan-eye',16) + t('xray_label', ' X-Ray') + '</button>'
                + '</div></header>';
        }

        // ---------- ambient + loop ----------
        function stageCards() {
            const order = ['intent','plan','execute','observe'];
            const meta = {
                intent: { label: t('stage_intent', 'Intent'), sub: S.run ? '"' + S.run.intent + '"' : t('stage_intent_sub', 'voice / text') },
                plan:   { label: t('stage_plan', 'Plan'), sub: t('stage_plan_sub', 'Planner over the SkillManifest') },
                execute:{ label: t('stage_execute', 'Execute'), sub: t('stage_execute_sub', 'SkillExecutor · headless') },
                observe:{ label: t('stage_observe', 'Observe'), sub: t('stage_observe_sub', 'live state · surface on demand') },
            };
            const cur = S.run ? S.run.stage : null;
            const rank = { intent:0, plan:1, ask:1, refuse:1, confirm:1, execute:2, observe:3 };
            return order.map(k => {
                let cls = 'os-stagecard';
                const ck = rank[cur] != null ? rank[cur] : -1;
                const kk = rank[k];
                if (ck === kk) cls += ' active';
                else if (ck > kk) cls += ' done';
                return '<div class="' + cls + '"><div class="os-stagecard__key">' + k + '</div>'
                    + '<div class="os-stagecard__label">' + meta[k].label + '</div>'
                    + '<div class="os-stagecard__sub">' + esc(meta[k].sub) + '</div></div>';
            }).join('');
        }
        function decisionPill() {
            const d = S.run.decision || S.run.stage;
            const map = { answer:'answer', ask:'ask', refuse:'refuse', needs_confirmation:'skill', confirm:'skill', executed:'execute', execute:'execute', plan:'', skill:'skill', dialog_exists:'ask' };
            const cls = map[d] || '';
            const label = ({ plan: t('decision_planning', 'planning…'), ask: t('decision_ask', 'ask (clarify)'), refuse: t('decision_refuse', 'refuse (safety stop)'), needs_confirmation: t('decision_propose', 'propose_skill'), confirm: t('decision_propose', 'propose_skill'), answer: t('decision_answer', 'answer'), executed: t('decision_executed', 'executed'), execute: t('executing', 'executing…'), dialog_exists: t('decision_already_open', 'already open') })[d] || d;
            return '<div class="os-pill ' + cls + '"><span class="dot"></span>' + t('planner_arrow', 'planner → ') + esc(label) + '</div>';
        }
        function gateHTML() {
            const r = S.run; const st = r.stage;
            if (st === 'ask') return '<div class="os-gate"><div class="os-ask"><div class="os-ask__q">' + esc(r.message || t('could_you_clarify', 'Could you clarify that?')) + '</div><div style="margin-top:16px"><button class="os-btn-sm" data-act="dismiss">' + t('dismiss', 'Dismiss') + '</button></div></div></div>';
            if (st === 'refuse') return '<div class="os-gate"><div class="os-refuse"><div class="os-refuse__eyebrow">' + ico('shield-x') + t('safety_stop', ' safety stop') + '</div><div class="os-refuse__reason">' + esc(r.message || r.reason || t('action_refused', 'This action was refused.')) + '</div><div style="margin-top:16px"><button class="os-btn-sm" data-act="dismiss">' + t('understood', 'Understood') + '</button></div></div></div>';
            if (st === 'dialog_exists') return '<div class="os-gate"><div class="os-ask"><div class="os-ask__q">' + esc(r.message || ((r.skill || t('that_tool', 'That tool')) + t('already_open_suffix', ' is already open.'))) + '</div><div class="os-gate__actions" style="margin-top:16px"><button class="os-btn-go" data-act="dlg-switch" data-skill="' + esc(r.skill || '') + '">' + ico('arrow-right',16) + t('switch_to_open', ' Switch to the open one') + '</button><button class="os-btn-sm" data-act="dlg-open-another" data-skill="' + esc(r.skill || '') + '">' + t('open_another', 'Open another') + '</button><button class="os-btn-sm" data-act="dismiss">' + t('cancel', 'Cancel') + '</button></div></div></div>';
            if (st === 'confirm') {
                const isPipe = Array.isArray(r.pipeline) && r.pipeline.length > 1;
                const chipItems = isPipe ? r.pipeline.map(s => s.skill) : (r.skill ? [r.skill] : []);
                const chips = chipItems.map((p, i) => '<span class="os-pipe__chip">' + (isPipe ? (i + 1) + '. ' : '') + esc(p) + '</span>').join(isPipe ? '<span style="color:#6f7d99">→</span>' : '');
                const heading = isPipe ? (t('pipeline_prefix', 'Pipeline · ') + r.pipeline.length + t('skills_suffix', ' skills')) : (r.skill || t('skill_label', 'Skill'));
                return '<div class="os-gate" style="max-width:720px"><div class="os-confirm">'
                    + '<div class="os-confirm__head"><div class="os-confirm__skill">' + esc(heading) + '</div>'
                    + '<div class="os-confirm__policy">' + esc(t('risk_prefix', 'risk · ') + (r.policy || 'medium')) + '</div></div>'
                    + '<div class="os-confirm__sum">' + esc(r.reason || r.message || t('run_this_skill', 'Run this skill?')) + '</div>'
                    + (chips ? '<div class="os-pipe">' + chips + '</div>' : '')
                    + '<div class="os-gate__actions"><button class="os-btn-go" data-act="approve">' + ico('check',17) + t('approve_run', ' Approve &amp; run') + '</button>'
                    + '<button class="os-btn-sm" data-act="dismiss">' + t('cancel', 'Cancel') + '</button></div></div></div>';
            }
            if (st === 'execute') {
                const u = S.units.map(x => '<div class="os-unit' + (x.busy?' busy':'') + '"><div class="os-unit__head">' + ico('box',20) + '<span class="os-unit__name">' + esc(x.name) + '</span><span class="os-unit__dot"></span></div><div class="os-unit__status">' + esc(x.status) + (x.exit != null && x.status !== 'running' ? ' · exit ' + x.exit : '') + '</div></div>').join('');
                return '<div class="os-units">' + (u || '<div class="os-unit"><div class="os-unit__status">' + t('executing', 'executing…') + '</div></div>') + '</div>';
            }
            return '';
        }
        function surfaceHTML(sf) {
            const head = (extra) => '<div class="os-surf-head">' + extra + '<span class="title">' + esc(sf.title) + '</span><span class="os-taxo">' + esc(sf.taxo) + '</span><button class="os-surf-close" data-act="close-surface" data-id="' + sf.id + '">' + ico('x',16) + '</button></div>';
            if (sf.kind === 'window') {
                return '<div class="os-surface"><div class="os-surf--window"><div class="bar"><span class="dots"><i style="background:#ff6b82"></i><i style="background:#f5c451"></i><i style="background:#37b7ff"></i></span><span class="wtitle">' + esc(sf.title) + '</span><span class="os-taxo">' + esc(sf.taxo) + '</span><button class="os-surf-close" data-act="close-surface" data-id="' + sf.id + '">' + ico('x',16) + '</button></div><div class="body"><pre>' + esc(sf.body) + '</pre>' + (sf.meta?'<div style="font-family:var(--font-mono);font-size:13px;color:#6f7d99;margin-top:12px">' + esc(sf.meta) + '</div>':'') + '</div></div></div>';
            }
            if (sf.kind === 'readout') {
                const rows = (sf.rows||[]).map(r => '<div class="os-readout-row"><span class="k">' + esc(r[0]) + '</span><span class="v">' + esc(r[1]) + '</span></div>').join('');
                return '<div class="os-surface"><div class="os-surf--readout">' + head(ico('activity')) + '<div class="os-readout-rows">' + rows + '</div></div></div>';
            }
            return '<div class="os-surface"><div class="os-surf--inline">' + head(ico('sparkles')) + '<div class="text">' + esc(sf.body) + '</div>' + (sf.meta?'<div class="meta">' + esc(sf.meta) + '</div>':'') + '</div></div>';
        }

        function ambientHTML() {
            let inner = '';
            const hasThread = ((S.history && S.history.length > 0) || (S.queue && S.queue.length > 0));
            if (!S.run && S.surfaces.length === 0 && !hasThread) {
                // Empty state — a warm greeting. Everything the user types (even
                // introducing themselves) then flows through the model.
                let greet, sub;
                if (boot.userName) {
                    const hh = new Date().getHours();
                    const g = hh < 5 ? t('still_up', 'Still up') : hh < 12 ? t('good_morning', 'Good morning') : hh < 18 ? t('good_afternoon', 'Good afternoon') : t('good_evening', 'Good evening');
                    greet = g + ', ' + esc(boot.userName) + '.';
                    sub = t('tell_prefix', 'Tell ') + esc(boot.assistantName) + t('tell_suffix', ' what you want. A surface is raised only when the intent needs space.');
                } else {
                    greet = t('hi_im', 'Hi, I\'m ') + esc(boot.assistantName) + '.';
                    sub = t('what_can_i_do', 'What can I do for you?');
                }
                inner = '<div class="os-calm"><div class="os-calm__greet">' + greet + '</div><div class="os-calm__sub">' + sub + '</div></div>';
            } else {
                // The dialog transcript (persisted turns + queued messages) is the
                // thread; the thinking indicator or an interactive gate sits at the bottom.
                inner += conversationHTML(); // includes the "typing" row when busy
                if (!S.busy && S.run) {
                    // An interactive gate (confirm / already-open) awaiting the user.
                    inner += '<div class="os-decision">' + decisionPill() + gateHTML() + '</div>';
                }
                if (S.surfaces.length) inner += '<div class="os-surfaces">' + S.surfaces.map(surfaceHTML).join('') + '</div>';
            }
            // Two Ambient sub-views: 'chat' (Планування — the conversation) and
            // 'workspace' (the 3D Weave graph, rendered in the persistent
            // #weave-layer behind — see syncWorkspace). The toggle sits under the
            // content, above the intent bar (which stays live in both views).
            const stage = (S.ambientView === 'workspace')
                ? '<div class="os-workspace-stage"></div>' + weaveHintHTML() + weaveCtrlsHTML()
                : '<div class="os-scroll" id="os-scroll">' + inner + '</div>';
            return stage + ambientViewToggleHTML() + intentbarHTML();
        }

        // Планування (chat) ↔ Workspace (3D graph) switch under the chat.
        function ambientViewToggleHTML() {
            const v = S.ambientView || 'chat';
            const opt = (m, icon, label) => '<button class="os-ambview__btn' + (v === m ? ' active' : '') + '" data-act="ambient-view" data-view="' + m + '">' + ico(icon, 15) + '<span>' + label + '</span></button>';
            return '<div class="os-ambview">' + opt('chat', 'messages-square', t('view_planning', 'Планування')) + opt('workspace', 'orbit', t('view_workspace', 'Workspace')) + '</div>';
        }

        // A coach hint so it's obvious how to grow the graph. Stronger call to
        // action when the graph is just "you"; a quiet reminder once it has grown.
        function weaveHintHTML() {
            if (S.weaveFocus) {
                return '<div class="os-weave-hint os-weave-focuschip">' + ico('crosshair', 15)
                    + '<span>' + t('in_focus', 'In focus: ') + '<b>' + esc(S.weaveFocus.label) + '</b>' + t('its_local_world', ' — its local world.') + '</span>'
                    + '<button class="os-weave-focusx" data-act="weave-unfocus" title="' + t('back_to_everything', 'Back to everything') + '">' + ico('x', 14) + '</button></div>';
            }
            const n = S.weaveCount || 0;
            if (n <= 1) {
                return '<div class="os-weave-hint os-weave-hint--start">' + ico('mouse-pointer-click', 16)
                    + '<span>' + t('weave_hint_start', 'This is <b>you</b>. Tap your node, then just <b>type</b> to place your work, family and projects around you — each one links to you.') + '</span></div>';
            }
            return '<div class="os-weave-hint">' + ico('mouse-pointer-click', 15)
                + '<span>' + t('weave_hint_more', 'Tap any node to add a connected item, edit it, or the pencil for details.') + '</span></div>';
        }
        // Zoom / fit controls for the graph (wheel + drag also work).
        function weaveCtrlsHTML() {
            const btn = (act, icon, label) => '<button class="os-weave-ctrl" data-act="' + act + '" title="' + label + '" aria-label="' + label + '">' + ico(icon, 17) + '</button>';
            return '<div class="os-weave-ctrls">'
                + btn('weave-zoom-in', 'plus', t('zoom_in', 'Zoom in'))
                + btn('weave-zoom-out', 'minus', t('zoom_out', 'Zoom out'))
                + btn('weave-fit', 'maximize', t('fit_to_view', 'Fit to view'))
                + '</div>';
        }

        // The contextual node popover (opened by openNodePop on node click).
        function nodePopHTML() {
            const p = S.nodePop;
            if (!p || !p.node) return '';
            const n = p.node;
            const kinds = ['project', 'person', 'topic', 'note', 'task', 'event', 'org', 'place', 'folder', 'file'];
            const opts = kinds.map(function (k) { return '<option value="' + k + '">' + t('kind_' + k, k) + '</option>'; }).join('');
            const dot = n.self ? cssVar('--accent', '#37b7ff') : kindColor(n.kind);
            return '<div class="os-nodepop__backdrop" id="nodepop-backdrop"><div class="os-nodepop" style="left:' + p.x + 'px;top:' + p.y + 'px">'
                + '<div class="os-nodepop__head">'
                + '<span class="os-nodepop__dot" style="background:' + dot + '"></span>'
                + '<span class="os-nodepop__title">' + esc(n.label) + (function () { const t = nodeNeighborSummary(n.id); return t ? '<span class="os-nodepop__sub">' + esc(t) + '</span>' : ''; })() + '</span>'
                + '<button class="os-nodepop__ic" data-act="pop-edit" title="' + t('edit_details', 'Edit details') + '">' + ico('pencil', 15) + '</button>'
                + (n.self ? '' : '<button class="os-nodepop__ic" data-act="pop-focus" title="' + t('pop_focus_title', 'Focus: show only this node\u2019s world') + '">' + ico('crosshair', 15) + '</button>')
                + (n.self ? '' : '<button class="os-nodepop__ic" data-act="pop-remove" title="' + t('remove', 'Remove') + '">' + ico('trash-2', 15) + '</button>')
                + '</div>'
                + '<div class="os-nodepop__add">'
                + '<select id="pop-kind" class="os-nodepop__kind" title="' + t('type_label', 'Type') + '">' + opts + '</select>'
                + '<input id="pop-title" placeholder="' + t('add_connected_ph', 'Add connected…') + '" autocomplete="off">'
                + '<button class="os-nodepop__go" data-act="pop-add-save" title="' + t('add_enter', 'Add (Enter)') + '">' + ico('plus', 17) + '</button>'
                + '</div>'
                + '</div></div>';
        }

        // The Workspace node editor — a modal raised on node click. The self node
        // ("Я") gets a rich profile form; every other node just title + description.
        // "Add connected" grows the graph (a new node linked to this one).
        function nodeEditorHTML() {
            const e = S.nodeEditor;
            if (!e) return '';
            const field = (label, id, value, ph, type) => '<label class="os-nedit__field"><span>' + esc(label) + '</span><input id="' + id + '" type="' + (type || 'text') + '" value="' + esc(value || '') + '" placeholder="' + esc(ph || '') + '" autocomplete="off"></label>';
            const area = (label, id, value, ph) => '<label class="os-nedit__field"><span>' + esc(label) + '</span><textarea id="' + id + '" rows="3" placeholder="' + esc(ph || '') + '">' + esc(value || '') + '</textarea></label>';
            let title, body, footer;
            if (e.adding) {
                const kinds = ['project', 'person', 'topic', 'note', 'task', 'event', 'org', 'place', 'folder', 'file'];
                title = t('add_to_prefix', 'Add to “') + esc((e.parent || {}).label || '') + t('add_to_suffix', '”');
                body = '<label class="os-nedit__field"><span>' + t('type_label', 'Type') + '</span><select id="we-add-kind">' + kinds.map(k => '<option value="' + k + '">' + t('kind_' + k, k) + '</option>').join('') + '</select></label>'
                    + field(t('title_label', 'Title'), 'we-add-title', '', t('eg_project_person', 'e.g. a project, a person…'))
                    + area(t('description_label', 'Description'), 'we-add-desc', '', t('optional_ph', 'Optional'));
                footer = '<span style="flex:1"></span><button class="os-btn-sm" data-act="weave-cancel">' + t('cancel', 'Cancel') + '</button><button class="os-btn-go" data-act="weave-add-save">' + ico('plus', 16) + t('add', ' Add') + '</button>';
            } else if (e.node && e.node.self) {
                const p = e.node.props || {};
                title = t('you', 'You');
                const av = p.avatar ? '<div class="os-nedit__avatar"><img src="' + esc(p.avatar) + '" alt=""></div>' : '';
                body = av
                    + field(t('name_label', 'Name'), 'we-name', e.node.label, t('your_name', 'Your name'))
                    + field(t('avatar_url', 'Avatar URL'), 'we-avatar', p.avatar, 'https://…')
                    + field(t('role_label', 'Role'), 'we-role', p.role, t('what_you_do', 'What you do'))
                    + field(t('email_label', 'Email'), 'we-email', p.email, 'you@example.com', 'email')
                    + field(t('phone_label', 'Phone'), 'we-phone', p.phone, '')
                    + area(t('about_label', 'About'), 'we-about', p.about, t('few_words_about_you', 'A few words about you'));
                footer = '<button class="os-btn-add" data-act="weave-add-open">' + ico('plus', 15) + t('add_connected', ' Add connected') + '</button><span style="flex:1"></span><button class="os-btn-sm" data-act="weave-cancel">' + t('cancel', 'Cancel') + '</button><button class="os-btn-go" data-act="weave-save">' + ico('check', 16) + t('save', ' Save') + '</button>';
            } else if (e.node) {
                const p = e.node.props || {};
                title = e.node.kind ? t('kind_' + e.node.kind, e.node.kind) : t('node', 'Node');
                const isPath = (e.node.kind === 'folder' || e.node.kind === 'file');
                body = field(t('title_label', 'Title'), 'we-title', e.node.label, t('title_label', 'Title'))
                    + (isPath ? field(t('path_label', 'Path'), 'we-path', p.path, e.node.kind === 'folder' ? '/path/to/folder' : '/path/to/file.ext') : '')
                    + area(t('description_label', 'Description'), 'we-desc', p.description, t('what_is_this', 'What is this?'));
                footer = '<button class="os-btn-add" data-act="weave-add-open">' + ico('plus', 15) + t('add_connected', ' Add connected') + '</button><span style="flex:1"></span><button class="os-btn-sm" data-act="weave-cancel">' + t('cancel', 'Cancel') + '</button><button class="os-btn-go" data-act="weave-save">' + ico('check', 16) + t('save', ' Save') + '</button>';
            } else {
                return '';
            }
            const icon = e.adding ? 'plus-circle' : (e.node && e.node.self ? 'user' : 'circle-dot');
            return '<div class="os-nedit__backdrop" id="we-backdrop"><div class="os-nedit">'
                + '<div class="os-nedit__head">' + ico(icon, 18) + '<span class="os-nedit__title">' + esc(title) + '</span></div>'
                + '<div class="os-nedit__body">' + body + '</div>'
                + '<div class="os-nedit__foot">' + footer + '</div>'
                + '</div></div>';
        }

        // Honest long-wait feedback: a cold model (first request after a worker
        // restart) legitimately takes minutes. Instead of an unexplained spinner,
        // escalate a status line at 25s and 90s. Cleared when the reply lands.
        let busyHintTimers = [];
        function startBusyHints() {
            stopBusyHints();
            busyHintTimers = [
                setTimeout(() => { S.busyHint = t('still_thinking', 'Still thinking — this is slower than usual, the model may be warming up…'); render(); }, 25000),
                setTimeout(() => { S.busyHint = boot.assistantName + t('waking_up', ' is waking up: the first request after a restart warms the model and can take a few minutes. Not stuck — your message is queued and will be answered.'); render(); }, 90000),
            ];
        }
        function stopBusyHints() {
            busyHintTimers.forEach(clearTimeout);
            busyHintTimers = [];
            S.busyHint = null;
        }
        function intentbarHTML() {
            const trim = (t) => (t && t.length > 54) ? t.slice(0, 52) + '…' : (t || '');
            // Adaptive chips from /os/suggestions (history + time-of-day). Until
            // they load, fall back to a few skill summaries so the bar is never bare.
            const list = (S.suggestions && S.suggestions.length)
                ? S.suggestions.slice(0, 4)
                : (boot.skills || []).slice(0, 4).map(s => ({ text: s.summary || s.name, hint: '', source: 'skill' }));
            const sugg = list
                .map(s => '<button class="os-suggest-chip" data-act="suggest" data-text="' + esc(s.text) + '"' + (s.hint ? ' title="' + esc(s.hint) + '"' : '') + '>' + esc(trim(s.text)) + '</button>').join('');
            const busy = S.busy;
            // The input is NEVER disabled — you can keep sending while the OS is
            // still thinking; messages queue and each appears instantly.
            const placeholder = t('tell_prefix', 'Tell ') + boot.assistantName + t('what_you_want_ph', ' what you want…');
            const idle = !((S.history && S.history.length) || (S.queue && S.queue.length) || S.run);
            return '<div class="os-intentbar">'
                + (idle ? '<div class="os-suggest">' + sugg + '</div>' : '')
                + (busy && S.busyHint ? '<div class="os-busyhint">' + ico('loader-circle', 13) + '<span>' + esc(S.busyHint) + '</span></div>' : '')
                + '<div class="os-intentbar__field' + (busy ? ' is-busy' : '') + '"><span class="dot"></span>'
                + '<input id="intent-input" placeholder="' + esc(placeholder) + '" value="' + esc(S.input) + '" autocomplete="off">'
                + '<button class="os-intentbar__send" data-act="submit">' + ico('arrow-up', 20) + '</button>'
                + '</div></div>';
        }

        // The compact side chat for Focus/Chill (Messenger-style). The full-screen
        // conversation lives only in Ambient; here it folds into a small dock that
        // collapses to a bubble. Reuses #intent-input so intent-first holds in every
        // zone and the existing submit / Enter / input handlers just work.
        function chatDockHTML() {
            if (S.chatDock === 'collapsed') {
                const badge = S.proactiveUnseen > 0 ? '<span class="os-chatdock-fab__badge">' + (S.proactiveUnseen > 9 ? '9+' : S.proactiveUnseen) + '</span>' : '';
                return '<button class="os-chatdock-fab" data-act="chatdock-toggle" title="' + t('chat_with', 'Chat with ') + esc(boot.assistantName) + '">' + ico('message-circle', 24) + badge + '</button>';
            }
            const hasThread = ((S.history && S.history.length) || (S.queue && S.queue.length) || S.run);
            const body = hasThread
                ? conversationHTML() + ((!S.busy && S.run) ? '<div class="os-decision">' + decisionPill() + gateHTML() + '</div>' : '')
                : '<div class="os-chatdock__empty">' + t('ask_prefix', 'Ask ') + esc(boot.assistantName) + t('dock_anything', ' anything — it keeps working while you stay here.') + '</div>';
            return '<div class="os-chatdock">'
                + '<div class="os-chatdock__bar"><span class="os-chatdock__title">' + ico('sparkles', 15) + ' ' + esc(boot.assistantName) + '</span>'
                + '<button class="os-chatdock__min" data-act="chatdock-toggle" title="' + t('collapse', 'Collapse') + '">' + ico('minus', 16) + '</button></div>'
                + '<div class="os-chatdock__body" id="os-dock-scroll">' + body + '</div>'
                + (S.busy && S.busyHint ? '<div class="os-busyhint os-busyhint--dock">' + ico('loader-circle', 12) + '<span>' + esc(S.busyHint) + '</span></div>' : '')
                + '<div class="os-chatdock__input"><input id="intent-input" placeholder="' + t('message_ph', 'Message ') + esc(boot.assistantName) + '…" value="' + esc(S.input) + '" autocomplete="off">'
                + '<button class="os-chatdock__send" data-act="submit">' + ico('arrow-up', 18) + '</button></div>'
                + '</div>';
        }

        // Chill's "what's running" panel — the observability primitives (current run
        // + loop stage + units + recent activity) raised into a full at-rest view.
        // Phase 1 is built on what exists (the foreground intent loop); a backend
        // registry for background tasks (copies, downloads) is a later phase.
        function chillProcessesHTML() {
            // Phase 3: the OS process registry IS the source. Anything doing
            // work — PHP packages, iframe apps, the native VM bridge — reports
            // into /os/process/report; this panel just renders the registry
            // (/os/process/list) and knows nothing about the producers.
            const procs = S.processes || [];
            const running = procs.filter(p => p.status === 'running');
            const stalled = procs.filter(p => p.status === 'stalled');
            const recentDone = procs.filter(p => p.status === 'done' || p.status === 'failed').slice(0, 3);
            const loopRun = !!S.run || S.units.length > 0;   // the foreground intent loop is a process too
            const live = running.length > 0 || loopRun;

            const originIcon = { internal: 'cpu', external: 'app-window', native: 'monitor' };
            const procCard = (p) => {
                const pct = (p.progress === null || p.progress === undefined) ? null : p.progress;
                const isStalled = p.status === 'stalled';
                return '<div class="os-proc-task' + (isStalled ? ' stalled' : '') + '">'
                    + '<div class="os-proc-task__head">' + ico(originIcon[p.origin] || 'circle-dot', 17)
                    + '<span class="os-proc-task__title">' + esc(p.title) + '</span>'
                    + '<span class="os-proc-task__auto">' + esc(p.source) + '</span>'
                    + '<span class="os-proc-task__pct">' + (isStalled ? esc(p.status_label) : (pct === null ? '…' : pct + '%')) + '</span></div>'
                    + (isStalled
                        ? ''
                        : (pct === null
                            ? '<div class="os-proc-bar indet"><i></i></div>'
                            : '<div class="os-proc-bar"><i style="width:' + pct + '%"></i></div>'))
                    + (p.detail ? '<div class="os-proc-task__detail">' + esc(p.detail) + '</div>' : '')
                    + '</div>';
            };

            let inner = '';
            if (loopRun) inner += '<div class="os-chill-loop">' + stageCards() + '</div>';
            if (running.length || stalled.length) inner += '<div class="os-proc-list">' + running.concat(stalled).map(procCard).join('') + '</div>';
            if (recentDone.length) {
                inner += '<div class="os-proc-recent"><div class="os-proc-recent__h">' + t('recently_completed', 'Recently completed') + '</div>'
                    + recentDone.map(p => '<div class="os-proc-recent__row">' + ico(p.status === 'failed' ? 'x' : 'check', 14) + '<span>' + esc(p.title) + '</span></div>').join('') + '</div>';
            }
            if (inner === '') {
                // No real tasks and no live loop → Phase-1 fallback (durable trace / idle).
                const recent = ((S.snapshot && S.snapshot.recent) || []).slice().reverse().slice(0, 5);
                inner = recent.length
                    ? '<div class="os-chill-recent">' + recent.map(e => '<div class="os-chill-recent__row"><span class="os-chill-recent__k">' + esc(e.skill || e.intent || e.decision || t('activity', 'activity')) + '</span><span class="os-chill-recent__v">' + esc(e.decision || '') + '</span></div>').join('') + '</div>'
                    : '<div class="os-chill-proc__idle">' + ico('moon', 22) + '<div>' + t('all_quiet', 'All quiet.') + '</div><div class="os-chill-proc__hint">' + t('nothing_running_ask', 'Nothing running right now. Ask ') + esc(boot.assistantName) + t('to_run_something', ' to run something — automated tasks show their progress here.') + '</div></div>';
            }
            return '<div class="os-chill-proc">'
                + '<div class="os-chill-proc__eyebrow">' + ico('activity', 16) + t('processes_running', ' Processes · what\'s running') + (live ? ' <span class="os-chill-proc__live">' + t('live', 'live') + '</span>' : '') + '</div>'
                + inner + '</div>';
        }

        // Focus = the app zone. A search box sits on top; BELOW it two views the
        // launcher swaps between (filterLauncher, no re-render):
        //  • browse (default, empty query) — the familiar gradation: Running,
        //    Recently opened, Your apps, then the full All apps catalog.
        //  • results (while typing) — one flat grid filtered across every app.
        // Apps launch DIRECTLY here — not only via an Ambient intent — because
        // chat-only launching proved inconvenient for a plain "open X". Windows
        // live in the persistent #wm-layer (syncDialogs).
        function focusHTML() {
            return '<div class="os-scroll"><div class="os-focus-zone">'
                + '<div class="os-focus__eyebrow"><span class="tag">' + t('mode_focus', 'Focus') + '</span><span class="note">' + t('open_or_switch', 'Open an app — or switch to a running one') + '</span></div>'
                + launcherHTML()
                + '</div></div>' + chatDockHTML();
        }

        // Reusable app-tile buttons (shared by the browse sections + results grid).
        function skillBtn(s, cls) {
            return '<button class="os-appicon' + (cls ? ' ' + cls : '') + '" data-act="open-dialog" data-skill="' + esc(s.name) + '" title="' + esc(s.summary || s.name) + '">'
                + '<span class="os-appicon__sym">' + ico(s.icon || 'app-window', 24) + '</span>'
                + '<span class="os-appicon__label">' + esc(s.name) + '</span></button>';
        }
        function webAppTile(a) {
            return '<div class="os-appicon-wrap"><button class="os-appicon recent" data-act="open-webapp" data-id="' + esc(a.id) + '" title="' + t('open_prefix', 'Open ') + esc(a.name) + '">'
                + '<span class="os-appicon__sym">' + ico(a.icon || 'globe', 24) + '</span>'
                + '<span class="os-appicon__label">' + esc(a.name) + '</span></button>'
                + '<button class="os-appicon-x" data-act="remove-webapp" data-id="' + esc(a.id) + '" title="' + t('remove_prefix', 'Remove ') + esc(a.name) + '">' + ico('x', 12) + '</button></div>';
        }
        function launcherSection(title, inner, hint) {
            return '<div class="os-recents"><div class="os-recents__h">' + title + '</div><div class="os-iconrow">' + inner + '</div>'
                + (hint ? '<div class="os-recents__hint">' + hint + '</div>' : '') + '</div>';
        }

        // The searchable launcher: a glass search box + the browse/results views.
        function launcherHTML() {
            const q = (S.launchQuery || '').trim().toLowerCase();
            const dialogs = S.dialogs || [];
            const skills = (boot.skills || []).filter(s => s.is_ui);
            const apps = S.webApps || [];

            // ---- browse view (the preserved gradation) ----
            const running = dialogs.map(d => {
                const active = d.id === S.focusedId && d.state !== 'minimized';
                return '<button class="os-appicon' + (d.state === 'minimized' ? ' min' : '') + (active ? ' active' : '') + '" data-act="task-click" data-id="' + d.id + '" title="' + esc(d.title) + (d.state === 'minimized' ? t('minimized_suffix', ' (minimized)') : '') + '">'
                    + '<span class="os-appicon__sym">' + ico(d.icon || 'app-window', 24) + '</span>'
                    + '<span class="os-appicon__label">' + esc(d.title) + '</span></button>';
            }).join('');
            const runningSkills = dialogs.map(d => d.skill);
            const recents = (S.recents || []).filter(s => !runningSkills.includes(s.name));

            let browse = '';
            if (dialogs.length) browse += launcherSection(t('running_section', 'Running · ') + dialogs.length, running);
            if (recents.length) browse += launcherSection(t('recently_opened', 'Recently opened'),
                recents.map(s => skillBtn({ name: s.name, icon: s.icon }, 'recent')).join(''));
            if (apps.length) browse += launcherSection(t('your_apps', 'Your apps'), apps.map(webAppTile).join(''),
                t('ask_open_prefix', 'Ask ') + esc(boot.assistantName) + t('open_any_site', ' to “open …” any site to add one.'));
            browse += skills.length
                ? launcherSection(t('all_apps', 'All apps'), skills.map(s => skillBtn(s)).join(''))
                : '<div class="os-launcher-empty">' + ico('layout-grid', 20) + ' <span>' + t('no_apps_yet_ask', 'No apps yet. Ask ') + esc(boot.assistantName) + t('to_open_site', ' to open a site to add one.') + '</span></div>';

            // ---- results view (flat, filtered across everything) ----
            const tile = (dataName, inner) => {
                const dn = (dataName || '').toLowerCase();
                return '<div class="os-appicon-wrap os-launch-tile" data-name="' + esc(dn) + '"'
                    + ((!q || dn.indexOf(q) !== -1) ? '' : ' hidden') + '>' + inner + '</div>';
            };
            const resultTiles = skills.map(s => tile(s.name + ' ' + (s.summary || ''), skillBtn(s)))
                .concat(apps.map(a => tile(a.name + ' ' + (a.host || ''), webAppTile(a)))).join('');

            return '<div class="os-launcher">'
                + '<div class="os-launcher__bar"><span class="os-launcher__ico">' + ico('search', 17) + '</span>'
                + '<input id="launcher-input" placeholder="' + t('search_apps', 'Search apps…') + '" value="' + esc(S.launchQuery || '') + '" autocomplete="off" spellcheck="false">'
                + '<button class="os-launcher__clear" data-act="launcher-clear" title="' + t('clear', 'Clear') + '" style="' + (q ? '' : 'display:none') + '">' + ico('x', 16) + '</button>'
                + '</div>'
                + '<div class="os-launcher-default"' + (q ? ' hidden' : '') + '>' + browse + '</div>'
                + '<div class="os-launcher-results"' + (q ? '' : ' hidden') + '>'
                + '<div class="os-iconrow os-launcher-grid">' + resultTiles + '</div>'
                + '<div class="os-launcher-empty" hidden>' + ico('search-x', 20) + ' <span>' + t('no_apps_match_prefix', 'No apps match “') + '<b class="q"></b>' + t('no_apps_match_suffix', '”.') + '</span></div>'
                + '</div></div>';
        }

        // Chill = the leisure zone. Instead of a mock, Semi asks what you feel
        // like and everything routes through the LLM (run-skill / intent bar →
        // /os/intent), so the planner catches context ("something relaxing" →
        // music, "let's play" → the game). Leisure UI-skills open as dialogs.
        // Chill = the "at rest, watch everything" zone: a live panel of what's
        // running (chillProcessesHTML) + leisure launchers (media / video / games).
        // The conversation is NOT inline here — it lives in the compact chat dock,
        // so you can watch a video or a process and still talk to Semi. Leisure
        // chips route through the LLM (run-skill → /os/intent); apps open as windows.
        function chillHTML() {
            const name = boot.userName ? (', ' + esc(boot.userName)) : '';
            // A chip with a REMEMBERED app (data-chill in S.chillApps) opens it
            // directly — no planner round-trip. First use (or after "change the
            // music app") routes through the LLM and the opened app sticks.
            const chip = (icon, label, text, activity) => '<button class="os-chill-chip" data-act="run-skill" data-text="' + esc(text) + '"'
                + (activity ? ' data-chill="' + activity + '"' : '') + '>' + ico(icon, 18) + '<span>' + esc(label) + '</span></button>';
            const chips = '<div class="os-chill-chips">'
                + chip('gamepad-2', t('tictactoe_with', 'Tic-tac-toe with ') + boot.assistantName, "Let's play tic-tac-toe", 'game')
                + chip('music', t('music_label', 'Music'), 'Put on some relaxing music', 'music')
                + chip('youtube', 'YouTube', 'Open YouTube to watch something', 'video')
                + chip('dices', t('surprise_me', 'Surprise me'), 'Surprise me with something fun to do')
                + '</div>';
            const leisure = '<div class="os-chill-leisure">'
                + '<div class="os-chill-hero__greet">' + t('in_the_mood', 'In the mood for something') + name + '?</div>'
                + '<div class="os-chill-hero__sub">' + t('watch_play_relax', 'Watch, play, or just relax — pick something, or ask ') + esc(boot.assistantName) + t('in_the_chat', ' in the chat.') + '</div>'
                + chips + '</div>';
            const inner = chillProcessesHTML() + leisure;
            return '<div class="os-scroll" id="os-scroll"><div class="os-chill">' + inner + '</div></div>' + chatDockHTML();
        }

        function xrayHTML() {
            const unit = (name, status, busy) => '<div class="os-unit' + (busy?' busy':'') + '"><div class="os-unit__head">' + ico('box',19) + '<span class="os-unit__name">' + esc(name) + '</span><span class="os-unit__dot"></span></div><div class="os-unit__status">' + esc(status) + '</div></div>';
            let units;
            if (S.units.length) {
                units = S.units.map(u => unit(u.name, u.status + (u.exit != null && u.status !== 'running' ? ' · exit ' + u.exit : ''), u.busy)).join('');
            } else {
                const recent = ((S.snapshot && S.snapshot.recent) || []).slice().reverse().slice(0, 4);
                units = recent.length
                    ? recent.map(e => unit(e.skill || e.intent || e.decision, e.decision, false)).join('')
                    : '<div class="os-xray__hint">' + t('no_activity_yet', 'No activity yet.') + '</div>';
            }
            const sessionCount = (S.snapshot && S.snapshot.count) || 0;
            const tr = S.log.map(e => '<div class="os-trace-line ' + (e.tone||'') + '">' + esc(e.t) + '</div>').join('');
            return '<aside class="os-xray"><div class="os-xray__eyebrow">' + ico('scan-eye',16) + t('xray_observability', ' X-Ray · observability') + '</div>'
                + '<div class="os-xray__hint">' + (S.units.length ? t('skills_in_flight', 'Skills in flight, live.') : t('recent_loop_activity', 'Recent loop activity (durable session).')) + '</div>'
                + '<div class="os-xray__units">' + units + '</div>'
                + '<div class="os-xray__meters"><div class="os-meter"><div class="os-meter__k">' + t('provider_label', 'PROVIDER ') + (boot.providerHealthy? t('provider_up', '· up') : t('provider_down', '· down')) + '</div><div class="os-meter__v accent" style="font-size:14px">' + esc(boot.providerModel) + '</div></div><div class="os-meter"><div class="os-meter__k">' + t('session_label', 'SESSION') + '</div><div class="os-meter__v plain">' + sessionCount + ' <span style="font-size:11px;color:#6f7d99">' + t('actions_word', 'actions') + '</span></div></div></div>'
                + '<div class="os-xray__trace-h">os:trace · live</div><div class="os-xray__trace">' + tr + '</div></aside>';
        }

        function cueHTML() {
            if (!S.cue) return '';
            return '<div class="os-cue"><div class="os-cue__head">' + ico('volume-2') + '<span class="os-cue__title">' + esc(S.cue.title) + '</span></div><div class="os-cue__body">' + esc(S.cue.body) + '</div></div>';
        }

        // ---------- Ambient Workspace: 2D Weave force-graph ----------
        // The graph (canvas + physics) is stateful, so it lives in the persistent
        // #weave-layer (sibling of #root, never wiped by render) and is created
        // once — same discipline as #wm-layer for dialog windows. 2D canvas (not
        // WebGL): lower cognitive load, cleaner, and no GPU dependency.
        const weaveLayer = document.getElementById('weave-layer');
        let weaveGraph = null;
        let weaveLoaded = false;

        function kindColor(kind) {
            const map = {
                project: '#37b7ff', person: '#34d399', topic: '#a78bfa', note: '#60a5fa',
                task: '#fbbf24', event: '#22d3ee', app: '#f472b6', file: '#94a3b8',
                folder: '#eab308', place: '#f59e0b', org: '#818cf8', thread: '#cbd5e1',
                // A managed site and its structure. Distinct hues on purpose:
                // on the museum's map a page and a collection are opened by
                // different gestures, and telling them apart at a glance is the
                // point of drawing the thing at all.
                site: '#2dd4bf', page: '#7dd3fc', collection: '#c084fc',
            };
            return map[kind] || '#94a3b8';
        }
        // Real per-kind icons on the graph nodes: reuse the shell's Lucide set —
        // rasterise each glyph (white) to a cached <img> once, then draw it on the
        // node's coloured disc in nodeCanvasObject. No new dependency.
        const KIND_ICON = {
            project: 'briefcase', person: 'user', org: 'building-2', topic: 'hash',
            note: 'sticky-note', task: 'list-checks', event: 'calendar', place: 'map-pin',
            folder: 'folder', file: 'file-text', app: 'layout-grid', thread: 'message-square',
            site: 'globe', page: 'file-text', collection: 'layers',
        };
        const _iconCache = {};
        function preloadNodeIcons() {
            if (!(window.lucide && window.lucide.createIcons)) { setTimeout(preloadNodeIcons, 200); return; }
            const names = Object.values(KIND_ICON).concat(['user-round', 'circle-dot']);
            const holder = document.createElement('div');
            holder.style.cssText = 'position:absolute;left:-9999px;top:-9999px;';
            holder.innerHTML = names.map(function (n) { return '<span data-k="' + n + '"><i data-lucide="' + n + '"></i></span>'; }).join('');
            document.body.appendChild(holder);
            window.lucide.createIcons();
            names.forEach(function (n) {
                const svg = holder.querySelector('[data-k="' + n + '"] svg');
                if (!svg) { _iconCache[n] = null; return; }
                svg.setAttribute('stroke', '#ffffff');
                const img = new Image();
                img.onload = function () { try { if (weaveGraph) weaveGraph.nodeRelSize(weaveGraph.nodeRelSize()); } catch (e) {} };
                img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg));
                _iconCache[n] = img;
            });
            document.body.removeChild(holder);
        }
        function nodeIcon(kind, isSelf) {
            return _iconCache[isSelf ? 'user-round' : (KIND_ICON[kind] || 'circle-dot')] || null;
        }
        function cssVar(name, fallback) {
            try { return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback; } catch (e) { return fallback; }
        }
        function weaveResize() {
            if (weaveGraph) weaveGraph.width(weaveLayer.clientWidth).height(weaveLayer.clientHeight);
        }
        function centerWeaveNode(n) {
            if (!weaveGraph || !n) return;
            weaveGraph.centerAt(n.x, n.y, 600);
        }
        // Zoom the 2D graph (factor < 1 = out, > 1 = in); drives the +/- buttons.
        function weaveZoom(factor) {
            if (!weaveGraph) return;
            weaveGraph.zoom(weaveGraph.zoom() * factor, 250);
        }
        function initWeaveGraph() {
            if (weaveGraph || !window.ForceGraph) return;
            const accent = cssVar('--accent', '#37b7ff');
            const textColor = cssVar('--os-text', '#eaf2ff');
            weaveGraph = ForceGraph()(weaveLayer)
                .backgroundColor('rgba(0,0,0,0)')          // let the shell's --os-bg show through
                .nodeRelSize(5)
                .nodeVal(function (n) { return n.self ? 7 : (n.kind === 'project' ? 4.5 : 2); }) // projects = gravitational centres
                .nodeColor(function (n) { return n.self ? accent : kindColor(n.kind); })
                .nodeLabel(function (n) { return t('kind_' + n.kind, n.kind); }) // hover tooltip = kind (title is drawn below)
                .nodeCanvasObjectMode(function () { return 'after'; })
                .nodeCanvasObject(function (n, ctx, scale) {
                    const r = Math.sqrt(n.self ? 7 : 2) * 5;
                    // real per-kind icon (white) on the coloured disc
                    const img = nodeIcon(n.kind, n.self);
                    if (img && img.complete && img.naturalWidth) {
                        const s = r * 1.15;
                        ctx.drawImage(img, n.x - s / 2, n.y - s / 2, s, s);
                    }
                    // title label below the node
                    const label = n.label || '';
                    if (label) {
                        const fontSize = Math.max(12 / scale, 2);
                        ctx.font = fontSize + 'px "IBM Plex Sans", system-ui, sans-serif';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'top';
                        ctx.fillStyle = n.self ? accent : textColor;
                        ctx.fillText(label, n.x, n.y + r + 2 / scale);
                    }
                })
                .linkColor(function () { return 'rgba(148,163,184,0.4)'; })
                .linkWidth(1)
                .linkDirectionalArrowLength(3.5)
                .linkDirectionalArrowRelPos(1)
                .linkDirectionalArrowColor(function () { return 'rgba(148,163,184,0.55)'; })
                .cooldownTicks(120)
                .onNodeClick(function (n, e) {
                    if ((n.kind === 'folder' || n.kind === 'file') && n.props && n.props.path) {
                        openWeaveFolder(n); // terminal node → open the in-OS Files manager here
                    } else if (n.props && n.props.opens === 'editor' && n.props.ref) {
                        openContentEditor(n); // a page of a managed site → edit its content
                    } else {
                        openNodePop(n, e);
                    }
                })
                .onEngineStop(function () { if (weaveGraph) weaveGraph.zoomToFit(400, 60); });
            // Spread the layout so it reads as a constellation, not a clump.
            try { weaveGraph.d3Force('charge').strength(-320); } catch (e) {}
            try { weaveGraph.d3Force('link').distance(95); } catch (e) {}
            preloadNodeIcons();
            weaveResize();
            window.addEventListener('resize', weaveResize);
        }
        // Click a node → open its editor. The self node ("Я") gets a rich profile
        // form; every other node a simple title + description. Also frames it.
        function openNodeEditor(n) {
            S.nodeEditor = { node: n, adding: false };
            render();
        }
        // Click a node → a compact contextual popover at the cursor: a focused
        // "add connected" field (growing is the primary act) + edit/remove. Fast
        // tap→type→Enter loop; the full editor is one click away (pencil).
        // One-line info scent for the popover: "3 person · 1 folder · 2 topic".
        function nodeNeighborSummary(id) {
            if (!weaveGraph) return '';
            const counts = {};
            (weaveGraph.graphData().links || []).forEach(function (l) {
                const s2 = l.source && l.source.id ? l.source.id : l.source;
                const t2 = l.target && l.target.id ? l.target.id : l.target;
                if (s2 !== id && t2 !== id) return;
                const other = s2 === id ? l.target : l.source;
                const k = other && other.kind ? other.kind : null;
                if (k) counts[k] = (counts[k] || 0) + 1;
            });
            return Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a]; })
                .slice(0, 3).map(function (k) { return counts[k] + ' ' + k; }).join(' · ');
        }
        function openNodePop(n, e) {
            const w = 300;
            const cx = (e && e.clientX) || (window.innerWidth / 2);
            const cy = (e && e.clientY) || (window.innerHeight / 2);
            S.nodePop = {
                node: n,
                x: Math.max(12, Math.min(cx - 20, window.innerWidth - w - 16)),
                y: Math.max(70, Math.min(cy + 14, window.innerHeight - 170)),
            };
            S._popFocus = true;
            render();
        }
        async function popAdd() {
            const p = S.nodePop; if (!p || !p.node) return;
            const val = function (id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
            const title = val('pop-title'); if (title === '') return;
            await post('/os/weave/node/add', { parentId: p.node.id, kind: val('pop-kind'), title: title, relation: 'part_of' });
            S._popFocus = true;                 // keep the popover open for rapid adding
            loadWeaveGraph(true);
        }
        async function popRemove() {
            const p = S.nodePop; if (!p || !p.node) return;
            await post('/os/weave/node/remove', { id: p.node.id });
            S.nodePop = null; render(); loadWeaveGraph(true);
        }
        // A folder/file node is a terminal point → clicking it opens the in-OS
        // Files manager at that location (as a Focus dialog). The Files app then
        // browses the real files (via the bridge) and can hand a code project to
        // an editor. A file node opens its containing folder.
        async function openWeaveFolder(node) {
            let path = node && node.props && node.props.path;
            if (!path) { openNodePop(node); return; }
            if (node.kind === 'file') { path = path.replace(/\/[^\/]*$/, '') || path; }
            S.nodePop = null;
            await post('/os/dialog/open', { skill: 'Files', path: path });
            S.mode = 'focus';
            fetchDialogs().then(render);
        }
        // A page on a site's map opens the content editor rather than the node
        // popover: what someone wants from "Контакти" is the page's text, not the
        // graph node's title. Renaming the node is still one click away, on the
        // node itself in the editor's own surface.
        async function openContentEditor(node) {
            const ref = node && node.props && node.props.ref;
            if (!ref) { openNodePop(node); return; }
            S.nodePop = null;
            await post('/os/dialog/open', { skill: 'Content', ref: ref });
            S.mode = 'focus';
            fetchDialogs().then(render);
        }
        async function weaveSaveNode() {
            const e = S.nodeEditor; if (!e || e.adding || !e.node) return;
            const n = e.node;
            const val = function (id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
            let title, props;
            if (n.self) {
                title = val('we-name');
                props = { avatar: val('we-avatar'), role: val('we-role'), email: val('we-email'), phone: val('we-phone'), about: val('we-about') };
            } else {
                title = val('we-title');
                props = { description: val('we-desc') };
                if (document.getElementById('we-path')) { props.path = val('we-path'); } // file/folder location
            }
            await post('/os/weave/node/save', { id: n.id, title: title, propsJson: JSON.stringify(props) });
            S.nodeEditor = null; render(); loadWeaveGraph(true);
            refreshPrefs();
        }
        async function weaveAddNode() {
            const e = S.nodeEditor; if (!e || !e.adding || !e.parent) return;
            const val = function (id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
            const title = val('we-add-title'); if (title === '') return;
            await post('/os/weave/node/add', { parentId: e.parent.id, kind: val('we-add-kind'), title: title, description: val('we-add-desc'), relation: 'part_of' });
            S.nodeEditor = null; render(); loadWeaveGraph(true);
        }
        async function loadWeaveGraph(force) {
            if (!weaveGraph || (weaveLoaded && !force)) return;
            try {
                // Contextual (ego) view: when focused, ask only for the local world.
                // A pending focusQuery (intent path) resolves server-side and comes
                // back as d.focus — one matcher for the skill, the feed and the UI.
                const focusQ = S.weaveFocusQuery
                    ? '&focusQuery=' + encodeURIComponent(S.weaveFocusQuery) + '&depth=1'
                    : (S.weaveFocus ? '&focus=' + encodeURIComponent(S.weaveFocus.id) + '&depth=1' : '');
                const r = await fetch('/os/weave/graph?t=' + Date.now() + focusQ, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                const d = await r.json();
                if (S.weaveFocusQuery) {
                    S.weaveFocusQuery = null;
                    if (d.focus) { S.weaveFocus = { id: d.focus.id, label: d.focus.label }; }
                }
                weaveGraph.graphData({ nodes: d.nodes || [], links: d.links || [] });
                weaveLoaded = true;
                const n = (d.nodes || []).length;
                if (n !== S.weaveCount) { S.weaveCount = n; render(); } // refresh the coach hint
            } catch (e) {}
        }
        // P2 contextual view: focus the Workspace on one node's local world
        // (2 hops). The chip above the graph names the focus; ✕ (or focusing
        // your own node) returns to the whole constellation.
        function weaveFocusOn(id, label) {
            S.weaveFocus = { id: id, label: label };
            S.nodePop = null;
            render(); loadWeaveGraph(true);
        }
        // Intent-route: "покажи X у моєму світі" → the show-in-world skill ran;
        // jump to the Workspace with the term pending — the feed resolves it.
        function enterWorkspaceFocused(query) {
            S.mode = 'ambient';
            if (S.ambientView !== 'workspace') {
                S.ambientView = 'workspace';
                try { localStorage.setItem('semitexa_os_ambient_view', 'workspace'); } catch (e) {}
            }
            S.weaveFocus = null;
            S.weaveFocusQuery = query;
            weaveLoaded = false;
            render(); // syncWorkspace() at the end of render inits + loads the graph
            loadWeaveGraph(true);
        }
        // attach-folder intent: folders live on the USER'S machine — only the
        // client-side bridge can see them. Resolve the name here (root listing,
        // exact match first, then contains), then complete via /os/weave/attach
        // with narrate=1 so the confirmation lands in the chat (proactive poll).
        async function completeAttachIntent(folder, connectTo) {
            const fail = (msg) => { S.cue = { title: boot.assistantName, body: msg }; render(); setTimeout(() => { S.cue = null; render(); }, 5200); };
            try {
                const r = await bridgeFetch('/list?path=', { signal: AbortSignal.timeout(2500) });
                if (!r.ok) throw 0;
                const d = await r.json();
                const dirs = (d.entries || []).filter(e => e.type === 'dir');
                const q = folder.toLowerCase();
                const hit = dirs.find(e => e.name.toLowerCase() === q)
                    || dirs.find(e => e.name.toLowerCase().startsWith(q))
                    || dirs.find(e => e.name.toLowerCase().includes(q));
                if (!hit) { fail(t('cant_find_folder_prefix', 'I couldn\u2019t find a "') + folder + t('folder_under', '" folder under ') + d.root + '.'); return; }
                const path = d.path.replace(/\/$/, '') + '/' + hit.name;
                await post('/os/weave/attach', { path: path, kind: 'folder', connect_to: connectTo || '', narrate: '1' });
                markWeaveStale();
            } catch (e) {
                fail(t('cant_reach_files', 'I can\u2019t reach your files \u2014 the desktop bridge isn\u2019t running.'));
            }
        }
        function weaveFocusClear() {
            S.weaveFocus = null;
            render(); loadWeaveGraph(true);
        }
        // The graph changed server-side (a skill or the background weaver wrote
        // to it). Visible Workspace → refetch NOW so the constellation grows in
        // front of the user; hidden → just invalidate the cache, syncWorkspace
        // refetches on the next entry.
        function markWeaveStale() {
            const visible = weaveGraph && !weaveLayer.hidden;
            if (visible) { loadWeaveGraph(true); } else { weaveLoaded = false; }
        }
        function syncWorkspace() {
            const show = (S.screen === 'os' && S.mode === 'ambient' && S.ambientView === 'workspace');
            weaveLayer.hidden = !show;
            // Route pointer events: while browsing the graph (no modal), #root is
            // click-through so the graph behind stays interactive; opening the
            // editor drops the class so the form captures normally.
            root.classList.toggle('os-root--graph', show && !S.nodeEditor && !S.nodePop);
            if (!show) {
                return;
            }
            if (!weaveGraph) {
                initWeaveGraph();
                // Dev affordance: ?debug exposes the graph instance — automated
                // tests need exact node coords (the force sim drifts under them).
                try { if (new URLSearchParams(location.search).has('debug')) window.__wg = weaveGraph; } catch (e) {}
            }
            weaveResize();
            loadWeaveGraph();
        }

        function render() {
            if (S.screen === 'awakening') { root.innerHTML = awakeningHTML(); drawIcons(); return; }
            // The launcher filters in place, but any full re-render (dialog/webapp
            // fetch, proactive poll) rebuilds innerHTML and would drop the search
            // box's focus — capture it so we can restore focus + caret below.
            const keepLauncher = document.activeElement && document.activeElement.id === 'launcher-input';
            const lsel = keepLauncher ? [document.activeElement.selectionStart, document.activeElement.selectionEnd] : null;
            let stage = '';
            if (S.mode === 'ambient') stage = ambientHTML();
            else if (S.mode === 'focus') stage = focusHTML();
            else if (S.mode === 'chill') stage = chillHTML();
            const railClass = (S.xray ? ' with-rail' : '') + (S.mode === 'chill' ? ' chill' : '');
            root.innerHTML = '<div class="os-shell">' + topbarHTML()
                + '<div class="os-main"><div class="os-stage' + railClass + '">' + stage + '</div>'
                + (S.xray ? xrayHTML() : '') + '</div>' + cueHTML() + nodeEditorHTML() + nodePopHTML() + '</div>';
            drawIcons();
            // Restore the launcher search box focus (kept across re-render), or give
            // it focus on first entering Focus (S._focusLauncher) so you can type
            // to filter immediately.
            const li = document.getElementById('launcher-input');
            if (li && (keepLauncher || S._focusLauncher)) {
                li.focus();
                if (keepLauncher && lsel) { try { li.setSelectionRange(lsel[0], lsel[1]); } catch (e) {} }
                else { li.setSelectionRange(li.value.length, li.value.length); }
                S._focusLauncher = false;
            }
            const inp = document.getElementById('intent-input');
            if (inp && S._focusInput) { inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); S._focusInput = false; }
            const pt = document.getElementById('pop-title');
            if (pt && S._popFocus) { pt.focus(); S._popFocus = false; }
            const scroll = document.getElementById('os-scroll');
            if (scroll) scroll.scrollTop = scroll.scrollHeight; // keep the newest turn in view
            const dockScroll = document.getElementById('os-dock-scroll');
            if (dockScroll) dockScroll.scrollTop = dockScroll.scrollHeight; // same, inside the chat dock
            syncDialogs();
            syncWorkspace();
        }

        // ---------- backend wiring (Ambient) ----------
        // The console's routes are authenticated, so every write carries the
        // double-submit CSRF token the server put in a readable cookie. Without
        // it CsrfListener answers 403 and the shell looks broken for no visible
        // reason.
        function csrfToken() {
            const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
            return m ? decodeURIComponent(m[1]) : '';
        }
        // A session can lapse while the shell is open. 401 means the answer is
        // the sign-in page, and only a navigation can render it.
        function signInAgain() {
            window.location.reload();
        }
        async function post(url, body) {
            try {
                const token = csrfToken();
                const headers = { 'Content-Type':'application/json', 'Accept':'application/json' };
                if (token) headers['X-CSRF-Token'] = token;
                const res = await fetch(url, { method:'POST', headers, body: JSON.stringify(body) });
                if (res.status === 401) { signInAgain(); return { decision:'error', message: t('signed_out', 'Signed out.') }; }
                const txt = await res.text();
                try { return JSON.parse(txt); } catch(e) { return { decision:'error', message: t('bad_response', 'Bad response (') + res.status + ')' }; }
            } catch (e) { return { decision:'error', message: t('network_error', 'Network error: ') + e.message }; }
        }
        function pushSurface(sf) { sf.id = 's' + (sid++); S.surfaces = [sf, ...S.surfaces].slice(0, 4); }
        // Real per-skill execution units (replaces the metaphor units).
        function unitsFor(skills, status, exit) {
            return (skills || []).filter(Boolean).map(name => ({ name, status, busy: status === 'running', exit }));
        }
        function unitsFromOutcome(resp) {
            const skills = (Array.isArray(resp.pipeline) && resp.pipeline.length) ? resp.pipeline.map(s => s.skill) : (resp.skill ? [resp.skill] : []);
            if (resp.decision !== 'executed') return unitsFor(skills, 'idle', resp.exit_code);
            const ok = resp.exit_code === 0 || resp.exit_code == null;
            return unitsFor(skills, ok ? 'done' : 'failed', resp.exit_code);
        }

        function observe(resp) {
            // Render by the surface the backend arbiter declared (minimal-sufficient).
            const surf = resp.surface || 'window';
            const title = resp.skill || boot.assistantName;
            const body = ((resp.output && resp.output.trim()) ? resp.output : (resp.message || '')) || '';
            const meta = (typeof resp.exit_code === 'number') ? 'exit ' + resp.exit_code : '';
            if (surf === 'none') return;
            if (surf === 'cue') { S.cue = { title, body: body || t('done_cue', 'Done.') }; setTimeout(() => { S.cue = null; render(); }, 4200); return; }
            if (surf === 'inline') { pushSurface({ kind: 'inline', title, taxo: t('taxo_inline', 'inline card'), body, meta }); return; }
            if (surf === 'readout') { pushSurface({ kind: 'inline', title, taxo: t('taxo_readout', 'readout'), body, meta }); return; }
            pushSurface({ kind: 'window', title, taxo: t('taxo_window', 'full window'), body, meta });
        }

        function submit() {
            const inp = document.getElementById('intent-input');
            const val = ((inp && inp.value) || '').trim();
            if (!val) return;
            if (inp) inp.value = '';
            S.input = '';
            // Everything goes through the model — including introducing yourself.
            // The planner extracts your name from any phrasing (set-user-name),
            // so onboarding is a real conversation, not client-side parsing.
            // Stay in the current zone — Ambient shows the full conversation; Focus
            // and Chill surface it in the chat dock. Make sure that dock is open so
            // the reply is visible.
            if (S.mode !== 'ambient' && S.chatDock === 'collapsed') setChatDock('open');
            S.queue.push(val);
            S._focusInput = true;
            render();
            processQueue();
        }
        // Process queued messages one at a time. Non-blocking: the input stays
        // live so more can be sent while this runs; each is answered in turn.
        // Pauses while an interactive gate (S.run) is open.
        async function processQueue() {
            if (S.busy || S.run || S.queue.length === 0) return;
            const text = S.queue[0]; // peek — the message stays shown until answered
            if (S.mode !== 'chill') S.mode = 'ambient'; // stay in the zone the intent came from
            S.run = { intent: text, stage: 'plan', decision: 'plan' }; S.units = []; S.busy = true; startBusyHints();
            log('intent · "' + text + '"', 'accent'); render();
            const resp = await post('/os/intent', { intent: text });
            S.busy = false; stopBusyHints();
            S.units = unitsFromOutcome(resp);
            const d = resp.decision;
            S.run.decision = d; S.run.message = resp.message; S.run.reason = resp.reason; S.run.skill = resp.skill; S.run.policy = resp.risk_level; S.run.arguments = resp.arguments; S.run.pipeline = resp.pipeline || [];
            refreshSuggestions(); // the system just learned something — re-adapt the chips
            refreshPrefs();       // a rename intent may have changed the assistant's name
            markWeaveStale();     // a skill (remember/…) may have grown the graph
            if (d === 'executed' && resp.skill === 'show-in-world' && resp.arguments && resp.arguments.query) {
                enterWorkspaceFocused(String(resp.arguments.query)); // visual navigation by intent
            }
            if (d === 'executed' && resp.skill === 'attach-folder' && resp.arguments && resp.arguments.folder) {
                completeAttachIntent(String(resp.arguments.folder), String(resp.arguments.connect_to || ''));
            }

            if (d === 'needs_confirmation') { S.run.stage = 'confirm'; log('planner · propose_skill · ' + (resp.skill||'')); render(); return; }
            if (d === 'dialog_exists') { S.run.stage = 'dialog_exists'; log('already open · ' + (resp.skill||''), 'accent'); fetchDialogs(); render(); return; }

            // Everything else is terminal — the exchange is in the DB now.
            S.queue.shift();
            if (d === 'open_dialog') {
                log('open dialog · ' + (resp.skill||''), 'accent');
                // A Chill chip's first pick sticks: later presses open directly.
                if (S._chillPending && resp.skill) { saveChillApp(S._chillPending, resp.skill); }
                S._chillPending = null;
                S.run = null; S.surfaces = [];
                await refreshHistory();
                // Chill-launched leisure apps (game/music/video) open right in Chill;
                // otherwise the tool surfaces in Focus.
                const zone = (S.mode === 'chill') ? 'chill' : 'focus';
                setTimeout(function () { S.mode = zone; fetchDialogs().then(render); }, 1400);
                processQueue();
                return;
            }
            if (d === 'answer') log('planner · answer');
            else if (d === 'ask') log('planner · ask (clarify)');
            else if (d === 'refuse') log('planner · refuse (safety stop)', 'danger');
            else if (d === 'executed') log('executed · ' + (resp.skill||''));
            else log('error', 'danger');
            S.run = null; S.surfaces = [];
            await refreshHistory();
            // A skill may have opened a window as a side effect (e.g. open-web-app
            // wraps a site as a dialog). If a new dialog appeared, reveal it.
            if (d === 'executed') {
                const beforeIds = (S.dialogs || []).map(x => x.id);
                await fetchDialogs();
                fetchWebApps(); // an open-web-app may have registered a new app
                const fresh = (S.dialogs || []).filter(x => beforeIds.indexOf(x.id) === -1);
                if (fresh.length) {
                    // A leisure intent that opened a window as a side effect (e.g.
                    // YouTube via open-web-app) also settles the chip's choice.
                    if (S._chillPending) { saveChillApp(S._chillPending, fresh[0].skill); }
                    if (S.mode !== 'chill') S.mode = 'focus';
                    render();
                }
            }
            S._chillPending = null; // any terminal outcome ends the pending chip pick
            processQueue(); // next queued message, if any
        }
        async function approve() {
            const r = S.run; if (!r) return;
            const isPipe = Array.isArray(r.pipeline) && r.pipeline.length > 1;
            r.stage = 'execute'; S.busy = true; startBusyHints();
            S.units = unitsFor(isPipe ? r.pipeline.map(s => s.skill) : (r.skill ? [r.skill] : []), 'running');
            log('execute · SkillExecutor · headless invoke'); render();
            const resp = isPipe
                ? await post('/os/pipeline', { intent: r.intent, steps: r.pipeline })
                : await post('/os/skill', { intent: r.intent, skill: r.skill, arguments: r.arguments || {} });
            S.busy = false; stopBusyHints();
            S.units = unitsFromOutcome(resp);
            log('observe · ' + (resp.skill||r.skill));
            // Approved & executed — the result joins the persisted thread; drop
            // the confirmed message from the queue and continue.
            S.run = null; S.surfaces = [];
            S.queue.shift();
            refreshPrefs();
            markWeaveStale();
            await refreshHistory();
            processQueue();
        }

        // ---------- dialogs (Focus / Chill zone) — persistent #wm-layer window manager ----------
        const wmLayer = document.getElementById('wm-layer');
        const winZ = {};
        let zTop = 100;

        // ---------- native bridge (OS mode) ----------
        // On the native desktop, windows open as top-level chromium --app
        // windows framed by semitexa-wm. The bridge is a tiny local daemon on
        // the machine itself; the shell just asks it to open a URL (BRIDGE is
        // resolved at boot from boot.bridgeUrl).
        //
        // The env flag only PERMITS os mode; this probe confirms the bridge is
        // actually up before we promote a single dialog. Success flips the live
        // source of truth S.windowMode to 'os'; failure leaves us on iframes.
        async function probeBridge() {
            if (!OS_PERMITTED) return;
            try {
                await fetch(BRIDGE + '/', { mode: 'no-cors' });
                S.windowMode = 'os';
            } catch (e) { /* bridge down → stay in web (iframe) mode */ }
        }
        async function nativeOpen(url) {
            try {
                const r = await bridgeFetch('/open?url=' + encodeURIComponent(url) + '&w=1100&h=700');
                return r.ok;
            } catch (e) { return false; }
        }
        // Ask the bridge to spawn a real native program (not a web view) — e.g.
        // a genuine Alpine shell for the Terminal. The capability web mode can't
        // offer: no sandbox, a real tty on the real machine.
        async function nativeOpenApp(app) {
            try {
                const r = await bridgeFetch('/open?app=' + encodeURIComponent(app));
                return r.ok;
            } catch (e) { return false; }
        }
        const nativeDiverted = new Set();
        // In OS mode a dialog is a REAL native window, not an in-page iframe.
        // A web-app resolves to its external URL; every other dialog opens its
        // own entry route (origin + d.entry — e.g. /os/app/calendar) as a
        // chromium --app window framed by semitexa-wm. Either way the
        // server-side dialog is closed so it never also renders in-page.
        async function divertDialog(d) {
            if (nativeDiverted.has(d.id)) return;
            nativeDiverted.add(d.id);
            if (d.skill === 'Terminal') {
                // A real program, not a web surface: the bridge spawns an actual
                // Alpine shell (xterm) rather than a chromium window of the web
                // console route. Web mode keeps the sandboxed console surface.
                await nativeOpenApp('terminal');
            } else {
                let url = null;
                if ((d.skill || '').indexOf('webapp:') === 0) {
                    if (!(S.webApps || []).length) await fetchWebApps();
                    const app = (S.webApps || []).find(a => 'webapp:' + a.id === d.skill);
                    url = app ? app.url : null;
                } else if (d.entry) {
                    url = location.origin + d.entry;
                }
                if (url) await nativeOpen(url);
            }
            try { await post('/os/dialog/close', { id: d.id }); } catch (e) {}
        }

        async function fetchDialogs() {
            try {
                const r = await fetch('/os/dialogs', { headers: { 'Accept': 'application/json' } });
                const j = await r.json();
                S.dialogs = j.dialogs || [];
                // Recents reflect internal skills that have been opened (web-apps
                // live in "Your apps"). Compute from the full list before any
                // OS-mode diversion empties the in-page window set.
                S.dialogs.forEach(d => { if ((d.skill || '').indexOf('webapp:') !== 0 && !S.recents.some(x => x.name === d.skill)) S.recents.unshift({ name: d.skill, icon: d.icon }); });
                S.recents = S.recents.slice(0, 6);
                if (isOsMode()) {
                    // In OS mode every dialog is a real native window (bridge),
                    // never an in-page iframe: divert them all and clear the
                    // in-page window set so syncDialogs() renders nothing here.
                    const divert = S.dialogs.slice();
                    S.dialogs = [];
                    divert.forEach(divertDialog);
                }
            } catch (e) {}
        }
        // The user's registered external apps (for the "Your apps" launcher).
        async function fetchWebApps() {
            try {
                const r = await fetch('/os/webapps', { headers: { 'Accept': 'application/json' } });
                const j = await r.json();
                S.webApps = j.apps || [];
            } catch (e) {}
        }
        async function openWebApp(id) {
            if (isOsMode()) {
                if (!(S.webApps || []).length) await fetchWebApps();
                const app = (S.webApps || []).find(a => a.id === id);
                if (app) { await nativeOpen(app.url); return; }
            }
            const before = (S.dialogs || []).length;
            await post('/os/webapp/open', { id });
            await fetchDialogs();
            if ((S.dialogs || []).length >= before) { if (S.mode !== 'chill') S.mode = 'focus'; render(); }
        }
        async function removeWebApp(id) {
            await post('/os/webapp/remove', { id });
            await fetchWebApps();
            render();
        }
        // Type-to-filter IN PLACE (no re-render → the input keeps focus + cursor).
        // An empty query shows the browse gradation; any query swaps to the flat
        // results grid, filtered by each tile's data-name (app name + summary/host),
        // and toggles the "no match" row + the clear button.
        function filterLauncher(q) {
            S.launchQuery = q;
            const query = (q || '').trim().toLowerCase();
            const clr = document.querySelector('.os-launcher__clear');
            if (clr) clr.style.display = query ? '' : 'none';
            const def = document.querySelector('.os-launcher-default');
            const res = document.querySelector('.os-launcher-results');
            if (def) def.hidden = !!query;
            if (res) res.hidden = !query;
            if (!query || !res) return;
            const grid = res.querySelector('.os-launcher-grid');
            if (!grid) return;
            let visible = 0;
            grid.querySelectorAll('.os-launch-tile').forEach(el => {
                const hit = (el.dataset.name || '').indexOf(query) !== -1;
                el.hidden = !hit; if (hit) visible++;
            });
            const empty = res.querySelector('.os-launcher-empty');
            if (empty) {
                empty.hidden = visible !== 0;
                const qs = empty.querySelector('.q'); if (qs) qs.textContent = q;
            }
        }
        // Enter in the search box opens the first visible app — reuses that tile's
        // own button handler, so UI-skills and web apps both just work.
        function openFirstLauncherMatch() {
            const grid = document.querySelector('.os-launcher-grid'); if (!grid) return;
            const el = Array.from(grid.querySelectorAll('.os-launch-tile')).find(x => !x.hidden);
            const btn = el && el.querySelector('button[data-act="open-dialog"], button[data-act="open-webapp"]');
            if (btn) btn.click();
        }
        // Pull the adaptive Ambient chips. We send the client's *local* wall-clock
        // context (hour + weekend) so suggestions match the user's real time of
        // day regardless of the server timezone. Called on entry and after each
        // intent, so the chips adapt as the system listens.
        async function refreshSuggestions() {
            try {
                const now = new Date();
                const weekend = (now.getDay() === 0 || now.getDay() === 6) ? 1 : 0;
                const r = await fetch('/os/suggestions?hour=' + now.getHours() + '&weekend=' + weekend + '&limit=4', { headers: { 'Accept': 'application/json' } });
                const d = await r.json();
                S.suggestions = Array.isArray(d.suggestions) ? d.suggestions : [];
                render();
            } catch (e) {}
        }
        // Re-read the assistant's name (it can change via a rename intent or the
        // Settings dialog) and re-render so it takes effect everywhere at once.
        async function refreshPrefs() {
            try {
                const r = await fetch('/os/preferences', { headers: { 'Accept': 'application/json' } });
                const d = await r.json();
                let changed = false;
                if (d && d.assistant_name) { boot.assistantName = d.assistant_name; changed = true; }
                // A chat command ("switch to light mode") changes the mode server-side;
                // reflect it here so the toggle + look update within a poll.
                if (d && d.theme_mode && d.theme_mode !== S.themeMode) {
                    S.themeMode = d.theme_mode;
                    try { localStorage.setItem('semitexa_os_theme_mode', d.theme_mode); } catch (e) {}
                    applyThemeMode();
                    changed = true;
                }
                // A chat command ("перейди на українську") changed the OS
                // language server-side: the string bundle rides on the BOOT
                // payload, so a full reload is the one honest way to re-render
                // every surface in the new language.
                if (d && typeof d.locale === 'string' && d.locale !== '' && d.locale !== boot.locale) {
                    location.reload();
                    return;
                }
                // Chill chips: a chat skill may have changed/reset a remembered
                // leisure app — the map is server-owned, mirror it verbatim.
                if (d && d.chill_apps && typeof d.chill_apps === 'object') S.chillApps = d.chill_apps;
                // A chat skill added/removed a layout or changed the hotkey;
                // swap the whole state in and re-arm the switcher/remapper.
                if (d && d.input_layouts) {
                    const next = normalizeInputLayouts(d.input_layouts);
                    if (JSON.stringify(next) !== JSON.stringify(S.inputLayouts)) { S.inputLayouts = next; changed = true; }
                }
                // Reconcile the wall-clock timezone once per session: the browser
                // knows where the user actually is; time-aware skills and the
                // planner's date anchor parse against this preference.
                if (d && !S.tzSynced) {
                    S.tzSynced = true;
                    try {
                        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                        if (tz && tz !== d.timezone) post('/os/preferences', { timezone: tz });
                    } catch (e) {}
                }
                if (changed) render();
            } catch (e) {}
        }
        // The persisted dialog transcript, rendered as a chat thread.
        function conversationHTML() {
            // Persisted turns + any queued (sent-but-not-yet-answered) user
            // messages, so a message shows the instant it is sent.
            const turns = (S.history || []).concat(
                (S.queue || []).map(function (text) { return { role: 'user', text: text, meta: {} }; })
            );
            const calendarSkills = ['calendar-create', 'calendar-lookup', 'Calendar'];
            const rows = turns.map(function (turn) {
                const who = turn.role === 'user' ? 'user' : 'assistant';
                const tag = (who === 'assistant' && turn.meta && turn.meta.skill)
                    ? '<span class="os-msg__tag">' + esc(turn.meta.skill) + '</span>' : '';
                // A clickable affordance to open the calendar dialog in Focus,
                // shown under calendar-related answers.
                const cta = (who === 'assistant' && turn.meta && calendarSkills.indexOf(turn.meta.skill) !== -1)
                    ? '<button type="button" class="os-msg__cta" data-act="open-calendar">' + ico('calendar', 15) + ' ' + t('open_calendar', 'Open calendar') + '</button>'
                    : '';
                return '<div class="os-msg os-msg--' + who + '"><div class="os-msg__bubble">' + esc(turn.text) + tag + '</div>' + cta + '</div>';
            }).join('');
            // The "typing" indicator is a row INSIDE the chat container, so it
            // aligns with the message bubbles instead of sliding to the far left.
            const typing = S.busy
                ? '<div class="os-msg os-msg--assistant"><div class="os-msg__bubble os-typing"><span></span><span></span><span></span></div></div>'
                : '';
            return '<div class="os-chat">' + rows + typing + '</div>';
        }
        // Load the saved transcript from the DB so returning to Ambient shows the
        // whole conversation, not a blank screen.
        async function refreshHistory() {
            try {
                const r = await fetch('/os/conversation', { headers: { 'Accept': 'application/json' } });
                const d = await r.json();
                S.history = Array.isArray(d.turns) ? d.turns : [];
                render();
            } catch (e) {}
        }
        // The OS process registry powers the Chill "Processes" panel — every
        // producer (PHP packages, iframe apps, the native bridge) reports into
        // it, so one fetch shows internal AND external work.
        async function refreshProcesses() {
            try {
                const r = await fetch('/os/process/list', { headers: { 'Accept': 'application/json' } });
                if (!r.ok) { S.processes = []; return; }
                const d = await r.json();
                S.processes = Array.isArray(d.processes) ? d.processes : [];
            } catch (e) { S.processes = []; }
        }
        // Phase 2: push, not poll. /os/process/feed re-runs on every os_process
        // write (registry reports), delivered over the page's shared KISS SSE
        // connection via ui-core's openFeedChannel. The 3.5s poll below stays
        // armed but only fires while the feed is NOT live — first paint before
        // the stream connects, no ui-core on the page, or permanent degrade.
        let procChannel = null, procFeedLive = false;
        function startProcessFeed() {
            if (procChannel || typeof window.EventSource === 'undefined') return;
            const core = window.SemitexaUi && window.SemitexaUi.core;
            if (!core || !core.openFeedChannel) return;
            procChannel = core.openFeedChannel({
                url: '/os/process/feed',
                dataEvent: 'ui.collection.data',
                errorEvent: 'ui.collection.error',
                onData: (envelope) => {
                    procFeedLive = true;
                    S.processes = (envelope && Array.isArray(envelope.data)) ? envelope.data : [];
                    if (S.screen === 'os' && S.mode === 'chill') render();
                },
                onError: () => {},
                onConnecting: () => { procFeedLive = false; }, // dead stream → poll covers the gap
                permanentPullDegrade: true,
                onPermanentDegrade: () => { procFeedLive = false; procChannel = null; },
            });
        }
        // Live skinning: the LLM skin generator writes a small `:root{}` override
        // (5 themable shell vars) to the server; the shell injects it so the WHOLE
        // interface reskins. Empty → the default navy/cyan look.
        async function applySkin() {
            try {
                const r = await fetch('/os/skin', { headers: { 'Accept': 'application/json' } });
                const d = r.ok ? await r.json() : { css: '' };
                let el = document.getElementById('os-skin');
                const css = (d && d.css) || '';
                if (!css) { if (el) el.remove(); return; }
                if (!el) { el = document.createElement('style'); el.id = 'os-skin'; document.head.appendChild(el); }
                if (el.textContent !== css) el.textContent = css;
            } catch (e) {}
        }
        // Proactive channel: the assistant speaking first. Poll /os/proactive; a
        // new item (e.g. a task auto-completed) → refresh the thread so it appears
        // in the conversation, raise a cue toast, and badge the dock if collapsed.
        async function pollProactive() {
            if (S.screen !== 'os') return;
            if (S.nodeEditor || S.nodePop) return; // don't re-render (and wipe form input) mid-edit
            try {
                const r = await fetch('/os/proactive?since=' + encodeURIComponent(PROACTIVE_CURSOR), { headers: { 'Accept': 'application/json' } });
                if (r.status === 401) { signInAgain(); return; }
                const d = await r.json();
                if (d.cursor) setProactiveCursor(d.cursor);
                const items = d.items || [];
                if (!items.length) return;
                await refreshHistory();                 // pull the new turn(s) into the thread
                // The weaver spoke — the graph it narrates already changed; show it.
                if (items.some(i => i.meta && i.meta.kind === 'woven')) markWeaveStale();
                // ONE signal per context — never both (no bell, no double-notify):
                //  • Ambient    → the conversation IS on screen; a brief toast pings it.
                //  • other tab, chat folded → a badge on the chat-open icon (the FAB).
                //  • other tab, chat open   → the turn just appears in the dock; nothing extra.
                if (S.mode === 'ambient') {
                    const last = items[items.length - 1];
                    S.cue = { title: boot.assistantName, body: last.text };
                    setTimeout(() => { S.cue = null; render(); }, 5200);
                } else if (S.chatDock === 'collapsed') {
                    S.proactiveUnseen += items.length;
                }
                blip(760);
                render();
            } catch (e) {}
        }
        async function openDialog(skill) { await post('/os/dialog/open', { skill }); await fetchDialogs(); render(); }
        // Open a REMEMBERED Chill app without the planner. Web apps go through
        // their own opener (webapp/open, or a native window in OS mode); UI
        // skills through dialog/open. If the app has vanished (uninstalled,
        // removed from the registry), forget the stale choice and fall back to
        // the normal intent flow so the assistant proposes options again.
        async function openChillApp(activity, skill, intentText) {
            blip(700);
            if (skill.indexOf('webapp:') === 0) {
                await openWebApp(skill.slice('webapp:'.length));
                if (isOsMode()) return; // native window raised — no dialog to check
                const ok = (S.dialogs || []).some(d => d.skill === skill);
                if (ok) { render(); return; }
            } else {
                await post('/os/dialog/open', { skill });
                await fetchDialogs();
                const opened = (S.dialogs || []).find(d => d.skill === skill);
                if (opened) { render(); focusDialog(opened.id); return; }
            }
            saveChillApp(activity, ''); // stale — forget it
            S._chillPending = activity;
            S.queue.push(intentText); render(); processQueue();
        }
        // Persist a chip's app choice (empty skill = forget) — shared source of
        // truth with the set-chill-app chat skill via /os/preferences.
        function saveChillApp(activity, skill) {
            if (skill) S.chillApps[activity] = skill; else delete S.chillApps[activity];
            post('/os/preferences', { chill_activity: activity, chill_skill: skill || '' });
        }
        async function dialogState(id, state) { await post('/os/dialog/state', { id, state }); await fetchDialogs(); render(); }
        async function dialogClose(id) { delete S.pos[id]; delete winZ[id]; await post('/os/dialog/close', { id }); await fetchDialogs(); render(); }

        function focusDialog(id) {
            S.focusedId = id;
            winZ[id] = ++zTop;
            const n = wmLayer.querySelector('[data-id="' + id + '"]');
            if (n) n.style.zIndex = winZ[id];
            document.querySelectorAll('.os-task[data-id]').forEach(b => {
                const d = (S.dialogs || []).find(x => x.id === b.dataset.id);
                b.classList.toggle('active', b.dataset.id === id && d && d.state !== 'minimized');
            });
        }

        function createWindow(d) {
            const node = document.createElement('div');
            // Apps size to their content: the calendar is wide, media players are
            // large, the game board is portrait-ish — everything else is default.
            const lg = (d.skill === 'Calendar' || (d.skill || '').indexOf('webapp:') === 0);
            const game = (d.skill === 'tic-tac-toe');
            node.className = 'os-win' + (lg ? ' os-win--lg' : '') + (game ? ' os-win--game' : '');
            node.dataset.id = d.id;
            node.innerHTML =
                '<div class="os-win__bar"><span class="os-win__title">' + ico(d.icon || 'app-window', 15) + ' ' + esc(d.title) + '</span>'
                + '<span class="os-win__ctl">'
                + '<button data-act="dlg-min" data-id="' + d.id + '" title="' + t('minimize', 'Minimize') + '">' + ico('minus', 15) + '</button>'
                + '<button data-maxbtn data-act="dlg-max" data-id="' + d.id + '" title="' + t('maximize', 'Maximize') + '">' + ico('square', 14) + '</button>'
                + '<button class="close" data-act="dlg-close" data-id="' + d.id + '" title="' + t('close', 'Close') + '">' + ico('x', 15) + '</button></span></div>'
                + '<div class="os-win__body"><iframe src="' + esc(d.entry || 'about:blank') + '" title="' + esc(d.title) + '"></iframe></div>'
                + ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'].map(dir => '<div class="os-win__rs" data-dir="' + dir + '"></div>').join('');
            node.addEventListener('mousedown', () => focusDialog(d.id));
            bindWindowDrag(node, d.id);
            bindWindowResize(node, d.id);
            winZ[d.id] = ++zTop;
            return node;
        }

        function bindWindowDrag(node, id) {
            const bar = node.querySelector('.os-win__bar');
            bar.addEventListener('mousedown', (e) => {
                if (e.target.closest('button')) return;
                const d = (S.dialogs || []).find(x => x.id === id);
                if (d && d.state === 'maximized') return; // no drag when fullscreen
                focusDialog(id);
                const startX = e.clientX, startY = e.clientY;
                const pos = S.pos[id] || { x: node.offsetLeft, y: node.offsetTop };
                const ox = pos.x, oy = pos.y;
                const ov = document.createElement('div'); ov.className = 'os-drag-ov'; document.body.appendChild(ov);
                const move = (ev) => {
                    const nx = Math.max(0, ox + (ev.clientX - startX)), ny = Math.max(0, oy + (ev.clientY - startY));
                    node.style.left = nx + 'px'; node.style.top = ny + 'px';
                    S.pos[id] = Object.assign({}, S.pos[id], { x: nx, y: ny }); // keep resized w/h
                };
                const up = () => { window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); ov.remove(); };
                window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
                e.preventDefault();
            });
        }

        // Drag any edge/corner to resize (mirrors the native WM's border hit
        // zones). Size joins x/y in S.pos so syncDialogs re-applies it; the
        // overlay shields the iframe from eating mousemove, like window drag.
        function bindWindowResize(node, id) {
            const MIN_W = 260, MIN_H = 160; // keep in sync with .os-win min-width/min-height
            node.querySelectorAll('.os-win__rs').forEach(h => h.addEventListener('mousedown', (e) => {
                const d = (S.dialogs || []).find(x => x.id === id);
                if (d && d.state === 'maximized') return;
                focusDialog(id);
                const dir = h.dataset.dir;
                const startX = e.clientX, startY = e.clientY;
                const ox = node.offsetLeft, oy = node.offsetTop;
                const ow = node.offsetWidth, oh = node.offsetHeight;
                const ov = document.createElement('div'); ov.className = 'os-drag-ov';
                ov.style.cursor = getComputedStyle(h).cursor; document.body.appendChild(ov);
                const move = (ev) => {
                    const dx = ev.clientX - startX, dy = ev.clientY - startY;
                    let x = ox, y = oy, w = ow, hh = oh;
                    if (dir.includes('e')) w = ow + dx;
                    if (dir.includes('s')) hh = oh + dy;
                    if (dir.includes('w')) { w = ow - dx; x = ox + dx; }
                    if (dir.includes('n')) { hh = oh - dy; y = oy + dy; }
                    if (w < MIN_W) { if (dir.includes('w')) x -= MIN_W - w; w = MIN_W; }
                    if (hh < MIN_H) { if (dir.includes('n')) y -= MIN_H - hh; hh = MIN_H; }
                    if (x < 0) { w += x; x = 0; }
                    if (y < 0) { hh += y; y = 0; }
                    node.style.left = x + 'px'; node.style.top = y + 'px';
                    node.style.width = w + 'px'; node.style.height = hh + 'px';
                    S.pos[id] = { x, y, w, h: hh };
                };
                const up = () => { window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); ov.remove(); };
                window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
                e.preventDefault(); e.stopPropagation();
            }));
        }

        // Reconcile #wm-layer with S.dialogs without recreating iframes (preserves state).
        function syncDialogs() {
            // Windows live in Focus and Chill (leisure apps: game, music, video).
            const showWindows = (S.screen === 'os' && (S.mode === 'focus' || S.mode === 'chill'));
            wmLayer.hidden = !showWindows;
            if (!showWindows) return;
            const dialogs = S.dialogs || [];
            const ids = dialogs.map(d => d.id);
            Array.from(wmLayer.children).forEach(n => { if (!ids.includes(n.dataset.id)) n.remove(); });
            let cascade = 0;
            dialogs.forEach(d => {
                let node = wmLayer.querySelector('[data-id="' + d.id + '"]');
                if (!node) { node = createWindow(d); wmLayer.appendChild(node); }
                const max = d.state === 'maximized';
                node.classList.toggle('max', max);
                node.classList.toggle('focused', d.id === S.focusedId && d.state !== 'minimized'); // cyan border, like the WM's active frame
                node.style.display = (d.state === 'minimized') ? 'none' : 'flex';
                node.style.zIndex = winZ[d.id] || (100 + (d.order || 0));
                const mb = node.querySelector('[data-maxbtn]');
                if (mb) { mb.dataset.act = max ? 'dlg-restore' : 'dlg-max'; mb.title = max ? t('restore', 'Restore') : t('maximize', 'Maximize'); mb.innerHTML = ico(max ? 'minimize-2' : 'square', 14); }
                if (max) {
                    // Clear ALL inline geometry: inline styles would beat .max's inset:0/auto.
                    node.style.left = ''; node.style.top = ''; node.style.width = ''; node.style.height = '';
                } else {
                    if (!S.pos[d.id]) S.pos[d.id] = { x: 90 + cascade * 30, y: 90 + cascade * 30 };
                    const p = S.pos[d.id];
                    node.style.left = p.x + 'px'; node.style.top = p.y + 'px';
                    node.style.width = p.w ? p.w + 'px' : ''; node.style.height = p.h ? p.h + 'px' : '';
                }
                cascade++;
            });
            drawIcons();
        }

        wmLayer.addEventListener('click', (e) => {
            const t = e.target.closest('[data-act]'); if (!t) return;
            const id = t.dataset.id;
            if (t.dataset.act === 'dlg-min') dialogState(id, 'minimized');
            else if (t.dataset.act === 'dlg-max') dialogState(id, 'maximized');
            else if (t.dataset.act === 'dlg-restore') dialogState(id, 'normal');
            else if (t.dataset.act === 'dlg-close') dialogClose(id);
        });

        // ---------- events ----------
        root.addEventListener('click', (e) => {
            // A click anywhere outside the layout switch closes its dropdown.
            if (S.layoutMenu && !e.target.closest('.os-layoutswitch')) { S.layoutMenu = false; render(); }
            // Click the modal backdrop (not its panel) closes the node editor.
            if (e.target.id === 'we-backdrop') { S.nodeEditor = null; render(); return; }
            if (e.target.id === 'nodepop-backdrop') { S.nodePop = null; render(); return; }
            const t = e.target.closest('[data-act]'); if (!t) return;
            const act = t.dataset.act;
            if (act === 'awaken') { if (S.awake!=='idle') return; S.awake='auth'; blip(540); render(); setTimeout(()=>{ S.awake='snapshot'; render(); }, 1500); }
            else if (act === 'enter-continue') { markSession(); S.screen='os'; S.mode='ambient'; blip(720); log('continued · local LLM deployment · fixture','accent'); render(); refreshSuggestions(); refreshHistory(); }
            else if (act === 'enter-fresh') { markSession(); S.screen='os'; S.mode='ambient'; blip(720); render(); refreshSuggestions(); refreshHistory(); }
            else if (act === 'mode') { S.mode = t.dataset.mode; blip(620); if (S.mode === 'ambient') S.proactiveUnseen = 0; if (S.mode === 'focus') S._focusLauncher = true; render(); if (S.mode === 'focus' || S.mode === 'chill') { fetchDialogs().then(render); if (S.mode === 'focus') fetchWebApps().then(render); if (S.mode === 'chill') { startProcessFeed(); refreshProcesses().then(render); } } if (S.mode === 'ambient') refreshHistory(); }
            else if (act === 'xray') { S.xray = !S.xray; blip(700); render(); }
            else if (act === 'theme-mode') { setThemeMode(t.dataset.mode); blip(640); render(); }
            else if (act === 'layout-menu') { S.layoutMenu = !S.layoutMenu; blip(620); render(); }
            else if (act === 'set-layout') { S.layoutMenu = false; setInputLayout(t.dataset.code); blip(700); render(); }
            else if (act === 'ambient-view') {
                const v = t.dataset.view === 'workspace' ? 'workspace' : 'chat';
                if (v !== S.ambientView) { S.ambientView = v; try { localStorage.setItem('semitexa_os_ambient_view', v); } catch (e) {} if (v === 'workspace') weaveLoaded = false; }
                blip(620); render();
            }
            else if (act === 'weave-save') { weaveSaveNode(); }
            else if (act === 'weave-cancel') { S.nodeEditor = null; render(); }
            else if (act === 'weave-add-open') { S.nodeEditor = { adding: true, parent: (S.nodeEditor && S.nodeEditor.node) || null }; render(); }
            else if (act === 'weave-add-save') { weaveAddNode(); }
            else if (act === 'weave-zoom-in') { weaveZoom(0.75); }
            else if (act === 'weave-zoom-out') { weaveZoom(1.4); }
            else if (act === 'weave-fit') { if (weaveGraph) weaveGraph.zoomToFit(500, 70); }
            else if (act === 'pop-add-save') { popAdd(); }
            else if (act === 'pop-edit') { const n = S.nodePop && S.nodePop.node; S.nodePop = null; if (n) openNodeEditor(n); }
            else if (act === 'pop-remove') { popRemove(); }
            else if (act === 'pop-focus') { const pn = S.nodePop && S.nodePop.node; if (pn) weaveFocusOn(pn.id, pn.label); }
            else if (act === 'weave-unfocus') { weaveFocusClear(); }
            else if (act === 'chatdock-toggle') { setChatDock(S.chatDock === 'open' ? 'collapsed' : 'open'); if (S.chatDock === 'open') { S._focusInput = true; S.proactiveUnseen = 0; } blip(600); render(); }
            else if (act === 'submit') { S._focusInput = true; submit(); }
            else if (act === 'suggest') { S.input = t.dataset.text; S._focusInput = true; render(); }
            else if (act === 'run-skill') {
                // A Chill chip with a remembered app opens it DIRECTLY — the
                // planner only runs on first use or after a "change it" reset.
                const activity = t.dataset.chill || '';
                const remembered = activity && S.chillApps[activity];
                if (remembered) { openChillApp(activity, remembered, t.dataset.text); }
                else {
                    if (activity) S._chillPending = activity; // remember what this intent was for
                    if (S.mode !== 'ambient' && S.chatDock === 'collapsed') setChatDock('open');
                    S.queue.push(t.dataset.text); S._focusInput = true; render(); processQueue();
                }
            }
            else if (act === 'open-dialog') { openDialog(t.dataset.skill); }
            else if (act === 'open-webapp') { openWebApp(t.dataset.id); }
            else if (act === 'remove-webapp') { removeWebApp(t.dataset.id); }
            else if (act === 'launcher-clear') { const li = document.getElementById('launcher-input'); if (li) li.value = ''; filterLauncher(''); if (li) li.focus(); }
            else if (act === 'open-calendar') {
                // Open the Calendar dialog in Focus — or focus it if already open.
                fetchDialogs().then(function () {
                    const existing = (S.dialogs || []).find(function (d) { return d.skill === 'Calendar'; });
                    if (existing) {
                        S.mode = 'focus'; render();
                        existing.state === 'minimized'
                            ? dialogState(existing.id, 'normal').then(function () { focusDialog(existing.id); })
                            : focusDialog(existing.id);
                    } else {
                        openDialog('Calendar').then(function () { S.mode = 'focus'; render(); });
                    }
                });
            }
            else if (act === 'task-click') {
                const id = t.dataset.id; const d = (S.dialogs || []).find(x => x.id === id);
                if (!d) return;
                if (d.state === 'minimized') { dialogState(id, 'normal').then(() => focusDialog(id)); }
                else if (S.focusedId === id) { dialogState(id, 'minimized'); }
                else { focusDialog(id); }
            }
            else if (act === 'dlg-open-another') { const sk = t.dataset.skill; S.run = null; S.queue.shift(); openDialog(sk).then(() => { S.mode = 'focus'; render(); }); refreshHistory(); processQueue(); }
            else if (act === 'dlg-switch') {
                const sk = t.dataset.skill; S.run = null; S.queue.shift(); S.mode = 'focus';
                fetchDialogs().then(() => {
                    render();
                    const d = (S.dialogs || []).find(x => x.skill === sk);
                    if (d) { d.state === 'minimized' ? dialogState(d.id, 'normal').then(() => focusDialog(d.id)) : focusDialog(d.id); }
                });
                refreshHistory(); processQueue();
            }
            else if (act === 'approve') approve();
            else if (act === 'dismiss') { S.run = null; S.surfaces = []; S.queue.shift(); refreshHistory(); processQueue(); }
            else if (act === 'close-surface') { S.surfaces = S.surfaces.filter(x => x.id !== t.dataset.id); render(); }
        });
        root.addEventListener('keydown', (e) => {
            if (e.target.id === 'intent-input' && e.key === 'Enter') { e.preventDefault(); S._focusInput = true; submit(); }
            else if (e.target.id === 'launcher-input') {
                if (e.key === 'Enter') { e.preventDefault(); openFirstLauncherMatch(); }
                else if (e.key === 'Escape') { e.preventDefault(); e.target.value = ''; filterLauncher(''); }
            }
            else if (e.target.id === 'pop-title') {
                if (e.key === 'Enter') { e.preventDefault(); popAdd(); }
                else if (e.key === 'Escape') { e.preventDefault(); S.nodePop = null; render(); }
            }
        });
        root.addEventListener('input', (e) => {
            if (e.target.id === 'intent-input') S.input = e.target.value;
            else if (e.target.id === 'launcher-input') filterLauncher(e.target.value);
        });

        // Web mode: the Settings iframe posts a message to its parent shell so a
        // rename takes effect everywhere without a reload. (In OS mode Settings is
        // a separate native window whose parent is NOT the shell — this never
        // fires there; the focus re-sync below covers that case instead.)
        window.addEventListener('message', (e) => {
            const m = e.data;
            if (m && m.type === 'os:weave-changed') {
                markWeaveStale(); // a Files-app attach (or other embedded surface) grew the graph
            }
            if (m && m.type === 'os:prefs-changed') {
                if (m.assistantName) boot.assistantName = m.assistantName;
                if (m.userName) boot.userName = m.userName;
                render();
            }
        });

        // OS mode: apps are separate native windows, so cross-window state can't
        // ride postMessage. The server (/os/preferences) is the source of truth,
        // so re-sync from it whenever the shell window regains focus — e.g. after
        // the user finishes in a native Settings window and switches back.
        window.addEventListener('focus', () => { if (isOsMode()) refreshPrefs(); });

        // Boot-time prefs sync — also reconciles the browser's timezone with the
        // server preference (see refreshPrefs), independent of the first intent.
        refreshPrefs();

        setInterval(() => { S.clock = new Date(); if (S.screen==='os') { const c = root.querySelector('.os-clock__time'); if (c) c.textContent = fmtTime(S.clock); const dt = root.querySelector('.os-clock__date'); if (dt) dt.textContent = fmtDate(S.clock); } if (S.themeMode === 'auto') applyThemeMode(); }, 1000);

        // Restore the real State Snapshot (OS-owned session log) for the Awakening screen.
        fetch('/os/snapshot', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json()).then(s => {
                S.snapshot = s;
                if (Array.isArray(s.recent) && s.recent.length) {
                    S.log = s.recent.slice().reverse().map(e => ({ t: 'session · ' + (e.skill || e.intent || e.decision), tone: 'mute' }));
                }
                render();
            }).catch(() => {});

        render();
        // Resolve the window-hosting mode (bridge probe) BEFORE restoring dialogs
        // so OS-mode windows are promoted to native from the first paint instead
        // of flashing as iframes first. In web mode probeBridge resolves at once.
        probeBridge().then(() => {
            // Resuming a session on reload: land in Ambient with the conversation,
            // suggestions and any open dialogs restored.
            if (S.screen === 'os') { refreshHistory(); refreshSuggestions(); fetchDialogs().then(render); }
        });
        // Proactive-message poll — seed the cursor first (no toast for history),
        // then watch for the assistant speaking first (e.g. a task finished).
        pollProactive();
        setInterval(pollProactive, 6000);
        // Apply any active skin on boot, and re-check on the poll cadence so a
        // skin generated by a chat intent takes effect within a few seconds.
        applySkin();
        setInterval(applySkin, 4000);
        // Keep the Chill processes panel live while it's open (task progress ticks).
        setInterval(() => { if (S.screen === 'os' && S.mode === 'chill' && !procFeedLive) refreshProcesses().then(render); }, 3500);
    })();
