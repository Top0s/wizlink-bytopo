<?php
// wizlink.bytopo.net · «Corridas», la página principal (Iván, 22-nov: R6, que toma R2): arriba, las corridas del horario
// de hoy, turno por turno; debajo, las corridas recibidas por día, la más nueva primero, cada una con su diagnóstico en
// palabras. Muestra los últimos días; los meses anteriores, solo con su registro, con «?mes=AAAA-MM».

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/almacen.php';
require_once __DIR__ . '/lib/vista.php';
require_once __DIR__ . '/lib/horario.php';
require_once __DIR__ . '/lib/explicar.php';

exigir_sesion();

$ahora = time();
$mes = (string)($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) $mes = '';
$soloProblemas = ($_GET['ver'] ?? '') === 'problemas';

$todas = listar_corridas();
$conZip = array_values(array_filter($todas, fn($d) => !$d['solo_registro']));
// Sin mes: los días con .zip y uno más de registro. Con mes: ese mes completo.
$desde = (int)strtotime('today', $ahora) - dias_guardar() * 86400;
$lista = $mes !== ''
    ? array_values(array_filter($todas, fn($d) => substr((string)$d['clave'], 0, 7) === $mes))
    : array_values(array_filter($todas, fn($d) => momento_de((string)$d['clave']) >= $desde));
if ($soloProblemas) {
    $lista = array_values(array_filter($lista, fn($d) => ($d['resultado'] ?? '') !== 'termino' || (int)($d['revisar'] ?? 0) > 0 || count($d['casos'] ?? []) > 0));
}

abrir_pagina('', true, 'corridas', true);
echo '<h1>Corridas recibidas</h1>';

$esperando = count(llaves_esperando());
if ($esperando > 0) {
    echo aviso('atencion', '<p><strong>' . ($esperando === 1 ? 'Una llave de WizLink espera' : $esperando . ' llaves de WizLink esperan') . ' su confirmación.</strong> '
        . 'Mientras tanto, las corridas no llegan: <a href="/llave.php">revisar en Llave de subida</a>.</p>');
} elseif (llave_creada() === null) {
    echo aviso('atencion', '<p><strong>Todavía no hay llave de subida.</strong> WizLink no puede mandar nada hasta que use su código '
        . 'o se pegue una llave en la máquina virtual: <a href="/llave.php">Llave de subida</a>.</p>');
}
echo aviso_automatica($todas[0] ?? null, $ahora);

if ($mes === '') echo seccion_turnos($todas, $ahora);

echo '<div class="resumen">
  <div class="dato"><span class="dato-titulo">Última recibida</span><span class="dato-valor">'
    . (isset($todas[0]) ? h(fecha_hora((int)$todas[0]['recibida'])) : 'Ninguna todavía') . '</span></div>
  <div class="dato"><span class="dato-titulo">Corridas con su .zip</span><span class="dato-valor">' . count($conZip) . '</span></div>
  <div class="dato"><span class="dato-titulo">Espacio usado</span><span class="dato-valor">' . h(peso(espacio_usado())) . '</span></div>
  <div class="dato"><span class="dato-titulo">Se guardan</span><span class="dato-valor">' . dias_guardar() . ' días el .zip · 1 año el registro</span></div>
</div>';

$base = $mes !== '' ? '/?mes=' . $mes : '/';
$sep = $mes !== '' ? '&amp;' : '?';
echo '<div class="filtro" role="group" aria-label="Qué corridas mostrar">
  <a class="filtro-opcion"' . (!$soloProblemas ? ' aria-current="true"' : '') . ' href="' . h($base) . '">' . (!$soloProblemas ? '<span class="simbolo" aria-hidden="true">✓</span>' : '') . 'Todas</a>
  <a class="filtro-opcion"' . ($soloProblemas ? ' aria-current="true"' : '') . ' href="' . h($base) . $sep . 'ver=problemas">' . ($soloProblemas ? '<span class="simbolo" aria-hidden="true">✓</span>' : '') . 'Solo con algo para revisar</a>
</div>';
if ($mes !== '') echo '<h2 class="titulo-mes">Registro de ' . h(nombre_mes($mes)) . '</h2>';

if (count($lista) === 0) {
    echo '<p class="vacio">' . ($soloProblemas ? 'Ninguna corrida tiene algo para revisar.' : ($mes !== '' ? 'No hay corridas de ese mes.' : 'Todavía no llegó ninguna corrida.')) . '</p>';
}

$dia = '';
foreach ($lista as $d) {
    $m = momento_de($d['clave']);
    $esteDia = date('Y-m-d', $m);
    if ($esteDia !== $dia) {
        if ($dia !== '') echo '</ul></section>';
        $dia = $esteDia;
        $soloReg = $d['solo_registro'];
        echo '<section class="dia"><h2>' . h(nombre_dia($m)) . ($soloReg ? ' · solo el registro' : '') . '</h2><ul class="corridas">';
    }
    $extra = [etiqueta_tipo($d)];
    $dur = duracion_texto(segundos_de($d));
    if ($dur !== '') $extra[] = 'Duró ' . h($dur);
    if ((int)($d['consultas'] ?? 0) > 0) $extra[] = (int)$d['consultas'] . ' consultas';
    if (!$d['solo_registro']) $extra[] = h(peso((int)($d['bytes'] ?? 0)));
    if (($d['version'] ?? '') !== '') $extra[] = h($d['version']);
    $sufijo = preg_match('/_(\d+)$/', $d['clave'], $mm) ? ' (' . $mm[1] . ')' : '';
    echo '<li class="corrida">
  <a class="corrida-enlace" href="/corrida.php?c=' . rawurlencode($d['clave']) . '">
    <span class="corrida-hora">' . h(hora($m)) . h($sufijo) . '</span>
    ' . etiqueta_resultado($d) . '
    <span class="corrida-extra">' . implode(' · ', $extra) . '</span>
    <span class="corrida-ir" aria-hidden="true">›</span>
    <span class="explica">' . explicacion_corta($d) . '</span>'
    . ($d['solo_registro'] ? '<span class="explica registro-solo"><span aria-hidden="true">🗎</span> El .zip se borró a los ' . dias_guardar() . ' días; queda el registro.</span>' : '') . '
  </a>
</li>';
}
if ($dia !== '') echo '</ul></section>';

// Los meses del registro
$meses = array_values(array_unique(array_map(fn($d) => substr((string)$d['clave'], 0, 7), $todas)));
if (count($meses) > 0) {
    echo '<nav class="meses" id="meses" aria-label="Registro por mes"><h2>Registro por mes</h2><ul>';
    if ($mes !== '') echo '<li><a href="/">Últimos días</a></li>';
    foreach ($meses as $x) echo '<li><a href="/?mes=' . h($x) . '"' . ($x === $mes ? ' aria-current="page"' : '') . '>' . h(nombre_mes($x)) . '</a></li>';
    echo '</ul></nav>';
}

cerrar_pagina();

/** Las corridas del horario de hoy, turno por turno (Iván, 22-nov: R2). */
function seccion_turnos(array $todas, int $ahora): string
{
    $hoy = (int)strtotime('today', $ahora);
    $turnos = turnos_del_dia($hoy, $todas, $ahora);
    $s = '<section class="linea-dia" aria-labelledby="turnos-titulo"><h2 id="turnos-titulo">Hoy, turno por turno</h2>'
        . '<p class="panel-sub">Las ' . count($turnos) . ' corridas del horario de hoy' . (date('j', $hoy) === '1' ? ' (día 1 del mes)' : '') . ' y cómo salió cada una · hora de Nicaragua</p><ol class="turnos">';
    $proxima = proxima_corrida($ahora);
    foreach ($turnos as $t) {
        [$simbolo, $palabra] = ESTADOS_TURNO[$t['estado']];
        $esProxima = $t['estado'] === 'pend' && $t['ts'] === $proxima['ts'];
        $texto = '<span class="turno-simbolo" aria-hidden="true">' . $simbolo . '</span><span class="turno-hora">' . h(hora_turno($t['ts'])) . '</span>'
            . '<span>' . h($esProxima ? 'Próxima' : $palabra) . '</span>'
            . ($t['tipo'] !== 'op' ? '<span class="turno-tipo">' . h(TIPOS_CORRIDA[$t['tipo']]['nombre']) . '</span>' : '');
        $etiqueta = hora($t['ts']) . ', ' . TIPOS_CORRIDA[$t['tipo']]['nombre'] . ': ' . ($esProxima ? 'próxima corrida' : $palabra);
        $clase = 'turno t-' . $t['estado'] . ($t['tipo'] !== 'op' ? ' t-ext' : '') . ($esProxima ? ' t-proxima' : '');
        $s .= '<li class="' . $clase . '">' . ($t['corrida'] !== null
            ? '<a href="/corrida.php?c=' . rawurlencode((string)$t['corrida']['clave']) . '" aria-label="' . h($etiqueta) . '">' . $texto . '</a>'
            : '<span class="turno-caja" aria-label="' . h($etiqueta) . '" role="img">' . $texto . '</span>') . '</li>';
    }
    return $s . '</ol><ul class="leyenda"><li>✓ Terminó</li><li>! Terminó con algo para revisar</li><li>■ Se detuvo</li><li>✕ No llegó</li><li>… En curso</li><li>○ Pendiente</li><li>Raya azul abajo: corrida Extendida</li></ul></section>';
}

/** «Hoy, jueves 19 nov 2026», «Ayer, …» o el día. */
function nombre_dia(int $ts): string
{
    static $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $f = $dias[(int)date('w', $ts)] . ' ' . fecha_corta($ts);
    if (date('Y-m-d', $ts) === date('Y-m-d')) return 'Hoy, ' . $f;
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'Ayer, ' . $f;
    return ucfirst($f);
}

/** «noviembre 2026». */
function nombre_mes(string $mes): string
{
    static $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $meses[(int)substr($mes, 5, 2) - 1] . ' ' . substr($mes, 0, 4);
}
