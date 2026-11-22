<?php
// wizlink.bytopo.net · «Problemas» (Iván, 22-nov: R6, que toma R4): cada problema de los últimos 7 días, juntado, con desde
// cuándo, qué pasó y qué hacer; debajo, las corridas de hoy en una tabla corta y el horario.

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

echo '<h2 class="sin-margen">Lo que necesita atención</h2>';
if (count($p['abiertos']) === 0) {
    echo aviso('exito', '<p>' . (count($p['corridas']) === 0 ? 'Todavía no llegó ninguna corrida en los últimos ' . dias_problemas() . ' días.' : 'La corrida más nueva no trae ningún problema.') . '</p>');
} else {
    echo '<div class="problemas">';
    foreach ($p['abiertos'] as $g) echo tarjeta_problema($g, $ahora, true);
    echo '</div>';
}
if (count($p['resueltos']) > 0) {
    echo '<h2>Resueltos en los últimos ' . dias_problemas() . ' días</h2><div class="problemas">';
    foreach ($p['resueltos'] as $g) echo tarjeta_problema($g, $ahora, false);
    echo '</div>';
}

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

/** Una tarjeta: qué pasó, desde cuándo y qué hacer. */
function tarjeta_problema(array $g, int $ahora, bool $abierto): string
{
    $donde = donde_texto($g);
    $chip = $abierto
        ? ($g['grave'] ? '<span class="chip chip-error"><span class="simbolo" aria-hidden="true">■</span>Grave</span>'
                       : '<span class="chip chip-atencion"><span class="simbolo" aria-hidden="true">!</span>Revisar</span>')
        : '<span class="chip chip-exito"><span class="simbolo" aria-hidden="true">✓</span>Resuelto</span>';
    $primera = momento_de($g['primera']);
    $ultima = momento_de($g['ultima']);
    if ($abierto) {
        $cuando = $g['seguidas'] > 1
            ? $g['seguidas'] . ' corridas seguidas, la más nueva ' . cuando_corto($ultima, $ahora)
            : 'La corrida de ' . cuando_corto($ultima, $ahora);
        if ($g['corridas'] > $g['seguidas']) $cuando .= ' · en total ' . $g['corridas'] . ' corridas desde ' . cuando_corto($primera, $ahora);
    } else {
        $cuando = ($g['corridas'] > 1 ? $g['corridas'] . ' corridas, de ' . cuando_corto($primera, $ahora) . ' a ' : 'Solo la corrida de ') . cuando_corto($ultima, $ahora);
    }
    $diag = '/corrida.php?c=' . rawurlencode($g['ultima']);
    $hacer = $abierto ? h($g['hacer']) : 'Nada: la corrida más nueva ya no lo trae.';
    return '<article class="problema' . ($abierto ? ($g['grave'] ? ' p-grave' : '') : ' p-ok') . '"><h3>' . $chip . h($g['titulo']) . ($donde !== '' ? ' <span class="problema-donde">' . h($donde) . '</span>' : '') . '</h3>'
        . '<dl><dt>Desde cuándo</dt><dd>' . h($cuando) . '</dd><dt>Qué pasó</dt><dd>' . h($g['que'])
        . ($g['id'] === 'otro' ? '' : ($g['motivo'] !== '' ? ' <span class="problema-original">WizLink dice: ' . h(recortar($g['motivo'], 200)) . '</span>' : ''))
        . '</dd><dt>Qué hacer</dt><dd>' . $hacer . ' <a href="' . $diag . '">Ver la corrida y su diagnóstico</a></dd></dl></article>';
}
