import './presentation/styles/app.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp, Link, Head } from '@inertiajs/vue3';
import { HttpPageLoader } from './infrastructure/http-page-loader';

const pages = import.meta.glob<DefineComponent>('./presentation/Pages/**/*.vue', {
  import: 'default',
});

async function start(): Promise<void> {
  const page = await new HttpPageLoader().load(window.location.href);
  // Login redirects must agree with the initial history entry (including WebView handoffs).
  if (page.url !== window.location.pathname + window.location.search) {
    window.history.replaceState(null, '', page.url);
  }
  await createInertiaApp({
    page,
    title: (title) => (title ? `${title} — FLC` : 'FLC'),
    resolve: async (name) => {
      const load = pages[`./presentation/Pages/${name}.vue`];
      if (!load) throw new Error(`Unknown page: ${name}`);
      return load();
    },
    setup({ el, App, props, plugin }) {
      createApp({ render: () => h(App, props) })
        .use(plugin)
        .component('Link', Link)
        .component('Head', Head)
        .mount(el);
    },
    progress: { color: '#4361ee', showSpinner: false },
  });
}

void start().catch((error: unknown) => {
  const root = document.getElementById('app');
  if (!root) return;
  root.replaceChildren();
  const message = document.createElement('p');
  message.textContent = error instanceof Error ? error.message : 'Unable to load FLC.';
  const retry = document.createElement('button');
  retry.textContent = 'Retry';
  retry.addEventListener('click', () => window.location.reload());
  root.append(message, retry);
});

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    void navigator.serviceWorker.register('/sw.js').catch(() => {
      // Private browsers may disallow service workers; the online app still works.
    });
  });
}
