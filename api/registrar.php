<?php
// wizlink.bytopo.net · donde WizLink registra su propia llave, una sola vez (Iván, 19-nov: L4).
// POST https://wizlink.bytopo.net/api/registrar.php
//
// Lo que manda WizLink (app/nucleo/EnvioRespaldo.cs en ZaFi-Online/WizLink):
//   Authorization: Bearer wzr_…     la llave que WizLink creó en la máquina virtual (la guarda cifrada allá)
//   X-WizLink-Codigo: wzc_…         el código para WizLink de un solo uso que trajo su versión
//
// Si el código es el vigente y no se usó, la llave queda «esperando confirmación» (Iván, 19-nov: S1): no sirve para
// subir hasta que Iván la confirma en Llave de subida; entonces reemplaza a la anterior y el código se quema. Así, alguien
// que sacara el código del programa publicado no podría subir nada ni dejar afuera a la máquina virtual. Responde 200
// {"ok": true, "pendiente": true}; para WizLink queda registrada, y sus corridas quedan pendientes (409 en subir.php)
// hasta la confirmación. La misma llave otra vez (un reintento): 200 con "ya_estaba". Con 10 llaves esperando: 429.
// Cualquier otra cosa: 401. Responde JSON {"ok", "ya_estaba", "pendiente", "mensaje"}.
// Esta dirección no muestra nada: ni la llave ni el código salen nunca del sitio.

declare(strict_types=1);

require_once __DIR__ . '/../lib/base.php';
require_once __DIR__ . '/../lib/almacen.php';

encabezados_seguridad();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responder_json(405, ['ok' => false, 'mensaje' => 'Solo se registran llaves (POST).']);
}

$ahora = time();
$dir = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
$llave = llave_de_la_peticion();
$codigo = trim((string)($_SERVER['HTTP_X_WIZLINK_CODIGO'] ?? ''));

// Con el código correcto o una llave ya registrada siempre entra; el tope de intentos solo frena a los equivocados (así
// nadie puede bloquear el registro de WizLink mandando llaves o códigos falsos)
if (!codigo_coincide($codigo) && !llave_correcta($llave) && demasiados_fallos($ahora)) {
    header('Retry-After: 3600');
    responder_json(429, ['ok' => false, 'mensaje' => 'Demasiados intentos equivocados. Se recibe de nuevo dentro de una hora.']);
}

try {
    $r = con_candado(fn() => registrar_llave($codigo, $llave, $ahora, $dir));
} catch (Throwable $e) {
    registrar('registro de la llave de WizLink con error: ' . $e->getMessage());
    responder_json(500, ['ok' => false, 'mensaje' => 'El sitio no pudo registrar la llave. Se reintenta después.']);
}

if ($r === 'espera') {
    registrar('llave de WizLink recibida con el código para WizLink, desde ' . $dir . ': espera la confirmación');
    responder_json(200, ['ok' => true, 'ya_estaba' => false, 'pendiente' => true,
        'mensaje' => 'Llave de WizLink recibida. Espera la confirmación en wizlink.bytopo.net › Llave de subida.']);
}
if ($r === 'ya') {
    $espera = llave_esperando($llave) !== null;
    registrar('registro repetido de la llave de WizLink (ya estaba' . ($espera ? ', espera la confirmación' : '') . '), desde ' . $dir);
    responder_json(200, ['ok' => true, 'ya_estaba' => true, 'pendiente' => $espera,
        'mensaje' => $espera ? 'La llave de WizLink ya estaba recibida y espera la confirmación.' : 'La llave de WizLink ya estaba registrada.']);
}
if ($r === 'lleno') {
    registrar('registro de llave sin lugar (ya hay ' . ESPERANDO_MAXIMO . ' esperando) desde ' . $dir);
    header('Retry-After: 3600');
    responder_json(429, ['ok' => false, 'mensaje' => 'Ya hay ' . ESPERANDO_MAXIMO . ' llaves esperando confirmación en wizlink.bytopo.net. Se intenta otra vez después.']);
}
anotar_fallo($ahora);
registrar('registro de llave rechazado desde ' . $dir);
responder_json(401, ['ok' => false, 'mensaje' => 'El código para WizLink no sirve: ya se usó, venció o fue reemplazado. Cree otro en wizlink.bytopo.net › Llave de subida.']);
