/* Web en vivo (Informática)
 * - Cada 15 s consulta vivo.php (solo base de datos). Si algo cambió, refresca la pantalla sin F5.
 * - Una sola pestaña (la "líder") sincroniza los Sheets cada 2 min y da la alerta de voz.
 * - Voz: "Nueva solicitud ingresada". Se puede apagar con el botón 🔊 del menú.
 */
(function () {
  'use strict';
  var B = document.body, csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  if (!B || !B.dataset.sello) return;
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var esc = function (t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ls = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
             set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };

  var PAG = B.dataset.pag, SOL = +B.dataset.sol || 0;
  var V = { sello: B.dataset.sello, selloSol: B.dataset.selloSol || '', intervalo: 120, sucio: false, parado: false, sincronizando: false };
  var TAB = Math.random().toString(36).slice(2), CONSULTA = 15000, ESPERA_LIDER = 40000;

  /* ---------------- Pestaña líder (una por navegador) ---------------- */
  function lider() {
    var l = null; try { l = JSON.parse(ls.get('sol_lider') || 'null'); } catch (e) {}
    var ahora = Date.now(), visible = document.visibilityState === 'visible';
    var libre = !l || ahora - l.t > ESPERA_LIDER || l.id === TAB || (visible && !l.v);   // la pestaña visible tiene prioridad
    if (libre) ls.set('sol_lider', JSON.stringify({ id: TAB, t: ahora, v: visible }));
    try { return JSON.parse(ls.get('sol_lider')).id === TAB; } catch (e) { return true; }
  }
  window.addEventListener('pagehide', function () {
    try { var l = JSON.parse(ls.get('sol_lider') || 'null'); if (l && l.id === TAB) localStorage.removeItem('sol_lider'); } catch (e) {}
  });

  /* ---------------- Voz y sonido ---------------- */
  var voz = { on: ls.get('sol_voz') !== '0', ctx: null, cola: null };
  function audio() {
    if (!voz.ctx) { var A = window.AudioContext || window.webkitAudioContext; if (A) try { voz.ctx = new A(); } catch (e) {} }
    return voz.ctx;
  }
  function bloqueado() { var c = audio(); return c && c.state === 'suspended'; }
  function campana(tipo) {
    var c = audio(); if (!c || c.state !== 'running') return;
    var notas = tipo === 'otic' ? [660, 990] : [784, 1175, 1568], t = c.currentTime;
    notas.forEach(function (f, i) {
      var o = c.createOscillator(), g = c.createGain();
      o.type = 'sine'; o.frequency.value = f; o.connect(g); g.connect(c.destination);
      var t0 = t + i * 0.13; g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.22, t0 + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.32);
      o.start(t0); o.stop(t0 + 0.34);
    });
  }
  function vozEs() {
    var vs = window.speechSynthesis ? speechSynthesis.getVoices() : [];
    var pref = ['es-PE', 'es-419', 'es-US', 'es-MX', 'es-ES'];
    for (var i = 0; i < pref.length; i++) for (var j = 0; j < vs.length; j++) if (vs[j].lang.replace('_', '-') === pref[i]) return vs[j];
    for (j = 0; j < vs.length; j++) if (/^es/i.test(vs[j].lang)) return vs[j];
    return null;
  }
  if (window.speechSynthesis) speechSynthesis.onvoiceschanged = function () {};
  function hablar(txt, tipo) {
    if (!voz.on) return;
    if (bloqueado()) { voz.cola = { txt: txt, tipo: tipo }; pintarVoz(); return; }
    campana(tipo);
    if (!window.speechSynthesis) return;
    setTimeout(function () {
      var u = new SpeechSynthesisUtterance(txt), v = vozEs();
      u.lang = v ? v.lang : 'es-PE'; if (v) u.voice = v; u.rate = 0.97; u.pitch = 1; u.volume = 1;
      u.onerror = function (e) { if (e.error === 'not-allowed') { voz.cola = { txt: txt, tipo: tipo }; pintarVoz(true); } };
      speechSynthesis.cancel(); speechSynthesis.speak(u);
    }, 420);
  }
  function desbloquear() {                         // primer clic del usuario en la página
    var c = audio(); if (c && c.state === 'suspended') c.resume().then(function () { pintarVoz(); if (voz.cola) { var q = voz.cola; voz.cola = null; hablar(q.txt, q.tipo); } });
    else if (voz.cola) { var q = voz.cola; voz.cola = null; hablar(q.txt, q.tipo); }
  }
  ['pointerdown', 'keydown'].forEach(function (ev) { document.addEventListener(ev, desbloquear, true); });
  function pintarVoz(forzarAviso) {
    var b = $('#btnVoz'); if (!b) return;
    var bloq = voz.on && (bloqueado() || forzarAviso);
    b.classList.toggle('off', !voz.on); b.classList.toggle('bloq', !!bloq);
    b.innerHTML = voz.on ? (bloq ? '🔈 <span>Clic aquí para activar la voz</span>' : '🔊 <span>Alertas de voz activadas</span>') : '🔇 <span>Alertas de voz apagadas</span>';
    b.title = voz.on ? 'Clic para apagar las alertas de voz' : 'Clic para encender las alertas de voz';
  }
  var bv = $('#btnVoz');
  if (bv) bv.addEventListener('click', function () {
    if (voz.on && bloqueado()) { desbloquear(); setTimeout(function () { hablar('Alertas de voz activadas'); }, 150); return; }
    voz.on = !voz.on; ls.set('sol_voz', voz.on ? '1' : '0'); pintarVoz();
    if (voz.on) setTimeout(function () { hablar('Alertas de voz activadas'); }, 150);
    else if (window.speechSynthesis) speechSynthesis.cancel();
  });
  pintarVoz();

  /* ---------------- Avisos en pantalla ---------------- */
  function titulo(n) { var t = document.title.replace(/^\(\d+\)\s*/, ''); document.title = (n > 0 ? '(' + n + ') ' : '') + t; }
  var avisosSinVer = 0;
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { avisosSinVer = 0; titulo(0); } });
  function aviso(html, tipo, href) {
    var cont = $('#alertas'); if (!cont) return;
    var a = document.createElement('div'); a.className = 'alerta-v alerta-' + tipo;
    a.innerHTML = '<div class="av-ico">' + (tipo === 'otic' ? '✓' : '🔔') + '</div><div class="av-txt">' + html + '</div>' +
      (href ? '<a class="av-ver" href="' + href + '">Ver</a>' : '') + '<button type="button" class="av-x" aria-label="Cerrar">×</button><i class="av-bar"></i>';
    a.querySelector('.av-x').addEventListener('click', function () { cerrar(a); });
    cont.prepend(a); while (cont.children.length > 4) cont.lastChild.remove();
    setTimeout(function () { cerrar(a); }, 20000);
    if (document.visibilityState !== 'visible') titulo(++avisosSinVer);
  }
  function cerrar(a) { a.classList.add('fuera'); setTimeout(function () { a.remove(); }, 300); }
  function nombreEnt(n) { return String(n || '').toLowerCase().replace(/(^|\s)\S/g, function (x) { return x.toUpperCase(); }); }

  /* ---------------- Interfaz del menú ---------------- */
  function badge(sel, n, cls) {
    var a = $(sel); if (!a) return;
    var b = a.querySelector('.badge');
    if (n > 0) { if (!b) { b = document.createElement('b'); b.className = 'badge' + (cls ? ' ' + cls : ''); a.appendChild(b); }
                 if (b.textContent !== String(n)) { b.textContent = n; b.classList.remove('pop'); void b.offsetWidth; b.classList.add('pop'); } }
    else if (b) b.remove();
  }
  var proxima = 0;
  function reloj() {
    var t = $('#vivoTxt'); if (!t) return;
    if (V.parado) { t.textContent = 'Sesión vencida: recargue la página'; $('#vivo').classList.add('off'); return; }
    if (V.sincronizando) { t.textContent = 'Sincronizando Sheets…'; return; }
    var p = +(ls.get('sol_proxima') || 0), s = Math.max(0, Math.round((p - Date.now()) / 1000));
    t.textContent = p ? 'En vivo · próxima sincronización ' + Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2) : 'En vivo';
  }
  setInterval(reloj, 1000);

  /* ---------------- ¿Está el usuario ocupado? (no refrescar encima) ---------------- */
  var ultTecla = 0;
  document.addEventListener('input', function (e) { if (e.target.closest('.main form') && !e.target.closest('.filters')) V.sucio = true; }, true);
  document.addEventListener('keydown', function () { ultTecla = Date.now(); }, true);
  function editando(a) {                            // un campo con foco solo "ocupa" si el usuario lo está usando
    if (!a || a === B || !/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) || /^(button|submit|hidden|checkbox|radio)$/.test(a.type)) return false;
    if (Date.now() - ultTecla < 8000) return true;
    if (a.tagName === 'SELECT') return [].some.call(a.options, function (o) { return o.selected !== o.defaultSelected; });
    return a.value !== a.defaultValue;
  }
  function ocupado() {
    if (editando(document.activeElement)) return true;
    if (document.querySelector('details.menu[open], .panel-plegable:not([hidden]), .modal:not([hidden])')) return true;
    var s = window.getSelection && getSelection(); if (s && String(s).trim()) return true;
    return false;
  }

  /* ---------------- Refresco del contenido ---------------- */
  function refrescarMain() {
    return fetch(location.href, { credentials: 'same-origin', headers: { 'X-Vivo': '1' } }).then(function (r) {
      if (r.redirected && /login/i.test(r.url)) throw new Error('sesion');
      return r.text();
    }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html'), nuevo = doc.querySelector('.main'), main = $('.main');
      if (!nuevo || !main) return false;
      if (ocupado()) return false;
      var act = document.activeElement, foco = act && act !== B && (act.id ? '#' + act.id : act.name ? '[name="' + act.name + '"]' : null);
      var guardar = [$('#toast', main)].filter(Boolean);
      main.classList.add('vivo-swap');
      Array.prototype.slice.call(main.children).forEach(function (n) { if (guardar.indexOf(n) < 0 && !n.classList.contains('flash')) n.remove(); });
      Array.prototype.slice.call(nuevo.children).forEach(function (n) { if (n.id !== 'toast' && !n.classList.contains('flash')) main.appendChild(document.importNode(n, true)); });
      main.querySelectorAll('.rowlink').forEach(function (tr) { tr.classList.add('vivo-flash'); });
      if (foco) { var nf = main.querySelector(foco); if (nf) try { nf.focus({ preventScroll: true }); } catch (e) {} }
      var nb = doc.body && doc.body.dataset.sello; if (nb) V.sello = nb;
      var t = doc.querySelector('title'); if (t) document.title = (document.title.match(/^\(\d+\)\s*/) || [''])[0] + t.textContent;
      return true;
    });
  }
  var pendienteRefresco = false;
  function aplicarCambios(j) {
    var cambio = j.sello !== V.sello, cambioSol = SOL && j.sello_sol && j.sello_sol !== V.selloSol;
    if (!cambio && !cambioSol && !pendienteRefresco) return;
    if (PAG === 'bandeja') {
      if (ocupado()) { pendienteRefresco = true; return; }
      refrescarMain().then(function (ok) { if (ok) { pendienteRefresco = false; V.sello = j.sello; } else pendienteRefresco = true; })
        .catch(function (e) { if (e.message === 'sesion') parar(); });
    } else if (PAG === 'index' || (PAG === 'solicitud' && cambioSol)) {
      if (ocupado() || V.sucio) {
        pendienteRefresco = false; V.sello = j.sello; V.selloSol = j.sello_sol || V.selloSol;
        aviso('<b>Hay información nueva en esta pantalla.</b><br>No se recargó para no perder lo que está escribiendo.', 'info', location.pathname.split('/').pop() + location.search);
        return;
      }
      try { sessionStorage.setItem('sol_scroll', location.href + '|' + scrollY); } catch (e) {}
      location.reload();
    } else { V.sello = j.sello; pendienteRefresco = false; }
  }
  try {                                             // tras recarga automática, vuelve a la misma posición
    var sc = (sessionStorage.getItem('sol_scroll') || '').split('|');
    if (sc[0] === location.href) { sessionStorage.removeItem('sol_scroll'); window.scrollTo(0, +sc[1] || 0); }
  } catch (e) {}

  /* ---------------- Consulta periódica ---------------- */
  function parar() { V.parado = true; reloj(); }
  function anunciar(j) {
    var ult = +(ls.get('sol_ult_id') || 0);
    if (!ult) { ls.set('sol_ult_id', String(j.max_id)); return; }       // primer uso en este navegador: no anuncia lo antiguo
    if (j.max_id > ult) ls.set('sol_ult_id', String(j.max_id));
    var revisar = (j.nuevas || []).filter(function (s) { return s.id > ult && ['PENDIENTE', 'OBSERVADA', 'DUPLICADA'].indexOf(s.estado) >= 0; });
    if (revisar.length) {
      var ents = []; revisar.forEach(function (s) { if (ents.indexOf(s.entidad) < 0) ents.push(s.entidad); });
      hablar(revisar.length === 1 ? 'Nueva solicitud ingresada. ' + nombreEnt(revisar[0].entidad) + '.'
                                  : revisar.length + ' nuevas solicitudes ingresadas. ' + ents.slice(0, 3).map(nombreEnt).join(', ') + '.', 'nueva');
      revisar.slice(0, 3).forEach(function (s) {
        aviso('<b>NUEVA SOLICITUD INGRESADA</b><br>' + esc(s.entidad) + '<br><span>' + esc(s.apellidos) + ', ' + esc(s.nombres) + ' · DNI ' + esc(s.dni) + '</span>', 'nueva', 'solicitud.php?id=' + s.id);
      });
      if (revisar.length > 3) aviso('<b>+' + (revisar.length - 3) + ' solicitudes más</b>', 'nueva', 'bandeja.php?estado=PENDIENTE');
    }
    var vistos = (ls.get('sol_otic_av') || '').split(',').filter(Boolean), nuevosOtic = (j.otic || []).filter(function (o) { return vistos.indexOf(String(o.id)) < 0; });
    if (nuevosOtic.length) {
      if (!ls.get('sol_otic_ini')) ls.set('sol_otic_ini', '1');                 // primer uso: no anuncia lo antiguo
      else {
        campana('otic');
        nuevosOtic.slice(0, 3).forEach(function (o) {
          aviso('<b>OTIC RECEPCIONADO</b><br>' + esc(o.entidad) + '<br><span>' + esc(o.apellidos) + ', ' + esc(o.nombres) + ' · DNI ' + esc(o.dni) + '</span>', 'otic', 'solicitud.php?id=' + o.id);
        });
      }
      ls.set('sol_otic_av', vistos.concat(nuevosOtic.map(function (o) { return String(o.id); })).slice(-300).join(','));
    } else if (!ls.get('sol_otic_ini')) ls.set('sol_otic_ini', '1');
  }
  function consultar() {
    if (V.parado) return Promise.resolve();
    var q = 'vivo.php?desde=' + (+(ls.get('sol_ult_id') || 0)) + (SOL ? '&sol=' + SOL : '');
    return fetch(q, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
      if (r.redirected || !(r.headers.get('content-type') || '').match(/json/)) throw new Error('sesion');
      return r.json();
    }).then(function (j) {
      V.intervalo = j.intervalo || 120;
      badge('.nav a[href="bandeja.php"]', j.pendientes);
      badge('.nav a[href="bandeja.php?otic=nuevos"]', j.otic_nuevos, 'badge-otic');
      var si = $('#syncInfo'); if (si && !V.sincronizando) si.textContent = 'Última: ' + j.hace_txt;
      var soy = lider();
      if (soy) {
        anunciar(j);
        var falta = j.hace == null ? 0 : V.intervalo - j.hace;
        ls.set('sol_proxima', String(Date.now() + Math.max(0, falta) * 1000));
        if (falta <= 0 && !V.sincronizando) sincronizarAuto();
      }
      aplicarCambios(j);
    }).catch(function (e) { if (e.message === 'sesion') parar(); });
  }
  function sincronizarAuto() {
    V.sincronizando = true; reloj(); $('#btnSync') && $('#btnSync').classList.add('girando');
    return fetch('vivo.php', { method: 'POST', headers: { 'X-CSRF': csrf }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.errores && j.errores.length) window.console && console.warn('Sincronización automática:', j.errores);
      })
      .catch(function () {})
      .then(function () { V.sincronizando = false; $('#btnSync') && $('#btnSync').classList.remove('girando'); return consultar(); });
  }
  window.solRevisar = function () { return consultar(); };

  setTimeout(consultar, 1200);
  setInterval(consultar, CONSULTA);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') consultar(); });
})();
