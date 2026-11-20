<?php
// Prueba de punta a punta contra la copia de prueba local de wizlink.bytopo.net (solo en el sandbox).
// Uso: php e2e.php <zip de una corrida> <nombre de la corrida> <carpeta wizlink-private de la copia>
declare(strict_types=1);

$zip = $argv[1]; $corrida = $argv[2]; $privado = rtrim($argv[3], '/');
$base = 'https://wizlink.bytopo.net';
$tarro = tempnam(sys_get_temp_dir(), 'tarro');
$fallas = 0; $total = 0;

function ok(bool $c, string $d): void { global $fallas, $total; $total++; if (!$c) { $fallas++; echo "FALLA: $d\n"; } }

/** Una petición lista para mandar, siempre a esta computadora (127.0.0.1) y nunca por un proxy. */
function peticion(string $metodo, string $ruta, array $op = [])
{
    global $base, $tarro;
    $ch = curl_init($base . $ruta);
    $cab = $op['cabeceras'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_RESOLVE => ['wizlink.bytopo.net:443:127.0.0.1', 'wizlink.bytopo.net:80:127.0.0.1'],
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => $cab,
    ]);
    $t = $op['tarro'] ?? $tarro;   // otro tarro de cookies: otra sesión
    if (!($op['sin_cookies'] ?? false)) { curl_setopt($ch, CURLOPT_COOKIEJAR, $t); curl_setopt($ch, CURLOPT_COOKIEFILE, $t); }
    if (isset($op['desde'])) curl_setopt($ch, CURLOPT_INTERFACE, $op['desde']);   // otra dirección de esta misma computadora (127.0.0.x)
    if (isset($op['cuerpo'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $op['cuerpo']);
    return $ch;
}

function respuesta($ch, ?string $r): array
{
    $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $tc = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['codigo' => $codigo, 'cab' => substr((string)$r, 0, $tc), 'cuerpo' => substr((string)$r, $tc)];
}

function pedir(string $metodo, string $ruta, array $op = []): array
{
    $ch = peticion($metodo, $ruta, $op);
    $r = curl_exec($ch);
    $x = respuesta($ch, is_string($r) ? $r : null);
    curl_close($ch);
    return $x;
}

/** Varias peticiones a la vez (como alguien que intenta en paralelo). $lista: [[metodo, ruta, op], ...] */
function pedir_a_la_vez(array $lista): array
{
    $mh = curl_multi_init();
    $hs = [];
    foreach ($lista as $i => [$metodo, $ruta, $op]) { $hs[$i] = peticion($metodo, $ruta, $op); curl_multi_add_handle($mh, $hs[$i]); }
    do { $st = curl_multi_exec($mh, $activas); if ($activas) curl_multi_select($mh, 1.0); } while ($activas && $st === CURLM_OK);
    $res = [];
    foreach ($hs as $i => $ch) { $res[$i] = respuesta($ch, curl_multi_getcontent($ch)); curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    return $res;
}

/** El nombre con que el sitio guarda una corrida: «2026-11-19 14.00 (2)» → «2026-11-19_14.00_2». */
function clave_e2e(string $corrida): string { return str_replace(' ', '_', (string)preg_replace('/ \((\d+)\)$/', '_$1', $corrida)); }

/** Un .zip inventado con la forma de una corrida de WizLink. */
function zip_de_prueba(string $corrida, string $nota): string
{
    // La ruta que da tempnam(), sin agregarle «.zip»: tempnam() ya crea ese archivo, y así se borra el mismo (Codex, PR #7)
    $f = tempnam(sys_get_temp_dir(), 'z');
    try {
        $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
        $z->addFromString($corrida . '/evidencia/resumen.txt', "WIZLINK · Prueba 6 · RESUMEN DE LA CORRIDA\nDuración: 1 min 2 s\nResultado: TERMINÓ ($nota)\n");
        $z->addFromString('bitacoras_wizlink/corrida_automatica.txt', "inventada: $nota\n");
        $z->close();
        return (string)file_get_contents($f);
    } finally {
        @unlink($f);
    }
}

function h_e2e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function atributo(string $html, string $id, string $attr): string
{
    return preg_match('/id="' . $id . '"[^>]*' . $attr . '="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : '';
}

// TOTP igual que la app
function b32d(string $t): string { $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; foreach (str_split(strtoupper(preg_replace('/\s/', '', $t))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT); $r = ''; foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $r .= chr(bindec($b)); return $r; }
function codigo(string $sec, int $paso): string { $h = hash_hmac('sha1', pack('N*', 0, $paso), $sec, true); $o = ord($h[19]) & 15; $n = ((ord($h[$o]) & 127) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]); return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT); }

// ------------------------------------------------------------------ sin entrar
foreach (['/', '/corrida.php?c=2026-11-19_00.59', '/archivo.php?c=2026-11-19_00.59&f=x', '/bajar.php?c=2026-11-19_00.59', '/llave.php'] as $r) {
    $x = pedir('GET', $r, ['sin_cookies' => true]);
    ok($x['codigo'] === 302 && str_contains($x['cab'], 'Location: /entrar.php'), "sin entrar, $r manda a la entrada ({$x['codigo']})");
}
foreach (['/.git/config', '/.git/HEAD', '/.git', '/.git%2Fconfig', '/%2egit/config', '//.git/config', '/x/../.git/config', '/lib/base.php', '/lib/horario.php', '/lib/', '/README.md', '/.htaccess', '/assets/', '/api/'] as $r) {
    $x = pedir('GET', $r, ['sin_cookies' => true]);
    ok(in_array($x['codigo'], [403, 404], true), "oculto: $r ({$x['codigo']})");
    ok(!str_contains($x['cuerpo'], 'repositoryformatversion') && !str_contains($x['cuerpo'], '<?php'), "oculto: $r no muestra contenido");
}
$x = pedir('GET', '/entrar.php');
foreach (['X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Content-Security-Policy:', 'X-Robots-Tag: noindex', 'Referrer-Policy:', 'Permissions-Policy:', 'Cache-Control: no-store'] as $h)
    ok(stripos($x['cab'], $h) !== false, "encabezado $h");
ok(preg_match('/Set-Cookie: wizlink_sesion=[^\r\n]*; secure; HttpOnly; SameSite=Lax/i', $x['cab']) === 1, 'la cookie de sesión es Secure, HttpOnly y SameSite');
$assets = pedir('GET', '/assets/estilo.css', ['sin_cookies' => true]);
ok($assets['codigo'] === 200 && stripos($assets['cab'], 'X-Content-Type-Options: nosniff') !== false, 'los archivos sueltos también llevan encabezados');

// ------------------------------------------------------------------ paso 1: crear el acceso
function ficha_de(string $html): string { return preg_match('/name="ficha" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : ''; }
$origen = ['Origin: https://wizlink.bytopo.net'];
$p = pedir('GET', '/entrar.php');
ok(str_contains($p['cuerpo'], 'Crear el acceso'), 'la primera vez pide crear el acceso');
$codigoAlta = trim((string)@file_get_contents($privado . '/estado/codigo_alta.txt'));
ok(preg_match('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){3}$/', $codigoAlta) === 1, 'el código de alta queda en lo privado');
ok(!str_contains($p['cuerpo'], $codigoAlta), 'la página no muestra el código de alta');
$f = ficha_de($p['cuerpo']);
$alta = function (array $campos) use (&$f, $origen) {
    return pedir('POST', '/entrar.php?paso=alta', ['cuerpo' => http_build_query(array_merge(['ficha' => $f], $campos)), 'cabeceras' => $origen]);
};
$contrasena = 'clave-de-prueba-9999';
$r = $alta(['codigo_alta' => 'AAAA-AAAA-AAAA-AAAA', 'usuario' => 'prueba01', 'clave' => $contrasena, 'repetir' => $contrasena]);
ok(str_contains($r['cuerpo'], 'código de alta no es correcto'), 'alta: un código de alta equivocado no sirve');
$r = $alta(['codigo_alta' => $codigoAlta, 'usuario' => 'prueba01', 'clave' => 'corta', 'repetir' => 'corta']);
ok(str_contains($r['cuerpo'], 'al menos 12'), 'alta: una contraseña corta no sirve');
$r = $alta(['codigo_alta' => $codigoAlta, 'usuario' => 'prueba01', 'clave' => $contrasena, 'repetir' => $contrasena . 'x']);
ok(str_contains($r['cuerpo'], 'no son iguales'), 'alta: las dos contraseñas tienen que ser iguales');
$r = $alta(['codigo_alta' => $codigoAlta, 'usuario' => 'con espacio', 'clave' => $contrasena, 'repetir' => $contrasena]);
ok(str_contains($r['cuerpo'], 'sin espacios'), 'alta: un usuario con espacios no sirve');
$r = pedir('POST', '/entrar.php?paso=alta', ['cuerpo' => http_build_query(['ficha' => str_repeat('0', 64), 'codigo_alta' => $codigoAlta, 'usuario' => 'prueba01', 'clave' => $contrasena, 'repetir' => $contrasena]), 'cabeceras' => $origen]);
ok(str_contains($r['cuerpo'], 'venció'), 'alta: sin la ficha correcta no se crea');
$r = pedir('POST', '/entrar.php?paso=alta', ['cuerpo' => http_build_query(['ficha' => $f, 'codigo_alta' => $codigoAlta, 'usuario' => 'prueba01', 'clave' => $contrasena, 'repetir' => $contrasena]), 'cabeceras' => ['Origin: https://otro.sitio.invalid']]);
ok(str_contains($r['cuerpo'], 'venció'), 'alta: desde otro sitio (Origin) no se crea');
$r = $alta(['codigo_alta' => strtolower($codigoAlta), 'usuario' => 'prueba01', 'clave' => $contrasena, 'repetir' => $contrasena]);
ok($r['codigo'] === 302, "alta: con el código correcto se crea el acceso ({$r['codigo']})");
$acceso = (string)@file_get_contents($privado . '/estado/acceso.json');
ok($acceso !== '' && !str_contains($acceso, $contrasena) && str_contains($acceso, '$2y$'), 'la contraseña no se guarda: solo su huella (bcrypt)');
ok(!is_file($privado . '/estado/codigo_alta.txt'), 'el código de alta se borra al usarse');
// Con solo el paso 1 todavía no se ve nada
ok(pedir('GET', '/')['codigo'] === 302, 'con solo la contraseña, la lista sigue cerrada');

// ------------------------------------------------------------------ paso 2: alta de la app
$alta = pedir('GET', '/entrar.php');
ok(str_contains($alta['cuerpo'], 'Configurar la app de autenticación'), 'la primera vez pide dar de alta la app');
$otp = atributo($alta['cuerpo'], 'qr', 'data-texto');
ok(str_starts_with($otp, 'otpauth://totp/'), 'el QR trae una dirección otpauth');
parse_str((string)parse_url($otp, PHP_URL_QUERY), $q);
$secreto = b32d($q['secret'] ?? '');
ok(strlen($secreto) === 20, 'secreto de 20 bytes');
$fichaForm = ficha_de($alta['cuerpo']);
$paso = intdiv(time(), 30);
$mal = codigo($secreto, $paso) === '000000' ? '111111' : '000000';
$r = pedir('POST', '/entrar.php?paso=codigo', ['cuerpo' => http_build_query(['ficha' => $fichaForm, 'codigo' => $mal]), 'cabeceras' => ['Origin: https://wizlink.bytopo.net']]);
ok(str_contains($r['cuerpo'], 'no es correcto'), 'un código equivocado no entra');
$r = pedir('POST', '/entrar.php?paso=codigo', ['cuerpo' => http_build_query(['ficha' => $fichaForm, 'codigo' => codigo($secreto, $paso)]), 'cabeceras' => ['Origin: https://wizlink.bytopo.net']]);
ok($r['codigo'] === 302 && str_contains($r['cab'], 'Location: /'), "el código correcto entra ({$r['codigo']})");
$l = pedir('GET', '/');
ok($l['codigo'] === 200 && str_contains($l['cuerpo'], 'Corridas recibidas'), 'la lista se abre');
// El horario de las corridas (Iván, 19-nov: A1, B2, C1): un panel, una sola «Próxima», las 12 corridas de un día normal
// (11 el día 1) y el otro horario a un clic, con los nombres cortos
ok(substr_count($l['cuerpo'], 'id="horario"') === 1 && substr_count($l['cuerpo'], 'class="horario-proxima"') === 1, 'horario: el panel con una sola próxima corrida');
ok(substr_count($l['cuerpo'], '<tr') === 2 + 12 + 11 && str_contains($l['cuerpo'], 'Ext. 365') && str_contains($l['cuerpo'], 'Ext. 120') && str_contains($l['cuerpo'], 'Normal</span>'), 'horario: las dos tablas, día normal y día 1');
ok(str_contains($l['cuerpo'], 'Próxima corrida:') && str_contains($l['cuerpo'], 'href="#horario"'), 'horario: la línea de la próxima corrida para el teléfono');
ok(str_contains($l['cuerpo'], 'Todavía no hay llave de subida'), 'avisa que falta la llave');

// ------------------------------------------------------------------ la llave de subida
$pl = pedir('GET', '/llave.php');
$fichaIn = preg_match('/name="ficha" value="([0-9a-f]{64})"/', $pl['cuerpo'], $m) ? $m[1] : '';
ok(pedir('POST', '/llave.php', ['cuerpo' => http_build_query(['ficha' => 'mala', 'que' => 'llave'])])['codigo'] === 200 && llave_de(pedir('POST', '/llave.php', ['cuerpo' => http_build_query(['ficha' => 'mala', 'que' => 'llave'])])['cuerpo']) === '', 'sin la ficha no se crea llave');
$r = pedir('POST', '/llave.php', ['cuerpo' => http_build_query(['ficha' => $fichaIn, 'que' => 'llave']), 'cabeceras' => ['Origin: https://wizlink.bytopo.net']]);
$llave = llave_de($r['cuerpo']);
ok(preg_match('/^wzr_[A-Za-z0-9_-]{43}$/', $llave) === 1, 'se crea la llave wzr_…');
ok(!str_contains((string)@file_get_contents($privado . '/estado/llave.json'), $llave), 'el sitio no guarda la llave, solo su huella');
$r = pedir('POST', '/llave.php', ['cuerpo' => http_build_query(['ficha' => $fichaIn, 'que' => 'llave']), 'cabeceras' => ['Origin: https://wizlink.bytopo.net']]);
ok(llave_de($r['cuerpo']) === '' && str_contains($r['cuerpo'], 'marque la casilla'), 'reemplazarla pide confirmar');

function llave_de(string $html): string { return preg_match('/id="llave" class="llave" value="(wzr_[^"]+)"/', $html, $m) ? $m[1] : ''; }

// ------------------------------------------------------------------ subir
$datos = file_get_contents($zip);
$sha = hash('sha256', $datos);
$subir = function (array $cab, string $cuerpo) {
    return pedir('POST', '/api/subir.php', ['sin_cookies' => true, 'cabeceras' => $cab, 'cuerpo' => $cuerpo]);
};
$cabBuena = ['Authorization: Bearer ' . $llave, 'Content-Type: application/zip', 'X-WizLink-Corrida: ' . $corrida, 'X-WizLink-Sha256: ' . $sha, 'X-WizLink-Version: Prueba 6'];
ok(pedir('GET', '/api/subir.php', ['sin_cookies' => true])['codigo'] === 405, 'subir: GET no');
ok($subir(array_merge(['Authorization: Bearer wzr_' . str_repeat('A', 43)], array_slice($cabBuena, 1)), $datos)['codigo'] === 401, 'subir: llave equivocada');
ok($subir(array_slice($cabBuena, 1), $datos)['codigo'] === 401, 'subir: sin llave');
ok($subir(array_merge(array_slice($cabBuena, 0, 2), ['X-WizLink-Corrida: ../../etc', 'X-WizLink-Sha256: ' . $sha]), $datos)['codigo'] === 400, 'subir: nombre de corrida con trampa');
ok($subir(array_merge(array_slice($cabBuena, 0, 3), ['X-WizLink-Sha256: ' . str_repeat('a', 64)]), $datos)['codigo'] === 422, 'subir: huella que no coincide');
ok($subir(array_merge([$cabBuena[0], 'Content-Type: text/plain'], array_slice($cabBuena, 2)), $datos)['codigo'] === 400, 'subir: no es un .zip');
ok($subir(array_merge(array_slice($cabBuena, 0, 3), ['X-WizLink-Sha256: ' . hash('sha256', 'hola')]), 'hola')['codigo'] === 400, 'subir: cuerpo que no es zip');
ok($subir(array_merge(array_slice($cabBuena, 0, 2), ['X-WizLink-Corrida: 2026-11-18 10.00', 'X-WizLink-Sha256: ' . $sha]), $datos)['codigo'] === 400, 'subir: un .zip que no es de esa corrida');
// Un .zip con un nombre con trampa adentro
$trampa = tempnam(sys_get_temp_dir(), 'z') . '.zip';
$z = new ZipArchive(); $z->open($trampa, ZipArchive::CREATE); $z->addFromString($corrida . '/evidencia/resumen.txt', 'x'); $z->addFromString('../../fuera.txt', 'x'); $z->close();
$dt = file_get_contents($trampa);
ok($subir(array_merge(array_slice($cabBuena, 0, 3), ['X-WizLink-Sha256: ' . hash('sha256', $dt)]), $dt)['codigo'] === 400, 'subir: .zip con ../ adentro');
$r = $subir($cabBuena, $datos);
ok($r['codigo'] === 200 && str_contains($r['cuerpo'], '"ok":true') && str_contains($r['cuerpo'], '"ya_estaba":false'), "subir: la corrida se guarda ({$r['codigo']} {$r['cuerpo']})");
$r = $subir($cabBuena, $datos);
ok($r['codigo'] === 200 && str_contains($r['cuerpo'], '"ya_estaba":true'), 'subir: la misma otra vez queda como ya estaba');
// La misma corrida con otra huella (un reintento de WizLink arma el .zip de nuevo, con sus bitácoras al día): nunca
// reemplaza a la guardada
$fb = tempnam(sys_get_temp_dir(), 'z') . '.zip'; file_put_contents($fb, $datos);
$z = new ZipArchive(); $z->open($fb); $z->addFromString('bitacoras_wizlink/reintento.txt', 'otra versión, inventada'); $z->close();
$datosB = (string)file_get_contents($fb); @unlink($fb);
$guardada = $privado . '/corridas/' . substr(clave_e2e($corrida), 0, 7) . '/' . clave_e2e($corrida);
$r = $subir(array_merge(array_slice($cabBuena, 0, 3), ['X-WizLink-Sha256: ' . hash('sha256', $datosB)]), $datosB);
ok($r['codigo'] === 200 && str_contains($r['cuerpo'], '"ya_estaba":true'), "subir: la misma corrida con otra huella queda como ya estaba ({$r['codigo']} {$r['cuerpo']})");
ok(hash_file('sha256', $guardada . '.zip') === $sha && (json_decode((string)@file_get_contents($guardada . '.json'), true)['sha256'] ?? '') === $sha,
   'subir: la corrida guardada no se reemplaza (ni el .zip ni sus datos)');
// Dos corridas que empezaron en el mismo minuto: WizLink nombra la segunda «… (2)» (Salida.cs)
$corrida2 = $corrida . ' (2)';
$z2 = zip_de_prueba($corrida2, 'la segunda del mismo minuto');
$r = $subir(array_merge(array_slice($cabBuena, 0, 2), ['X-WizLink-Corrida: ' . $corrida2, 'X-WizLink-Sha256: ' . hash('sha256', $z2)]), $z2);
ok($r['codigo'] === 200 && str_contains($r['cuerpo'], '"ya_estaba":false'), "subir: la corrida «(2)» se guarda ({$r['codigo']})");
$clave2 = clave_e2e($corrida2);
ok(is_file($privado . '/corridas/' . substr($clave2, 0, 7) . '/' . $clave2 . '.zip'), "subir: la «(2)» queda como $clave2.zip");
// Muchas llaves equivocadas no bloquean a la correcta
for ($i = 0; $i < 31; $i++) $subir(array_merge(['Authorization: Bearer wzr_' . str_repeat('C', 43)], array_slice($cabBuena, 1)), 'x');
ok($subir(array_merge(['Authorization: Bearer wzr_' . str_repeat('C', 43)], array_slice($cabBuena, 1)), 'x')['codigo'] === 429, 'subir: después de 30 llaves equivocadas en una hora, 429');
$r = $subir($cabBuena, $datos);
ok($r['codigo'] === 200, "subir: la llave correcta sigue entrando ({$r['codigo']})");
ok(count(glob(dirname($privado) . '/public_html/**/*.zip') ?: []) === 0 && count(glob(dirname($privado) . '/public_html/*.zip') ?: []) === 0, 'nada queda dentro de public_html');

// Una «(3)» guardada con el nombre que usaban las versiones anteriores («…_(3)», que no se veía): se renombra sola con
// la siguiente subida
$claveP = clave_e2e($corrida);
$antes3 = $privado . '/corridas/' . substr($claveP, 0, 7) . '/' . $claveP . '_(3)';
file_put_contents($antes3 . '.zip', zip_de_prueba($corrida . ' (3)', 'guardada con el nombre de antes'));
file_put_contents($antes3 . '.json', json_encode(['corrida' => $corrida . ' (3)', 'recibida' => time(), 'bytes' => 1, 'sha256' => str_repeat('0', 64), 'version' => 'Prueba 6', 'resultado' => 'termino', 'detalle' => '', 'duracion' => '', 'consultas' => 0, 'revisar' => 0, 'archivos' => 2]));
@chown($antes3 . '.zip', 'www-data'); @chown($antes3 . '.json', 'www-data');

// Una corrida vieja (de hace 40 días) se borra con la siguiente subida
$vieja = date('Y-m-d H.i', time() - 40 * 86400);
$zv = tempnam(sys_get_temp_dir(), 'z') . '.zip';
$z = new ZipArchive(); $z->open($zv, ZipArchive::CREATE); $z->addFromString($vieja . '/evidencia/resumen.txt', "WIZLINK · VIEJA · RESUMEN DE LA CORRIDA\nResultado: TERMINÓ\n"); $z->close();
$dv = file_get_contents($zv);
ok($subir(array_merge(array_slice($cabBuena, 0, 2), ['X-WizLink-Corrida: ' . $vieja, 'X-WizLink-Sha256: ' . hash('sha256', $dv)]), $dv)['codigo'] === 200, 'subir: una corrida vieja se recibe');
$clVieja = str_replace(' ', '_', $vieja);
ok(!is_file($privado . '/corridas/' . substr($clVieja, 0, 7) . '/' . $clVieja . '.zip'), 'la limpieza borra la de más de 30 días');
ok(!is_file($antes3 . '.zip') && is_file($privado . '/corridas/' . substr($claveP, 0, 7) . '/' . $claveP . '_3.zip') && is_file($privado . '/corridas/' . substr($claveP, 0, 7) . '/' . $claveP . '_3.json'),
   'la limpieza renombra una «(3)» guardada con el nombre de antes, con sus datos');
$vieja2 = $vieja . ' (2)';
$dv2 = zip_de_prueba($vieja2, 'vieja del mismo minuto');
ok($subir(array_merge(array_slice($cabBuena, 0, 2), ['X-WizLink-Corrida: ' . $vieja2, 'X-WizLink-Sha256: ' . hash('sha256', $dv2)]), $dv2)['codigo'] === 200, 'subir: una «(2)» vieja se recibe');
ok(count(glob($privado . '/corridas/' . substr($clVieja, 0, 7) . '/' . $clVieja . '*') ?: []) === 0, 'la limpieza también borra la «(2)» de más de 30 días');

// Si algo falla al guardar, nunca queda una corrida a medias, y el reintento de WizLink la guarda entera
$cf = substr($corrida, 0, 10) . ' 00.01'; $clf = clave_e2e($cf); $dirF = $privado . '/corridas/' . substr($clf, 0, 7);
$zf = zip_de_prueba($cf, 'falla al guardar');
$cabF = array_merge(array_slice($cabBuena, 0, 2), ['X-WizLink-Corrida: ' . $cf, 'X-WizLink-Sha256: ' . hash('sha256', $zf)]);
@mkdir("$dirF/$clf.json");   // los datos no se pueden escribir
$r = $subir($cabF, $zf);
ok($r['codigo'] === 500 && !is_file("$dirF/$clf.zip"), "falla al guardar los datos: 500 y no queda un .zip sin datos ({$r['codigo']})");
@rmdir("$dirF/$clf.json");
@mkdir("$dirF/$clf.zip");    // ahora el .zip no se puede poner en su lugar
$r = $subir($cabF, $zf);
ok($r['codigo'] === 500 && !is_file("$dirF/$clf.json"), "falla al guardar el .zip: 500 y no quedan datos sueltos ({$r['codigo']})");
@rmdir("$dirF/$clf.zip");
$r = $subir($cabF, $zf);
ok($r['codigo'] === 200 && is_file("$dirF/$clf.zip") && is_file("$dirF/$clf.json"), "falla al guardar: el reintento la guarda entera ({$r['codigo']})");
// Un .zip guardado sin sus datos (lo podía dejar una versión anterior): el siguiente envío rehace los datos a partir del
// .zip guardado, sin reemplazarlo
unlink("$dirF/$clf.json");
$zf2 = zip_de_prueba($cf, 'reintento con otra huella');
$r = $subir(array_merge(array_slice($cabBuena, 0, 2), ['X-WizLink-Corrida: ' . $cf, 'X-WizLink-Sha256: ' . hash('sha256', $zf2)]), $zf2);
$jf = json_decode((string)@file_get_contents("$dirF/$clf.json"), true);
ok($r['codigo'] === 200 && str_contains($r['cuerpo'], '"ya_estaba":true') && ($jf['sha256'] ?? '') === hash('sha256', $zf) && hash_file('sha256', "$dirF/$clf.zip") === hash('sha256', $zf),
   "un .zip sin sus datos: se rehacen a partir del guardado, sin reemplazarlo ({$r['codigo']})");

// ------------------------------------------------------------------ el código para WizLink (L4)
$pl = pedir('GET', '/llave.php');
$r = pedir('POST', '/llave.php', ['cuerpo' => http_build_query(['ficha' => ficha_de($pl['cuerpo']), 'que' => 'codigo']), 'cabeceras' => $origen]);
$codigo = preg_match('/id="codigo-nuevo" class="llave" value="(wzc_[^"]+)"/', $r['cuerpo'], $m) ? $m[1] : '';
ok(preg_match('/^wzc_[A-Za-z0-9_-]{43}$/', $codigo) === 1, 'código para WizLink: se crea y se muestra una vez');
ok(!str_contains((string)@file_get_contents($privado . '/estado/codigo_wizlink.json'), $codigo), 'código para WizLink: el sitio guarda solo su huella');
$r = pedir('POST', '/llave.php', ['cuerpo' => http_build_query(['ficha' => ficha_de($pl['cuerpo']), 'que' => 'codigo']), 'cabeceras' => $origen]);
ok(str_contains($r['cuerpo'], 'Ya hay un código para WizLink sin usar'), 'código para WizLink: reemplazar uno sin usar pide confirmar');
$propia = 'wzr_' . str_repeat('P', 40) . '-_1';
$otra = 'wzr_' . str_repeat('Q', 40) . '-_2';
$registrar = function (string $llaveR, string $codigoR, string $desde = '127.0.0.1') {
    return pedir('POST', '/api/registrar.php', ['sin_cookies' => true, 'desde' => $desde, 'cabeceras' => ['Authorization: Bearer ' . $llaveR, 'X-WizLink-Codigo: ' . $codigoR], 'cuerpo' => '']);
};
$esperando = fn() => (array)(json_decode((string)@file_get_contents($privado . '/estado/llaves_esperando.json'), true)['llaves'] ?? []);
$fallosAnotados = fn() => count((array)(json_decode((string)@file_get_contents($privado . '/estado/subidas_rechazadas.json'), true)['fallos'] ?? []));
ok(pedir('GET', '/api/registrar.php', ['sin_cookies' => true])['codigo'] === 405, 'registrar: GET no');
ok(in_array($registrar($propia, 'wzc_' . str_repeat('Z', 43))['codigo'], [401, 429], true), 'registrar: un código equivocado no sirve');
ok(in_array($registrar($propia, '')['codigo'], [401, 429], true), 'registrar: sin código no sirve');
ok($registrar('corta', $codigo)['codigo'] === 401, 'registrar: una llave con otra forma no sirve');

// S1: la llave registrada espera la confirmación de Iván
$r = $registrar($propia, $codigo);
ok($r['codigo'] === 200 && str_contains($r['cuerpo'], '"ya_estaba":false') && str_contains($r['cuerpo'], '"pendiente":true'),
   "registrar: la llave de WizLink queda esperando confirmación ({$r['codigo']} {$r['cuerpo']})");
$r = $registrar($propia, $codigo);
ok($r['codigo'] === 200 && str_contains($r['cuerpo'], '"ya_estaba":true') && str_contains($r['cuerpo'], '"pendiente":true'), 'registrar: el mismo reintento ya estaba, y sigue esperando');
$cabPropia = array_merge(['Authorization: Bearer ' . $propia], array_slice($cabBuena, 1));
$antes = $fallosAnotados();
$r = $subir($cabPropia, $datos);
ok($r['codigo'] === 409 && str_contains($r['cuerpo'], '"pendiente":true'), "subir: una llave que espera no sube nada (409) ({$r['codigo']})");
ok($fallosAnotados() === $antes, 'subir: una llave que espera no cuenta como intento equivocado');
$e = $esperando();
ok(count($e) === 1 && (int)$e[0]['intentos'] === 1 && $e[0]['ultima'] === $corrida && $e[0]['desde'] === '127.0.0.1', 'subir: se anota el intento (la corrida y la dirección)');
ok(!str_contains(json_encode($e), $propia), 'la lista de las que esperan guarda solo la huella');
ok(in_array($subir($cabBuena, $datos)['codigo'], [200], true), 'subir: la llave vigente sigue sirviendo mientras otra espera');

// Otra dirección (alguien que sacó el código del programa): espera aparte; una por dirección; como máximo 10
ok(str_contains($registrar($otra, $codigo, '127.0.0.2')['cuerpo'], '"pendiente":true'), 'registrar: desde otra dirección también espera');
$otra2 = 'wzr_' . str_repeat('R', 40) . '-_3';
ok(str_contains($registrar($otra2, $codigo, '127.0.0.2')['cuerpo'], '"pendiente":true'), 'registrar: otra llave desde la misma dirección');
$e = $esperando();
ok(count($e) === 2 && in_array(hash('sha256', $otra2), array_column($e, 'huella'), true) && !in_array(hash('sha256', $otra), array_column($e, 'huella'), true),
   'registrar: una sola por dirección (la más nueva reemplaza a la anterior)');
for ($i = 3; $i <= 10; $i++) $registrar('wzr_' . str_repeat('S', 40) . sprintf('-%02d', $i), $codigo, '127.0.0.' . $i);
ok(count($esperando()) === 10, 'registrar: hasta 10 esperando');
$r = $registrar('wzr_' . str_repeat('T', 40) . '-11', $codigo, '127.0.0.11');
ok($r['codigo'] === 429 && count($esperando()) === 10, "registrar: con 10 esperando, la siguiente no entra (429) ({$r['codigo']})");
ok($registrar($propia, $codigo)['codigo'] === 200, 'registrar: con la lista llena, la que ya espera sigue recibiendo «ya estaba»');

// Lo que ve Iván
$l = pedir('GET', '/');
ok(str_contains($l['cuerpo'], 'llaves de WizLink esperan') && str_contains($l['cuerpo'], 'su confirmación'), 'Corridas avisa que hay llaves esperando');
$pl = pedir('GET', '/llave.php');
ok(str_contains($pl['cuerpo'], 'Esperando confirmación') && substr_count($pl['cuerpo'], 'Confirmar esta llave') === 10, 'Llave de subida muestra las 10 que esperan');
ok(str_contains($pl['cuerpo'], '127.0.0.2') && str_contains($pl['cuerpo'], h_e2e($corrida)) && str_contains($pl['cuerpo'], 'Intentó subir'), 'cada una con su dirección y la corrida que intentó subir');
ok(!str_contains($pl['cuerpo'], $propia) && !str_contains($pl['cuerpo'], $otra2) && !str_contains($pl['cuerpo'], $codigo), 'la página nunca muestra las llaves ni el código');
$id = fn(string $desde) => (string)(array_values(array_filter($esperando(), fn($x) => $x['desde'] === $desde))[0]['id'] ?? '');
$accion = fn(string $que, string $idX, array $mas = []) => pedir('POST', '/llave.php', ['cuerpo' => http_build_query(array_merge(['ficha' => ficha_de(pedir('GET', '/llave.php')['cuerpo']), 'que' => $que, 'id' => $idX], $mas)), 'cabeceras' => $origen]);
$sinFicha = pedir('POST', '/llave.php', ['cuerpo' => http_build_query(['que' => 'confirmar', 'id' => $id('127.0.0.2')]), 'cabeceras' => $origen]);
ok(count($esperando()) === 10 && str_contains($sinFicha['cuerpo'], 'venció'), 'confirmar: sin la ficha de la página no se hace nada');
$r = $accion('rechazar', $id('127.0.0.2'));
ok(str_contains($r['cuerpo'], 'Llave rechazada') && count($esperando()) === 9, 'rechazar: la de otra dirección sale de la lista');
ok($subir(array_merge(['Authorization: Bearer ' . $otra2], array_slice($cabBuena, 1)), $datos)['codigo'] !== 409, 'rechazar: la rechazada ya no espera');
ok(str_contains($accion('rechazar', 'noexiste')['cuerpo'], 'ya no espera confirmación'), 'rechazar: una que no está dice que ya no espera');
$r = $accion('confirmar', $id('127.0.0.1'));
ok(str_contains($r['cuerpo'], 'marque la casilla') && count($esperando()) === 9, 'confirmar: si hay una llave vigente, pide la casilla para reemplazarla');
$r = $accion('confirmar', $id('127.0.0.1'), ['confirmo' => 'si']);
ok(str_contains($r['cuerpo'], 'Llave confirmada'), 'confirmar: la de la máquina virtual queda confirmada');
ok(count($esperando()) === 0 || !str_contains((string)@file_get_contents($privado . '/estado/llaves_esperando.json'), '127.0.0.3'), 'confirmar: las demás que esperaban se descartan');

// Después de confirmar
ok($subir($cabPropia, $datos)['codigo'] === 200, 'subir: con la llave confirmada');
$r = $registrar($propia, $codigo);
ok($r['codigo'] === 200 && str_contains($r['cuerpo'], '"ya_estaba":true') && str_contains($r['cuerpo'], '"pendiente":false'), 'registrar: después de confirmar, la misma llave ya estaba');
ok($registrar($otra, $codigo, '127.0.0.2')['codigo'] === 401, 'registrar: el código ya usado no sirve para otra llave');
ok(in_array($subir(array_merge(['Authorization: Bearer ' . 'wzr_' . str_repeat('S', 40) . '-03'], array_slice($cabBuena, 1)), $datos)['codigo'], [401, 429], true), 'subir: una que esperaba y se descartó no sirve');
ok(in_array($subir($cabBuena, $datos)['codigo'], [401, 429], true), 'subir: la llave para pegar a mano anterior ya no sirve (401, o 429 después de tantas equivocadas)');
$pl = pedir('GET', '/llave.php');
ok(str_contains($pl['cuerpo'], 'Registrada por WizLink') && str_contains($pl['cuerpo'], 'y confirmada el') && str_contains($pl['cuerpo'], 'ya se usó'), 'la página dice que la llave la registró WizLink y quedó confirmada');
ok(!str_contains($pl['cuerpo'], 'Esperando confirmación') && !str_contains(pedir('GET', '/')['cuerpo'], 'esperan su confirmación'), 'ya no hay avisos de llaves esperando');
ok(!str_contains($pl['cuerpo'], $propia) && !str_contains($pl['cuerpo'], $codigo), 'la página nunca muestra la llave ni el código usados');
ok(!str_contains((string)@file_get_contents($privado . '/estado/llave.json'), $propia), 'el sitio guarda solo la huella de la llave de WizLink');
$cabBuena[0] = 'Authorization: Bearer ' . $propia;   // lo que sigue sube con la llave de WizLink

// ------------------------------------------------------------------ ver
$clave = str_replace(' ', '_', $corrida);
$l = pedir('GET', '/');
ok(str_contains($l['cuerpo'], '/corrida.php?c=' . rawurlencode($clave)), 'la lista muestra la corrida');
ok(str_contains($l['cuerpo'], 'Terminó'), 'la lista dice que terminó');
ok(str_contains($l['cuerpo'], '/corrida.php?c=' . rawurlencode($clave2)) && str_contains($l['cuerpo'], ' (2)</span>'), 'la lista muestra la «(2)», con su número');
$c2 = pedir('GET', '/corrida.php?c=' . rawurlencode($clave2));
ok($c2['codigo'] === 200 && str_contains($c2['cuerpo'], '(2)</h1>'), "la «(2)» se abre y su título la distingue ({$c2['codigo']})");
$c = pedir('GET', '/corrida.php?c=' . rawurlencode($clave));
ok($c['codigo'] === 200 && str_contains($c['cuerpo'], 'Resumen') && str_contains($c['cuerpo'], 'Diagnóstico') && str_contains($c['cuerpo'], 'Bitácora'), 'la corrida tiene sus accesos');
$a = pedir('GET', '/archivo.php?c=' . rawurlencode($clave) . '&f=' . rawurlencode($corrida . '/evidencia/resumen.txt'));
ok($a['codigo'] === 200 && str_contains($a['cuerpo'], 'RESUMEN DE LA CORRIDA'), 'el resumen se ve');
$b = pedir('GET', '/archivo.php?c=' . rawurlencode($clave) . '&f=' . rawurlencode($corrida . '/evidencia/resumen.txt') . '&bajar=1');
ok($b['codigo'] === 200 && str_contains($b['cab'], 'attachment'), 'un archivo se baja');
foreach (['../../wizlink-private/config.php', '/etc/passwd', $corrida . '/../../config.php', 'bitacoras_wizlink/../../x'] as $f) {
    $x = pedir('GET', '/archivo.php?c=' . rawurlencode($clave) . '&f=' . rawurlencode($f));
    ok($x['codigo'] === 404 && !str_contains($x['cuerpo'], '<?php'), "archivo con trampa: $f ({$x['codigo']})");
}
foreach (['../config', '2026-11-19_00.59/../x', 'x'] as $cl) {
    ok(pedir('GET', '/bajar.php?c=' . rawurlencode($cl))['codigo'] === 404, "bajar con trampa: $cl");
    ok(pedir('GET', '/corrida.php?c=' . rawurlencode($cl))['codigo'] === 404, "corrida con trampa: $cl");
}
$bz = pedir('GET', '/bajar.php?c=' . rawurlencode($clave));
ok($bz['codigo'] === 200 && hash('sha256', $bz['cuerpo']) === $sha, 'el .zip se baja idéntico');

// La corrida automática en pausa (Iván, 20-nov: D): la corrida más nueva trae el estado de WizLink con la pausa, y la lista
// lo dice arriba, con el motivo y qué hacer. La siguiente sin pausa lo quita.
$conEstado = function (string $nombre, string $estado) use ($subir, $cabBuena): array {
    $f = tempnam(sys_get_temp_dir(), 'z');
    try {
        file_put_contents($f, zip_de_prueba($nombre, 'con el estado de la corrida automática'));
        $z = new ZipArchive(); $z->open($f); $z->addFromString('bitacoras_wizlink/corrida_automatica_estado.txt', $estado); $z->close();
        $dz = (string)file_get_contents($f);
    } finally {
        @unlink($f);
    }
    return $subir(array_merge(array_slice($cabBuena, 0, 2), ['X-WizLink-Corrida: ' . $nombre, 'X-WizLink-Sha256: ' . hash('sha256', $dz)]), $dz);
};
$enPausa = date('Y-m-d H.i', time() - 60);
$r = $conEstado($enPausa, "\xEF\xBB\xBF# inventado\r\npausa=WizLink se detuvo sin teclear nada más: <script>prueba</script>\r\nque_hacer=Presione «Reanudar» para seguir con el horario.\r\n");
ok($r['codigo'] === 200, "pausa: la corrida con la pausa se recibe ({$r['codigo']})");
$l = pedir('GET', '/');
ok(str_contains($l['cuerpo'], 'reportó la corrida automática en pausa') && str_contains($l['cuerpo'], 'aviso-error') && str_contains($l['cuerpo'], 'Presione «Reanudar» para seguir con el horario.'), 'pausa: la lista lo dice arriba, con qué hacer');
ok(str_contains($l['cuerpo'], '&lt;script&gt;prueba') && !str_contains($l['cuerpo'], '<script>prueba'), 'pausa: el motivo va escapado');
ok(!str_contains($l['cuerpo'], 'Sin corridas nuevas'), 'pausa: con la corrida recién llegada no avisa de atrasadas');
// Los datos con el formato de la versión 1.4.0 no traen «pausa»: se lee del .zip guardado (Codex, PR #7)
$clPausa = clave_e2e($enPausa);
$jsonPausa = $privado . '/corridas/' . substr($clPausa, 0, 7) . '/' . $clPausa . '.json';
$dj = json_decode((string)file_get_contents($jsonPausa), true);
unset($dj['pausa'], $dj['que_hacer']);
file_put_contents($jsonPausa, json_encode($dj));
ok(str_contains(pedir('GET', '/')['cuerpo'], 'reportó la corrida automática en pausa'), 'pausa: con los datos de la versión 1.4.0, la lee del .zip');
$r = $conEstado(date('Y-m-d H.i'), "pausa=\r\nque_hacer=\r\n");
ok($r['codigo'] === 200 && !str_contains(pedir('GET', '/')['cuerpo'], 'reportó la corrida automática en pausa'), 'pausa: la corrida siguiente, sin pausa, lo quita');

// ------------------------------------------------------------------ salir
$fs = preg_match('/action="\/salir.php"><input type="hidden" name="ficha" value="([0-9a-f]{64})"/', $l['cuerpo'], $m) ? $m[1] : '';
ok(pedir('GET', '/salir.php')['codigo'] === 302 && pedir('GET', '/')['codigo'] === 200, 'salir por GET no cierra la sesión');
pedir('POST', '/salir.php', ['cuerpo' => http_build_query(['ficha' => $fs]), 'cabeceras' => ['Origin: https://wizlink.bytopo.net']]);
ok(pedir('GET', '/')['codigo'] === 302, 'Salir cierra la sesión');

// ------------------------------------------------------------------ restablecer la contraseña o la app cierra las sesiones abiertas
$pasoClave = function (array $op = []) use ($contrasena, $origen): array {
    $p = pedir('GET', '/entrar.php', $op);
    return pedir('POST', '/entrar.php?paso=clave', $op + ['cuerpo' => http_build_query(['ficha' => ficha_de($p['cuerpo']), 'usuario' => 'prueba01', 'clave' => $contrasena]), 'cabeceras' => $origen]);
};
$totp = $privado . '/estado/totp.json';
$entrarCompleto = function () use ($pasoClave, $totp, $secreto, $origen): bool {
    $pasoClave();
    $t = json_decode((string)file_get_contents($totp), true);
    $t['ultimo_paso'] = 0;   // para poder usar el código de ahora aunque esta prueba ya lo haya usado
    file_put_contents($totp, json_encode($t));
    $p = pedir('GET', '/entrar.php');
    $r = pedir('POST', '/entrar.php?paso=codigo', ['cuerpo' => http_build_query(['ficha' => ficha_de($p['cuerpo']), 'codigo' => codigo($secreto, intdiv(time(), 30))]), 'cabeceras' => $origen]);
    return $r['codigo'] === 302 && pedir('GET', '/')['codigo'] === 200;
};
ok($entrarCompleto(), 'sesiones: se entra con los dos pasos');
rename($totp, $totp . '.aparte');   // como borrar totp.json en el Administrador de archivos (teléfono perdido)
ok(pedir('GET', '/')['codigo'] === 302, 'sesiones: borrar totp.json cierra la sesión abierta');
rename($totp . '.aparte', $totp);
ok(pedir('GET', '/')['codigo'] === 302, 'sesiones: y sigue cerrada aunque el archivo vuelva');
ok($entrarCompleto(), 'sesiones: se entra otra vez');
$acceso = $privado . '/estado/acceso.json';
$accesoAntes = (string)file_get_contents($acceso);
$a = json_decode($accesoAntes, true);
$a['huella'] = password_hash('otra-clave-inventada-9999', PASSWORD_DEFAULT); $a['creado'] = time();
file_put_contents($acceso, json_encode($a));   // como borrar acceso.json y crear el acceso otra vez
ok(pedir('GET', '/')['codigo'] === 302, 'sesiones: cambiar la contraseña cierra la sesión abierta');
file_put_contents($acceso, $accesoAntes);

// totp.json dañado: la entrada se detiene y nunca ofrece configurar otra app
$totpBueno = (string)file_get_contents($totp);
file_put_contents($totp, '{"secreto": "da');
$pasoClave();
$p = pedir('GET', '/entrar.php');
ok(str_contains($p['cuerpo'], 'No se puede leer la configuración de la app de autenticación') && !str_contains($p['cuerpo'], 'otpauth') && !str_contains($p['cuerpo'], 'name="codigo"'),
   'totp.json dañado: avisa y no ofrece configurar otra app');
$r = pedir('POST', '/entrar.php?paso=codigo', ['cuerpo' => http_build_query(['ficha' => ficha_de($p['cuerpo']), 'codigo' => codigo($secreto, intdiv(time(), 30))]), 'cabeceras' => $origen]);
ok($r['codigo'] !== 302 && file_get_contents($totp) === '{"secreto": "da', 'totp.json dañado: no entra ni lo reemplaza');
file_put_contents($totp, $totpBueno);
pedir('POST', '/salir.php', ['cuerpo' => http_build_query(['ficha' => ficha_de($p['cuerpo'])]), 'cabeceras' => $origen]);

// ------------------------------------------------------------------ intentos en paralelo: el tope de cinco se cumple igual
pedir_a_la_vez(array_fill(0, 30, ['GET', '/robots.txt', ['sin_cookies' => true]]));   // que el servidor tenga sus procesos listos
$tarroNuevo = fn() => tempnam(sys_get_temp_dir(), 'tarro');
$desdeP = '127.0.0.30';
$lista = [];
for ($i = 0; $i < 20; $i++) {
    $t = $tarroNuevo();
    $p = pedir('GET', '/entrar.php', ['tarro' => $t, 'desde' => $desdeP]);
    $lista[] = ['POST', '/entrar.php?paso=clave', ['tarro' => $t, 'desde' => $desdeP, 'cabeceras' => $origen,
        'cuerpo' => http_build_query(['ficha' => ficha_de($p['cuerpo']), 'usuario' => 'prueba01', 'clave' => "equivocada-$i-9999"])]];
}
$res = pedir_a_la_vez($lista);
$revisadas = count(array_filter($res, fn($x) => str_contains($x['cuerpo'], 'Usuario o contraseña incorrectos')));
ok($revisadas <= 5, "en paralelo: de 20 contraseñas equivocadas a la vez se revisan como máximo 5 ($revisadas)");
$r = $pasoClave(['tarro' => $tarroNuevo(), 'desde' => $desdeP]);
ok($r['codigo'] !== 302 && str_contains($r['cuerpo'], 'Demasiados intentos'), 'en paralelo: después, esa dirección queda bloqueada aunque llegue la contraseña correcta');
$desdeC = '127.0.0.31';
$validos = array_map(fn($d) => codigo($secreto, intdiv(time(), 30) + $d), [-1, 0, 1, 2]);
$lista = [];
for ($i = 0; $i < 20; $i++) {
    $t = $tarroNuevo();
    $pasoClave(['tarro' => $t, 'desde' => $desdeC]);
    $p = pedir('GET', '/entrar.php', ['tarro' => $t, 'desde' => $desdeC]);
    $mal = sprintf('%06d', 314159 + $i);
    if (in_array($mal, $validos, true)) $mal = sprintf('%06d', 271828 + $i);
    $lista[] = ['POST', '/entrar.php?paso=codigo', ['tarro' => $t, 'desde' => $desdeC, 'cabeceras' => $origen, 'cuerpo' => http_build_query(['ficha' => ficha_de($p['cuerpo']), 'codigo' => $mal])]];
}
$res = pedir_a_la_vez($lista);
$revisados = count(array_filter($res, fn($x) => str_contains($x['cuerpo'], 'no es correcto o ya se usó')));
ok($revisados <= 5, "en paralelo: de 20 códigos equivocados a la vez se revisan como máximo 5 ($revisados)");
ok((int)(json_decode((string)@file_get_contents($privado . '/estado/intentos.json'), true)['bloqueado_hasta'] ?? 0) > time(), 'en paralelo: después, la entrada queda bloqueada 15 minutos');

// ------------------------------------------------------------------ segunda entrada: contraseña, el código de un solo uso y los bloqueos
$p = pedir('GET', '/entrar.php');
ok(str_contains($p['cuerpo'], 'Usuario y contraseña') && !str_contains($p['cuerpo'], 'Crear el acceso'), 'segunda entrada: pide usuario y contraseña');
$f = ficha_de($p['cuerpo']);
$entrar = function (string $u, string $c) use (&$f, $origen) {
    return pedir('POST', '/entrar.php?paso=clave', ['cuerpo' => http_build_query(['ficha' => $f, 'usuario' => $u, 'clave' => $c]), 'cabeceras' => $origen]);
};
ok(str_contains($entrar('prueba01', 'otra-clave-9999')['cuerpo'], 'Usuario o contraseña incorrectos'), 'entrar: contraseña equivocada');
ok(str_contains($entrar('otro', $contrasena)['cuerpo'], 'Usuario o contraseña incorrectos'), 'entrar: usuario equivocado, el mismo mensaje');
$r = $entrar('PRUEBA01', $contrasena);
ok($r["codigo"] === 302, "entrar: usuario (sin importar mayúsculas) y contraseña correctos ({$r["codigo"]}) " . (preg_match("/aviso-error[^>]*>.*?<p>(.*?)<\/p>/s", $r["cuerpo"], $mm) ? $mm[1] : ""));
$pc = pedir('GET', '/entrar.php');
ok(str_contains($pc['cuerpo'], 'Código de verificación') && !str_contains($pc['cuerpo'], 'data-texto'), 'segunda entrada: solo pide el código, sin QR');
$f2 = ficha_de($pc['cuerpo']);
$r = pedir('POST', '/entrar.php?paso=codigo', ['cuerpo' => http_build_query(['ficha' => $f2, 'codigo' => codigo($secreto, $paso)]), 'cabeceras' => $origen]);
ok($r['codigo'] !== 302, 'el mismo código no sirve dos veces');
for ($i = 0; $i < 5; $i++) pedir('POST', '/entrar.php?paso=codigo', ['cuerpo' => http_build_query(['ficha' => $f2, 'codigo' => '123456']), 'cabeceras' => $origen]);
$r = pedir('POST', '/entrar.php?paso=codigo', ['cuerpo' => http_build_query(['ficha' => $f2, 'codigo' => codigo($secreto, intdiv(time(), 30) + 1)]), 'cabeceras' => $origen]);
ok(str_contains($r['cuerpo'], 'Demasiados códigos'), 'después de 5 códigos equivocados, bloqueado aunque el código sea correcto');
// Cinco contraseñas equivocadas bloquean esa dirección, aunque después venga la correcta
pedir('POST', '/salir.php', ['cuerpo' => http_build_query(['ficha' => $f2]), 'cabeceras' => $origen]);
$p = pedir('GET', '/entrar.php'); $f = ficha_de($p['cuerpo']);
for ($i = 0; $i < 5; $i++) $entrar('prueba01', 'equivocada-' . $i . '-9999');
$r = $entrar('prueba01', $contrasena);
ok(str_contains($r['cuerpo'], 'Demasiados intentos') && $r['codigo'] !== 302, 'después de 5 contraseñas equivocadas, la dirección queda bloqueada');
ok(!str_contains((string)@file_get_contents($privado . '/estado/intentos_acceso.json'), '127.0.0.1'), 'los intentos no guardan la dirección a la vista (solo su huella)');

echo "\n$total comprobaciones, $fallas fallas\n";
exit($fallas > 0 ? 1 : 0);
