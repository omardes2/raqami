import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// The SPA talks to the Laravel API. In dev we proxy /api and the Sanctum
// endpoints to the backend so cookies are first-party (same origin).
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': { target: 'http://localhost:8000', changeOrigin: true },
      '/sanctum': { target: 'http://localhost:8000', changeOrigin: true },
    },
  },
})
