import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Configurar Axios
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Configurar Pusher
window.Pusher = Pusher;

// Configurar Laravel Echo
window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    
    // Configuración para desarrollo local con Laravel WebSockets
    wsHost: import.meta.env.VITE_PUSHER_HOST ? import.meta.env.VITE_PUSHER_HOST : window.location.hostname,
    wsPort: import.meta.env.VITE_PUSHER_PORT ? parseInt(import.meta.env.VITE_PUSHER_PORT) : 6001,
    wssPort: import.meta.env.VITE_PUSHER_PORT ? parseInt(import.meta.env.VITE_PUSHER_PORT) : 6001,
    forceTLS: (import.meta.env.VITE_PUSHER_SCHEME === 'https'),
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    
    // Configuración adicional para Laravel Sanctum si usas autenticación
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        }
    }
});