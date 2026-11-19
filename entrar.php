<?php
// wizlink.bytopo.net · la entrada, en dos pasos (Iván, 19-nov: usuario y contraseña, y la app de autenticación).
//   Paso 1: usuario y contraseña (lib/acceso.php). La primera vez, «Crear el acceso», con el código de alta que solo se
//           lee en el Administrador de archivos de Hostinger.
//   Paso 2: el código de 6 dígitos de la app de autenticación (lib/totp.php). La primera vez, el alta: el sitio crea el
//           secreto, lo muestra como código QR y lo guarda cuando la app da el primer código correcto.

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
$ahora = time();
$dir = direccion();
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
$paso = (string)($_GET['paso'] ?? '');

// ---------------------------------------------------------------- paso 1: crear el acceso (la primera vez)
if ($metodo === 'POST' && $paso === 'alta') {
    if (!peticion_valida($_POST['ficha'] ?? null)) {
        $error = 'La página venció. Vuelva a intentarlo.';
    } elseif (($espera = acceso_bloqueado($dir, $ahora)) > 0) {
        $error = 'Demasiados intentos equivocados. Intente de nuevo en ' . max(1, (int)ceil($espera / 60)) . ' minutos.';
    } else {
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $motivo = crear_acceso((string)($_POST['codigo_alta'] ?? ''), $usuario, (string)($_POST['clave'] ?? ''), (string)($_POST['repetir'] ?? ''));
        if ($motivo === null) {
            acceso_acierto($dir);
            marcar_clave($usuario);
            header('Location: /entrar.php');
            exit;
        }
        if (str_starts_with($motivo, 'El código de alta')) acceso_fallo($dir, $ahora);
        $error = $motivo;
    }
}

// ---------------------------------------------------------------- paso 1: usuario y contraseña
if ($metodo === 'POST' && $paso === 'clave') {
    if (!peticion_valida($_POST['ficha'] ?? null)) {
        $error = 'La página venció. Vuelva a intentarlo.';
    } elseif (($espera = acceso_bloqueado($dir, $ahora)) > 0) {
        $error = 'Demasiados intentos equivocados. Intente de nuevo en ' . max(1, (int)ceil($espera / 60)) . ' minutos.';
    } else {
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        if (acceso_correcto($usuario, (string)($_POST['clave'] ?? ''))) {
            acceso_acierto($dir);
            marcar_clave(acceso()['usuario']);
            registrar('entrada: paso 1 correcto desde ' . $dir);
            header('Location: /entrar.php');
            exit;
        }
        acceso_fallo($dir, $ahora);
        registrar('entrada: usuario o contraseña equivocados desde ' . $dir);
        $error = 'Usuario o contraseña incorrectos.';
    }
}

// ---------------------------------------------------------------- paso 2: el código de la app
if ($metodo === 'POST' && $paso === 'codigo') {
    if (!peticion_valida($_POST['ficha'] ?? null)) {
        $error = 'La página venció. Vuelva a escribir el código.';
    } elseif (!clave_reciente()) {
        $error = 'Pasaron más de 10 minutos desde el usuario y la contraseña. Entre de nuevo.';
        $_SESSION = [];
    } elseif (($espera = totp_bloqueado($ahora)) > 0) {
        $error = 'Demasiados códigos equivocados. Intente de nuevo en ' . max(1, (int)ceil($espera / 60)) . ' minutos.';
    } else {
        $codigo = (string)($_POST['codigo'] ?? '');
        $alta = totp_alta();
        if ($alta === null) {
            // Alta: el secreto todavía solo está en la sesión; se guarda con el primer código correcto
            $secretoB32 = (string)($_SESSION['alta_secreto'] ?? '');
            $p = $secretoB32 !== '' ? totp_revisar((string)base32_decodificar($secretoB32), $codigo, $ahora, 0) : null;
            if ($p !== null) {
                escribir_json(archivo_totp(), ['secreto' => $secretoB32, 'alta' => $ahora, 'ultimo_paso' => $p]);
                totp_acierto();
                marcar_completa();
                registrar('segundo factor: alta de la app de autenticación');
                header('Location: /');
                exit;
            }
        } else {
            $p = totp_revisar((string)base32_decodificar($alta['secreto']), $codigo, $ahora, (int)($alta['ultimo_paso'] ?? 0));
            if ($p !== null) {
                $alta['ultimo_paso'] = $p;
                escribir_json(archivo_totp(), $alta);
                totp_acierto();
                marcar_completa();
                registrar('entrada completa (contraseña y código) desde ' . $dir);
                header('Location: /');
                exit;
            }
        }
        totp_fallo($ahora);
        registrar('segundo factor: código equivocado');
        $error = 'El código no es correcto o ya se usó. Escriba el que muestra la app ahora.';
    }
}

// ---------------------------------------------------------------- la página
abrir_pagina('Entrar', false);
echo '<section class="tarjeta tarjeta-entrar">';
$errorHtml = $error !== '' ? aviso('error', '<p>' . h($error) . '</p>') : '';

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
} else {
    $alta = totp_alta();
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
