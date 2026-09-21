import { defineConfig } from 'vite';
import { resolve } from 'path';

/** YouTube content script: IIFE bundle for watch-page context sync. */
export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: false,
    lib: {
      entry: resolve(__dirname, 'src/content/youtube-content.ts'),
      formats: ['iife'],
      name: 'FlcYoutube',
      fileName: () => 'content/youtube-content.js',
    },
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
      },
    },
  },
});
