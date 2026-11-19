# wizlink.bytopo.net: el respaldo de las corridas de WizLink

**Qué es:** el sitio privado donde WizLink deja cada corrida completa, para diagnosticarla sin pedirle la carpeta a
Informática. WizLink es el programa que saca de Wizard los contratos, las reservas y la flota y los manda a ABP Unify
(repositorio `ZaFi-Online/WizLink`). Corre solo en la máquina virtual de Informática; al terminar cada corrida, sube
aquí un .zip con su carpeta completa.

**Quién lo usa:** solo Iván, con su usuario, su contraseña y el código de 6 dígitos de su app de autenticación.

**Dirección:** https://wizlink.bytopo.net

## Qué hace
- **Recibe** cada corrida en `api/subir.php`, con la llave de subida de WizLink. Esa llave solo sirve para subir: con ella
  no se puede ver, listar ni bajar nada.
- **Guarda** cada corrida como un .zip en `wizlink-private/corridas/`, junto a `public_html` y nunca dentro: desde internet
  solo se llega a ellas entrando. Borra solas las de más de 30 días.
- **Muestra** la lista de corridas por día (resultado, duración, versión, consultas en REVISAR, peso) y cada corrida con
  accesos directos al resumen, el diagnóstico, la bitácora y el control, todos sus archivos para ver o bajar, y el .zip
  completo.
- **Se instala** en el teléfono como app desde Chrome (⋮ › Instalar app). No tiene service worker: no guarda nada en el
  teléfono.

Las corridas traen datos de clientes (Iván, 19-nov: A1). Lo que se baja de aquí no va a GitHub, a un issue ni a un chat
(`docs/DIAGNOSTICAR_CORRIDA.md` de WizLink, sección 1).

## Cómo se entra
1. **Usuario y contraseña.** La contraseña nunca se guarda: solo su huella (bcrypt). Cinco intentos equivocados desde una
   misma dirección la bloquean 15 minutos.
2. **El código de 6 dígitos** de una app de autenticación gratuita (Google Authenticator, Microsoft Authenticator, 2FAS o
   Aegis). Es el estándar abierto TOTP: no hay servicio externo ni costo. Cada código sirve una sola vez, y cinco
   códigos equivocados seguidos bloquean la entrada 15 minutos.

La sesión vence a los 30 minutos sin uso y a las 12 horas.

**La primera vez (crear el acceso):** el sitio pide un código de alta que solo se puede leer en hPanel › Administrador de
archivos, en `wizlink-private/estado/codigo_alta.txt` (lo crea el sitio al abrir la entrada). Con él se eligen el
usuario y la contraseña (al menos 12 caracteres) y después se escanea el código QR con la app. Así nadie más puede crear
el acceso, aunque llegue al sitio antes.

**Si se olvida la contraseña:** borrar `wizlink-private/estado/acceso.json` en el Administrador de archivos y crear el
acceso otra vez. La app de autenticación sigue igual.

**Si se pierde el teléfono:** borrar también `wizlink-private/estado/totp.json`. Al entrar con la contraseña, el sitio
pide dar de alta la app otra vez.

## Cómo se publica (Hostinger)
- Un sitio propio en hPanel: `wizlink.bytopo.net` (PHP 8.3, sin base de datos, CDN apagado).
- Despliegue con Git desde la rama `main` de este repositorio a `public_html`. Cada fusión en `main` publica sola.
- **Lo privado**, en la carpeta `wizlink-private`, junto a `public_html`. La crea el sitio solo la primera vez que
  alguien abre la entrada. Nunca está en GitHub:

  | Archivo o carpeta | Qué es |
  |---|---|
  | `estado/acceso.json` | El usuario y la huella de la contraseña |
  | `estado/codigo_alta.txt` | El código para crear el acceso; existe solo mientras no hay acceso |
  | `estado/totp.json` | El secreto de la app de autenticación |
  | `estado/llave.json` | La huella de la llave de subida |
  | `estado/` (lo demás) | Los intentos, los bloqueos y las sesiones |
  | `corridas/` | Las corridas recibidas, por mes |
  | `registro/` | La bitácora del sitio, un archivo por mes, con fecha y hora UTC |
  | `config.php` (opcional) | Solo para guardar otro número de días: `<?php return ['dias_guardar' => 60];` |

- **Límites de PHP** (hPanel › Avanzado › Configuración de PHP): `post_max_size` y `upload_max_filesize` de 70M o más, para
  corridas de hasta 64 MB comprimidas, y `max_execution_time` de 300. La extensión `zip` encendida (sin ella, el sitio
  guarda y deja bajar las corridas, pero no las abre). Sin CDN.

## La llave de subida
En el sitio, «Llave de subida» › «Crear la llave de subida». Se muestra una sola vez: el sitio guarda solo su huella. Se le
da a Informática por un canal seguro (nunca por chat ni correo) y se guarda en WizLink, en la máquina virtual:
Configuración › Opciones de Informática › «Guardar Llave del respaldo», y se marca «Respaldo completo». Crear una nueva
reemplaza la anterior.

## Lo que recibe (el contrato con WizLink)
`POST /api/subir.php`, con `Authorization: Bearer wzr_…`, `Content-Type: application/zip`, `X-WizLink-Corrida`
(`AAAA-MM-DD HH.MM`, el nombre de la carpeta), `X-WizLink-Sha256` y `X-WizLink-Version`. Adentro del .zip:
`<corrida>/…` con la carpeta completa y `bitacoras_wizlink/…`. Responde JSON `{"ok", "ya_estaba", "mensaje"}`: 200 guardada
(o ya estaba igual), 400 o 413 rechazada, 401 llave, 422 llegó incompleta, 429 demasiados intentos con una llave
equivocada, 500 error del sitio. Del lado de WizLink: `app/nucleo/EnvioRespaldo.cs`. Un cambio aquí tiene que seguir
sirviéndole a esa versión.

## Carpetas
| Dónde | Qué hay |
|---|---|
| `index.php` | La lista de corridas |
| `entrar.php`, `salir.php` | La entrada en dos pasos (y crear el acceso la primera vez) y la salida |
| `corrida.php`, `archivo.php`, `bajar.php` | Una corrida, un archivo y el .zip completo |
| `llave.php` | La llave de subida |
| `api/subir.php` | Donde sube WizLink |
| `lib/` | El código común (no se sirve): base, sesión, acceso (usuario y contraseña), código de la app, almacén y el marco de las páginas |
| `assets/` | Estilos, iconos y los scripts del código QR y de «Copiar». `qrcode.js` es de Kazuhiko Arase (licencia MIT) |
| `pruebas/` | La prueba de punta a punta (no se sirve) |

## Probar en una computadora
Con Apache (`AllowOverride All`, `mod_rewrite`, `mod_headers`, `mod_ssl`) y PHP 8.3 con las extensiones `zip` y
`mbstring`, montado como Hostinger: `public_html` con este repositorio (con su `.git`), `wizlink-private` al lado, y el
nombre `wizlink.bytopo.net` apuntando a esa computadora. Nunca contra el sitio real: la prueba empieza borrando el acceso,
el segundo factor, las corridas y la bitácora de la copia.

```
pruebas/correr.sh <zip de una corrida de WizLink> "AAAA-MM-DD HH.MM" [/ruta/de/wizlink-private]
```

Comprueba la entrada (el código de alta, la contraseña que no se guarda, el usuario y la contraseña equivocados, el código
de un solo uso y los dos bloqueos), la llave, cada rechazo de la subida (llave, nombre con trampa, huella, no es .zip,
`../` adentro, y que muchas llaves equivocadas no bloqueen la correcta), la limpieza de 30 días, lo que no se debe servir
(`.git`, `lib/`, `pruebas/`, `.md`) y los encabezados de seguridad.

## Cómo se revisa cada cambio
Por pull request a `main`, con los chequeos de GitHub (`safety-checks` y `claude-review`) en verde; Iván lo fusiona.
Fusionar publica en el sitio real.

## Notas para Claude
En `CLAUDE.md`.
