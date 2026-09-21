import { defineConfig } from 'vite';
import { resolve } from 'path';

/** Content script: một file IIFE (Chrome không chạy ES import trong content script). */
export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: false,
    lib: {
      entry: resolve(__dirname, 'src/content/content-script.ts'),
      formats: ['iife'],
      name: 'FlcContent',
      fileName: () => 'content/content-script.js',
    },
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
      },
    },
  },
});
