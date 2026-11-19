<?php
// wizlink.bytopo.net · una corrida: sus datos, accesos directos al resumen, el diagnóstico y la bitácora, y todos sus
// archivos para ver o bajar.

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/almacen.php';
require_once __DIR__ . '/lib/vista.php';

exigir_sesion();

$clave = (string)($_GET['c'] ?? '');
$datos = clave_valida($clave) ? leer_json(ruta_datos($clave)) : null;
if ($datos === null || !is_file(ruta_zip($clave))) {
    http_response_code(404);
    abrir_pagina('Corrida no encontrada', true);
    echo '<p><a href="/">‹ Corridas</a></p><h1>Corrida no encontrada</h1><p>Puede haberse borrado por tener más de ' . dias_guardar() . ' días.</p>';
    cerrar_pagina();
    exit;
}
$corrida = corrida_de($clave);
$m = momento_de($clave);
$sufijo = preg_match('/_(\d+)$/', $clave, $mm) ? ' (' . $mm[1] . ')' : '';   // la segunda del mismo minuto, como en la lista

abrir_pagina('Corrida ' . $corrida, true);
echo '<p class="migas"><a href="/">‹ Corridas</a></p>
<h1>Corrida del ' . h(fecha_hora($m)) . h($sufijo) . '</h1>
<div class="cabecera-corrida">' . etiqueta_resultado($datos) . '</div>';
if (($datos['resultado'] ?? '') === 'detuvo' && ($datos['detalle'] ?? '') !== '') {
    echo aviso('error', '<p>' . h($datos['detalle']) . '</p>');
}
echo '<dl class="ficha">
  <div><dt>Duración</dt><dd>' . h(($datos['duracion'] ?? '') ?: '—') . '</dd></div>
  <div><dt>Consultas</dt><dd>' . (int)($datos['consultas'] ?? 0) . ((int)($datos['revisar'] ?? 0) > 0 ? ' · ' . (int)$datos['revisar'] . ' no OK' : '') . '</dd></div>
  <div><dt>Versión</dt><dd>' . h(($datos['version'] ?? '') ?: '—') . '</dd></div>
  <div><dt>Recibida</dt><dd>' . h(fecha_hora((int)($datos['recibida'] ?? 0))) . '</dd></div>
  <div><dt>Peso</dt><dd>' . h(peso((int)($datos['bytes'] ?? 0))) . ' comprimida · ' . (int)($datos['archivos'] ?? 0) . ' archivos</dd></div>
</dl>
<p><a class="boton" href="/bajar.php?c=' . rawurlencode($clave) . '">Bajar la corrida completa (.zip)</a></p>';

$z = abrir_corrida($clave);
if ($z === null) {
    echo aviso('atencion', '<p>Este servidor no puede abrir archivos .zip (falta la extensión zip de PHP). La corrida se puede bajar completa.</p>');
    cerrar_pagina();
    exit;
}

$archivos = [];
for ($i = 0; $i < $z->numFiles; $i++) {
    $e = $z->statIndex($i);
    if ($e === false || str_ends_with((string)$e['name'], '/')) continue;
    $archivos[] = ['nombre' => (string)$e['name'], 'tam' => (int)$e['size']];
}
$z->close();
usort($archivos, fn($a, $b) => strcmp($a['nombre'], $b['nombre']));

// Los que se revisan primero (docs/DIAGNOSTICAR_CORRIDA.md del repositorio de WizLink)
$primero = [
    $corrida . '/evidencia/resumen.txt' => 'Resumen',
    $corrida . '/evidencia/diagnostico.txt' => 'Diagnóstico',
    $corrida . '/evidencia/bitacora.txt' => 'Bitácora',
    'bitacoras_wizlink/corrida_automatica.txt' => 'Corrida automática',
];
echo '<h2>Para empezar</h2><ul class="accesos">';
foreach ($primero as $n => $titulo) {
    foreach ($archivos as $a) {
        if ($a['nombre'] !== $n) continue;
        echo '<li><a class="acceso" href="' . h(enlace_archivo($clave, $n)) . '">' . h($titulo) . ' <span class="acceso-tam">' . h(peso($a['tam'])) . '</span></a></li>';
    }
}
foreach ($archivos as $a) {
    if (preg_match('#^' . preg_quote($corrida, '#') . '/control_[^/]+\.csv$#', $a['nombre'])) {
        echo '<li><a class="acceso" href="' . h(enlace_archivo($clave, $a['nombre'])) . '">Control <span class="acceso-tam">' . h(peso($a['tam'])) . '</span></a></li>';
    }
}
echo '</ul>';

// Todos los archivos, por carpeta
echo '<h2>Todos los archivos</h2>';
$carpetas = [];
foreach ($archivos as $a) {
    $p = strrpos($a['nombre'], '/');
    $carpetas[$p === false ? '' : substr($a['nombre'], 0, $p)][] = $a;
}
foreach ($carpetas as $c => $lista) {
    $abierta = !str_contains($c, '/pantallas') && !str_contains($c, '/impresos');
    echo '<details class="carpeta"' . ($abierta ? ' open' : '') . '><summary>' . h($c === '' ? '(raíz)' : $c) . ' <span class="carpeta-cuenta">' . count($lista) . ' archivos</span></summary><ul class="archivos">';
    foreach ($lista as $a) {
        $nombre = substr($a['nombre'], strlen($c) + ($c === '' ? 0 : 1));
        $ver = es_texto($a['nombre']) && $a['tam'] <= MAXIMO_VER;
        echo '<li><span class="archivo-nombre">' . h($nombre) . '</span><span class="archivo-tam">' . h(peso($a['tam'])) . '</span>'
            . ($ver ? '<a href="' . h(enlace_archivo($clave, $a['nombre'])) . '">Ver</a>' : '<span class="archivo-sin" aria-hidden="true"></span>')
            . '<a href="' . h(enlace_archivo($clave, $a['nombre'], true)) . '">Bajar<span class="solo-lector"> ' . h($nombre) . '</span></a></li>';
    }
    echo '</ul></details>';
}
cerrar_pagina();

function enlace_archivo(string $clave, string $nombre, bool $bajar = false): string
{
    return '/archivo.php?c=' . rawurlencode($clave) . '&f=' . rawurlencode($nombre) . ($bajar ? '&bajar=1' : '');
}
