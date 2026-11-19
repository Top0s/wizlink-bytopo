# wizlink.bytopo.net: notas para Claude Code

Lo que es el sitio, cómo se publica y cómo se prueba: `README.md`. Aquí, las reglas que no se negocian.

1. **Nada de las corridas en el repositorio.** Las corridas traen datos de clientes de Budget. Viven solo en
   `wizlink-private/corridas/`, fuera de `public_html`, y nunca entran a GitHub, a un issue ni a un comentario. Las
   pruebas usan corridas del simulador de WizLink o inventadas, que se ven inventadas.
2. **Lo privado, fuera del repositorio.** Todo `wizlink-private/`: la huella de la contraseña, el código de alta, el
   secreto de la app de autenticación, la huella de la llave de subida, las sesiones y la bitácora. Nunca se piden ni
   se escriben contraseñas, llaves ni códigos en el chat ni en el código.
3. **La entrada no se afloja.** Siempre los dos pasos: usuario y contraseña (solo su huella, con bloqueo por dirección)
   y el código de 6 dígitos (de un solo uso, con bloqueo). El acceso se crea solo con el código de alta, que se lee en
   el Administrador de archivos de Hostinger.
4. **La llave de subida solo sube.** `api/subir.php` nunca muestra, lista ni devuelve una corrida. El sitio guarda solo
   las huellas de la llave y del código para WizLink. El código sirve una sola vez (`api/registrar.php`): después solo
   acepta a la misma llave que lo usó.
5. **El contrato con WizLink.** Lo que reciben `api/subir.php` y `api/registrar.php` (encabezados, el .zip y la respuesta) lo usa
   `app/nucleo/EnvioRespaldo.cs` de `ZaFi-Online/WizLink`. Un cambio aquí tiene que seguir sirviéndole a la versión
   instalada en la máquina virtual; si no, primero cambia WizLink.
6. **Nada se ejecuta de lo que llega.** Los .zip se guardan tal cual, fuera de `public_html`, y se leen con `ZipArchive`
   sin sacar nada a disco. Los nombres internos se revisan (sin rutas absolutas, `..` ni barras invertidas).
7. **Encabezados de seguridad en cada respuesta** (`lib/base.php` y `.htaccess`): CSP sin `unsafe-inline` (solo lo
   propio), `X-Frame-Options`, `nosniff`, `noindex` y `no-store`. Sin service worker.
8. **Textos profesionales**, en español neutro o con «usted»; nunca «vos» ni «tú». Cada aviso con símbolo y palabra, no
   solo color. Contraste AA en claro y oscuro.
9. **Sin costo nuevo** ni servicios externos: el sitio no llama a nadie.
10. **Antes de cada pull request:** la prueba de punta a punta (`pruebas/correr.sh`) en una copia como Hostinger, la
    revisión de accesibilidad y la de instalación como app.
