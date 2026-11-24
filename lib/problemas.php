<?php
// wizlink.bytopo.net · los problemas juntados (Iván, 22-nov: R6, pestaña «Problemas»), por cuándo pasaron (Iván, 24-nov: A2).
//   - Un problema es un caso del diagnóstico ya explicado (explicar.php): el mismo caso en varias corridas es uno solo.
//   - Abierto: lo trae la corrida más nueva. Ya no se repite: salió antes, pero la corrida más nueva ya no lo trae.
//   - La pestaña los separa en Hoy, Ayer, Esta semana (los 5 días antes de ayer) y, antes, por mes (el mes de hoy y el
//     anterior, con el registro de un año). Lo de hoy se marca aunque ya no se repita, para que no pase sin verse.
//   - Además, la corrida automática en pausa o sin corridas nuevas (el aviso de horario.php) cuenta como un problema abierto.
//   - Solo lee: no cambia nada ni le avisa nada a WizLink.

declare(strict_types=1);

require_once __DIR__ . '/almacen.php';
require_once __DIR__ . '/explicar.php';
require_once __DIR__ . '/horario.php';

/** Cuántos días mira la pestaña «Problemas»: los mismos que se guarda el .zip, para poder abrir el diagnóstico. */
function dias_problemas(): int
{
    return dias_guardar();
}

/**
 * Los problemas, juntados: ['abiertos' => [...], 'resueltos' => [...]]. Cada uno es un caso explicado (explicar_caso) con
 * 'corridas' (cuántas lo trajeron), 'primera' y 'ultima' (las claves de la primera y la última que lo trajo) y 'seguidas'
 * (cuántas corridas seguidas, contando desde la más nueva, lo traen). $corridas: la lista de listar_corridas(), la más
 * nueva primero.
 */
function juntar_problemas(array $corridas): array
{
    $grupos = [];
    $orden = 0;
    foreach ($corridas as $i => $d) {
        foreach (casos_explicados($d) as $e) {
            $k = $e['clave'];
            if (!isset($grupos[$k])) {
                $e['corridas'] = 0; $e['ultima'] = $d['clave']; $e['primera'] = $d['clave']; $e['seguidas'] = 0; $e['racha'] = true;
                $e['orden'] = $orden++;
                $grupos[$k] = $e;
            }
            $g = &$grupos[$k];
            $g['corridas']++;
            $g['primera'] = $d['clave'];
            foreach ($e['sucursales'] as $s) if (!in_array($s, $g['sucursales'], true)) $g['sucursales'][] = $s;
            unset($g);
        }
        // La racha: cuántas corridas seguidas, desde la más nueva, traen cada problema
        $aqui = array_map(fn($e) => $e['clave'], casos_explicados($d));
        foreach ($grupos as $k => &$g) {
            if (!$g['racha']) continue;
            if (in_array($k, $aqui, true) && $g['seguidas'] === $i) $g['seguidas']++;
            else $g['racha'] = false;
        }
        unset($g);
    }
    $abiertos = $resueltos = [];
    $nueva = $corridas[0]['clave'] ?? '';
    foreach ($grupos as $g) {
        if ($g['ultima'] === $nueva && $g['seguidas'] > 0) $abiertos[] = $g; else $resueltos[] = $g;
    }
    usort($abiertos, fn($a, $b) => [(int)$b['grave'], $a['orden']] <=> [(int)$a['grave'], $b['orden']]);
    return ['abiertos' => $abiertos, 'resueltos' => $resueltos];
}

/** Lo de la pestaña: las corridas de los últimos días, los problemas juntados y el aviso de la corrida automática. */
function estado_problemas(int $ahora): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $corridas = listar_corridas($ahora - dias_problemas() * 86400);
    $p = juntar_problemas($corridas);
    $p['corridas'] = $corridas;
    $p['aviso'] = aviso_automatica($corridas[0] ?? null, $ahora);
    return $cache = $p;
}

/**
 * El número de la pestaña «Problemas» (Iván, 24-nov): los problemas de hoy y los abiertos, cada uno una vez, más uno si
 * la corrida automática necesita a alguien.
 */
function contar_problemas(int $ahora): int
{
    $p = estado_problemas($ahora);
    $claves = array_map(fn($g) => $g['clave'], $p['abiertos']);
    $hoy = (int)strtotime('today', $ahora);
    foreach ($p['corridas'] as $d) {
        if (momento_de((string)$d['clave']) < $hoy) break;
        foreach (casos_explicados($d) as $e) $claves[] = $e['clave'];
    }
    return count(array_unique($claves)) + ($p['aviso'] !== '' ? 1 : 0);
}

// ------------------------------------------------------------------ por cuándo pasó (Iván, 24-nov: A2)

/** «18 nov», sin el año. */
function dia_mes(int $ts): string
{
    static $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return date('j', $ts) . ' ' . $meses[(int)date('n', $ts) - 1];
}

/** «noviembre». */
function mes_largo(int $ts): string
{
    static $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $meses[(int)date('n', $ts) - 1];
}

/**
 * Los periodos de la pestaña, del más nuevo al más viejo y sin cruzarse: cada uno con 'id', 'titulo', 'sub' (las fechas),
 * 'desde' y 'hasta' (hasta, sin contarlo) y 'cerrado' (los meses van cerrados). Hoy, Ayer y Esta semana siempre; el mes de
 * hoy antes de esa semana, si tiene días; y el mes anterior, lo que no cayó en la semana.
 */
function periodos_problemas(int $ahora): array
{
    $hoy = (int)strtotime('today', $ahora);
    $ayer = (int)strtotime('-1 day', $hoy);
    $semana = (int)strtotime('-6 days', $hoy);
    $finSemana = (int)strtotime('-1 day', $ayer);
    static $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $p = [
        ['id' => 'hoy', 'titulo' => 'Hoy', 'sub' => $dias[(int)date('w', $hoy)] . ' ' . dia_mes($hoy), 'desde' => $hoy, 'hasta' => PHP_INT_MAX, 'cerrado' => false],
        ['id' => 'ayer', 'titulo' => 'Ayer', 'sub' => $dias[(int)date('w', $ayer)] . ' ' . dia_mes($ayer), 'desde' => $ayer, 'hasta' => $hoy, 'cerrado' => false],
        ['id' => 'semana', 'titulo' => 'Esta semana', 'sub' => 'del ' . dia_mes($semana) . ' al ' . dia_mes($finSemana), 'desde' => $semana, 'hasta' => $ayer, 'cerrado' => false],
    ];
    $inicioMes = (int)strtotime(date('Y-m-01', $hoy));
    if ($inicioMes < $semana) {
        $p[] = ['id' => 'mes', 'titulo' => 'Antes en ' . mes_largo($inicioMes), 'sub' => 'del ' . dia_mes($inicioMes) . ' al ' . dia_mes((int)strtotime('-1 day', $semana)),
                'desde' => $inicioMes, 'hasta' => $semana, 'cerrado' => true];
    }
    $inicioAnterior = (int)strtotime(date('Y-m-01', (int)strtotime('-1 day', $inicioMes)));
    $hastaAnterior = min($inicioMes, $semana);
    $p[] = ['id' => 'anterior', 'titulo' => ucfirst(mes_largo($inicioAnterior)) . ' ' . date('Y', $inicioAnterior),
            'sub' => $hastaAnterior < $inicioMes ? 'hasta el ' . dia_mes((int)strtotime('-1 day', $hastaAnterior)) : '',
            'desde' => $inicioAnterior, 'hasta' => $hastaAnterior, 'cerrado' => true];
    return $p;
}

/**
 * Los problemas de cada periodo. $corridas: la lista de listar_corridas(), la más nueva primero, desde el primer día del
 * mes anterior. Devuelve los periodos con 'corridas' (cuántas llegaron) y 'problemas': cada uno un caso explicado con
 * 'corridas', 'primera' y 'ultima' de ese periodo, 'abierto' (lo trae la corrida más nueva de todas) y 'despues'
 * (cuántas corridas llegaron después de la última que lo trajo). También 'mes': cuántos problemas distintos van en el mes.
 */
function problemas_por_periodo(array $corridas, int $ahora): array
{
    // Cada corrida se explica una sola vez
    $explicadas = array_map(fn($d) => casos_explicados($d), $corridas);
    $nuevas = [];
    foreach ($explicadas[0] ?? [] as $e) $nuevas[$e['clave']] = true;
    $periodos = periodos_problemas($ahora);
    $inicioMes = (int)strtotime(date('Y-m-01', $ahora));
    $delMes = [];
    foreach ($corridas as $i => $d) {
        if (momento_de((string)$d['clave']) >= $inicioMes) foreach ($explicadas[$i] as $e) $delMes[$e['clave']] = true;
    }
    foreach ($periodos as &$per) {
        $per['corridas'] = 0;
        $grupos = [];
        foreach ($corridas as $i => $d) {
            $m = momento_de((string)$d['clave']);
            if ($m < $per['desde'] || $m >= $per['hasta']) continue;
            $per['corridas']++;
            foreach ($explicadas[$i] as $e) {
                $k = $e['clave'];
                if (!isset($grupos[$k])) {
                    $e['corridas'] = 0; $e['ultima'] = $d['clave']; $e['abierto'] = isset($nuevas[$k]);
                    $e['despues'] = $i;
                    $grupos[$k] = $e;
                }
                $grupos[$k]['corridas']++;
                $grupos[$k]['primera'] = $d['clave'];
                foreach ($e['sucursales'] as $s) if (!in_array($s, $grupos[$k]['sucursales'], true)) $grupos[$k]['sucursales'][] = $s;
            }
        }
        // Primero lo abierto y lo grave; después, el más nuevo
        $grupos = array_values($grupos);
        usort($grupos, fn($a, $b) => [(int)$b['abierto'], (int)$b['grave'], $a['despues']] <=> [(int)$a['abierto'], (int)$a['grave'], $b['despues']]);
        $per['problemas'] = $grupos;
    }
    unset($per);
    return ['periodos' => $periodos, 'mes' => count($delMes)];
}
