import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  base: process.env.GITHUB_ACTIONS ? '/SedergPay_oficial/' : '/',
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': process.env.VITE_DEV_API_PROXY ?? 'http://127.0.0.1:8081',
    },
  },
});
