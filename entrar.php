<?php
// wizlink.bytopo.net · la entrada, en dos pasos (Iván, 19-nov: usuario y contraseña, y la app de autenticación).
//   Paso 1: usuario y contraseña (lib/acceso.php). La primera vez, «Crear el acceso», con el código de alta que solo se
//           lee en el Administrador de archivos de Hostinger.
//   Paso 2: el código de 6 dígitos de la app de autenticación (lib/totp.php). La primera vez, el alta: el sitio crea el
//           secreto, lo muestra como código QR y lo guarda cuando la app da el primer código correcto. Si totp.json está
//           pero no se puede leer, la entrada se detiene (nunca se ofrece el alta de otra app).
//   Cada intento se atiende de uno en uno (el candado de la entrada), de la revisión del bloqueo hasta anotar el
//   resultado: muchos intentos en paralelo no se saltan el tope de cinco.

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/acceso.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/vista.php';

iniciar_sesion();

if (sesion_completa()) {
    header('Location: /');
    exit;
}

$error = '';
$dir = direccion();
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
$paso = (string)($_GET['paso'] ?? '');

// ---------------------------------------------------------------- los tres pasos (cada uno devuelve [error, a dónde ir])

/** Paso 1, la primera vez: crear el acceso con el código de alta. */
function paso_alta(string $dir, int $ahora): array
{
    if (($espera = acceso_bloqueado($dir, $ahora)) > 0) {
        return ['Demasiados intentos equivocados. Intente de nuevo en ' . max(1, (int)ceil($espera / 60)) . ' minutos.', null];
    }
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $motivo = crear_acceso((string)($_POST['codigo_alta'] ?? ''), $usuario, (string)($_POST['clave'] ?? ''), (string)($_POST['repetir'] ?? ''));
    if ($motivo === null) {
        acceso_acierto($dir);
        marcar_clave($usuario);
        return ['', '/entrar.php'];
    }
    if (str_starts_with($motivo, 'El código de alta')) acceso_fallo($dir, $ahora);
    return [$motivo, null];
}

/** Paso 1: usuario y contraseña. */
function paso_clave(string $dir, int $ahora): array
{
    if (($espera = acceso_bloqueado($dir, $ahora)) > 0) {
        return ['Demasiados intentos equivocados. Intente de nuevo en ' . max(1, (int)ceil($espera / 60)) . ' minutos.', null];
    }
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    if (acceso_correcto($usuario, (string)($_POST['clave'] ?? ''))) {
        acceso_acierto($dir);
        marcar_clave(acceso()['usuario']);
        registrar('entrada: paso 1 correcto desde ' . $dir);
        return ['', '/entrar.php'];
    }
    acceso_fallo($dir, $ahora);
    registrar('entrada: usuario o contraseña equivocados desde ' . $dir);
    return ['Usuario o contraseña incorrectos.', null];
}

/** Paso 2: el código de la app (o el alta de la app, la primera vez). */
function paso_codigo(string $dir, int $ahora): array
{
    if (!clave_reciente()) {
        $_SESSION = [];
        return ['Pasaron más de 10 minutos desde el usuario y la contraseña. Entre de nuevo.', null];
    }
    [$alta, $danado] = totp_estado();   // una sola lectura de totp.json para decidir entre el código y el alta
    if ($danado) {
        // La página ya lo explica; no cuenta como un código equivocado
        registrar('segundo factor: totp.json no se puede leer; entrada detenida');
        return ['', null];
    }
    if (($espera = totp_bloqueado($ahora)) > 0) {
        return ['Demasiados códigos equivocados. Intente de nuevo en ' . max(1, (int)ceil($espera / 60)) . ' minutos.', null];
    }
    $codigo = (string)($_POST['codigo'] ?? '');
    if ($alta === null) {
        // Alta: el secreto todavía solo está en la sesión; se guarda con el primer código correcto
        $secretoB32 = (string)($_SESSION['alta_secreto'] ?? '');
        $p = $secretoB32 !== '' ? totp_revisar((string)base32_decodificar($secretoB32), $codigo, $ahora, 0) : null;
        if ($p !== null) {
            escribir_json(archivo_totp(), ['secreto' => $secretoB32, 'alta' => $ahora, 'ultimo_paso' => $p]);
            totp_acierto();
            marcar_completa();
            registrar('segundo factor: alta de la app de autenticación');
            return ['', '/'];
        }
    } else {
        $p = totp_revisar((string)base32_decodificar($alta['secreto']), $codigo, $ahora, (int)($alta['ultimo_paso'] ?? 0));
        if ($p !== null) {
            $alta['ultimo_paso'] = $p;
            escribir_json(archivo_totp(), $alta);
            totp_acierto();
            marcar_completa();
            registrar('entrada completa (contraseña y código) desde ' . $dir);
            return ['', '/'];
        }
    }
    totp_fallo($ahora);
    registrar('segundo factor: código equivocado');
    return ['El código no es correcto o ya se usó. Escriba el que muestra la app ahora.', null];
}

if ($metodo === 'POST' && in_array($paso, ['alta', 'clave', 'codigo'], true)) {
    if (!peticion_valida($_POST['ficha'] ?? null)) {
        $error = $paso === 'codigo' ? 'La página venció. Vuelva a escribir el código.' : 'La página venció. Vuelva a intentarlo.';
    } else {
        // Un intento a la vez, de principio a fin: revisar el bloqueo, comprobar y anotar el resultado. Así, muchos
        // intentos en paralelo (cada uno con su sesión) no pueden saltarse el tope de cinco.
        try {
            [$error, $ir] = con_candado_archivo('entrada.lock', fn() => match ($paso) {
                'alta' => paso_alta($dir, time()),
                'clave' => paso_clave($dir, time()),
                'codigo' => paso_codigo($dir, time()),
            });
        } catch (Throwable $e) {
            registrar('entrada con error: ' . $e->getMessage());
            [$error, $ir] = ['El sitio no pudo revisar la entrada. Vuelva a intentarlo en un momento.', null];
        }
        if ($ir !== null) {
            header('Location: ' . $ir);
            exit;
        }
    }
}

// ---------------------------------------------------------------- la página
abrir_pagina('Entrar', false);
echo '<section class="tarjeta tarjeta-entrar">';
$errorHtml = $error !== '' ? aviso('error', '<p>' . h($error) . '</p>') : '';
[$altaApp, $appDanada] = totp_estado();   // una sola lectura de totp.json para elegir la pantalla del paso 2

if (acceso() === null) {
    codigo_alta();   // lo crea si falta, para que se pueda leer en el Administrador de archivos
    echo '<h1>Crear el acceso</h1>
<p class="ayuda">Paso 1 de 2 · Se hace una sola vez. El código de alta está en el Administrador de archivos de Hostinger: carpeta <code>wizlink-private</code> › <code>estado</code> › <code>codigo_alta.txt</code>.</p>' . $errorHtml . '
<form method="post" action="/entrar.php?paso=alta" class="formulario">
  <input type="hidden" name="ficha" value="' . h(ficha()) . '">
  <div><label for="codigo_alta">Código de alta</label>
  <input id="codigo_alta" name="codigo_alta" class="codigo-alta" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX" required></div>
  <div><label for="usuario">Usuario</label>
  <input id="usuario" name="usuario" autocomplete="username" spellcheck="false" required minlength="3" maxlength="40"></div>
  <div><label for="clave">Contraseña nueva</label>
  <input id="clave" name="clave" type="password" autocomplete="new-password" required minlength="' . CLAVE_MINIMA . '">
  <p class="ayuda">Al menos ' . CLAVE_MINIMA . ' caracteres. Guárdela en su gestor de contraseñas.</p></div>
  <div><label for="repetir">Repetir la contraseña</label>
  <input id="repetir" name="repetir" type="password" autocomplete="new-password" required minlength="' . CLAVE_MINIMA . '"></div>
  <button type="submit" class="boton">Crear el acceso</button>
</form>';
} elseif (!clave_reciente()) {
    echo '<h1>Entrar</h1>
<p class="ayuda">Paso 1 de 2 · Usuario y contraseña.</p>' . $errorHtml . '
<form method="post" action="/entrar.php?paso=clave" class="formulario">
  <input type="hidden" name="ficha" value="' . h(ficha()) . '">
  <div><label for="usuario">Usuario</label>
  <input id="usuario" name="usuario" autocomplete="username" spellcheck="false" required autofocus></div>
  <div><label for="clave">Contraseña</label>
  <input id="clave" name="clave" type="password" autocomplete="current-password" required></div>
  <button type="submit" class="boton">Continuar</button>
</form>';
} elseif ($appDanada) {
    // totp.json está pero no se puede leer: la entrada se detiene, sin ofrecer el alta de otra app (lib/totp.php)
    echo '<h1>Código de verificación</h1>
<p class="ayuda">Paso 2 de 2.</p>' . aviso('error', '<p><strong>No se puede leer la configuración de la app de autenticación.</strong></p>'
        . '<p>Por seguridad, la entrada queda detenida. Para configurar la app otra vez, borre <code>wizlink-private</code> › <code>estado</code> › '
        . '<code>totp.json</code> en el Administrador de archivos de Hostinger y vuelva a entrar.</p>') . '
<form method="post" action="/salir.php" class="otra-cuenta"><input type="hidden" name="ficha" value="' . h(ficha()) . '"><button type="submit" class="enlace-boton">Volver a empezar</button></form>';
} else {
    $alta = $altaApp;
    if ($alta === null) {
        if (empty($_SESSION['alta_secreto'])) $_SESSION['alta_secreto'] = totp_secreto_nuevo();
        $secreto = (string)$_SESSION['alta_secreto'];
        echo '<h1>Configurar la app de autenticación</h1>
<p class="ayuda">Paso 2 de 2 · Se hace una sola vez. Sirve cualquier app gratuita: Google Authenticator, Microsoft Authenticator, 2FAS o Aegis.</p>
<ol class="pasos">
  <li>En la app, elija <strong>Agregar cuenta</strong> o <strong>+</strong> y luego <strong>Escanear código QR</strong>.</li>
  <li>Escanee este código:
    <div class="qr" id="qr" data-texto="' . h(totp_direccion($secreto, (string)$_SESSION['usuario'])) . '" role="img" aria-label="Código QR para agregar WizLink bytopo a la app de autenticación"></div>
    <details class="manual"><summary>No puedo escanear: escribir la clave a mano</summary>
      <p>Cuenta: <strong>WizLink bytopo</strong> · Tipo: <strong>basada en el tiempo</strong></p>
      <p class="clave">' . h(trim(chunk_split($secreto, 4, ' '))) . '</p>
    </details>
  </li>
  <li>Escriba el código de 6 dígitos que muestra la app para confirmar.</li>
</ol>';
    } else {
        echo '<h1>Código de verificación</h1>
<p class="ayuda">Paso 2 de 2 · Abra su app de autenticación y escriba el código de 6 dígitos de <strong>WizLink bytopo</strong>.</p>';
    }
    echo $errorHtml . '<form method="post" action="/entrar.php?paso=codigo" class="formulario" autocomplete="off">
  <input type="hidden" name="ficha" value="' . h(ficha()) . '">
  <div><label for="codigo">Código de 6 dígitos</label>
  <input id="codigo" name="codigo" class="campo-codigo" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus></div>
  <button type="submit" class="boton">' . ($alta === null ? 'Confirmar' : 'Entrar') . '</button>
</form>
<form method="post" action="/salir.php" class="otra-cuenta"><input type="hidden" name="ficha" value="' . h(ficha()) . '"><button type="submit" class="enlace-boton">Volver a empezar</button></form>';
    if ($alta === null) echo '<script src="/assets/qrcode.js?v=' . VERSION_SITIO . '"></script><script src="/assets/qr.js?v=' . VERSION_SITIO . '"></script>';
}
echo '</section>';
cerrar_pagina();
