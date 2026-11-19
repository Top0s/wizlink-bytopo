<?php
// wizlink.bytopo.net · la llave de subida de WizLink. Llega de una de dos maneras:
//   1. «Código para WizLink» (Iván, 19-nov: L4; la recomendada, sin nadie en la máquina virtual): el sitio crea un código
//      de un solo uso y lo muestra una vez. Se guarda como secreto WIZLINK_RESPALDO_CODIGO en GitHub (ZaFi-Online/WizLink,
//      entorno «publicacion») y la próxima versión de WizLink lo trae. En su primer respaldo, WizLink crea su propia llave,
//      la guarda cifrada en la máquina virtual y la registra aquí con el código (api/registrar.php). Esa llave espera
//      la confirmación de Iván en esta página (Iván, 19-nov: S1): solo al confirmarla sirve y el código se quema.
//   2. «Llave para pegar a mano»: el sitio la crea y la muestra una vez; Informática la pega en WizLink (Opciones de
//      Informática › «Guardar Llave del respaldo»).
// El sitio guarda solo huellas (SHA-256): ni la llave ni el código se pueden volver a mostrar. Hay una sola llave
// vigente: la última que llegó reemplaza a la anterior.

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/almacen.php';
require_once __DIR__ . '/lib/vista.php';

exigir_sesion();

$nuevaLlave = null;
$nuevoCodigo = null;
$error = '';
$hecho = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $que = (string)($_POST['que'] ?? '');
    if (!peticion_valida($_POST['ficha'] ?? null)) {
        $error = 'La página venció. Vuelva a intentarlo.';
    } elseif ($que === 'codigo') {
        $c = codigo_wizlink();
        if ($c !== null && (int)($c['usado'] ?? 0) === 0 && ($_POST['confirmo'] ?? '') !== 'si') {
            $error = 'Ya hay un código para WizLink sin usar. Para reemplazarlo, marque la casilla de confirmación.';
        } else {
            $nuevoCodigo = codigo_wizlink_nuevo();
            registrar('código para WizLink nuevo creado');
        }
    } elseif ($que === 'confirmar' || $que === 'rechazar') {
        $id = (string)($_POST['id'] ?? '');
        if ($que === 'confirmar' && llave_creada() !== null && ($_POST['confirmo'] ?? '') !== 'si') {
            $error = 'Para reemplazar la llave vigente, marque la casilla de confirmación.';
        } else {
            try {
                $e = con_candado(fn() => $que === 'confirmar' ? confirmar_llave($id, time()) : rechazar_llave($id));
            } catch (Throwable $t) {
                $e = false;
                $error = 'El sitio está ocupado. Vuelva a intentarlo en un momento.';
            }
            if ($e === false) {
                // ya dice el motivo
            } elseif ($e === null) {
                $error = 'Esa llave ya no espera confirmación. Revise la lista otra vez.';
            } else {
                $hecho = $que === 'confirmar' ? 'confirmada' : 'rechazada';
                registrar('llave de WizLink ' . $hecho . ' (llegó desde ' . ($e['desde'] ?? '?') . ')');
            }
        }
    } elseif ($que === 'llave') {
        if (llave_creada() !== null && ($_POST['confirmo'] ?? '') !== 'si') {
            $error = 'Para reemplazar la llave vigente, marque la casilla de confirmación.';
        } else {
            $nuevaLlave = llave_nueva();
            registrar('llave de subida nueva creada para pegar a mano');
        }
    }
}
$creada = llave_creada();
$codigo = codigo_wizlink();
$esperando = llaves_esperando();
$vigente = leer_json(archivo_llave()) ?? [];

abrir_pagina('Llave de subida', true, 'llave');
echo '<h1>Llave de subida</h1>
<p class="ayuda">Es la llave con la que WizLink sube cada corrida a este sitio. Solo sirve para subir: no deja ver ni bajar nada.</p>';
if ($error !== '') echo aviso('error', '<p>' . h($error) . '</p>');
if ($hecho === 'confirmada') echo aviso('exito', '<p><strong>Llave confirmada.</strong> WizLink ya puede subir; las corridas que esperaban llegan con la próxima.</p>');
if ($hecho === 'rechazada') echo aviso('info', '<p><strong>Llave rechazada.</strong> Esa llave no podrá subir nada. El código sigue sirviendo para la máquina virtual.</p>');

// ---------------------------------------------------------------- las que esperan confirmación (S1)
if (count($esperando) > 0) {
    echo '<h2>Esperando confirmación</h2>
<p class="ayuda">WizLink registró su llave con el código. Confírmela solo si la dirección y la corrida son de la máquina virtual de Informática. Mientras espera, las corridas quedan pendientes en WizLink y llegan al confirmarla (hasta 7 días).</p>';
    foreach ($esperando as $e) {
        $intentos = (int)($e['intentos'] ?? 0);
        $intento = $intentos === 0
            ? 'Todavía ninguna'
            : ($intentos === 1 ? '1 vez' : $intentos . ' veces') . '. La última: <strong>' . h((string)$e['ultima']) . '</strong>'
                . ((string)($e['version'] ?? '') !== '' ? ', con WizLink ' . h((string)$e['version']) : '') . ' (' . h(fecha_hora((int)$e['ultimo_intento'])) . ')';
        echo '<div class="tarjeta tarjeta-espera">
  <p class="espera-titulo"><span class="chip chip-atencion"><span aria-hidden="true">⏳</span> Espera confirmación</span></p>
  <dl class="espera-datos">
    <dt>Llegó</dt><dd>' . h(fecha_hora((int)$e['llego'])) . '</dd>
    <dt>Desde la dirección</dt><dd><strong>' . h((string)($e['desde'] ?? '?')) . '</strong></dd>
    <dt>Intentó subir</dt><dd>' . $intento . '</dd>
  </dl>
  <form method="post" class="formulario formulario-espera">
    <input type="hidden" name="ficha" value="' . h(ficha()) . '">
    <input type="hidden" name="id" value="' . h((string)$e['id']) . '">'
        . ($creada !== null ? '<label class="casilla"><input type="checkbox" name="confirmo" value="si"> Reemplazar la llave vigente al confirmar</label>' : '') . '
    <div class="botones">
      <button type="submit" class="boton" name="que" value="confirmar">Confirmar esta llave</button>
      <button type="submit" class="boton boton-secundario" name="que" value="rechazar">Rechazar</button>
    </div>
  </form>
</div>';
    }
}

// ---------------------------------------------------------------- la llave vigente
echo '<h2>Llave vigente</h2>';
if ($creada === null) {
    echo '<p>Todavía no hay ninguna. Llega cuando WizLink use su código, o cuando se cree una para pegar a mano.</p>';
} elseif (llave_origen() === 'wizlink') {
    // fecha_hora termina en «a. m.» o «p. m.»: la frase no lleva otro punto después
    echo aviso('exito', '<p><strong>Registrada por WizLink</strong> con el código para WizLink'
        . ((string)($vigente['desde'] ?? '') !== '' ? ', desde la dirección ' . h((string)$vigente['desde']) : '')
        . ', el ' . h(fecha_hora($creada))
        . (isset($vigente['confirmada']) ? ', y confirmada el ' . h(fecha_hora((int)$vigente['confirmada'])) : '') . '</p>');
} else {
    echo '<p>Creada aquí para pegar a mano, el <strong>' . h(fecha_hora($creada)) . '</strong>; no se puede volver a mostrar.</p>';
}

// ---------------------------------------------------------------- el código para WizLink
echo '<h2>Código para WizLink <span class="etiqueta-recomendado">Recomendado</span></h2>
<p class="ayuda">Sin nadie en la máquina virtual: el código viaja dentro de la próxima versión de WizLink, que crea su propia llave y la registra aquí. Esa llave sirve cuando usted la confirma.</p>';
if ($nuevoCodigo !== null) {
    echo aviso('exito', '<p><strong>Código creado.</strong> Se muestra solo esta vez: cópielo ahora. Vence en ' . CODIGO_DIAS . ' días si no se usa.</p>') . '
<div class="llave-nueva">
  <label for="codigo-nuevo">Código para WizLink</label>
  <input id="codigo-nuevo" class="llave" value="' . h($nuevoCodigo) . '" readonly spellcheck="false">
  <button type="button" class="boton" id="copiar" data-copiar="codigo-nuevo">Copiar</button>
  <p id="copiar-estado" class="ayuda" role="status" aria-live="polite"></p>
</div>
<ol class="pasos">
  <li>En GitHub, <strong>ZaFi-Online/WizLink</strong> › <strong>Settings</strong> › <strong>Environments</strong> › <strong>publicacion</strong>.</li>
  <li>En <strong>Environment secrets</strong>: <strong>Add environment secret</strong>, nombre <code>WIZLINK_RESPALDO_CODIGO</code>, pegar el código y <strong>Add secret</strong>.</li>
  <li>La próxima versión de WizLink lo trae. En su primer respaldo, su llave aparece aquí, en «Esperando confirmación»: revise la dirección y la corrida, y confírmela.</li>
</ol>';
} else {
    if ($codigo !== null && (int)($codigo['usado'] ?? 0) > 0) {
        echo '<p>El último código ya se usó: la llave de WizLink quedó confirmada el <strong>' . h(fecha_hora((int)$codigo['usado'])) . '</strong></p>';
    } elseif ($codigo !== null && time() - (int)($codigo['creado'] ?? 0) > CODIGO_DIAS * 86400) {
        echo aviso('atencion', '<p>El código creado el ' . h(fecha_hora((int)$codigo['creado'])) . ' venció sin que WizLink lo usara.</p>');
    } elseif ($codigo !== null && count($esperando) > 0) {
        echo '<p>El código creado el <strong>' . h(fecha_hora((int)$codigo['creado'])) . '</strong> ya registró ' . (count($esperando) === 1 ? 'una llave, que espera' : count($esperando) . ' llaves, que esperan') . ' su confirmación arriba.</p>';
    } elseif ($codigo !== null) {
        echo aviso('info', '<p>Hay un código creado el <strong>' . h(fecha_hora((int)$codigo['creado'])) . '</strong>, esperando a WizLink.</p>');
    }
    $esperando = $codigo !== null && (int)($codigo['usado'] ?? 0) === 0;
    echo '<form method="post" class="formulario">
  <input type="hidden" name="ficha" value="' . h(ficha()) . '">
  <input type="hidden" name="que" value="codigo">'
        . ($esperando ? '<label class="casilla"><input type="checkbox" name="confirmo" value="si" required> Reemplazar el código que espera a WizLink</label>' : '') . '
  <button type="submit" class="boton">Crear código para WizLink</button>
</form>';
}

// ---------------------------------------------------------------- la llave para pegar a mano
echo '<h2>Llave para pegar a mano</h2>
<p class="ayuda">Solo si alguien de Informática la puede pegar en WizLink, en la máquina virtual.</p>';
if ($nuevaLlave !== null) {
    echo aviso('exito', '<p><strong>Llave creada.</strong> Se muestra solo esta vez: cópiela ahora.</p>') . '
<div class="llave-nueva">
  <label for="llave">Llave de subida</label>
  <input id="llave" class="llave" value="' . h($nuevaLlave) . '" readonly spellcheck="false">
  <button type="button" class="boton" id="copiar" data-copiar="llave">Copiar</button>
  <p id="copiar-estado" class="ayuda" role="status" aria-live="polite"></p>
</div>
<ol class="pasos">
  <li>Entréguela a Informática por un canal seguro (por ejemplo, un gestor de contraseñas). Nunca por chat ni correo.</li>
  <li>En la máquina virtual, Informática abre WizLink › Configuración › <strong>Opciones de Informática</strong> y presiona <strong>Guardar Llave del respaldo</strong>.</li>
</ol>';
} else {
    echo '<form method="post" class="formulario">
  <input type="hidden" name="ficha" value="' . h(ficha()) . '">
  <input type="hidden" name="que" value="llave">'
        . ($creada !== null ? aviso('atencion', '<p>Crear una llave para pegar a mano reemplaza la vigente: WizLink deja de subir corridas hasta que Informática guarde la nueva.</p>')
            . '<label class="casilla"><input type="checkbox" name="confirmo" value="si" required> Entiendo que la llave vigente deja de servir</label>' : '') . '
  <button type="submit" class="boton boton-secundario">Crear llave para pegar a mano</button>
</form>';
}
if ($nuevaLlave !== null || $nuevoCodigo !== null) echo '<script src="/assets/llave.js?v=' . VERSION_SITIO . '"></script>';
cerrar_pagina();
