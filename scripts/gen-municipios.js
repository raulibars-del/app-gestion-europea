#!/usr/bin/env node
/**
 * Descarga todos los municipios de España del INE y genera src/municipios-data.js
 * Uso: node scripts/gen-municipios.js
 */

import https from 'https';
import fs    from 'fs';
import path  from 'path';
import { fileURLToPath } from 'url';
const __dirname = path.dirname(fileURLToPath(import.meta.url));

const PROVINCIAS = [
  {code:'01',name:'Álava / Araba'},
  {code:'02',name:'Albacete'},
  {code:'03',name:'Alicante / Alacant'},
  {code:'04',name:'Almería'},
  {code:'05',name:'Ávila'},
  {code:'06',name:'Badajoz'},
  {code:'07',name:'Balears (Illes)'},
  {code:'08',name:'Barcelona'},
  {code:'09',name:'Burgos'},
  {code:'10',name:'Cáceres'},
  {code:'11',name:'Cádiz'},
  {code:'12',name:'Castellón / Castelló'},
  {code:'13',name:'Ciudad Real'},
  {code:'14',name:'Córdoba'},
  {code:'15',name:'A Coruña'},
  {code:'16',name:'Cuenca'},
  {code:'17',name:'Girona'},
  {code:'18',name:'Granada'},
  {code:'19',name:'Guadalajara'},
  {code:'20',name:'Gipuzkoa'},
  {code:'21',name:'Huelva'},
  {code:'22',name:'Huesca'},
  {code:'23',name:'Jaén'},
  {code:'24',name:'León'},
  {code:'25',name:'Lleida'},
  {code:'26',name:'La Rioja'},
  {code:'27',name:'Lugo'},
  {code:'28',name:'Madrid'},
  {code:'29',name:'Málaga'},
  {code:'30',name:'Murcia'},
  {code:'31',name:'Navarra'},
  {code:'32',name:'Ourense'},
  {code:'33',name:'Asturias'},
  {code:'34',name:'Palencia'},
  {code:'35',name:'Las Palmas'},
  {code:'36',name:'Pontevedra'},
  {code:'37',name:'Salamanca'},
  {code:'38',name:'Santa Cruz de Tenerife'},
  {code:'39',name:'Cantabria'},
  {code:'40',name:'Segovia'},
  {code:'41',name:'Sevilla'},
  {code:'42',name:'Soria'},
  {code:'43',name:'Tarragona'},
  {code:'44',name:'Teruel'},
  {code:'45',name:'Toledo'},
  {code:'46',name:'Valencia / València'},
  {code:'47',name:'Valladolid'},
  {code:'48',name:'Bizkaia'},
  {code:'49',name:'Zamora'},
  {code:'50',name:'Zaragoza'},
  {code:'51',name:'Ceuta'},
  {code:'52',name:'Melilla'},
];

function fetchJSON(url) {
  return new Promise((resolve, reject) => {
    https.get(url, { headers: { 'User-Agent': 'Mozilla/5.0' } }, (res) => {
      if (res.statusCode !== 200) { reject(new Error(`HTTP ${res.statusCode}`)); return; }
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => { try { resolve(JSON.parse(data)); } catch(e) { reject(e); } });
    }).on('error', reject);
  });
}

async function main() {
  console.log('Descargando municipios de España del INE...\n');
  const result = {};
  for (const p of PROVINCIAS) {
    const n = parseInt(p.code, 10);
    process.stdout.write(`  [${p.code}] ${p.name.padEnd(30)} `);
    try {
      const data = await fetchJSON(`https://servicios.ine.es/wstempus/js/es/MUNICIPIOS_PROVINCIA?id=${n}`);
      const names = [...new Set(data.map(m => m.Nombre).filter(Boolean))].sort((a,b) => a.localeCompare(b,'es'));
      result[p.code] = names;
      console.log(`✅ ${names.length} municipios`);
    } catch(e) {
      console.log(`❌ Error: ${e.message}`);
      result[p.code] = [];
    }
    await new Promise(r => setTimeout(r, 250)); // respetar rate-limit INE
  }

  const total = Object.values(result).reduce((s,a) => s + a.length, 0);
  const out = [
    `// Municipios de España — fuente: INE`,
    `// Generado por: node scripts/gen-municipios.js`,
    `// Total: ${total} municipios en 52 provincias`,
    `export const MUNICIPIOS_ES = ${JSON.stringify(result)};`,
    '',
  ].join('\n');

  const outPath = path.join(__dirname, '../src/municipios-data.js');
  fs.writeFileSync(outPath, out, 'utf8');
  console.log(`\n✅ Generado: src/municipios-data.js  (${total} municipios, ${(out.length/1024).toFixed(0)} KB)`);
  console.log('   Ahora haz: git add src/municipios-data.js && git commit -m "Add municipios data" && git push');
}

main().catch(e => { console.error('Error fatal:', e); process.exit(1); });
