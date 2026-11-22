<?php
// Comprobaciones sin servidor de lo que el sitio lee de cada corrida y cómo lo explica (Iván, 22-nov: R6): el tipo, la
// duración en horas, minutos y segundos, los casos del diagnóstico, los casos conocidos, los problemas juntados y los
// turnos del día. Corre con pruebas/correr.sh; no toca nada guardado.
declare(strict_types=1);

require dirname(__DIR__) . '/lib/problemas.php';
require __DIR__ . '/corrida_inventada.php';

$fallas = 0; $total = 0;
function ok_e(bool $c, string $d): void { global $fallas, $total; $total++; if (!$c) { $fallas++; echo "FALLA: $d\n"; } }
function datos_inventados(string $corrida, array $op): array
{
    $f = tempnam(sys_get_temp_dir(), 'z');
    file_put_contents($f, corrida_inventada($corrida, $op));
    try {
        $d = datos_de_zip($f, $corrida, '1.0.23', (int)filesize($f), 'x');
    } finally {
        @unlink($f);
    }
    $d['clave'] = clave_de($corrida);
    $d['solo_registro'] = false;
    return $d;
}

// La duración (Iván, 22-nov): sin «0 h» cuando dura menos de una hora
ok_e(duracion_texto(948) === '15 min 48 s', 'duración: 15 min 48 s');
ok_e(duracion_texto(460) === '7 min 40 s', 'duración: 7 min 40 s, sin cero adelante');
ok_e(duracion_texto(4472) === '1 h 14 min 32 s', 'duración: 1 h 14 min 32 s');
ok_e(duracion_texto(3600) === '1 h 00 min 00 s' && duracion_texto(59) === '0 min 59 s' && duracion_texto(-1) === '', 'duración: una hora justa, menos de un minuto y sin dato');
ok_e(segundos_de(['duracion' => '74 min 32 s']) === 4472, 'duración: los datos de antes («74 min 32 s») también');

// Lo que se lee del .zip
$d = datos_inventados('2026-11-22 10.15', ['tipo' => 'e120', 'segundos' => 4472, 'casos' => [CASO_FLOTA, CASO_IMPRESORA, CASO_IMPRESORA2]]);
ok_e($d['forma'] === DATOS_FORMA && $d['tipo'] === 'e120' && $d['segundos'] === 4472 && $d['duracion'] === '74 min 32 s', 'zip: el tipo y los segundos');
ok_e($d['consultas'] === 13 && $d['revisar'] === 3 && $d['revisar_por_tipo'] === ['flota' => 1, 'impresion' => 2] && $d['impresos'] === 38, 'zip: el control y los impresos');
ok_e(count($d['casos']) === 3 && $d['casos'][0] === ['p' => 'R', 'texto' => CASO_FLOTA[1], 'motivo' => CASO_FLOTA[2]], 'zip: los casos del diagnóstico, con su primer motivo');
ok_e(!str_contains(json_encode($d), 'XXXXXX') && !str_contains(json_encode($d), 'pantallas/'), 'zip: no se guardan la pantalla ni los archivos del caso');
$op = datos_inventados('2026-11-22 12.30', []);
ok_e($op['tipo'] === 'op' && $op['casos'] === [] && $op['resultado'] === 'termino', 'zip: una Operativa sin casos');
$viejo = datos_inventados('2026-11-10 09.00', ['casos' => [CASO_FLOTA], 'sin_diagnostico' => true]);
ok_e(count($viejo['casos']) === 1 && str_starts_with($viejo['casos'][0]['texto'], 'flota · MGA 07768') && str_contains($viejo['casos'][0]['motivo'], 'no coincide'),
     'zip: sin diagnóstico (WizLink de antes del 12-nov), los casos salen del control');

// Los casos conocidos
$e = casos_explicados($d);
ok_e(count($e) === 2, 'explicar: las dos impresiones son un solo problema, y la flota otro');
$imp = array_values(array_filter($e, fn($x) => $x['id'] === 'impresora'))[0] ?? null;
$flo = array_values(array_filter($e, fn($x) => $x['id'] === 'flota_total'))[0] ?? null;
ok_e($imp !== null && $imp['veces'] === 2 && $imp['sucursales'] === ['MN5 07769', 'LEO 07770'] && donde_texto($imp) === 'en 2 sucursales', 'explicar: la impresora, en 2 sucursales');
ok_e($flo !== null && $flo['donde'] === 'MGA 07768' && $flo['dia'] === '22NOV26' && donde_texto($flo) === 'en MGA 07768, día 22NOV26', 'explicar: la flota, con su sucursal y su día');
$des = explicar_caso(['p' => 'N', 'texto' => CASO_DESCONEXION[1], 'motivo' => CASO_DESCONEXION[2]]);
ok_e($des['id'] === 'desconexion' && $des['grave'], 'explicar: la desconexión de BlueZone es grave');
ok_e(explicar_caso(['p' => 'S', 'texto' => 'Se detuvo a las 01:00:00', 'motivo' => 'PASSWORD EXPIRED'])['id'] === 'seguridad', 'explicar: un aviso de seguridad');
$nuevo = explicar_caso(['p' => 'R', 'texto' => CASO_NUEVO[1], 'motivo' => CASO_NUEVO[2]]);
ok_e($nuevo['id'] === 'otro' && $nuevo['titulo'] === 'Las reservas no cuadraron con Wizard' && str_contains($nuevo['que'], 'un caso que nadie había visto'), 'explicar: un caso nuevo, con las palabras de WizLink');
ok_e(explicar_caso(['p' => 'R', 'texto' => 'contrato 0000000 · REVISAR', 'motivo' => 'la página 1 no trae impuesto'])['id'] === 'recibo', 'explicar: un recibo sin impuesto');
ok_e(explicar_caso(['p' => 'N', 'texto' => 'ABP Unify (Producción): sin red', 'motivo' => ''])['id'] === 'unify', 'explicar: un envío a Unify');
ok_e(explicacion_corta($op) === 'Todo cuadró con Wizard.', 'lista: sin casos, «Todo cuadró con Wizard.»');
$corta = explicacion_corta($d);
ok_e(str_contains($corta, 'La impresora P2 de BlueZone no imprimió (en 2 sucursales).') || str_contains($corta, 'La flota no cuadró'), 'lista: la explicación corta nombra los problemas');
$trampa = explicacion_corta(['casos' => [['p' => 'R', 'texto' => 'flota · <script>alert(1)</script>', 'motivo' => '<img src=x onerror=alert(1)>']]]);
ok_e(!str_contains($trampa, '<script') && !str_contains($trampa, '<img'), 'lista: lo que llega en el .zip se muestra escapado');

// Los problemas juntados (la más nueva primero)
$a = datos_inventados('2026-11-22 12.30', ['casos' => [CASO_FLOTA]]);
$b = datos_inventados('2026-11-22 10.15', ['tipo' => 'e120', 'casos' => [CASO_FLOTA, CASO_IMPRESORA]]);
$c = datos_inventados('2026-11-22 09.00', ['casos' => [CASO_IMPRESORA]]);
$p = juntar_problemas([$a, $b, $c]);
ok_e(count($p['abiertos']) === 1 && $p['abiertos'][0]['id'] === 'flota_total' && $p['abiertos'][0]['seguidas'] === 2 && $p['abiertos'][0]['corridas'] === 2, 'problemas: la flota, abierta en 2 corridas seguidas');
ok_e(count($p['resueltos']) === 1 && $p['resueltos'][0]['id'] === 'impresora' && $p['resueltos'][0]['corridas'] === 2 && $p['resueltos'][0]['primera'] === '2026-11-22_09.00', 'problemas: la impresora, resuelta');
$p = juntar_problemas([$op, $a]);
ok_e(count($p['abiertos']) === 0 && count($p['resueltos']) === 1, 'problemas: la corrida más nueva sin casos deja todo resuelto');
$p = juntar_problemas([$a, $op, $b]);
ok_e(count($p['abiertos']) === 1 && $p['abiertos'][0]['seguidas'] === 1 && $p['abiertos'][0]['corridas'] === 2, 'problemas: la racha se corta con una corrida sin el caso');

// Los turnos del día (horario de un día normal, hora de Nicaragua)
$t = fn(string $s) => (int)strtotime($s);
$x = fn(string $corrida, string $r, int $rev) => ['clave' => clave_de($corrida), 'resultado' => $r, 'revisar' => $rev];
$corridas = [$x('2026-11-22 00.00', 'termino', 0), $x('2026-11-22 03.00', 'detuvo', 0), $x('2026-11-22 04.02', 'termino', 1), $x('2026-11-21 22.00', 'termino', 0)];
$tu = turnos_del_dia($t('2026-11-22 00:00'), $corridas, $t('2026-11-22 08:00'));
ok_e(count($tu) === 12 && array_column($tu, 'estado') === ['ok', 'det', 'rev', 'curso', 'pend', 'pend', 'pend', 'pend', 'pend', 'pend', 'pend', 'pend'], 'turnos: a las 8:00, tres corridas, la de las 7:30 en curso');
$tu = turnos_del_dia($t('2026-11-22 00:00'), $corridas, $t('2026-11-22 10:00'));
ok_e($tu[3]['estado'] === 'falta' && $tu[4]['estado'] === 'curso', 'turnos: a las 10:00, la de las 7:30 no llegó');
ok_e($tu[2]['corrida']['clave'] === '2026-11-22_04.02' && $tu[2]['tipo'] === 'e120', 'turnos: una corrida que empezó tarde le toca a su hora');
$tu = turnos_del_dia($t('2026-12-01 00:00'), [], $t('2026-12-01 00:30'));
ok_e(count($tu) === 11 && $tu[1]['tipo'] === 'e365', 'turnos: el día 1, 11 corridas con la Extendida 365');
ok_e(hora_turno($t('2026-11-22 00:00')) === '12:00a' && hora_turno($t('2026-11-22 15:00')) === '3:00p', 'turnos: la hora corta');

// El registro que queda un año, sin números de contrato (Iván, 22-nov)
ok_e(tapar_numeros('contrato 1234567 · REVISAR · MGA 07768 · 22NOV26') === 'contrato 9999999 · REVISAR · MGA 07768 · 22NOV26', 'registro: el contrato se tapa; la sucursal y el día no');
ok_e(explicar_caso(['p' => 'R', 'texto' => tapar_numeros('contrato 1234567 · REVISAR'), 'motivo' => 'la página 1 no trae impuesto'])['id'] === 'recibo', 'registro: tapado, se sigue explicando');

echo "\nexplicar: $total comprobaciones, $fallas fallas\n";
exit($fallas > 0 ? 1 : 0);
