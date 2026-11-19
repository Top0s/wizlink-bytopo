// wizlink.bytopo.net · dibuja el código QR del alta de la app de autenticación (con qrcode.js, de Kazuhiko Arase,
// licencia MIT). Se dibuja como SVG con el DOM, sin insertar HTML.
(function () {
  'use strict';
  var caja = document.getElementById('qr');
  if (!caja || typeof qrcode !== 'function') return;
  var qr = qrcode(0, 'M');
  qr.addData(caja.getAttribute('data-texto'));
  qr.make();
  var n = qr.getModuleCount(), margen = 2, total = n + margen * 2;
  var ns = 'http://www.w3.org/2000/svg';
  var svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 ' + total + ' ' + total);
  svg.setAttribute('shape-rendering', 'crispEdges');
  svg.setAttribute('aria-hidden', 'true');
  var fondo = document.createElementNS(ns, 'rect');
  fondo.setAttribute('width', total); fondo.setAttribute('height', total); fondo.setAttribute('fill', '#FFFFFF');
  svg.appendChild(fondo);
  var d = '';
  for (var f = 0; f < n; f++) for (var c = 0; c < n; c++) if (qr.isDark(f, c)) d += 'M' + (c + margen) + ' ' + (f + margen) + 'h1v1h-1z';
  var camino = document.createElementNS(ns, 'path');
  camino.setAttribute('d', d); camino.setAttribute('fill', '#000000');
  svg.appendChild(camino);
  caja.appendChild(svg);
})();
