#!/usr/bin/env python3
"""
Descarga todos los municipios de España del INE y genera src/municipios-data.js
Usa curl del sistema (más fiable que urllib para evitar bloqueos SSL del INE).
Uso: python3 scripts/gen-municipios.py
"""
import subprocess
import json
import time
import os
import sys

PROVINCIAS = [
    {"code": "01", "name": "Álava / Araba"},
    {"code": "02", "name": "Albacete"},
    {"code": "03", "name": "Alicante / Alacant"},
    {"code": "04", "name": "Almería"},
    {"code": "05", "name": "Ávila"},
    {"code": "06", "name": "Badajoz"},
    {"code": "07", "name": "Balears (Illes)"},
    {"code": "08", "name": "Barcelona"},
    {"code": "09", "name": "Burgos"},
    {"code": "10", "name": "Cáceres"},
    {"code": "11", "name": "Cádiz"},
    {"code": "12", "name": "Castellón / Castelló"},
    {"code": "13", "name": "Ciudad Real"},
    {"code": "14", "name": "Córdoba"},
    {"code": "15", "name": "A Coruña"},
    {"code": "16", "name": "Cuenca"},
    {"code": "17", "name": "Girona"},
    {"code": "18", "name": "Granada"},
    {"code": "19", "name": "Guadalajara"},
    {"code": "20", "name": "Gipuzkoa"},
    {"code": "21", "name": "Huelva"},
    {"code": "22", "name": "Huesca"},
    {"code": "23", "name": "Jaén"},
    {"code": "24", "name": "León"},
    {"code": "25", "name": "Lleida"},
    {"code": "26", "name": "La Rioja"},
    {"code": "27", "name": "Lugo"},
    {"code": "28", "name": "Madrid"},
    {"code": "29", "name": "Málaga"},
    {"code": "30", "name": "Murcia"},
    {"code": "31", "name": "Navarra"},
    {"code": "32", "name": "Ourense"},
    {"code": "33", "name": "Asturias"},
    {"code": "34", "name": "Palencia"},
    {"code": "35", "name": "Las Palmas"},
    {"code": "36", "name": "Pontevedra"},
    {"code": "37", "name": "Salamanca"},
    {"code": "38", "name": "Santa Cruz de Tenerife"},
    {"code": "39", "name": "Cantabria"},
    {"code": "40", "name": "Segovia"},
    {"code": "41", "name": "Sevilla"},
    {"code": "42", "name": "Soria"},
    {"code": "43", "name": "Tarragona"},
    {"code": "44", "name": "Teruel"},
    {"code": "45", "name": "Toledo"},
    {"code": "46", "name": "Valencia / València"},
    {"code": "47", "name": "Valladolid"},
    {"code": "48", "name": "Bizkaia"},
    {"code": "49", "name": "Zamora"},
    {"code": "50", "name": "Zaragoza"},
    {"code": "51", "name": "Ceuta"},
    {"code": "52", "name": "Melilla"},
]

def fetch_with_curl(prov_id):
    url = f"https://servicios.ine.es/wstempus/js/es/MUNICIPIOS_PROVINCIA?id={prov_id}"
    r = subprocess.run(
        ["curl", "-s", "--max-time", "30",
         "-H", "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36",
         "-H", "Accept: application/json, */*",
         "-H", "Accept-Language: es-ES,es;q=0.9",
         "-H", "Referer: https://www.ine.es/",
         url],
        capture_output=True, timeout=35
    )
    raw = r.stdout
    if not raw:
        stderr = r.stderr.decode("utf-8", errors="replace")[:200]
        raise RuntimeError(f"Respuesta vacía. curl stderr: {stderr}")
    try:
        data = json.loads(raw.decode("utf-8"))
    except json.JSONDecodeError:
        snippet = raw[:150].decode("utf-8", errors="replace")
        raise RuntimeError(f"JSON inválido: {snippet!r}")
    names = sorted(set(m["Nombre"] for m in data if m.get("Nombre")),
                   key=lambda x: x.casefold())
    return names

def main():
    # Verificar que curl está disponible
    try:
        subprocess.run(["curl", "--version"], capture_output=True, check=True)
    except (FileNotFoundError, subprocess.CalledProcessError):
        print("❌  curl no encontrado. Instálalo o usa el script Node.")
        sys.exit(1)

    print("Descargando municipios de España del INE (via curl)...\n")
    result = {}
    failed = []

    for p in PROVINCIAS:
        n = int(p["code"])
        label = f"[{p['code']}] {p['name']}"
        print(f"  {label:<38}", end="", flush=True)

        success = False
        for attempt in range(3):
            try:
                if attempt > 0:
                    wait = 10 * attempt
                    print(f"\n    Reintento {attempt}/2 (espera {wait}s)...", end="", flush=True)
                    time.sleep(wait)
                names = fetch_with_curl(n)
                result[p["code"]] = names
                print(f"✅  {len(names)} municipios")
                success = True
                break
            except Exception as e:
                if attempt == 2:
                    print(f"❌  {e}")
                    result[p["code"]] = []
                    failed.append(p["code"])

        time.sleep(2)

    total = sum(len(v) for v in result.values())
    script_dir = os.path.dirname(os.path.abspath(__file__))
    out_path = os.path.join(script_dir, "..", "src", "municipios-data.js")

    with open(out_path, "w", encoding="utf-8") as f:
        f.write("// Municipios de España — fuente: INE\n")
        f.write("// Generado por: python3 scripts/gen-municipios.py\n")
        f.write(f"// Total: {total} municipios en {len(PROVINCIAS)} provincias\n")
        f.write(f"export const MUNICIPIOS_ES = {json.dumps(result, ensure_ascii=False)};\n")

    size_kb = os.path.getsize(out_path) // 1024
    print(f"\n✅  Generado: src/municipios-data.js  ({total} municipios, {size_kb} KB)")
    if failed:
        print(f"⚠️  Provincias fallidas (vacías): {', '.join(failed)}")
        print("    Vuelve a ejecutar el script para reintentar solo las que fallaron.")
    print('\n   git add src/municipios-data.js && git commit -m "Add municipios data" && git push')

if __name__ == "__main__":
    main()
