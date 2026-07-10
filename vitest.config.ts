import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Standalone Vitest config kept separate from vite.config.ts so the test
// runner does not pull in the Laravel/Wayfinder/Tailwind build plugins.
// The Vue plugin is needed because some tested modules (e.g. the TipTap
// extension contract tests) import extensions that reference .vue node views.
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['resources/js/**/*.{test,spec}.ts'],
    },
});
