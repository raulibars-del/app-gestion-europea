<?php
// Plantilla de configuración. NO se sube con datos reales:
// el workflow de GitHub Actions genera public/api/config.php
// automáticamente a partir de los GitHub Secrets en cada deploy.
// Esta copia .example solo sirve de referencia.

define('DB_HOST', 'localhost');
define('DB_NAME', 'nombre_basededatos');
define('DB_USER', 'usuario_db');
define('DB_PASS', 'password_db');
define('API_KEY', 'una_clave_secreta_larga');
