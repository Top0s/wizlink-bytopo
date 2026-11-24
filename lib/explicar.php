<?php
// wizlink.bytopo.net · lo que pasó en cada corrida, en palabras (Iván, 22-nov: R6).
//   - Al recibir una corrida se leen tres archivos de adentro del .zip (sin sacar nada a disco): el resumen (tipo de
//     corrida y duración), el diagnóstico por partes de WizLink (`evidencia/diagnostico.txt`, app/nucleo/Diagnostico.cs de
//     ZaFi-Online/WizLink) y el control (una fila por consulta). Se guardan solo los títulos y el primer motivo de cada
//     caso, que WizLink ya escribe sin datos de clientes.
//   - La explicación se arma al mostrar, con la lista de casos conocidos de abajo (los de `docs/DIAGNOSTICAR_CORRIDA.md`
//     de WizLink, sección 5). Así, un caso nuevo se agrega aquí y se ve también en las corridas ya guardadas.
//   - Un caso que no está en la lista se muestra con el texto del mismo diagnóstico.
//   - Solo lee: no cambia nada ni le avisa nada a WizLink.

declare(strict_types=1);

require_once __DIR__ . '/base.php';

/** Cuántos casos se guardan por corrida, como máximo (una corrida con la impresora caída trae uno por sucursal). */
const MAXIMO_CASOS = 30;

/** Las partes del diagnóstico de WizLink, por su letra. */
const PARTES_DIAGNOSTICO = [
    'PROTECCIÓN' => 'P', 'ERROR' => 'E', 'SEGURIDAD' => 'S', 'REVISIÓN' => 'R', 'ENTORNO' => 'N',
];

// ------------------------------------------------------------------ leer lo que trae la corrida

/** El tipo de corrida que dice el resumen: 'op' (Operativa, que no lleva la línea), 'e120' o 'e365'. */
function tipo_de_resumen(string $resumen): string
{
    if (preg_match('/^Lectura: Extendida 365/mu', $resumen)) return 'e365';
    if (preg_match('/^Lectura: Extendida 120/mu', $resumen)) return 'e120';
    return 'op';
}

/** Los segundos que duró, de «Duración: 74 min 32 s» (o del «Inicio» y el «Fin» si no está). -1 si no se sabe. */
function segundos_de_resumen(string $resumen): int
{
    if (preg_match('/Duración: (\d+) min (\d+) s/u', $resumen, $m)) return (int)$m[1] * 60 + (int)$m[2];
    return -1;
}

/**
 * Los casos del diagnóstico de WizLink: [['p' => 'R', 'texto' => título, 'motivo' => primer motivo], …]. Cada caso empieza
 * con «[R1] …» dentro de su parte («== REVISIÓN ==»); los renglones de abajo con sangría traen el motivo, los archivos y la
 * pantalla tapada, que aquí no se guardan.
 */
function casos_de_diagnostico(string $texto): array
{
    $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto) ?? '';
    $casos = [];
    $parte = '';
    $actual = null;
    foreach (preg_split('/\r?\n/', $texto) ?: [] as $l) {
        if (preg_match('/^== (.+) ==$/u', $l, $m)) {
            if ($actual !== null) { $casos[] = $actual; $actual = null; }
            $parte = PARTES_DIAGNOSTICO[$m[1]] ?? '';
            continue;
        }
        if ($parte === '') continue;
        if (preg_match('/^\[([PESRN])\d+\] (.*)$/u', $l, $m) && $m[1] === $parte) {
            if ($actual !== null) $casos[] = $actual;
            $actual = ['p' => $parte, 'texto' => recortar($m[2], 300), 'motivo' => ''];
            continue;
        }
        if ($actual !== null && $actual['motivo'] === '' && preg_match('/^\s+Motivo: (.*)$/u', $l, $m)) $actual['motivo'] = recortar($m[1], 300);
    }
    if ($actual !== null) $casos[] = $actual;
    return array_slice($casos, 0, MAXIMO_CASOS);
}

/**
 * Para una corrida sin diagnóstico (de una versión de WizLink anterior al 12-nov): los casos salen del control, una fila por
 * consulta que no quedó en OK. Con la misma forma que el diagnóstico: «flota · MGA 07768 · 22NOV26 · REVISAR».
 */
function casos_de_control(array $filas): array
{
    $casos = [];
    foreach ($filas as $c) {
        if (($c['estado'] ?? 'OK') === 'OK') continue;
        $donde = trim(($c['estacion_mnemonico'] ?? '') . ' ' . ($c['estacion_codigo'] ?? ''));
        $texto = ($c['tipo'] ?? '') . ' · ' . $donde . (($c['dia'] ?? '') !== '' ? ' · ' . $c['dia'] : '') . ' · ' . ($c['estado'] ?? '');
        $casos[] = ['p' => 'R', 'texto' => recortar($texto, 300), 'motivo' => recortar((string)($c['motivo'] ?? ''), 300)];
        if (count($casos) >= MAXIMO_CASOS) break;
    }
    return $casos;
}

function recortar(string $s, int $n): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

// ------------------------------------------------------------------ los casos conocidos

/**
 * Cada caso conocido: con qué se reconoce (en el título y el motivo del diagnóstico), qué título lleva, qué pasó y qué
 * hacer, en palabras. 'grave': true si detiene la corrida o la deja sin datos. 'por_sucursal': true si cada sucursal es un
 * problema aparte (la flota de una sucursal); false si junta todas (la impresora es una sola para todas).
 * El orden importa: gana el primero que coincide.
 */
function casos_conocidos(): array
{
    return [
        ['id' => 'seguridad', 'parte' => 'S', 'busca' => '//', 'grave' => true, 'por_sucursal' => false,
         'titulo' => 'Wizard mostró un aviso de contraseña, PIN o usuario',
         'que' => 'WizLink se detuvo sin teclear nada más y borró la contraseña recordada. La corrida automática quedó en pausa.',
         'hacer' => 'En la máquina virtual, inicie sesión en WizLink y marque «Recordar en esta PC». Si Wizard pide una contraseña nueva, cámbiela primero en BlueZone.'],
        ['id' => 'proteccion', 'parte' => 'P', 'busca' => '//', 'grave' => true, 'por_sucursal' => false,
         'titulo' => 'WizLink se negó a teclear (protección)',
         'que' => 'La pantalla, el campo o el cursor no eran los esperados, y WizLink se detuvo sin teclear nada más.',
         'hacer' => 'Nada se tecleó de más. Pida la revisión en WizLink con este diagnóstico.'],
        ['id' => 'desconexion', 'busca' => '/BlueZone se desconectó/u', 'grave' => true, 'por_sucursal' => false,
         'titulo' => 'BlueZone perdió la conexión con el mainframe',
         'que' => 'La sesión de Wizard se cerró con la desconexión y WizLink no tecleó nada más. No queda en pausa: la corrida siguiente entra a su hora.',
         'hacer' => 'Nada, si la corrida siguiente llega. Si se repite, revise la red de la máquina virtual y que BlueZone diga «Connected».'],
        ['id' => 'no_respondio', 'busca' => '/Wizard no respondió/u', 'grave' => true, 'por_sucursal' => false,
         'titulo' => 'Wizard no respondió',
         'que' => 'Wizard no soltó el teclado después de tres esperas y WizLink se detuvo sin teclear nada más.',
         'hacer' => 'Revise BlueZone en la máquina virtual: puede estar congelado o con un aviso abierto.'],
        ['id' => 'vigilante', 'busca' => '/Vigilante/u', 'grave' => true, 'por_sucursal' => false,
         'titulo' => 'La corrida pasó 10 minutos sin avanzar',
         'que' => 'El vigilante la detuvo sin teclear nada más; WizLink se volvió a abrir y la corrida empezó de nuevo.',
         'hacer' => 'Nada, si la corrida que vuelve a empezar termina. A la tercera seguida queda en pausa: revise BlueZone.'],
        ['id' => 'sesion_abierta', 'busca' => '/sesión de Wizard (no se cerró|quedó abierta)/u', 'grave' => true, 'por_sucursal' => false,
         'titulo' => 'La sesión de Wizard quedó abierta',
         'que' => 'WizLink no pudo cerrar la sesión de Wizard al terminar.',
         'hacer' => 'En la máquina virtual, ciérrela a mano (/RCL) y presione «Reanudar».'],
        ['id' => 'no_empezo', 'busca' => '/^No empezó/u', 'grave' => true, 'por_sucursal' => false,
         'titulo' => 'La corrida no pudo empezar',
         'que' => 'Algo de afuera de Wizard no estaba listo y WizLink no tecleó nada.',
         'hacer' => 'Lea el motivo en el diagnóstico. A la tercera seguida, la corrida automática queda en pausa.'],
        ['id' => 'unify', 'parte' => 'N', 'busca' => '/Unify/u', 'grave' => true, 'por_sucursal' => false,
         'titulo' => 'Un envío a ABP Unify no llegó',
         'que' => 'Los archivos de la corrida no llegaron a uno de los servidores de Unify.',
         'hacer' => 'WizLink la vuelve a mandar al terminar la corrida siguiente. Si se repite, revise la red de la máquina virtual o la llave de Unify.'],
        ['id' => 'no_autorizado', 'busca' => '/NOT AUTHORIZED|UNAUTHORIZED|no autorizad/iu', 'grave' => false, 'por_sucursal' => true,
         'titulo' => 'El usuario de Wizard no tiene permiso para una sucursal',
         'que' => 'Wizard respondió «no autorizado». WizLink no insistió con esa sucursal.',
         'hacer' => 'Pida a Informática el permiso de esa sucursal para el usuario de Wizard de la máquina virtual.'],
        ['id' => 'impresora', 'busca' => '/no apareció el impreso|impresora de BlueZone|carpeta de la impresora|no llegó a la carpeta de/u', 'grave' => false, 'por_sucursal' => false,
         'titulo' => 'La impresora P2 de BlueZone no imprimió',
         'que' => 'Wizard mandó los contratos a imprimir, pero no llegaron a la carpeta de impresos de BlueZone.',
         'hacer' => 'En la máquina virtual, revise que la sesión de impresora P2 de BlueZone diga «Connected»; si no, presione Connect.'],
        ['id' => 'flota_total', 'busca' => '/no coincide con la columna TOT/u', 'grave' => false, 'por_sucursal' => true,
         'titulo' => 'La flota no cuadró con la línea TOTAL de Wizard',
         'que' => 'Suele ser una reserva justo a las 12:00 a. m. o carros que llegan con reservas de una vía (RES D/I). Los demás datos llegaron a Unify.',
         'hacer' => 'Nada en la máquina virtual. Si se repite en varias corridas, pida el arreglo en WizLink.'],
        ['id' => 'flota_paginas', 'busca' => '/VARAVL no llegó a la última página/u', 'grave' => false, 'por_sucursal' => true,
         'titulo' => 'La flota quedó incompleta',
         'que' => 'WizLink no llegó a la última página de VARAVL (el tope de páginas).',
         'hacer' => 'Si se repite, pida la revisión en WizLink con este diagnóstico.'],
        ['id' => 'reservas_fin', 'busca' => '/no llegó a END OF REPORT/u', 'grave' => false, 'por_sucursal' => true,
         'titulo' => 'Las reservas quedaron incompletas',
         'que' => 'El manifiesto no llegó a END OF REPORT (el tope de páginas).',
         'hacer' => 'Si se repite, pida la revisión en WizLink con este diagnóstico.'],
        // Un abierto que se cerró entre la lectura de la lista y su impresión: Wizard da el recibo (Iván, 24-nov). La corrida
        // siguiente lo lee entre los cerrados.
        ['id' => 'cerrado_en_corrida', 'busca' => '/RECIBO: el contrato ya se cerró/u', 'grave' => false, 'por_sucursal' => false,
         'titulo' => 'Un contrato se cerró durante la corrida',
         'que' => 'Estaba en la lista de abiertos, pero se cerró antes de que WizLink lo imprimiera: Wizard dio el recibo con los cargos reales. No es un error de lectura.',
         'hacer' => 'Nada: la corrida siguiente lo lee entre los cerrados, con su recibo.'],
        ['id' => 'recibo', 'busca' => '/^(recibos|contrato) ·|NO CUADRA|no trae impuesto/u', 'grave' => false, 'por_sucursal' => true,
         'titulo' => 'Un recibo o un contrato no cuadró por dentro',
         'que' => 'WizLink no pudo leer con seguridad un recibo o un contrato impreso y lo dejó en revisar.',
         'hacer' => 'Revise el caso en el diagnóstico. Los prepagos internacionales y los recibos exentos de impuesto ya se conocen.'],
    ];
}

/** Los nombres de las consultas como los escribe WizLink, en palabras. */
const NOMBRES_CONSULTA = [
    'reservas' => 'Las reservas', 'contratos' => 'Los contratos abiertos', 'cerrados' => 'Los contratos cerrados',
    'flota' => 'La flota', 'recibos' => 'Los recibos', 'impresion' => 'La impresión', 'contrato' => 'Un contrato',
];

/** La sucursal de un caso («MGA 07768»), si el título la trae: «flota · MGA 07768 · 22NOV26 · REVISAR». */
function sucursal_de(string $texto): string
{
    return preg_match('/^[a-z]+ · ([A-Z0-9]{2,6} \d{5})\b/u', $texto, $m) ? $m[1] : '';
}

/** El día de un caso («22NOV26»), si el título lo trae. */
function dia_de(string $texto): string
{
    return preg_match('/ · (\d{2}[A-Z]{3}\d{2})\b/u', $texto, $m) ? $m[1] : '';
}

/**
 * Un caso del diagnóstico, explicado: ['id', 'clave' (para juntar los repetidos), 'titulo', 'que', 'hacer', 'grave',
 * 'donde', 'dia', 'original' (el texto de WizLink)].
 */
function explicar_caso(array $c): array
{
    $texto = (string)($c['texto'] ?? '');
    $motivo = (string)($c['motivo'] ?? '');
    $parte = (string)($c['p'] ?? '');
    $donde = sucursal_de($texto);
    $dia = dia_de($texto);
    foreach (casos_conocidos() as $k) {
        if (isset($k['parte']) && $k['parte'] !== $parte) continue;
        if (!preg_match($k['busca'], $texto) && !preg_match($k['busca'], $motivo)) continue;
        return ['id' => $k['id'], 'clave' => $k['id'] . ($k['por_sucursal'] ? '|' . $donde : ''), 'titulo' => $k['titulo'], 'que' => $k['que'],
                'hacer' => $k['hacer'], 'grave' => $k['grave'] || in_array($parte, ['P', 'E', 'S'], true), 'donde' => $k['por_sucursal'] ? $donde : '',
                'dia' => $dia, 'original' => $texto, 'motivo' => $motivo];
    }
    // Un caso que no está en la lista: se dice con las palabras del diagnóstico
    $consulta = preg_match('/^([a-z]+) ·/u', $texto, $m) ? $m[1] : '';
    $titulo = isset(NOMBRES_CONSULTA[$consulta]) ? NOMBRES_CONSULTA[$consulta] . ' no ' . ($consulta === 'contrato' ? 'se pudo leer con seguridad' : 'cuadraron con Wizard')
        : ['P' => 'Protección', 'E' => 'Error', 'S' => 'Seguridad', 'R' => 'Revisión', 'N' => 'Entorno'][$parte] . ': ' . recortar($texto, 120);
    if (in_array($consulta, ['flota', 'impresion'], true)) $titulo = NOMBRES_CONSULTA[$consulta] . ' no cuadró con Wizard';
    return ['id' => 'otro', 'clave' => 'otro|' . $parte . '|' . $consulta . '|' . $donde, 'titulo' => $titulo,
            'que' => $motivo !== '' ? 'WizLink dice: ' . $motivo : 'WizLink dice: ' . $texto,
            'hacer' => 'Revise el caso en el diagnóstico de la corrida.', 'grave' => in_array($parte, ['P', 'E', 'S'], true),
            'donde' => $donde, 'dia' => $dia, 'original' => $texto, 'motivo' => $motivo];
}

/** Los casos de una corrida, explicados y juntados: uno por clave, con cuántas veces salió y en qué sucursales. */
function casos_explicados(array $d): array
{
    $grupos = [];
    foreach (($d['casos'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $e = explicar_caso($c);
        if (!isset($grupos[$e['clave']])) { $e['veces'] = 0; $e['sucursales'] = []; $grupos[$e['clave']] = $e; }
        $grupos[$e['clave']]['veces']++;
        $s = sucursal_de($e['original']);
        if ($s !== '' && !in_array($s, $grupos[$e['clave']]['sucursales'], true)) $grupos[$e['clave']]['sucursales'][] = $s;
    }
    // Primero lo grave
    uasort($grupos, fn($a, $b) => (int)$b['grave'] <=> (int)$a['grave']);
    return array_values($grupos);
}

/**
 * Cuántas cosas quedaron en revisar en una corrida (Iván, 24-nov): los casos de la parte REVISIÓN del diagnóstico, que
 * traen las consultas y también los contratos o recibos sueltos («y 3 contratos o recibos más» cuenta 3). Las consultas
 * del control que no quedaron en OK cuentan si son más (una corrida sin diagnóstico o con más casos de los que se guardan).
 */
function en_revisar(array $d): int
{
    $n = 0;
    foreach (($d['casos'] ?? []) as $c) {
        if (!is_array($c) || ($c['p'] ?? '') !== 'R') continue;
        $n += preg_match('/^y (\d{1,5}) contratos/u', (string)($c['texto'] ?? ''), $m) ? (int)$m[1] : 1;
    }
    return max($n, (int)($d['revisar'] ?? 0));
}

/** «en MGA 07768», «en 6 sucursales» o ''. */
function donde_texto(array $e): string
{
    if ($e['donde'] !== '') return 'en ' . $e['donde'] . ($e['dia'] !== '' ? ', día ' . $e['dia'] : '');
    $n = count($e['sucursales'] ?? []);
    if ($n > 1) return 'en ' . $n . ' sucursales';
    if ($n === 1) return 'en ' . $e['sucursales'][0];
    return '';
}

// ------------------------------------------------------------------ para mostrar

/** «15 min 48 s» si duró menos de una hora; «1 h 14 min 32 s» si duró más (Iván, 22-nov). '' si no se sabe. */
function duracion_texto(int $s): string
{
    if ($s < 0) return '';
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $seg = $s % 60;
    return $h > 0 ? sprintf('%d h %02d min %02d s', $h, $m, $seg) : sprintf('%d min %02d s', $m, $seg);
}

/** Los segundos que duró una corrida guardada (los datos de antes del 22-nov traían solo «74 min 32 s»). */
function segundos_de(array $d): int
{
    if (isset($d['segundos']) && is_int($d['segundos'])) return $d['segundos'];
    return preg_match('/^(\d+) min (\d+) s$/', (string)($d['duracion'] ?? ''), $m) ? (int)$m[1] * 60 + (int)$m[2] : -1;
}

/** El nombre de cada tipo de corrida, como en WizLink. */
const NOMBRES_TIPO = ['op' => 'Operativa', 'e120' => 'Extendida 120', 'e365' => 'Extendida 365'];

function tipo_de(array $d): string
{
    $t = (string)($d['tipo'] ?? 'op');
    return isset(NOMBRES_TIPO[$t]) ? $t : 'op';
}

/** La línea que explica una corrida en la lista (HTML ya escapado). */
function explicacion_corta(array $d): string
{
    $casos = casos_explicados($d);
    if (count($casos) === 0) {
        if (($d['resultado'] ?? '') === 'detuvo') return '<b>Se detuvo:</b> ' . h(recortar((string)($d['detalle'] ?? ''), 200));
        if (($d['resultado'] ?? '') === 'sin_resumen') return 'La corrida llegó sin su resumen: no se sabe cómo terminó.';
        if ((int)($d['revisar'] ?? 0) > 0) return h((int)$d['revisar'] . ((int)$d['revisar'] === 1 ? ' consulta quedó' : ' consultas quedaron') . ' en revisar. Vea el control de la corrida.');
        return 'Todo cuadró con Wizard.';
    }
    $partes = [];
    foreach (array_slice($casos, 0, 2) as $e) {
        $donde = donde_texto($e);
        $partes[] = '<b>' . h($e['titulo']) . ($donde !== '' ? ' (' . h($donde) . ')' : '') . '.</b> ' . h($e['que']);
    }
    if (count($casos) > 2) $partes[] = h('Y ' . (count($casos) - 2) . (count($casos) - 2 === 1 ? ' caso más.' : ' casos más.'));
    return implode(' ', $partes);
}

/** Cambia cada serie de 6 o más dígitos por 9 del mismo largo («contrato 1234567» → «contrato 9999999»). */
function tapar_numeros(string $s): string
{
    return (string)preg_replace_callback('/\d{6,}/', fn($m) => str_repeat('9', strlen($m[0])), $s);
}
