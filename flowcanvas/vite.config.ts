import fs from 'node:fs';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import { viteSingleFile } from 'vite-plugin-singlefile';

/*
 * npm run build:file（--mode standalone）では、配れる1つの HTML（release/FlowCanvas.html）を作る。
 * 図はサーバーではなくブラウザの中に保存する（src/store/localApi.ts）。
 */
function standaloneHtml(): Plugin {
  const icon = 'data:image/svg+xml;base64,' + fs.readFileSync('public/favicon.svg').toString('base64');
  return {
    name: 'flowcanvas-standalone-html',
    transformIndexHtml: (html) =>
      html
        .replace(/\s*<link rel="apple-touch-icon"[^>]*>/, '')
        .replace(/\s*<link rel="manifest"[^>]*>/, '')
        .replace('href="/favicon.svg"', `href="${icon}"`),
  };
}

export default defineConfig(({ mode }) => {
  const standalone = mode === 'standalone';
  return {
    plugins: standalone ? [react(), standaloneHtml(), viteSingleFile()] : [react()],
    publicDir: standalone ? false : 'public',
    build: standalone ? { outDir: 'release', emptyOutDir: true } : undefined,
    server: {
      port: 5173,
      strictPort: true,
      proxy: { '/api': 'http://127.0.0.1:3001' },
    },
  };
});
