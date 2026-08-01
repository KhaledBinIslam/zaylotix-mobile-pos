import { defineConfig } from 'vite';
import { fileURLToPath, URL } from 'node:url';

// Separate from vite.config.js on purpose — that one carries the Laravel
// dev-server plugin, which Vitest doesn't need and shouldn't try to boot.
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['resources/js/**/__tests__/**/*.test.js'],
    },
});
