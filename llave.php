<?php
// wizlink.bytopo.net · la llave de subida de WizLink.
//   - Se crea aquí y se muestra UNA sola vez. El sitio guarda solo su huella (SHA-256): ni el sitio puede mostrarla
//     de nuevo.
//   - Se le da a Informática por un canal seguro (nunca por chat ni correo) y se guarda en WizLink, en la máquina
//     virtual: Configuración › Opciones de Informática › «Guardar llave de wizlink.bytopo.net».
//   - Crear una nueva reemplaza la anterior: WizLink deja de poder subir hasta que tenga la nueva.

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/almacen.php';
require_once __DIR__ . '/lib/vista.php';

exigir_sesion();

$nueva = null;
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!peticion_valida($_POST['ficha'] ?? null)) {
        $error = 'La página venció. Vuelva a intentarlo.';
    } elseif (llave_creada() !== null && ($_POST['confirmo'] ?? '') !== 'si') {
        $error = 'Para reemplazar la llave, marque la casilla de confirmación.';
    } else {
        $nueva = llave_nueva();
        registrar('llave de subida nueva creada');
    }
}
$creada = llave_creada();

abrir_pagina('Llave de subida', true, 'llave');
echo '<h1>Llave de subida</h1>
<p class="ayuda">Es la llave con la que WizLink sube cada corrida a este sitio. Solo sirve para subir: no deja ver ni bajar nada.</p>';

if ($nueva !== null) {
    echo aviso('exito', '<p><strong>Llave creada.</strong> Se muestra solo esta vez: cópiela ahora.</p>') . '
<div class="llave-nueva">
  <label for="llave">Llave de subida</label>
  <input id="llave" class="llave" value="' . h($nueva) . '" readonly spellcheck="false">
  <button type="button" class="boton" id="copiar" data-copiar="llave">Copiar</button>
  <p id="copiar-estado" class="ayuda" role="status" aria-live="polite"></p>
</div>
<h2>Qué hacer con ella</h2>
<ol class="pasos">
  <li>Entréguela a Informática por un canal seguro (por ejemplo, un gestor de contraseñas). Nunca por chat ni correo.</li>
  <li>En la máquina virtual, Informática abre WizLink › Configuración › <strong>Opciones de Informática</strong>, presiona <strong>Guardar llave de wizlink.bytopo.net</strong>, la pega y marca <strong>Respaldo en wizlink.bytopo.net</strong>.</li>
  <li>Al terminar la próxima corrida, aparece en <a href="/">Corridas</a>.</li>
</ol>
<script src="/assets/llave.js?v=' . VERSION_SITIO . '"></script>';
} else {
    if ($error !== '') echo aviso('error', '<p>' . h($error) . '</p>');
    if ($creada !== null) {
        echo '<p>Hay una llave vigente, creada el <strong>' . h(fecha_hora($creada)) . '</strong>. No se puede volver a mostrar.</p>
<form method="post" class="formulario">
  <input type="hidden" name="ficha" value="' . h(ficha()) . '">
  ' . aviso('atencion', '<p>Crear una llave nueva reemplaza la vigente: WizLink deja de subir corridas hasta que Informática guarde la nueva.</p>') . '
  <label class="casilla"><input type="checkbox" name="confirmo" value="si" required> Entiendo que la llave vigente deja de servir</label>
  <button type="submit" class="boton boton-secundario">Crear una llave nueva</button>
</form>';
    } else {
        echo '<p>Todavía no hay ninguna llave.</p>
<form method="post" class="formulario">
  <input type="hidden" name="ficha" value="' . h(ficha()) . '">
  <button type="submit" class="boton">Crear la llave de subida</button>
</form>';
    }
}
cerrar_pagina();
