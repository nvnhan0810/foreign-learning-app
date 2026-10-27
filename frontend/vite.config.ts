import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@/Components': fileURLToPath(new URL('./src/presentation/Components', import.meta.url)),
      '@/Layouts': fileURLToPath(new URL('./src/presentation/Layouts', import.meta.url)),
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    proxy: {
      '^/(?:$|\\?)': {
        target: 'http://127.0.0.1:8080',
        bypass: (req) => (req.headers['x-inertia'] ? undefined : '/index.html'),
      },
      '^/(?:home|login|lookup|listening)(?:/|$|\\?)': {
        target: 'http://127.0.0.1:8080',
        bypass: (req) => {
          const pathname = (req.url ?? '').split('?')[0] ?? '';
          const backendResource =
            pathname.endsWith('/audio') ||
            pathname.endsWith('/pronounce') ||
            pathname.startsWith('/home/word/');
          if (
            req.method === 'GET' &&
            !req.headers['x-inertia'] &&
            !backendResource &&
            String(req.headers.accept).includes('text/html')
          )
            return '/index.html';
          return undefined;
        },
      },
      '/api': 'http://127.0.0.1:8080',
      '/auth': 'http://127.0.0.1:8080',
      '/logout': 'http://127.0.0.1:8080',
      '/media': 'http://127.0.0.1:8080',
      '/sanctum': 'http://127.0.0.1:8080',
      '/admin': 'http://127.0.0.1:8080',
    },
  },
});
