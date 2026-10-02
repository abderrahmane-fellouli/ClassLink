import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Le proxy `/api` évite CORS en développement : le navigateur appelle
 * `/api/...` sur le même origine que le frontend, Vite relaie vers Laravel.
 * En production, `VITE_API_URL` pointe vers l'API déployée.
 */
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      // `import.meta.url` plutôt que `__dirname` : le projet est en ESM.
      '@': path.resolve(path.dirname(fileURLToPath(import.meta.url)), './src'),
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    // Par defaut Vite n'ecoute que sur 127.0.0.1 et ne repond qu'a
    // `localhost` : c'est le comportement le plus sur (protection contre le
    // DNS rebinding). Pour une demonstration partagee, on elargit
    // explicitement via deux variables d'environnement :
    //   VITE_DEV_HOST=true              -> ecoute sur 0.0.0.0
    //   VITE_ALLOWED_HOSTS=a,.b.example -> Hotes autorises (un `.` en tete
    //                                      autorise le domaine et ses
    //                                      sous-domaines)
    host: process.env.VITE_DEV_HOST === 'true' ? '0.0.0.0' : '127.0.0.1',
    allowedHosts: process.env.VITE_ALLOWED_HOSTS
      ? process.env.VITE_ALLOWED_HOSTS.split(',').map((h) => h.trim()).filter(Boolean)
      : [],
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY ?? 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    // Le socle React/router est mis en cache séparément des écrans :
    // un changement d'écran ne réinvalid�� plus tout le bundle.
    rolldownOptions: {
      output: {
        codeSplitting: {
          groups: [
            { name: 'react', test: /node_modules[\\/](react|react-dom|scheduler)[\\/]/ },
            { name: 'router', test: /node_modules[\\/]react-router/ },
          ],
        },
      },
    },
  },
  preview: {
    port: 5173,
  },
})
