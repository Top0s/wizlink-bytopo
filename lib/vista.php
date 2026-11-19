<?php
// wizlink.bytopo.net · el marco de cada página: encabezado, barra de arriba y pie. Los textos van en español
// neutro (sin «vos» ni «tú»).

declare(strict_types=1);

require_once __DIR__ . '/base.php';

/** El principio de la página. $menu: true en las páginas de adentro (con Corridas, Llave de subida y Salir).
 *  $ancha: true en «Corridas», que lleva el panel del horario a la derecha. */
function abrir_pagina(string $titulo, bool $menu, string $activa = '', bool $ancha = false): void
{
    encabezados_seguridad();
    header('Content-Type: text/html; charset=utf-8');
    $t = h($titulo === '' ? 'WizLink · Corridas' : $titulo . ' · WizLink');
    echo '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#00214D">
<title>' . $t . '</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/icono-32.png" sizes="32x32" type="image/png">
<link rel="icon" href="/assets/icono-192.png" sizes="192x192" type="image/png">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<link rel="stylesheet" href="/assets/estilo.css?v=' . VERSION_SITIO . '">
</head>
<body>
<a class="saltar" href="#contenido">Ir al contenido</a>
<header class="barra">
  <a class="marca" href="/"><img src="/assets/icono-64.png" alt="" width="32" height="32"><span>WizLink <span class="marca-sub">Corridas</span></span></a>';
    if ($menu) {
        echo '
  <nav class="menu" aria-label="Menú">
    <a href="/"' . ($activa === 'corridas' ? ' aria-current="page"' : '') . '>Corridas</a>
    <a href="/llave.php"' . ($activa === 'llave' ? ' aria-current="page"' : '') . '>Llave de subida</a>
    <form method="post" action="/salir.php"><input type="hidden" name="ficha" value="' . h(ficha()) . '"><button type="submit" class="enlace-boton">Salir</button></form>
  </nav>';
    }
    echo '
</header>';
    echo '
<main id="contenido" class="contenido' . ($ancha ? ' contenido-ancho' : '') . '">';
}

function cerrar_pagina(): void
{
    echo '
</main>
<footer class="pie">WizLink · respaldo de corridas · versión del sitio ' . h(VERSION_SITIO) . '</footer>
</body>
</html>';
}

/** Un aviso dentro de la página: tipo 'error', 'atencion', 'exito' o 'info'. Siempre con un símbolo y texto. */
function aviso(string $tipo, string $html): string
{
    $simbolo = ['error' => '!', 'atencion' => '!', 'exito' => '✓', 'info' => 'i'][$tipo] ?? 'i';
    $rol = $tipo === 'error' ? ' role="alert"' : '';
    return '<div class="aviso aviso-' . h($tipo) . '"' . $rol . '><span class="aviso-simbolo" aria-hidden="true">' . $simbolo . '</span><div>' . $html . '</div></div>';
}

/** La etiqueta del resultado de una corrida: palabra + símbolo, nunca solo el color. */
function etiqueta_resultado(array $d): string
{
    $r = $d['resultado'] ?? '';
    $revisar = (int)($d['revisar'] ?? 0);
    if ($r === 'detuvo') return '<span class="chip chip-error"><span class="simbolo" aria-hidden="true">■</span>Se detuvo</span>';
    if ($r === 'sin_resumen') return '<span class="chip chip-neutro"><span class="simbolo" aria-hidden="true">?</span>Sin resumen</span>';
    if ($revisar > 0) return '<span class="chip chip-atencion"><span class="simbolo" aria-hidden="true">!</span>Terminó · ' . $revisar . ' en revisar</span>';
    return '<span class="chip chip-exito"><span class="simbolo" aria-hidden="true">✓</span>Terminó</span>';
}
