/**
 * @OnlyCurrentDoc
 *
 * PLANTILLA DE SOLICITUDES DE CRÉDITO — UGEL CHINCHA
 * Formato basado en "Constancia de disponibilidad del 50% - DRE Ica".
 *
 * Instalación (solo la UGEL, una vez en la plantilla maestra):
 *   1. Crea un Google Sheet vacío con la cuenta de la UGEL.
 *   2. Extensiones → Apps Script → pega este archivo completo → Guardar.
 *   3. Cambia CUENTA_SERVICIO por el correo de la cuenta de servicio.
 *   4. Recarga el Sheet → menú "UGEL Chincha" → "Instalar / reparar plantilla".
 * Por cada entidad: Archivo → Hacer una copia → en la copia, "UGEL Chincha" → "Configurar entidad".
 */

var CONFIG = {
  CUENTA_SERVICIO: 'TU-CUENTA@TU-PROYECTO.iam.gserviceaccount.com',
  HOJA: 'SOLICITUDES',
  FILA_CAB: 6,           // fila de cabeceras
  FILAS: 500,            // filas disponibles para solicitudes
  TIPOS:  ['NUEVO', 'NUEVA COMPRA DE PRODUCTO', 'AMPLIACION', 'AMPLIACION COMPRA'],
  COMPRA: ['NUEVA COMPRA DE PRODUCTO', 'AMPLIACION COMPRA'],
  GRUPOS: ['COOPERATIVA JUDICIALES', 'FONDO DE BIENESTAR', 'COOPERATIVAS', 'ENTIDADES REGULADAS POR LA SBS', 'SINDICATO'],
  COLOR:  '#0a3a5a',
  COLOR_UGEL: '#475569'
};

// Columnas (1 = A). v2: sin GRUPO ni ENTIDAD (ya están en la cabecera del Sheet)
var C = { ITEM: 1, MOD: 2, CARGO: 3, DNI: 4, NOM: 5, APE: 6, TIPO: 7, COMPRA: 8, PER: 9, CUOTA: 10, COND: 11,
          EST: 12, OBS: 13, FEC: 14, POR: 15, REG: 16, ID: 17 };
var CABECERAS = ['ITEM', 'COD_MODULAR', 'COD_CARGO', 'DNI', 'NOMBRES', 'APELLIDOS', 'TIPO DE PRODUCTO',
  'ENTIDAD COMPRA DE DEUDA', 'PERIODO', 'MONTO DE CUOTA MENSUAL', 'ACT/CESANT',
  'ESTADO UGEL', 'OBSERVACIÓN UGEL', 'FECHA EVALUACIÓN', 'REGISTRADO POR', 'FECHA REGISTRO', 'ID'];
// Celdas de la cabecera con los datos de la entidad
var CELDA = { COD: 'C3', ENT: 'E3', GRUPO: 'K3' };
var BLOQUEADOS = ['APROBADO', 'DESAPROBADO', 'DUPLICADA'];

/* ============================== MENÚ ============================== */

function onOpen() {
  SpreadsheetApp.getUi().createMenu('UGEL Chincha')
    .addItem('Revisar mis filas', 'revisarFilas')
    .addItem('Ayuda', 'ayuda')
    .addSeparator()
    .addItem('Configurar entidad (solo UGEL)', 'configurarEntidad')
    .addItem('Instalar / reparar plantilla (solo UGEL)', 'instalarPlantilla')
    .addToUi();
}

/* ======================= EDICIÓN DE LA ENTIDAD ======================= */

function onEdit(e) {
  if (!e || !e.range) return;
  var sh = e.range.getSheet();
  if (sh.getName() !== CONFIG.HOJA) return;

  var r0 = e.range.getRow(), nR = e.range.getNumRows();
  var c0 = e.range.getColumn(), nC = e.range.getNumColumns();
  var primera = CONFIG.FILA_CAB + 1;
  if (r0 + nR - 1 < primera || c0 > C.COND) return;

  var desde = Math.max(r0, primera);
  var filas = r0 + nR - desde;
  var vals = sh.getRange(desde, 1, filas, C.ID).getValues();

  // 1) Filas ya evaluadas: no se pueden modificar
  var bloqueadas = [];
  for (var i = 0; i < vals.length; i++) {
    var est = String(vals[i][C.EST - 1]).toUpperCase();
    if (BLOQUEADOS.indexOf(est) >= 0) bloqueadas.push(desde + i);
  }
  if (bloqueadas.length) {
    if (nR === 1 && nC === 1) {
      e.range.setValue(e.oldValue === undefined ? '' : e.oldValue);
      SpreadsheetApp.getActive().toast('La fila ' + bloqueadas[0] + ' ya fue evaluada por la UGEL y no se puede modificar. Registre la solicitud en una fila nueva.', 'Fila bloqueada', 8);
      return;
    }
    SpreadsheetApp.getActive().toast('Atención: modificó filas ya evaluadas (' + bloqueadas.join(', ') + '). La UGEL las volverá a revisar.', 'Filas evaluadas', 8);
  }

  // 2) Completar automáticamente: ID y mayúsculas
  var nomApe = [], ids = [];
  for (var j = 0; j < vals.length; j++) {
    var v = vals[j];
    var conDatos = [C.MOD, C.DNI, C.NOM, C.APE, C.CUOTA].some(function (c) { return String(v[c - 1]).trim() !== ''; });
    var nom = typeof v[C.NOM - 1] === 'string' ? v[C.NOM - 1].toUpperCase().replace(/\s+/g, ' ').trim() : v[C.NOM - 1];
    var ape = typeof v[C.APE - 1] === 'string' ? v[C.APE - 1].toUpperCase().replace(/\s+/g, ' ').trim() : v[C.APE - 1];
    // Fila vaciada = solicitud anulada; si luego se reutiliza, será una solicitud nueva
    var id  = !conDatos ? '' : (v[C.ID - 1] === '' ? Utilities.getUuid() : v[C.ID - 1]);
    nomApe.push([nom, ape]); ids.push([id]);
  }
  // 3) Mismo cliente ya registrado en este Sheet → aviso (no bloquea)
  if (c0 <= C.DNI && C.DNI <= c0 + nC - 1) avisarDuplicado_(sh, desde, vals);

  sh.getRange(desde, C.NOM, filas, 2).setValues(nomApe);
  sh.getRange(desde, C.ID, filas, 1).setValues(ids);
}

function avisarDuplicado_(sh, desde, vals) {
  var f1 = CONFIG.FILA_CAB + 1;
  var dnis = sh.getRange(f1, C.DNI, CONFIG.FILAS, 1).getValues();
  var estados = sh.getRange(f1, C.EST, CONFIG.FILAS, 1).getValues();
  for (var j = 0; j < vals.length; j++) {
    var dni = String(vals[j][C.DNI - 1]).trim();
    if (!dni) continue;
    for (var k = 0; k < dnis.length; k++) {
      var fila = f1 + k;
      if (fila !== desde + j && String(dnis[k][0]).trim() === dni) {
        SpreadsheetApp.getActive().toast('El DNI ' + dni + ' ya está registrado en la fila ' + fila +
          (estados[k][0] ? ' (estado UGEL: ' + estados[k][0] + ')' : '') + '. Puede continuar: la UGEL revisará el posible duplicado.',
          '⚠ Cliente ya registrado', 10);
        return;
      }
    }
  }
}

/** Revisión rápida antes de que la UGEL sincronice. */
function revisarFilas() {
  var sh = hoja_();
  var n = sh.getLastRow() - CONFIG.FILA_CAB;
  var ui = SpreadsheetApp.getUi();
  if (n <= 0) { ui.alert('No hay solicitudes registradas.'); return; }
  var vals = sh.getRange(CONFIG.FILA_CAB + 1, 1, n, C.ID).getValues();
  var problemas = [], total = 0;
  vals.forEach(function (v, i) {
    var fila = CONFIG.FILA_CAB + 1 + i;
    var conDatos = [C.MOD, C.DNI, C.NOM, C.APE, C.CUOTA].some(function (c) { return String(v[c - 1]).trim() !== ''; });
    if (!conDatos) return;
    total++;
    var p = [];
    if (!/^\d{8}$/.test(String(v[C.DNI - 1]))) p.push('DNI');
    if (!/^\d{6,10}$/.test(String(v[C.MOD - 1]))) p.push('cód. modular');
    if (!/^\d{1,10}$/.test(String(v[C.CARGO - 1]))) p.push('cód. cargo');
    if (!String(v[C.NOM - 1]).trim()) p.push('nombres');
    if (!String(v[C.APE - 1]).trim()) p.push('apellidos');
    if (CONFIG.TIPOS.indexOf(String(v[C.TIPO - 1])) < 0) p.push('tipo de producto');
    if (CONFIG.COMPRA.indexOf(String(v[C.TIPO - 1])) >= 0 && !String(v[C.COMPRA - 1]).trim()) p.push('entidad de compra de deuda');
    if (!/^20\d{2}(0[1-9]|1[0-2])$/.test(String(v[C.PER - 1]))) p.push('periodo AAAAMM');
    if (!(Number(v[C.CUOTA - 1]) > 0)) p.push('cuota');
    if (['A', 'C'].indexOf(String(v[C.COND - 1])) < 0) p.push('ACT/CESANT');
    if (p.length) problemas.push('Fila ' + fila + ': revisar ' + p.join(', '));
  });
  ui.alert(problemas.length
    ? 'Se encontraron ' + problemas.length + ' fila(s) con datos incompletos o inválidos:\n\n' + problemas.slice(0, 25).join('\n') + (problemas.length > 25 ? '\n…' : '')
    : '✓ Las ' + total + ' solicitud(es) tienen los datos completos.');
}

function ayuda() {
  SpreadsheetApp.getUi().alert(
    'Cómo registrar una solicitud\n\n' +
    '• Use una fila por solicitud, desde la fila ' + (CONFIG.FILA_CAB + 1) + '.\n' +
    '• DNI: 8 dígitos. Periodo: AAAAMM (ej. 202611). ACT/CESANT: A o C.\n' +
    '• Tipo de producto: elija de la lista. La entidad y el grupo ya figuran arriba.\n' +
    '• Si es COMPRA de deuda, indique la entidad cuya deuda compra.\n' +
    '• Las columnas grises (ESTADO, OBSERVACIÓN, FECHA) las completa la UGEL.\n' +
    '• Si una fila sale OBSERVADA, corríjala: se vuelve a evaluar sola.\n' +
    '• Las filas APROBADAS o DESAPROBADAS ya no se pueden modificar.\n' +
    '• Para anular una solicitud aún no evaluada, borre el contenido de sus celdas (columnas B a K).');
}

/* ============================ SOLO UGEL ============================ */

function configurarEntidad() {
  var ui = SpreadsheetApp.getUi();
  var sh = hoja_();
  var c = ui.prompt('Configurar entidad', 'Código de la entidad en planilla (ej. 1754):', ui.ButtonSet.OK_CANCEL);
  if (c.getSelectedButton() !== ui.Button.OK) return;
  var r = ui.prompt('Configurar entidad', 'Nombre de la entidad (como figura en planilla):', ui.ButtonSet.OK_CANCEL);
  if (r.getSelectedButton() !== ui.Button.OK || !r.getResponseText().trim()) return;
  var nombre = r.getResponseText().trim().toUpperCase();

  var g = ui.prompt('Grupo de descuento', 'Escriba el número:\n' + CONFIG.GRUPOS.map(function (x, i) { return (i + 1) + '. ' + x; }).join('\n'), ui.ButtonSet.OK_CANCEL);
  if (g.getSelectedButton() !== ui.Button.OK) return;
  var grupo = CONFIG.GRUPOS[parseInt(g.getResponseText(), 10) - 1] || '';

  sh.getRange(CELDA.COD).setValue(c.getResponseText().replace(/\D/g, ''));
  sh.getRange(CELDA.ENT).setValue(nombre);
  sh.getRange(CELDA.GRUPO).setValue(grupo);
  SpreadsheetApp.getActive().rename('SOLICITUDES DE CRÉDITO — ' + nombre);
  aplicarProtecciones_(sh);
  ui.alert('Listo.\n\nAhora comparta este Sheet (Compartir) como Editor con:\n• Los usuarios de ' + nombre + '\n• ' + CONFIG.CUENTA_SERVICIO +
           '\n\nAcceso general: Restringido.\nLuego registre el enlace en la web de Informática → Entidades.');
}

function instalarPlantilla() {
  var ss = SpreadsheetApp.getActive();
  var sh = ss.getSheetByName(CONFIG.HOJA) || ss.insertSheet(CONFIG.HOJA, 0);
  ss.getSheets().forEach(function (s) { if (s.getName() !== CONFIG.HOJA && s.getLastRow() === 0 && ss.getSheets().length > 1) ss.deleteSheet(s); });

  var totalFilas = CONFIG.FILA_CAB + CONFIG.FILAS;
  if (sh.getMaxRows() < totalFilas) sh.insertRowsAfter(sh.getMaxRows(), totalFilas - sh.getMaxRows());
  if (sh.getMaxColumns() < C.ID) sh.insertColumnsAfter(sh.getMaxColumns(), C.ID - sh.getMaxColumns());
  if (sh.getMaxColumns() > C.ID) sh.deleteColumns(C.ID + 1, sh.getMaxColumns() - C.ID);

  var datos = sh.getRange(CONFIG.FILA_CAB + 1, 1, CONFIG.FILAS, C.ID);
  datos.clearDataValidations().clearFormat();
  sh.setConditionalFormatRules([]);
  sh.showColumns(1, C.ID);
  sh.getRange(1, 1, CONFIG.FILA_CAB, C.ID).breakApart().clearContent().clearFormat();
  sh.setHiddenGridlines(true);
  sh.getRange(1, 1, totalFilas, C.ID).setFontFamily('Arial').setFontSize(10).setVerticalAlignment('middle');

  // Encabezado
  sh.getRange('A1:P1').merge().setValue('FORMATO PARA CONSTANCIA DE DISPONIBILIDAD DEL 50% - DRE ICA')
    .setFontSize(15).setFontWeight('bold').setFontColor(CONFIG.COLOR).setHorizontalAlignment('center');
  sh.getRange('A2:P2').merge().setValue('UGEL CHINCHA · Registro de solicitudes de crédito de entidades terceras')
    .setFontColor('#64748b').setHorizontalAlignment('center');
  sh.getRange('A3:B3').merge().setValue('CÓDIGO:').setFontWeight('bold').setHorizontalAlignment('right');
  sh.getRange('C3').setNumberFormat('@').setFontWeight('bold').setFontSize(12).setBackground(CONFIG.COLOR).setFontColor('#ffffff').setHorizontalAlignment('center');
  sh.getRange('D3').setValue('ENTIDAD:').setFontWeight('bold').setHorizontalAlignment('right');
  sh.getRange('E3:H3').merge().setFontWeight('bold').setFontSize(12).setBackground('#eef4f8').setFontColor(CONFIG.COLOR);
  sh.getRange('I3:J3').merge().setValue('GRUPO:').setFontWeight('bold').setHorizontalAlignment('right');
  sh.getRange('K3:N3').merge().setFontWeight('bold').setBackground('#eef4f8').setFontColor(CONFIG.COLOR);
  sh.getRange('A4:P4').merge().setValue('Registre las solicitudes en el Portal de entidades. DNI de 8 dígitos · Periodo AAAAMM (ej. 202611) · ACT/CESANT: A o C. ' +
    'Las columnas grises las completa la UGEL; las filas con respuesta quedan bloqueadas. Fecha: AA/MM/DD.')
    .setFontSize(9).setFontColor('#475569').setWrap(true).setFontStyle('italic');
  sh.setRowHeight(1, 34); sh.setRowHeight(3, 26); sh.setRowHeight(4, 30); sh.setRowHeight(5, 8);

  // Cabeceras
  var cab = sh.getRange(CONFIG.FILA_CAB, 1, 1, C.ID);
  cab.setValues([CABECERAS]).setBackground(CONFIG.COLOR).setFontColor('#ffffff').setFontWeight('bold')
     .setFontSize(9).setHorizontalAlignment('center').setWrap(true);
  sh.getRange(CONFIG.FILA_CAB, C.EST, 1, 5).setBackground(CONFIG.COLOR_UGEL);
  sh.setRowHeight(CONFIG.FILA_CAB, 44);
  sh.setFrozenRows(CONFIG.FILA_CAB);

  // Formatos de datos
  [C.MOD, C.CARGO, C.DNI, C.PER, C.COND, C.FEC, C.POR, C.REG].forEach(function (c) { sh.getRange(CONFIG.FILA_CAB + 1, c, CONFIG.FILAS, 1).setNumberFormat('@'); });
  sh.getRange(CONFIG.FILA_CAB + 1, C.CUOTA, CONFIG.FILAS, 1).setNumberFormat('#,##0.00');
  datos.setBorder(true, true, true, true, true, true, '#d5dde6', SpreadsheetApp.BorderStyle.SOLID);
  sh.getRange(CONFIG.FILA_CAB + 1, C.ITEM, CONFIG.FILAS, 1).setHorizontalAlignment('center').setFontColor('#64748b').setBackground('#f8fafc');
  [C.MOD, C.CARGO, C.DNI, C.PER, C.COND].forEach(function (c) { sh.getRange(CONFIG.FILA_CAB + 1, c, CONFIG.FILAS, 1).setHorizontalAlignment('center'); });
  sh.getRange(CONFIG.FILA_CAB + 1, C.EST, CONFIG.FILAS, 5).setBackground('#f1f5f9');
  sh.getRange(CONFIG.FILA_CAB + 1, C.POR, CONFIG.FILAS, 2).setFontSize(9).setFontColor('#475569');
  sh.getRange(CONFIG.FILA_CAB + 1, C.EST, CONFIG.FILAS, 1).setFontWeight('bold').setHorizontalAlignment('center');
  sh.getRange(CONFIG.FILA_CAB + 1, C.OBS, CONFIG.FILAS, 1).setWrap(true).setFontSize(9);

  // N° de ítem automático
  sh.getRange(CONFIG.FILA_CAB + 1, C.ITEM, CONFIG.FILAS, 1).clearContent();
  var f1 = CONFIG.FILA_CAB + 1, fN = CONFIG.FILA_CAB + CONFIG.FILAS;
  SEP = detectarSeparador_(sh);
  sh.getRange(f1, C.ITEM).setFormula(F_('=ARRAYFORMULA(IF(D' + f1 + ':D' + fN + '=""§""§ROW(D' + f1 + ':D' + fN + ')-' + CONFIG.FILA_CAB + '))'));

  // Anchos
  var anchos = [44, 100, 84, 86, 160, 170, 170, 180, 76, 104, 72, 112, 280, 96, 190, 130, 60];
  anchos.forEach(function (w, i) { sh.setColumnWidth(i + 1, w); });
  sh.hideColumns(C.ID);

  aplicarValidaciones_(sh);
  aplicarFormatoCondicional_(sh);
  aplicarProtecciones_(sh);
  sh.activate();
  SpreadsheetApp.getUi().alert('Plantilla instalada.\n\nPara cada entidad: Archivo → Hacer una copia, y en la copia: UGEL Chincha → Configurar entidad.');
}

/* ============================ INTERNOS ============================ */

/* Separador de argumentos según la configuración regional del Sheet (',' o ';') */
var SEP = ',';
function F_(formula) { return formula.replace(/§/g, SEP); }
function detectarSeparador_(sh) {
  var celda = sh.getRange(1, C.ID);
  var sep = ';';
  try {
    celda.setFormula('=SUM(1,2)');
    SpreadsheetApp.flush();
    if (celda.getValue() === 3) sep = ',';
  } catch (e) { sep = ';'; }
  celda.clearContent();
  return sep;
}

function hoja_() {
  var sh = SpreadsheetApp.getActive().getSheetByName(CONFIG.HOJA);
  if (!sh) throw new Error('No existe la pestaña ' + CONFIG.HOJA + '. Ejecute "Instalar / reparar plantilla".');
  return sh;
}

function rango_(sh, col) { return sh.getRange(CONFIG.FILA_CAB + 1, col, CONFIG.FILAS, 1); }
function ref_(col) { return String.fromCharCode(64 + col) + (CONFIG.FILA_CAB + 1); }

function aplicarValidaciones_(sh) {
  var B = SpreadsheetApp.newDataValidation;
  var regla = function (col, regex, ayuda) {
    rango_(sh, col).setDataValidation(B()
      .requireFormulaSatisfied(F_('=OR(' + ref_(col) + '=""§REGEXMATCH(TO_TEXT(' + ref_(col) + ')§"' + regex + '"))'))
      .setAllowInvalid(false).setHelpText(ayuda).build());
  };
  regla(C.MOD,   '^[0-9]{6,10}$', 'Código modular: solo números.');
  regla(C.CARGO, '^[0-9]{1,10}$', 'Código de cargo: solo números.');
  regla(C.DNI,   '^[0-9]{8}$',    'DNI: exactamente 8 dígitos.');
  regla(C.PER,   '^20[0-9]{2}(0[1-9]|1[0-2])$', 'Periodo en formato AAAAMM, por ejemplo 202611.');
  rango_(sh, C.TIPO).setDataValidation(B().requireValueInList(CONFIG.TIPOS, true).setAllowInvalid(false).build());
  rango_(sh, C.COND).setDataValidation(B().requireValueInList(['A', 'C'], true).setAllowInvalid(false)
    .setHelpText('A = activo, C = cesante').build());
  rango_(sh, C.CUOTA).setDataValidation(B().requireNumberGreaterThan(0).setAllowInvalid(false)
    .setHelpText('Cuota mensual en soles, mayor a cero.').build());
}

function aplicarFormatoCondicional_(sh) {
  var f1 = CONFIG.FILA_CAB + 1;
  var filas = sh.getRange(f1, 1, CONFIG.FILAS, C.FEC);
  var compra = sh.getRange(f1, C.COMPRA, CONFIG.FILAS, 1);
  var R = SpreadsheetApp.newConditionalFormatRule;
  var cond = '$G' + f1, est = '$L' + f1;
  sh.setConditionalFormatRules([
    reglaDuplicado_(sh),
    R().whenFormulaSatisfied(F_('=AND(OR(' + cond + '="NUEVA COMPRA DE PRODUCTO"§' + cond + '="AMPLIACION COMPRA")§$H' + f1 + '="")'))
       .setBackground('#fde68a').setRanges([compra]).build(),
    R().whenFormulaSatisfied('=' + est + '="APROBADO"').setBackground('#e5f5ee').setFontColor('#0f6b4a').setRanges([filas]).build(),
    R().whenFormulaSatisfied('=' + est + '="DESAPROBADO"').setBackground('#fbeaea').setFontColor('#9b2a2f').setRanges([filas]).build(),
    R().whenFormulaSatisfied('=' + est + '="OBSERVADA"').setBackground('#fdf3e1').setRanges([filas]).build(),
    R().whenFormulaSatisfied('=' + est + '="DUPLICADA"').setBackground('#efe9fb').setFontColor('#5b3fa0').setRanges([filas]).build(),
    R().whenFormulaSatisfied('=' + est + '="EN EVALUACIÓN"').setFontColor('#1e4e86').setRanges([sh.getRange(f1, C.EST, CONFIG.FILAS, 1)]).build()
  ]);
}

/** DNI repetido en el mismo Sheet: resalta en rojo DNI, nombres y apellidos de todas las filas repetidas. */
function reglaDuplicado_(sh) {
  var f1 = CONFIG.FILA_CAB + 1, fN = CONFIG.FILA_CAB + CONFIG.FILAS;
  return SpreadsheetApp.newConditionalFormatRule()
    .whenFormulaSatisfied(F_('=AND($D' + f1 + '<>""§COUNTIF($D$' + f1 + ':$D$' + fN + '§$D' + f1 + ')>1)'))
    .setBackground('#f8d7da').setFontColor('#8f1d22').setBold(true)
    .setRanges([sh.getRange(f1, C.DNI, CONFIG.FILAS, 3)]).build();
}

function aplicarProtecciones_(sh) {
  sh.getProtections(SpreadsheetApp.ProtectionType.RANGE).forEach(function (p) {
    if (p.getDescription().indexOf('UGEL') === 0) p.remove();
  });
  var proteger = function (rango, desc) {
    var p = rango.protect().setDescription(desc);
    p.removeEditors(p.getEditors());
    if (p.canDomainEdit()) p.setDomainEdit(false);
    try { p.addEditor(Session.getEffectiveUser()); } catch (e) {}
    try { p.addEditor(CONFIG.CUENTA_SERVICIO); } catch (e) { /* se agrega cuando el Sheet ya está compartido con ella */ }
  };
  proteger(sh.getRange(1, 1, CONFIG.FILA_CAB, C.ID), 'UGEL - encabezado');
  proteger(rango_(sh, C.ITEM), 'UGEL - ítem automático');
  proteger(sh.getRange(CONFIG.FILA_CAB + 1, C.EST, CONFIG.FILAS, 5), 'UGEL - respuesta de evaluación');  // L..P (ID en Q queda libre para onEdit)
}
