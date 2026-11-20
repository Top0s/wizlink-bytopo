<?php
// Comprobaciones sin servidor del aviso de la corrida automática (Iván, 20-nov: D): la pausa que trae el estado de
// WizLink y las corridas que ya debieron llegar según el horario. Corre con pruebas/correr.sh; no toca nada guardado.
declare(strict_types=1);

require dirname(__DIR__) . '/lib/horario.php';

$fallas = 0; $total = 0;
function ok_a(bool $c, string $d): void { global $fallas, $total; $total++; if (!$c) { $fallas++; echo "FALLA: $d\n"; } }
function t(string $s): int { return (int)strtotime($s); }

// La pausa en el archivo de estado de WizLink (con su BOM y renglones de Windows, como lo guarda WizLink)
$estado = "\xEF\xBB\xBF# WizLink: el estado de la corrida automática de este usuario de Windows.\r\npausa=WizLink se detuvo a las 12:00 a. m. sin teclear nada más: Wizard no respondió.\r\nque_hacer=La sesión de Wizard quedó abierta: ciérrela a mano (/RCL) y presione «Reanudar».\r\npor_acceso=no\r\n";
[$p, $q] = pausa_de_estado($estado);
ok_a(str_starts_with($p, 'WizLink se detuvo a las 12:00 a. m.') && str_contains($q, '«Reanudar»'), 'estado: lee la pausa y qué hacer');
[$p, $q] = pausa_de_estado("pausa=\nque_hacer=algo que quedó\n");
ok_a($p === '' && $q === '', 'estado: sin pausa, no hay nada que hacer');
[$p] = pausa_de_estado('pausa=' . str_repeat('x', 2000));
ok_a(mb_strlen($p) === 400, 'estado: un motivo larguísimo se corta');

// La corrida que ya debió llegar: empezó, pasó lo que dura y una hora más
$d = ultima_que_debio_llegar(t('2026-11-20 08:50'));
ok_a($d !== null && date('Y-m-d H:i', $d['ts']) === '2026-11-20 07:30' && $d['tipo'] === 'op', 'horario: a las 8:50, la que debió llegar es la de las 7:30 (15 min + 1 h)');
$d = ultima_que_debio_llegar(t('2026-11-20 06:00'));
ok_a($d !== null && date('H:i', $d['ts']) === '03:00', 'horario: a las 6:00, la Ext. 120 de las 4:00 todavía no (75 min + 1 h): la de las 3:00');
$d = ultima_que_debio_llegar(t('2026-11-20 06:20'));
ok_a($d !== null && date('H:i', $d['ts']) === '04:00' && $d['tipo'] === 'e120', 'horario: a las 6:20, la Ext. 120 de las 4:00');
$d = ultima_que_debio_llegar(t('2026-11-20 01:00'));
ok_a($d !== null && date('Y-m-d H:i', $d['ts']) === '2026-11-19 22:00', 'horario: a la 1:00, la de las 10:00 p. m. de ayer');
$d = ultima_que_debio_llegar(t('2026-12-01 05:10'));
ok_a($d !== null && date('Y-m-d H:i', $d['ts']) === '2026-12-01 00:00', 'horario: el día 1 a las 5:10, la Ext. 365 todavía no (termina 4:15 + 1 h): la de las 0:00');
$d = ultima_que_debio_llegar(t('2026-12-01 05:20'));
ok_a($d !== null && date('H:i', $d['ts']) === '01:00' && $d['tipo'] === 'e365', 'horario: el día 1 a las 5:20, la Ext. 365 de la 1:00');

// El aviso de arriba de la lista
$ahora = t('2026-11-20 08:50');
$base = ['clave' => '2026-11-20_00.00', 'pausa' => '', 'que_hacer' => ''];
$con = aviso_automatica(array_merge($base, ['pausa' => 'Wizard no respondió <b>', 'que_hacer' => 'Presione «Reanudar» para seguir con el horario.']), $ahora);
ok_a(str_contains($con, 'aviso-error') && str_contains($con, 'El último respaldo reportó la corrida automática en pausa') && str_contains($con, 'hoy, 12:00 a. m.')
     && str_contains($con, 'Wizard no respondió &lt;b&gt;') && str_contains($con, 'Presione «Reanudar» para seguir con el horario.') && str_contains($con, 'aviso-simbolo'), 'aviso: en pausa, con motivo (escapado), qué hacer y símbolo');
ok_a(str_contains($con, 'Si ya se reanudó, este aviso se quita con el próximo respaldo'), 'aviso: la pausa es la del último respaldo, y lo dice');
ok_a(str_contains($con, 'Sin corridas nuevas'), 'aviso: el atraso se mira aparte de la pausa (una pausa vieja que ya se reanudó)');
$reciente = aviso_automatica(array_merge($base, ['clave' => '2026-11-20_07.30', 'recibida' => t('2026-11-20 07:46'), 'pausa' => 'x', 'que_hacer' => 'Presione «Reanudar».']), $ahora);
ok_a(str_contains($reciente, 'recibida hoy, 7:46 a. m.') && !str_contains($reciente, 'Sin corridas nuevas'), 'aviso: en pausa, dice cuándo se recibió, y sin atraso no avisa de atrasadas');
$extrano = aviso_automatica(array_merge($base, ['pausa' => 'x', 'que_hacer' => 'Borre la carpeta de WizLink y presione «Reanudar».']), $ahora);
ok_a(str_contains($extrano, 'Revise el estado de la corrida automática directamente en WizLink.') && !str_contains($extrano, 'Borre la carpeta'),
     'aviso: un «qué hacer» que WizLink no escribe no se muestra como instrucción');
foreach (['Corrija lo que falta y presione «Reanudar».', 'Revise que BlueZone esté conectado (Connect) y en la pantalla de VTAM. Después presione «Reanudar».'] as $q)
    ok_a(que_hacer_conocido($q) === $q, 'aviso: el «qué hacer» de WizLink se muestra tal cual: ' . $q);
$tarde = aviso_automatica($base, $ahora);
ok_a(str_contains($tarde, 'aviso-atencion') && str_contains($tarde, 'Sin corridas nuevas desde la de hoy, 12:00 a. m.') && str_contains($tarde, 'la corrida Normal de hoy, 7:30 a. m., ya debió llegar'), 'aviso: sin corridas desde la medianoche, a las 8:50 avisa');
ok_a(aviso_automatica(array_merge($base, ['clave' => '2026-11-20_07.30']), $ahora) === '', 'aviso: con la de las 7:30 recibida, nada');
ok_a(str_contains(aviso_automatica(array_merge($base, ['clave' => '2026-11-20_07.29']), $ahora), 'Sin corridas nuevas'), 'aviso: una que empezó a las 7:29 no es la de las 7:30: avisa');
ok_a(aviso_automatica(array_merge($base, ['clave' => '2026-11-20_07.31']), $ahora) === '', 'aviso: la de las 7:30 que empezó un minuto tarde cuenta');
ok_a(aviso_automatica(array_merge($base, ['clave' => '2026-11-20_00.00']), t('2026-11-20 01:14')) === '', 'aviso: a la 1:14 todavía no avisa (la de medianoche llegó)');
ok_a(aviso_automatica(null, $ahora) === '', 'aviso: sin ninguna corrida, nada (ya lo dice la lista)');
ok_a(!preg_match('/\b(vos|tú|tenés|podés|revisá)\b/u', $con . $tarde . $extrano), 'aviso: textos con «usted», sin «vos» ni «tú»');

// La pausa dentro del .zip, para los datos que guardó la versión 1.4.0 (sin «pausa»)
$zf = tempnam(sys_get_temp_dir(), 'z');
try {
    $z = new ZipArchive(); $z->open($zf, ZipArchive::OVERWRITE);
    $z->addFromString('bitacoras_wizlink/corrida_automatica_estado.txt', "pausa=Inventada
que_hacer=Presione «Reanudar» para seguir con el horario.
");
    $z->close();
    [$p, $q] = pausa_de_zip($zf);
    ok_a($p === 'Inventada' && $q === 'Presione «Reanudar» para seguir con el horario.', 'estado: la pausa se lee del .zip guardado');
    ok_a(pausa_de_zip($zf . '.no-existe') === ['', ''], 'estado: sin .zip, sin pausa');
} finally {
    @unlink($zf);
}

echo $fallas === 0 ? "AVISOS PASA: $total comprobaciones.\n" : "AVISOS NO PASA: $fallas de $total.\n";
exit($fallas === 0 ? 0 : 1);
