/// <reference types="vitest" />
import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import path from 'node:path'

export default defineConfig({
  plugins: [react()],
  resolve: { alias: { '@': path.resolve(__dirname, 'src') } },
  server: {
    port: 5173,
    // same-origin in dev so the Sanctum session cookie works without CORS
    proxy: {
      '/api': { target: process.env.VITE_BACKEND_URL ?? 'http://localhost:8000', changeOrigin: false },
      '/sanctum': { target: process.env.VITE_BACKEND_URL ?? 'http://localhost:8000', changeOrigin: false },
    },
  },
  test: { environment: 'jsdom', globals: true, setupFiles: ['./src/test-setup.ts'] },
})
