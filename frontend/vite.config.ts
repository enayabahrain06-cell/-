import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// In development the Laravel API is reached through the Vite proxy, so no CORS setup is needed.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target = env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000'

  return {
    plugins: [react(), tailwindcss()],
    server: {
      port: 5173,
      proxy: {
        '/api': { target, changeOrigin: true },
        '/media': { target, changeOrigin: true },
      },
    },
  }
})
