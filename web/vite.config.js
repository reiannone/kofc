import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Dev: proxy /api to the local XAMPP backend so the SPA is same-origin in dev too.
// Prod: build static files; Apache serves them at the site root and PHP at /api.
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
        rewrite: (p) => '/kofc' + p,
      },
    },
  },
  build: { outDir: 'dist' },
});