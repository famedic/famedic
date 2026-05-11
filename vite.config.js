import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

/** Orígenes que pueden cargar módulos desde el dev server (página en nginx u otro puerto). */
function devServerCorsOrigins(appUrl) {
    const base = (appUrl || 'http://localhost:8080').replace(/\/$/, '');
    const origins = new Set([
        base,
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ]);
    try {
        const u = new URL(base);
        const alt =
            u.hostname === 'localhost'
                ? '127.0.0.1'
                : u.hostname === '127.0.0.1'
                  ? 'localhost'
                  : null;
        if (alt) {
            origins.add(`${u.protocol}//${alt}${u.port ? `:${u.port}` : ''}`);
        }
    } catch {
        /* APP_URL inválida: se usan valores por defecto arriba */
    }
    return [...origins];
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = env.APP_URL;

    return {
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            origin: 'http://localhost:5173',
            hmr: {
                host: 'localhost',
                port: 5173,
                clientPort: 5173,
            },
            cors: {
                origin: devServerCorsOrigins(appUrl),
            },
            watch: {
                usePolling: true,
                ignored: ['**/.env', '**/.env.*'],
            },
        },
        plugins: [
            laravel({
                input: 'resources/js/app.jsx',
                ssr: 'resources/js/ssr.jsx',
                refresh: true,
            }),
            react(),
        ],
    };
});
