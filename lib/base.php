<?php
// wizlink.bytopo.net · base común de todas las páginas.
//   - Dónde está lo privado: la carpeta wizlink-private, JUNTO a public_html y nunca dentro. Ahí viven las corridas
//     recibidas, el acceso (la huella de la contraseña), el secreto del segundo factor, la huella de la llave de subida
//     y la bitácora. El sitio la crea solo. Nada de eso está en GitHub.
//   - Una configuración opcional (wizlink-private/config.php), solo para cambiar los días que se guardan las corridas.
//   - Los encabezados de seguridad de cada respuesta y la bitácora con fecha y hora UTC.

declare(strict_types=1);

const VERSION_SITIO = '1.1.0';
const ZONA_HORARIA = 'America/Managua';   // la hora que se muestra; la bitácora va en UTC

define('PUBLICO', dirname(__DIR__));
define('PRIVADO', dirname(PUBLICO) . '/wizlink-private');

date_default_timezone_set(ZONA_HORARIA);

/** La configuración privada, o un arreglo vacío si todavía no existe. */
function config(): array
{
    static $c = null;
    if ($c !== null) return $c;
    $archivo = PRIVADO . '/config.php';
    $c = [];
    if (is_file($archivo)) {
        $v = require $archivo;
        if (is_array($v)) $c = $v;
    }
    return $c;
}

/** Días que se guardan las corridas (30 si la configuración no dice otra cosa; entre 7 y 365). */
function dias_guardar(): int
{
    $v = config()['dias_guardar'] ?? 30;
    $n = is_int($v) ? $v : (int)$v;
    return max(7, min(365, $n ?: 30));
}

/** Una carpeta dentro de lo privado; la crea si falta, solo para este usuario del servidor. */
function carpeta(string $nombre): string
{
    $d = PRIVADO . '/' . $nombre;
    if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
        throw new RuntimeException('No se pudo crear la carpeta privada ' . $nombre);
    }
    return $d;
}

/** Una línea en la bitácora del mes: fecha y hora UTC, y lo que pasó. Nunca contraseñas, códigos ni llaves. */
function registrar(string $texto): void
{
    $linea = gmdate('Y-m-d\TH:i:s\Z') . ' ' . str_replace(["\r", "\n"], ' ', $texto) . "\n";
    try {
        @file_put_contents(carpeta('registro') . '/actividad-' . gmdate('Y-m') . '.log', $linea, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('wizlink: no se pudo escribir la bitácora');
    }
}

/** Lee un JSON privado; null si no existe o no se entiende. */
function leer_json(string $archivo): ?array
{
    if (!is_file($archivo)) return null;
    $t = @file_get_contents($archivo);
    if ($t === false) return null;
    $v = json_decode($t, true);
    return is_array($v) ? $v : null;
}

/** Escribe un JSON privado de una sola vez (un archivo temporal y después el cambio de nombre). */
function escribir_json(string $archivo, array $datos): void
{
    $tmp = $archivo . '.tmp' . bin2hex(random_bytes(4));
    $texto = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($texto === false || @file_put_contents($tmp, $texto, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('No se pudo guardar ' . basename($archivo));
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $archivo)) {
        @unlink($tmp);
        throw new RuntimeException('No se pudo guardar ' . basename($archivo));
    }
}

/** Texto seguro para HTML. */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Los encabezados de seguridad de cada respuesta. Las páginas solo cargan lo propio; nada se guarda en el navegador ni
 * en el caché, y ningún buscador las indexa.
 */
function encabezados_seguridad(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, max-age=0');
    header('Strict-Transport-Security: max-age=31536000');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}

/** Responde JSON y termina. */
function responder_json(int $codigo, array $datos): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** La dirección que se pidió termina aquí, con un código y un texto corto. */
function terminar(int $codigo, string $texto): never
{
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    echo $texto;
    exit;
}

/** Fecha y hora de Nicaragua para mostrar: «19 nov 2026, 2:14 p. m.». */
function fecha_hora(int $ts): string
{
    return fecha_corta($ts) . ', ' . hora($ts);
}

function fecha_corta(int $ts): string
{
    static $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return date('j', $ts) . ' ' . $meses[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function hora(int $ts): string
{
    $h = (int)date('G', $ts);
    $h12 = $h % 12 === 0 ? 12 : $h % 12;
    return $h12 . ':' . date('i', $ts) . ($h < 12 ? ' a. m.' : ' p. m.');
}

/** Bytes en palabras: «820 KB», «2.4 MB». */
function peso(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / 1024 / 1024, 1) . ' MB';
    return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
}
