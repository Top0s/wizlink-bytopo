<?php
// wizlink.bytopo.net · la sesión de quien entra.
//   - Se entra en dos pasos: primero el usuario y la contraseña (lib/acceso.php) y después el código de 6 dígitos de
//     la app de autenticación (lib/totp.php). Hasta completar los dos, ninguna página muestra corridas.
//   - La sesión vence a los 30 minutos sin uso y, de todos modos, a las 12 horas.
//   - Cada sesión guarda la huella del acceso y de la app con que se entró. Si después se borra o se cambia acceso.json
//     o totp.json (contraseña olvidada, teléfono perdido), todas las sesiones abiertas se cierran en su próximo clic.
//   - Cada formulario lleva una ficha (CSRF) y además se revisa que venga de este mismo sitio.

declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/totp.php';

const MINUTOS_SIN_USO = 30;
const HORAS_MAXIMO = 12;

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_save_path(carpeta('estado/sesiones'));
    session_name('wizlink_sesion');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)(HORAS_MAXIMO * 3600));
    ini_set('session.gc_probability', '1');   // las sesiones viejas se borran solas (la carpeta es propia)
    ini_set('session.gc_divisor', '50');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $ahora = time();
    $vencida = (isset($_SESSION['ultimo']) && $ahora - (int)$_SESSION['ultimo'] > MINUTOS_SIN_USO * 60)
        || (isset($_SESSION['inicio']) && $ahora - (int)$_SESSION['inicio'] > HORAS_MAXIMO * 3600);
    // Cambió el acceso o la app desde que se entró (o es una sesión de antes de esta revisión, sin huella): se cierra
    $cambio = (!empty($_SESSION['completa']) && !hash_equals(huella_credenciales(), (string)($_SESSION['huella_credenciales'] ?? '')))
        || (empty($_SESSION['completa']) && !empty($_SESSION['usuario']) && !hash_equals(huella_acceso(), (string)($_SESSION['huella_acceso'] ?? '')));
    if ($cambio && !empty($_SESSION['completa'])) registrar('sesión cerrada: cambió el acceso o la app de autenticación');
    if ($vencida || $cambio) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['ultimo'] = $ahora;
}

/** La huella del acceso vigente (usuario y huella de la contraseña), o '' si no hay. Nunca sale de la sesión. */
function huella_acceso(): string
{
    $a = acceso();
    return $a === null ? '' : hash('sha256', json_encode([$a['usuario'], $a['huella'], (int)($a['creado'] ?? 0)]));
}

/**
 * La huella del acceso y de la app de autenticación vigentes. No cambia con cada entrada (el último código usado no
 * cuenta); sí cambia al borrar o crear de nuevo acceso.json o totp.json.
 */
function huella_credenciales(): string
{
    $t = totp_alta();
    return hash('sha256', json_encode([huella_acceso(), $t === null ? '' : $t['secreto'], $t === null ? 0 : (int)($t['alta'] ?? 0)]));
}

/** La ficha de los formularios de esta sesión. */
function ficha(): string
{
    if (empty($_SESSION['ficha'])) $_SESSION['ficha'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['ficha'];
}

/** true si la petición viene de este mismo sitio y trae la ficha correcta. */
function peticion_valida(?string $fichaRecibida): bool
{
    $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
    $esperado = 'https://' . ($_SERVER['HTTP_HOST'] ?? '');
    if ($origen !== '' && $origen !== $esperado) return false;
    $sitio = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($sitio !== '' && $sitio !== 'same-origin') return false;
    return is_string($fichaRecibida) && $fichaRecibida !== '' && !empty($_SESSION['ficha'])
        && hash_equals((string)$_SESSION['ficha'], $fichaRecibida);
}

/** Paso 1 terminado: el usuario y la contraseña son correctos. */
function marcar_clave(string $usuario): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'usuario' => $usuario,
        'clave_en' => time(),
        'inicio' => time(),
        'ultimo' => time(),
        'huella_acceso' => huella_acceso(),
    ];
}

/** true si el paso 1 fue hace menos de 10 minutos (para pedir el código). */
function clave_reciente(): bool
{
    return !empty($_SESSION['usuario']) && time() - (int)($_SESSION['clave_en'] ?? 0) < 600;
}

/** Paso 2 terminado: el código de la app es correcto. */
function marcar_completa(): void
{
    session_regenerate_id(true);
    $_SESSION['completa'] = true;
    $_SESSION['huella_credenciales'] = huella_credenciales();   // después de guardar totp.json (el alta la crea)
    unset($_SESSION['alta_secreto']);
}

/** true con los dos pasos hechos y con el mismo acceso y la misma app con que se entró (iniciar_sesion lo revisa). */
function sesion_completa(): bool
{
    return !empty($_SESSION['completa']) && !empty($_SESSION['usuario'])
        && hash_equals(huella_credenciales(), (string)($_SESSION['huella_credenciales'] ?? ''));
}

/** Las páginas con datos: sin los dos pasos, a la entrada. */
function exigir_sesion(): void
{
    iniciar_sesion();
    if (!sesion_completa()) {
        header('Location: /entrar.php');
        exit;
    }
}

function cerrar_sesion(): void
{
    iniciar_sesion();
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
    setcookie('wizlink_sesion', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}
