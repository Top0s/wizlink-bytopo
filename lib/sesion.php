<?php
// wizlink.bytopo.net · la sesión de quien entra.
//   - Se entra en dos pasos: primero el usuario y la contraseña (lib/acceso.php) y después el código de 6 dígitos de
//     la app de autenticación (lib/totp.php). Hasta completar los dos, ninguna página muestra corridas.
//   - La sesión vence a los 30 minutos sin uso y, de todos modos, a las 12 horas.
//   - Cada formulario lleva una ficha (CSRF) y además se revisa que venga de este mismo sitio.

declare(strict_types=1);

require_once __DIR__ . '/base.php';

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
    if ($vencida) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['ultimo'] = $ahora;
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
    unset($_SESSION['alta_secreto']);
}

function sesion_completa(): bool
{
    return !empty($_SESSION['completa']) && !empty($_SESSION['usuario']);
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
