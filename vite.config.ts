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
    // the dep pre-bundle lets plugin-vue compile its SFCs, and noExternal
    // pulls it through the same pipeline in the SSR build.
    optimizeDeps: {
        exclude: ['@saad5400/ai-kit'],
    },
    ssr: {
        noExternal: ['@saad5400/ai-kit'],
    },
});
