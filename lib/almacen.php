<?php
// wizlink.bytopo.net · dónde quedan las corridas que manda WizLink.
//   - Cada corrida llega como un solo .zip (su carpeta completa más las bitácoras de WizLink) y se guarda en
//     wizlink-private/corridas/AAAA-MM/, fuera de public_html: desde internet solo se llega a ellas entrando.
//   - Junto a cada .zip, un .json corto con lo que muestra la lista (resultado, duración, versión, cuántas en REVISAR),
//     leído del resumen y del control de la corrida al recibirla.
//   - La llave de subida: el sitio la crea y guarda solo su huella (SHA-256). Solo sirve para subir: no deja ver,
//     listar ni bajar nada.
//   - Se borran solas las corridas de más de 30 días (dias_guardar en la configuración).

declare(strict_types=1);

require_once __DIR__ . '/base.php';

const MAXIMO_BYTES = 64 * 1024 * 1024;              // por corrida, comprimida
const MAXIMO_DESCOMPRIMIDO = 1024 * 1024 * 1024;    // lo que puede ocupar adentro
const MAXIMO_ARCHIVOS = 65000;
const MAXIMO_VER = 3 * 1024 * 1024;                 // un archivo más grande solo se baja
const LLAVE_FORMA = '/^wzr_[A-Za-z0-9_-]{43}$/';

/** El nombre de la carpeta de una corrida en la PC: «2026-11-19 14.00», o «2026-11-19 14.00 (2)» si hubo dos. */
function corrida_valida(string $c): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2})\.(\d{2})(?: \((\d{1,3})\))?$/', $c, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[4] < 24 && (int)$m[5] < 60;
}

/** El nombre que se usa en el servidor y en las direcciones: «2026-11-19_14.00» o «2026-11-19_14.00_2». */
function clave_de(string $corrida): string
{
    return preg_replace('/ \((\d+)\)$/', '_$1', str_replace(' ', '_', $corrida), 1) ?? '';
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

/** Crea una llave nueva (la anterior deja de servir) y la devuelve; solo se guarda su huella. */
function llave_nueva(): string
{
    $llave = 'wzr_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    escribir_json(archivo_llave(), ['huella' => hash('sha256', $llave), 'creada' => time()]);
    return $llave;
}

/** La fecha en que se creó la llave vigente, o null si no hay. */
function llave_creada(): ?int
{
    $d = leer_json(archivo_llave());
    return ($d !== null && is_string($d['huella'] ?? null)) ? (int)($d['creada'] ?? 0) : null;
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
            foreach ($lineas as $l) {
                if (preg_match('/Duración: (\d+ min \d+ s)/u', $l, $m)) $d['duracion'] = $m[1];
                if (str_starts_with($l, 'Resultado: ')) {
                    $r = trim(substr($l, strlen('Resultado: ')));
                    $d['resultado'] = str_starts_with($r, 'TERMINÓ') ? 'termino' : 'detuvo';
                    $d['detalle'] = mb_substr($r, 0, 300);
                }
            }
        }
        // El control: una fila por consulta, con su estado (OK, REVISAR, NO SE PUDO, SE DETUVO, NO SE CORRIÓ)
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
                if (($c[$col] ?? 'OK') !== 'OK') $d['revisar']++;
            }
            break;
        }
    } finally {
        $z->close();
    }
    if ($d['resultado'] === '') $d['resultado'] = 'sin_resumen';
    return $d;
}

/** Todas las corridas guardadas, la más nueva primero. */
function listar_corridas(): array
{
    $l = [];
    foreach (glob(carpeta('corridas') . '/*/*.json') ?: [] as $f) {
        $clave = basename($f, '.json');
        if (!clave_valida($clave) || !is_file(substr($f, 0, -5) . '.zip')) continue;
        $d = leer_json($f);
        if ($d === null) continue;
        $d['clave'] = $clave;
        $l[] = $d;
    }
    usort($l, fn($a, $b) => strcmp($b['clave'], $a['clave']));
    return $l;
}

/** Lo que ocupan todas las corridas guardadas. */
function espacio_usado(): int
{
    $t = 0;
    foreach (glob(carpeta('corridas') . '/*/*.zip') ?: [] as $f) $t += (int)@filesize($f);
    return $t;
}

/** Borra las corridas de más días que los configurados. Devuelve cuántas borró. */
function limpiar_viejas(int $ahora): int
{
    $limite = $ahora - dias_guardar() * 86400;
    $n = 0;
    foreach (glob(carpeta('corridas') . '/*/*.zip') ?: [] as $f) {
        $clave = basename($f, '.zip');
        if (!clave_valida($clave)) continue;
        $m = momento_de($clave);
        if ($m > 0 && $m < $limite) {
            @unlink($f);
            @unlink(substr($f, 0, -4) . '.json');
            $n++;
        }
    }
    // Lo que quedó a medio recibir (una subida cortada) se borra al día siguiente
    foreach (glob(carpeta('corridas/recibiendo') . '/*.part') ?: [] as $f) if ((int)@filemtime($f) < $ahora - 86400) @unlink($f);
    foreach (glob(carpeta('corridas') . '/*', GLOB_ONLYDIR) ?: [] as $d) @rmdir($d);   // solo si quedó vacía
    return $n;
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
