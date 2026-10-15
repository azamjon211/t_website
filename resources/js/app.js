/**
 * Telegram-style chat client.
 *
 * Realtime model: the client polls /api/sync with a server-issued cursor. The server returns every
 * message created, edited, read or deleted since then (with a small overlap), and the client upserts
 * them by id — so duplicates are harmless and nothing is missed. When the Reverb websocket is up, the
 * server pings `App.Models.User.{id}` whenever something changes, the client syncs immediately and
 * the poll slows down; if the socket is down, polling alone keeps everything working.
 */

import './echo';

const root = document.getElementById('app');
if (root) {
    initChat();
}

function initChat() {
    const boot = window.__CHAT__;
    const me = boot.me;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const POLL_ACTIVE_MS = 1500;
    const POLL_HIDDEN_MS = 6000;
    const POLL_SOCKET_MS = 5000;
    const MAX_IMAGE_SIDE = 2048;
    const COMPRESS_MIN_BYTES = 400 * 1024;
    const STORY_VIEWERS_POLL_MS = 5000;
    const STORIES_POLL_MS = 10000;
    const AVATAR_GRADIENTS = [
        ['#ff885e', '#ff516a'], ['#ffcd6a', '#ffa85c'], ['#82b1ff', '#665fff'], ['#a0de7e', '#54cb68'],
        ['#53edd6', '#28c9b7'], ['#72d5fd', '#2a9ef1'], ['#e0a2f3', '#d669ed'],
    ];

    // ---------------------------------------------------------------- state
    const state = {
        chats: new Map(),            // partnerId -> { user, last_message, unread }
        users: new Map(),            // userId -> contact
        messages: new Map(),         // partnerId -> Map(messageId -> message)
        hasMore: new Map(),          // partnerId -> bool
        loaded: new Set(),           // partnerIds whose history was fetched
        seen: new Map(),             // messageId -> last synced JSON, to ignore poll overlap
        storyGroups: [],             // [{ user, is_me, stories: [...] }]
        peer: null,                  // open chat contact
        cursor: null,
        searchResults: null,
        replyTo: null,
        editing: null,
        loadingOlder: false,
        tempSeq: 0,
        lastTypingSent: 0,
        newWhileAway: 0,
    };
    state.users.set(me.id, me);

    // ---------------------------------------------------------------- elements
    const $ = (id) => document.getElementById(id);
    const el = {
        chatList: $('chatList'), search: $('search'), menuBtn: $('menuBtn'), menu: $('menu'),
        savedBtn: $('savedBtn'), themeBtn: $('themeBtn'),
        emptyState: $('emptyState'), inner: $('conversationInner'), backBtn: $('backBtn'),
        peerAvatar: $('peerAvatar'), peerName: $('peerName'), peerStatus: $('peerStatus'),
        scroll: $('messagesScroll'), messages: $('messages'),
        scrollDown: $('scrollDown'), scrollDownBadge: $('scrollDownBadge'),
        input: $('input'), sendBtn: $('sendBtn'), attachBtn: $('attachBtn'), fileInput: $('fileInput'),
        ctx: $('composerContext'), ctxTitle: $('composerContextTitle'), ctxBody: $('composerContextBody'),
        ctxClose: $('composerContextClose'), ctxIcon: $('composerContextIcon'),
        dropOverlay: $('dropOverlay'), contextMenu: $('contextMenu'),
        lightbox: $('lightbox'), lightboxBody: $('lightboxBody'), lightboxClose: $('lightboxClose'),
        lightboxDownload: $('lightboxDownload'), toast: $('toast'),
    };

    // ---------------------------------------------------------------- helpers
    async function api(method, url, body) {
        const options = {
            method,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        };
        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        const res = await fetch(url, options);
        if (res.status === 401 || res.status === 419) {
            window.location.href = '/login';
            throw new Error('unauthenticated');
        }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const firstError = data.errors ? Object.values(data.errors)[0][0] : null;
            throw new Error(firstError || data.message || `HTTP ${res.status}`);
        }
        return data;
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }

    function linkify(text) {
        return escapeHtml(text).replace(
            /(https?:\/\/[^\s<]+[^\s<.,:;"')\]!?])/g,
            (url) => `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`,
        );
    }

    function h(html) {
        const t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstElementChild;
    }

    function initials(name) {
        const parts = String(name || '?').trim().split(/\s+/);
        return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?';
    }

    function paintAvatar(node, user, { saved = false, ring = true } = {}) {
        node.classList.toggle('saved', saved);
        node.classList.toggle('online', !saved && !!user.online && user.id !== me.id);
        node.classList.toggle('story-unseen', ring && !saved && storyStatus(user.id) === 'unseen');
        node.classList.toggle('story-seen', ring && !saved && storyStatus(user.id) === 'seen');
        if (saved) {
            node.innerHTML = '<svg viewBox="0 0 24 24"><path d="M6 3h12v18l-6-4-6 4z"/></svg>';
            node.style.removeProperty('--a1');
            node.style.removeProperty('--a2');
            return;
        }
        const [a1, a2] = AVATAR_GRADIENTS[user.id % AVATAR_GRADIENTS.length];
        node.style.setProperty('--a1', a1);
        node.style.setProperty('--a2', a2);
        if (user.avatar_url) {
            const current = node.querySelector('img');
            if (!current || current.getAttribute('src') !== user.avatar_url) {
                const img = document.createElement('img');
                img.src = user.avatar_url;
                img.alt = '';
                img.loading = 'lazy';
                node.replaceChildren(img);
            }
        } else {
            node.textContent = initials(user.name);
        }
    }

    /** @returns {'unseen'|'seen'|null} */
    function storyStatus(userId) {
        const group = state.storyGroups?.find((g) => g.user.id === userId);
        if (!group) return null;
        return group.stories.some((s) => !s.seen) ? 'unseen' : 'seen';
    }

    const pad = (n) => String(n).padStart(2, '0');
    const timeOf = (iso) => { const d = new Date(iso); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };
    const dayKey = (iso) => { const d = new Date(iso); return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`; };
    const MONTHS = ['yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avgust', 'sentabr', 'oktabr', 'noyabr', 'dekabr'];

    function dayLabel(iso) {
        const d = new Date(iso);
        const today = new Date();
        const yesterday = new Date(); yesterday.setDate(today.getDate() - 1);
        if (dayKey(d) === dayKey(today)) return 'Bugun';
        if (dayKey(d) === dayKey(yesterday)) return 'Kecha';
        const label = `${d.getDate()}-${MONTHS[d.getMonth()]}`;
        return d.getFullYear() === today.getFullYear() ? label : `${label}, ${d.getFullYear()}`;
    }

    function listTime(iso) {
        const d = new Date(iso);
        const now = new Date();
        if (dayKey(d) === dayKey(now)) return timeOf(iso);
        if (now - d < 6 * 864e5) return ['Yak', 'Dush', 'Sesh', 'Chor', 'Pay', 'Jum', 'Shan'][d.getDay()];
        return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${String(d.getFullYear()).slice(2)}`;
    }

    function formatSize(bytes) {
        if (!bytes && bytes !== 0) return '';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0; let n = bytes;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
    }

    function lastSeenText(user) {
        if (user.id === me.id) return '';
        if (user.online) return 'online';
        if (!user.last_seen_at) return 'yaqinda bo\'lgan';
        const d = new Date(user.last_seen_at);
        const diffMin = Math.floor((Date.now() - d) / 60000);
        if (diffMin < 1) return 'hozirgina bo\'lgan';
        if (diffMin < 60) return `${diffMin} daqiqa oldin bo'lgan`;
        if (dayKey(d) === dayKey(new Date())) return `bugun ${timeOf(d)} da bo'lgan`;
        return `${dayLabel(d.toISOString())} ${timeOf(d)} da bo'lgan`;
    }

    function previewText(message) {
        if (!message) return '';
        if (message.deleted) return 'Xabar o\'chirildi';
        if (message.type === 'sticker') return `${stickerEmoji(message.sticker)} Stiker`;
        const label = { image: '🖼 Rasm', video: '🎬 Video', audio: '🎵 Audio', gif: '🎞 GIF', file: `📎 ${message.attachment?.name || 'Fayl'}` }[message.type];
        if (!label && message.story) return `📖 ${message.body || 'Story\'ga javob'}`;
        if (label && message.body) return `${label.split(' ')[0]} ${message.body}`;
        return label || message.body || '';
    }

    function stickerUrl(code) {
        return `https://fonts.gstatic.com/s/e/notoemoji/latest/${code}/512.webp`;
    }

    function stickerEmoji(code) {
        return code ? String.fromCodePoint(...code.split('_').map((part) => parseInt(part, 16))) : '';
    }

    function displayName(user) {
        return user.id === me.id ? 'Saved Messages' : user.name;
    }

    function showToast(text) {
        el.toast.textContent = text;
        el.toast.hidden = false;
        clearTimeout(showToast.timer);
        showToast.timer = setTimeout(() => { el.toast.hidden = true; }, 3000);
    }

    const ICONS = {
        check: '<svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
        checks: '<svg viewBox="0 0 24 24"><path d="M2 12.5l4.5 4.5L16 7.5M10.5 16l1 1L21 7.5"/></svg>',
        clock: '<svg class="clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        file: '<svg viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>',
        download: '<svg viewBox="0 0 24 24"><path d="M12 4v12M6 10l6 6 6-6M4 20h16"/></svg>',
        reply: '<svg viewBox="0 0 24 24"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/></svg>',
        edit: '<svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/></svg>',
        copy: '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/></svg>',
        trash: '<svg viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>',
        error: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>',
    };

    // ---------------------------------------------------------------- theme
    const THEME_KEY = 'chat-theme';
    try {
        const saved = localStorage.getItem(THEME_KEY);
        if (saved) document.documentElement.dataset.theme = saved;
    } catch { /* storage unavailable */ }

    el.themeBtn.addEventListener('click', () => {
        const isDark = document.documentElement.dataset.theme
            ? document.documentElement.dataset.theme === 'dark'
            : matchMedia('(prefers-color-scheme: dark)').matches;
        const next = isDark ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { localStorage.setItem(THEME_KEY, next); } catch { /* ignore */ }
        closeMenu();
    });

    // ---------------------------------------------------------------- menu
    function closeMenu() { el.menu.hidden = true; }
    el.menuBtn.addEventListener('click', (e) => { e.stopPropagation(); el.menu.hidden = !el.menu.hidden; });
    document.addEventListener('click', (e) => { if (!el.menu.contains(e.target)) closeMenu(); });
    el.savedBtn.addEventListener('click', () => { closeMenu(); openChat(me); });
    paintAvatar(el.menu.querySelector('.avatar'), me, { ring: false });

    // ================================================================ SIDEBAR
    function renderSidebar() {
        el.chatList.replaceChildren();

        if (state.searchResults !== null) {
            const label = h('<div class="list-label">Global qidiruv natijalari</div>');
            el.chatList.append(label);
            if (state.searchResults.length === 0) {
                el.chatList.append(h('<div class="list-empty">Hech kim topilmadi.<br>Username to\'g\'ri yozilganini tekshiring.</div>'));
                return;
            }
            for (const user of state.searchResults) {
                const item = h(`
                    <button type="button" class="chat-item" role="listitem">
                        <div class="avatar"></div>
                        <div class="chat-item-body">
                            <div class="chat-item-row"><div class="chat-item-name"></div></div>
                            <div class="chat-item-row"><div class="chat-item-preview"></div></div>
                        </div>
                    </button>`);
                paintAvatar(item.querySelector('.avatar'), user, { saved: user.id === me.id });
                item.querySelector('.chat-item-name').textContent = displayName(user);
                item.querySelector('.chat-item-preview').textContent = `@${user.username}`;
                item.addEventListener('click', () => {
                    el.search.value = '';
                    state.searchResults = null;
                    openChat(user);
                    renderSidebar();
                });
                el.chatList.append(item);
            }
            return;
        }

        const chats = [...state.chats.values()].sort((a, b) => b.last_message.id - a.last_message.id);
        if (chats.length === 0) {
            el.chatList.append(h('<div class="list-empty">Hali suhbatlar yo\'q.<br>Yuqoridagi qidiruvga do\'stingizning <b>@username</b>ini yozing.</div>'));
            return;
        }

        for (const chat of chats) {
            const { user, last_message: last, unread } = chat;
            const isOut = last.sender_id === me.id;
            const item = h(`
                <button type="button" class="chat-item" role="listitem">
                    <div class="avatar"></div>
                    <div class="chat-item-body">
                        <div class="chat-item-row">
                            <div class="chat-item-name"></div>
                            <div class="chat-item-time"></div>
                        </div>
                        <div class="chat-item-row">
                            <div class="chat-item-preview"></div>
                        </div>
                    </div>
                </button>`);
            item.classList.toggle('active', state.peer?.id === user.id);
            paintAvatar(item.querySelector('.avatar'), user, { saved: user.id === me.id });
            item.querySelector('.avatar').addEventListener('click', (e) => {
                if (user.id !== me.id && storyStatus(user.id)) { e.stopPropagation(); openStoryViewer(user.id); }
            });
            item.querySelector('.chat-item-name').textContent = displayName(user);

            const time = item.querySelector('.chat-item-time');
            if (isOut && user.id !== me.id && !last.deleted) {
                time.insertAdjacentHTML('beforeend', last.read_at ? ICONS.checks : ICONS.check);
            }
            time.append(listTime(last.created_at || new Date().toISOString()));

            const preview = item.querySelector('.chat-item-preview');
            if (isOut && user.id !== me.id) {
                const sender = document.createElement('span');
                sender.className = 'sender';
                sender.textContent = 'Siz: ';
                preview.append(sender);
            }
            preview.append(previewText(last));

            if (unread > 0) {
                item.querySelectorAll('.chat-item-row')[1].append(h(`<div class="unread-badge">${unread > 999 ? '999+' : unread}</div>`));
            }

            item.addEventListener('click', () => openChat(user));
            el.chatList.append(item);
        }
    }

    async function loadChats() {
        const { data } = await api('GET', '/api/chats');
        state.chats.clear();
        for (const chat of data) {
            state.chats.set(chat.user.id, chat);
            state.users.set(chat.user.id, chat.user);
        }
        renderSidebar();
        updateTitle();
    }

    let reloadChatsTimer;
    function scheduleReloadChats() {
        clearTimeout(reloadChatsTimer);
        reloadChatsTimer = setTimeout(() => loadChats().catch(() => {}), 250);
    }

    function updateTitle() {
        const unread = [...state.chats.values()].reduce((sum, c) => sum + (c.unread || 0), 0);
        document.title = unread > 0 ? `(${unread}) Chat` : 'Chat';
    }

    let searchTimer;
    let searchSeq = 0;
    el.search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = el.search.value.trim();
        if (!q) {
            state.searchResults = null;
            renderSidebar();
            return;
        }
        searchTimer = setTimeout(async () => {
            const seq = ++searchSeq;
            try {
                const { data } = await api('GET', `/api/users/search?q=${encodeURIComponent(q)}`);
                if (seq !== searchSeq || !el.search.value.trim()) return;
                state.searchResults = data;
                renderSidebar();
            } catch (e) { showToast(e.message); }
        }, 250);
    });
    el.search.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { el.search.value = ''; state.searchResults = null; renderSidebar(); }
    });

    // ================================================================ CONVERSATION
    function messagesOf(peerId) {
        if (!state.messages.has(peerId)) state.messages.set(peerId, new Map());
        return state.messages.get(peerId);
    }

    function partnerOf(message) {
        return message.sender_id === me.id ? message.receiver_id : message.sender_id;
    }

    async function openChat(user, { pushHistory = true } = {}) {
        if (state.peer?.id === user.id) {
            root.classList.add('chat-open');
            return;
        }
        state.peer = user;
        state.users.set(user.id, { ...state.users.get(user.id), ...user });
        state.replyTo = null;
        state.editing = null;
        state.newWhileAway = 0;
        renderComposerContext();
        el.input.value = drafts.get(user.id) || '';
        autoGrow();

        el.emptyState.hidden = true;
        el.inner.hidden = false;
        root.classList.add('chat-open');
        renderHeader();
        renderSidebar();

        if (pushHistory) {
            history.pushState({ peer: user.username }, '', `#@${user.username}`);
        }

        el.messages.replaceChildren();
        nodeCache.clear();

        if (!state.loaded.has(user.id)) {
            el.messages.append(h('<div class="loader-row"><div class="spinner"></div></div>'));
            try {
                const res = await api('GET', `/api/chats/${user.id}/messages`);
                if (state.peer?.id !== user.id) return;
                const store = messagesOf(user.id);
                for (const m of res.data) store.set(m.id, m);
                state.hasMore.set(user.id, res.has_more);
                state.loaded.add(user.id);
            } catch (e) {
                showToast(e.message);
                return;
            }
            el.messages.replaceChildren();
        }

        renderMessages();
        scrollToBottom(false);
        markRead();
        if (matchMedia('(min-width: 769px)').matches) el.input.focus();
    }

    function closeChat({ pushHistory = true } = {}) {
        root.classList.remove('chat-open');
        if (pushHistory && location.hash) history.pushState({}, '', location.pathname);
        if (matchMedia('(max-width: 768px)').matches) {
            setTimeout(() => {
                if (!root.classList.contains('chat-open')) {
                    state.peer = null;
                    el.inner.hidden = true;
                    el.emptyState.hidden = false;
                    renderSidebar();
                }
            }, 250);
        }
    }

    el.backBtn.addEventListener('click', () => closeChat());

    window.addEventListener('popstate', async () => {
        const match = location.hash.match(/^#@([a-z0-9_]+)$/i);
        if (match) {
            await openChatByUsername(match[1], false);
        } else if (state.peer) {
            closeChat({ pushHistory: false });
        }
    });

    async function openChatByUsername(username, pushHistory) {
        const known = [...state.users.values()].find((u) => u.username === username.toLowerCase());
        if (known) return openChat(known, { pushHistory });
        try {
            const { data } = await api('GET', `/api/users/${encodeURIComponent(username.toLowerCase())}`);
            openChat(data, { pushHistory });
        } catch {
            showToast('Foydalanuvchi topilmadi');
        }
    }

    function renderHeader() {
        const peer = state.users.get(state.peer.id) || state.peer;
        paintAvatar(el.peerAvatar, peer, { saved: peer.id === me.id });
        el.peerName.textContent = displayName(peer);
        if (peer.typing) {
            el.peerStatus.textContent = 'yozmoqda...';
            el.peerStatus.classList.add('accent');
        } else {
            el.peerStatus.textContent = peer.id === me.id ? '' : lastSeenText(peer);
            el.peerStatus.classList.toggle('accent', !!peer.online);
        }
    }

    // ---------------------------------------------------------------- message rendering (keyed)
    const nodeCache = new Map(); // key -> { node, sig }

    function sortedMessages(peerId) {
        return [...messagesOf(peerId).values()]
            .filter((m) => !m.deleted)
            .sort((a, b) => {
                const at = typeof a.id === 'string', bt = typeof b.id === 'string';
                if (at !== bt) return at ? 1 : -1;
                return at ? a.seq - b.seq : a.id - b.id;
            });
    }

    function contentSignature(m) {
        return JSON.stringify([m.body, m.edited_at, m.type, m.attachment?.url, m.gif?.url, m.sticker, m.story?.id, m.story?.expired, m.reply_to?.id, m.reply_to?.body, m.reply_to?.type, m.failed, !!m.pending]);
    }

    function renderMessages() {
        if (!state.peer) return;
        const peerId = state.peer.id;
        const list = sortedMessages(peerId);
        const desired = [];

        if (state.hasMore.get(peerId)) desired.push({ key: 'older', node: () => h('<div class="loader-row older"><div class="spinner"></div></div>') });

        let prevDay = null;
        list.forEach((m, i) => {
            const created = m.created_at || new Date().toISOString();
            const day = dayKey(created);
            if (day !== prevDay) {
                desired.push({ key: `day-${day}`, node: () => h(`<div class="date-sep">${escapeHtml(dayLabel(created))}</div>`) });
                prevDay = day;
            }
            const prev = list[i - 1];
            const next = list[i + 1];
            const sameAsPrev = prev && prev.sender_id === m.sender_id && dayKey(prev.created_at || created) === day;
            const sameAsNext = next && next.sender_id === m.sender_id && dayKey(next.created_at || created) === day;
            desired.push({ key: `m-${m.id}`, message: m, groupStart: !sameAsPrev, groupEnd: !sameAsNext });
        });

        const keep = new Set(desired.map((d) => d.key));
        for (const [key, entry] of nodeCache) {
            if (!keep.has(key)) { entry.node.remove(); nodeCache.delete(key); }
        }

        let cursor = el.messages.firstElementChild;
        for (const item of desired) {
            let entry = nodeCache.get(item.key);
            if (item.message) {
                const sig = contentSignature(item.message);
                if (!entry || entry.sig !== sig) {
                    const node = buildMessageNode(item.message);
                    if (entry) entry.node.replaceWith(node);
                    entry = { node, sig };
                    nodeCache.set(item.key, entry);
                }
                entry.node.classList.toggle('group-start', item.groupStart);
                entry.node.classList.toggle('group-end', item.groupEnd);
                updateMeta(entry.node, item.message);
            } else if (!entry) {
                entry = { node: item.node(), sig: '' };
                nodeCache.set(item.key, entry);
            }
            if (entry.node !== cursor) {
                el.messages.insertBefore(entry.node, cursor);
            } else {
                cursor = cursor.nextElementSibling;
            }
        }
        // drop stray nodes (e.g. initial loader)
        while (cursor) { const next = cursor.nextElementSibling; cursor.remove(); cursor = next; }
    }

    function buildMessageNode(m) {
        const out = m.sender_id === me.id;
        const node = h(`<div class="msg ${out ? 'out' : 'in'}"><div class="bubble"></div></div>`);
        node.dataset.id = m.id;
        node.classList.toggle('failed', !!m.failed);
        const bubble = node.firstElementChild;

        if (m.reply_to) {
            const author = m.reply_to.sender_id === me.id ? 'Siz' : (state.users.get(m.reply_to.sender_id)?.name || '');
            const quote = h('<div class="reply-quote"><div class="reply-quote-name"></div><div class="reply-quote-text"></div></div>');
            quote.querySelector('.reply-quote-name').textContent = author;
            quote.querySelector('.reply-quote-text').textContent = m.reply_to.type === 'deleted'
                ? 'O\'chirilgan xabar'
                : previewText({ ...m.reply_to, attachment: { name: m.reply_to.attachment_name } });
            quote.addEventListener('click', () => jumpTo(m.reply_to.id));
            bubble.append(quote);
        }

        if (m.story) bubble.append(buildStoryQuote(m));

        if (m.type === 'sticker' && m.sticker) {
            const sticker = h('<img class="sticker" alt="" width="160" height="160" decoding="async">');
            sticker.src = stickerUrl(m.sticker);
            sticker.alt = stickerEmoji(m.sticker);
            bubble.append(sticker);
            bubble.classList.add('sticker-only');
        } else if (m.type === 'gif' && m.gif) {
            const media = h('<div class="media gif"><img alt="GIF" decoding="async"></div>');
            const img = media.querySelector('img');
            Object.assign(img, { src: m.gif.url, width: m.gif.width, height: m.gif.height });
            img.addEventListener('load', () => keepBottomIfNear());
            bubble.append(media);
        }

        const att = m.attachment;
        const src = att?.url || m.localUrl;
        if (m.type === 'image' && src) {
            const media = h('<div class="media"><img alt="" loading="lazy" decoding="async"></div>');
            const img = media.querySelector('img');
            img.src = src;
            img.addEventListener('load', () => keepBottomIfNear());
            media.addEventListener('click', () => !m.pending && openLightbox(m));
            bubble.append(media);
        } else if (m.type === 'video' && src) {
            const media = h('<div class="media"><video controls preload="metadata" playsinline></video></div>');
            media.querySelector('video').src = src;
            media.querySelector('video').addEventListener('loadedmetadata', () => keepBottomIfNear());
            bubble.append(media);
        } else if (m.type === 'audio' && src) {
            const audio = h('<audio class="audio" controls preload="metadata"></audio>');
            audio.src = src;
            bubble.append(audio);
        } else if (m.type === 'file' || ((m.type === 'audio' || m.type === 'video' || m.type === 'image') && !src)) {
            const file = h(`<a class="file" target="_blank" rel="noopener"><div class="file-icon">${ICONS.download}</div><div style="min-width:0"><div class="file-name"></div><div class="file-size"></div></div></a>`);
            if (att) file.href = att.download_url;
            file.querySelector('.file-name').textContent = att?.name || m.localName || 'Fayl';
            file.querySelector('.file-size').textContent = formatSize(att?.size ?? m.localSize);
            bubble.append(file);
        }

        if (m.pending && m.progress !== undefined) {
            bubble.append(h('<div class="upload-progress"><div style="width:0%"></div></div>'));
        }

        const meta = h('<span class="meta"></span>');
        if (m.body) {
            const text = h('<div class="bubble-text"></div>');
            text.innerHTML = linkify(m.body);
            text.append(meta);
            bubble.append(text);
        } else {
            bubble.append(meta);
            if (m.type === 'image' || m.type === 'video' || m.type === 'gif') bubble.classList.add('media-only');
        }

        if (m.failed) {
            bubble.append(h(`<div class="msg-error">${ICONS.error.replace('<svg', '<svg style="width:14px;height:14px;vertical-align:-2px"')} ${escapeHtml(m.failed)}</div>`));
        }

        node.addEventListener('contextmenu', (e) => { e.preventDefault(); openContextMenu(e.clientX, e.clientY, m.id); });
        attachLongPress(node, (x, y) => openContextMenu(x, y, m.id));
        node.addEventListener('dblclick', (e) => {
            if (e.target.closest('a, video, audio, .media') || typeof m.id === 'string') return;
            startReply(messagesOf(state.peer.id).get(m.id));
        });
        return node;
    }

    // The story a message answers: a thumbnail while it is alive, a placeholder once it has expired.
    function buildStoryQuote(m) {
        const quote = h('<div class="story-quote"><div class="story-quote-thumb"></div><div class="story-quote-text"><div class="story-quote-name"></div><div class="story-quote-caption"></div></div></div>');
        const thumb = quote.querySelector('.story-quote-thumb');
        const authorId = m.receiver_id;
        const author = authorId === me.id ? 'Sizning story\'ingiz' : `${state.users.get(authorId)?.name || ''} story'si`;
        quote.querySelector('.story-quote-name').textContent = author;
        quote.querySelector('.story-quote-caption').textContent = m.story.expired
            ? 'Story muddati tugagan'
            : (m.story.caption || (m.story.type === 'video' ? '🎬 Video' : '🖼 Rasm'));

        if (!m.story.expired && m.story.url) {
            const media = m.story.type === 'video'
                ? Object.assign(document.createElement('video'), { muted: true, preload: 'metadata', playsInline: true })
                : Object.assign(document.createElement('img'), { alt: '', loading: 'lazy', decoding: 'async' });
            media.src = m.story.url;
            thumb.append(media);
            quote.addEventListener('click', () => {
                if (state.storyGroups.some((g) => g.user.id === authorId && g.stories.some((s) => s.id === m.story.id))) {
                    openStoryViewer(authorId);
                }
            });
        } else {
            quote.classList.add('expired');
            thumb.textContent = '📖';
        }
        return quote;
    }

    function updateMeta(node, m) {
        const meta = node.querySelector('.meta');
        if (!meta) return;
        const out = m.sender_id === me.id;
        let status = '';
        if (out && m.pending) status = ICONS.clock;
        else if (out && m.failed) status = '';
        else if (out && m.receiver_id !== me.id) status = m.read_at ? ICONS.checks : ICONS.check;
        else if (out) status = ICONS.checks;
        const html = `${m.edited_at ? '<span class="edited">tahrirlangan</span>' : ''}${timeOf(m.created_at || new Date().toISOString())}${status}`;
        if (meta.innerHTML !== html) meta.innerHTML = html;

        if (m.pending && m.progress !== undefined) {
            const bar = node.querySelector('.upload-progress div');
            if (bar) bar.style.width = `${Math.round(m.progress * 100)}%`;
        }
    }

    // ---------------------------------------------------------------- scrolling
    const isNearBottom = () => el.scroll.scrollHeight - el.scroll.scrollTop - el.scroll.clientHeight < 120;

    function scrollToBottom(smooth = true) {
        el.scroll.scrollTo({ top: el.scroll.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        state.newWhileAway = 0;
        updateScrollDown();
    }

    let wasNearBottom = true;
    function keepBottomIfNear() {
        if (wasNearBottom) el.scroll.scrollTop = el.scroll.scrollHeight;
    }

    function updateScrollDown() {
        el.scrollDown.hidden = isNearBottom();
        el.scrollDownBadge.hidden = state.newWhileAway === 0;
        el.scrollDownBadge.textContent = state.newWhileAway;
    }

    el.scroll.addEventListener('scroll', () => {
        wasNearBottom = isNearBottom();
        if (wasNearBottom) { state.newWhileAway = 0; markRead(); }
        updateScrollDown();
        if (el.scroll.scrollTop < 300) loadOlder();
    }, { passive: true });

    el.scrollDown.addEventListener('click', () => scrollToBottom());

    async function loadOlder() {
        const peerId = state.peer?.id;
        if (!peerId || state.loadingOlder || !state.hasMore.get(peerId)) return;
        const oldest = sortedMessages(peerId).find((m) => typeof m.id === 'number');
        if (!oldest) return;
        state.loadingOlder = true;
        try {
            const res = await api('GET', `/api/chats/${peerId}/messages?before=${oldest.id}`);
            if (state.peer?.id !== peerId) return;
            const store = messagesOf(peerId);
            for (const m of res.data) if (!store.has(m.id)) store.set(m.id, m);
            state.hasMore.set(peerId, res.has_more);
            const fromBottom = el.scroll.scrollHeight - el.scroll.scrollTop;
            renderMessages();
            el.scroll.scrollTop = el.scroll.scrollHeight - fromBottom;
        } catch (e) {
            showToast(e.message);
        } finally {
            state.loadingOlder = false;
        }
    }

    async function jumpTo(messageId) {
        const peerId = state.peer.id;
        let node = el.messages.querySelector(`.msg[data-id="${messageId}"]`);
        let guard = 0;
        while (!node && state.hasMore.get(peerId) && guard++ < 20) {
            await loadOlder();
            node = el.messages.querySelector(`.msg[data-id="${messageId}"]`);
        }
        if (!node) return showToast('Xabar topilmadi');
        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        node.classList.remove('highlight');
        void node.offsetWidth;
        node.classList.add('highlight');
    }

    // ---------------------------------------------------------------- read receipts
    let markReadInFlight = false;
    async function markRead() {
        const peer = state.peer;
        if (!peer || document.hidden || markReadInFlight || !isNearBottom()) return;
        const hasUnread = [...messagesOf(peer.id).values()].some((m) => m.sender_id === peer.id && m.receiver_id === me.id && !m.read_at && !m.deleted);
        if (!hasUnread) return;
        markReadInFlight = true;
        try {
            await api('POST', `/api/chats/${peer.id}/read`);
            const now = new Date().toISOString();
            for (const m of messagesOf(peer.id).values()) {
                if (m.sender_id === peer.id && !m.read_at) m.read_at = now;
            }
            const chat = state.chats.get(peer.id);
            if (chat) { chat.unread = 0; renderSidebar(); updateTitle(); }
        } catch { /* retried on next sync */ } finally {
            markReadInFlight = false;
        }
    }

    // ================================================================ COMPOSER
    const drafts = new Map();

    function autoGrow() {
        el.input.style.height = 'auto';
        el.input.style.height = `${Math.min(el.input.scrollHeight, 200)}px`;
    }

    el.input.addEventListener('input', () => {
        autoGrow();
        if (state.peer) drafts.set(state.peer.id, el.input.value);
        sendTyping();
    });

    el.input.addEventListener('keydown', (e) => {
        // Enter sends everywhere (phones included); Shift+Enter inserts a new line.
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            submit();
        } else if (e.key === 'Escape' && (state.replyTo || state.editing)) {
            cancelContext();
        } else if (e.key === 'ArrowUp' && !el.input.value && state.peer) {
            const lastOwn = sortedMessages(state.peer.id).reverse().find((m) => m.sender_id === me.id && typeof m.id === 'number');
            if (lastOwn) { e.preventDefault(); startEdit(lastOwn); }
        }
    });

    el.sendBtn.addEventListener('click', () => { submit(); el.input.focus(); });

    function sendTyping() {
        if (!state.peer || state.peer.id === me.id || !el.input.value.trim()) return;
        const now = Date.now();
        if (now - state.lastTypingSent < 3000) return;
        state.lastTypingSent = now;
        api('POST', `/api/chats/${state.peer.id}/typing`).catch(() => {});
    }

    function submit() {
        const text = el.input.value.trim();
        if (!state.peer || !text) return;

        if (state.editing) {
            const target = state.editing;
            cancelContext();
            el.input.value = '';
            autoGrow();
            if (text !== target.body) editMessage(target, text);
            return;
        }

        el.input.value = '';
        drafts.delete(state.peer.id);
        autoGrow();
        sendText(state.peer.id, text, state.replyTo);
        cancelContext();
    }

    function addTemp(peerId, fields) {
        const id = `tmp-${++state.tempSeq}`;
        const temp = {
            id, seq: state.tempSeq, sender_id: me.id, receiver_id: peerId,
            created_at: new Date().toISOString(), pending: true, read_at: null, edited_at: null, ...fields,
        };
        messagesOf(peerId).set(id, temp);
        renderIfOpen(peerId);
        if (state.peer?.id === peerId) scrollToBottom(false);
        return temp;
    }

    function replyPayload(reply) {
        return reply ? { id: reply.id, sender_id: reply.sender_id, type: reply.type, body: reply.body, attachment_name: reply.attachment?.name } : null;
    }

    async function sendText(peerId, text, reply) {
        const temp = addTemp(peerId, { type: 'text', body: text, reply_to: replyPayload(reply) });
        try {
            const { data } = await api('POST', `/api/chats/${peerId}/messages`, { body: text, reply_to_id: reply?.id ?? null });
            settleTemp(peerId, temp, data);
        } catch (e) {
            failTemp(peerId, temp, e.message);
        }
    }

    function settleTemp(peerId, temp, real) {
        const store = messagesOf(peerId);
        store.delete(temp.id);
        if (temp.localUrl) setTimeout(() => URL.revokeObjectURL(temp.localUrl), 10000);
        const existing = store.get(real.id);
        if (!existing || !existing.read_at) store.set(real.id, real);
        renderIfOpen(peerId);
        scheduleReloadChats();
    }

    function failTemp(peerId, temp, message) {
        temp.pending = false;
        temp.failed = message || 'Yuborilmadi';
        renderIfOpen(peerId);
    }

    function renderIfOpen(peerId) {
        if (state.peer?.id === peerId) renderMessages();
    }

    // ---------------------------------------------------------------- uploads
    el.attachBtn.addEventListener('click', () => el.fileInput.click());
    el.fileInput.addEventListener('change', () => {
        sendFiles([...el.fileInput.files]);
        el.fileInput.value = '';
    });

    el.input.addEventListener('paste', (e) => {
        const files = [...(e.clipboardData?.files || [])];
        if (files.length) { e.preventDefault(); sendFiles(files); }
    });

    let dragDepth = 0;
    el.inner.addEventListener('dragenter', (e) => {
        if (![...e.dataTransfer.types].includes('Files')) return;
        e.preventDefault(); dragDepth++; el.dropOverlay.hidden = false;
    });
    el.inner.addEventListener('dragover', (e) => { if ([...e.dataTransfer.types].includes('Files')) e.preventDefault(); });
    el.inner.addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; el.dropOverlay.hidden = true; } });
    el.inner.addEventListener('drop', (e) => {
        e.preventDefault(); dragDepth = 0; el.dropOverlay.hidden = true;
        sendFiles([...e.dataTransfer.files]);
    });

    function guessType(file) {
        if (/^image\/(jpeg|png|gif|webp|avif|bmp)$/.test(file.type)) return 'image';
        if (/^video\/(mp4|webm|ogg|quicktime)$/.test(file.type)) return 'video';
        if (/^audio\//.test(file.type)) return 'audio';
        return 'file';
    }

    function sendFiles(files) {
        if (!state.peer || files.length === 0) return;
        const peerId = state.peer.id;
        // The typed text becomes the caption of the first file, like Telegram.
        let caption = el.input.value.trim();
        if (state.editing) caption = '';
        const reply = state.replyTo;
        if (caption) { el.input.value = ''; drafts.delete(peerId); autoGrow(); }
        cancelContext();

        files.forEach((file, index) => {
            if (file.size > boot.maxUploadBytes) {
                showToast(`${file.name}: fayl juda katta (max ${formatSize(boot.maxUploadBytes)})`);
                return;
            }
            const type = guessType(file);
            const body = index === 0 ? caption : '';
            uploadFile(peerId, file, type, body, index === 0 ? reply : null);
        });
    }

    /**
     * Shrink big photos before upload (phone cameras produce 4–12 MB files), so they send and load fast.
     * GIFs and small images go through untouched; anything that fails falls back to the original file.
     */
    async function compressImage(file) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < COMPRESS_MIN_BYTES) return file;
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, MAX_IMAGE_SIDE / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff'; // transparent PNGs would otherwise turn black as JPEG
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            bitmap.close();
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
            if (!blob || blob.size >= file.size) return file;
            return new File([blob], `${file.name.replace(/\.[^.]+$/, '')}.jpg`, { type: 'image/jpeg' });
        } catch {
            return file;
        }
    }

    async function uploadFile(peerId, file, type, body, reply) {
        const localUrl = type === 'file' ? null : URL.createObjectURL(file);
        const temp = addTemp(peerId, {
            type, body: body || null, localUrl, localName: file.name, localSize: file.size, progress: 0, reply_to: replyPayload(reply),
        });

        const form = new FormData();
        form.append('file', type === 'image' ? await compressImage(file) : file);
        if (body) form.append('body', body);
        if (reply) form.append('reply_to_id', reply.id);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', `/api/chats/${peerId}/messages`);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', (e) => {
            if (!e.lengthComputable) return;
            temp.progress = e.loaded / e.total;
            const node = nodeCache.get(`m-${temp.id}`)?.node;
            if (node) updateMeta(node, temp);
        });
        xhr.addEventListener('load', () => {
            let data = {};
            try { data = JSON.parse(xhr.responseText); } catch { /* non-JSON error page */ }
            if (xhr.status >= 200 && xhr.status < 300) {
                settleTemp(peerId, temp, data.data);
            } else if (xhr.status === 413) {
                failTemp(peerId, temp, 'Fayl serverga sig\'madi (php.ini: upload_max_filesize / post_max_size)');
            } else {
                const err = data.errors ? Object.values(data.errors)[0][0] : data.message;
                failTemp(peerId, temp, err || `Xatolik ${xhr.status}`);
            }
        });
        xhr.addEventListener('error', () => failTemp(peerId, temp, 'Tarmoq xatosi'));
        xhr.send(form);
    }

    // ---------------------------------------------------------------- stickers & GIFs
    const picker = { el: $('picker'), btn: $('pickerBtn'), grid: $('pickerGrid'), search: $('gifSearch'), tab: 'stickers', gifTimer: 0, gifRequest: 0 };

    function togglePicker(open = picker.el.hidden) {
        picker.el.hidden = !open;
        picker.btn.setAttribute('aria-expanded', String(open));
        if (open) showPickerTab(picker.tab);
    }

    function showPickerTab(tab) {
        picker.tab = tab;
        for (const button of picker.el.querySelectorAll('.picker-tab')) {
            const active = button.dataset.tab === tab;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', String(active));
        }
        picker.search.hidden = tab !== 'gifs';
        picker.grid.classList.toggle('gifs', tab === 'gifs');
        if (tab === 'stickers') {
            renderStickerGrid();
        } else {
            loadGifs(picker.search.value);
            if (matchMedia('(min-width: 769px)').matches) picker.search.focus();
        }
    }

    function renderStickerGrid() {
        picker.grid.replaceChildren(...boot.stickers.map((code) => {
            const item = h('<button type="button" class="picker-item"><img alt="" loading="lazy" decoding="async"></button>');
            const img = item.firstElementChild;
            img.src = stickerUrl(code);
            img.alt = stickerEmoji(code);
            item.addEventListener('click', () => sendSpecial({ type: 'sticker', sticker: code }, { sticker: code }));
            return item;
        }));
    }

    async function loadGifs(query) {
        if (!boot.gifsEnabled) {
            picker.grid.replaceChildren(h('<div class="picker-note">GIF qidiruv hali sozlanmagan (GIPHY_API_KEY)</div>'));
            return;
        }
        const requestId = ++picker.gifRequest;
        picker.grid.replaceChildren(h('<div class="picker-note"><div class="spinner"></div></div>'));
        try {
            const { data } = await api('GET', `/api/gifs?${new URLSearchParams({ q: query.trim() })}`);
            if (requestId !== picker.gifRequest) return;
            if (data.length === 0) {
                picker.grid.replaceChildren(h('<div class="picker-note">Hech narsa topilmadi</div>'));
                return;
            }
            picker.grid.replaceChildren(...data.map((gif) => {
                const item = h('<button type="button" class="picker-item"><img alt="GIF" loading="lazy" decoding="async"></button>');
                item.firstElementChild.src = gif.preview;
                const payload = { url: gif.url, width: gif.width, height: gif.height };
                item.addEventListener('click', () => sendSpecial({ type: 'gif', gif: payload }, { gif: payload }));
                return item;
            }));
        } catch (e) {
            if (requestId === picker.gifRequest) picker.grid.replaceChildren(h(`<div class="picker-note">${escapeHtml(e.message)}</div>`));
        }
    }

    picker.btn.addEventListener('click', (e) => { e.stopPropagation(); togglePicker(); });
    for (const button of picker.el.querySelectorAll('.picker-tab')) {
        button.addEventListener('click', () => showPickerTab(button.dataset.tab));
    }
    picker.search.addEventListener('input', () => {
        clearTimeout(picker.gifTimer);
        picker.gifTimer = setTimeout(() => loadGifs(picker.search.value), 350);
    });
    document.addEventListener('click', (e) => {
        if (!picker.el.hidden && !picker.el.contains(e.target) && !picker.btn.contains(e.target)) togglePicker(false);
    });
    picker.el.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); togglePicker(false); el.input.focus(); } });

    async function sendSpecial(fields, payload) {
        if (!state.peer) return;
        const peerId = state.peer.id;
        const reply = state.replyTo;
        cancelContext();
        togglePicker(false);
        const temp = addTemp(peerId, { ...fields, body: null, reply_to: replyPayload(reply) });
        try {
            const { data } = await api('POST', `/api/chats/${peerId}/messages`, { ...payload, reply_to_id: reply?.id ?? null });
            settleTemp(peerId, temp, data);
        } catch (e) {
            failTemp(peerId, temp, e.message);
        }
    }

    // ---------------------------------------------------------------- reply / edit
    function startReply(message) {
        if (!message || typeof message.id !== 'number') return;
        state.editing = null;
        state.replyTo = message;
        renderComposerContext();
        el.input.focus();
    }

    function startEdit(message) {
        state.replyTo = null;
        state.editing = message;
        el.input.value = message.body || '';
        autoGrow();
        renderComposerContext();
        el.input.focus();
        el.input.setSelectionRange(el.input.value.length, el.input.value.length);
    }

    function cancelContext() {
        const wasEditing = !!state.editing;
        state.replyTo = null;
        state.editing = null;
        renderComposerContext();
        if (wasEditing) { el.input.value = (state.peer && drafts.get(state.peer.id)) || ''; autoGrow(); }
    }

    function renderComposerContext() {
        const target = state.editing || state.replyTo;
        el.ctx.hidden = !target;
        if (!target) return;
        el.ctx.querySelector('svg').outerHTML = state.editing ? ICONS.edit : ICONS.reply;
        el.ctxTitle.textContent = state.editing
            ? 'Tahrirlash'
            : `Javob: ${target.sender_id === me.id ? 'Siz' : (state.users.get(target.sender_id)?.name || '')}`;
        el.ctxBody.textContent = previewText(target);
    }

    el.ctxClose.addEventListener('click', cancelContext);

    async function editMessage(message, text) {
        const peerId = partnerOf(message);
        const original = { ...message };
        Object.assign(message, { body: text, edited_at: new Date().toISOString() });
        renderIfOpen(peerId);
        try {
            const { data } = await api('PATCH', `/api/messages/${message.id}`, { body: text });
            messagesOf(peerId).set(data.id, data);
            renderIfOpen(peerId);
            scheduleReloadChats();
        } catch (e) {
            Object.assign(message, original);
            renderIfOpen(peerId);
            showToast(e.message);
        }
    }

    async function deleteMessage(message) {
        const peerId = partnerOf(message);
        if (typeof message.id === 'string') {
            messagesOf(peerId).delete(message.id);
            renderIfOpen(peerId);
            return;
        }
        if (!confirm('Xabar ikkala tomondan ham o\'chirilsinmi?')) return;
        try {
            await api('DELETE', `/api/messages/${message.id}`);
            messagesOf(peerId).set(message.id, { ...message, deleted: true });
            renderIfOpen(peerId);
            scheduleReloadChats();
        } catch (e) { showToast(e.message); }
    }

    // ---------------------------------------------------------------- context menu
    function openContextMenu(x, y, messageId) {
        const message = messagesOf(state.peer.id).get(messageId);
        if (!message) return;
        const isReal = typeof message.id === 'number';
        const own = message.sender_id === me.id;
        const items = [];
        if (isReal) items.push(['reply', 'Javob berish', () => startReply(message)]);
        if (isReal && own && message.type !== 'sticker') items.push(['edit', 'Tahrirlash', () => startEdit(message)]);
        if (message.body) items.push(['copy', 'Nusxa olish', () => navigator.clipboard?.writeText(message.body).then(() => showToast('Nusxa olindi'))]);
        if (message.attachment) items.push(['download', 'Yuklab olish', () => { window.location.href = message.attachment.download_url; }]);
        items.push(['trash', isReal ? 'O\'chirish' : 'Bekor qilish', () => deleteMessage(message), true]);

        el.contextMenu.replaceChildren(...items.map(([icon, label, action, danger]) => {
            const btn = h(`<button type="button" class="dropdown-item${danger ? ' danger' : ''}">${ICONS[icon]}<span></span></button>`);
            btn.querySelector('span').textContent = label;
            btn.addEventListener('click', () => { closeContextMenu(); action(); });
            return btn;
        }));
        el.contextMenu.hidden = false;
        const { offsetWidth: w, offsetHeight: hgt } = el.contextMenu;
        el.contextMenu.style.left = `${Math.min(x, innerWidth - w - 8)}px`;
        el.contextMenu.style.top = `${Math.min(y, innerHeight - hgt - 8)}px`;
    }

    function closeContextMenu() { el.contextMenu.hidden = true; }
    document.addEventListener('click', (e) => { if (!el.contextMenu.contains(e.target)) closeContextMenu(); });
    document.addEventListener('scroll', closeContextMenu, true);
    window.addEventListener('resize', closeContextMenu);

    function attachLongPress(node, callback) {
        let timer;
        node.addEventListener('touchstart', (e) => {
            const t = e.touches[0];
            timer = setTimeout(() => { navigator.vibrate?.(10); callback(t.clientX, t.clientY); }, 450);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach((ev) => node.addEventListener(ev, () => clearTimeout(timer), { passive: true }));
    }

    // ---------------------------------------------------------------- lightbox
    function openLightbox(message) {
        el.lightboxBody.replaceChildren();
        const img = document.createElement('img');
        img.src = message.attachment.url;
        img.alt = message.attachment.name || '';
        el.lightboxBody.append(img);
        el.lightboxDownload.href = message.attachment.download_url;
        el.lightbox.hidden = false;
    }

    function closeLightbox() { el.lightbox.hidden = true; el.lightboxBody.replaceChildren(); }
    el.lightboxClose.addEventListener('click', closeLightbox);
    el.lightbox.addEventListener('click', (e) => { if (e.target === el.lightbox || e.target === el.lightboxBody) closeLightbox(); });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (!el.lightbox.hidden) closeLightbox();
        else if (document.querySelector('.modal-backdrop:not([hidden])')) document.querySelectorAll('.modal-backdrop').forEach((m) => { m.hidden = true; });
        else if (!el.contextMenu.hidden) closeContextMenu();
    });

    // ================================================================ PROFILE
    const profile = {
        modal: $('profileModal'), avatar: $('profileAvatar'), avatarInput: $('avatarInput'),
        uploadBtn: $('avatarUploadBtn'), removeBtn: $('avatarRemoveBtn'), progress: $('avatarProgress'),
        form: $('profileForm'), name: $('profileName'), username: $('profileUsername'),
    };

    function openModal(modal) { modal.hidden = false; }
    function closeModal(modal) { modal.hidden = true; }
    document.querySelectorAll('.modal-backdrop').forEach((modal) => {
        modal.addEventListener('click', (e) => { if (e.target === modal || e.target.closest('[data-close]')) closeModal(modal); });
    });

    function applyMe(updated) {
        Object.assign(me, updated);
        state.users.set(me.id, { ...state.users.get(me.id), ...updated });
        for (const group of state.storyGroups) if (group.user.id === me.id) Object.assign(group.user, updated);
        $('menuName').textContent = me.name;
        paintAvatar(el.menu.querySelector('.avatar'), me, { ring: false });
        paintAvatar(profile.avatar, me, { ring: false });
        profile.removeBtn.hidden = !me.avatar_url;
        renderStories();
        renderSidebar();
    }

    function openProfile() {
        closeMenu();
        profile.name.value = me.name;
        profile.username.textContent = `@${me.username}`;
        paintAvatar(profile.avatar, me, { ring: false });
        profile.removeBtn.hidden = !me.avatar_url;
        openModal(profile.modal);
    }

    $('profileBtn').addEventListener('click', openProfile);
    profile.avatar.addEventListener('click', () => profile.avatarInput.click());
    profile.uploadBtn.addEventListener('click', () => profile.avatarInput.click());

    profile.avatarInput.addEventListener('change', async () => {
        const file = profile.avatarInput.files[0];
        profile.avatarInput.value = '';
        if (!file) return;
        const form = new FormData();
        form.append('avatar', file);
        profile.progress.hidden = false;
        try {
            const res = await fetch('/api/profile/avatar', {
                method: 'POST', body: form, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.errors ? Object.values(data.errors)[0][0] : (data.message || `HTTP ${res.status}`));
            applyMe(data.data);
            showToast('Profil rasmi yangilandi');
        } catch (e) {
            showToast(e.message);
        } finally {
            profile.progress.hidden = true;
        }
    });

    profile.removeBtn.addEventListener('click', async () => {
        if (!confirm('Profil rasmini o\'chirasizmi?')) return;
        try {
            const { data } = await api('DELETE', '/api/profile/avatar');
            applyMe(data);
        } catch (e) { showToast(e.message); }
    });

    profile.form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = profile.name.value.trim();
        if (!name) return;
        try {
            const { data } = await api('PATCH', '/api/profile', { name });
            applyMe(data);
            closeModal(profile.modal);
            showToast('Saqlandi');
        } catch (err) { showToast(err.message); }
    });

    // ================================================================ STORIES
    const STORY_IMAGE_MS = 5000;
    const sv = {
        strip: $('stories'), viewer: $('storyViewer'), bars: $('storyBars'),
        avatar: $('storyAvatar'), name: $('storyName'), time: $('storyTime'), media: $('storyMedia'),
        caption: $('storyCaptionView'), prev: $('storyPrev'), next: $('storyNext'), close: $('storyCloseBtn'),
        del: $('storyDeleteBtn'), mute: $('storyMuteBtn'), viewsBtn: $('storyViewsBtn'), viewsCount: $('storyViewsCount'),
        viewersPanel: $('storyViewersPanel'), viewersList: $('storyViewersList'), viewersClose: $('storyViewersClose'),
        replyForm: $('storyReplyForm'), replyInput: $('storyReplyInput'),
        composer: $('storyComposer'), preview: $('storyPreview'), captionInput: $('storyCaption'),
        publish: $('storyPublishBtn'), progress: $('storyProgress'), input: $('storyInput'),
    };
    const viewer = { groupIndex: 0, storyIndex: 0, started: 0, elapsed: 0, paused: false, raf: 0, muted: true, groups: [] };
    let storiesDirty = false; // a websocket ping arrived while the viewer was open
    let pendingStoryFile = null;

    async function loadStories() {
        const { data } = await api('GET', '/api/stories');
        // Keep locally-known "seen" flags so a reload racing a view request doesn't resurrect rings.
        const seenLocally = new Set(state.storyGroups.flatMap((g) => g.stories.filter((s) => s.seen).map((s) => s.id)));
        for (const group of data) for (const story of group.stories) if (seenLocally.has(story.id)) story.seen = true;
        state.storyGroups = data;
        renderStories();
        renderSidebar();
        if (state.peer) renderHeader();
    }

    function relativeTime(iso) {
        const min = Math.floor((Date.now() - new Date(iso)) / 60000);
        if (min < 1) return 'hozirgina';
        if (min < 60) return `${min} daqiqa oldin`;
        return `${Math.floor(min / 60)} soat oldin`;
    }

    function renderStories() {
        const mine = state.storyGroups.find((g) => g.is_me);
        const others = state.storyGroups.filter((g) => !g.is_me);
        sv.strip.hidden = false;
        sv.strip.replaceChildren();

        const myItem = h('<button type="button" class="story-item"><div class="avatar"></div><span class="story-item-name">Mening story\'m</span></button>');
        const myAvatar = myItem.querySelector('.avatar');
        paintAvatar(myAvatar, me, { ring: false });
        if (mine) myAvatar.classList.add('story-seen');
        myItem.append(h('<span class="story-add" title="Story qo\'shish">+</span>'));
        myItem.querySelector('.story-add').addEventListener('click', (e) => { e.stopPropagation(); sv.input.click(); });
        myItem.addEventListener('click', () => (mine ? openStoryViewer(me.id) : sv.input.click()));
        sv.strip.append(myItem);

        for (const group of others) {
            const item = h('<button type="button" class="story-item"><div class="avatar"></div><span class="story-item-name"></span></button>');
            const avatar = item.querySelector('.avatar');
            paintAvatar(avatar, group.user);
            avatar.classList.remove('online');
            item.querySelector('.story-item-name').textContent = group.user.name;
            item.addEventListener('click', () => openStoryViewer(group.user.id));
            sv.strip.append(item);
        }
    }

    // ---------- composer
    $('addStoryMenuBtn').addEventListener('click', () => { closeMenu(); sv.input.click(); });

    function clearStoryPreview() {
        const media = sv.preview.firstElementChild;
        if (media?.src) URL.revokeObjectURL(media.src);
        sv.preview.replaceChildren();
    }

    sv.input.addEventListener('change', () => {
        const file = sv.input.files[0];
        sv.input.value = '';
        if (!file) return;
        if (file.size > boot.maxStoryBytes) {
            showToast(`Fayl juda katta (max ${formatSize(boot.maxStoryBytes)})`);
            return;
        }
        pendingStoryFile = file;
        clearStoryPreview();
        const url = URL.createObjectURL(file);
        const node = file.type.startsWith('video/')
            ? Object.assign(document.createElement('video'), { src: url, autoplay: true, muted: true, loop: true, playsInline: true })
            : Object.assign(document.createElement('img'), { src: url, alt: '' });
        sv.preview.replaceChildren(node);
        sv.captionInput.value = '';
        sv.progress.hidden = true;
        sv.publish.disabled = false;
        openModal(sv.composer);
        sv.captionInput.focus();
    });

    sv.captionInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); sv.publish.click(); } });

    sv.publish.addEventListener('click', async () => {
        if (!pendingStoryFile || sv.publish.disabled) return;
        sv.publish.disabled = true;
        const form = new FormData();
        form.append('file', await compressImage(pendingStoryFile));
        if (sv.captionInput.value.trim()) form.append('caption', sv.captionInput.value.trim());

        sv.progress.hidden = false;
        const bar = sv.progress.firstElementChild;
        bar.style.width = '0%';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/stories');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', (e) => { if (e.lengthComputable) bar.style.width = `${Math.round((e.loaded / e.total) * 100)}%`; });
        xhr.addEventListener('load', async () => {
            let data = {};
            try { data = JSON.parse(xhr.responseText); } catch { /* non-JSON error page */ }
            if (xhr.status >= 200 && xhr.status < 300) {
                pendingStoryFile = null;
                closeModal(sv.composer);
                clearStoryPreview();
                showToast('Story joylandi');
                await loadStories().catch(() => {});
            } else {
                sv.publish.disabled = false;
                showToast(xhr.status === 413
                    ? 'Fayl serverga sig\'madi (php.ini limitlari)'
                    : (data.errors ? Object.values(data.errors)[0][0] : (data.message || `Xatolik ${xhr.status}`)));
            }
        });
        xhr.addEventListener('error', () => { sv.publish.disabled = false; showToast('Tarmoq xatosi'); });
        xhr.send(form);
    });

    // ---------- viewer
    function openStoryViewer(userId) {
        // Snapshot groups so the order doesn't shift while watching.
        viewer.groups = state.storyGroups.map((g) => ({ ...g, stories: [...g.stories] }));
        const groupIndex = viewer.groups.findIndex((g) => g.user.id === userId);
        if (groupIndex === -1) return;
        const firstUnseen = viewer.groups[groupIndex].stories.findIndex((s) => !s.seen);
        sv.viewer.hidden = false;
        showStory(groupIndex, firstUnseen === -1 ? 0 : firstUnseen);
    }

    function closeStoryViewer() {
        cancelAnimationFrame(viewer.raf);
        sv.viewer.hidden = true;
        sv.media.replaceChildren();
        sv.viewersPanel.hidden = true;
        renderStories();
        renderSidebar();
        if (storiesDirty) { storiesDirty = false; loadStories().catch(() => {}); }
        if (state.peer) renderHeader();
    }

    function currentStory() {
        return viewer.groups[viewer.groupIndex]?.stories[viewer.storyIndex];
    }

    function showStory(groupIndex, storyIndex) {
        cancelAnimationFrame(viewer.raf);
        const group = viewer.groups[groupIndex];
        if (!group) return closeStoryViewer();
        viewer.groupIndex = groupIndex;
        viewer.storyIndex = storyIndex;
        const story = group.stories[storyIndex];
        const isMine = group.user.id === me.id;

        sv.bars.replaceChildren(...group.stories.map((s, i) => {
            const bar = h('<div class="story-bar"><div></div></div>');
            bar.firstElementChild.style.width = i < storyIndex ? '100%' : '0%';
            return bar;
        }));
        paintAvatar(sv.avatar, group.user, { ring: false });
        sv.avatar.classList.remove('online');
        sv.name.textContent = isMine ? 'Mening story\'m' : group.user.name;
        sv.time.textContent = relativeTime(story.created_at);
        sv.del.hidden = !isMine;
        sv.viewsBtn.hidden = !isMine;
        sv.viewsCount.textContent = story.views_count ?? 0;
        sv.replyForm.hidden = isMine;
        sv.viewersPanel.hidden = true;
        sv.caption.hidden = !story.caption;
        sv.caption.textContent = story.caption || '';

        viewer.elapsed = 0;
        viewer.started = 0;
        viewer.paused = false;
        sv.media.replaceChildren(h('<div class="spinner"></div>'));

        if (story.type === 'video') {
            const video = document.createElement('video');
            Object.assign(video, { src: story.url, playsInline: true, muted: viewer.muted, autoplay: true, preload: 'auto' });
            video.addEventListener('ended', () => nextStory());
            video.addEventListener('error', () => nextStory());
            sv.media.replaceChildren(video);
            video.play().catch(() => { video.muted = true; viewer.muted = true; updateMuteIcon(); video.play().catch(() => {}); });
            sv.mute.hidden = false;
            updateMuteIcon();
            tick();
        } else {
            const img = new Image();
            img.alt = '';
            img.onload = () => {
                if (currentStory() !== story) return;
                sv.media.replaceChildren(img);
                viewer.started = performance.now();
                tick();
            };
            img.onerror = () => nextStory();
            img.src = story.url;
            sv.mute.hidden = true;
        }

        if (!story.seen) {
            story.seen = true;
            const original = state.storyGroups.find((g) => g.user.id === group.user.id)?.stories.find((s) => s.id === story.id);
            if (original) original.seen = true;
            if (!isMine) api('POST', `/api/stories/${story.id}/view`).catch(() => {});
        }
    }

    function tick() {
        const story = currentStory();
        if (!story || sv.viewer.hidden) return;
        const bar = sv.bars.children[viewer.storyIndex]?.firstElementChild;
        let progress = 0;
        if (story.type === 'video') {
            const video = sv.media.querySelector('video');
            if (video && video.duration) progress = video.currentTime / video.duration;
        } else if (!viewer.paused) {
            progress = (viewer.elapsed + performance.now() - viewer.started) / STORY_IMAGE_MS;
            if (progress >= 1) return nextStory();
        } else {
            progress = viewer.elapsed / STORY_IMAGE_MS;
        }
        if (bar) bar.style.width = `${Math.min(progress, 1) * 100}%`;
        viewer.raf = requestAnimationFrame(tick);
    }

    function pauseStory() {
        if (viewer.paused) return;
        viewer.paused = true;
        if (currentStory()?.type === 'video') sv.media.querySelector('video')?.pause();
        else if (viewer.started) viewer.elapsed += performance.now() - viewer.started;
    }

    function resumeStory() {
        if (!viewer.paused || !sv.viewersPanel.hidden || document.activeElement === sv.replyInput) return;
        viewer.paused = false;
        viewer.started = performance.now();
        if (currentStory()?.type === 'video') sv.media.querySelector('video')?.play().catch(() => {});
    }

    function nextStory() {
        const group = viewer.groups[viewer.groupIndex];
        if (!group) return closeStoryViewer();
        if (viewer.storyIndex < group.stories.length - 1) showStory(viewer.groupIndex, viewer.storyIndex + 1);
        else if (viewer.groupIndex < viewer.groups.length - 1) showStory(viewer.groupIndex + 1, 0);
        else closeStoryViewer();
    }

    function prevStory() {
        if (viewer.storyIndex > 0) showStory(viewer.groupIndex, viewer.storyIndex - 1);
        else if (viewer.groupIndex > 0) showStory(viewer.groupIndex - 1, viewer.groups[viewer.groupIndex - 1].stories.length - 1);
        else showStory(viewer.groupIndex, 0);
    }

    // Tap = navigate, press-and-hold = pause (like Telegram).
    for (const [zone, action] of [[sv.prev, prevStory], [sv.next, nextStory]]) {
        let downAt = 0;
        zone.addEventListener('pointerdown', () => { downAt = Date.now(); pauseStory(); });
        zone.addEventListener('pointerup', () => {
            const held = Date.now() - downAt > 250;
            resumeStory();
            if (!held) action();
        });
        zone.addEventListener('pointerleave', resumeStory);
        zone.addEventListener('contextmenu', (e) => e.preventDefault());
    }

    sv.close.addEventListener('click', closeStoryViewer);
    sv.viewer.addEventListener('click', (e) => { if (e.target === sv.viewer) closeStoryViewer(); });

    function updateMuteIcon() {
        sv.mute.innerHTML = viewer.muted
            ? '<svg viewBox="0 0 24 24"><path d="M4 9h4l5-4v14l-5-4H4zM17 9l4 6M21 9l-4 6"/></svg>'
            : '<svg viewBox="0 0 24 24"><path d="M4 9h4l5-4v14l-5-4H4zM17 9a4 4 0 0 1 0 6M19.5 6.5a8 8 0 0 1 0 11"/></svg>';
    }

    sv.mute.addEventListener('click', () => {
        viewer.muted = !viewer.muted;
        const video = sv.media.querySelector('video');
        if (video) video.muted = viewer.muted;
        updateMuteIcon();
    });

    sv.del.addEventListener('click', async () => {
        const story = currentStory();
        if (!story) return;
        pauseStory();
        if (!confirm('Story o\'chirilsinmi?')) { resumeStory(); return; }
        try {
            await api('DELETE', `/api/stories/${story.id}`);
            const group = viewer.groups[viewer.groupIndex];
            group.stories.splice(viewer.storyIndex, 1);
            for (const g of state.storyGroups) g.stories = g.stories.filter((s) => s.id !== story.id);
            state.storyGroups = state.storyGroups.filter((g) => g.stories.length > 0);
            if (group.stories.length === 0) closeStoryViewer();
            else showStory(viewer.groupIndex, Math.min(viewer.storyIndex, group.stories.length - 1));
        } catch (e) { showToast(e.message); resumeStory(); }
    });

    let refreshingViewers = false;

    // Fetches who viewed my current story and updates the counter (and the list, if it is open).
    async function refreshStoryViewers({ silent = false } = {}) {
        const story = currentStory();
        if (!story || refreshingViewers) return;
        refreshingViewers = true;
        try {
            const { data } = await api('GET', `/api/stories/${story.id}/viewers`);
            if (currentStory() !== story) return;
            story.views_count = data.length;
            sv.viewsCount.textContent = data.length;
            if (sv.viewersPanel.hidden) return;
            if (data.length === 0) {
                sv.viewersList.replaceChildren(h('<div class="list-empty">Hali hech kim ko\'rmadi</div>'));
                return;
            }
            sv.viewersList.replaceChildren(...data.map((u) => {
                const row = h('<div class="viewer-row"><div class="avatar"></div><div><div class="viewer-name"></div><div class="viewer-time"></div></div></div>');
                paintAvatar(row.querySelector('.avatar'), u, { ring: false });
                row.querySelector('.viewer-name').textContent = u.name;
                row.querySelector('.viewer-time').textContent = relativeTime(u.viewed_at);
                return row;
            }));
        } catch (e) {
            if (!silent) { sv.viewersList.replaceChildren(); showToast(e.message); }
        } finally {
            refreshingViewers = false;
        }
    }

    sv.viewsBtn.addEventListener('click', () => {
        if (!currentStory()) return;
        pauseStory();
        sv.viewersPanel.hidden = false;
        sv.viewersList.replaceChildren(h('<div class="loader-row"><div class="spinner"></div></div>'));
        refreshStoryViewers();
    });

    // While I'm looking at my own story, keep its viewers fresh without a page reload.
    setInterval(() => {
        if (sv.viewer.hidden || sv.viewsBtn.hidden || document.hidden || socketConnected()) return;
        refreshStoryViewers({ silent: true });
    }, STORY_VIEWERS_POLL_MS);

    sv.viewersClose.addEventListener('click', () => { sv.viewersPanel.hidden = true; resumeStory(); });

    sv.replyInput.addEventListener('focus', pauseStory);
    sv.replyInput.addEventListener('blur', () => setTimeout(resumeStory, 0));
    sv.replyForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const text = sv.replyInput.value.trim();
        const group = viewer.groups[viewer.groupIndex];
        if (!text || !group) return;
        const story = currentStory();
        sv.replyInput.value = '';
        sv.replyInput.blur();
        api('POST', `/api/chats/${group.user.id}/messages`, { body: text, story_id: story?.id ?? null })
            .then(() => { showToast('Javob yuborildi'); scheduleReloadChats(); })
            .catch((err) => showToast(err.message));
    });

    document.addEventListener('keydown', (e) => {
        if (sv.viewer.hidden || e.target === sv.replyInput) return;
        if (e.key === 'Escape') closeStoryViewer();
        else if (e.key === 'ArrowRight') nextStory();
        else if (e.key === 'ArrowLeft') prevStory();
    });

    el.peerAvatar.addEventListener('click', () => {
        if (state.peer && state.peer.id !== me.id && storyStatus(state.peer.id)) openStoryViewer(state.peer.id);
    });

    setInterval(() => { if (sv.viewer.hidden && !document.hidden && !socketConnected()) loadStories().catch(() => {}); }, STORIES_POLL_MS);

    // ================================================================ SYNC LOOP
    let syncTimer;
    let syncing = false;
    let syncQueued = false;

    async function sync() {
        if (syncing) return;
        syncing = true;
        try {
            const params = new URLSearchParams();
            if (state.cursor) params.set('since', state.cursor);
            if (state.peer) params.set('peer', state.peer.id);
            const res = await api('GET', `/api/sync?${params}`);
            state.cursor = res.cursor;
            applySync(res);
        } catch { /* network hiccup: try again on next tick */ } finally {
            syncing = false;
            clearTimeout(syncTimer);
            if (syncQueued) {
                syncQueued = false;
                syncTimer = setTimeout(sync, 0);
            } else {
                syncTimer = setTimeout(sync, document.hidden ? POLL_HIDDEN_MS : (socketConnected() ? POLL_SOCKET_MS : POLL_ACTIVE_MS));
            }
        }
    }

    function syncNow() {
        if (syncing) { syncQueued = true; return; }
        clearTimeout(syncTimer);
        sync();
    }

    // ================================================================ WEBSOCKET
    function socketConnected() {
        return window.Echo?.connector?.pusher?.connection?.state === 'connected';
    }

    window.Echo?.private(`App.Models.User.${me.id}`).listen('.sync', ({ topic, story_id: storyId }) => {
        if (topic === 'messages') {
            syncNow();
        } else if (topic === 'stories') {
            if (sv.viewer.hidden) loadStories().catch(() => {});
            else storiesDirty = true;
        } else if (topic === 'story-viewed') {
            if (!sv.viewer.hidden && currentStory()?.id === storyId) refreshStoryViewers({ silent: true });
            else if (sv.viewer.hidden) loadStories().catch(() => {});
            else storiesDirty = true;
        }
    });

    // Pings sent while the socket was reconnecting are lost, so catch up once it is back.
    window.Echo?.connector?.pusher?.connection?.bind('connected', () => {
        syncNow();
        if (sv.viewer.hidden) loadStories().catch(() => {});
        else storiesDirty = true;
    });

    function applySync({ messages, peer }) {
        const touchedPeers = new Set();
        let chatsDirty = false;
        let incomingForOpen = 0;

        for (const incoming of messages) {
            const peerId = partnerOf(incoming);
            const store = messagesOf(peerId);
            const json = JSON.stringify(incoming);
            const seenBefore = state.seen.get(incoming.id);
            if (seenBefore === json) continue;
            state.seen.set(incoming.id, json);

            const isNew = seenBefore === undefined && !store.has(incoming.id) && !incoming.deleted && incoming.sender_id !== me.id;
            if (isNew && state.peer?.id === peerId) incomingForOpen++;
            if (isNew && (state.peer?.id !== peerId || document.hidden)) notify(incoming);

            // Only cache history for chats we've opened; others are summarised in the sidebar.
            if (state.loaded.has(peerId)) store.set(incoming.id, incoming);
            touchedPeers.add(peerId);
            chatsDirty = true;
        }

        if (peer && state.peer?.id === peer.id) {
            const before = JSON.stringify(state.users.get(peer.id));
            state.users.set(peer.id, peer);
            if (before !== JSON.stringify(peer)) renderHeader();
        }

        if (state.peer && touchedPeers.has(state.peer.id)) {
            const near = isNearBottom();
            renderMessages();
            if (near) { scrollToBottom(true); markRead(); } else { state.newWhileAway += incomingForOpen; updateScrollDown(); }
        }

        if (chatsDirty) scheduleReloadChats();
    }

    // ---------------------------------------------------------------- notifications (sound + system)
    let audioCtx;
    function beep() {
        try {
            audioCtx ||= new (window.AudioContext || window.webkitAudioContext)();
            const o = audioCtx.createOscillator();
            const g = audioCtx.createGain();
            o.type = 'sine';
            o.frequency.setValueAtTime(880, audioCtx.currentTime);
            o.frequency.exponentialRampToValueAtTime(1320, audioCtx.currentTime + 0.08);
            g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.15, audioCtx.currentTime + 0.01);
            g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.25);
            o.connect(g).connect(audioCtx.destination);
            o.start();
            o.stop(audioCtx.currentTime + 0.26);
        } catch { /* audio not allowed yet */ }
    }

    function notify(message) {
        beep();
        if (!document.hidden || !('Notification' in window) || Notification.permission !== 'granted') return;
        const sender = state.users.get(message.sender_id) || state.chats.get(message.sender_id)?.user;
        const n = new Notification(sender?.name || 'Yangi xabar', { body: previewText(message), tag: `chat-${message.sender_id}` });
        n.onclick = () => { window.focus(); if (sender) openChat(sender); n.close(); };
    }

    document.addEventListener('click', () => {
        if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    }, { once: true });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { markRead(); clearTimeout(syncTimer); sync(); }
    });

    // Re-render relative "last seen" labels and day separators occasionally.
    setInterval(() => { if (state.peer) renderHeader(); }, 30000);

    // ================================================================ BOOT
    (async () => {
        renderSidebar();
        renderStories();
        try { await Promise.all([loadChats(), loadStories()]); } catch (e) { showToast(e.message); }
        const match = location.hash.match(/^#@([a-z0-9_]+)$/i);
        if (match) {
            history.replaceState({ peer: match[1] }, '', location.href);
            await openChatByUsername(match[1], false);
        }
        sync();
    })();
}
