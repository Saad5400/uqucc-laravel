import '../css/app.css';
import '../css/typography.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ConfigProvider } from 'reka-ui';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    /** Pages provide complete titles (SeoHead bakes the site name in server-side), so never re-append it. */
    title: (title) => title || appName,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(ConfigProvider, { dir: 'rtl' }, () => h(App, props)) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
    defaults: {
        visitOptions: (href, options) => {
            return { viewTransition: !options.preserveState };
        },
    },
});
