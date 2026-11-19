<?php
// wizlink.bytopo.net · el horario fijo de las corridas automáticas de WizLink, para tenerlo a la vista en «Corridas».
//   - Es una copia del horario que vive en el código de WizLink (`Horario` en app/nucleo/CorridaAutomatica.cs de
//     ZaFi-Online/WizLink; Iván, 19-nov). Si allá cambia, se cambia aquí también: el sitio no lo lee de WizLink.
//   - Las horas son de Nicaragua (ZONA_HORARIA), las mismas de la PC donde corre WizLink.
//   - «Termina» es aproximado: la hora de empezar más lo que suele durar cada tipo de corrida.
//   - Solo muestra: no cambia nada ni le avisa nada a WizLink.

declare(strict_types=1);

require_once __DIR__ . '/base.php';

/** Cada tipo de corrida: su nombre corto (Iván, 19-nov: B2; en WizLink se llaman Corrida Operativa, Extendida 120 y
 *  Extendida 365), su símbolo (nunca solo el color) y cuánto suele durar. */
const TIPOS_CORRIDA = [
    'op'   => ['nombre' => 'Normal',   'simbolo' => '●', 'minutos' => 15],
    'e120' => ['nombre' => 'Ext. 120', 'simbolo' => '◆', 'minutos' => 75],
    'e365' => ['nombre' => 'Ext. 365', 'simbolo' => '■', 'minutos' => 195],
];

/** Las corridas de un día, en orden: [hora, minuto, tipo]. El día 1 de cada mes cambia la madrugada. */
function horario_del_dia(bool $diaPrimero): array
{
    $madrugada = $diaPrimero
        ? [[0, 0, 'op'], [1, 0, 'e365']]
        : [[0, 0, 'op'], [3, 0, 'op'], [4, 0, 'e120']];
    return array_merge($madrugada, [
        [7, 30, 'op'], [9, 0, 'op'], [10, 15, 'e120'], [12, 30, 'op'], [15, 0, 'op'],
        [17, 0, 'op'], [18, 0, 'e120'], [20, 0, 'op'], [22, 0, 'op'],
    ]);
}

/** «3:00 p. m.» a partir de los minutos desde la medianoche (también los que pasan al día siguiente). */
function hora_del_dia(int $minutos): string
{
    $minutos %= 24 * 60;
    $h = intdiv($minutos, 60);
    $h12 = $h % 12 === 0 ? 12 : $h % 12;
    return $h12 . ':' . sprintf('%02d', $minutos % 60) . ($h < 12 ? ' a. m.' : ' p. m.');
}

/** La tabla de un día. $proxima: la fila que se marca como la próxima corrida (-1: ninguna). */
function tabla_horario(bool $diaPrimero, int $proxima, string $titulo): string
{
    $s = '<table class="horario"><caption class="solo-lector">' . h($titulo) . '</caption>'
        . '<thead><tr><th scope="col">Hora</th><th scope="col">Corrida</th><th scope="col">Termina</th></tr></thead><tbody>';
    foreach (horario_del_dia($diaPrimero) as $i => [$h, $m, $tipo]) {
        $t = TIPOS_CORRIDA[$tipo];
        $inicio = $h * 60 + $m;
        $esProxima = $i === $proxima;
        $s .= '<tr' . ($esProxima ? ' class="horario-proxima"' : '') . '>'
            . '<td>' . h(hora_del_dia($inicio))
            . ($esProxima ? '<span class="horario-marca"><span aria-hidden="true">▶</span> Próxima</span>' : '') . '</td>'
            . '<td><span class="tipo tipo-' . $tipo . '"><span aria-hidden="true">' . $t['simbolo'] . '</span> ' . h($t['nombre']) . '</span></td>'
            . '<td>' . h(hora_del_dia($inicio + $t['minutos'])) . '</td></tr>';
    }
    return $s . '</tbody></table>';
}

/**
 * Lo que sigue a partir de $ahora: el día cuyo horario se muestra (hoy, o mañana si hoy ya no queda ninguna), si ese
 * día es día 1, la fila de la próxima corrida y su timestamp.
 */
function proxima_corrida(int $ahora): array
{
    $hoy = (int)strtotime('today', $ahora);
    foreach ([$hoy, (int)strtotime('tomorrow', $ahora)] as $dia) {
        $primero = date('j', $dia) === '1';
        foreach (horario_del_dia($primero) as $i => [$h, $m, $tipo]) {
            $ts = (int)strtotime(sprintf('%s %02d:%02d', date('Y-m-d', $dia), $h, $m));
            if ($ts > $ahora) return ['dia' => $dia, 'es_hoy' => $dia === $hoy, 'primero' => $primero, 'fila' => $i, 'ts' => $ts, 'tipo' => $tipo];
        }
    }
    // No se llega aquí (mañana siempre tiene corridas), pero por si acaso: el horario de hoy sin marcar nada.
    return ['dia' => $hoy, 'es_hoy' => true, 'primero' => date('j', $hoy) === '1', 'fila' => -1, 'ts' => 0, 'tipo' => 'op'];
}

/** La línea corta para el teléfono, arriba de la lista: la próxima corrida y el enlace al horario completo. */
function linea_proxima(int $ahora): string
{
    $p = proxima_corrida($ahora);
    $cuando = ($p['es_hoy'] ? '' : 'mañana, ') . hora($p['ts']);
    return '<p class="horario-linea"><span aria-hidden="true">▶</span> Próxima corrida: <strong>' . h($cuando) . '</strong> · '
        . h(TIPOS_CORRIDA[$p['tipo']]['nombre']) . ' · <a href="#horario">Ver el horario</a></p>';
}

/** El panel completo: el horario del día que sigue con la próxima marcada, y el otro horario a un clic. */
function panel_horario(int $ahora): string
{
    $p = proxima_corrida($ahora);
    $cual = $p['primero'] ? 'día 1 del mes' : 'día normal';
    $sub = ($p['es_hoy'] ? 'Hoy es ' : 'Mañana es ') . $cual . ' · hora de Nicaragua';
    $titulo = $p['primero'] ? 'Día 1 de cada mes' : 'Día normal';
    $otro = $p['primero'] ? 'Día normal' : 'Día 1 de cada mes';
    return '<aside class="panel-horario tarjeta" id="horario" aria-labelledby="horario-titulo">'
        . '<h2 id="horario-titulo">Horario de corridas</h2>'
        . '<p class="panel-sub">' . h($sub) . '</p>'
        . tabla_horario($p['primero'], $p['fila'], $titulo)
        . '<details class="horario-otro"><summary>' . h($otro) . ' (ver horario)</summary>'
        . tabla_horario(!$p['primero'], -1, $otro) . '</details>'
        . '<p class="panel-nota">«Termina» es aproximado. Si una hora llega durante otra corrida, espera a que esa termine.</p>'
        . '</aside>';
}
