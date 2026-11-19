<?php
// wizlink.bytopo.net · la lista de corridas recibidas, por día, la más nueva primero.

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/almacen.php';
require_once __DIR__ . '/lib/vista.php';
require_once __DIR__ . '/lib/horario.php';

exigir_sesion();

$todas = listar_corridas();
$soloProblemas = ($_GET['ver'] ?? '') === 'problemas';
$lista = $soloProblemas
    ? array_values(array_filter($todas, fn($d) => ($d['resultado'] ?? '') !== 'termino' || (int)($d['revisar'] ?? 0) > 0))
    : $todas;

abrir_pagina('', true, 'corridas', true);
// A la izquierda la lista; a la derecha, el horario de las corridas (Iván, 19-nov: A1). En el teléfono el horario va al
// final, y arriba queda una línea con la próxima corrida y un enlace a él.
$ahora = time();
echo '<div class="con-horario"><div class="con-horario-lista">';
echo '<h1>Corridas recibidas</h1>';

$esperando = count(llaves_esperando());
if ($esperando > 0) {
    echo aviso('atencion', '<p><strong>' . ($esperando === 1 ? 'Una llave de WizLink espera' : $esperando . ' llaves de WizLink esperan') . ' su confirmación.</strong> '
        . 'Mientras tanto, las corridas no llegan: <a href="/llave.php">revisar en Llave de subida</a>.</p>');
} elseif (llave_creada() === null) {
    echo aviso('atencion', '<p><strong>Todavía no hay llave de subida.</strong> WizLink no puede mandar nada hasta que use su código '
        . 'o se pegue una llave en la máquina virtual: <a href="/llave.php">Llave de subida</a>.</p>');
}

$ultima = $todas[0] ?? null;
echo '<div class="resumen">
  <div class="dato"><span class="dato-titulo">Última recibida</span><span class="dato-valor">'
    . ($ultima ? h(fecha_hora((int)$ultima['recibida'])) : 'Ninguna todavía') . '</span></div>
  <div class="dato"><span class="dato-titulo">Corridas guardadas</span><span class="dato-valor">' . count($todas) . '</span></div>
  <div class="dato"><span class="dato-titulo">Espacio usado</span><span class="dato-valor">' . h(peso(espacio_usado())) . '</span></div>
  <div class="dato"><span class="dato-titulo">Se guardan</span><span class="dato-valor">' . dias_guardar() . ' días</span></div>
</div>';
echo linea_proxima($ahora);

echo '<div class="filtro" role="group" aria-label="Qué corridas mostrar">
  <a class="filtro-opcion"' . (!$soloProblemas ? ' aria-current="true"' : '') . ' href="/">' . (!$soloProblemas ? '<span class="simbolo" aria-hidden="true">✓</span>' : '') . 'Todas</a>
  <a class="filtro-opcion"' . ($soloProblemas ? ' aria-current="true"' : '') . ' href="/?ver=problemas">' . ($soloProblemas ? '<span class="simbolo" aria-hidden="true">✓</span>' : '') . 'Solo con algo para revisar</a>
</div>';

if (count($lista) === 0) {
    echo '<p class="vacio">' . ($soloProblemas ? 'Ninguna corrida tiene algo para revisar.' : 'Todavía no llegó ninguna corrida.') . '</p>';
}

$dia = '';
foreach ($lista as $d) {
    $m = momento_de($d['clave']);
    $esteDia = date('Y-m-d', $m);
    if ($esteDia !== $dia) {
        if ($dia !== '') echo '</ul></section>';
        $dia = $esteDia;
        echo '<section class="dia"><h2>' . h(nombre_dia($m)) . '</h2><ul class="corridas">';
    }
    $extra = [];
    if (($d['duracion'] ?? '') !== '') $extra[] = 'Duró ' . h($d['duracion']);
    if ((int)($d['consultas'] ?? 0) > 0) $extra[] = (int)$d['consultas'] . ' consultas';
    $extra[] = h(peso((int)($d['bytes'] ?? 0)));
    if (($d['version'] ?? '') !== '') $extra[] = h($d['version']);
    $sufijo = preg_match('/_(\d+)$/', $d['clave'], $mm) ? ' (' . $mm[1] . ')' : '';
    echo '<li class="corrida">
  <a class="corrida-enlace" href="/corrida.php?c=' . rawurlencode($d['clave']) . '">
    <span class="corrida-hora">' . h(hora($m)) . h($sufijo) . '</span>
    ' . etiqueta_resultado($d) . '
    <span class="corrida-extra">' . implode(' · ', $extra) . '</span>
    <span class="corrida-ir" aria-hidden="true">›</span>
  </a>
</li>';
}
if ($dia !== '') echo '</ul></section>';
echo '</div>' . panel_horario($ahora) . '</div>';

cerrar_pagina();

/** «Hoy, jueves 19 nov 2026», «Ayer, …» o el día. */
function nombre_dia(int $ts): string
{
    static $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $f = $dias[(int)date('w', $ts)] . ' ' . fecha_corta($ts);
    if (date('Y-m-d', $ts) === date('Y-m-d')) return 'Hoy, ' . $f;
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'Ayer, ' . $f;
    return ucfirst($f);
}
