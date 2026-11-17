/**
 * PORTAL DE ENTIDADES — UGEL CHINCHA
 * Va en el MISMO proyecto de Apps Script del PANEL ENTIDADES (archivo nuevo "Portal").
 *
 * Las entidades entran con usuario y contraseña creados por la UGEL (hoja USUARIOS del panel),
 * registran solicitudes con el formato DRE y ven la respuesta de la UGEL.
 * Escribe en el Google Sheet de su entidad (los mismos que lee la web de Informática).
 *
 * Publicar: Implementar → Nueva implementación → Aplicación web
 *           Ejecutar como: Yo (cuenta UGEL) · Quién tiene acceso: Cualquier persona
 */

var PORTAL = {
  HOJA_USU: 'USUARIOS',
  HOJA_ENT: 'ENTIDADES',
  SESION_SEG: 6 * 60 * 60,      // la sesión dura 6 horas
  MAX_INTENTOS: 5,              // intentos fallidos antes de bloquear 10 minutos
  TITULO: 'Solicitudes de crédito · UGEL Chincha',
  ZONA: 'America/Lima',
  TIPOS: ['NUEVO', 'NUEVA COMPRA DE PRODUCTO', 'AMPLIACION', 'AMPLIACION COMPRA'],
  COMPRA: ['NUEVA COMPRA DE PRODUCTO', 'AMPLIACION COMPRA'],
  HOJA_DISP: 'DISPONIBLE',     // la publica la web de Informática: monto disponible por DNI
  HOJA_PADRON: 'PADRON',       // la publica la web de Informática: DNI → apellidos, nombres, código modular
  MAX_CONSULTAS: 300,           // consultas de DNI por usuario y por hora (evita descargas masivas del padrón)
  FMT_EVAL: 'yy/MM/dd',         // FECHA EVALUACIÓN: AA/MM/DD
  FMT_REG: 'yy/MM/dd HH:mm',    // FECHA REGISTRO
  BLOQUEADOS: ['APROBADO', 'DESAPROBADO', 'DUPLICADA'],
  EDITABLES: ['OBSERVADA']      // v1.14: una solicitud enviada queda bloqueada; solo se corrige si la UGEL la OBSERVA
};
// Columnas del Sheet de cada entidad: se ubican por el nombre de la cabecera (sirve para el formato
// anterior, con GRUPO y ENTIDAD, y para el nuevo, sin ellas).
var COLS = {
  MOD: 'COD MODULAR', CARGO: 'COD CARGO', DNI: 'DNI', NOM: 'NOMBRES', APE: 'APELLIDOS', TIPO: 'TIPO DE PRODUCTO',
  GRUPO: 'GRUPO DE DESCUENTO', ENT: 'ENTIDAD', COMPRA: 'ENTIDAD COMPRA DE DEUDA', PER: 'PERIODO',
  CUOTA: 'MONTO DE CUOTA MENSUAL', COND: 'ACT/CESANT', EST: 'ESTADO UGEL', OBS: 'OBSERVACION UGEL',
  FEC: 'FECHA EVALUACION', POR: 'REGISTRADO POR', REG: 'FECHA REGISTRO', ID: 'ID', OTIC: 'RECEPCION OTIC',
  TTRAB: 'TIPO DE TRABAJADOR'
};
// v1.15: tipo de trabajador que marca la entidad (la web de Informática lo usa para elegir la evaluación)
var TIPOS_TRAB = ['DOCENTE/CESANTE', 'ADMINISTRATIVO'];
// Columnas que el portal agrega al final si el Sheet no las tiene
var COLS_EXTRA = [['POR', 'REGISTRADO POR', 200], ['REG', 'FECHA REGISTRO', 130], ['OTIC', 'RECEPCIÓN OTIC', 210], ['TTRAB', 'TIPO DE TRABAJADOR', 150]];
// Columnas de la hoja USUARIOS
var U = { USU: 1, NOM: 2, COD: 3, ENT: 4, ACT: 5, TMP: 6, RES: 7, ACC: 8, HASH: 9, CAMB: 10 };
// F = contraseña que escribe la UGEL (al guardarla se reemplaza por ✓) · G = cuándo se asignó
var U_CAB = ['USUARIO', 'NOMBRE COMPLETO', 'COD ENTIDAD', 'ENTIDAD', 'ACTIVO', 'CONTRASEÑA', 'ASIGNADA', 'ÚLTIMO ACCESO', 'HASH', 'DEBE CAMBIAR'];
var MARCA_CLAVE = '✓ guardada';

/* ============================== WEB ============================== */

function doGet() {
  return HtmlService.createHtmlOutputFromFile('Portal')
    .setTitle(PORTAL.TITULO)
    .addMetaTag('viewport', 'width=device-width, initial-scale=1')
    .setFaviconUrl('https://ssl.gstatic.com/docs/spreadsheets/favicon3.ico');
}

/* ---------- Funciones llamadas desde el navegador (google.script.run) ---------- */

function portalLogin(usuario, clave) {
  usuario = String(usuario || '').trim().toLowerCase();
  var cache = CacheService.getScriptCache();
  var kFail = 'fail:' + usuario;
  var fallos = parseInt(cache.get(kFail) || '0', 10);
  if (fallos >= PORTAL.MAX_INTENTOS) throw new Error('Demasiados intentos fallidos. Espere 10 minutos e intente de nuevo.');

  var u = buscarUsuario_(usuario);
  var ok = u && u.activo && u.hash && verificarClave_(String(clave || ''), u.hash);
  if (!ok) {
    cache.put(kFail, String(fallos + 1), 600);
    throw new Error(u && !u.activo ? 'Su usuario está desactivado. Comuníquese con la UGEL.' : 'Usuario o contraseña incorrectos.');
  }
  cache.remove(kFail);
  var ent = buscarEntidad_(u.cod);
  if (!ent) throw new Error('Su usuario no tiene una entidad válida asignada. Comuníquese con la UGEL.');

  u.hoja.getRange(u.fila, U.ACC).setValue(ahora_());
  var token = Utilities.getUuid();
  cache.put('s:' + token, JSON.stringify({ usuario: u.usuario, nombre: u.nombre, cod: u.cod }), PORTAL.SESION_SEG);
  return { token: token, nombre: u.nombre, usuario: u.usuario, entidad: ent.nombre, grupo: ent.grupo, cod: ent.cod, debeCambiar: u.debeCambiar };
}

function portalSalir(token) {
  CacheService.getScriptCache().remove('s:' + token);
  return true;
}

function portalCambiarClave(token, actual, nueva) {
  var s = sesion_(token);
  var u = buscarUsuario_(s.usuario);
  if (!u || !verificarClave_(String(actual || ''), u.hash)) throw new Error('La contraseña actual no es correcta.');
  nueva = String(nueva || '');
  if (nueva.length < 8 || !/[A-Za-z]/.test(nueva) || !/\d/.test(nueva)) throw new Error('La nueva contraseña debe tener al menos 8 caracteres, con letras y números.');
  if (nueva === actual) throw new Error('La nueva contraseña debe ser distinta a la actual.');
  u.hoja.getRange(u.fila, U.HASH).setValue(hashNuevo_(nueva));
  u.hoja.getRange(u.fila, U.TMP).setValue('✓ cambiada por el usuario');
  u.hoja.getRange(u.fila, U.RES).setValue(ahora_());
  u.hoja.getRange(u.fila, U.CAMB).setValue(false);
  return true;
}

/** Solicitudes de la entidad del usuario. */
function portalDatos(token) {
  var s = sesion_(token);
  var ent = buscarEntidad_(s.cod);
  var sh = hojaEntidad_(ent);
  var filas = leerFilas_(sh).map(function (f) { delete f._raw; return f; });
  return { entidad: ent.nombre, grupo: ent.grupo, cod: ent.cod, filas: filas, tipos: PORTAL.TIPOS, compra: PORTAL.COMPRA, hora: ahora_() };
}

/**
 * Registra una solicitud nueva. Si el DNI ya existe en el Sheet de la entidad y no se confirmó,
 * devuelve {duplicado: {...}} para que el usuario decida (no bloquea).
 */
function portalRegistrar(token, d, confirmarDuplicado) {
  var s = sesion_(token);
  var ent = buscarEntidad_(s.cod);
  var v = validar_(d, ent);
  if (v.errores.length) throw new Error(v.errores.join('\n'));
  var bloqueo = excedeDisponible_(v.datos);
  if (bloqueo) throw new Error(bloqueo);

  var lock = LockService.getScriptLock();
  lock.waitLock(20000);                       // dos usuarios guardando a la vez no pisan la misma fila
  try {
    var sh = hojaEntidad_(ent);
    var filas = leerFilas_(sh);
    var previo = filas.filter(function (f) { return f.dni === v.datos.dni && NO_CUENTAN.indexOf(f.estado) < 0; })[0];
    if (previo && !confirmarDuplicado) {
      return { duplicado: { fila: previo.fila, por: previo.por, fecha: previo.registrado, estado: previo.estado || 'EN EVALUACIÓN', cuota: previo.cuota, periodo: previo.periodo } };
    }
    var k = asegurarColumnas_(sh);
    var fila = filaLibre_(sh, k);
    escribirDatos_(sh, k, fila, v.datos);
    sh.getRange(fila, k.ID).setValue(Utilities.getUuid());
    sh.getRange(fila, k.POR).setValue(s.nombre + ' (' + s.usuario + ')');
    sh.getRange(fila, k.REG).setValue(ahora_());
    SpreadsheetApp.flush();
    return { ok: true, fila: fila, duplicadoDe: previo ? previo.fila : null };
  } finally {
    lock.releaseLock();
  }
}

/** Corrige una solicitud aún no evaluada (por ejemplo, una OBSERVADA). */
function portalEditar(token, uid, d) {
  var s = sesion_(token);
  var ent = buscarEntidad_(s.cod);
  var v = validar_(d, ent);
  if (v.errores.length) throw new Error(v.errores.join('\n'));
  var bloqueo = excedeDisponible_(v.datos);
  if (bloqueo) throw new Error(bloqueo.replace('No se puede registrar esta solicitud', 'No se puede guardar la corrección'));
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var sh = hojaEntidad_(ent);
    var f = buscarPorId_(sh, uid);
    if (PORTAL.EDITABLES.indexOf(f.estado) < 0) throw new Error(bloqueada_(f, 'modificar'));
    var k = asegurarColumnas_(sh);
    escribirDatos_(sh, k, f.fila, v.datos);
    sh.getRange(f.fila, k.REG).setValue((f.registrado ? f.registrado + ' · ' : '') + 'corregida ' + ahora_() + ' por ' + s.usuario);
    sh.getRange(f.fila, k.EST, 1, 1).setValue('EN EVALUACIÓN');   // corregida: vuelve a la UGEL y queda bloqueada otra vez
    SpreadsheetApp.flush();
    return { ok: true, fila: f.fila };
  } finally {
    lock.releaseLock();
  }
}

/** Anula (borra) una solicitud aún no evaluada. */
function portalAnular(token, uid) {
  var s = sesion_(token);
  var ent = buscarEntidad_(s.cod);
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var sh = hojaEntidad_(ent);
    var f = buscarPorId_(sh, uid);
    if (PORTAL.EDITABLES.indexOf(f.estado) < 0) throw new Error(bloqueada_(f, 'anular'));
    var k = cols_(sh);
    sh.getRange(f.fila, 2, 1, k.COND - 1).clearContent();         // datos de la solicitud
    ['EST', 'OBS', 'FEC', 'ID', 'POR', 'REG', 'OTIC', 'TTRAB'].forEach(function (c) { if (k[c]) sh.getRange(f.fila, k[c]).clearContent(); });
    SpreadsheetApp.flush();
    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

/**
 * La entidad confirma que recibió el OTIC de una solicitud APROBADA (o lo desmarca).
 * Es solo informativo: la solicitud ya está aprobada y no queda pendiente de nada.
 */
function portalOtic(token, uid, marcar) {
  var s = sesion_(token);
  var ent = buscarEntidad_(s.cod);
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var sh = hojaEntidad_(ent);
    var f = buscarPorId_(sh, uid);
    if (!oticAplica_(f)) throw new Error('El OTIC solo se envía a DOCENTES / CESANTES con solicitud APROBADA o DESAPROBADA por la UGEL.');
    var k = asegurarColumnas_(sh);
    var txt = marcar ? ahora_() + ' · ' + s.nombre + ' (' + s.usuario + ')' : '';
    sh.getRange(f.fila, k.OTIC).setNumberFormat('@').setValue(txt);
    SpreadsheetApp.flush();
    return { ok: true, otic: txt };
  } finally {
    lock.releaseLock();
  }
}

/* ============================ INTERNOS ============================ */

/**
 * Busca el DNI en el padrón de la UGEL (pestaña PADRON del PANEL) para autocompletar el formulario.
 * Solo devuelve apellidos, nombres y código modular, y solo con el DNI completo.
 * { hallado: false } si no figura: la entidad escribe los datos a mano.
 */
function portalBuscarDni(token, dni) {
  var s = sesion_(token);
  dni = String(dni || '').replace(/\D/g, '');
  if (!/^\d{8}$/.test(dni)) return { hallado: false };
  var cache = CacheService.getScriptCache();
  var hora = Utilities.formatDate(new Date(), PORTAL.ZONA, 'yyMMddHH');
  var kq = 'q:' + String(s.usuario).toLowerCase() + ':' + hora;
  var n = parseInt(cache.get(kq) || '0', 10);
  if (n >= PORTAL.MAX_CONSULTAS) return { hallado: false, limite: true };
  cache.put(kq, String(n + 1), 3700);

  var kc = 'p:' + dni, guardado = cache.get(kc);
  if (guardado) return JSON.parse(guardado);
  var sh = panel_().getSheetByName(PORTAL.HOJA_PADRON), r = { hallado: false };
  if (sh && sh.getLastRow() >= 2) {
    var c = sh.getRange(2, 1, sh.getLastRow() - 1, 1).createTextFinder(dni).matchEntireCell(true).findNext();
    if (c) {
      var v = sh.getRange(c.getRow(), 1, 1, 5).getDisplayValues()[0];
      r = { hallado: true, apellidos: (String(v[1]).trim() + ' ' + String(v[2]).trim()).trim(), nombres: String(v[3]).trim(),
            mod: String(v[4]).replace(/\D/g, '') };
    }
  }
  cache.put(kc, JSON.stringify(r), 1800);   // 30 min: el padrón cambia poco
  return r;
}

/** v1.17: el OTIC solo corresponde a docente/cesante, aprobado o desaprobado por la UGEL (no administrativos). */
function oticAplica_(f) {
  if (['APROBADO', 'DESAPROBADO'].indexOf(f.estado) < 0) return false;
  if (f.ttrab === 'ADMINISTRATIVO') return false;
  if (f.ttrab || String(f.cond).toUpperCase() === 'C') return true;
  return f.estado === 'APROBADO';                                  // solicitudes antiguas sin tipo: como antes
}

/** v1.17: un desaprobado no amarra presupuesto ni cuenta como cliente ya registrado. */
var NO_CUENTAN = ['DESAPROBADO', 'POSIBLE DESAPROBACIÓN', 'ANULADA'];   // v1.18: la anulada por la UGEL tampoco cuenta

function bloqueada_(f, accion) {
  var e = f.estado || 'EN EVALUACIÓN';
  return 'La solicitud de la fila ' + f.fila + ' ya fue enviada a la UGEL (' + e + ') y no se puede ' + accion + '. ' +
         (PORTAL.BLOQUEADOS.indexOf(e) >= 0 || e === 'POSIBLE DESAPROBACIÓN' || e === 'ANULADA' ? 'Ya tiene respuesta de la UGEL.'
                                                                         : 'Solo se puede corregir si la UGEL la OBSERVA.');
}

function ahora_() { return Utilities.formatDate(new Date(), PORTAL.ZONA, PORTAL.FMT_REG); }

/**
 * Regla del monto disponible (la misma que aplica la web): si la última evaluación de la UGEL para este DNI
 * —en cualquier entidad y para cualquier tipo de producto— dejó un disponible menor que la cuota pedida,
 * el portal NO deja registrar la solicitud. Devuelve el mensaje, o '' si se puede registrar.
 */
function excedeDisponible_(d) {
  var u = disponible_(d.dni);
  if (!u || !(d.cuota > u.monto + 0.001)) return '';
  return 'POSIBLE DESAPROBACIÓN — No se puede registrar esta solicitud: la cuota ' + soles_(d.cuota) + ' excede el monto disponible del trabajador (' +
         soles_(u.monto) + ') según la última evaluación de la UGEL del ' + u.fecha + '.';
}

function disponible_(dni) {
  var sh = panel_().getSheetByName(PORTAL.HOJA_DISP);
  if (!sh || sh.getLastRow() < 2) return null;
  var hallado = sh.getRange(2, 1, sh.getLastRow() - 1, 1).createTextFinder(String(dni)).matchEntireCell(true).findNext();
  if (!hallado) return null;
  var r = sh.getRange(hallado.getRow(), 1, 1, 4).getValues()[0];
  var monto = Number(r[1]);
  if (r[1] === '' || isNaN(monto)) return null;
  return { monto: monto, resultado: String(r[2]), fecha: r[3] instanceof Date ? Utilities.formatDate(r[3], PORTAL.ZONA, PORTAL.FMT_EVAL) : String(r[3]) };
}

function soles_(n) {
  var p = (Math.round(Number(n) * 100) / 100).toFixed(2).split('.');
  return 'S/ ' + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + p[1];
}

/** Mapa columna → número (1 = A) leyendo la fila de cabeceras del Sheet de la entidad. */
function cols_(sh) {
  var cab = sh.getRange(CONFIG.FILA_CAB, 1, 1, sh.getLastColumn()).getValues()[0].map(norm_);
  var k = {};
  Object.keys(COLS).forEach(function (c) { var i = cab.indexOf(COLS[c]); if (i >= 0) k[c] = i + 1; });
  ['MOD', 'DNI', 'NOM', 'APE', 'TIPO', 'PER', 'CUOTA', 'COND', 'EST', 'OBS', 'FEC', 'ID'].forEach(function (c) {
    if (!k[c]) throw new Error('El Sheet de la entidad no tiene la columna ' + COLS[c] + '. Comuníquese con la UGEL.');
  });
  return k;
}

function panel_() {
  var id = PropertiesService.getScriptProperties().getProperty('PANEL_ID');
  return id ? SpreadsheetApp.openById(id) : SpreadsheetApp.getActive();
}

function sesion_(token) {
  var raw = token && CacheService.getScriptCache().get('s:' + token);
  if (!raw) throw new Error('SESION: Su sesión terminó. Vuelva a ingresar.');
  return JSON.parse(raw);
}

function buscarUsuario_(usuario) {
  var q = String(usuario || '').trim().toLowerCase();       // al entrar no se distinguen mayúsculas
  var sh = panel_().getSheetByName(PORTAL.HOJA_USU);
  if (!sh || sh.getLastRow() < 2 || !q) return null;
  var v = sh.getRange(2, 1, sh.getLastRow() - 1, U_CAB.length).getValues();
  for (var i = 0; i < v.length; i++) {
    var tal = String(v[i][U.USU - 1]).trim();
    if (tal.toLowerCase() === q) {
      var act = v[i][U.ACT - 1];
      return {                                                // usuario tal como está en el PANEL (ej. CAJAICA)
        hoja: sh, fila: i + 2, usuario: tal, nombre: String(v[i][U.NOM - 1] === '' || v[i][U.NOM - 1] == null ? tal : v[i][U.NOM - 1]),
        cod: String(v[i][U.COD - 1]).trim(), activo: act === true || /^(si|sí|true|1)$/i.test(String(act)),
        hash: String(v[i][U.HASH - 1] || ''), debeCambiar: v[i][U.CAMB - 1] === true || String(v[i][U.CAMB - 1]).toUpperCase() === 'TRUE'
      };
    }
  }
  return null;
}

function buscarEntidad_(cod) {
  var sh = panel_().getSheetByName(PORTAL.HOJA_ENT);
  var v = sh.getRange(2, 1, Math.max(sh.getLastRow() - 1, 1), 4).getValues();
  for (var i = 0; i < v.length; i++) {
    if (String(v[i][0]).trim() === String(cod).trim() && String(v[i][3]).trim()) {
      return { cod: String(v[i][0]), nombre: String(v[i][1]).trim().toUpperCase(), grupo: String(v[i][2]), enlace: String(v[i][3]) };
    }
  }
  return null;
}

function hojaEntidad_(ent) {
  if (!ent) throw new Error('Entidad no encontrada.');
  var sh = SpreadsheetApp.openById(id_(ent.enlace)).getSheetByName(CONFIG.HOJA_PLANTILLA);
  if (!sh) throw new Error('El Sheet de la entidad no tiene la pestaña ' + CONFIG.HOJA_PLANTILLA + '.');
  return sh;
}

function leerFilas_(sh) {
  var f1 = CONFIG.FILA_CAB + 1;
  var n = Math.max(sh.getLastRow() - CONFIG.FILA_CAB, 0);
  if (!n) return [];
  var k = cols_(sh);
  var v = sh.getRange(f1, 1, n, sh.getLastColumn()).getValues();
  var g = function (r, c) { return k[c] ? r[k[c] - 1] : ''; };
  var out = [];
  for (var i = 0; i < v.length; i++) {
    var r = v[i];
    var conDatos = ['MOD', 'DNI', 'NOM', 'APE', 'CUOTA'].some(function (c) { return String(g(r, c)).trim() !== ''; });
    if (!conDatos) continue;
    out.push({
      fila: f1 + i, uid: String(g(r, 'ID') || ''),
      mod: txt_(g(r, 'MOD')), cargo: txt_(g(r, 'CARGO')), dni: txt_(g(r, 'DNI')), nombres: String(g(r, 'NOM')), apellidos: String(g(r, 'APE')),
      tipo: String(g(r, 'TIPO')), compra: String(g(r, 'COMPRA')), periodo: txt_(g(r, 'PER')),
      cuota: Number(g(r, 'CUOTA')) || 0, cond: String(g(r, 'COND')),
      estado: String(g(r, 'EST') || ''), obs: String(g(r, 'OBS') || ''), fecha: fecha_(g(r, 'FEC'), PORTAL.FMT_EVAL),
      por: String(g(r, 'POR') || ''), registrado: fecha_(g(r, 'REG'), PORTAL.FMT_REG), otic: fecha_(g(r, 'OTIC'), PORTAL.FMT_REG),
      ttrab: String(g(r, 'TTRAB') || '').trim().toUpperCase()
    });
  }
  return out;
}

function txt_(v) { return (typeof v === 'number') ? String(Math.round(v)) : String(v || '').trim(); }
function fecha_(v, fmt) { return v instanceof Date ? Utilities.formatDate(v, PORTAL.ZONA, fmt) : String(v || ''); }

function buscarPorId_(sh, uid) {
  var f = leerFilas_(sh).filter(function (x) { return x.uid && x.uid === uid; })[0];
  if (!f) throw new Error('No se encontró la solicitud (puede que la hayan borrado). Actualice la lista.');
  return f;
}

function filaLibre_(sh, k) {
  var f1 = CONFIG.FILA_CAB + 1;
  var v = sh.getRange(f1, 2, CONFIG.FILAS, k.COND - 1).getValues();     // columnas de datos (B..ACT/CESANT)
  for (var i = 0; i < v.length; i++) {
    if (v[i].every(function (c) { return String(c).trim() === ''; })) return f1 + i;
  }
  sh.insertRowsAfter(sh.getMaxRows(), 50);
  return f1 + v.length;
}

/** Agrega al final las columnas que falten (REGISTRADO POR, FECHA REGISTRO, RECEPCIÓN OTIC). */
function asegurarColumnas_(sh) {
  var k = cols_(sh);
  var faltan = COLS_EXTRA.filter(function (x) { return !k[x[0]]; });
  if (!faltan.length) return k;
  var c = sh.getLastColumn() + 1;
  if (sh.getMaxColumns() < c + faltan.length - 1) sh.insertColumnsAfter(sh.getMaxColumns(), c + faltan.length - 1 - sh.getMaxColumns());
  faltan.forEach(function (x, i) {
    sh.getRange(CONFIG.FILA_CAB, c + i).setValue(x[1]).setBackground('#475569').setFontColor('#ffffff')
      .setFontWeight('bold').setFontSize(9).setHorizontalAlignment('center').setVerticalAlignment('middle').setWrap(true);
    sh.setColumnWidth(c + i, x[2]);
    sh.getRange(CONFIG.FILA_CAB + 1, c + i, CONFIG.FILAS, 1).setNumberFormat('@').setFontSize(9).setFontColor('#475569').setBackground('#f1f5f9');
  });
  return cols_(sh);
}

function escribirDatos_(sh, k, fila, d) {
  var fila_ = sh.getRange(fila, 2, 1, k.COND - 1).getValues()[0];
  var pon = function (c, v) { if (k[c]) fila_[k[c] - 2] = v; };
  pon('MOD', d.mod); pon('CARGO', d.cargo); pon('DNI', d.dni); pon('NOM', d.nombres); pon('APE', d.apellidos);
  pon('TIPO', d.tipo); pon('GRUPO', d.grupo); pon('ENT', d.entidad); pon('COMPRA', d.compra);
  pon('PER', d.periodo); pon('CUOTA', d.cuota); pon('COND', d.cond);
  sh.getRange(fila, 2, 1, k.COND - 1).setValues([fila_]);
  if (k.TTRAB) sh.getRange(fila, k.TTRAB).setValue(d.ttrab || '');
}

function validar_(d, ent) {
  d = d || {};
  var t = function (x) { return String(x == null ? '' : x).trim(); };
  var x = {
    mod: t(d.mod), cargo: t(d.cargo), dni: t(d.dni), nombres: t(d.nombres).toUpperCase().replace(/\s+/g, ' '),
    apellidos: t(d.apellidos).toUpperCase().replace(/\s+/g, ' '), tipo: t(d.tipo).toUpperCase(),
    grupo: ent.grupo, entidad: ent.nombre, compra: t(d.compra).toUpperCase(),
    periodo: t(d.periodo).replace(/\D/g, ''), cuota: Math.round(Number(String(d.cuota).replace(',', '.')) * 100) / 100, cond: t(d.cond).toUpperCase(),
    ttrab: t(d.ttrab).toUpperCase()
  };
  if (x.cond === 'C') x.ttrab = 'DOCENTE/CESANTE';            // todo cesante se evalúa como "Docente y/o cesante"
  var e = [];
  if (!/^\d{6,10}$/.test(x.mod)) e.push('Código modular: solo números (6 a 10 dígitos).');
  if (!/^\d{1,10}$/.test(x.cargo)) e.push('Código de cargo: solo números.');
  if (!/^\d{8}$/.test(x.dni)) e.push('DNI: exactamente 8 dígitos.');
  if (!x.nombres) e.push('Faltan los nombres.');
  if (!x.apellidos) e.push('Faltan los apellidos.');
  if (PORTAL.TIPOS.indexOf(x.tipo) < 0) e.push('Elija el tipo de producto.');
  if (PORTAL.COMPRA.indexOf(x.tipo) >= 0 && !x.compra) e.push('Es compra de deuda: indique la entidad cuya deuda se compra.');
  if (x.tipo === 'AMPLIACION') x.compra = ent.nombre;          // la ampliación es siempre de la misma entidad
  else if (PORTAL.COMPRA.indexOf(x.tipo) < 0) x.compra = '';
  if (!/^20\d{2}(0[1-9]|1[0-2])$/.test(x.periodo)) e.push('Periodo inválido (AAAAMM).');
  if (!(x.cuota > 0)) e.push('La cuota mensual debe ser mayor a cero.');
  if (['A', 'C'].indexOf(x.cond) < 0) e.push('Indique si es Activo (A) o Cesante (C).');
  if (TIPOS_TRAB.indexOf(x.ttrab) < 0) e.push('Indique el tipo de trabajador: Docente / Cesante o Administrativo.');
  return { datos: x, errores: e };
}

/* ---------------------------- Contraseñas ---------------------------- */

function hashNuevo_(clave) {
  var salt = Utilities.getUuid().replace(/-/g, '').slice(0, 16);
  return salt + '$' + derivar_(clave, salt);
}
function verificarClave_(clave, guardado) {
  var p = String(guardado).split('$');
  return p.length === 2 && derivar_(clave, p[0]) === p[1];
}
function derivar_(clave, salt) {
  var h = salt + '|' + clave;
  for (var i = 0; i < 150; i++) {
    h = Utilities.base64Encode(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, h + '|' + salt, Utilities.Charset.UTF_8));
  }
  return h;
}
function claveAleatoria_() {
  var abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ', num = '23456789', s = '';
  for (var i = 0; i < 6; i++) s += abc.charAt(Math.floor(Math.random() * abc.length));
  for (var j = 0; j < 2; j++) s += num.charAt(Math.floor(Math.random() * num.length));
  return s;
}

/* ===================== ADMINISTRACIÓN (menú del PANEL) ===================== */

function prepararUsuarios() {
  var ss = SpreadsheetApp.getActive();
  PropertiesService.getScriptProperties().setProperty('PANEL_ID', ss.getId());
  var sh = ss.getSheetByName(PORTAL.HOJA_USU) || ss.insertSheet(PORTAL.HOJA_USU);
  sh.getRange(1, 1, 1, U_CAB.length).setValues([U_CAB]).setBackground('#0a3a5a').setFontColor('#fff').setFontWeight('bold').setWrap(true);
  sh.setFrozenRows(1);
  [150, 230, 90, 330, 70, 130, 85, 130, 60, 60].forEach(function (w, i) { sh.setColumnWidth(i + 1, w); });
  var cods = ss.getSheetByName(PORTAL.HOJA_ENT).getRange('A2:A300');
  sh.getRange(2, U.COD, 500, 1).setDataValidation(SpreadsheetApp.newDataValidation().requireValueInRange(cods, true).setAllowInvalid(false).build());
  sh.getRange(2, U.ACT, 500, 1).insertCheckboxes();
  sh.getRange(2, U.CAMB, 500, 1).insertCheckboxes();
  formatoClaves_(sh);
  sh.hideColumns(U.HASH, 2);
  SpreadsheetApp.getUi().alert('Hoja USUARIOS lista.\n\nPor cada persona de una entidad escriba: USUARIO (ej. pichincha.jorge), NOMBRE, COD ENTIDAD y la CONTRASEÑA que usted elija.\n' +
    'Luego: Entidades UGEL → 6. Portal: guardar contraseñas.');
}

/** Columnas F (CONTRASEÑA) y G (ASIGNADA). También convierte hojas creadas con la versión anterior (clave temporal). */
function formatoClaves_(sh) {
  sh.getRange(1, U.TMP, 1, 2).setValues([['CONTRASEÑA', 'ASIGNADA']]);
  var g = sh.getRange(2, U.RES, 500, 1);
  if (g.getDataValidations().some(function (r) { return r[0] !== null; })) {      // antes era la casilla RESETEAR
    g.clearDataValidations();
    var vals = g.getValues().map(function (r) { return [r[0] === true || r[0] === false ? '' : r[0]]; });
    g.setValues(vals);
  }
  g.setNumberFormat('@').setFontSize(9).setFontColor('#475569');
  sh.getRange(2, U.TMP, 500, 1).setNumberFormat('@').setFontFamily('Roboto Mono').setFontWeight('bold').setBackground('#fff8e1');
  sh.setColumnWidth(U.TMP, 150); sh.setColumnWidth(U.RES, 120);
}

/**
 * Guarda las contraseñas que la UGEL escribió en la columna F (CONTRASEÑA).
 * Cada contraseña se guarda cifrada y en la celda queda "✓ guardada"; el usuario entra con ella sin
 * tener que cambiarla. Para cambiar la contraseña de alguien, escriba una nueva en su fila y vuelva a ejecutar.
 */
function generarClaves() {
  var ss = SpreadsheetApp.getActive();
  PropertiesService.getScriptProperties().setProperty('PANEL_ID', ss.getId());
  var sh = ss.getSheetByName(PORTAL.HOJA_USU);
  if (!sh) { SpreadsheetApp.getUi().alert('Primero use "5. Portal: preparar hoja de USUARIOS".'); return; }
  formatoClaves_(sh);
  var n = sh.getLastRow() - 1;
  if (n < 1) { SpreadsheetApp.getUi().alert('No hay usuarios.'); return; }
  var v = sh.getRange(2, 1, n, U_CAB.length).getValues();
  var vistos = {}, hechos = [], avisos = [];
  for (var i = 0; i < v.length; i++) {
    var tal = String(v[i][U.USU - 1]).trim();            // se guarda como lo escribió la UGEL (mayúsculas o minúsculas)
    var usu = tal.toLowerCase();                         // al entrar no se distinguen mayúsculas
    if (!usu) continue;
    var fila = i + 2;
    if (!/^[a-zñ0-9._-]{3,40}$/.test(usu)) { avisos.push('Fila ' + fila + ': usuario inválido (solo letras, números, punto o guion, sin espacios ni tildes)'); continue; }
    if (vistos[usu]) { avisos.push('Fila ' + fila + ': el usuario ' + tal + ' está repetido'); continue; }
    vistos[usu] = true;
    var ent = buscarEntidad_(v[i][U.COD - 1]);
    if (!ent) { avisos.push('Fila ' + fila + ' (' + usu + '): COD ENTIDAD inválido o entidad sin Sheet'); continue; }
    if (tal !== String(v[i][U.USU - 1])) sh.getRange(fila, U.USU).setValue(tal);   // solo quita espacios sobrantes
    sh.getRange(fila, U.ENT).setValue(ent.nombre);
    var clave = String(v[i][U.TMP - 1] == null ? '' : v[i][U.TMP - 1]);
    var tieneHash = !!v[i][U.HASH - 1];
    if (!clave || clave.charAt(0) === '✓') {
      if (!tieneHash) avisos.push('Fila ' + fila + ' (' + usu + '): escriba su CONTRASEÑA en la columna F');
      continue;
    }
    if (clave.length < 8 || !/[A-Za-z]/.test(clave) || !/\d/.test(clave) || /\s/.test(clave)) {
      avisos.push('Fila ' + fila + ' (' + usu + '): la contraseña debe tener al menos 8 caracteres, con letras y números, sin espacios');
      continue;
    }
    if (!tieneHash) sh.getRange(fila, U.ACT).setValue(true);           // usuario nuevo: queda activo
    sh.getRange(fila, U.HASH).setValue(hashNuevo_(clave));
    sh.getRange(fila, U.CAMB).setValue(false);                          // no se le pide cambiarla
    sh.getRange(fila, U.TMP).setValue(MARCA_CLAVE);
    sh.getRange(fila, U.RES).setValue(ahora_());
    CacheService.getScriptCache().remove('fail:' + usu);                // desbloquea si estaba bloqueado
    hechos.push(tal);
  }
  SpreadsheetApp.getUi().alert('Contraseñas guardadas: ' + hechos.length + (hechos.length ? ' (' + hechos.join(', ') + ')' : '') + '.\n\n' +
    'Por seguridad, en la columna F ahora dice "' + MARCA_CLAVE + '": la contraseña ya no se muestra en la hoja.\n' +
    'Para cambiar la contraseña de alguien, escriba una nueva en su fila y vuelva a ejecutar este menú.' +
    (avisos.length ? '\n\nRevise:\n' + avisos.join('\n') : ''));
}

function verEnlacePortal() {
  var url = '';
  try { url = ScriptApp.getService().getUrl(); } catch (e) {}
  SpreadsheetApp.getUi().alert(url
    ? 'Enlace del portal para las entidades:\n\n' + url + '\n\n(Compártalo junto con el usuario y la contraseña de cada entidad.)'
    : 'El portal aún no está publicado.\n\nExtensiones → Apps Script → Implementar → Nueva implementación → tipo "Aplicación web"\nEjecutar como: Yo · Quién tiene acceso: Cualquier usuario.');
}
