<?php
// Cliente SMTP mínimo, sin librerías externas (no se puede usar composer en el
// hosting compartido de IONOS). Soporta STARTTLS (puerto 587), SSL implícito
// (puerto 465) y AUTH LOGIN. Suficiente para enviar emails con un PDF adjunto.

function smtp_send_mail($cfg, $to, $toName, $subject, $html, $attachment = null, $cc = null, $replyTo = null, $fromName = null) {
    $host = $cfg['host'];
    $port = (int)($cfg['port'] ?: 587);
    $timeout = 15;

    $transport = ($port === 465) ? "ssl://$host" : $host;

    $errno = 0; $errstr = '';
    $socket = @fsockopen($transport, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        throw new Exception("No se pudo conectar a $host:$port ($errstr)");
    }
    stream_set_timeout($socket, $timeout);

    $readLine = function() use ($socket) {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            // La última línea de una respuesta multilinea tiene un espacio tras el código
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $send = function($cmd) use ($socket) { fwrite($socket, $cmd . "\r\n"); };
    $expect = function($codes) use ($readLine) {
        $resp = $readLine();
        $codes = is_array($codes) ? $codes : [$codes];
        $ok = false;
        foreach ($codes as $c) { if (substr($resp, 0, 3) === (string)$c) { $ok = true; break; } }
        if (!$ok) throw new Exception("Respuesta SMTP inesperada: " . trim($resp));
        return $resp;
    };

    $heloName = $_SERVER['SERVER_NAME'] ?? 'europeademaquinaria.com';

    $expect(220);
    $send("EHLO $heloName");
    $expect(250);

    if ($port === 587) {
        $send("STARTTLS");
        $expect(220);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception("No se pudo iniciar TLS (STARTTLS)");
        }
        $send("EHLO $heloName");
        $expect(250);
    }

    $send("AUTH LOGIN");
    $expect(334);
    $send(base64_encode($cfg['user']));
    $expect(334);
    $send(base64_encode($cfg['pass']));
    $expect(235);

    // $cc puede venir como string ("a@x.com,b@x.com"), array, o vacío/null.
    $ccList = [];
    if ($cc) {
        $ccRaw = is_array($cc) ? $cc : explode(',', $cc);
        foreach ($ccRaw as $addr) {
            $addr = trim($addr);
            if ($addr !== '') $ccList[] = $addr;
        }
    }

    $from = $cfg['from'] ?: $cfg['user'];
    $send("MAIL FROM:<$from>");
    $expect(250);
    $send("RCPT TO:<$to>");
    $expect([250, 251]);
    foreach ($ccList as $addr) {
        $send("RCPT TO:<$addr>");
        $expect([250, 251]);
    }
    $send("DATA");
    $expect(354);

    $boundary = "em_" . md5(uniqid('', true));
    $headers = [];
    // Nombre visible del remitente: por defecto "Europea de Maquinaria", pero al
    // reenviar una tarea se manda el nombre de quien la reenvía (ej. "Raúl Ibars -
    // Europea de Maquinaria"), aunque el correo siga saliendo técnicamente de la
    // cuenta $from (gestion@...). mb_encode_mimeheader por si lleva tildes/ñ.
    $fromDisplay = $fromName ?: 'Europea de Maquinaria';
    $headers[] = "From: " . mb_encode_mimeheader($fromDisplay, 'UTF-8', 'B') . " <$from>";
    $headers[] = "To: " . ($toName ? "$toName <$to>" : $to);
    // Permite que, aunque el correo se autentique y salga de $from (la cuenta
    // gestion@...), las respuestas del destinatario lleguen directamente al email
    // de la persona que lo envió (ej. el reenvío de una tarea a un proveedor).
    if ($replyTo) $headers[] = "Reply-To: $replyTo";
    if ($ccList) $headers[] = "Cc: " . implode(', ', $ccList);
    $headers[] = "Subject: " . mb_encode_mimeheader($subject, 'UTF-8', 'B');
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Date: " . date('r');

    $body = '';
    if ($attachment) {
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $html . "\r\n\r\n";
        $body .= "--$boundary\r\n";
        $mime = $attachment['mime'] ?: 'application/octet-stream';
        $name = $attachment['name'] ?: 'documento.pdf';
        $body .= "Content-Type: $mime; name=\"$name\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n";
        $body .= chunk_split($attachment['base64']) . "\r\n";
        $body .= "--$boundary--\r\n";
    } else {
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        $body .= $html . "\r\n";
    }

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    // Dot-stuffing: las líneas que empiecen por "." se escapan duplicando el punto
    $message = preg_replace('/\r\n\./', "\r\n..", $message);

    fwrite($socket, $message . "\r\n.\r\n");
    $expect(250);

    $send("QUIT");
    fclose($socket);
    return true;
}
