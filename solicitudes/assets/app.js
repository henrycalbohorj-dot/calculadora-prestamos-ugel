(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  function toast(msg, err) {
    var t = $('#toast'); if (!t) return;
    t.innerHTML = msg; t.className = 'toast' + (err ? ' err' : ''); t.hidden = false;
    clearTimeout(t._h); t._h = setTimeout(function () { t.hidden = true; }, err ? 9000 : 5000);
  }

  // Filas clicables (delegado: sigue funcionando cuando la bandeja se refresca sola)
  document.addEventListener('click', function (e) {
    var tr = e.target.closest && e.target.closest('.rowlink');
    if (!tr || e.target.closest('a,button,input,select,label')) return;
    location.href = tr.dataset.href;
  });

  // Solicitud recién evaluada: se muestra en la bandeja
  var hecha = document.querySelector('.rowlink.hecho'); if (hecha) setTimeout(function () { hecha.scrollIntoView({ block: 'center', behavior: 'smooth' }); }, 250);

  // Copiar
  $$('[data-copiar]').forEach(function (b) {
    b.addEventListener('click', function () {
      var txt = document.getElementById(b.dataset.copiar).textContent;
      (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () {
        b.textContent = 'Copiado';
      }, function () {
        var r = document.createRange(); r.selectNode(document.getElementById(b.dataset.copiar));
        getSelection().removeAllRanges(); getSelection().addRange(r);
      });
    });
  });

  // Sincronizar
  var bs = $('#btnSync');
  if (bs) bs.addEventListener('click', function () {
    if (bs.disabled) return;
    bs.disabled = true; bs.classList.add('girando'); bs.querySelector('span').textContent = 'Sincronizando…';
    fetch('sincronizar.php', { method: 'POST', headers: { 'X-CSRF': csrf }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.error) throw new Error(j.error);
        var t = j.totales;
        var msg = '<b>' + j.entidades + ' Sheet(s) sincronizados.</b><br>' + t.leidas + ' filas · ' + t.nuevas + ' nuevas · ' +
                  t.actualizadas + ' con cambios · ' + t.retiradas + ' retiradas · ' + t.escritas + ' respuestas escritas' +
                  (t.duplicados ? '<br>⚠ <b>' + t.duplicados + ' posible(s) cliente(s) duplicado(s)</b> — revisa la Bandeja' : '') +
                  (t.automaticas ? '<br>⛔ <b>' + t.automaticas + ' desaprobada(s) automáticamente</b> por exceder el monto disponible' : '');
        if (j.errores.length) msg += '<br><br>⚠ ' + j.errores.join('<br>⚠ ');
        toast(msg, j.errores.length > 0);
        $('#syncInfo').textContent = 'Última: hace un momento';
        if (t.nuevas || t.actualizadas || t.retiradas || t.duplicados) {
          if (window.solRevisar && document.body.dataset.pag !== 'entidades') window.solRevisar();   // refresca sin recargar
          else setTimeout(function () { location.reload(); }, 1600);
        }
      })
      .catch(function (e) { toast('No se pudo sincronizar: ' + e.message, true); })
      .finally(function () { bs.disabled = false; bs.classList.remove('girando'); bs.querySelector('span').textContent = 'Sincronizar Sheets'; });
  });

  // Cerrar menús ⋯ al hacer clic fuera
  document.addEventListener('click', function (e) {
    $$('details.menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });

  /* ---------------- Paneles plegables (Registrar / Importar) ---------------- */
  $$('[data-panel]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = document.getElementById(b.dataset.panel); if (!p) return;
      $$('.panel-plegable').forEach(function (x) { if (x !== p) x.hidden = true; });
      p.hidden = !p.hidden;
      if (!p.hidden) { p.scrollIntoView({ behavior: 'smooth', block: 'start' }); var i = p.querySelector('input:not([type=hidden]),textarea'); if (i) i.focus(); }
    });
  });

  /* ---------------- Tabla de entidades: grupo, búsqueda, orden y páginas ---------------- */
  var tbl = $('#tblEnt');
  if (tbl) {
    var filas = $$('tbody tr[data-nombre]', tbl), estado = { grupo: '', q: '', pag: 1, por: 15, sort: null, dir: 1 };
    try { estado.por = parseInt(localStorage.getItem('entMostrar') || '15', 10); } catch (e) {}
    $('#entMostrar').value = String(estado.por);
    var norm = function (t) { return (t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
    function pintar() {
      var visibles = filas.filter(function (r) {
        var okG = !estado.grupo || (estado.grupo === '__error' ? r.dataset.error === '1' : r.dataset.grupo === estado.grupo);
        var t = norm(r.dataset.buscar);
        return okG && estado.q.split(/\s+/).every(function (w) { return !w || t.indexOf(w) >= 0; });
      });
      if (estado.sort) {
        visibles.sort(function (a, b) {
          var x = a.dataset[estado.sort], y = b.dataset[estado.sort];
          var nx = parseFloat(x), ny = parseFloat(y);
          var c = (!isNaN(nx) && !isNaN(ny) && estado.sort !== 'nombre' && estado.sort !== 'grupo') ? nx - ny : x.localeCompare(y, 'es');
          return c * estado.dir;
        });
        var tb = tbl.tBodies[0]; visibles.forEach(function (r) { tb.appendChild(r); });
      }
      var total = visibles.length, por = estado.por || total || 1, paginas = Math.max(1, Math.ceil(total / por));
      if (estado.pag > paginas) estado.pag = paginas;
      filas.forEach(function (r) { r.hidden = true; });
      visibles.slice((estado.pag - 1) * por, estado.pag * por).forEach(function (r) { r.hidden = false; });
      var pg = $('#entPager'), h = '<span class="muted xs">' + total + ' entidad(es)</span>';
      if (paginas > 1) {
        h += '<span class="pg">';
        for (var i = 1; i <= paginas; i++) h += '<button type="button" class="btn btn-sm ' + (i === estado.pag ? 'btn-pri' : 'btn-ghost') + '" data-pag="' + i + '">' + i + '</button>';
        h += '</span><button type="button" class="btn btn-sm" data-todas>Ver todas</button>';
      }
      pg.innerHTML = h;
    }
    $('#entTabs').addEventListener('click', function (e) {
      var a = e.target.closest('a'); if (!a) return; e.preventDefault();
      $$('a', this).forEach(function (x) { x.classList.toggle('on', x === a); });
      estado.grupo = a.dataset.grupo; estado.pag = 1; pintar();
    });
    $('#entBuscar').addEventListener('input', function () { estado.q = norm(this.value.trim()); estado.pag = 1; pintar(); });
    $('#entMostrar').addEventListener('change', function () {
      estado.por = parseInt(this.value, 10); estado.pag = 1;
      try { localStorage.setItem('entMostrar', this.value); } catch (e) {}
      pintar();
    });
    $('#entPager').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      if (b.hasAttribute('data-todas')) { estado.por = 0; $('#entMostrar').value = '0'; } else estado.pag = parseInt(b.dataset.pag, 10);
      pintar();
    });
    $$('th.sortable', tbl).forEach(function (th) {
      th.addEventListener('click', function () {
        estado.dir = estado.sort === th.dataset.sort ? -estado.dir : 1; estado.sort = th.dataset.sort;
        $$('th.sortable', tbl).forEach(function (x) { x.classList.remove('asc', 'desc'); });
        th.classList.add(estado.dir === 1 ? 'asc' : 'desc'); pintar();
      });
    });
    pintar();
  }

  /* ---------------- Evaluación en vivo (misma fórmula que lib/Evaluador.php) ---------------- */
  var f = $('#fEval');
  if (!f) return;
  var pct = parseFloat(f.dataset.pct), cuota = parseFloat(f.dataset.cuota), aprobSis = parseFloat(f.dataset.aprob);
  var fmt = function (n) { return 'S/ ' + (Math.round(n * 100) / 100).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var val = function (el) { var v = parseFloat(String(el && el.value || '').replace(',', '')); return isNaN(v) || v < 0 ? 0 : v; };
  var norm = function (t) { return String(t || '').trim().toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
  var activo = function (el) { return el && !el.matches(':disabled'); };

  /* --- 1. Tipo de trabajador --- */
  function modo() { var r = f.querySelector('input[name=modo]:checked'); return r ? r.value : ''; }
  function aplicarModo(animar) {
    var m = modo();
    $$('.modo-op', f).forEach(function (o) { o.classList.toggle('on', o.dataset.m === m); });
    $$('.modo-panel', f).forEach(function (p) {
      var si = p.dataset.panel === m;
      p.disabled = !si; p.hidden = !si;
      if (si && animar) { p.classList.remove('entra'); void p.offsetWidth; p.classList.add('entra'); }
    });
    $('#modoVacio').hidden = !!m;
    var tit = $('#evalTitulo');
    if (tit) tit.textContent = m === 'DOCENTE' ? tit.dataset.doc : (m === 'ADMINISTRATIVO' ? tit.dataset.admin : 'Evaluación');
    $('#decN').textContent = m === 'ADMINISTRATIVO' ? '6' : '3';
    // v1.17: el OTIC solo se envía a docentes y cesantes
    $$('[data-solo]').forEach(function (c) { c.hidden = !!m && c.dataset.solo !== m; });
    ['#bAprobar', '#bDesaprobar'].forEach(function (x) { var b = $(x); if (b && !b.hasAttribute('data-bloq')) b.classList.toggle('apagado', !m); });
    calcular();
  }
  $$('input[name=modo]', f).forEach(function (r) { r.addEventListener('change', function () { aplicarModo(true); }); });
  $$('[data-usar-disp]', f).forEach(function (b) { b.addEventListener('click', function () { $('#dispDoc').value = b.dataset.usarDisp; $('#dispDoc').dispatchEvent(new Event('input')); calcular(); }); });

  /* --- 3. Descuentos de ley: botones activables, AFP y ONP se excluyen --- */
  function exclusivos() {
    var tiles = $$('.ley-tile', f), usados = {};
    tiles.forEach(function (t) { if (t.classList.contains('on') && t.dataset.excl) usados[t.dataset.excl] = t; });
    tiles.forEach(function (t) {
      var g = t.dataset.excl, otro = g && usados[g] && usados[g] !== t;
      t.classList.toggle('bloq', !!otro);
      t.querySelector('.lt-btn').disabled = !!otro;
      t.title = otro ? 'No aplica: ya registró ' + usados[g].dataset.concepto + ' (solo un sistema de pensiones)' : '';
    });
  }
  function toggleTile(t, si) {
    t.classList.toggle('on', si);
    $$('input', t).forEach(function (i) { i.disabled = !si; });
    if (si) { var m = t.querySelector('input[type=number]'); setTimeout(function () { m.focus(); m.select(); }, 30); }
    exclusivos(); calcular();
  }
  $$('.ley-tile .lt-btn', f).forEach(function (b) {
    b.addEventListener('click', function () { var t = b.closest('.ley-tile'); toggleTile(t, !t.classList.contains('on')); });
  });

  /* --- 4. Créditos vigentes: una entidad no se agrega dos veces --- */
  function chipsUsados() {
    var usados = {};
    $$('.terc-row-t input[type=text]', f).forEach(function (i) { if (i.value.trim()) usados[norm(i.value)] = true; });
    $$('#chipsTerc .chip-cat', f).forEach(function (c) {
      var u = !!usados[norm(c.dataset.nombre)];
      c.disabled = u; c.classList.toggle('usado', u);
      c.textContent = (u ? '✓ ' : '+ ') + c.dataset.nombre;
    });
  }

  function calcular() {
    // Misma fórmula que la calculadora (api/guardar_evaluacion.php) y lib/Evaluador.php
    var bruto = val(f.remuneracion), ley = 0, vig = 0, lib = 0;
    $$('#leyBox .ley-m, #leyLista .ley-m', f).forEach(function (i) { if (activo(i)) ley += val(i); });
    $$('.terc-row-t', $('#tercLista')).forEach(function (row) {
      var m = val(row.querySelector('input[type=number]'));
      if (row.querySelector('input[type=checkbox]').checked) lib += m; else vig += m;
    });
    var aprob = f.considerar_aprobadas && f.considerar_aprobadas.checked ? aprobSis : 0;
    var neto = Math.max(bruto - ley, 0), tope = neto * pct / 100, comp = vig + aprob, total = comp + cuota;
    var disp = tope - total, margen = disp;
    var ok = bruto > 0 && cuota > 0 && total <= tope + 0.001;
    var m = modo();

    $('#sumLey').textContent = fmt(ley);
    $('#cRem').textContent = fmt(bruto); $('#cLey').textContent = fmt(ley); $('#cBase').textContent = fmt(neto);
    $('#cTope').textContent = fmt(tope); $('#cTerc').textContent = fmt(vig); $('#cAprob').textContent = fmt(aprob);
    $('#cLib').textContent = fmt(lib); $('#cDisp').textContent = fmt(disp);

    var box = $('#resultado'), ba = $('#bAprobar'), bd = $('#bDesaprobar'), cyl = $('#cyl');
    if (m === 'DOCENTE') {
      f.dataset.calc = '';
      var dd = $('#dispDoc');
      if (ba && bd) { ba.classList.toggle('rec', dd.value !== ''); bd.classList.remove('rec'); }
      return;
    }
    if (m !== 'ADMINISTRATIVO' || bruto <= 0) {
      box.className = 'result'; $('#rBadge').textContent = 'Ingresa la boleta'; $('#rMargen').textContent = '';
      f.dataset.calc = ''; if (ba && bd) { ba.classList.remove('rec'); bd.classList.remove('rec'); }
      ['#gComp', '#gCuota', '#gOver'].forEach(function (x) { $(x).style.width = '0'; }); $('#rUso').textContent = '';
      cyl.style.setProperty('--h', '0%'); cyl.className = 'cyl'; $('#cylPct').textContent = '0%';
      return;
    }
    box.className = 'result ' + (ok ? 'r-ok' : 'r-no');
    f.dataset.calc = ok ? 'APROBADO' : 'DESAPROBADO';
    if (ba && bd) { ba.classList.toggle('rec', ok); bd.classList.toggle('rec', !ok); }
    $('#rBadge').textContent = ok ? 'APROBADO' : 'DESAPROBADO';
    $('#rMargen').textContent = ok ? 'Le queda ' + fmt(margen) : 'Excede en ' + fmt(-margen);
    $('#rMargen').className = 'res-margen ' + (ok ? 't-ok' : 't-no');

    var escala = Math.max(tope, total, 0.01);
    var wComp = Math.min(comp, tope) / escala * 100;
    var wCuota = Math.max(0, Math.min(cuota, tope - Math.min(comp, tope))) / escala * 100;
    var wOver = Math.max(0, total - tope) / escala * 100;
    $('#gComp').style.width = wComp + '%'; $('#gCuota').style.width = wCuota + '%'; $('#gOver').style.width = wOver + '%';
    var uso = tope > 0 ? Math.round(total / tope * 100) : 0;
    $('#rUso').textContent = tope > 0 ? 'Uso del ' + pct + '%: ' + uso + '%' : '';
    cyl.style.setProperty('--h', Math.min(uso, 100) + '%');
    cyl.className = 'cyl ' + (ok ? (uso > 85 ? 'cyl-warn' : 'cyl-ok') : 'cyl-no');
    $('#cylPct').textContent = uso + '%';
  }

  // Desaprobar y observar exigen motivo; aprobar no. Aprobar en contra del cálculo pide confirmar (2.º clic).
  var confirmarAprob = false;
  f.addEventListener('submit', function (e) {
    var b = e.submitter, av = $('#decAviso'), motivo = f.comentario.value.trim(), m = modo();
    if (!b) return;
    var mostrar = function (html) { e.preventDefault(); av.innerHTML = html; av.hidden = false; };
    if ((b.value === 'aprobar' || b.value === 'desaprobar') && !m) {
      mostrar('Primero elige el <b>tipo de trabajador</b>: Docente y/o cesante o Administrativo.'); return;
    }
    if ((b.value === 'desaprobar' || b.value === 'observar') && !motivo) {
      mostrar('Escribe el <b>motivo</b> para ' + (b.value === 'desaprobar' ? 'desaprobar' : 'observar') + ': la entidad lo verá en su Sheet.');
      f.comentario.focus(); return;
    }
    if (b.value === 'aprobar' && m === 'DOCENTE' && $('#dispDoc').value === '') {
      mostrar('Para aprobar indica el <b>monto disponible</b> del trabajador.'); $('#dispDoc').focus(); return;
    }
    if (b.value === 'aprobar' && m === 'ADMINISTRATIVO' && f.dataset.calc === 'DESAPROBADO' && !confirmarAprob) {
      mostrar('El cálculo indica que la cuota <b>excede el 50%</b>. Si igual deseas aprobar, pulsa <b>Aprobado</b> otra vez.');
      confirmarAprob = true; return;
    }
  });
  f.addEventListener('input', function () { confirmarAprob = false; $('#decAviso').hidden = true; });

  f.addEventListener('input', function (e) { if (e.target.closest('.terc-row-t')) chipsUsados(); calcular(); });
  f.addEventListener('change', calcular);

  var idx = 1000;
  function agregar(tipo, nombre) {
    var lista = tipo === 'ley' ? $('#leyLista') : $('#tercLista');
    var tpl = tipo === 'ley' ? $('#tplLey') : $('#tplTerc');
    var vacia = $$('.terc-row', lista).filter(function (r) { return !r.querySelector('input[type=text]').value && !r.querySelector('input[type=number]').value; })[0];
    if (!vacia) {
      lista.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, String(idx++)));
      vacia = lista.lastElementChild;
      vacia.classList.add('entra');
    }
    if (nombre) { vacia.querySelector('input[type=text]').value = nombre; vacia.querySelector('input[type=number]').focus(); }
    else vacia.querySelector('input[type=text]').focus();
    chipsUsados(); calcular();
  }
  $$('[data-agregar]').forEach(function (b) { b.addEventListener('click', function () { agregar(b.dataset.agregar); }); });
  $$('[data-add]').forEach(function (b) { b.addEventListener('click', function () { if (!b.disabled) agregar(b.dataset.add, b.dataset.nombre); }); });
  f.addEventListener('click', function (e) {
    if (!e.target.matches('[data-quitar]')) return;
    var row = e.target.closest('.terc-row'), lista = row.parentNode;
    if (lista.id === 'leyLista' || $$('.terc-row', lista).length > 1) row.remove();
    else $$('input', row).forEach(function (i) { i.type === 'checkbox' ? (i.checked = false) : (i.value = ''); });
    chipsUsados(); calcular();
  });
  exclusivos(); chipsUsados(); aplicarModo(false);
})();

/* v1.9 · Mensajes rápidos para la entidad y "le queda disponible" del modo docente */
(function () {
  var txt = document.getElementById('txtComentario');
  document.querySelectorAll('#msgChips [data-msg]').forEach(function (b) {
    b.addEventListener('click', function () {
      var m = b.getAttribute('data-msg');
      if (!txt) return;
      txt.value = txt.value.trim() ? (txt.value.trim().indexOf(m) >= 0 ? txt.value : txt.value.trim() + ' ' + m) : m;
      txt.focus();
    });
  });
  // Evita dobles clics: una vez enviada la decisión, los botones se bloquean hasta que la página responde
  var fe = document.getElementById('fEval');
  if (fe) fe.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    if (fe.dataset.enviando) { e.preventDefault(); return; }
    fe.dataset.enviando = '1';
    var b = e.submitter;
    if (b && b.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = b.name; h.value = b.value; fe.appendChild(h); }
    setTimeout(function () {
      fe.querySelectorAll('button[name=accion]').forEach(function (x) { x.disabled = true; });
      if (b) { b.classList.add('enviando'); var t = b.querySelector('b'); if (t) t.textContent = 'Guardando…'; }
    }, 0);
  });

  var inp = document.getElementById('dispDoc'), out = document.getElementById('dispQueda');
  if (inp && out) {
    var cuota = parseFloat(out.getAttribute('data-cuota')) || 0;
    var fmt = function (n) { return 'S/ ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var pintar = function () {
      var v = parseFloat(inp.value);
      if (isNaN(v)) { out.textContent = ''; out.classList.remove('no'); return; }
      var queda = v - cuota;
      out.classList.toggle('no', queda < -0.001);
      out.textContent = queda < -0.001 ? '⛔ La cuota (' + fmt(cuota) + ') supera este monto: no se puede aprobar.'
                                       : 'Si apruebas, le quedará disponible ' + fmt(queda) + ' para otras entidades.';
    };
    inp.addEventListener('input', pintar); pintar();
  }
})();
