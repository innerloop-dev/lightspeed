// Copied verbatim from docs/configuration.md, "Client setup".
//
// The other page in this demo (resources/views/welcome.blade.php) drives raw
// pusher-js on purpose, so a reader can see every frame. This file is the
// opposite: it is exactly what the documentation tells you to write, run as
// written, so the documented path is proven rather than described.

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// laravel-echo reads the Pusher client off the window
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb', // the pusher-protocol driver; talks to Lightspeed unchanged
    key: import.meta.env.VITE_LIGHTSPEED_APP_KEY,
    wsHost: import.meta.env.VITE_LIGHTSPEED_HOST,
    wsPort: Number(import.meta.env.VITE_LIGHTSPEED_PORT),
    wssPort: Number(import.meta.env.VITE_LIGHTSPEED_PORT),
    forceTLS: import.meta.env.VITE_LIGHTSPEED_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
});
