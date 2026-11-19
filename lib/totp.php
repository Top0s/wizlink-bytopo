<?php
// wizlink.bytopo.net · el segundo factor (paso 2): el código de 6 dígitos de una app de autenticación.
//   - Es el estándar abierto TOTP (RFC 6238): sirve cualquier app gratuita (Google Authenticator, Microsoft
//     Authenticator, 2FAS, Aegis). No hay ningún servicio externo ni costo: el sitio y la app calculan el mismo código
//     con un secreto que comparten y con la hora.
//   - El secreto lo crea el sitio la primera vez (alta) y se guarda solo en wizlink-private/estado/totp.json. Se
//     muestra una sola vez, como código QR, para agregarlo a la app.
//   - Un código sirve una sola vez, y se aceptan el de ahora y los de 30 segundos antes o después (por si el reloj del
//     teléfono va un poco corrido).
//   - Cinco códigos equivocados seguidos bloquean la entrada 15 minutos (de uno en uno, con el candado de la entrada).
//   - Si se pierde el teléfono: en el Administrador de archivos de Hostinger se borra wizlink-private/estado/totp.json
//     y, al entrar de nuevo con el usuario y la contraseña, el sitio pide dar de alta la app otra vez. Las sesiones
//     abiertas (también la del teléfono perdido) se cierran solas (lib/sesion.php).
//   - Si totp.json está pero no se puede leer, la entrada se detiene (totp_danado): nunca pide dar de alta otra app.

declare(strict_types=1);

require_once __DIR__ . '/base.php';

const TOTP_PASO = 30;
const TOTP_DIGITOS = 6;
const TOTP_FALLOS = 5;
const TOTP_BLOQUEO = 900;   // segundos

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_codificar(string $bytes): string
{
    $bits = '';
    foreach (str_split($bytes) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $r = '';
    foreach (str_split($bits, 5) as $grupo) $r .= BASE32[bindec(str_pad($grupo, 5, '0'))];
    return $r;
}

function base32_decodificar(string $texto): ?string
{
    $texto = strtoupper(preg_replace('/[\s=-]/', '', $texto));
    $bits = '';
    foreach (str_split($texto) as $c) {
        $i = strpos(BASE32, $c);
        if ($i === false) return null;
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $r = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $r .= chr(bindec($byte));
    return $r;
}

/** El código de un paso de 30 segundos (RFC 6238 con HMAC-SHA1, como todas las apps). */
function totp_codigo(string $secreto, int $paso): string
{
    $contador = pack('N*', 0, $paso);   // 8 bytes, el número más significativo primero
    $hmac = hash_hmac('sha1', $contador, $secreto, true);
    $desde = ord($hmac[19]) & 0x0f;
    $numero = ((ord($hmac[$desde]) & 0x7f) << 24) | (ord($hmac[$desde + 1]) << 16) | (ord($hmac[$desde + 2]) << 8) | ord($hmac[$desde + 3]);
    return str_pad((string)($numero % (10 ** TOTP_DIGITOS)), TOTP_DIGITOS, '0', STR_PAD_LEFT);
}

/** El paso de 30 segundos que corresponde a un momento. */
function totp_paso(int $ts): int
{
    return intdiv($ts, TOTP_PASO);
}

/**
 * Revisa un código contra el secreto: el de ahora, el anterior o el siguiente. Devuelve el paso que coincidió, o null.
 * Un paso igual o anterior al último usado no vale (un código sirve una sola vez).
 */
function totp_revisar(string $secreto, string $codigo, int $ahora, int $ultimoUsado): ?int
{
    $codigo = preg_replace('/\s/', '', $codigo);
    if (!preg_match('/^\d{' . TOTP_DIGITOS . '}$/', $codigo)) return null;
    $p = totp_paso($ahora);
    foreach ([$p - 1, $p, $p + 1] as $paso) {
        if ($paso <= $ultimoUsado) continue;
        if (hash_equals(totp_codigo($secreto, $paso), $codigo)) return $paso;
    }
    return null;
}

function archivo_totp(): string
{
    return carpeta('estado') . '/totp.json';
}

/** El alta guardada ({secreto, alta, ultimo_paso}), o null si todavía no se dio de alta ninguna app. */
function totp_alta(): ?array
{
    $d = leer_json(archivo_totp());
    if ($d === null || !is_string($d['secreto'] ?? null) || base32_decodificar($d['secreto']) === null) return null;
    return $d;
}

/**
 * true si totp.json existe pero no se puede leer o no se entiende (editado a mano, permisos cambiados, una copia a
 * medias). Entonces la entrada se detiene: nunca se ofrece dar de alta otra app, porque cualquiera que sepa la
 * contraseña podría poner la suya. Para darla de alta otra vez se borra el archivo en el Administrador de archivos.
 */
function totp_danado(): bool
{
    return totp_estado()[1];
}

/**
 * totp.json leído una sola vez: [el alta o null, true si está dañado]. Quien decide entre pedir el código y ofrecer el
 * alta usa esto, para que una lectura que falle por un instante nunca lleve al alta.
 */
function totp_estado(): array
{
    $alta = totp_alta();
    return [$alta, $alta === null && file_exists(archivo_totp())];
}

/** Un secreto nuevo de 20 bytes, en base32 (como lo piden las apps). */
function totp_secreto_nuevo(): string
{
    return base32_codificar(random_bytes(20));
}

/** La dirección otpauth:// que lee la app desde el código QR. */
function totp_direccion(string $secreto, string $cuenta): string
{
    $emisor = 'WizLink bytopo';
    return 'otpauth://totp/' . rawurlencode($emisor . ':' . $cuenta)
        . '?secret=' . $secreto . '&issuer=' . rawurlencode($emisor)
        . '&algorithm=SHA1&digits=' . TOTP_DIGITOS . '&period=' . TOTP_PASO;
}

// ------------------------------------------------------------------ bloqueo por códigos equivocados

function archivo_intentos(): string
{
    return carpeta('estado') . '/intentos.json';
}

/** Segundos que faltan para poder intentar otra vez; 0 si no está bloqueado. */
function totp_bloqueado(int $ahora): int
{
    $d = leer_json(archivo_intentos()) ?? [];
    return max(0, (int)($d['bloqueado_hasta'] ?? 0) - $ahora);
}

function totp_fallo(int $ahora): void
{
    $d = leer_json(archivo_intentos()) ?? [];
    $fallos = array_values(array_filter((array)($d['fallos'] ?? []), fn($t) => is_int($t) && $ahora - $t < TOTP_BLOQUEO));
    $fallos[] = $ahora;
    $d['fallos'] = $fallos;
    if (count($fallos) >= TOTP_FALLOS) {
        $d['bloqueado_hasta'] = $ahora + TOTP_BLOQUEO;
        $d['fallos'] = [];
        registrar('segundo factor: ' . TOTP_FALLOS . ' códigos equivocados, entrada bloqueada 15 minutos');
    }
    escribir_json(archivo_intentos(), $d);
}

function totp_acierto(): void
{
    escribir_json(archivo_intentos(), ['fallos' => [], 'bloqueado_hasta' => 0]);
}
