/* Panel analítico — UGEL Chincha · Solicitudes de crédito
 * ECharts 5 + ECharts GL (incluidos en assets/vendor, funcionan sin internet). */
(function () {
  'use strict';
  var el = document.getElementById('dashData');
  if (!el) return;
  var D = JSON.parse(el.textContent);
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var FONT = '"Plus Jakarta Sans", system-ui, "Segoe UI", sans-serif';
  var C = { ink: '#0d1b2a', muted: '#6b7a90', line: '#e3e8ef', teal: '#14b8a6', blue: '#3b82f6', green: '#10b981', red: '#ef4444', amber: '#f59e0b', violet: '#8b5cf6', navy: '#0a3a5a' };
  var soles = function (n) { return 'S/ ' + Number(n || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var charts = [];

  function grad(a, b, horizontal) {
    return new echarts.graphic.LinearGradient(0, 0, horizontal ? 1 : 0, horizontal ? 0 : 1, [{ offset: 0, color: a }, { offset: 1, color: b }]);
  }
  function vacio(id, txt) {
    var n = document.getElementById(id);
    if (n) n.innerHTML = '<div class="chart-empty"><span>◌</span>' + (txt || 'Sin datos todavía') + '</div>';
  }
  function iniciar(id, opt) {
    var n = document.getElementById(id);
    if (!n || typeof echarts === 'undefined') return null;
    var ch = echarts.init(n, null, { renderer: 'canvas' });
    opt.textStyle = Object.assign({ fontFamily: FONT, color: C.ink }, opt.textStyle || {});
    opt.animationDuration = reduceMotion ? 0 : 1200;
    opt.animationEasing = 'cubicOut';
    ch.setOption(opt);
    charts.push(ch);
    return ch;
  }
  var tip = { backgroundColor: 'rgba(13,27,42,.92)', borderWidth: 0, padding: [10, 14], textStyle: { color: '#fff', fontFamily: FONT, fontSize: 12.5 }, extraCssText: 'border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.25)' };

  if (typeof echarts === 'undefined') {
    document.querySelectorAll('.chart').forEach(function (n) { n.innerHTML = '<div class="chart-empty">No se pudieron cargar los gráficos (assets/vendor).</div>'; });
    return;
  }

  /* ---------------- 1. Barras 3D: entidad × periodo ---------------- */
  (function () {
    if (!D.c3d.length) return vacio('ch3d', 'Las barras 3D aparecen cuando haya solicitudes con periodo');
    var webgl = (function () { try { var c = document.createElement('canvas'); return !!(c.getContext('webgl') || c.getContext('experimental-webgl')); } catch (e) { return false; } })();
    var max = Math.max.apply(null, D.c3d.map(function (c) { return c[2]; }).concat([1]));
    if (webgl) {
      try {
        iniciar('ch3d', {
          tooltip: Object.assign({}, tip, { formatter: function (p) {
            var v = p.value; return '<b>' + D.e3d[v[1]] + '</b><br>' + D.p3d[v[0]] + '<br>Solicitudes: <b>' + v[2] + '</b><br>Aprobadas: <b>' + v[3] + '</b>';
          } }),
          visualMap: { show: true, min: 0, max: max, dimension: 2, orient: 'horizontal', right: 10, top: 0, itemWidth: 10, itemHeight: 120, text: ['más', 'menos'], textStyle: { color: C.muted, fontSize: 11 },
            inRange: { color: ['#c7f2ec', '#5eead4', '#14b8a6', '#0e7490', '#1e3a8a', '#6d28d9'] } },
          xAxis3D: { type: 'category', data: D.p3d, name: '', axisLabel: { color: C.muted, fontSize: 11 } },
          yAxis3D: { type: 'category', data: D.e3d, name: '', axisLabel: { color: C.ink, fontSize: 10, interval: 0, formatter: function (v) { return v.length > 18 ? v.slice(0, 17) + '…' : v; } } },
          zAxis3D: { type: 'value', name: 'Solicitudes', nameTextStyle: { color: C.muted }, axisLabel: { color: C.muted, fontSize: 10 }, minInterval: 1 },
          grid3D: {
            boxWidth: 120, boxDepth: 150, boxHeight: 70,
            axisLine: { lineStyle: { color: '#94a3b8' } }, axisPointer: { lineStyle: { color: C.teal } },
            splitLine: { lineStyle: { color: 'rgba(148,163,184,.25)' } }, environment: 'none',
            light: { main: { intensity: 1.25, shadow: true, shadowQuality: 'high', alpha: 35, beta: 30 }, ambient: { intensity: 0.35 } },
            viewControl: { autoRotate: !reduceMotion, autoRotateSpeed: 5, autoRotateAfterStill: 4, distance: 230, alpha: 24, beta: 38, minDistance: 140, maxDistance: 400 }
          },
          series: [{
            type: 'bar3D', data: D.c3d.map(function (c) { return { value: c }; }), shading: 'lambert', bevelSize: 0.35, bevelSmoothness: 4,
            barSize: 9, label: { show: false }, itemStyle: { opacity: 0.96 },
            emphasis: { label: { show: true, formatter: function (p) { return p.value[2]; }, textStyle: { fontSize: 14, color: C.ink, fontWeight: 'bold', backgroundColor: '#fff', padding: 4, borderRadius: 4 } }, itemStyle: { color: '#f59e0b' } },
            animationDurationUpdate: 1000
          }]
        });
        return;
      } catch (e) { /* sin WebGL: continúa con el mapa de calor */ }
    }
    iniciar('ch3d', {
      tooltip: tip, grid: { left: 190, right: 30, top: 20, bottom: 40 },
      xAxis: { type: 'category', data: D.p3d }, yAxis: { type: 'category', data: D.e3d },
      visualMap: { min: 0, max: max, calculable: true, orient: 'horizontal', left: 'center', bottom: 0, inRange: { color: ['#e0f7f4', '#14b8a6', '#1e3a8a'] } },
      series: [{ type: 'heatmap', data: D.c3d.map(function (c) { return [c[0], c[1], c[2]]; }), label: { show: true } }]
    });
  })();

  /* ---------------- 2. Medidor de aprobación ---------------- */
  iniciar('chGauge', {
    series: [{
      type: 'gauge', startAngle: 210, endAngle: -30, min: 0, max: 100, radius: '96%', center: ['50%', '60%'],
      progress: { show: true, width: 16, roundCap: true, itemStyle: { color: grad('#14b8a6', '#0e7490', true), shadowBlur: 14, shadowColor: 'rgba(20,184,166,.45)' } },
      axisLine: { roundCap: true, lineStyle: { width: 16, color: [[1, '#eef2f6']] } },
      pointer: { show: false }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false },
      anchor: { show: false }, title: { offsetCenter: [0, '32%'], color: C.muted, fontSize: 12 },
      detail: { valueAnimation: true, offsetCenter: [0, '-4%'], fontSize: 38, fontWeight: 800, color: C.ink, formatter: '{value}%' },
      data: [{ value: D.tasa, name: 'aprobadas / evaluadas' }]
    }]
  });

  /* ---------------- 3. Embudo ---------------- */
  if (D.embudo[0].value > 0) {
    iniciar('chFunnel', {
      tooltip: Object.assign({}, tip, { formatter: function (p) { var d = D.embudo[p.dataIndex]; return p.name + ': <b>' + p.value + '</b>' + (d && d.det ? '<br><span style="opacity:.75">' + d.det + '</span>' : ''); } }),
      series: [{
        type: 'funnel', left: '6%', right: '6%', top: 6, bottom: 6, minSize: '38%', sort: 'none', gap: 4,
        label: { show: true, position: 'inside', formatter: '{b}  {c}', color: '#fff', fontWeight: 700, fontSize: 12 },
        itemStyle: { borderWidth: 0, shadowBlur: 12, shadowColor: 'rgba(13,27,42,.18)', shadowOffsetY: 4 },
        data: D.embudo.map(function (d, i) { return { name: d.name, value: d.value, itemStyle: { color: grad(['#0e7490', '#14b8a6', '#3b82f6', '#10b981', '#ef4444', '#94a3b8'][i] || '#94a3b8', ['#155e75', '#0f766e', '#1d4ed8', '#047857', '#b91c1c', '#475569'][i] || '#475569', true) } }; })
      }]
    });
  } else vacio('chFunnel');

  /* ---------------- 4. Tendencia 30 días ---------------- */
  iniciar('chTrend', {
    tooltip: Object.assign({}, tip, { trigger: 'axis', axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(20,184,166,.08)' } } }),
    legend: { top: 0, right: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 8, textStyle: { color: C.muted } },
    grid: { left: 36, right: 12, top: 36, bottom: 30 },
    xAxis: { type: 'category', data: D.dias, axisLine: { lineStyle: { color: C.line } }, axisTick: { show: false }, axisLabel: { color: C.muted, fontSize: 10.5, interval: 4 } },
    yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: '#eef2f6' } }, axisLabel: { color: C.muted } },
    dataZoom: [{ type: 'inside' }],
    series: [
      { name: 'Recibidas', type: 'line', smooth: true, symbol: 'circle', symbolSize: 6, showSymbol: false, data: D.recibidas,
        lineStyle: { width: 3, color: C.teal, shadowBlur: 10, shadowColor: 'rgba(20,184,166,.4)' }, itemStyle: { color: C.teal },
        areaStyle: { color: grad('rgba(20,184,166,.35)', 'rgba(20,184,166,0)') } },
      { name: 'Aprobadas', type: 'bar', stack: 'd', barWidth: 8, data: D.aprobadas, itemStyle: { color: grad('#34d399', '#059669'), borderRadius: [4, 4, 0, 0] } },
      { name: 'Desaprobadas', type: 'bar', stack: 'd', barWidth: 8, data: D.desaprob, itemStyle: { color: grad('#f87171', '#dc2626'), borderRadius: [4, 4, 0, 0] } },
      { name: 'Anuladas', type: 'bar', stack: 'd', barWidth: 8, data: D.anuladas || [], itemStyle: { color: grad('#94a3b8', '#475569'), borderRadius: [4, 4, 0, 0] } }
    ]
  });

  /* ---------------- 5. Dona de estados (con profundidad) ---------------- */
  (function () {
    var total = D.estados.reduce(function (a, b) { return a + b.value; }, 0);
    if (!total) return vacio('chDonut');
    var datos = D.estados.filter(function (e) { return e.value > 0; });
    iniciar('chDonut', {
      tooltip: Object.assign({}, tip, { formatter: '{b}: <b>{c}</b> ({d}%)' }),
      legend: { bottom: 0, icon: 'circle', itemWidth: 9, textStyle: { color: C.muted, fontSize: 11 } },
      graphic: [{ type: 'text', left: 'center', top: '36%', style: { text: String(total), fontSize: 34, fontWeight: 800, fill: C.ink, fontFamily: FONT, textAlign: 'center' } },
                { type: 'text', left: 'center', top: '52%', style: { text: 'solicitudes', fontSize: 12, fill: C.muted, fontFamily: FONT, textAlign: 'center' } }],
      series: [
        { type: 'pie', radius: ['50%', '76%'], center: ['50%', '46%'], silent: true, z: 1, label: { show: false },
          itemStyle: { color: 'rgba(13,27,42,.06)' }, data: [{ value: 1 }], emphasis: { disabled: true } },
        { type: 'pie', radius: ['46%', '72%'], center: ['50%', '44%'], z: 2, avoidLabelOverlap: true, padAngle: 2,
          itemStyle: { borderRadius: 8, shadowBlur: 18, shadowOffsetY: 6, shadowColor: 'rgba(13,27,42,.22)' },
          label: { show: false }, emphasis: { scale: true, scaleSize: 8 },
          data: datos.map(function (e) { return { name: e.name, value: e.value, itemStyle: { color: grad(echarts.color.lift(e.c, 0.18), e.c) } }; }) }
      ]
    });
  })();

  /* ---------------- 6. Rosa por grupo ---------------- */
  if (D.grupos.length) {
    iniciar('chGrupo', {
      tooltip: Object.assign({}, tip, { formatter: function (p) { var g = D.grupos[p.dataIndex]; return '<b>' + g.name + '</b><br>Solicitudes: <b>' + g.n + '</b><br>Cuota aprobada: <b>' + soles(g.monto) + '</b>'; } }),
      legend: { bottom: 0, icon: 'circle', itemWidth: 9, textStyle: { color: C.muted, fontSize: 10.5 }, formatter: function (n) { return n.length > 22 ? n.slice(0, 21) + '…' : n; } },
      series: [{ type: 'pie', roseType: 'radius', radius: ['16%', '70%'], center: ['50%', '44%'],
        itemStyle: { borderRadius: 6, borderColor: '#fff', borderWidth: 2, shadowBlur: 14, shadowColor: 'rgba(13,27,42,.15)' },
        label: { show: true, formatter: '{c}', fontWeight: 700, color: C.ink },
        color: ['#14b8a6', '#3b82f6', '#8b5cf6', '#f59e0b', '#ec4899'],
        data: D.grupos.map(function (g) { return { name: g.name, value: g.n }; }) }]
    });
  } else vacio('chGrupo');

  /* ---------------- 7. Ranking por cuota aprobada ---------------- */
  if (D.topMonto.length) {
    iniciar('chTop', {
      tooltip: Object.assign({}, tip, { formatter: function (p) { var t = D.topMonto[p.dataIndex]; return '<b>' + t.full + '</b><br>' + soles(t.monto) + ' · ' + t.n + ' aprobada(s)'; } }),
      grid: { left: 10, right: 90, top: 8, bottom: 8, containLabel: true },
      xAxis: { type: 'value', show: false },
      yAxis: { type: 'category', data: D.topMonto.map(function (t) { return t.name; }), axisLine: { show: false }, axisTick: { show: false }, axisLabel: { color: C.ink, fontSize: 11.5 } },
      series: [{ type: 'bar', barWidth: 16, data: D.topMonto.map(function (t) { return t.monto; }), showBackground: true, backgroundStyle: { color: '#f1f5f9', borderRadius: 8 },
        itemStyle: { borderRadius: 8, color: grad('#0e7490', '#14b8a6', true), shadowBlur: 8, shadowColor: 'rgba(20,184,166,.3)' },
        label: { show: true, position: 'right', formatter: function (p) { return soles(p.value); }, color: C.ink, fontWeight: 700, fontSize: 11.5 } }]
    });
  } else vacio('chTop', 'Aún no hay cuotas aprobadas');

  window.addEventListener('resize', function () { charts.forEach(function (c) { c.resize(); }); });

  /* ---------------- Contadores animados ---------------- */
  document.querySelectorAll('[data-count]').forEach(function (n) {
    var fin = parseInt(n.dataset.count, 10) || 0;
    if (reduceMotion || !fin) { n.textContent = fin; return; }
    var t0 = null, dur = 1100;
    function paso(t) { if (!t0) t0 = t; var k = Math.min(1, (t - t0) / dur); n.textContent = Math.round(fin * (1 - Math.pow(1 - k, 3))); if (k < 1) requestAnimationFrame(paso); }
    requestAnimationFrame(paso);
  });

  /* ---------------- Tarjetas con inclinación 3D ---------------- */
  if (!reduceMotion) document.querySelectorAll('.tilt').forEach(function (c) {
    c.addEventListener('mousemove', function (e) {
      var r = c.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
      c.style.transform = 'perspective(700px) rotateX(' + (-y * 10).toFixed(2) + 'deg) rotateY(' + (x * 12).toFixed(2) + 'deg) translateZ(6px)';
      c.style.setProperty('--mx', ((x + 0.5) * 100).toFixed(1) + '%'); c.style.setProperty('--my', ((y + 0.5) * 100).toFixed(1) + '%');
    });
    c.addEventListener('mouseleave', function () { c.style.transform = ''; });
  });

  /* ---------------- Fondo del encabezado: red 3D en perspectiva ---------------- */
  (function () {
    var cv = document.getElementById('heroCanvas');
    if (!cv) return;
    var ctx = cv.getContext('2d'), w, h, pts = [], N = 70, dpr = Math.min(window.devicePixelRatio || 1, 2), t = 0, on = true;
    function size() { var r = cv.getBoundingClientRect(); w = r.width; h = r.height; cv.width = w * dpr; cv.height = h * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0); }
    size(); window.addEventListener('resize', size);
    for (var i = 0; i < N; i++) pts.push({ x: (Math.random() - 0.5) * 2, y: (Math.random() - 0.5) * 1.2, z: Math.random() * 2 + 0.4 });
    function proy(p) { var f = 1 / p.z; return { x: w * 0.62 + p.x * w * 0.45 * f, y: h * 0.5 + p.y * h * 0.9 * f, s: f }; }
    function frame() {
      if (!on) return;
      t += 0.0025; ctx.clearRect(0, 0, w, h);
      // suelo en perspectiva
      ctx.strokeStyle = 'rgba(94,234,212,0.10)'; ctx.lineWidth = 1;
      for (var g = 0; g < 14; g++) { var yy = h * 0.62 + Math.pow(g / 14, 1.8) * h * 0.6; ctx.beginPath(); ctx.moveTo(0, yy); ctx.lineTo(w, yy); ctx.stroke(); }
      for (var v = -12; v <= 12; v++) { ctx.beginPath(); ctx.moveTo(w * 0.62 + v * 18, h * 0.62); ctx.lineTo(w * 0.62 + v * 140, h * 1.3); ctx.stroke(); }
      var P = pts.map(function (p) { p.z -= 0.0018; if (p.z < 0.35) { p.z = 2.4; p.x = (Math.random() - 0.5) * 2; p.y = (Math.random() - 0.5) * 1.2; } var r = Math.sin(t + p.x * 3) * 0.02; return proy({ x: p.x + r, y: p.y, z: p.z }); });
      for (var a = 0; a < P.length; a++) for (var b = a + 1; b < P.length; b++) {
        var dx = P[a].x - P[b].x, dy = P[a].y - P[b].y, d = dx * dx + dy * dy;
        if (d < 9000) { ctx.strokeStyle = 'rgba(94,234,212,' + (0.16 * (1 - d / 9000)).toFixed(3) + ')'; ctx.beginPath(); ctx.moveTo(P[a].x, P[a].y); ctx.lineTo(P[b].x, P[b].y); ctx.stroke(); }
      }
      P.forEach(function (q) { ctx.fillStyle = 'rgba(153,246,228,' + Math.min(0.9, q.s * 0.5).toFixed(2) + ')'; ctx.beginPath(); ctx.arc(q.x, q.y, Math.min(3.2, 1.1 * q.s + 0.4), 0, 6.283); ctx.fill(); });
      requestAnimationFrame(frame);
    }
    document.addEventListener('visibilitychange', function () { on = !document.hidden; if (on && !reduceMotion) requestAnimationFrame(frame); });
    if (!reduceMotion) requestAnimationFrame(frame); else frame();
  })();
})();
