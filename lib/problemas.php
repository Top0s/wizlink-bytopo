<?php
// wizlink.bytopo.net · los problemas de los últimos 7 días, juntados (Iván, 22-nov: R6, pestaña «Problemas»).
//   - Un problema es un caso del diagnóstico ya explicado (explicar.php): el mismo caso en varias corridas es uno solo.
//   - Abierto: lo trae la corrida más nueva. Resuelto: salió en los últimos 7 días, pero la corrida más nueva ya no lo trae.
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

/** El número de la pestaña «Problemas»: los abiertos, más uno si la corrida automática necesita a alguien. */
function contar_problemas(int $ahora): int
{
    $p = estado_problemas($ahora);
    return count($p['abiertos']) + ($p['aviso'] !== '' ? 1 : 0);
}
