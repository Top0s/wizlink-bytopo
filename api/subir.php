<?php
// wizlink.bytopo.net · donde WizLink sube cada corrida (POST https://wizlink.bytopo.net/api/subir.php).
//
// Lo que manda WizLink (app/nucleo/EnvioRespaldo.cs en ZaFi-Online/WizLink):
//   Authorization: Bearer wzr_…            la llave de subida (se crea en «Llave de subida» de este sitio)
//   Content-Type: application/zip          el cuerpo es el .zip de la corrida, tal cual
//   X-WizLink-Corrida: 2026-11-19 14.00    el nombre de la carpeta de la corrida en la PC
//   X-WizLink-Sha256: <64 hex>             la huella del .zip, para saber que llegó entero
//   X-WizLink-Version: Beta 3.1 (1.0.10)   la versión de WizLink (solo para mostrarla)
//
// Lo que responde (JSON): {"ok": true, "ya_estaba": false, "mensaje": "…"} con 200; 400 si el .zip o los datos no
// sirven (no se reintenta), 401 si la llave no sirve, 405 si no es POST, 413 si pesa demasiado, 422 si llegó cortada,
// 429 si hubo muchos intentos con una llave equivocada en la última hora (una llave correcta entra siempre), y 500 si
// algo falló aquí (WizLink lo reintenta después).
//
// Esta dirección no muestra nada: con la llave no se puede ver, listar ni bajar ninguna corrida.

declare(strict_types=1);

require_once __DIR__ . '/../lib/base.php';
require_once __DIR__ . '/../lib/almacen.php';

encabezados_seguridad();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responder_json(405, ['ok' => false, 'mensaje' => 'Solo se reciben corridas (POST).']);
}

$ahora = time();

// La llave primero: una llave correcta siempre entra. El tope de intentos solo frena a las equivocadas (con 256 bits,
// adivinarla es imposible; el tope evita que alguien llene la bitácora), y así nadie puede bloquear las subidas buenas.
$llave = llave_de_la_peticion();
if (!llave_correcta($llave)) {
    if (demasiados_fallos($ahora)) {
        header('Retry-After: 3600');
        responder_json(429, ['ok' => false, 'mensaje' => 'Demasiados intentos con una llave equivocada. Se recibe de nuevo dentro de una hora.']);
    }
    anotar_fallo($ahora);
    registrar('subida rechazada: llave ' . ($llave === '' ? 'ausente' : 'equivocada') . ' desde ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    responder_json(401, ['ok' => false, 'mensaje' => 'La llave de subida no sirve o fue reemplazada. Cree una nueva en wizlink.bytopo.net › Llave de subida.']);
}

$corrida = trim((string)($_SERVER['HTTP_X_WIZLINK_CORRIDA'] ?? ''));
$sha = strtolower(trim((string)($_SERVER['HTTP_X_WIZLINK_SHA256'] ?? '')));
$version = trim((string)($_SERVER['HTTP_X_WIZLINK_VERSION'] ?? ''));
$version = mb_substr(preg_replace('/[^\p{L}\p{N} .,()_\-]/u', '', $version) ?? '', 0, 60);
$tipo = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
$largo = (int)($_SERVER['CONTENT_LENGTH'] ?? -1);

if (!corrida_valida($corrida)) responder_json(400, ['ok' => false, 'mensaje' => 'Falta el nombre de la corrida o no tiene la forma AAAA-MM-DD HH.MM.']);
if (!preg_match('/^[0-9a-f]{64}$/', $sha)) responder_json(400, ['ok' => false, 'mensaje' => 'Falta la huella SHA-256 del .zip.']);
if ($tipo !== 'application/zip') responder_json(400, ['ok' => false, 'mensaje' => 'Solo se recibe un .zip (application/zip).']);
if ($largo > MAXIMO_BYTES) responder_json(413, ['ok' => false, 'mensaje' => 'La corrida pesa más de ' . peso(MAXIMO_BYTES) . ' comprimida.']);
if ($largo <= 0) responder_json(400, ['ok' => false, 'mensaje' => 'La corrida llegó vacía.']);

$clave = clave_de($corrida);
$destino = ruta_zip($clave);
$tmp = carpeta('corridas/recibiendo') . '/' . $clave . '.' . bin2hex(random_bytes(6)) . '.part';

// Una sola subida de la misma corrida a la vez
$candado = fopen(carpeta('estado') . '/subida.lock', 'c');
if ($candado === false || !flock($candado, LOCK_EX)) responder_json(500, ['ok' => false, 'mensaje' => 'El sitio está ocupado. Se reintenta después.']);

try {
    // Se copia lo que llega a un archivo temporal, con tope, calculando la huella en el camino
    $entrada = fopen('php://input', 'rb');
    $salida = fopen($tmp, 'xb');
    if ($entrada === false || $salida === false) throw new RuntimeException('no se pudo abrir el archivo temporal');
    $ctx = hash_init('sha256');
    $total = 0;
    while (!feof($entrada)) {
        $bloque = fread($entrada, 1024 * 1024);
        if ($bloque === false) throw new RuntimeException('se cortó la lectura');
        $total += strlen($bloque);
        if ($total > MAXIMO_BYTES) {
            fclose($salida); @unlink($tmp);
            responder_json(413, ['ok' => false, 'mensaje' => 'La corrida pesa más de ' . peso(MAXIMO_BYTES) . ' comprimida.']);
        }
        hash_update($ctx, $bloque);
        if (fwrite($salida, $bloque) !== strlen($bloque)) throw new RuntimeException('no hay espacio para guardar la corrida');
    }
    fclose($entrada);
    fclose($salida);
    @chmod($tmp, 0600);

    if ($total !== $largo || !hash_equals($sha, hash_final($ctx))) {
        @unlink($tmp);
        // Llegó cortada o distinta: no es un rechazo definitivo, WizLink la vuelve a mandar
        responder_json(422, ['ok' => false, 'mensaje' => 'La corrida llegó incompleta (la huella no coincide). Se reintenta después.']);
    }

    $problema = revisar_zip($tmp, $corrida);
    if ($problema !== null) {
        @unlink($tmp);
        registrar('subida rechazada: ' . $corrida . ': ' . $problema);
        responder_json(400, ['ok' => false, 'mensaje' => 'La corrida no se guardó: ' . $problema . '.']);
    }

    // La misma corrida otra vez (un reenvío): si es idéntica, no se toca
    $datosAntes = leer_json(ruta_datos($clave));
    if (is_file($destino) && is_array($datosAntes) && hash_equals((string)($datosAntes['sha256'] ?? ''), $sha)) {
        @unlink($tmp);
        registrar('subida repetida: ' . $corrida . ' (ya estaba igual)');
        responder_json(200, ['ok' => true, 'ya_estaba' => true, 'mensaje' => 'Esta corrida ya estaba en wizlink.bytopo.net.']);
    }

    $datos = datos_de_zip($tmp, $corrida, $version, $total, $sha);
    if (!@rename($tmp, $destino)) throw new RuntimeException('no se pudo guardar la corrida');
    escribir_json(ruta_datos($clave), $datos);
    $borradas = limpiar_viejas($ahora);
    registrar('subida: ' . $corrida . ' · ' . peso($total) . ' · ' . $datos['archivos'] . ' archivos · ' . $datos['resultado']
        . ($borradas > 0 ? ' · limpieza: ' . $borradas . ' corridas de más de ' . dias_guardar() . ' días' : ''));
    responder_json(200, ['ok' => true, 'ya_estaba' => false, 'mensaje' => 'Corrida guardada en wizlink.bytopo.net.']);
} catch (Throwable $e) {
    @unlink($tmp);
    registrar('subida con error: ' . $corrida . ': ' . $e->getMessage());
    responder_json(500, ['ok' => false, 'mensaje' => 'El sitio no pudo guardar la corrida. Se reintenta después.']);
} finally {
    if (is_resource($candado)) { flock($candado, LOCK_UN); fclose($candado); }
}
