# Calculadora de Disponibilidad de Crédito — UGEL

Aplicación web (PHP + PostgreSQL) para calcular cuánto puede solicitar un
trabajador en bancos y cooperativas, respetando el límite del **50% de su
sueldo neto total**, tal como se evalúa hoy en la hoja de cálculo de
referencia.

## ¿Qué hace?

1. Registra el sueldo bruto del trabajador y permite marcarlo como
   **Personal Administrativo** o **Docente**.
2. Guarda a cada trabajador evaluado (nombre, DNI, área, tipo) para poder
   **buscarlo y reutilizarlo después** con la lupa de búsqueda — no hace
   falta volver a escribir sus datos, sus descuentos de ley ni las
   entidades financieras cada vez (el sueldo bruto base y las entidades
   se pueden ajustar antes de guardar, ya que suelen cambiar mes a mes).
3. Aplica descuentos de ley editables (AFP, ONP, Seguro, Quinta Categoría,
   etc.) — se pueden agregar o quitar conceptos libremente, y **AFP/ONP
   son mutuamente excluyentes** (solo se puede descontar por un sistema
   pensionario a la vez).
4. Cualquier concepto de descuento o entidad financiera nuevo que se
   escriba durante una evaluación **se aprende automáticamente** y queda
   disponible como acceso rápido la próxima vez — no es necesario
   configurarlo antes en Catálogos.
5. Los accesos rápidos (chips) de descuentos y entidades se **ordenan por
   frecuencia de uso real**, así el personal de RRHH siempre ve primero
   lo que más usa.
6. Calcula el **sueldo neto total (100%)** y la **capacidad máxima
   disponible (50% del neto)**.
7. Permite registrar los montos que el trabajador solicita en distintas
   entidades financieras (bancos, cajas, cooperativas) — también
   editables.
8. Muestra la **capacidad disponible para crédito** y advierte cuando el
   total solicitado excede el 50% permitido.
9. Guarda cada evaluación en PostgreSQL (historial completo, con detalle
   de descuentos y créditos) para consulta y reportes posteriores.
10. Permite registrar una **remuneración adicional opcional** (bonos,
    asignaciones u otros conceptos que no todos los trabajadores reciben)
    que se suma al sueldo bruto base para todos los cálculos, mostrando
    el desglose claramente en el resumen y en la impresión.
11. Impresión profesional en **una sola hoja**, con membrete institucional
    (logo UGEL), folio, fecha, todos los datos ordenados y líneas de
    firma — tanto en la calculadora como en el detalle de una evaluación
    guardada. El resumen se lee de arriba hacia abajo en el orden lógico:
    primero el **sueldo bruto total**, luego los **descuentos**, y al
    final, en el recuadro más destacado, la **capacidad disponible para
    crédito**.
12. Marca de agua institucional de fondo en toda la aplicación (calculadora,
    historial, catálogos), además del ícono en la barra superior.
13. Incluye inicio de sesión básico y un módulo de administración de
    catálogos (descuentos y entidades) para el usuario administrador.
14. **Gestión de usuarios** (solo administradores, en "Usuarios"): crear
    una cuenta con su propio usuario y contraseña para cada persona que
    va a ingresar (útil cuando varias PCs entran al mismo servidor),
    con sus datos de contacto (correo, celular, dirección, cargo,
    área), editar esos datos después, cambiar entre rol
    Administrador/Operador, restablecer contraseñas y
    activar/desactivar cuentas — con resguardos para que el sistema
    nunca se quede sin ningún administrador activo.
15. **"Olvidé mi contraseña"** en la pantalla de ingreso: cualquier
    usuario puede recuperar el acceso por su cuenta, verificando su
    usuario + correo + celular registrados, sin depender del
    administrador ni necesitar un servidor de correo.

## Requisitos

- PHP 8.0 o superior, con la extensión `pdo_pgsql` habilitada.
- PostgreSQL 12 o superior.
- Servidor web (Apache, Nginx o el servidor embebido de PHP para pruebas).

## Instalación nueva (primera vez)

### 1. Crear la base de datos

```bash
createdb calculadora_prestamos
psql -d calculadora_prestamos -f schema.sql
```

Esto crea todas las tablas (incluida `trabajadores`) y carga los
catálogos iniciales (los mismos conceptos y entidades de la hoja de
referencia: AFP, ONP, Seguro, Quinta Categoría, INASIS, Tardanza,
Descuento Judicial / CONTASE, Interbank, Caja Arequipa, Caja Ica,
Scotiabank, Coopsemul, Subcafae, Banco Pichincha), más un usuario
administrador inicial:

- **Usuario:** `admin`
- **Contraseña:** `admin123`

**Cambia esta contraseña de inmediato** (puedes crear un nuevo usuario
administrador o actualizar el hash de `admin` directamente en la tabla
`usuarios` con `password_hash()` de PHP).

### 2. Configurar la conexión a la base de datos

Edita `config/database.php` y ajusta host, puerto, nombre de base,
usuario y contraseña según tu instalación. También puedes definirlos
como variables de entorno (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
`DB_PASS`) si tu servidor lo permite.

### 3. Subir los archivos al servidor

Copia toda la carpeta del proyecto a la raíz de tu sitio (o a una
subcarpeta, por ejemplo `/prestamos/`) vía cPanel File Manager, FTP,
XAMPP (`htdocs`) o el método que uses habitualmente. Asegúrate de que la
imagen **`ugel.jpg`** quede en la raíz del proyecto, junto a `index.php`.

> **Nota:** los archivos `.php` de este proyecto se entregan dentro de un
> único archivo `.zip` para evitar el problema de subidas vacías al
> compartir `.php` de forma individual.

### 4. Probar localmente (opcional)

Si quieres probar antes de subir al servidor:

```bash
php -S localhost:8000
```

Y abre `http://localhost:8000/login.php` en el navegador.

## Actualizar una instalación existente (ya tenías una versión anterior)

Si ya tenías la calculadora instalada y con evaluaciones guardadas, **no
vuelvas a correr `schema.sql`** (borraría el `admin` y los catálogos ya
personalizados). En su lugar:

1. Abre pgAdmin (o `psql`) contra tu base `calculadora_prestamos` ya
   existente.
2. Ejecuta, en este orden, **`migracion_v2.sql`**, **`migracion_v3.sql`**
   y **`migracion_v4.sql`** incluidos en este paquete (si ya habías
   corrido alguno antes, no hay problema en volver a ejecutarlo — son
   seguros y no duplican nada). Juntos agregan las tablas y columnas
   nuevas sin borrar nada, reconstruyen automáticamente la tabla de
   trabajadores y los contadores de uso a partir de tu historial ya
   guardado, y agregan los campos de contacto de los usuarios (correo,
   celular, dirección, cargo, área). **Importante:** después de correr
   `migracion_v4.sql`, entra a "Usuarios" → "Editar" en cada cuenta que
   ya tenías creada y completa su correo y celular — son obligatorios
   para que esa persona pueda usar "Olvidé mi contraseña".
3. Reemplaza los archivos `.php`, `assets/` y `config/` por los de este
   paquete — **excepto `config/database.php`**: consérvalo tal cual lo
   tienes, ya que trae tu contraseña real de PostgreSQL (el que viene en
   el zip solo trae valores de ejemplo). Si por error lo reemplazaste,
   solo edítalo de nuevo con tus datos reales de conexión.
4. Deja `ugel.jpg` donde ya estaba — no se toca.

Repite estos 4 pasos en **cada PC** donde tengas la calculadora
instalada. Los scripts de migración se pueden ejecutar más de una vez
sin problema (no duplican nada si ya se aplicaron).

## Estructura del proyecto

```
calculadora-prestamos-ugel/
├── schema.sql                  Esquema completo (solo para instalación nueva)
├── migracion_v2.sql             Migración para instalaciones ya existentes (trabajadores, tipo_personal)
├── migracion_v3.sql             Migración: remuneración adicional (sueldo base + adicional)
├── migracion_v4.sql             Migración: datos de contacto de usuarios (correo, celular, etc.)
├── ugel.jpg                      Logo institucional (marca de agua / membrete)
├── config/
│   └── database.php            Conexión PDO a PostgreSQL (editar aquí)
├── includes/
│   ├── auth.php                Funciones de sesión y autenticación
│   ├── header.php               Encabezado común (menú, marca)
│   └── footer.php               Pie de página común
├── api/
│   ├── guardar_evaluacion.php        Guarda evaluación + trabajador + aprendizaje de catálogos
│   ├── buscar_trabajador.php         Búsqueda de trabajadores ya guardados (lupa)
│   ├── obtener_trabajador.php        Carga los datos de un trabajador guardado
│   ├── guardar_catalogo_item.php     Agrega ítems al catálogo (admin)
│   ├── eliminar_catalogo_item.php    Desactiva ítems del catálogo (admin)
│   ├── guardar_usuario.php           Crea un nuevo usuario (admin)
│   ├── actualizar_usuario.php        Edita perfil/rol/estado/contraseña de un usuario (admin)
│   ├── verificar_identidad_usuario.php   Paso 1 de "Olvidé mi contraseña" (público)
│   └── restablecer_password_propio.php   Paso 2 de "Olvidé mi contraseña" (público)
├── assets/
│   ├── css/style.css            Estilos (paleta institucional) + impresión
│   └── js/app.js                 Filas dinámicas, cálculo en vivo, buscador, exclusión AFP/ONP
├── login.php / logout.php       Autenticación
├── recuperar_password.php       "Olvidé mi contraseña" (autoservicio, sin admin)
├── index.php                    Calculadora principal
├── historial.php                Listado/búsqueda de evaluaciones guardadas
├── detalle.php                  Vista/impresión de una evaluación específica
├── catalogos.php                Administración de descuentos y entidades (admin)
└── usuarios.php                 Administración de usuarios, perfiles y roles (admin)
```

## Sobre la impresión en una sola hoja

Si alguna vez notas que una evaluación imprime en 2 hojas en lugar de 1
(sobre todo con muchos descuentos, muchas entidades y observaciones
largas al mismo tiempo), revisa esto antes de nada en el diálogo de
impresión de Chrome:

- Abre **"Más ajustes"** en el panel de impresión y **desactiva
  "Encabezados y pies de página"**. Chrome reserva espacio extra arriba
  y abajo de cada hoja para la fecha, el título y el número de página
  cuando esa opción está activada, y ese espacio se resta del que usa
  el contenido — puede ser la diferencia entre 1 y 2 hojas.
- Deja la **escala en 100%** (o "Predeterminado") y el tamaño de papel
  en **A4**.

Con una evaluación típica (2 a 4 descuentos, 1 a 3 entidades) esto ya no
debería ser necesario — el diseño de impresión fue ajustado para caber
en una sola hoja incluso con "Encabezados y pies" activado. Solo en
casos extremos (6 descuentos, 5 entidades y observaciones muy largas a
la vez) podría seguir usando una segunda hoja para el resumen final; en
la práctica, con la cantidad de conceptos que maneja la UGEL, esto no
debería ocurrir.

## Sobre la marca de agua / logo

El logo institucional (`ugel.jpg`) se usa como marca de agua en el fondo
del login, como ícono en la barra superior de todas las páginas, y en el
membrete de impresión. Si alguna vez cambias de logo, basta con
reemplazar el archivo `ugel.jpg` en la raíz del proyecto por el nuevo
(mismo nombre) — no es necesario tocar ningún archivo PHP.

## Notas de uso

- **Crear un usuario para cada persona:** en "Usuarios" (solo visible
  para administradores) puedes crear una cuenta con su propio usuario y
  contraseña para cada persona que va a ingresar desde su PC — muy útil
  ahora que varias máquinas entran al mismo servidor por IP. El rol
  **Operador** solo ve Calculadora e Historial; el rol **Administrador**
  ve además Catálogos y Usuarios. El sistema no deja desactivarte a ti
  mismo ni quedarte sin ningún administrador activo. Al crear o editar
  un usuario, correo y celular son obligatorios (son la base de
  "Olvidé mi contraseña"); cargo, área y dirección son opcionales.
- **"Olvidé mi contraseña" (autoservicio):** desde la pantalla de
  ingreso, cualquier usuario puede hacer clic en "¿Olvidaste tu
  contraseña?", escribir su usuario + correo + celular tal como los
  tiene registrados, y si coinciden puede definir una nueva contraseña
  ahí mismo — sin llamar al administrador. Por eso es tan importante
  que cada usuario tenga su correo y celular reales cargados.
- **Imprimir justo después de guardar:** al presionar "Guardar
  evaluación", el mensaje de confirmación incluye un botón "🖨 Imprimir
  esta evaluación" que abre esa evaluación ya guardada en una pestaña
  nueva y lanza el diálogo de impresión de inmediato — no hace falta
  entrar a "Historial" a buscarla.
- **Buscar un trabajador ya evaluado:** usa la lupa en "1. Datos del
  trabajador" — al seleccionarlo se completan sus datos, tipo de
  personal, su sueldo base, sus descuentos de ley, las entidades
  financieras y la remuneración adicional (si tenía) de su última
  evaluación. Revisa y ajusta los montos antes de guardar, ya que
  pueden cambiar de una evaluación a otra.
- **Remuneración adicional (opcional):** en "2. Sueldo bruto y
  descuentos de ley", el botón "+ Agregar remuneración por otro
  concepto" permite sumar un bono, asignación u otro concepto que solo
  reciben algunos trabajadores. Se recuerda igual que los descuentos:
  la próxima vez que busques a ese trabajador con la lupa, se
  precarga automáticamente. Si un mes no le corresponde, usa "✕ Quitar
  remuneración adicional" y guarda así la evaluación — la próxima
  búsqueda ya no la traerá.
- **AFP / ONP:** son excluyentes — al marcar uno se desactiva el otro
  automáticamente, tanto si ya estaba prellenado como si se agrega
  manualmente.
- **Nuevos conceptos de descuento o entidades:** simplemente escríbelos
  en una fila nueva y guarda la evaluación; quedan disponibles como
  acceso rápido para la próxima vez sin pasos adicionales.
- **Grupo excluyente personalizado:** desde "Catálogos" (solo admin)
  puedes definir un grupo excluyente propio para dos o más conceptos de
  descuento (por ejemplo, dos tipos de adelanto que no deberían marcarse
  juntos), escribiendo el mismo texto de grupo en ambos.

## Próximos pasos sugeridos (para la versión de escritorio)

- La lógica de negocio vive en PHP + PostgreSQL, por lo que se puede
  empaquetar tal cual con un servidor local embebido (por ejemplo,
  usando XAMPP portátil o un binario PHP embebido junto con PostgreSQL
  portátil) dentro de un empaquetador de escritorio (Electron, Tauri o
  NW.js apuntando a `http://localhost:<puerto>`).
- Cambiar la contraseña del usuario `admin` antes de la demo.

---

## Despliegue en el servidor Ubuntu (Nginx + PHP-FPM)

En producción corre en Ubuntu con PHP 8.5-FPM, Nginx y PostgreSQL, junto al resto de apps del Área de Informática ([apps-ugel-chincha](https://github.com/henrycalbohorj-dot/apps-ugel-chincha)).

- **Base de datos:** `database/schema.sql` tiene solo la estructura (sin datos de trabajadores). Incluye las tablas de la calculadora (`trabajadores`, `evaluaciones`, `evaluacion_descuentos`, `evaluacion_creditos`, catálogos, `usuarios`) y las del módulo de solicitudes (`sol_*`).
- **Credenciales:** `config/database.php` lee `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` del entorno (ver `.env.example`). En Nginx se pasan con `fastcgi_param` dentro del bloque PHP de la app.
- **Google Sheets:** la clave de la cuenta de servicio va en `solicitudes/credenciales/service-account.json` y **no** está en el repositorio. Con Nginx los `.htaccess` no se aplican, así que hay que bloquear esas carpetas en la configuración:

```nginx
location ~ ^/calculadora-prestamos-ugel/(config|database|solicitudes/(credenciales|cache|cli|lib|sql|partials|plantillas|google-apps-script))/ {
    deny all;
}
```

- **Integración:** la vista `avisos_solicitudes` (solo id, estado y fechas) la lee el [Centro de avisos](https://github.com/henrycalbohorj-dot/centro-avisos-ugel) para anunciar en la TV las solicitudes nuevas.

---
Desarrollado por **Henry Braulio Pachas Dávalos** · Área de Informática, UGEL Chincha.
