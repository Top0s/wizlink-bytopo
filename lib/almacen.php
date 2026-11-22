<?php
// wizlink.bytopo.net · dónde quedan las corridas que manda WizLink.
//   - Cada corrida llega como un solo .zip (su carpeta completa más las bitácoras de WizLink) y se guarda en
//     wizlink-private/corridas/AAAA-MM/, fuera de public_html: desde internet solo se llega a ellas entrando.
//   - Junto a cada .zip, un .json corto con lo que muestra la lista (resultado, duración, versión, cuántas en REVISAR),
//     leído del resumen y del control de la corrida al recibirla.
//   - La llave de subida: guarda solo su huella (SHA-256). Solo sirve para subir: no deja ver, listar ni bajar nada.
//     Llega de dos maneras: la crea WizLink en la máquina virtual y la registra una sola vez con un «código para
//     WizLink» de un solo uso (Iván, 19-nov: L4; api/registrar.php), o la crea este sitio y Informática la pega a mano.
//     Hay una sola llave vigente: la última que llegó reemplaza a la anterior. La que registra WizLink espera la
//     confirmación de Iván antes de servir (Iván, 19-nov: S1).
//   - El .zip se borra solo a los 7 días (Iván, 22-nov). El .json se queda 1 año más (B1): es el registro de la corrida
//     (resultado, duración, tipo y el diagnóstico en palabras), unos pocos KB, para seguir viendo qué pasó.

declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/explicar.php';

/** Hasta cuándo se guarda el registro de una corrida (su .json) después de borrar su .zip (Iván, 22-nov: B1). */
const DIAS_REGISTRO = 365;
/** La forma de los datos de cada corrida: con la 2 llegan el tipo, los segundos y los casos del diagnóstico (sitio 2.0.0). */
const DATOS_FORMA = 2;

const MAXIMO_BYTES = 64 * 1024 * 1024;              // por corrida, comprimida
const MAXIMO_DESCOMPRIMIDO = 1024 * 1024 * 1024;    // lo que puede ocupar adentro
const MAXIMO_ARCHIVOS = 65000;
const MAXIMO_VER = 3 * 1024 * 1024;                 // un archivo más grande solo se baja
const LLAVE_FORMA = '/^wzr_[A-Za-z0-9_-]{43}$/';
const CODIGO_FORMA = '/^wzc_[A-Za-z0-9_-]{43}$/';
const CODIGO_DIAS = 30;                              // un código para WizLink sin usar vence a los 30 días

/** El nombre de la carpeta de una corrida en la PC: «2026-11-19 14.00», o «2026-11-19 14.00 (2)» si hubo dos. */
function corrida_valida(string $c): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2})\.(\d{2})(?: \((\d{1,3})\))?$/', $c, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[4] < 24 && (int)$m[5] < 60;
}

/**
 * El nombre que se usa en el servidor y en las direcciones: «2026-11-19_14.00» o «2026-11-19_14.00_2». Primero el número
 * de la segunda corrida del mismo minuto, y después los espacios (al revés, « (2)» ya no se encontraba: quedaba «_(2)»).
 */
function clave_de(string $corrida): string
{
    return str_replace(' ', '_', preg_replace('/ \((\d{1,3})\)$/', '_$1', $corrida, 1) ?? '');
}

function clave_valida(string $clave): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}\.\d{2}(?:_\d{1,3})?$/', $clave);
}

function corrida_de(string $clave): string
{
    return preg_replace('/_(\d+)$/', ' ($1)', preg_replace('/_/', ' ', $clave, 1), 1) ?? '';
}

/** La fecha y hora de la corrida (hora de Nicaragua), de su nombre. */
function momento_de(string $clave): int
{
    $t = DateTime::createFromFormat('Y-m-d_H.i', substr($clave, 0, 16), new DateTimeZone(ZONA_HORARIA));
    return $t ? $t->getTimestamp() : 0;
}

function ruta_zip(string $clave): string
{
    return carpeta('corridas/' . substr($clave, 0, 7)) . '/' . $clave . '.zip';
}

function ruta_datos(string $clave): string
{
    return carpeta('corridas/' . substr($clave, 0, 7)) . '/' . $clave . '.json';
}

// ------------------------------------------------------------------ la llave de subida

function archivo_llave(): string
{
    return carpeta('estado') . '/llave.json';
}

/** 32 bytes al azar en base64url, con su prefijo: «wzr_…» (llave) o «wzc_…» (código para WizLink). */
function al_azar(string $prefijo): string
{
    return $prefijo . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/** Crea una llave para pegar a mano (la anterior deja de servir) y la devuelve; solo se guarda su huella. */
function llave_nueva(): string
{
    $llave = al_azar('wzr_');
    escribir_json(archivo_llave(), ['huella' => hash('sha256', $llave), 'creada' => time(), 'origen' => 'sitio']);
    return $llave;
}

/** La fecha en que se creó la llave vigente, o null si no hay. */
function llave_creada(): ?int
{
    $d = leer_json(archivo_llave());
    return ($d !== null && is_string($d['huella'] ?? null)) ? (int)($d['creada'] ?? 0) : null;
}

/** De dónde vino la llave vigente: 'wizlink' (la registró WizLink con el código) o 'sitio' (creada aquí). */
function llave_origen(): string
{
    $d = leer_json(archivo_llave()) ?? [];
    return ($d['origen'] ?? 'sitio') === 'wizlink' ? 'wizlink' : 'sitio';
}

// ------------------------------------------------------------------ el código para WizLink (L4)

function archivo_codigo_wizlink(): string
{
    return carpeta('estado') . '/codigo_wizlink.json';
}

/** Crea un código para WizLink (reemplaza al anterior sin usar) y lo devuelve; solo se guarda su huella. */
function codigo_wizlink_nuevo(): string
{
    $codigo = al_azar('wzc_');
    escribir_json(archivo_codigo_wizlink(), ['huella' => hash('sha256', $codigo), 'creado' => time(), 'usado' => 0, 'llave' => '']);
    return $codigo;
}

/** El código para WizLink guardado ({huella, creado, usado, llave}), o null si no hay. */
function codigo_wizlink(): ?array
{
    $d = leer_json(archivo_codigo_wizlink());
    return ($d !== null && is_string($d['huella'] ?? null)) ? $d : null;
}

/** true si el código es el código para WizLink guardado (usado o no). */
function codigo_coincide(string $codigo): bool
{
    if (!preg_match(CODIGO_FORMA, $codigo)) return false;
    $c = codigo_wizlink();
    return $c !== null && hash_equals((string)$c['huella'], hash('sha256', $codigo));
}

/**
 * WizLink registra su propia llave con el código (Iván, 19-nov: S1). La llave no sirve todavía: queda «esperando
 * confirmación» hasta que Iván la confirma en Llave de subida. Así, si alguien sacara el código del programa publicado
 * (el portal de las actualizaciones se baja sin contraseña), no podría subir nada ni dejar afuera a la máquina virtual.
 * Devuelve 'espera' si quedó esperando, 'ya' si esa misma llave ya estaba (esperando o confirmada con este código; un
 * reintento después de un corte), 'lleno' si ya hay ESPERANDO_MAXIMO llaves esperando, o 'no'. Cada dirección tiene
 * una sola llave esperando: la más nueva reemplaza a la anterior de esa dirección. Se llama con el candado tomado.
 */
function registrar_llave(string $codigo, string $llave, int $ahora, string $dir): string
{
    if (!preg_match(CODIGO_FORMA, $codigo) || !preg_match(LLAVE_FORMA, $llave)) return 'no';
    $c = codigo_wizlink();
    if ($c === null || !hash_equals((string)$c['huella'], hash('sha256', $codigo))) return 'no';
    $huellaLlave = hash('sha256', $llave);
    if ((int)($c['usado'] ?? 0) > 0) {
        return hash_equals((string)($c['llave'] ?? ''), $huellaLlave) && llave_correcta($llave) ? 'ya' : 'no';
    }
    if ($ahora - (int)($c['creado'] ?? 0) > CODIGO_DIAS * 86400) return 'no';
    $esperando = llaves_esperando();
    foreach ($esperando as $e) if (hash_equals((string)$e['huella'], $huellaLlave)) return 'ya';
    $esperando = array_values(array_filter($esperando, fn($e) => ($e['desde'] ?? '') !== $dir));
    if (count($esperando) >= ESPERANDO_MAXIMO) return 'lleno';
    $esperando[] = ['id' => bin2hex(random_bytes(6)), 'huella' => $huellaLlave, 'codigo' => (string)$c['huella'], 'llego' => $ahora,
                    'desde' => $dir, 'intentos' => 0, 'ultima' => '', 'version' => '', 'ultimo_intento' => 0];
    guardar_esperando($esperando);
    return 'espera';
}

// ------------------------------------------------------------------ llaves que esperan confirmación (Iván, 19-nov: S1)

const ESPERANDO_MAXIMO = 10;

function archivo_esperando(): string
{
    return carpeta('estado') . '/llaves_esperando.json';
}

/**
 * Las llaves que WizLink registró con el código vigente y esperan la confirmación de Iván, la más vieja primero. Las de
 * un código anterior, ya usado o vencido no cuentan.
 */
function llaves_esperando(): array
{
    $c = codigo_wizlink();
    if ($c === null || (int)($c['usado'] ?? 0) > 0 || time() - (int)($c['creado'] ?? 0) > CODIGO_DIAS * 86400) return [];
    $l = (array)(leer_json(archivo_esperando())['llaves'] ?? []);
    return array_values(array_filter($l, fn($e) => is_array($e) && is_string($e['huella'] ?? null) && is_string($e['id'] ?? null)
        && hash_equals((string)$c['huella'], (string)($e['codigo'] ?? ''))));
}

function guardar_esperando(array $l): void
{
    escribir_json(archivo_esperando(), ['llaves' => array_values($l)]);
}

/** La llave que espera confirmación con esa huella, o null. */
function llave_esperando(string $llave): ?array
{
    if (!preg_match(LLAVE_FORMA, $llave)) return null;
    $h = hash('sha256', $llave);
    foreach (llaves_esperando() as $e) if (hash_equals((string)$e['huella'], $h)) return $e;
    return null;
}

/** Anota que una llave que espera intentó subir una corrida (lo que Iván ve para decidir). Con el candado tomado. */
function anotar_intento(string $llave, string $corrida, string $version, int $ahora): void
{
    $h = hash('sha256', $llave);
    $l = llaves_esperando();
    foreach ($l as $i => $e) {
        if (!hash_equals((string)$e['huella'], $h)) continue;
        if ($ahora - (int)($e['ultimo_intento'] ?? 0) < 10) return;   // muchas seguidas no reescriben el archivo cada vez
        $l[$i]['intentos'] = (int)($e['intentos'] ?? 0) + 1;
        $l[$i]['ultima'] = corrida_valida($corrida) ? $corrida : '';
        $l[$i]['version'] = $version;
        $l[$i]['ultimo_intento'] = $ahora;
        guardar_esperando($l);
        return;
    }
}

/**
 * Iván confirma una llave que espera: queda como la llave de subida (reemplaza a la vigente), el código se quema y las
 * demás que esperaban se descartan. Devuelve la llave confirmada, o null si ya no estaba. Con el candado tomado.
 */
function confirmar_llave(string $id, int $ahora): ?array
{
    foreach (llaves_esperando() as $e) {
        if (!hash_equals((string)$e['id'], $id)) continue;
        escribir_json(archivo_llave(), ['huella' => (string)$e['huella'], 'creada' => (int)$e['llego'], 'origen' => 'wizlink',
                                        'desde' => (string)($e['desde'] ?? ''), 'confirmada' => $ahora]);
        $c = codigo_wizlink();
        $c['usado'] = $ahora;
        $c['llave'] = (string)$e['huella'];
        escribir_json(archivo_codigo_wizlink(), $c);
        guardar_esperando([]);
        return $e;
    }
    return null;
}

/** Iván rechaza una llave que espera: se borra de la lista. El código sigue sirviendo. Con el candado tomado. */
function rechazar_llave(string $id): ?array
{
    $l = llaves_esperando();
    foreach ($l as $i => $e) {
        if (!hash_equals((string)$e['id'], $id)) continue;
        unset($l[$i]);
        guardar_esperando($l);
        return $e;
    }
    return null;
}

/** Hace $f con el candado del estado (el mismo de las subidas y el registro): nada se pisa. */
function con_candado(callable $f)
{
    return con_candado_archivo('subida.lock', $f);
}

// ------------------------------------------------------------------ intentos con una llave o un código equivocados

const FALLOS_POR_HORA = 30;

/** true si en la última hora hubo demasiados intentos equivocados (entonces se responde 429 a los equivocados). */
function demasiados_fallos(int $ahora): bool
{
    $f = array_filter((array)(leer_json(carpeta('estado') . '/subidas_rechazadas.json')['fallos'] ?? []), fn($t) => is_int($t) && $ahora - $t < 3600);
    return count($f) >= FALLOS_POR_HORA;
}

function anotar_fallo(int $ahora): void
{
    $archivo = carpeta('estado') . '/subidas_rechazadas.json';
    $f = array_values(array_filter((array)(leer_json($archivo)['fallos'] ?? []), fn($t) => is_int($t) && $ahora - $t < 3600));
    $f[] = $ahora;
    try { escribir_json($archivo, ['fallos' => $f]); } catch (Throwable $e) { }
}

/** La llave que trae el encabezado Authorization: Bearer (también si Apache lo pasa con otro nombre). */
function llave_de_la_peticion(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strcasecmp((string)$k, 'Authorization') === 0) $auth = (string)$v;
    }
    return preg_match('/^Bearer\s+(\S+)$/', (string)$auth, $m) ? $m[1] : '';
}

function llave_correcta(string $llave): bool
{
    if (!preg_match(LLAVE_FORMA, $llave)) return false;
    $d = leer_json(archivo_llave());
    $huella = is_array($d) ? ($d['huella'] ?? '') : '';
    return is_string($huella) && strlen($huella) === 64 && hash_equals($huella, hash('sha256', $llave));
}

// ------------------------------------------------------------------ revisar y guardar lo que llega

/**
 * Revisa un .zip recibido sin sacar nada a disco: que se abra, que no tenga nombres con trampa (rutas absolutas,
 * «..», barras invertidas), que no pase de los topes y que sea de esa corrida. Devuelve null si está bien, o el motivo.
 */
function revisar_zip(string $archivo, string $corrida): ?string
{
    if (!class_exists('ZipArchive')) return null;   // sin la extensión zip se guarda igual; solo no se puede ver adentro
    $z = new ZipArchive();
    if ($z->open($archivo, ZipArchive::CHECKCONS) !== true) return 'el archivo no es un .zip válido';
    try {
        if ($z->numFiles < 1 || $z->numFiles > MAXIMO_ARCHIVOS) return 'el .zip tiene ' . $z->numFiles . ' archivos';
        $total = 0;
        $deLaCorrida = false;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $e = $z->statIndex($i);
            if ($e === false) return 'no se pudo leer el .zip';
            $n = (string)$e['name'];
            if (!nombre_interno_valido($n)) return 'el .zip tiene un nombre de archivo no permitido';
            $total += (int)$e['size'];
            if ($total > MAXIMO_DESCOMPRIMIDO) return 'el .zip ocupa más de 1 GB descomprimido';
            if (str_starts_with($n, $corrida . '/')) $deLaCorrida = true;
        }
        return $deLaCorrida ? null : 'el .zip no trae la carpeta de la corrida ' . $corrida;
    } finally {
        $z->close();
    }
}

function nombre_interno_valido(string $n): bool
{
    if ($n === '' || strlen($n) > 400 || !mb_check_encoding($n, 'UTF-8')) return false;
    if ($n[0] === '/' || str_contains($n, '\\') || str_contains($n, "\0") || preg_match('/[\x00-\x1f]/', $n)) return false;
    foreach (explode('/', rtrim($n, '/')) as $p) if ($p === '' || $p === '.' || $p === '..') return false;
    return true;
}

/** Lo que muestra la lista, leído del resumen y del control de la corrida dentro del .zip. */
function datos_de_zip(string $archivo, string $corrida, string $version, int $bytes, string $sha): array
{
    $d = [
        'corrida' => $corrida,
        'recibida' => time(),
        'bytes' => $bytes,
        'sha256' => $sha,
        'version' => $version,
        'resultado' => '',
        'detalle' => '',
        'duracion' => '',
        'consultas' => 0,
        'revisar' => 0,
        'archivos' => 0,
        'pausa' => '',
        'que_hacer' => '',
        'forma' => DATOS_FORMA,
        'tipo' => 'op',
        'segundos' => -1,
        'casos' => [],
        'impresos' => -1,
        'revisar_por_tipo' => [],
    ];
    if (!class_exists('ZipArchive')) return $d;
    $z = new ZipArchive();
    if ($z->open($archivo) !== true) return $d;
    try {
        $d['archivos'] = $z->numFiles;
        $resumen = $z->getFromName($corrida . '/evidencia/resumen.txt', 65536);
        if (is_string($resumen)) {
            $resumen = preg_replace('/^\xEF\xBB\xBF/', '', $resumen);
            $lineas = preg_split('/\r?\n/', $resumen);
            if (preg_match('/^WIZLINK · (.+?) · RESUMEN/u', $lineas[0] ?? '', $m)) $d['version'] = mb_substr($m[1], 0, 60);
            $d['tipo'] = tipo_de_resumen($resumen);
            $d['segundos'] = segundos_de_resumen($resumen);
            if (preg_match('/Impresos leídos: (\d+)/u', $resumen, $m)) $d['impresos'] = (int)$m[1];
            foreach ($lineas as $l) {
                if (preg_match('/Duración: (\d+ min \d+ s)/u', $l, $m)) $d['duracion'] = $m[1];
                if (str_starts_with($l, 'Resultado: ')) {
                    $r = trim(substr($l, strlen('Resultado: ')));
                    $d['resultado'] = str_starts_with($r, 'TERMINÓ') ? 'termino' : 'detuvo';
                    $d['detalle'] = mb_substr($r, 0, 300);
                }
            }
        }
        // La corrida automática al terminar esta corrida (Iván, 20-nov: D): si quedó en pausa, por qué y qué hacer. Solo esas
        // dos líneas; el archivo nunca trae la contraseña ni el usuario de Wizard.
        $estado = $z->getFromName('bitacoras_wizlink/corrida_automatica_estado.txt', 65536);
        if (is_string($estado)) [$d['pausa'], $d['que_hacer']] = pausa_de_estado($estado);
        // El diagnóstico por partes de WizLink: solo el título y el primer motivo de cada caso (explicar.php)
        $diagnostico = $z->getFromName($corrida . '/evidencia/diagnostico.txt', 1024 * 1024);
        $hayDiagnostico = is_string($diagnostico);
        if ($hayDiagnostico) $d['casos'] = casos_de_diagnostico($diagnostico);
        // El control: una fila por consulta, con su estado (OK, REVISAR, NO SE PUDO, SE DETUVO, NO SE CORRIÓ)
        $filasControl = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = (string)$z->getNameIndex($i);
            if (!preg_match('#^' . preg_quote($corrida, '#') . '/control_[^/]+\.csv$#', $n)) continue;
            $csv = $z->getFromIndex($i, 4 * 1024 * 1024);
            if (!is_string($csv)) break;
            $filas = preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', $csv));
            $cab = str_getcsv(array_shift($filas) ?? '', ',', '"', '');
            $col = array_search('estado', $cab, true);
            foreach ($filas as $f) {
                if (trim($f) === '' || $col === false) continue;
                $c = str_getcsv($f, ',', '"', '');
                $d['consultas']++;
                if (($c[$col] ?? 'OK') === 'OK') continue;
                $d['revisar']++;
                $fila = [];
                foreach ($cab as $i => $nombre) $fila[$nombre] = (string)($c[$i] ?? '');
                $tipo = preg_match('/^[a-z]{1,20}$/', $fila['tipo'] ?? '') ? $fila['tipo'] : 'otro';
                $d['revisar_por_tipo'][$tipo] = ($d['revisar_por_tipo'][$tipo] ?? 0) + 1;
                if (count($filasControl) < MAXIMO_CASOS) $filasControl[] = $fila;
            }
            break;
        }
        // Una corrida de antes del diagnóstico (WizLink anterior al 12-nov): los casos salen del control
        if (!$hayDiagnostico) $d['casos'] = casos_de_control($filasControl);
    } finally {
        $z->close();
    }
    if ($d['resultado'] === '') $d['resultado'] = 'sin_resumen';
    return $d;
}

/** La pausa que trae el .zip de una corrida guardada (para los datos de la versión 1.4.0, que no la tenían). */
function pausa_de_zip(string $archivo): array
{
    if (!class_exists('ZipArchive') || !is_file($archivo)) return ['', ''];
    $z = new ZipArchive();
    if ($z->open($archivo) !== true) return ['', ''];
    try {
        $estado = $z->getFromName('bitacoras_wizlink/corrida_automatica_estado.txt', 65536);
        return is_string($estado) ? pausa_de_estado($estado) : ['', ''];
    } finally {
        $z->close();
    }
}

/**
 * La pausa de la corrida automática en su archivo de estado (`corrida_automatica_estado.txt` de WizLink: una línea
 * `clave=valor` por dato). Devuelve [pausa, que_hacer]; los dos vacíos si no está en pausa.
 */
function pausa_de_estado(string $texto): array
{
    $v = ['pausa' => '', 'que_hacer' => ''];
    foreach (preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', $texto)) as $l) {
        if (preg_match('/^(pausa|que_hacer)=(.*)$/u', $l, $m)) $v[$m[1]] = mb_substr(trim($m[2]), 0, 400);
    }
    if ($v['pausa'] === '') $v['que_hacer'] = '';
    return [$v['pausa'], $v['que_hacer']];
}

/**
 * Todas las corridas guardadas, la más nueva primero: las que tienen su .zip y las que ya solo tienen su registro
 * ('solo_registro' => true). $desde: solo las que empezaron desde ese momento (0: todas).
 * Los datos que guardó una versión anterior del sitio (sin tipo, segundos ni casos) se rehacen una vez a partir del .zip.
 */
function listar_corridas(int $desde = 0): array
{
    $l = [];
    $rehechas = 0;
    foreach (glob(carpeta('corridas') . '/*/*.json') ?: [] as $f) {
        $clave = basename($f, '.json');
        if (!clave_valida($clave)) continue;
        if ($desde > 0 && momento_de($clave) < $desde) continue;
        $d = leer_json($f);
        if ($d === null) continue;
        $zip = substr($f, 0, -5) . '.zip';
        $hayZip = is_file($zip);
        // Una vez por corrida, y pocas por visita para que la página no tarde (las demás, en la próxima)
        if ($hayZip && (int)($d['forma'] ?? 1) < DATOS_FORMA && $rehechas < 40) { $d = rehacer_datos($clave, $d); $rehechas++; }
        $d['clave'] = $clave;
        $d['solo_registro'] = !$hayZip;
        $l[] = $d;
    }
    usort($l, fn($a, $b) => strcmp($b['clave'], $a['clave']));
    return $l;
}

/** Los datos de una corrida leídos otra vez de su .zip, sin perder cuándo llegó ni su huella. Se guardan. */
function rehacer_datos(string $clave, array $antes): array
{
    $zip = ruta_zip($clave);
    if (!is_file($zip) || !class_exists('ZipArchive')) return $antes;
    $d = datos_de_zip($zip, corrida_de($clave), (string)($antes['version'] ?? ''), (int)($antes['bytes'] ?? filesize($zip)), (string)($antes['sha256'] ?? ''));
    $d['recibida'] = (int)($antes['recibida'] ?? filemtime($zip));
    try { escribir_json(ruta_datos($clave), $d); } catch (Throwable $e) { return $antes; }
    return $d;
}

/** Lo que ocupan todas las corridas guardadas. */
function espacio_usado(): int
{
    $t = 0;
    foreach (glob(carpeta('corridas') . '/*/*.zip') ?: [] as $f) $t += (int)@filesize($f);
    return $t;
}

/**
 * Las «(2)» que guardaron las versiones anteriores del sitio quedaron como «…_(2).zip», un nombre que la lista no mostraba
 * y la limpieza no borraba: se les pone el nombre de ahora («…_2.zip»), con sus datos. Devuelve cuántas renombró.
 */
function renombrar_nombres_viejos(): int
{
    $n = 0;
    foreach (glob(carpeta('corridas') . '/*/*_(*).zip') ?: [] as $f) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}_\d{2}\.\d{2})_\((\d{1,3})\)\.zip$/', basename($f), $m)) continue;
        $nueva = $m[1] . '_' . $m[2];
        $base = dirname($f) . '/';
        if (!clave_valida($nueva) || file_exists($base . $nueva . '.zip')) continue;
        if (!@rename($f, $base . $nueva . '.zip')) continue;
        $datos = substr($f, 0, -4) . '.json';
        if (is_file($datos)) @rename($datos, $base . $nueva . '.json');
        registrar('corrida renombrada: ' . basename($f) . ' ahora es ' . $nueva . '.zip');
        $n++;
    }
    return $n;
}

/**
 * Borra el .zip de las corridas de más de 7 días (Iván, 22-nov) y deja su registro (.json) un año (B1); antes de borrar
 * un .zip, su registro se pone al día para que guarde el diagnóstico en palabras. Devuelve cuántos .zip borró.
 */
function limpiar_viejas(int $ahora): int
{
    renombrar_nombres_viejos();
    $limite = $ahora - dias_guardar() * 86400;
    $limiteRegistro = $ahora - DIAS_REGISTRO * 86400;
    $n = 0;
    foreach (glob(carpeta('corridas') . '/*/*.zip') ?: [] as $f) {
        $clave = basename($f, '.zip');
        if (!clave_valida($clave)) continue;
        $m = momento_de($clave);
        if ($m > 0 && $m < $limite) {
            $datos = leer_json(substr($f, 0, -4) . '.json');
            if ($datos !== null && (int)($datos['forma'] ?? 1) < DATOS_FORMA) rehacer_datos($clave, $datos);
            tapar_registro(substr($f, 0, -4) . '.json');
            @unlink($f);
            $n++;
        }
    }
    foreach (glob(carpeta('corridas') . '/*/*.json') ?: [] as $f) {
        $clave = basename($f, '.json');
        if (!clave_valida($clave)) continue;
        $m = momento_de($clave);
        if ($m > 0 && $m < $limiteRegistro && !is_file(substr($f, 0, -5) . '.zip')) @unlink($f);
    }
    // Lo que quedó a medio recibir (una subida cortada) se borra al día siguiente
    foreach (glob(carpeta('corridas/recibiendo') . '/*.part') ?: [] as $f) if ((int)@filemtime($f) < $ahora - 86400) @unlink($f);
    foreach (glob(carpeta('corridas') . '/*', GLOB_ONLYDIR) ?: [] as $d) @rmdir($d);   // solo si quedó vacía
    return $n;
}

/**
 * El registro que queda un año, sin los números de contrato o reserva (Iván, 22-nov): al borrar el .zip, toda serie de 6
 * o más dígitos en los casos y en el resultado se cambia por 9 del mismo largo. Ya no sirven para diagnosticar.
 */
function tapar_registro(string $archivo): void
{
    $d = leer_json($archivo);
    if ($d === null || !empty($d['tapado'])) return;
    foreach ($d['casos'] ?? [] as $i => $c) {
        foreach (['texto', 'motivo'] as $k) if (isset($c[$k])) $d['casos'][$i][$k] = tapar_numeros((string)$c[$k]);
    }
    if (isset($d['detalle'])) $d['detalle'] = tapar_numeros((string)$d['detalle']);
    $d['tapado'] = true;
    escribir_json($archivo, $d);
}

/** Abre el .zip de una corrida para leerlo; null si no existe o no se puede. */
function abrir_corrida(string $clave): ?ZipArchive
{
    if (!clave_valida($clave) || !class_exists('ZipArchive')) return null;
    $f = ruta_zip($clave);
    if (!is_file($f)) return null;
    $z = new ZipArchive();
    return $z->open($f, ZipArchive::RDONLY) === true ? $z : null;
}

/** Para la pantalla: texto en UTF-8, aunque el archivo venga en Latin-1 (los impresos de Wizard). */
function como_texto(string $bytes): string
{
    $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
    return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
}

/** Si un archivo se puede mostrar como texto (los demás solo se bajan). */
function es_texto(string $nombre): bool
{
    return (bool)preg_match('/\.(txt|csv|log|dat|json|ini|md)$/i', $nombre);
}
