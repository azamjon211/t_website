@extends('layouts.app')

@section('title', 'Chat')
@section('body-class', 'chat-page')

@section('content')
    <div id="app" class="app" data-csrf="{{ csrf_token() }}">
        {{-- ============ Sidebar ============ --}}
        <aside class="sidebar">
            <header class="sidebar-header">
                <div class="menu-wrap">
                    <button type="button" class="icon-btn" id="menuBtn" aria-label="Menyu">
                        <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <div class="dropdown" id="menu" hidden>
                        <div class="dropdown-profile">
                            <div class="avatar" data-avatar="{{ $user->id }}">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</div>
                            <div>
                                <div class="dropdown-name" id="menuName">{{ $user->name }}</div>
                                <div class="dropdown-username">{{ '@'.$user->username }}</div>
                            </div>
                        </div>
                        <button type="button" class="dropdown-item" id="profileBtn">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg> Profil
                        </button>
                        <button type="button" class="dropdown-item" id="addStoryMenuBtn">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-dasharray="4 3"/><path d="M12 8v8M8 12h8"/></svg> Story qo'shish
                        </button>
                        <button type="button" class="dropdown-item" id="savedBtn">
                            <svg viewBox="0 0 24 24"><path d="M6 3h12v18l-6-4-6 4z"/></svg> Saved Messages
                        </button>
                        <button type="button" class="dropdown-item" id="themeBtn">
                            <svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg> Tun / kun rejimi
                        </button>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item danger">
                                <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l-5-5 5-5M5 12h12"/></svg> Chiqish
                            </button>
                        </form>
                    </div>
                </div>
                <div class="search">
                    <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                    <input type="search" id="search" placeholder="Qidirish (@username)" autocomplete="off" spellcheck="false">
                </div>
            </header>
            <div class="stories" id="stories" hidden></div>
            <div class="chat-list" id="chatList" role="list"></div>
        </aside>

        {{-- ============ Conversation ============ --}}
        <main class="conversation" id="conversation">
            <div class="conversation-empty" id="emptyState">
                <span>Suhbatni boshlash uchun chatni tanlang yoki @username bo'yicha qidiring</span>
            </div>

            <div class="conversation-inner" id="conversationInner" hidden>
                <header class="chat-header">
                    <button type="button" class="icon-btn back-btn" id="backBtn" aria-label="Orqaga">
                        <svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
                    </button>
                    <div class="avatar" id="peerAvatar"></div>
                    <div class="chat-header-info">
                        <div class="chat-header-name" id="peerName"></div>
                        <div class="chat-header-status" id="peerStatus"></div>
                    </div>
                </header>

                <div class="messages-scroll" id="messagesScroll">
                    <div class="messages" id="messages"></div>
                </div>

                <button type="button" class="scroll-down" id="scrollDown" hidden aria-label="Pastga">
                    <svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
                    <span class="badge" id="scrollDownBadge" hidden></span>
                </button>

                <footer class="composer-wrap">
                    <div class="composer">
                        <div class="composer-context" id="composerContext" hidden>
                            <svg viewBox="0 0 24 24" id="composerContextIcon"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/></svg>
                            <div class="composer-context-text">
                                <div class="composer-context-title" id="composerContextTitle"></div>
                                <div class="composer-context-body" id="composerContextBody"></div>
                            </div>
                            <button type="button" class="icon-btn" id="composerContextClose" aria-label="Bekor qilish">
                                <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
                            </button>
                        </div>
                        <div class="composer-row">
                            <button type="button" class="icon-btn" id="attachBtn" aria-label="Fayl biriktirish">
                                <svg viewBox="0 0 24 24"><path d="M21 11.5l-8.6 8.6a5.5 5.5 0 0 1-7.8-7.8l8.9-8.9a3.7 3.7 0 0 1 5.2 5.2l-8.9 8.9a1.8 1.8 0 0 1-2.6-2.6l8.2-8.2"/></svg>
                            </button>
                            <input type="file" id="fileInput" multiple hidden>
                            <textarea id="input" rows="1" placeholder="Xabar yozing..." maxlength="4096"></textarea>
                        </div>
                    </div>
                    <button type="button" class="send-btn" id="sendBtn" aria-label="Yuborish">
                        <svg viewBox="0 0 24 24"><path d="M3.4 20.4l17.4-7.5c.8-.4.8-1.5 0-1.8L3.4 3.6c-.7-.3-1.4.4-1.2 1.1L4 11l9 1-9 1-1.8 6.3c-.2.7.5 1.4 1.2 1.1z"/></svg>
                    </button>
                </footer>

                <div class="drop-overlay" id="dropOverlay" hidden>
                    <div>Fayllarni shu yerga tashlang</div>
                </div>
            </div>
        </main>
    </div>

    <div class="context-menu" id="contextMenu" hidden></div>

    <div class="lightbox" id="lightbox" hidden>
        <div class="lightbox-bar">
            <a class="icon-btn" id="lightboxDownload" aria-label="Yuklab olish" download>
                <svg viewBox="0 0 24 24"><path d="M12 4v12M6 10l6 6 6-6M4 20h16"/></svg>
            </a>
            <button type="button" class="icon-btn" id="lightboxClose" aria-label="Yopish">
                <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="lightbox-body" id="lightboxBody"></div>
    </div>

    {{-- ============ Profile ============ --}}
    <div class="modal-backdrop" id="profileModal" hidden>
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="profileTitle">
            <header class="modal-header">
                <h2 id="profileTitle">Profil</h2>
                <button type="button" class="icon-btn" data-close aria-label="Yopish">
                    <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </header>
            <div class="profile-avatar-wrap">
                <button type="button" class="avatar profile-avatar" id="profileAvatar" aria-label="Rasmni o'zgartirish"></button>
                <div class="profile-avatar-overlay" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                </div>
                <div class="upload-ring" id="avatarProgress" hidden><div class="spinner"></div></div>
            </div>
            <div class="profile-actions">
                <button type="button" class="btn-ghost" id="avatarUploadBtn">Rasm yuklash</button>
                <button type="button" class="btn-ghost danger" id="avatarRemoveBtn">Rasmni o'chirish</button>
            </div>
            <input type="file" id="avatarInput" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
            <form id="profileForm" class="profile-form">
                <label class="field">
                    <input type="text" id="profileName" name="name" placeholder=" " maxlength="64" required>
                    <span>Ism</span>
                </label>
                <div class="profile-username">Username: <b id="profileUsername"></b></div>
                <button type="submit" class="btn-primary">Saqlash</button>
            </form>
        </div>
    </div>

    {{-- ============ Story composer ============ --}}
    <div class="modal-backdrop" id="storyComposer" hidden>
        <div class="modal story-composer" role="dialog" aria-modal="true" aria-labelledby="storyComposerTitle">
            <header class="modal-header">
                <h2 id="storyComposerTitle">Yangi story</h2>
                <button type="button" class="icon-btn" data-close aria-label="Yopish">
                    <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </header>
            <div class="story-preview" id="storyPreview"></div>
            <input type="text" class="story-caption-input" id="storyCaption" maxlength="200" placeholder="Izoh qo'shing...">
            <div class="upload-progress" id="storyProgress" hidden><div style="width:0%"></div></div>
            <button type="button" class="btn-primary" id="storyPublishBtn">Joylash · 24 soat</button>
        </div>
    </div>
    <input type="file" id="storyInput" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime" hidden>

    {{-- ============ Story viewer ============ --}}
    <div class="story-viewer" id="storyViewer" hidden>
        <div class="story-stage" id="storyStage">
            <div class="story-bars" id="storyBars"></div>
            <header class="story-top">
                <div class="avatar" id="storyAvatar"></div>
                <div class="story-who">
                    <div class="story-name" id="storyName"></div>
                    <div class="story-time" id="storyTime"></div>
                </div>
                <button type="button" class="icon-btn" id="storyMuteBtn" aria-label="Ovoz" hidden>
                    <svg viewBox="0 0 24 24"><path d="M4 9h4l5-4v14l-5-4H4zM17 9a4 4 0 0 1 0 6"/></svg>
                </button>
                <button type="button" class="icon-btn" id="storyDeleteBtn" aria-label="O'chirish" hidden>
                    <svg viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
                </button>
                <button type="button" class="icon-btn" id="storyCloseBtn" aria-label="Yopish">
                    <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </header>
            <div class="story-media" id="storyMedia"></div>
            <div class="story-tap story-tap-prev" id="storyPrev"></div>
            <div class="story-tap story-tap-next" id="storyNext"></div>
            <div class="story-caption" id="storyCaptionView" hidden></div>
            <footer class="story-bottom">
                <button type="button" class="story-views-btn" id="storyViewsBtn" hidden>
                    <svg viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span id="storyViewsCount">0</span>
                </button>
                <form class="story-reply" id="storyReplyForm" hidden>
                    <input type="text" id="storyReplyInput" maxlength="4096" placeholder="Javob yozing..." autocomplete="off">
                    <button type="submit" class="icon-btn" aria-label="Yuborish">
                        <svg viewBox="0 0 24 24"><path d="M3.4 20.4l17.4-7.5c.8-.4.8-1.5 0-1.8L3.4 3.6c-.7-.3-1.4.4-1.2 1.1L4 11l9 1-9 1-1.8 6.3c-.2.7.5 1.4 1.2 1.1z" fill="currentColor" stroke="none"/></svg>
                    </button>
                </form>
            </footer>
            <div class="story-viewers-panel" id="storyViewersPanel" hidden>
                <header>
                    <b>Ko'rganlar</b>
                    <button type="button" class="icon-btn" id="storyViewersClose" aria-label="Yopish">
                        <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </header>
                <div class="story-viewers-list" id="storyViewersList"></div>
            </div>
        </div>
    </div>

    <div class="toast" id="toast" hidden></div>

    <script>
        window.__CHAT__ = @json($bootstrap);
    </script>
@endsection
