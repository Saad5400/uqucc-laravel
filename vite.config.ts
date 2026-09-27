import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts', 'resources/css/app.css', 'resources/css/typography.css'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
    // @saad5400/ai-kit ships .ts/.vue sources, not a build. Excluding it from
    // the dep pre-bundle lets plugin-vue compile its SFCs.
    optimizeDeps: {
        exclude: ['@saad5400/ai-kit'],
    },
    ssr: {
        // Bundle every dependency into bootstrap/ssr (the kit's SFCs included,
        // through the same plugin-vue pipeline), so the SSR server needs almost
        // nothing from node_modules at runtime. The one exception is
        // isomorphic-dompurify: its jsdom reads its own files at load time
        // (default-stylesheet.css, xhr-sync-worker.js), which a bundle cannot
        // carry. The Railpack build prunes node_modules down to it and
        // @takumi-rs (scripts/prune-node-modules.mjs).
        noExternal: true,
        external: ['isomorphic-dompurify'],
    },
});
