<?php
// wizlink.bytopo.net · el paso 1 de la entrada: usuario y contraseña (Iván, 19-nov: sin Google).
//   - La contraseña nunca se guarda: solo su huella con password_hash (bcrypt), en wizlink-private/estado/acceso.json.
//   - La primera vez no hay acceso: el sitio crea un «código de alta» en wizlink-private/estado/codigo_alta.txt, que solo
//     se puede leer en el Administrador de archivos de Hostinger. Quien lo tiene crea el usuario y la contraseña. Así
//     nadie más puede adelantarse a crear el acceso, aunque llegue al sitio antes.
//   - Para cambiar la contraseña o el usuario: borrar acceso.json en el Administrador de archivos. El sitio crea otro
//     código de alta. La app de autenticación sigue igual (para cambiarla, borrar también totp.json).
//   - Cinco intentos equivocados desde una misma dirección (en IPv6, la misma red /64) bloquean esa dirección 15 minutos.
//     Los intentos se atienden de uno en uno (el candado de la entrada, en entrar.php): varios a la vez no se saltan el tope.

declare(strict_types=1);

require_once __DIR__ . '/base.php';

const CLAVE_MINIMA = 12;
const ACCESO_FALLOS = 5;
const ACCESO_BLOQUEO = 900;   // segundos

function archivo_acceso(): string
{
    return carpeta('estado') . '/acceso.json';
}

function archivo_codigo_alta(): string
{
    return carpeta('estado') . '/codigo_alta.txt';
}

/** El acceso guardado ({usuario, huella, creado}), o null si todavía no se creó. */
function acceso(): ?array
{
    $d = leer_json(archivo_acceso());
    if ($d === null || !is_string($d['usuario'] ?? null) || !is_string($d['huella'] ?? null)) return null;
    return $d;
}

/** El código de alta (lo crea si falta). Solo existe mientras no hay acceso. */
function codigo_alta(): string
{
    $f = archivo_codigo_alta();
    $c = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    if (preg_match('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){3}$/', $c)) return $c;
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $c = '';
    for ($i = 0; $i < 16; $i++) $c .= ($i > 0 && $i % 4 === 0 ? '-' : '') . $a[random_int(0, 31)];
    if (@file_put_contents($f, $c . "\n", LOCK_EX) === false) throw new RuntimeException('No se pudo crear el código de alta');
    @chmod($f, 0600);
    registrar('acceso: se creó un código de alta nuevo');
    return $c;
}

/** El usuario: letras, números, punto, guion o guion bajo, de 3 a 40. */
function usuario_valido(string $u): bool
{
    return (bool)preg_match('/^[A-Za-z0-9._-]{3,40}$/', $u);
}

/**
 * Crea el acceso con el código de alta. Devuelve null si quedó creado, o el motivo para la pantalla.
 */
function crear_acceso(string $codigo, string $usuario, string $clave, string $repetir): ?string
{
    if (acceso() !== null) return 'El acceso ya existe. Entre con su usuario y contraseña.';
    $codigo = strtoupper(trim($codigo));
    if (!hash_equals(codigo_alta(), $codigo)) return 'El código de alta no es correcto.';
    if (!usuario_valido($usuario)) return 'El usuario lleva de 3 a 40 letras o números (también punto, guion o guion bajo), sin espacios.';
    if (mb_strlen($clave) < CLAVE_MINIMA) return 'La contraseña necesita al menos ' . CLAVE_MINIMA . ' caracteres.';
    if (!hash_equals($clave, $repetir)) return 'Las dos contraseñas no son iguales.';
    if (strcasecmp($clave, $usuario) === 0) return 'La contraseña no puede ser igual al usuario.';
    escribir_json(archivo_acceso(), ['usuario' => $usuario, 'huella' => password_hash($clave, PASSWORD_DEFAULT), 'creado' => time()]);
    @unlink(archivo_codigo_alta());
    registrar('acceso: se creó el usuario y la contraseña');
    return null;
}

/** true si el usuario y la contraseña son los guardados. */
function acceso_correcto(string $usuario, string $clave): bool
{
    $a = acceso();
    if ($a === null) return false;
    $ok = password_verify($clave, $a['huella']);   // siempre, para que tarde lo mismo con un usuario equivocado
    return $ok && hash_equals(strtolower($a['usuario']), strtolower(trim($usuario)));
}

// ------------------------------------------------------------------ bloqueo por dirección

function archivo_intentos_acceso(): string
{
    return carpeta('estado') . '/intentos_acceso.json';
}

/** La dirección de quien entra, solo para contar intentos (el CDN del sitio está apagado). */
function direccion(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '?');
}

/**
 * Con qué se cuentan los intentos de una dirección: la dirección IPv4 tal cual; en IPv6, su red /64 (quien tiene una
 * dirección IPv6 suele tener miles en la misma red, y cambiar de una a otra no debe dar cinco intentos más). Solo se
 * guarda su huella.
 */
function clave_intentos(string $dir): string
{
    if (filter_var($dir, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $bin = inet_pton($dir);
        if ($bin !== false && strlen($bin) === 16) $dir = inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return hash('sha256', $dir);
}

/**
 * Segundos que faltan para que esa dirección pueda intentar otra vez; 0 si no está bloqueada. Se llama con el candado de
 * la entrada tomado (entrar.php), igual que acceso_fallo y acceso_acierto: así los intentos a la vez no se pisan.
 */
function acceso_bloqueado(string $dir, int $ahora): int
{
    $d = leer_json(archivo_intentos_acceso()) ?? [];
    $e = $d[clave_intentos($dir)] ?? [];
    return max(0, (int)($e['hasta'] ?? 0) - $ahora);
}

function acceso_fallo(string $dir, int $ahora): void
{
    $d = leer_json(archivo_intentos_acceso()) ?? [];
    // Se olvidan las direcciones que no intentan nada hace más de un día
    foreach ($d as $k => $e) if (is_array($e) && $ahora - (int)($e['ultimo'] ?? 0) > 86400) unset($d[$k]);
    $k = clave_intentos($dir);
    $e = is_array($d[$k] ?? null) ? $d[$k] : [];
    $fallos = array_values(array_filter((array)($e['fallos'] ?? []), fn($t) => is_int($t) && $ahora - $t < ACCESO_BLOQUEO));
    $fallos[] = $ahora;
    $e = ['fallos' => $fallos, 'hasta' => (int)($e['hasta'] ?? 0), 'ultimo' => $ahora];
    if (count($fallos) >= ACCESO_FALLOS) {
        $e = ['fallos' => [], 'hasta' => $ahora + ACCESO_BLOQUEO, 'ultimo' => $ahora];
        registrar('acceso: ' . ACCESO_FALLOS . ' intentos equivocados desde ' . $dir . ', bloqueada 15 minutos');
    }
    $d[$k] = $e;
    escribir_json(archivo_intentos_acceso(), $d);
}

function acceso_acierto(string $dir): void
{
    $d = leer_json(archivo_intentos_acceso()) ?? [];
    unset($d[clave_intentos($dir)]);
    escribir_json(archivo_intentos_acceso(), $d);
}
