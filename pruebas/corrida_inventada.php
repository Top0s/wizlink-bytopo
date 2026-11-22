<?php
// Una corrida de WizLink inventada, con la forma de las de verdad (resumen, diagnóstico por partes y control), para las
// pruebas. Los datos se ven inventados: sucursales y días de ejemplo, sin clientes. Nunca una corrida real (CLAUDE.md, regla 1).
declare(strict_types=1);

/**
 * El .zip (sus bytes) de una corrida inventada. $op:
 *   'tipo' => 'op' | 'e120' | 'e365', 'segundos' => duración, 'resultado' => 'termino' | 'detuvo',
 *   'casos' => [['R', 'flota · MGA 07768 · 22NOV26 · REVISAR · leídas 31', 'TOTAL ON-HAND (25) no coincide con la columna TOT (24)'], …],
 *   'sin_diagnostico' => true (como WizLink antes del 12-nov), 'impresos' => n.
 */
function corrida_inventada(string $corrida, array $op = []): string
{
    $tipo = $op['tipo'] ?? 'op';
    $seg = (int)($op['segundos'] ?? 948);
    $casos = $op['casos'] ?? [];
    $detuvo = ($op['resultado'] ?? 'termino') === 'detuvo';
    $lectura = ['op' => '', 'e120' => "Lectura: Extendida 120\r\n", 'e365' => "Lectura: Extendida 365\r\n"][$tipo];
    $inicio = substr($corrida, 0, 10) . ' ' . str_replace('.', ':', substr($corrida, 11, 5)) . ':00';
    $resumen = "\xEF\xBB\xBFWIZLINK · 1.0.23 · RESUMEN DE LA CORRIDA\r\n" . $lectura
        . "Inicio: $inicio   Fin: 00:00:00   Duración: " . intdiv($seg, 60) . ' min ' . ($seg % 60) . " s\r\n"
        . 'Resultado: ' . ($detuvo ? 'SE DETUVO: BlueZone se desconectó del mainframe a las 03:07:40.' : 'TERMINÓ') . "\r\n"
        . "Datos de clientes en los archivos: sí\r\n\r\n"
        . 'Archivos para Unify: 10 contratos, 20 reservas, 30 filas de flota. Impresos leídos: ' . (int)($op['impresos'] ?? 38) . " contratos y 4 recibos.\r\n";
    $partes = ['P' => 'PROTECCIÓN', 'E' => 'ERROR', 'S' => 'SEGURIDAD', 'R' => 'REVISIÓN', 'N' => 'ENTORNO'];
    $diag = "\xEF\xBB\xBFWIZLINK · DIAGNÓSTICO DE LA CORRIDA\r\nCorrida: $inicio · 1.0.23\r\n" . $lectura . "Resultado: " . ($detuvo ? 'se detuvo (entorno)' : 'terminó') . "\r\n";
    foreach ($partes as $l => $nombre) {
        $diag .= "\r\n== $nombre ==\r\n";
        $n = 0;
        foreach ($casos as [$p, $titulo, $motivo]) {
            if ($p !== $l) continue;
            $diag .= '[' . $l . (++$n) . '] ' . $titulo . "\r\n" . ($motivo !== '' ? "     Motivo: $motivo\r\n" : '') . "     Se guardó: pantallas/ejemplo_p01.txt\r\n     Pantalla (tapada):\r\n       | VARAVL  XXXXXX 9999\r\n";
        }
        if ($n === 0) $diag .= "(nada)\r\n";
    }
    $diag .= "\r\n== NOTAS DE LA CORRIDA ==\r\n(nada)\r\n";
    $control = "\xEF\xBB\xBFtipo,estacion_codigo,estacion_mnemonico,sucursal,dia,estado,filas,total_wizard,parciales,paginas,segundos,motivo,extraido_en,version_extractor\r\n";
    for ($i = 0; $i < 10; $i++) $control .= "reservas,07768,MGA,Aeropuerto,22NOV26,OK,5,,0,1,3,,$inicio,1.0.23\r\n";
    foreach ($casos as [$p, $titulo, $motivo]) {
        if ($p !== 'R' || !preg_match('/^([a-z]+) · ([A-Z0-9]+) (\d{5})/u', $titulo, $m)) continue;
        $control .= "{$m[1]},{$m[3]},{$m[2]},Ejemplo,,REVISAR,0,,0,1,3,\"" . str_replace('"', '""', $motivo) . "\",$inicio,1.0.23\r\n";
    }
    $f = tempnam(sys_get_temp_dir(), 'z');
    try {
        $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
        $z->addFromString($corrida . '/evidencia/resumen.txt', $resumen);
        if (!($op['sin_diagnostico'] ?? false)) $z->addFromString($corrida . '/evidencia/diagnostico.txt', $diag);
        $z->addFromString($corrida . '/control_' . str_replace([' ', '.', '-'], '', $corrida) . '.csv', $control);
        $z->addFromString($corrida . '/evidencia/bitacora.txt', "Pausa antes de cada Enter, PA1 o PF8: 0.5 s\r\n00:00:01  #1  VTAM  ·  inventada\r\n");
        $z->addFromString($corrida . '/reservas_ejemplo.csv', "numero_reserva\r\nPRUEBA01\r\n");
        $z->addFromString('bitacoras_wizlink/corrida_automatica.txt', "inventada\r\n");
        $z->close();
        return (string)file_get_contents($f);
    } finally {
        @unlink($f);
    }
}

/** Los casos de ejemplo, tal como los escribe WizLink. */
const CASO_FLOTA = ['R', 'flota · MGA 07768 · 22NOV26 · REVISAR · leídas 31', 'TOTAL ON-HAND (25) no coincide con la columna TOT (24)'];
const CASO_IMPRESORA = ['R', 'impresion · MN5 07769 · REVISAR', 'Wizard dijo "FREE FORM DOCUMENT SENT TO PRINTER", pero el contrato impreso no apareció en la carpeta de la impresora (3 intentos)'];
const CASO_IMPRESORA2 = ['R', 'impresion · LEO 07770 · NO SE CORRIÓ', 'No se imprimen más contratos: revise que la impresora de BlueZone (P2) esté abierta y en "Generic / Text Only".'];
const CASO_DESCONEXION = ['N', 'Se detuvo a las 03:07:40 · flota · CH0 07771 · 22NOV26', 'BlueZone se desconectó del mainframe: la sesión de Wizard se cerró con la desconexión y WizLink no tecleó nada más.'];
const CASO_NUEVO = ['R', 'reservas · RIV 07772 · 23NOV26 · REVISAR · leídas 3', 'un caso que nadie había visto'];
