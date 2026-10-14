import { fileURLToPath, URL } from 'node:url'
import { resolve } from 'node:path'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

// O SPA fala apenas com a API Laravel. Em desenvolvimento, /api é encaminhado ao `php artisan serve`.
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': { target: process.env.VITE_DEV_API_TARGET ?? 'http://127.0.0.1:8000', changeOrigin: false },
    },
  },
  css: {
    preprocessorOptions: {
      // Bootstrap 5.3 ainda usa @import/funções globais do Sass (necessário para o Form.io).
      scss: { quietDeps: true, silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'] },
    },
  },
  build: {
    chunkSizeWarningLimit: 4000,
    rollupOptions: {
      input: {
        main: resolve(fileURLToPath(new URL('.', import.meta.url)), 'index.html'),
        redirect: resolve(fileURLToPath(new URL('.', import.meta.url)), 'redirect.html'),
      },
    },
  },
  test: {
    environment: 'jsdom',
  },
})
