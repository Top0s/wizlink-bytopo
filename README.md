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
  solo se llega a ellas entrando. Guarda el .zip 7 días (Iván, 22-nov) y después lo borra solo; queda el registro de
  la corrida (resultado, tipo, duración, consultas y su diagnóstico en palabras) un año, para el registro por mes.
- **Explica cada corrida a primera vista** (Iván, 22-nov: R6). Lee del .zip el resumen, el diagnóstico y el control
  (`lib/explicar.php`; sin sacar nada a disco y sin guardar pantallas ni archivos del caso) y dice en palabras qué pasó y
  qué hacer, con los casos ya conocidos (impresora P2, flota que no cuadra, desconexión de BlueZone, seguridad, Unify…);
  uno nuevo se muestra con las palabras de WizLink. La duración va en horas, minutos y segundos, sin «0 h» si dura menos
  de una hora.
- **Tres pestañas en la barra de arriba:** «Corridas» (las corridas del horario de hoy, turno por turno, y debajo las
  recibidas por día, cada una con su explicación; los meses anteriores, solo con su registro, en `?mes=AAAA-MM`),
  «Tendencias» (cuántas corridas de hoy salieron bien, lo que dura cada tipo contra lo estimado y las consultas en
  revisar por día) y «Problemas» (cada problema de los últimos 7 días juntado, desde cuándo, qué pasó y qué hacer, con
  su cuenta en la barra; `lib/problemas.php`). Cada corrida tiene accesos directos al resumen, el diagnóstico, la
  bitácora y el control, todos sus archivos para ver o bajar, y el .zip completo mientras se guarda.
- **Tiene a la vista el horario de las corridas automáticas** (en «Problemas», a la derecha; en el teléfono, al final, con
  la próxima corrida arriba): el de hoy, con la próxima marcada, y el otro (día normal o día 1 del mes) a un clic. Es una
  copia del horario fijo de WizLink (`Horario` en `app/nucleo/CorridaAutomatica.cs`): si allá cambia, se cambia también
  en `lib/horario.php`.
- **Avisa arriba de la lista si la corrida automática necesita a alguien** (Iván, 20-nov: D): «El último respaldo
  reportó la corrida automática en pausa», con cuándo llegó, el motivo y qué hacer, si la corrida más nueva trae la pausa
  en el estado de WizLink (`bitacoras_wizlink/corrida_automatica_estado.txt` dentro del .zip; solo se leen `pausa` y
  `que_hacer`). Es el estado de ese respaldo: WizLink no manda uno al pausar ni al reanudar. «Qué hacer» solo muestra
  los textos que WizLink escribe; cualquier otro se cambia por «Revise el estado de la corrida automática directamente
  en WizLink». Aparte, «Sin corridas nuevas» si, según el horario, ya pasó lo que dura una corrida y una hora más sin
  que llegue ninguna que haya empezado a esa hora o después.
- **Se instala** en el teléfono como app desde Chrome (⋮ › Instalar app). No tiene service worker: no guarda nada en el
  teléfono.

Las corridas traen datos de clientes (Iván, 19-nov: A1). Lo que se baja de aquí no va a GitHub, a un issue ni a un chat
(`docs/DIAGNOSTICAR_CORRIDA.md` de WizLink, sección 1).

## Cómo se entra
1. **Usuario y contraseña.** La contraseña nunca se guarda: solo su huella (bcrypt). Cinco intentos equivocados desde una
   misma dirección (en IPv6, la misma red /64) la bloquean 15 minutos.
2. **El código de 6 dígitos** de una app de autenticación gratuita (Google Authenticator, Microsoft Authenticator, 2FAS o
   Aegis). Es el estándar abierto TOTP: no hay servicio externo ni costo. Cada código sirve una sola vez, y cinco
   códigos equivocados seguidos bloquean la entrada 15 minutos.

Los intentos se atienden de uno en uno: muchos a la vez tampoco pasan del tope de cinco. La sesión vence a los 30 minutos
sin uso y a las 12 horas. Si se borra o se cambia `acceso.json` o `totp.json`, todas las sesiones abiertas se cierran en su
próximo clic.

**La primera vez (crear el acceso):** el sitio pide un código de alta que solo se puede leer en hPanel › Administrador de
archivos, en `wizlink-private/estado/codigo_alta.txt` (lo crea el sitio al abrir la entrada). Con él se eligen el
usuario y la contraseña (al menos 12 caracteres) y después se escanea el código QR con la app. Así nadie más puede crear
el acceso, aunque llegue al sitio antes.

**Si se olvida la contraseña:** borrar `wizlink-private/estado/acceso.json` en el Administrador de archivos y crear el
acceso otra vez. La app de autenticación sigue igual. Las sesiones abiertas se cierran solas.

**Si se pierde el teléfono:** borrar también `wizlink-private/estado/totp.json`. Al entrar con la contraseña, el sitio
pide dar de alta la app otra vez. Las sesiones abiertas, también la del teléfono perdido, se cierran solas.

**Si `totp.json` está pero no se puede leer** (por ejemplo, se editó a mano): el sitio detiene la entrada y pide borrarlo.
Nunca ofrece configurar otra app por su cuenta, porque cualquiera que supiera la contraseña podría poner la suya.

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
  | `config.php` (opcional) | Solo para guardar otro número de días: `<?php return ['dias_guardar' => 5];` (de 1 a 7) |

- **Límites de PHP** (hPanel › Avanzado › Configuración de PHP): `post_max_size` y `upload_max_filesize` de 70M o más, para
  corridas de hasta 64 MB comprimidas, y `max_execution_time` de 300. La extensión `zip` encendida (sin ella, el sitio
  guarda y deja bajar las corridas, pero no las abre). Sin CDN.

## La llave de subida
Es la llave con la que WizLink sube cada corrida; solo sirve para subir. Hay una sola vigente y el sitio guarda solo su
huella. Llega de una de dos maneras («Llave de subida»):

1. **Código para WizLink** (recomendado; Iván, 19-nov: L4). Sin nadie en la máquina virtual.
   1. El sitio crea un código de un solo uso (`wzc_…`) y lo muestra una vez. Vence a los 30 días si no se usa.
   2. Se guarda como secreto `WIZLINK_RESPALDO_CODIGO` en GitHub: `ZaFi-Online/WizLink` › Settings › Environments ›
      `publicacion`. La próxima versión de WizLink lo trae (lo escribe el chequeo «Publicar» al compilar).
   3. En su primer respaldo, WizLink crea su propia llave, la guarda cifrada en la máquina virtual y la registra aquí
      con el código (`api/registrar.php`). La llave queda «esperando confirmación» (Iván, 19-nov: S1): todavía no sube
      nada, y WizLink deja sus corridas pendientes (las reenvía hasta 7 días).
   4. Iván la confirma en «Llave de subida» (Corridas avisa arriba cuando hay una). Ahí ve cuándo llegó, desde qué
      dirección y qué corrida intentó subir, con qué versión. Al confirmarla, el código se quema y las demás que
      esperaban se descartan; una de otra dirección, sin corridas de verdad, se rechaza. Así, alguien que sacara el
      código del programa publicado (el portal de las actualizaciones se baja sin contraseña) no podría subir nada ni
      dejar afuera a la máquina virtual. Cada dirección tiene una sola llave esperando y, como máximo, hay 10.
   5. Desde ahí, todas las corridas suben con esa llave, también después de las actualizaciones.
   Hace falta otro código solo si se crea una llave nueva aquí, si se reinstala Windows o cambia el usuario de Windows de
   la máquina virtual, o si WizLink pasa a otra máquina.
2. **Llave para pegar a mano** (`wzr_…`): el sitio la crea y la muestra una vez; Informática la pega en WizLink
   (Configuración › Opciones de Informática › «Guardar Llave del respaldo»). Reemplaza a la vigente.

## Lo que recibe (el contrato con WizLink)
**Registrar la llave de WizLink:** `POST /api/registrar.php`, con `Authorization: Bearer wzr_…` (la llave que creó WizLink)
y `X-WizLink-Codigo: wzc_…`. Responde JSON `{"ok", "ya_estaba", "pendiente", "mensaje"}`: 200 recibida, esperando la
confirmación (`"pendiente": true`; o ya estaba, si es la misma llave con el mismo código), 401 código equivocado, usado
con otra llave o vencido, 429 con 10 llaves esperando o demasiados intentos equivocados (con el código correcto nunca).

**Subir una corrida:** `POST /api/subir.php`, con `Authorization: Bearer wzr_…`, `Content-Type: application/zip`, `X-WizLink-Corrida`
(el nombre de la carpeta: `AAAA-MM-DD HH.MM`, o `AAAA-MM-DD HH.MM (2)` si dos corridas empezaron en el mismo minuto),
`X-WizLink-Sha256` y `X-WizLink-Version`. Adentro del .zip:
`<corrida>/…` con la carpeta completa y `bitacoras_wizlink/…`. Responde JSON `{"ok", "ya_estaba", "mensaje"}`: 200 guardada
(o «ya estaba», `"ya_estaba": true`, si esa corrida ya estaba guardada, aunque llegue con otra huella: la guardada nunca se
reemplaza), 400 o 413 rechazada, 401 llave, 409 la llave espera la confirmación (no se recibe nada; WizLink
la deja pendiente), 422 llegó incompleta, 429 demasiados intentos con una llave equivocada, 500 error del sitio. Del lado de WizLink: `app/nucleo/EnvioRespaldo.cs`. Un cambio aquí tiene que seguir
sirviéndole a esa versión.

## Carpetas
| Dónde | Qué hay |
|---|---|
| `index.php` | «Corridas»: el día turno por turno y la lista de corridas explicadas |
| `tendencias.php`, `problemas.php` | «Tendencias» y «Problemas» |
| `entrar.php`, `salir.php` | La entrada en dos pasos (y crear el acceso la primera vez) y la salida |
| `corrida.php`, `archivo.php`, `bajar.php` | Una corrida, un archivo y el .zip completo |
| `llave.php` | La llave de subida: confirmar la que registró WizLink, el código para WizLink o una llave para pegar a mano |
| `api/subir.php`, `api/registrar.php` | Donde sube WizLink, y donde registra su llave (que espera la confirmación) |
| `lib/` | El código común (no se sirve): base, sesión, acceso (usuario y contraseña), código de la app, almacén, el horario y los turnos del día, la lectura y explicación del diagnóstico, los problemas juntados y el marco de las páginas |
| `assets/` | Estilos, iconos y los scripts del código QR y de «Copiar». `qrcode.js` es de Kazuhiko Arase (licencia MIT) |
| `pruebas/` | La prueba de punta a punta, las comprobaciones sin servidor (`avisos.php`, `explicar.php`) y la corrida inventada que usan (no se sirve) |

## Probar en una computadora
Con Apache (`AllowOverride All`, `mod_rewrite`, `mod_headers`, `mod_ssl`) y PHP 8.3 con las extensiones `zip` y
`mbstring`, montado como Hostinger: `public_html` con este repositorio (con su `.git`), `wizlink-private` al lado, y el
nombre `wizlink.bytopo.net` apuntando a esa computadora. Nunca contra el sitio real: la prueba empieza borrando el acceso,
el segundo factor, las corridas y la bitácora de la copia.

```
pruebas/correr.sh <zip de una corrida de WizLink> "AAAA-MM-DD HH.MM" [/ruta/de/wizlink-private]
```

Comprueba la entrada (el código de alta, la contraseña que no se guarda, el usuario y la contraseña equivocados, el código
de un solo uso, los dos bloqueos también con 20 intentos a la vez, que restablecer la contraseña o la app cierre las
sesiones abiertas y que un `totp.json` dañado detenga la entrada), la llave, cada rechazo de la subida (llave, nombre con
trampa, huella, no es .zip, `../` adentro, y que muchas llaves equivocadas no bloqueen la correcta), que la misma corrida
con otra huella no reemplace a la guardada, la corrida «(2)», que una falla al guardar no deje nada a medias, la limpieza
(el .zip a los 7 días, el registro al año), el registro por mes, la explicación de una corrida inventada en las tres
pestañas, el paso de los registros de antes al formato nuevo, el panel del horario, el aviso de la pausa (y `pruebas/avisos.php`, el de las corridas atrasadas; `pruebas/explicar.php`, la lectura del diagnóstico, los casos conocidos y los turnos), lo que no se debe servir (`.git`, `lib/`, `pruebas/`, `.md`) y los encabezados de seguridad.

## Cómo se revisa cada cambio
Por pull request a `main`, con los chequeos de GitHub (`safety-checks` y `claude-review`) en verde; Iván lo fusiona.
Fusionar publica en el sitio real.

## Notas para Claude
En `CLAUDE.md`.
