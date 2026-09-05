import fs from 'node:fs';
import path from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

const certDir = path.resolve(__dirname, '.infrastructure/conf/traefik/dev/certificates');
const keyPath = path.join(certDir, 'local-dev-key.pem');
const certPath = path.join(certDir, 'local-dev.pem');

// Dev-server TLS certs are only needed when serving (HMR over HTTPS), and only
// when they actually exist. Skip during `vite build` and when certs are absent
// (e.g. CI), so dev still starts over HTTP instead of crashing.
const devHttps =
    fs.existsSync(keyPath) && fs.existsSync(certPath)
        ? {
              key: fs.readFileSync(keyPath),
              cert: fs.readFileSync(certPath),
          }
        : undefined;

export default defineConfig(({ command }) => ({
    server: {
        host: '0.0.0.0',
        hmr: {
            host: 'vite.vantagejobs.test',
            clientPort: 443,
        },
        https: command === 'serve' ? devHttps : undefined,
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
}));
