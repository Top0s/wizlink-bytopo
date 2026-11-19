// wizlink.bytopo.net · el botón «Copiar» de la llave nueva. La llave no se guarda en el navegador.
(function () {
  'use strict';
  var boton = document.getElementById('copiar');
  var estado = document.getElementById('copiar-estado');
  if (!boton) return;
  boton.addEventListener('click', function () {
    var campo = document.getElementById(boton.getAttribute('data-copiar'));
    campo.select();
    var ok = function () { estado.textContent = 'Llave copiada.'; };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(campo.value).then(ok, function () { estado.textContent = 'Seleccione la llave y cópiela a mano.'; });
    } else {
      try { document.execCommand('copy'); ok(); } catch (e) { estado.textContent = 'Seleccione la llave y cópiela a mano.'; }
    }
  });
})();
