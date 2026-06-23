import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
// Fecha de build (se ejecuta en el runner de GitHub Actions justo tras cada
// "git push origin main", que es lo que dispara el deploy), para mostrar en el
// pie de la app cuándo se subió la última actualización sin mantenerla a mano.
const fechaBuild = (() => {
  const d = new Date();
  const p = n => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()}`;
})();
export default defineConfig({
  plugins: [react()],
  base: '/',
  define: {
    __BUILD_DATE__: JSON.stringify(fechaBuild),
  },
})
