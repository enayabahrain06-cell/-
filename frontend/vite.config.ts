import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// In development the Laravel API is reached through the Vite proxy, so no CORS setup is needed.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target = env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000'

  return {
    plugins: [react(), tailwindcss()],
    // @ahl/certificates-react and @ahl/id-card-reader are linked from ../packages as TypeScript source: resolve them inside
    // node_modules (so its peer dependencies come from here) and serve it untouched by the pre-bundler.
    resolve: { preserveSymlinks: true },
    optimizeDeps: { exclude: ['@ahl/certificates-react', '@ahl/id-card-reader'] },
    server: {
      port: 5173,
      // The linked @ahl packages live under node_modules, which Vite does not watch by default: watch them so
      // edits to a package reach the running dev server without a restart.
      watch: { ignored: ['!**/node_modules/@ahl/**'] },
      proxy: {
        '/api': { target, changeOrigin: true },
        '/media': { target, changeOrigin: true },
      },
    },
  }
})
