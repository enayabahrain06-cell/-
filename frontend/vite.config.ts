import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig, loadEnv, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

const here = path.dirname(fileURLToPath(import.meta.url))

/**
 * The @ahl packages are linked from ../packages, outside this folder, so Vite never watches them, and with
 * preserveSymlinks it knows their modules by the node_modules/@ahl/... path, not the real one. Watch ../packages and
 * map each change back to the linked path, so an edit to a package reloads the page instead of serving a stale copy.
 */
function watchLinkedPackages(): Plugin {
  const packages = path.resolve(here, '../packages')
  const linked = path.resolve(here, 'node_modules/@ahl')
  return {
    name: 'watch-linked-packages',
    apply: 'serve',
    configureServer(server) {
      server.watcher.add(packages)
      server.watcher.on('change', (file) => {
        const rel = path.relative(packages, path.resolve(file))
        if (rel.startsWith('..') || path.isAbsolute(rel)) return
        const id = path.join(linked, rel).split(path.sep).join('/')
        server.moduleGraph.getModulesByFile(id)?.forEach((m) => server.moduleGraph.invalidateModule(m))
        server.ws.send({ type: 'full-reload' })
      })
    },
  }
}

// In development the Laravel API is reached through the Vite proxy, so no CORS setup is needed.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target = env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000'

  return {
    plugins: [react(), tailwindcss(), watchLinkedPackages()],
    // @ahl/certificates-react and @ahl/id-card-reader are linked from ../packages as TypeScript source: resolve them inside
    // node_modules (so its peer dependencies come from here) and serve it untouched by the pre-bundler.
    resolve: { preserveSymlinks: true },
    optimizeDeps: { exclude: ['@ahl/certificates-react', '@ahl/id-card-reader'] },
    server: {
      port: 5173,
      proxy: {
        '/api': { target, changeOrigin: true },
        '/media': { target, changeOrigin: true },
      },
    },
  }
})
