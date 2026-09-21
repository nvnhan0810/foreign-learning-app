import { defineConfig } from 'vite';
import { resolve } from 'path';
import { copyFileSync, mkdirSync, readdirSync, statSync } from 'fs';

function copyDir(src: string, dest: string) {
  mkdirSync(dest, { recursive: true });
  for (const entry of readdirSync(src)) {
    const s = resolve(src, entry);
    const d = resolve(dest, entry);
    if (statSync(s).isDirectory()) {
      copyDir(s, d);
    } else {
      copyFileSync(s, d);
    }
  }
}

export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        'background/service-worker': resolve(__dirname, 'src/background/service-worker.ts'),
        'popup/popup': resolve(__dirname, 'src/popup/popup.ts'),
        'options/options': resolve(__dirname, 'src/options/options.ts'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
      },
    },
  },
  plugins: [
    {
      name: 'copy-static',
      closeBundle() {
        copyFileSync(resolve(__dirname, 'manifest.json'), resolve(__dirname, 'dist/manifest.json'));
        copyDir(resolve(__dirname, 'public'), resolve(__dirname, 'dist'));
        for (const page of ['popup', 'options'] as const) {
          copyFileSync(
            resolve(__dirname, `src/${page}/${page}.html`),
            resolve(__dirname, `dist/${page}/${page}.html`)
          );
          const css = resolve(__dirname, `src/${page}/${page}.css`);
          try {
            copyFileSync(css, resolve(__dirname, `dist/${page}/${page}.css`));
          } catch {
            /* optional */
          }
        }
        mkdirSync(resolve(__dirname, 'dist/content'), { recursive: true });
        mkdirSync(resolve(__dirname, 'dist/shared'), { recursive: true });
        copyFileSync(
          resolve(__dirname, 'src/shared/theme.css'),
          resolve(__dirname, 'dist/shared/theme.css')
        );
      },
    },
  ],
});
