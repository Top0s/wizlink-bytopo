<?php
// wizlink.bytopo.net · bajar el .zip completo de una corrida, tal como lo mandó WizLink.

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/almacen.php';

exigir_sesion();
encabezados_seguridad();

$clave = (string)($_GET['c'] ?? '');
if (!clave_valida($clave) || !is_file(ruta_zip($clave))) terminar(404, 'No se encontró esa corrida.');
$f = ruta_zip($clave);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="WizLink_' . $clave . '.zip"');
header('Content-Length: ' . filesize($f));
readfile($f);
registrar('bajó la corrida ' . $clave);
