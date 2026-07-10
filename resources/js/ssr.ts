import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ConfigProvider } from 'reka-ui';
import { createSSRApp, DefineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,
            /** Pages provide complete titles (SeoHead bakes the site name in server-side), so never re-append it. */
            title: (title) => title || appName,
            resolve: resolvePage,
            setup: ({ App, props, plugin }) => createSSRApp({ render: () => h(ConfigProvider, { dir: 'rtl' }, () => h(App, props)) }).use(plugin),
            defaults: {
                visitOptions: (href, options) => {
                    return { viewTransition: !options.preserveState };
                },
            },
        }),
    { cluster: true },
);

function resolvePage(name: string) {
    const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');

    return resolvePageComponent<DefineComponent>(`./pages/${name}.vue`, pages);
}
