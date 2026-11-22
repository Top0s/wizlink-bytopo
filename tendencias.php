<?php
// wizlink.bytopo.net · «Tendencias» (Iván, 22-nov: R6, que toma R3): cuánto duran de verdad las corridas contra lo
// estimado, cuántas consultas quedan en revisar por día y cómo va el día de hoy. Las gráficas se dibujan aquí (SVG), sin
// scripts ni servicios de afuera.

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/almacen.php';
require_once __DIR__ . '/lib/vista.php';
require_once __DIR__ . '/lib/horario.php';
require_once __DIR__ . '/lib/explicar.php';

exigir_sesion();

$ahora = time();
$hoy = (int)strtotime('today', $ahora);
$semana = listar_corridas($hoy - 6 * 86400);   // hoy y los 6 días de antes

abrir_pagina('Tendencias', true, 'tendencias', true);
echo '<h1>Tendencias</h1>';

// ------------------------------------------------------------------ los indicadores
$turnos = turnos_del_dia($hoy, $semana, $ahora);
$cuenta = array_count_values(array_map(fn($t) => $t['estado'], $turnos));
$llegaron = count(array_filter($turnos, fn($t) => $t['corrida'] !== null));
$partes = [];
foreach (['ok' => '✓ %d bien', 'rev' => '! %d con algo para revisar', 'det' => '■ %d detenida%s', 'falta' => '✕ %d no llegó'] as $k => $f) {
    if (($cuenta[$k] ?? 0) > 0) $partes[] = sprintf($f, $cuenta[$k], ($cuenta[$k] ?? 0) > 1 ? 's' : '');
}

$promedios = [];
foreach (NOMBRES_TIPO as $t => $n) {
    $s = array_filter(array_map(fn($d) => tipo_de($d) === $t && ($d['resultado'] ?? '') === 'termino' ? segundos_de($d) : -1, $semana), fn($x) => $x > 0);
    $promedios[$t] = count($s) > 0 ? ['seg' => (int)round(array_sum($s) / count($s)), 'n' => count($s)] : null;
}
$revisarHoy = [];
foreach ($semana as $d) {
    if (momento_de((string)$d['clave']) < $hoy) continue;
    foreach (($d['revisar_por_tipo'] ?? []) as $tipo => $n) $revisarHoy[(string)$tipo] = ($revisarHoy[(string)$tipo] ?? 0) + (int)$n;
}
arsort($revisarHoy);

echo '<div class="tablero">';
echo '<div class="kpi"><span class="kpi-t">Corridas de hoy</span><span class="kpi-v">' . $llegaron . ' de ' . count($turnos) . '</span>'
    . '<span class="kpi-n">' . h(count($partes) > 0 ? implode(' · ', $partes) : 'Todavía no llegó ninguna') . '</span></div>';
foreach (['op' => 'Operativa', 'e120' => 'Extendida 120', 'e365' => 'Extendida 365'] as $t => $n) {
    if ($t === 'e365' && $promedios['e365'] === null) continue;   // solo el día 1 de cada mes
    $p = $promedios[$t];
    echo '<div class="kpi"><span class="kpi-t">Duración real, ' . h($n) . '</span><span class="kpi-v">' . h($p ? duracion_texto($p['seg']) : '—') . '</span>'
        . '<span class="kpi-n">' . h(($p ? 'promedio de ' . $p['n'] . ($p['n'] === 1 ? ' corrida' : ' corridas') . ' en 7 días · ' : 'sin corridas terminadas en 7 días · ')
        . 'estimada ' . minutos_texto(TIPOS_CORRIDA[$t]['minutos'])) . '</span></div>';
}
$totalRev = array_sum($revisarHoy);
$nombres = ['reservas' => 'reservas', 'contratos' => 'contratos', 'cerrados' => 'cerrados', 'flota' => 'flota', 'recibos' => 'recibos', 'impresion' => 'impresión'];
echo '<div class="kpi"><span class="kpi-t">Consultas en revisar hoy</span><span class="kpi-v">' . $totalRev . '</span><span class="kpi-n">'
    . h($totalRev > 0 ? implode(' · ', array_map(fn($k, $v) => ($nombres[$k] ?? $k) . ' ' . $v, array_keys($revisarHoy), $revisarHoy)) : 'Todo cuadró con Wizard') . '</span></div>';
echo '</div>';

// ------------------------------------------------------------------ las gráficas
$ultimas = array_reverse(array_slice(array_values(array_filter($semana, fn($d) => segundos_de($d) > 0)), 0, 14));
$porDia = [];
for ($i = 6; $i >= 0; $i--) $porDia[date('Y-m-d', $hoy - $i * 86400)] = 0;
foreach ($semana as $d) {
    $k = date('Y-m-d', momento_de((string)$d['clave']));
    if (isset($porDia[$k])) $porDia[$k] += (int)($d['revisar'] ?? 0);
}

echo '<div class="graficas">';
echo '<section class="grafica" tabindex="0" aria-labelledby="g1"><h2 id="g1">Cuánto dura cada corrida</h2>'
    . '<p>Minutos, últimas ' . count($ultimas) . ' corridas · ● azul: Operativa · ◆ ámbar: Extendida 120'
    . (in_array('e365', array_map('tipo_de', $ultimas), true) ? ' · ■ rosado: Extendida 365' : '') . ' · raya: lo estimado</p>'
    . grafica_duracion($ultimas, $ahora) . '</section>';
echo '<section class="grafica" tabindex="0" aria-labelledby="g2"><h2 id="g2">Consultas en revisar, por día</h2><p>Últimos 7 días, sumando todas las corridas de cada día</p>'
    . grafica_revisar($porDia) . '</section>';
echo '</div>';

$todas = listar_corridas();
echo '<div class="resumen">
  <div class="dato"><span class="dato-titulo">Última recibida</span><span class="dato-valor">' . (isset($todas[0]) ? h(fecha_hora((int)$todas[0]['recibida'])) : 'Ninguna todavía') . '</span></div>
  <div class="dato"><span class="dato-titulo">Corridas con su .zip</span><span class="dato-valor">' . count(array_filter($todas, fn($d) => !$d['solo_registro'])) . '</span></div>
  <div class="dato"><span class="dato-titulo">Espacio usado</span><span class="dato-valor">' . h(peso(espacio_usado())) . '</span></div>
  <div class="dato"><span class="dato-titulo">Se guardan</span><span class="dato-valor">' . dias_guardar() . ' días el .zip · 1 año el registro</span></div>
</div>';
cerrar_pagina();

/** «15 min» o «1 h 15 min»: lo estimado, sin segundos. */
function minutos_texto(int $m): string
{
    return $m >= 60 ? intdiv($m, 60) . ' h' . ($m % 60 > 0 ? ' ' . ($m % 60) . ' min' : '') : $m . ' min';
}

/** Una escala redonda para el eje: 0, 15, 30… hasta pasar el máximo. */
function escala(int $maximo, array $pasos): array
{
    foreach ($pasos as $p) if ($maximo <= $p * 4) return [$p, $p * max(1, (int)ceil($maximo / $p))];
    $p = end($pasos);
    return [$p, $p * (int)ceil($maximo / $p)];
}

function grafica_duracion(array $corridas, int $ahora): string
{
    if (count($corridas) === 0) return '<p class="vacio">Todavía no hay corridas con duración.</p>';
    $W = 460; $H = 226; $x0 = 36; $y0 = 176; $alto = 150;
    $simbolos = ['op' => '●', 'e120' => '◆', 'e365' => '■'];
    $mins = array_map(fn($d) => (int)round(segundos_de($d) / 60), $corridas);
    [$paso, $tope] = escala(max(max($mins), 20), [5, 15, 30, 60]);
    $y = fn(float $v) => $y0 - $v / $tope * $alto;
    $s = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="g1t"><title id="g1t">Duración en minutos de las últimas ' . count($corridas) . ' corridas</title>';
    for ($v = 0; $v <= $tope; $v += $paso) {
        $s .= '<line class="guia" x1="' . $x0 . '" x2="' . ($W - 6) . '" y1="' . $y($v) . '" y2="' . $y($v) . '"/><text class="ax" x="' . ($x0 - 6) . '" y="' . ($y($v) + 4) . '" text-anchor="end">' . $v . '</text>';
    }
    $bw = ($W - $x0 - 10) / count($corridas);
    foreach ($corridas as $i => $d) {
        $v = $mins[$i]; $x = $x0 + 4 + $i * $bw; $h = max(1, $v / $tope * $alto);
        $s .= '<rect class="b-' . tipo_de($d) . '" x="' . round($x, 1) . '" y="' . round($y0 - $h, 1) . '" width="' . round(max(2, $bw - 6), 1) . '" height="' . round($h, 1) . '" rx="2"/>'
            . '<text class="val" x="' . round($x + ($bw - 6) / 2, 1) . '" y="' . round($y0 - $h - 4, 1) . '" text-anchor="middle">' . $v . '</text>'
            // El símbolo del tipo debajo de cada barra: el tipo no se distingue solo por el color
            . '<text class="ax" x="' . round($x + ($bw - 6) / 2, 1) . '" y="' . ($y0 + 15) . '" text-anchor="middle" aria-hidden="true">' . $simbolos[tipo_de($d)] . '</text>';
    }
    $tipos = array_unique(array_map('tipo_de', $corridas));
    foreach ($tipos as $t) {
        $e = TIPOS_CORRIDA[$t]['minutos'];
        if ($e <= $tope) $s .= '<line class="est" x1="' . $x0 . '" x2="' . ($W - 6) . '" y1="' . $y($e) . '" y2="' . $y($e) . '"/>';
    }
    $s .= '<line class="eje" x1="' . $x0 . '" x2="' . ($W - 6) . '" y1="' . $y0 . '" y2="' . $y0 . '"/>'
        . '<text class="ax" x="' . $x0 . '" y="' . ($y0 + 36) . '">' . h(cuando_corto(momento_de($corridas[0]['clave']), $ahora)) . '</text>'
        . '<text class="ax" x="' . ($W - 6) . '" y="' . ($y0 + 36) . '" text-anchor="end">' . h(cuando_corto(momento_de(end($corridas)['clave']), $ahora)) . '</text></svg>';
    // Los mismos datos en una tabla, para quien no ve la gráfica
    $s .= '<table class="solo-lector"><caption>Duración de cada corrida</caption><thead><tr><th>Corrida</th><th>Tipo</th><th>Duró</th></tr></thead><tbody>';
    foreach ($corridas as $d) $s .= '<tr><td>' . h(cuando_corto(momento_de($d['clave']), $ahora)) . '</td><td>' . h(NOMBRES_TIPO[tipo_de($d)]) . '</td><td>' . h(duracion_texto(segundos_de($d))) . '</td></tr>';
    return $s . '</tbody></table>';
}

function grafica_revisar(array $porDia): string
{
    static $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    $W = 460; $H = 210; $x0 = 36; $y0 = 176; $alto = 150;
    [$paso, $tope] = escala(max(max($porDia), 4), [1, 5, 15, 50, 150]);
    $y = fn(float $v) => $y0 - $v / $tope * $alto;
    $s = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="g2t"><title id="g2t">Consultas en revisar por día, últimos 7 días</title>';
    for ($v = 0; $v <= $tope; $v += $paso) {
        $s .= '<line class="guia" x1="' . $x0 . '" x2="' . ($W - 6) . '" y1="' . $y($v) . '" y2="' . $y($v) . '"/><text class="ax" x="' . ($x0 - 6) . '" y="' . ($y($v) + 4) . '" text-anchor="end">' . $v . '</text>';
    }
    $bw = ($W - $x0 - 10) / count($porDia);
    $i = 0;
    foreach ($porDia as $dia => $v) {
        $x = $x0 + 4 + $i * $bw; $h = $v > 0 ? max(2, $v / $tope * $alto) : 0; $ts = (int)strtotime($dia);
        $s .= '<rect class="b-rev" x="' . round($x, 1) . '" y="' . round($y0 - $h, 1) . '" width="' . round($bw - 14, 1) . '" height="' . round($h, 1) . '" rx="2"/>'
            . '<text class="val" x="' . round($x + ($bw - 14) / 2, 1) . '" y="' . round($y0 - $h - 4, 1) . '" text-anchor="middle">' . $v . '</text>'
            . '<text class="ax" x="' . round($x + ($bw - 14) / 2, 1) . '" y="' . ($y0 + 16) . '" text-anchor="middle">' . h($dias[(int)date('w', $ts)] . ' ' . date('j', $ts)) . '</text>';
        $i++;
    }
    $s .= '<line class="eje" x1="' . $x0 . '" x2="' . ($W - 6) . '" y1="' . $y0 . '" y2="' . $y0 . '"/></svg>';
    $s .= '<table class="solo-lector"><caption>Consultas en revisar por día</caption><thead><tr><th>Día</th><th>En revisar</th></tr></thead><tbody>';
    foreach ($porDia as $dia => $v) $s .= '<tr><td>' . h(fecha_corta((int)strtotime($dia))) . '</td><td>' . $v . '</td></tr>';
    return $s . '</tbody></table>';
}
