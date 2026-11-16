import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig(({ command }) => ({
  plugins: [vue()],
  base: command === 'build' ? '/dist/' : '/',
  build: { outDir: 'backend/public/dist', emptyOutDir: true },
  server: { proxy: { '/api': { target: 'http://127.0.0.1:8000' } } },
}))
