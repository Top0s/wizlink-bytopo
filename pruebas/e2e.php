<?php
// Prueba de punta a punta contra la copia de prueba local de wizlink.bytopo.net (solo en el sandbox).
// Uso: php e2e.php <zip de una corrida> <nombre de la corrida> <carpeta wizlink-private de la copia>
declare(strict_types=1);

$zip = $argv[1]; $corrida = $argv[2]; $privado = rtrim($argv[3], '/');
$base = 'https://wizlink.bytopo.net';
$tarro = tempnam(sys_get_temp_dir(), 'tarro');
$fallas = 0; $total = 0;

function ok(bool $c, string $d): void { global $fallas, $total; $total++; if (!$c) { $fallas++; echo "FALLA: $d\n"; } }

function pedir(string $metodo, string $ruta, array $op = []): array
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
    if (!($op['sin_cookies'] ?? false)) { curl_setopt($ch, CURLOPT_COOKIEJAR, $tarro); curl_setopt($ch, CURLOPT_COOKIEFILE, $tarro); }
    if (isset($op['desde'])) curl_setopt($ch, CURLOPT_INTERFACE, $op['desde']);   // otra dirección de esta misma computadora (127.0.0.x)
    if (isset($op['cuerpo'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $op['cuerpo']);
    $r = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $tc = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['codigo' => $codigo, 'cab' => substr((string)$r, 0, $tc), 'cuerpo' => substr((string)$r, $tc)];
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
foreach (['/.git/config', '/.git/HEAD', '/.git', '/.git%2Fconfig', '/%2egit/config', '//.git/config', '/x/../.git/config', '/lib/base.php', '/lib/', '/README.md', '/.htaccess', '/assets/', '/api/'] as $r) {
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
// Muchas llaves equivocadas no bloquean a la correcta
for ($i = 0; $i < 31; $i++) $subir(array_merge(['Authorization: Bearer wzr_' . str_repeat('C', 43)], array_slice($cabBuena, 1)), 'x');
ok($subir(array_merge(['Authorization: Bearer wzr_' . str_repeat('C', 43)], array_slice($cabBuena, 1)), 'x')['codigo'] === 429, 'subir: después de 30 llaves equivocadas en una hora, 429');
$r = $subir($cabBuena, $datos);
ok($r['codigo'] === 200, "subir: la llave correcta sigue entrando ({$r['codigo']})");
ok(count(glob(dirname($privado) . '/public_html/**/*.zip') ?: []) === 0 && count(glob(dirname($privado) . '/public_html/*.zip') ?: []) === 0, 'nada queda dentro de public_html');

// Una corrida vieja (de hace 40 días) se borra con la siguiente subida
$vieja = date('Y-m-d H.i', time() - 40 * 86400);
$zv = tempnam(sys_get_temp_dir(), 'z') . '.zip';
$z = new ZipArchive(); $z->open($zv, ZipArchive::CREATE); $z->addFromString($vieja . '/evidencia/resumen.txt', "WIZLINK · VIEJA · RESUMEN DE LA CORRIDA\nResultado: TERMINÓ\n"); $z->close();
$dv = file_get_contents($zv);
ok($subir(array_merge(array_slice($cabBuena, 0, 2), ['X-WizLink-Corrida: ' . $vieja, 'X-WizLink-Sha256: ' . hash('sha256', $dv)]), $dv)['codigo'] === 200, 'subir: una corrida vieja se recibe');
$clVieja = str_replace(' ', '_', $vieja);
ok(!is_file($privado . '/corridas/' . substr($clVieja, 0, 7) . '/' . $clVieja . '.zip'), 'la limpieza borra la de más de 30 días');

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

// ------------------------------------------------------------------ salir
$fs = preg_match('/action="\/salir.php"><input type="hidden" name="ficha" value="([0-9a-f]{64})"/', $l['cuerpo'], $m) ? $m[1] : '';
ok(pedir('GET', '/salir.php')['codigo'] === 302 && pedir('GET', '/')['codigo'] === 200, 'salir por GET no cierra la sesión');
pedir('POST', '/salir.php', ['cuerpo' => http_build_query(['ficha' => $fs]), 'cabeceras' => ['Origin: https://wizlink.bytopo.net']]);
ok(pedir('GET', '/')['codigo'] === 302, 'Salir cierra la sesión');

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
