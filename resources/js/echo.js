import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Without a Reverb key the chat simply keeps polling, so the websocket is strictly an accelerator.
const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    const scheme = import.meta.env.VITE_REVERB_SCHEME || 'https';

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT || 80,
        wssPort: import.meta.env.VITE_REVERB_PORT || 443,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
