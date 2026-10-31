import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// En desarrollo, las peticiones a /api se reenvían al backend PHP (puerto 8000),
// así el navegador no necesita CORS. El backend también tiene CORS configurado
// por si se llama directamente con VITE_API_URL.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
