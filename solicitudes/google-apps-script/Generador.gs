/**
 * PANEL DE ENTIDADES — UGEL CHINCHA
 * Crea, de una sola vez, el Google Sheet de cada entidad a partir de la plantilla maestra,
 * lo configura (nombre y grupo), lo comparte con la cuenta de servicio y luego con sus usuarios.
 *
 * Instalación (cuenta de la UGEL):
 *   1. Crea un Google Sheet vacío llamado "PANEL ENTIDADES".
 *   2. Archivo → Configuración → Configuración regional: México (o Perú) · Zona horaria: Lima.
 *   3. Extensiones → Apps Script → pega este archivo → completa CONFIG → Guardar.
 *   4. Recarga el Sheet → menú "Entidades UGEL" → "1. Cargar lista inicial".
 */

var CONFIG = {
  // Enlace (o ID) de la PLANTILLA SOLICITUDES maestra
  PLANTILLA: 'https://docs.google.com/spreadsheets/d/ID_DE_TU_PLANTILLA/edit',
  // Enlace (o ID) de la carpeta de Drive donde se guardarán los Sheets (vacío = misma carpeta que la plantilla)
  CARPETA: '',
  CUENTA_SERVICIO: 'TU-CUENTA@TU-PROYECTO.iam.gserviceaccount.com',
  HOJA_PLANTILLA: 'SOLICITUDES',
  FILA_CAB: 6,
  FILAS: 500,
  GRUPOS: ['COOPERATIVA JUDICIALES', 'FONDO DE BIENESTAR', 'COOPERATIVAS', 'ENTIDADES REGULADAS POR LA SBS', 'SINDICATO']
};

// Columnas del panel
var P = { COD: 1, NOMBRE: 2, GRUPO: 3, ENLACE: 4, CORREOS: 5, ESTADO: 6, NOTA: 7 };
var CAB = ['COD', 'NOMBRE COMPLETO', 'GRUPO DE DESCUENTO', 'ENLACE DEL SHEET', 'CORREOS DE USUARIOS (separados por coma)', 'ESTADO', 'NOTA'];

// Lista inicial (terceros_lista_1.xlsx). Grupo vacío = decidir antes de crear.
var LISTA = [
  [6, 'APORTE DERRAMA MAGISTERIAL', 'FONDO DE BIENESTAR', 'CONFIRMAR grupo'],
  [8, 'COOPERATIVA DE SERVICIOS MÚLTIPLES CAPAC YUPANQUI', 'COOPERATIVAS', ''],
  [15, 'SERVICIO SOCIAL DEL DIRECTOR Y SUPERVISOR', 'FONDO DE BIENESTAR', 'CONFIRMAR grupo'],
  [23, 'COOPERATIVA DE SERVICIOS MÚLTIPLES EDUCACIÓN COOPERATIVA IQUEÑA', 'COOPERATIVAS', ''],
  [126, 'COOP SERV MULT INTEGRACION MAGISTERIAL', 'COOPERATIVAS', ''],
  [129, 'COOPERATIVA DE SERVICIOS MÚLTIPLES Y EDUCATIVOS VIRGEN DEL ROSARIO LTDA', 'COOPERATIVAS', ''],
  [149, 'RIMAC SEGUROS', 'ENTIDADES REGULADAS POR LA SBS', 'CONFIRMAR grupo'],
  [151, 'CAJA CUSCO', 'ENTIDADES REGULADAS POR LA SBS', ''],
  [152, 'CAJA MUNICIPAL DE AHORRO Y CREDITO DE AREQUIPA S.A. - CAJA AREQUIPA', 'ENTIDADES REGULADAS POR LA SBS', ''],
  [156, 'BANCO INTERBANK', 'ENTIDADES REGULADAS POR LA SBS', ''],
  [160, 'BBVA BANCO CONTINENTAL', 'ENTIDADES REGULADAS POR LA SBS', ''],
  [190, 'CAJA MUNICIPAL DE AHORRO Y CREDITO DE TRUJILLO SA', 'ENTIDADES REGULADAS POR LA SBS', ''],
  [418, 'PRESTAMO DERRAMA MAGISTERIAL', 'FONDO DE BIENESTAR', 'CONFIRMAR grupo'],
  [446, 'COOPERATIVA DE AHORRO Y CRÉDITO SAN CRISTÓBAL DE HUAMANGA', 'COOPERATIVAS', 'CONFIRMAR grupo: ¿COOPERATIVAS o SBS?'],
  [447, 'COOPERATIVA SANTA MARÍA MAGDALENA', 'COOPERATIVAS', ''],
  [449, 'CAJA MUNICIPAL DE AHORRO Y CREDITO ICA', 'ENTIDADES REGULADAS POR LA SBS', ''],
  [1010, 'COOPERATIVA DE SERVICIOS ESPECIALES SANTO TOMÁS', 'COOPERATIVAS', ''],
  [1086, 'COOPERATIVA DE SERVICIOS MÚLTIPLES "JOSMAR" LTDA.', 'COOPERATIVAS', ''],
  [1236, 'ASOCIACIÓN EDUCATIVA HERMENÉUTICA Y VALORES - ASOCIACIÓN EDUCATIVA HV', 'COOPERATIVAS', 'CONFIRMAR grupo'],
  [1502, 'COOPERATIVA DE SERVICIOS MÚLTIPLES VIDA - COOPVIDA', 'COOPERATIVAS', ''],
  [1558, 'CONFEDERACION NACIONAL DE TRABAJADORES ADMINISTRATIVOS DE SEDES E INSTITUCIONES EDUCATIVAS DEL PERU', 'SINDICATO', ''],
  [1583, 'BANCO RIPLEY', 'ENTIDADES REGULADAS POR LA SBS', ''],
  [1638, 'SCOTIABANK PERÚ S.A.A.', 'ENTIDADES REGULADAS POR LA SBS', ''],
  [1691, 'ASOCIACIÓN PROVINCIAL DE CESANTES Y JUBILADOS DE EDUCACIÓN DE CHINCHA', 'COOPERATIVAS', 'CONFIRMAR grupo'],
  [1708, 'COOPERATIVA DE SERVICIOS EDUCATIVOS CECES', 'COOPERATIVAS', ''],
  [1710, 'COOPERATIVA DE AHORRO Y CRÉDITO SAN MIGUEL', 'COOPERATIVAS', 'CONFIRMAR grupo: ¿COOPERATIVAS o SBS?'],
  [1717, 'COOPERATIVA DE SERVICIOS MÚLTIPLES ICNM "NUEVO MUNDO"', 'COOPERATIVAS', ''],
  [1723, 'COOPERATIVA DE SERVICIOS EDUCATIVOS IDECAP', 'COOPERATIVAS', ''],
  [1732, 'COOPERATIVA DE AHORRO Y CRÉDITO GRUPO HORIZONTE', 'COOPERATIVAS', 'CONFIRMAR grupo: ¿COOPERATIVAS o SBS?'],
  [1754, 'BANCO PICHINCHA', 'ENTIDADES REGULADAS POR LA SBS', ''],
  [1763, 'GRUPO PROEDUCAR', 'COOPERATIVAS', 'CONFIRMAR grupo'],
  [1789, 'COOPERATIVA DE SERVICIOS ESPECIALES FIDELIDAD JG Y ASOCIADOS', 'COOPERATIVAS', ''],
  [1809, 'COOPERATIVA DE SERVICIOS EDUCATIVOS ICEPRE PERU', 'COOPERATIVAS', ''],
  [1819, 'ASOCIACIÓN GLOBAL ESTUDIOS', 'COOPERATIVAS', 'CONFIRMAR grupo'],
  [1829, 'COOPERATIVA DE SERVICIOS ESPECIALES ALTO MONTESSORI', 'COOPERATIVAS', ''],
  [1910, 'COOPERATIVA DE AHORRO Y CREDITO INTERFINCO LIMITADA - INTERFINCO', 'COOPERATIVAS', 'CONFIRMAR grupo: ¿COOPERATIVAS o SBS?'],
  [1974, 'SUBCOMITÉS DE ADMINISTRACIÓN DEL FONDO DE ASISTENCIA Y ESTÍMULO', 'FONDO DE BIENESTAR', '']
];

/* ============================== MENÚ ============================== */

function onOpen() {
  SpreadsheetApp.getUi().createMenu('Entidades UGEL')
    .addItem('1. Cargar lista inicial', 'cargarLista')
    .addItem('2. Crear Sheets de entidades', 'crearSheets')
    .addItem('3. Compartir con correos Google (opcional)', 'compartirUsuarios')
    .addItem('4. Activar alerta de clientes duplicados en los Sheets', 'activarAlertaDuplicados')
    .addSeparator()
    .addItem('5. Portal: preparar hoja de USUARIOS', 'prepararUsuarios')
    .addItem('6. Portal: guardar contraseñas', 'generarClaves')
    .addItem('7. Portal: ver enlace para las entidades', 'verEnlacePortal')
    .addSeparator()
    .addItem('8. Migrar Sheets al formato nuevo (sin GRUPO ni ENTIDAD, con código)', 'migrarSheets')
    .addSeparator()
    .addItem('9. Borrar TODAS las solicitudes (para empezar pruebas de cero)', 'limpiarPruebas')
    .addToUi();
}

function cargarLista() {
  var sh = SpreadsheetApp.getActiveSheet();
  var ui = SpreadsheetApp.getUi();
  if (sh.getLastRow() > 1 && ui.alert('La hoja ya tiene datos. ¿Reemplazar con la lista inicial?', ui.ButtonSet.YES_NO) !== ui.Button.YES) return;
  sh.clear();
  sh.setName('ENTIDADES');
  sh.getRange(1, 1, 1, CAB.length).setValues([CAB]).setBackground('#0a3a5a').setFontColor('#fff').setFontWeight('bold').setWrap(true);
  var filas = LISTA.map(function (x) { return [x[0], x[1], x[2], '', '', '', x[3]]; });
  sh.getRange(2, 1, filas.length, CAB.length).setValues(filas);
  sh.getRange(2, P.GRUPO, 300, 1).setDataValidation(SpreadsheetApp.newDataValidation().requireValueInList(CONFIG.GRUPOS, true).build());
  sh.setFrozenRows(1);
  [60, 420, 230, 300, 320, 160, 380].forEach(function (w, i) { sh.setColumnWidth(i + 1, w); });
  filas.forEach(function (f, i) {
    if (f[6].indexOf('CONFIRMAR') === 0 || !f[2]) sh.getRange(i + 2, 1, 1, CAB.length).setBackground('#fdf3e1');
  });
  ui.alert('Lista cargada (' + filas.length + ' entidades).\n\nRevisa las filas en amarillo y corrige el GRUPO si hace falta.\n' +
           'Luego: Entidades UGEL → 2. Crear Sheets de entidades.');
}

/* ======================== CREAR SHEETS ======================== */

function crearSheets() {
  var ui = SpreadsheetApp.getUi();
  var plantillaId = id_(CONFIG.PLANTILLA);
  if (!plantillaId || plantillaId.indexOf('PEGAR') === 0) { ui.alert('Falta CONFIG.PLANTILLA: pega el enlace de la plantilla maestra en el script.'); return; }
  var plantilla = DriveApp.getFileById(plantillaId);
  var carpeta = CONFIG.CARPETA ? DriveApp.getFolderById(id_(CONFIG.CARPETA)) : (plantilla.getParents().hasNext() ? plantilla.getParents().next() : DriveApp.getRootFolder());

  var sh = SpreadsheetApp.getActive().getSheetByName('ENTIDADES');
  var datos = sh.getRange(2, 1, Math.max(sh.getLastRow() - 1, 1), CAB.length).getValues();
  var inicio = Date.now(), creados = 0, pendientes = 0, sinGrupo = 0;

  for (var i = 0; i < datos.length; i++) {
    var d = datos[i], fila = i + 2;
    if (!String(d[P.NOMBRE - 1]).trim() || String(d[P.ENLACE - 1]).trim()) continue;   // vacía o ya creada
    if (CONFIG.GRUPOS.indexOf(d[P.GRUPO - 1]) < 0) { sh.getRange(fila, P.ESTADO).setValue('FALTA GRUPO'); sinGrupo++; continue; }
    if (Date.now() - inicio > 4.5 * 60 * 1000) { pendientes++; continue; }             // límite de 6 min de Apps Script

    var nombre = String(d[P.NOMBRE - 1]).trim().toUpperCase();
    var grupo = d[P.GRUPO - 1];
    try {
      var copia = plantilla.makeCopy('SOLICITUDES DE CRÉDITO — ' + nombre, carpeta);
      copia.addEditor(CONFIG.CUENTA_SERVICIO);
      var ss = SpreadsheetApp.openById(copia.getId());
      var hoja = ss.getSheetByName(CONFIG.HOJA_PLANTILLA);
      escribirCabecera_(hoja, d[P.COD - 1], nombre, grupo);
      proteger_(hoja);
      sh.getRange(fila, P.ENLACE).setValue(copia.getUrl());
      sh.getRange(fila, P.ESTADO).setValue('CREADO');
      creados++;
      SpreadsheetApp.flush();
    } catch (e) {
      sh.getRange(fila, P.ESTADO).setValue('ERROR: ' + e.message);
    }
  }
  ui.alert('Sheets creados: ' + creados +
           (sinGrupo ? '\nSin grupo (no creados): ' + sinGrupo : '') +
           (pendientes ? '\n\nQuedan ' + pendientes + ' por crear: vuelve a ejecutar "2. Crear Sheets" (Google limita cada ejecución a 6 minutos).' : '\n\nListo. Copia las columnas A a D y pégalas en la web → Entidades → Importar lista.'));
}

/* ======================== COMPARTIR ======================== */

function compartirUsuarios() {
  var ui = SpreadsheetApp.getUi();
  var sh = SpreadsheetApp.getActive().getSheetByName('ENTIDADES');
  var datos = sh.getRange(2, 1, Math.max(sh.getLastRow() - 1, 1), CAB.length).getValues();
  var total = 0;
  for (var i = 0; i < datos.length; i++) {
    var enlace = String(datos[i][P.ENLACE - 1]).trim();
    var correos = String(datos[i][P.CORREOS - 1]).split(/[\s,;]+/).filter(function (c) { return /@/.test(c); });
    if (!enlace || !correos.length) continue;
    try {
      var f = DriveApp.getFileById(id_(enlace));
      var ya = f.getEditors().map(function (u) { return u.getEmail().toLowerCase(); });
      var nuevos = correos.filter(function (c) { return ya.indexOf(c.toLowerCase()) < 0; });
      if (nuevos.length) f.addEditors(nuevos);
      // Acceso general restringido: solo las personas agregadas
      f.setSharing(DriveApp.Access.PRIVATE, DriveApp.Permission.NONE);
      sh.getRange(i + 2, P.ESTADO).setValue('COMPARTIDO (' + correos.length + ')');
      total += nuevos.length;
    } catch (e) {
      sh.getRange(i + 2, P.ESTADO).setValue('ERROR AL COMPARTIR: ' + e.message);
    }
  }
  ui.alert('Usuarios agregados: ' + total + '.\nCada usuario recibe un correo de Google con el enlace de su Sheet.');
}

/* ================== ALERTA DE DUPLICADOS ================== */

/**
 * Agrega a cada Sheet de entidad una regla que pinta en rojo el DNI (y nombres) cuando el mismo
 * cliente se registra más de una vez. Es instantánea para el usuario que escribe y no bloquea.
 * Se puede ejecutar varias veces: reemplaza la regla anterior.
 */
function activarAlertaDuplicados() {
  var sh = SpreadsheetApp.getActive().getSheetByName('ENTIDADES');
  var datos = sh.getRange(2, 1, Math.max(sh.getLastRow() - 1, 1), CAB.length).getValues();
  var ok = 0, err = [];
  var f1 = CONFIG.FILA_CAB + 1, fN = CONFIG.FILA_CAB + CONFIG.FILAS;
  for (var i = 0; i < datos.length; i++) {
    var enlace = String(datos[i][P.ENLACE - 1]).trim();
    if (!enlace) continue;
    try {
      var hoja = SpreadsheetApp.openById(id_(enlace)).getSheetByName(CONFIG.HOJA_PLANTILLA);
      var sep = separador_(hoja);
      var formula = ('=AND($D' + f1 + '<>""§COUNTIF($D$' + f1 + ':$D$' + fN + '§$D' + f1 + ')>1)').replace(/§/g, sep);
      var reglas = hoja.getConditionalFormatRules().filter(function (r) {
        var b = r.getBooleanCondition();
        return !(b && String(b.getCriteriaValues()[0] || '').indexOf('COUNTIF($D$' + f1) >= 0);
      });
      reglas.unshift(SpreadsheetApp.newConditionalFormatRule().whenFormulaSatisfied(formula)
        .setBackground('#f8d7da').setFontColor('#8f1d22').setBold(true)
        .setRanges([hoja.getRange(f1, 4, CONFIG.FILAS, 3)]).build());
      hoja.setConditionalFormatRules(reglas);
      ok++;
    } catch (e) {
      err.push(datos[i][P.NOMBRE - 1] + ': ' + e.message);
    }
  }
  SpreadsheetApp.getUi().alert('Alerta de duplicados activada en ' + ok + ' Sheet(s).' + (err.length ? '\n\nCon error:\n' + err.join('\n') : ''));
}

function separador_(hoja) {
  var c = hoja.getRange(1, 17), sep = ';';
  try { c.setFormula('=SUM(1,2)'); SpreadsheetApp.flush(); if (c.getValue() === 3) sep = ','; } catch (e) {}
  c.clearContent();
  return sep;
}

/* ================== MIGRACIÓN AL FORMATO v2 ================== */

// Formato v2: sin GRUPO DE DESCUENTO ni ENTIDAD (van en la cabecera, junto al CÓDIGO)
var CAB_V2 = ['ITEM', 'COD_MODULAR', 'COD_CARGO', 'DNI', 'NOMBRES', 'APELLIDOS', 'TIPO DE PRODUCTO',
  'ENTIDAD COMPRA DE DEUDA', 'PERIODO', 'MONTO DE CUOTA MENSUAL', 'ACT/CESANT',
  'ESTADO UGEL', 'OBSERVACIÓN UGEL', 'FECHA EVALUACIÓN', 'REGISTRADO POR', 'FECHA REGISTRO', 'ID'];

function escribirCabecera_(hoja, cod, nombre, grupo) {
  hoja.getRange('C3').setNumberFormat('@').setValue(String(cod || '').replace(/\D/g, ''));
  hoja.getRange('E3').setValue(nombre);
  hoja.getRange('K3').setValue(grupo || '');
}

/**
 * Crea, para cada entidad, un Sheet nuevo desde la PLANTILLA v2 y le copia todas sus filas
 * (con su ID, respuesta de la UGEL y firma del portal). Actualiza el enlace en el panel y envía
 * el Sheet anterior a la papelera de Drive (se puede recuperar durante 30 días).
 * Se puede ejecutar varias veces: salta las entidades ya migradas.
 */
function migrarSheets() {
  var ui = SpreadsheetApp.getUi();
  var plantillaId = id_(CONFIG.PLANTILLA);
  var cabP = SpreadsheetApp.openById(plantillaId).getSheetByName(CONFIG.HOJA_PLANTILLA).getRange(CONFIG.FILA_CAB, 1, 1, 17).getValues()[0];
  if (norm_(cabP[7]) !== 'ENTIDAD COMPRA DE DEUDA') {
    ui.alert('Primero actualiza la PLANTILLA SOLICITUDES maestra:\n\nExtensiones → Apps Script → pega el Codigo.gs nuevo → Guardar → recarga la plantilla → UGEL Chincha → Instalar / reparar plantilla.');
    return;
  }
  if (ui.alert('Migrar Sheets al formato nuevo',
      'Se creará un Sheet nuevo por entidad (sin columnas GRUPO ni ENTIDAD, con el CÓDIGO arriba) y se copiarán todas sus solicitudes.\n' +
      'El Sheet anterior irá a la papelera de Drive.\n\n¿Continuar?', ui.ButtonSet.YES_NO) !== ui.Button.YES) return;

  var plantilla = DriveApp.getFileById(plantillaId);
  var carpeta = CONFIG.CARPETA ? DriveApp.getFolderById(id_(CONFIG.CARPETA)) : (plantilla.getParents().hasNext() ? plantilla.getParents().next() : DriveApp.getRootFolder());
  var sh = SpreadsheetApp.getActive().getSheetByName('ENTIDADES');
  var datos = sh.getRange(2, 1, Math.max(sh.getLastRow() - 1, 1), CAB.length).getValues();
  var zona = Session.getScriptTimeZone() || 'America/Lima';
  var inicio = Date.now(), hechas = 0, pendientes = 0, filasCopiadas = 0, errores = [];

  for (var i = 0; i < datos.length; i++) {
    var d = datos[i], fila = i + 2, enlace = String(d[P.ENLACE - 1]).trim();
    if (!enlace || String(d[P.ESTADO - 1]).indexOf('MIGRADO v2') === 0) continue;
    if (Date.now() - inicio > 4.5 * 60 * 1000) { pendientes++; continue; }
    var nombre = String(d[P.NOMBRE - 1]).trim().toUpperCase();
    try {
      var viejoId = id_(enlace);
      var hojaVieja = SpreadsheetApp.openById(viejoId).getSheetByName(CONFIG.HOJA_PLANTILLA);
      var vals = hojaVieja.getDataRange().getValues();
      var cab = (vals[CONFIG.FILA_CAB - 1] || []).map(norm_);
      var idx = {};
      cab.forEach(function (h, c) { if (h && idx[h] == null) idx[h] = c; });
      if (idx['GRUPO DE DESCUENTO'] == null && idx['ENTIDAD COMPRA DE DEUDA'] === 7) {      // ya está en formato v2
        escribirCabecera_(hojaVieja, d[P.COD - 1], nombre, d[P.GRUPO - 1]);
        sh.getRange(fila, P.ESTADO).setValue('MIGRADO v2');
        hechas++; continue;
      }
      var filas = [];
      for (var r = CONFIG.FILA_CAB; r < vals.length; r++) {
        var fuera = CAB_V2.slice(1).map(function (h) {
          var c = idx[norm_(h)];
          var v = c == null ? '' : vals[r][c];
          if (v instanceof Date) v = Utilities.formatDate(v, zona, norm_(h) === 'FECHA EVALUACION' ? 'yy/MM/dd' : 'yy/MM/dd HH:mm');
          return v;
        });
        if (fuera.slice(0, 10).some(function (v) { return String(v).trim() !== ''; })) filas.push(fuera);
      }
      var copia = plantilla.makeCopy('SOLICITUDES DE CRÉDITO — ' + nombre, carpeta);
      copia.addEditor(CONFIG.CUENTA_SERVICIO);
      try {   // conserva a quienes tenían acceso al Sheet anterior
        var antes = DriveApp.getFileById(viejoId).getEditors().map(function (u) { return u.getEmail(); })
          .filter(function (m) { return m && m !== CONFIG.CUENTA_SERVICIO && m !== Session.getEffectiveUser().getEmail(); });
        if (antes.length) copia.addEditors(antes);
      } catch (e) {}
      var hoja = SpreadsheetApp.openById(copia.getId()).getSheetByName(CONFIG.HOJA_PLANTILLA);
      escribirCabecera_(hoja, d[P.COD - 1], nombre, d[P.GRUPO - 1]);
      if (filas.length) {
        if (hoja.getMaxRows() < CONFIG.FILA_CAB + filas.length) hoja.insertRowsAfter(hoja.getMaxRows(), filas.length);
        hoja.getRange(CONFIG.FILA_CAB + 1, 2, filas.length, CAB_V2.length - 1).setValues(filas);
      }
      proteger_(hoja);
      SpreadsheetApp.flush();
      sh.getRange(fila, P.ENLACE).setValue(copia.getUrl());
      sh.getRange(fila, P.ESTADO).setValue('MIGRADO v2 (' + filas.length + ' filas)');
      DriveApp.getFileById(viejoId).setTrashed(true);
      hechas++; filasCopiadas += filas.length;
    } catch (e) {
      errores.push(nombre + ': ' + e.message);
      sh.getRange(fila, P.ESTADO).setValue('ERROR AL MIGRAR: ' + e.message);
    }
  }
  ui.alert('Sheets migrados: ' + hechas + ' (' + filasCopiadas + ' solicitudes copiadas).' +
    (pendientes ? '\n\nQuedan ' + pendientes + ': vuelve a ejecutar "8. Migrar" (Google limita cada ejecución a 6 minutos).' :
      '\n\nListo. Ahora en la web de Informática: Entidades → "Actualizar entidades desde el PANEL" para tomar los enlaces nuevos.') +
    (errores.length ? '\n\nCon error:\n' + errores.join('\n') : ''));
}

function norm_(v) {
  return String(v == null ? '' : v).trim().toUpperCase().replace(/_/g, ' ')
    .replace(/[ÁÀ]/g, 'A').replace(/[ÉÈ]/g, 'E').replace(/[ÍÌ]/g, 'I').replace(/[ÓÒ]/g, 'O').replace(/[ÚÙ]/g, 'U').replace(/\s+/g, ' ');
}

/* ======================== INTERNOS ======================== */

function id_(s) {
  s = String(s || '').trim();
  var m = s.match(/\/d\/([a-zA-Z0-9_-]{20,})/) || s.match(/folders\/([a-zA-Z0-9_-]{20,})/) || s.match(/id=([a-zA-Z0-9_-]{20,})/);
  return m ? m[1] : s;
}

/** Igual que la plantilla: encabezado, ítem y columnas de respuesta solo editables por la UGEL y la cuenta de servicio. */
function proteger_(sh) {
  sh.getProtections(SpreadsheetApp.ProtectionType.RANGE).forEach(function (p) {
    if (p.getDescription().indexOf('UGEL') === 0) p.remove();
  });
  var f1 = CONFIG.FILA_CAB + 1;
  [[sh.getRange(1, 1, CONFIG.FILA_CAB, 17), 'UGEL - encabezado'],
   [sh.getRange(f1, 1, CONFIG.FILAS, 1), 'UGEL - ítem automático'],
   [sh.getRange(f1, 12, CONFIG.FILAS, 5), 'UGEL - respuesta de evaluación']].forEach(function (x) {
    var p = x[0].protect().setDescription(x[1]);
    p.removeEditors(p.getEditors());
    if (p.canDomainEdit()) p.setDomainEdit(false);
    p.addEditor(Session.getEffectiveUser());
    p.addEditor(CONFIG.CUENTA_SERVICIO);
  });
}


/* ================== LIMPIEZA PARA PRUEBAS ================== */

/**
 * Borra las solicitudes de los Sheets de TODAS las entidades (desde la fila 7, incluidas las columnas de
 * respuesta, firma, ID y OTIC) y la pestaña DISPONIBLE del panel. No toca la cabecera (código, entidad,
 * grupo), la fórmula del ÍTEM, la lista de entidades ni los usuarios del portal.
 * Después hay que limpiar la base de la web con limpiar_pruebas.sql.
 */
function limpiarPruebas() {
  var ui = SpreadsheetApp.getUi();
  var r = ui.prompt('Borrar TODAS las solicitudes',
    'Se borrarán todas las solicitudes de los Sheets de TODAS las entidades (desde la fila ' + (CONFIG.FILA_CAB + 1) + ') y los montos de la pestaña DISPONIBLE.\n' +
    'No se tocan la cabecera de cada Sheet, la lista de entidades ni los usuarios del portal.\n\nEscriba BORRAR para confirmar:', ui.ButtonSet.OK_CANCEL);
  if (r.getSelectedButton() !== ui.Button.OK || String(r.getResponseText()).trim().toUpperCase() !== 'BORRAR') { ui.alert('Cancelado. No se borró nada.'); return; }

  var ss = SpreadsheetApp.getActive();
  var sh = ss.getSheetByName('ENTIDADES');
  var datos = sh.getRange(2, 1, Math.max(sh.getLastRow() - 1, 1), CAB.length).getValues();
  var inicio = Date.now(), hojas = 0, filas = 0, errores = [], pendientes = 0;
  for (var i = 0; i < datos.length; i++) {
    var enlace = String(datos[i][P.ENLACE - 1]).trim(), nombre = String(datos[i][P.NOMBRE - 1]).trim();
    if (!enlace) continue;
    if (Date.now() - inicio > 5 * 60 * 1000) { pendientes++; continue; }
    try {
      var hoja = SpreadsheetApp.openById(id_(enlace)).getSheetByName(CONFIG.HOJA_PLANTILLA);
      if (!hoja) { errores.push(nombre + ': no tiene la pestaña ' + CONFIG.HOJA_PLANTILLA); continue; }
      var n = hoja.getMaxRows() - CONFIG.FILA_CAB, c = hoja.getLastColumn();
      if (n > 0 && c > 1) {
        var rango = hoja.getRange(CONFIG.FILA_CAB + 1, 2, n, c - 1);      // desde la columna B: la A es la fórmula del ÍTEM
        filas += rango.getValues().filter(function (f) { return f.some(function (v) { return String(v).trim() !== ''; }); }).length;
        rango.clearContent();
      }
      hojas++;
    } catch (e) { errores.push(nombre + ': ' + e.message); }
  }
  var disp = ss.getSheetByName('DISPONIBLE');
  if (disp && disp.getLastRow() > 1) disp.getRange(2, 1, disp.getLastRow() - 1, Math.max(disp.getLastColumn(), 1)).clearContent();
  SpreadsheetApp.flush();
  ui.alert('Limpieza terminada.\n\nSheets revisados: ' + hojas + ' · solicitudes borradas: ' + filas + '.\nPestaña DISPONIBLE: vacía.' +
    (pendientes ? '\n\nQuedan ' + pendientes + ' Sheets: vuelva a ejecutar este menú.' : '\n\nAhora limpie la base de la web con limpiar_pruebas.sql (pgAdmin).') +
    (errores.length ? '\n\nCon error:\n' + errores.join('\n') : ''));
}
