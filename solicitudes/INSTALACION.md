# Módulo Solicitudes de Crédito — Guía de instalación

**Cómo funciona:**

1. Cada entidad (cooperativa, banco, caja) llena su propio Google Sheet.
2. La web de Informática sincroniza esos Sheets, filtra los datos, y el operador evalúa cada solicitud con la regla del 50%.
3. El resultado (APROBADO / DESAPROBADO / OBSERVADA) vuelve automáticamente al Sheet de la entidad.

```
Entidad (Google Sheet propio) ──► Web Informática (XAMPP + PostgreSQL) ──► Respuesta en el Sheet
```

---

## 1. Cuenta de servicio de Google (una sola vez, ~10 min)

Es el "usuario robot" que lee y escribe los Sheets. Hazlo con la cuenta Google de la UGEL.

1. Entra a https://console.cloud.google.com → **Crear proyecto** → nombre: `UGEL Solicitudes`.
2. **APIs y servicios → Biblioteca** → busca **Google Sheets API** → **Habilitar**.
3. **APIs y servicios → Credenciales → Crear credenciales → Cuenta de servicio**.
   Ponle un nombre (`ugel-solicitudes`) y haz clic en **Listo**. No necesita roles.
4. Abre la cuenta creada → pestaña **Claves → Agregar clave → Crear clave nueva → JSON**. Se descarga un archivo.
5. Renómbralo a `service-account.json` y cópialo a:
   `C:\xampp\htdocs\calculadora-prestamos-ugel\solicitudes\credenciales\`
6. Anota el correo de la cuenta. Termina en `@...iam.gserviceaccount.com`. También aparece en la web, en **Entidades**.

> Ese JSON es una llave: no lo envíes por WhatsApp ni lo subas a ningún Drive. La carpeta `credenciales` ya está bloqueada para el navegador.

## 2. Instalar el módulo en la calculadora

1. Descomprime el zip **directamente en** `C:\xampp\htdocs\calculadora-prestamos-ugel\`. Trae dos cosas:
   - La carpeta nueva `solicitudes\`.
   - `includes\header.php`: es **tu mismo header** (con logo y enlace Usuarios) más el enlace **"Solicitudes"**. Acepta reemplazarlo.

   ⚠ Trabaja sobre la copia de **htdocs**, no sobre una del Escritorio.
2. Copia el JSON de la cuenta de servicio a `solicitudes\credenciales\service-account.json`.
3. En pgAdmin, abre la base **calculadora_prestamos** y ejecuta `solicitudes\sql\001_solicitudes.sql`. Se puede ejecutar varias veces sin problema. **Vuelve a ejecutarlo cada vez que actualices el módulo.**
4. En `C:\xampp\php\php.ini`, verifica que estas líneas **no** tengan `;` al inicio, y luego reinicia Apache:
   ```
   extension=curl
   extension=openssl
   extension=pdo_pgsql
   extension=zip
   ```
5. Entra a la calculadora con tu usuario → menú **Solicitudes**.

Requiere la calculadora con sus migraciones v2, v3 y v4 aplicadas, como la de la UGEL. No hace falta configurar la base de datos ni el login: el módulo usa automáticamente `config/database.php` y la sesión de la calculadora. Solo los usuarios con rol **admin** pueden registrar o editar entidades. Los operadores pueden sincronizar y evaluar.

## 3. Plantilla maestra en Google Sheets (una sola vez)

1. Con la cuenta de la UGEL, crea un Google Sheet vacío llamado `PLANTILLA SOLICITUDES`.
2. Ve a **Extensiones → Apps Script**, borra lo que aparece y pega todo `google-apps-script\Codigo.gs`.
3. En la línea `CUENTA_SERVICIO`, pega el correo del paso 1.6 y guarda (💾).
4. Vuelve al Sheet y recárgalo (F5). Aparece el menú **UGEL Chincha → Instalar / reparar plantilla**.
   La primera vez Google pide autorización: **Revisar permisos → tu cuenta → Configuración avanzada → Ir a … → Permitir**.

La plantilla queda con:

- Las mismas columnas del formato DRE.
- Listas desplegables y validación de DNI (8 dígitos), periodo AAAAMM, cuota mayor a 0 y A/C.
- Ítem automático y columnas de respuesta de la UGEL en gris, protegidas.

## 4. Alta de entidades: todas de una vez con el Panel

1. Crea un Google Sheet vacío llamado **PANEL ENTIDADES**, en la carpeta "Solicitudes de crédito".
2. **Archivo → Configuración**: región **México** (o Perú) y zona horaria **Lima**.
3. **Extensiones → Apps Script**: pega `google-apps-script/Generador.gs`.
   - En `PLANTILLA`, pega el enlace de la PLANTILLA SOLICITUDES maestra.
   - Guarda.
4. Recarga el Sheet. En el menú **Entidades UGEL**:
   1. **Cargar lista inicial**: carga las 37 entidades de planilla. Las filas amarillas tienen el grupo por confirmar: corrígelo donde haga falta.
   2. **Crear Sheets de entidades**: crea, configura, protege y comparte con la cuenta de servicio cada Sheet. Si son muchas, vuelve a ejecutarlo hasta que diga "Listo".
   3. *(Opcional)* Compartir con correos: solo si alguna entidad prefiere usar el Sheet directamente. Con el **Portal** (punto 4-B) no hace falta.
5. En la web → **Entidades → Importar lista**: copia las columnas **A a D** del panel y pégalas → **Verificar e importar**.

Para una sola entidad nueva se puede seguir usando: plantilla → *Hacer una copia* → *Configurar entidad* → compartir → registrarla en la web.

## 4-B. Portal de entidades (usuario y contraseña, sin correo)

Los usuarios de las entidades **no entran al Google Sheet**. Entran a una página web (el *Portal*) con un usuario y una contraseña que les da la UGEL. El Portal escribe en el Sheet de su entidad por ellos y firma cada fila (columnas **R = REGISTRADO POR** y **S = FECHA REGISTRO**). Es gratis: corre en Apps Script con la cuenta de la UGEL.

Instalación (una sola vez, en el Apps Script del **PANEL ENTIDADES**):

1. **Extensiones → Apps Script**. Reemplaza `Generador.gs` con la versión nueva.
2. **＋ → Secuencia de comandos** → nombre `Portal` → pega `Portal.gs`.
3. **＋ → HTML** → nombre `Portal` → pega `Portal.html`. Guarda.
4. Recarga el PANEL. Menú **Entidades UGEL → 5. Portal: preparar hoja de USUARIOS**.
5. En la pestaña **USUARIOS** llena una fila por persona: usuario (ej. `pichincha.jorge`), nombre, **COD ENTIDAD** (el código de la columna A del panel) y la **CONTRASEÑA** que tú elijas (mínimo 8 caracteres, con letras y números, sin espacios). Anótala: al guardarla deja de verse.
6. Menú **6. Portal: generar contraseñas**: guarda las contraseñas cifradas, y en la columna F queda **✓ guardada**. El usuario entra directamente con esa contraseña.
7. **Implementar → Nueva implementación → ⚙ Aplicación web**. Ejecutar como: **Yo**. Quién tiene acceso: **Cualquier persona** → Implementar → Autorizar.
8. Menú **7. Portal: ver enlace**. Ese enlace, el usuario y la contraseña es lo que se entrega a cada entidad. Pruébalo en una ventana de incógnito.

Uso:

- **Nuevo usuario:** agrega la fila con su contraseña y ejecuta el menú 6.
- **Cambiar u olvidar una contraseña:** escribe una nueva en su columna F y ejecuta el menú 6. Eso también lo desbloquea.
- **Dar de baja:** desmarca **ACTIVO**.
- Tras 5 intentos fallidos el usuario queda bloqueado 10 minutos.
- El usuario puede registrar, ver sus respuestas y corregir/anular mientras la fila **no** esté APROBADA, DESAPROBADA o DUPLICADA.
- Si cambias `Portal.gs` o `Portal.html`: **Implementar → Gestionar implementaciones → ✏ → Versión: Nueva versión**. Así el enlace no cambia.

En la web de Informática se ve **✎ Nombre (usuario)** en la Bandeja y **Registrado por** en cada solicitud y en la alerta de duplicados.

## 4-C. Formato v2 del Sheet y regla del monto disponible (v1.8)

**Formato v2:** el Sheet ya no tiene las columnas GRUPO DE DESCUENTO ni ENTIDAD: van arriba, junto al **CÓDIGO** de la entidad. Las fechas salen como **AA/MM/DD** (ej. `26/10/28`).

Para pasar los Sheets existentes al formato v2:

1. **PLANTILLA SOLICITUDES** → Extensiones → Apps Script → pega el `Codigo.gs` nuevo → Guardar → recarga → **UGEL Chincha → Instalar / reparar plantilla**.
2. **PANEL ENTIDADES** → Apps Script: reemplaza `Generador.gs`, `Portal.gs` y `Portal.html` → Guardar → recarga.
3. Menú **Entidades UGEL → 8. Migrar Sheets al formato nuevo**:
   - Crea un Sheet nuevo por entidad y copia todas sus filas, con ID, respuesta y firma.
   - El Sheet anterior va a la papelera de Drive, recuperable 30 días.
   - Si dice "Quedan N", vuelve a ejecutarlo.
4. Si el portal ya estaba publicado: **Implementar → Gestionar implementaciones → ✏ → Nueva versión**.
5. En la web: **Entidades → PANEL ENTIDADES**:
   - Pega el enlace del PANEL y pulsa **Conectar**.
   - Luego pulsa **Actualizar entidades desde el PANEL**. Así la web toma los enlaces nuevos.

   Antes, comparte el PANEL como **Editor** con la cuenta de servicio.

**Regla del monto disponible** (para todas las entidades): si la última evaluación de la UGEL dejó a un trabajador con *S/ 250* disponibles y otra entidad pide una cuota de *S/ 400*, la solicitud sale **DESAPROBADA** automáticamente, con el motivo, sin pasar a evaluación.

- **En el Portal:** al instante, apenas el usuario registra o corrige. Usa la pestaña **DISPONIBLE** del PANEL, que la web publica sola después de cada aprobación o desaprobación.
- **Filas escritas directo en el Sheet:** se aplica al sincronizar.
- **Monto disponible:**
  - *Administrativo:* lo que le queda tras la decisión (capacidad 50% − compromisos, menos la cuota si se aprobó).
  - *Docente / cesante:* el monto que escribe el evaluador.
- Para limitar la vigencia de la regla (ej. 90 días), agrega `'regla_vigencia_dias' => 90` dentro de `'evaluacion'` en config.php. Por defecto no vence.

**Evaluación — tipo de trabajador:**

- **Docente y/o cesante:** solo se marca el estado y, si se aprueba, el **monto disponible**.
- **Administrativo:** cálculo completo con la boleta.
- Los descuentos de ley no se repiten. **AFP y ONP se excluyen entre sí**: un solo sistema de pensiones.
- Cada entidad de crédito vigente se agrega una sola vez.

## 4-D. Regla del monto disponible y OTIC (v1.9)

**Monto disponible:**
- **Administrativo:** lo calcula la boleta. Si apruebas, al trabajador le queda *capacidad 50% − compromisos − cuota*.
- **Docente y/o cesante:** escribes **hasta cuánto se le puede descontar** (antes de esta cuota). Si apruebas, le queda *monto − cuota*. No se puede aprobar una cuota mayor al monto.

**La regla aplica a los 4 tipos de producto:**
- **Portal:** si la cuota supera lo que le queda al trabajador, **no deja registrar** la solicitud y muestra el motivo. Tampoco deja guardar una corrección que la supere.
- **Solicitudes ya pendientes:** cuando apruebas o desapruebas, las demás solicitudes pendientes de ese DNI, en cualquier entidad, se revisan. Las que exceden quedan **DESAPROBADAS** automáticamente, con el motivo en su Sheet.
- **Filas escritas directo en el Sheet:** se revisan igual al sincronizar.

**OTIC:**
- **Al aprobar:** escribe el mensaje para la entidad. El botón **✉ OTIC enviado por correo** lo llena solo.
- **En el portal:** la entidad ve el mensaje y marca **Recepcioné el OTIC** en su solicitud aprobada. Se guarda en la columna **RECEPCIÓN OTIC** de su Sheet, que el portal crea sola.
- **En la web:** la Bandeja muestra **✓ OTIC recibido** u **OTIC sin confirmar**, y la ficha muestra quién lo confirmó y cuándo.
- **Es solo informativo:** la solicitud ya está aprobada y no queda pendiente por esto.

## 4-E. Cambios v1.10

- **POSIBLE DESAPROBACIÓN:** cuando actúa la regla del monto disponible, la entidad ve *POSIBLE DESAPROBACIÓN* con el motivo. En el portal puede **corregir la cuota**; si la nueva cuota cabe, vuelve a EN EVALUACIÓN. En la web aparece como Desaprobada con la etiqueta *posible desaprobación*.
- **AMPLIACIÓN:** solo de la misma entidad. En el portal, la entidad de la ampliación se llena sola con la propia y no se puede cambiar. Si en el Sheet escriben otra entidad, la fila queda OBSERVADA.
- **OTIC confirmados:** el menú **OTIC confirmados** de la web muestra con un número las confirmaciones nuevas que llegan del portal. Se dan por revisadas al abrir la solicitud o con **Marcar todas como revisadas**. Llegan a la web al sincronizar.
- **Docente y/o cesante:** el título de la evaluación es *Monto disponible*, no *Regla del 50%*.
- **Después de cambiar Portal.gs o Portal.html:** siempre **Implementar → Gestionar implementaciones → ✏ → Nueva versión → Implementar**. Si no, las entidades siguen viendo la versión anterior.

## 4-F. Padrón de trabajadores (v1.11)
El portal autocompleta **apellidos, nombres y código modular** al escribir un DNI de 8 dígitos.

1. Ejecute `sql/001_solicitudes.sql` en pgAdmin (crea `sol_padron` y `sol_padron_cargas`).
2. En la web: **Padrón → Cargar y publicar**. Suba juntos NEXUS (.xls), PENSIÓN y SOBREVIVIENTE (.xlsx) o cualquier .csv
   con columnas DNI / APELLIDO PATERNO / APELLIDO MATERNO / NOMBRES / CODIGO MODULAR (en cualquier orden).
   Los DNI repetidos quedan una sola vez; el código modular se conserva si un archivo no lo trae.
3. Se publica la pestaña **PADRON** en el PANEL ENTIDADES (no la edite a mano: se reemplaza en cada carga).
4. Reemplace `portal.gs` y `Portal.html` y publique **Nueva versión** de la implementación.
5. Cada mes, vuelva a subir el NEXUS actualizado. Marque “Reemplazar el padrón completo” solo si quiere borrar a quienes ya no figuran.

Si el DNI no figura, la entidad escribe los datos a mano. Si figura sin código modular, solo escribe el código.
La entidad puede pulsar “Corregir datos”; en la ficha de la web, Informática ve “✓ Coincide” o “⚠ Difiere del padrón”.
Privacidad: el portal solo devuelve esos 3 datos, solo con el DNI completo y con un límite de 300 consultas por usuario y por hora.
Los reportes .lis (BEN010R) no traen DNI y no sirven para el padrón.

## 4-G. Web en vivo y bienvenida del portal (v1.12)
- Con la web de Informática abierta, los Sheets se sincronizan solos **cada 2 minutos** (una sola pestaña lo hace; el
  resto solo escucha). Cada 15 s la pantalla revisa la base de datos local y, si hubo cambios, refresca la Bandeja,
  "OTIC confirmados", el Panel y la ficha abierta **sin F5** (no refresca encima de lo que se está escribiendo).
- Nueva solicitud → voz **"Nueva solicitud ingresada"** + aviso en pantalla. OTIC recepcionado → sonido + aviso.
- El navegador exige **un clic en la página** antes de permitir sonido: si el botón 🔈 del menú parpadea en amarillo,
  haga clic en él. El botón 🔊/🔇 enciende o apaga la voz.
- El botón "Sincronizar Sheets" sigue funcionando; comparte el candado con la automática (nunca corren dos a la vez).
- Portal: al ingresar, la entidad ve una bienvenida de 2.2 s (se salta con un clic o una tecla).

## 4-H. Evaluación cerrada (v1.14)
- Web: solo se decide sobre una solicitud **Por evaluar**. Una vez aprobada, desaprobada, observada o marcada duplicada,
  los botones y los campos quedan bloqueados (también en el servidor: un doble clic o dos usuarios a la vez registran
  una sola evaluación). Para corregir un error, un **administrador** usa "Reabrir".
- Portal: una solicitud enviada queda **bloqueada** (🔒 Enviada); no se puede corregir ni anular. Solo se corrige si la
  UGEL la **OBSERVA**; al guardar la corrección vuelve a quedar bloqueada. Antes de enviar se muestra un resumen.
- Limpieza única: `quitar_evaluaciones_repetidas.sql` borra evaluaciones duplicadas por clics repetidos (conserva la primera).

## 4-I. Tipo de trabajador desde el portal (v1.15)
- La entidad marca **Docente / Cesante** o **Administrativo** (todo cesante queda como Docente / Cesante). Se guarda en la
  columna **TIPO DE TRABAJADOR** del Sheet (el portal la agrega sola) y la sincronización la lleva a `tipo_trabajador`.
- En la web, la evaluación se abre directamente en ese modo y el otro queda bloqueado (también en el servidor).
  Si la entidad se equivocó: **Observar** para que lo corrija.
- Solicitudes antiguas sin ese dato: se evalúan como antes (Informática elige el tipo).
- Ambas webs muestran la firma: "Desarrollado por el Equipo de Informática · UGEL Chincha".
- Requiere ejecutar `sql/001_solicitudes.sql` (agrega la columna `tipo_trabajador`).

## 4-J. OTIC y desaprobados (v1.17)
- El **OTIC solo se envía a DOCENTE / CESANTE** (aprobado o desaprobado por Informática). A los administrativos no:
  la web oculta el mensaje "OTIC enviado por correo", la bandeja no muestra "OTIC sin confirmar" y el portal no
  ofrece "Recepcioné el OTIC". Las desaprobaciones automáticas (POSIBLE DESAPROBACIÓN) tampoco llevan OTIC.
- **Un desaprobado no amarra presupuesto**: el disponible no descuenta la cuota rechazada; la entidad ve en su respuesta
  "Monto disponible del trabajador: S/ X" y puede registrar una nueva solicitud por ese monto sin que salga como
  "cliente ya registrado" ni como posible duplicado.

## 4-K. Anular una solicitud evaluada (v1.18)
- Solo Informática, desde la ficha de una solicitud **Aprobada** o **Desaprobada**: "🚫 Anular solicitud" con **sustento obligatorio**.
- Queda como **ANULADA** en la web y en el Sheet de la entidad ("Anulada por la UGEL: <sustento>. Puede registrar una nueva solicitud…").
- Deja de contar: devuelve el monto disponible que tenía antes de esa cuota, no aparece como "cliente ya registrado" ni como duplicado,
  no lleva OTIC y la entidad no puede editarla. Si alguien vuelve a escribir en esa fila del Sheet, sigue ANULADA.
- Queda en el historial de la solicitud (con el sustento y el estado anterior) y en la observación de la evaluación en el Historial de la calculadora.
- Un administrador puede usar "Reabrir" si se anuló por error.

## 5. Sincronización automática (recomendado)

Programador de tareas de Windows → **Crear tarea básica**:

- Desencadenador: diario, y en Propiedades marcar **Repetir cada 10 minutos** durante todo el día.
- Acción: iniciar un programa.
  - Programa: `C:\xampp\php\php.exe`
  - Argumentos: `C:\xampp\htdocs\calculadora-prestamos-ugel\solicitudes\cli\sincronizar.php`

También está el botón **Sincronizar Sheets** en la web, para hacerlo al instante.

## 6. Uso diario

| Estado | Qué significa | Lo que ve la entidad |
|---|---|---|
| **Por evaluar** | Datos completos, falta calcular | EN EVALUACIÓN |
| **Observada** | Errores de datos, o el operador la observó | OBSERVADA + motivo |
| **Aprobado** | La cuota cabe en el 50% | APROBADO (fila bloqueada) |
| **Desaprobado** | La cuota excede el tope | DESAPROBADO (fila bloqueada) |
| **Retirada** | La entidad borró la fila | — |

En **Bandeja → Evaluar**, ingresa los datos de la boleta: sueldo bruto, descuentos de ley y créditos vigentes. El **resultado del cálculo** aparece en vivo como **referencia**.

La **decisión es del evaluador**, con los botones **Aprobar**, **Desaprobar** u **Observar**. La entidad solo ve la respuesta en su Sheet después de que eliges uno. Si decides distinto al cálculo, el sistema pide el motivo, y queda registrado quién decidió, el cálculo y la decisión.

- Si es **compra de deuda** o **ampliación**, marca **"Se libera"** en la deuda que desaparece.
- Si el trabajador tiene otra solicitud **ya aprobada en el sistema** para el mismo periodo, se suma automáticamente. Puedes desmarcarlo.
- La boleta se recuerda por DNI: la próxima solicitud de esa persona viene precargada.

Otras funciones:

- **Exportar formato DRE** (en la Bandeja) genera el Excel oficial de la DRE Ica con los filtros activos. Por ejemplo, *Aprobadas + Nov 2026*.
- **Alerta de concurrencia** (en el Panel) muestra los DNI que piden crédito a varias entidades en el mismo periodo.

### Cliente ingresado dos veces por la misma entidad

Ejemplo: Jorge y Martín, de la misma entidad, registran al mismo cliente.

- **En el Sheet:** el DNI repetido se pinta de **rojo** al instante, sin bloquear. Al sincronizar, la fila nueva muestra *"ALERTA: este cliente ya fue registrado en la fila N"*.
- **En la web:**
  - La solicitud aparece con la etiqueta **⚠ duplicado de fila N** y en la pestaña **Posibles duplicados**.
  - El Panel muestra la tarjeta **Clientes duplicados**.
  - Al abrir la solicitud se ve la **última evaluación del primer ingreso**: neto, capacidad, disponible, decisión, quién la hizo y cuándo.
  - Sus datos de boleta vienen **precargados**. Si ese ingreso ya fue aprobado en el mismo periodo, su cuota se suma como compromiso.
- **Opciones del evaluador:** evaluarla como una solicitud adicional, o pulsar **Marcar como duplicada**. En ese caso la entidad ve *DUPLICADA — Cliente ya registrado en la fila N*.

Para activar el resaltado rojo en los Sheets ya creados: **PANEL ENTIDADES → Entidades UGEL → 4. Activar alerta de clientes duplicados en los Sheets**.

### Motivos

| Decisión | ¿Motivo? |
|---|---|
| **Aprobar** | No. Si el cálculo excede el 50%, se pide confirmar con un segundo clic. |
| **Desaprobar** | Obligatorio. La entidad lo ve. |
| **Observar** | Obligatorio. La entidad lo ve. |

## 7. Fórmula aplicada (idéntica a la calculadora)

```
neto total  = max(sueldo bruto − descuentos de ley, 0)
capacidad   = neto total × 50 %
total       = créditos vigentes (los que NO se liberan) + aprobadas en el módulo + cuota solicitada
APROBADO  si  total ≤ capacidad
```

Cada evaluación se copia también al **Historial** de la calculadora, con el detalle de descuentos y créditos y el número de solicitud en las observaciones. Para desactivar esa copia, cambia `registrar_en_calculadora` a `false` en `config.php`.

El porcentaje se cambia en `config.php` (`porcentaje_tope`).

Si algún día cambia la regla, hay que ajustar estos archivos:

- `lib/Evaluador.php`: la fórmula oficial, la que se guarda.
- `api/guardar_evaluacion.php` de la calculadora.
- `assets/app.js`, función `calcular`: la vista previa en vivo.

## 8. Problemas comunes

| Mensaje | Solución |
|---|---|
| `Sin conexión con Google: SSL certificate problem` | Revisa `google.ca_bundle` en config.php. XAMPP lo trae en `C:/xampp/apache/bin/curl-ca-bundle.crt`. |
| `Google respondió 403/404` | El Sheet no está compartido con la cuenta de servicio como **Editor**. |
| `No tiene la pestaña 'SOLICITUDES'` | Falta ejecutar "Instalar / reparar plantilla" en ese Sheet. |
| `Class "ZipArchive" not found` al exportar | Activa `extension=zip` en php.ini. |
| La entidad no ve el menú UGEL Chincha | Que recargue el Sheet. Los usuarios no necesitan autorizar nada para llenar filas. |
