<?php
// wizlink.bytopo.net · un archivo de una corrida: se muestra como texto (resumen, diagnóstico, bitácora, CSV,
// pantallas e impresos) o se baja tal cual.

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/almacen.php';
require_once __DIR__ . '/lib/vista.php';

exigir_sesion();

$clave = (string)($_GET['c'] ?? '');
$nombre = (string)($_GET['f'] ?? '');
$bajar = ($_GET['bajar'] ?? '') === '1';

$z = nombre_interno_valido($nombre) ? abrir_corrida($clave) : null;
$e = $z !== null ? $z->statName($nombre) : false;
if ($z === null || $e === false) {
    if ($z !== null) $z->close();
    encabezados_seguridad();
    terminar(404, 'No se encontró ese archivo.');
}
$tam = (int)$e['size'];

if ($bajar || !es_texto($nombre) || $tam > MAXIMO_VER) {
    $flujo = $z->getStream($nombre);
    if ($flujo === false) terminar(500, 'No se pudo leer el archivo.');
    encabezados_seguridad();
    $archivo = preg_replace('/[^A-Za-z0-9._() -]/', '_', basename($nombre)) ?: 'archivo';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $archivo . '"; filename*=UTF-8\'\'' . rawurlencode(basename($nombre)));
    header('Content-Length: ' . $tam);
    fpassthru($flujo);
    fclose($flujo);
    $z->close();
    registrar('bajó un archivo de ' . $clave);
    exit;
}

$texto = como_texto((string)$z->getFromName($nombre));
$z->close();

abrir_pagina(basename($nombre), true);
echo '<p class="migas"><a href="/">‹ Corridas</a> · <a href="/corrida.php?c=' . rawurlencode($clave) . '">' . h(corrida_de($clave)) . '</a></p>
<h1 class="titulo-archivo">' . h(basename($nombre)) . '</h1>
<p class="ayuda">' . h($nombre) . ' · ' . h(peso($tam)) . ' · <a href="/archivo.php?c=' . rawurlencode($clave) . '&amp;f=' . rawurlencode($nombre) . '&amp;bajar=1">Bajar</a></p>
<pre class="texto" tabindex="0">' . h($texto) . '</pre>';
cerrar_pagina();
