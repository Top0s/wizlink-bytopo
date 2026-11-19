<?php
// wizlink.bytopo.net · cerrar la sesión (solo con el botón Salir, que manda la ficha del formulario).

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';

iniciar_sesion();
encabezados_seguridad();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !peticion_valida($_POST['ficha'] ?? null)) {
    header('Location: /');
    exit;
}
cerrar_sesion();
header('Location: /entrar.php');
