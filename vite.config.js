import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { writeFileSync } from 'node:fs'
// Fecha de build (se ejecuta en el runner de GitHub Actions justo tras cada
// "git push origin main", que es lo que dispara el deploy), para mostrar en el
// pie de la app cuándo se subió la última actualización sin mantenerla a mano.
const fechaBuild = (() => {
  const d = new Date();
  const p = n => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()}`;
})();
// Id único de esta build (timestamp). Se escribe en public/version.json para
// que la app, ya en marcha en el navegador, pueda comparar periódicamente su
// propio id (incrustado en el bundle vía __BUILD_ID__) con el de version.json
// y detectar así que hay una versión más nueva desplegada. Esto soluciona el
// caso de una pestaña abierta desde hace tiempo que sigue ejecutando JS
// antiguo (con su lógica de guardado antigua, sin ninguna de estas
// protecciones): aunque arreglemos el código en el servidor, esa pestaña
// nunca se entera si no se recarga sola. Con esto se fuerza la recarga.
const buildId = String(Date.now());
try { writeFileSync('public/version.json', JSON.stringify({ build: buildId })); } catch (e) {}
export default defineConfig({
  plugins: [react()],
  base: '/',
  define: {
    __BUILD_DATE__: JSON.stringify(fechaBuild),
    __BUILD_ID__: JSON.stringify(buildId),
  },
})
