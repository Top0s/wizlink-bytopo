<?php
// wizlink.bytopo.net · «Problemas» (Iván, 22-nov: R6, que toma R4; 24-nov: A2): cada problema juntado, con cuándo, qué
// pasó y qué hacer, separado en Hoy, Ayer, Esta semana y los meses; debajo, las corridas de hoy en una tabla corta y el horario.

declare(strict_types=1);

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/problemas.php';
require_once __DIR__ . '/lib/vista.php';

exigir_sesion();

$ahora = time();
$p = estado_problemas($ahora);

abrir_pagina('Problemas', true, 'problemas', true);
echo '<div class="con-horario"><div class="con-horario-lista"><h1>Problemas</h1>';
echo $p['aviso'];

// Por cuándo pasó (Iván, 24-nov: A2): un resumen de hoy, cuatro cajas y una sección por periodo
$periodos = periodos_problemas($ahora);
$todas = listar_corridas((int)end($periodos)['desde']);
$x = problemas_por_periodo($todas, $ahora);
$porId = [];
foreach ($x['periodos'] as $per) $porId[$per['id']] = $per;
echo resumen_de_hoy($porId['hoy'], $todas[0] ?? null);
echo cajas_periodos($porId, $x['mes'], $ahora);
foreach ($x['periodos'] as $per) echo seccion_periodo($per, $ahora);

// Las corridas de hoy
$hoy = (int)strtotime('today', $ahora);
$deHoy = array_values(array_filter($p['corridas'], fn($d) => momento_de((string)$d['clave']) >= $hoy));
echo '<h2>Todas las corridas de hoy</h2>';
if (count($deHoy) === 0) {
    echo '<p class="vacio">Hoy todavía no llegó ninguna corrida.</p>';
} else {
    echo '<table class="tabla"><caption class="solo-lector">Corridas de hoy</caption><thead><tr><th scope="col">Hora</th><th scope="col" class="ocultar">Corrida</th>'
        . '<th scope="col">Resultado</th><th scope="col">Duró</th><th scope="col" class="ocultar">Consultas</th><th scope="col" class="ocultar">Peso</th></tr></thead><tbody>';
    foreach ($deHoy as $d) {
        $m = momento_de((string)$d['clave']);
        $tipo = NOMBRES_TIPO[tipo_de($d)];
        echo '<tr><td class="nw"><a href="/corrida.php?c=' . rawurlencode((string)$d['clave']) . '">' . h(hora($m)) . '</a><span class="solo-tel"><br>' . h($tipo) . '</span></td>'
            . '<td class="ocultar">' . h($tipo) . '</td><td>' . etiqueta_resultado($d) . '</td>'
            . '<td class="nw">' . h(duracion_texto(segundos_de($d)) ?: '—') . '</td>'
            . '<td class="ocultar">' . (int)($d['consultas'] ?? 0) . '</td><td class="ocultar">' . h(peso((int)($d['bytes'] ?? 0))) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '<p class="registro-solo nota-abajo">Corridas de más de ' . dias_guardar() . ' días: solo el registro (resultado, duración y diagnóstico en palabras). <a href="/#meses">Ver el registro por mes</a></p>';
echo '</div>' . panel_horario($ahora) . '</div>';
cerrar_pagina();

/** Lo de hoy, en una frase arriba de todo: lo primero que se ve al abrir la pestaña. */
function resumen_de_hoy(array $hoy, ?array $nueva): string
{
    $n = count($hoy['problemas']);
    if ($n === 0) {
        return $hoy['corridas'] === 0 ? aviso('info', '<p>Hoy todavía no llegó ninguna corrida.</p>')
            : aviso('exito', '<p><b>Hoy no hubo problemas:</b> ' . ($hoy['corridas'] === 1 ? 'la corrida de hoy terminó bien.' : 'las ' . $hoy['corridas'] . ' corridas de hoy terminaron bien.') . '</p>');
    }
    $titulos = array_map(fn($g) => $g['titulo'] . (donde_texto($g) !== '' ? ' (' . donde_texto($g) . ')' : ''), array_slice($hoy['problemas'], 0, 3));
    $abiertos = count(array_filter($hoy['problemas'], fn($g) => $g['abierto']));
    $grave = count(array_filter($hoy['problemas'], fn($g) => $g['abierto'] && $g['grave'])) > 0;
    $texto = '<b>Hoy hubo ' . $n . ($n === 1 ? ' problema:' : ' problemas:') . '</b> ' . h(implode('; ', $titulos)) . ($n > 3 ? ' y ' . ($n - 3) . ' más' : '') . '. ';
    $texto .= $abiertos === 0 ? 'La corrida más nueva ya no lo trae.' : ($abiertos === 1 ? 'La corrida más nueva todavía trae uno.' : 'La corrida más nueva todavía trae ' . $abiertos . '.');
    return aviso($grave ? 'error' : 'atencion', '<p>' . $texto . '</p>');
}

/** Las cuatro cajas: Hoy, Ayer, Esta semana y el mes, con cuántos problemas distintos. Símbolo y palabra, no solo color. */
function cajas_periodos(array $porId, int $delMes, int $ahora): string
{
    $caja = function (string $ancla, string $titulo, int $n, string $sub, int $corridas, bool $marcarHoy): string {
        $clase = $marcarHoy && $n > 0 ? 'cj-rev' : ($n === 0 && $corridas > 0 ? 'cj-ok' : 'cj-neu');
        $simbolo = $clase === 'cj-rev' ? '! ' : ($clase === 'cj-ok' ? '✓ ' : '');
        $palabra = $n === 0 ? ($corridas > 0 ? 'sin problemas' : 'sin corridas') : ($n === 1 ? 'problema' : 'problemas');
        return '<li class="' . $clase . '"><a href="#' . $ancla . '"><span class="c-t"><span aria-hidden="true">' . $simbolo . '</span>' . h($titulo) . '</span>'
            . '<span class="c-n">' . $n . '</span><span class="c-s">' . h($palabra . ($sub === '' ? '' : (str_starts_with($sub, 'en ') ? ' ' : ' · ') . $sub)) . '</span></a></li>';
    };
    $mesId = isset($porId['mes']) ? 'mes' : 'semana';
    return '<ul class="cuando-cajas" aria-label="Problemas por cuándo pasaron">'
        . $caja('hoy', 'Hoy', count($porId['hoy']['problemas']), dia_mes($porId['hoy']['desde']), $porId['hoy']['corridas'], true)
        . $caja('ayer', 'Ayer', count($porId['ayer']['problemas']), dia_mes($porId['ayer']['desde']), $porId['ayer']['corridas'], false)
        . $caja('semana', 'Esta semana', count($porId['semana']['problemas']), substr($porId['semana']['sub'], 4), $porId['semana']['corridas'], false)
        . $caja($mesId, ucfirst(mes_largo($ahora)), $delMes, 'en el mes', 1, false)
        . '</ul>';
}

/** Una sección: Hoy, Ayer y Esta semana abiertas; los meses, cerrados. */
function seccion_periodo(array $per, int $ahora): string
{
    $n = count($per['problemas']);
    $cuenta = $n === 0 ? '' : ' · ' . $n . ($n === 1 ? ' problema' : ' problemas');
    $tarjetas = '';
    foreach ($per['problemas'] as $g) $tarjetas .= tarjeta_problema($g, $ahora, $per['id'] === 'hoy');
    if ($per['cerrado']) {
        $titulo = h($per['titulo']) . ' <span class="sub">' . h(($per['sub'] !== '' ? ' · ' . $per['sub'] : '') . ($n === 0 ? ' · ningún problema' : $cuenta)) . '</span>';
        if ($n === 0) return '<p class="grupo-cuando grupo-vacio" id="' . h($per['id']) . '">' . $titulo . '</p>';
        return '<details class="mas" id="' . h($per['id']) . '"><summary>' . $titulo . '</summary><div class="problemas">' . $tarjetas . '</div></details>';
    }
    $chip = $per['id'] === 'hoy' && $n > 0 ? '<span class="chip chip-atencion"><span class="simbolo" aria-hidden="true">!</span>Hoy</span>' . h($per['sub']) : h($per['titulo']) . ' <span class="sub">· ' . h($per['sub']) . '</span>';
    $s = '<h2 class="grupo-cuando" id="' . h($per['id']) . '">' . $chip . ($n > 0 ? ' <span class="sub">' . h($cuenta) . '</span>' : '') . '</h2>';
    if ($n === 0) {
        $vacio = $per['corridas'] === 0 ? 'No llegó ninguna corrida.'
            : ($per['corridas'] === 1 ? 'Ningún problema: la corrida terminó bien.' : 'Ningún problema: las ' . $per['corridas'] . ' corridas terminaron bien.');
        return $s . '<p class="nada">' . ($per['corridas'] > 0 ? '<span aria-hidden="true">✓</span> ' : '') . h($vacio) . '</p>';
    }
    return $s . '<div class="problemas">' . $tarjetas . '</div>';
}

/**
 * Una tarjeta: qué pasó, cuándo y qué hacer. Abierto (lo trae la corrida más nueva): «Grave» o «Revisar». Lo de hoy que ya
 * no se repite sigue en amarillo, «Pasó hoy», para que se vea (Iván, 24-nov). Lo de antes: «Ya no se repite».
 */
function tarjeta_problema(array $g, int $ahora, bool $deHoy): string
{
    $donde = donde_texto($g);
    $s = fn(string $simbolo, string $clase, string $texto) => '<span class="chip ' . $clase . '"><span class="simbolo" aria-hidden="true">' . $simbolo . '</span>' . $texto . '</span>';
    if ($g['abierto']) { $chip = $g['grave'] ? $s('■', 'chip-error', 'Grave') : $s('!', 'chip-atencion', 'Revisar'); $clase = $g['grave'] ? ' p-grave' : ''; }
    elseif ($deHoy) { $chip = $s('!', 'chip-atencion', 'Pasó hoy'); $clase = ''; }
    else { $chip = $s('✓', 'chip-exito', 'Ya no se repite'); $clase = ' p-ok'; }
    $primera = momento_de($g['primera']);
    $ultima = momento_de($g['ultima']);
    $cuando = $g['corridas'] > 1
        ? $g['corridas'] . ' corridas, de ' . cuando_corto($primera, $ahora) . ' a ' . cuando_corto($ultima, $ahora)
        : 'Solo la corrida de ' . cuando_corto($ultima, $ahora);
    // La hora ya termina en punto («a. m.»): sin otro punto
    $cuando .= str_ends_with($cuando, '.') ? ' ' : '. ';
    if ($g['abierto']) $cuando .= 'Sigue en la corrida más nueva.';
    elseif ($g['despues'] > 0) $cuando .= 'No se repitió en ' . ($g['despues'] === 1 ? 'la corrida siguiente.' : 'las ' . $g['despues'] . ' corridas siguientes.');
    $cuando = rtrim($cuando);
    $hacer = $g['abierto'] || str_starts_with($g['hacer'], 'Nada') ? $g['hacer'] : 'Nada: no volvió a pasar en las corridas siguientes.';
    $diag = '/corrida.php?c=' . rawurlencode($g['ultima']);
    return '<article class="problema' . $clase . '"><h3>' . $chip . h($g['titulo']) . ($donde !== '' ? ' <span class="problema-donde">' . h($donde) . '</span>' : '') . '</h3>'
        . '<dl><dt>Cuándo</dt><dd>' . h($cuando) . '</dd><dt>Qué pasó</dt><dd>' . h($g['que'])
        . ($g['id'] === 'otro' ? '' : ($g['motivo'] !== '' ? ' <span class="problema-original">WizLink dice: ' . h(recortar($g['motivo'], 200)) . '</span>' : ''))
        . '</dd><dt>Qué hacer</dt><dd>' . h($hacer) . ' <a href="' . $diag . '">Ver la corrida y su diagnóstico</a></dd></dl></article>';
}
