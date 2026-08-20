/**
 * Hover tooltips for the doughnut charts.
 *
 * The Chart.js bundled with the dashio template is the original 2013 release,
 * which has no tooltip support at all - there is no `tooltipTemplate` option to
 * turn on. Rather than upgrade the library (every .Doughnut()/.Bar()/.Line()
 * call site in the app uses its v1 API), this hit-tests the pointer against the
 * same geometry Chart.js uses to draw the segments.
 *
 * Geometry mirrored from Chart.js `Doughnut()`:
 *   centre        = (width/2, height/2)   in canvas pixels
 *   outer radius  = min(width, height)/2 - 5
 *   inner radius  = outer * percentageInnerCutout/100
 *   first segment starts at -PI/2 and they run clockwise
 *
 * Usage:
 *   TLPieTooltip.attach(canvas, segments, {percentageInnerCutout: 45});
 *
 * `segments` is the same array handed to .Doughnut(): [{value, color, label}].
 * Pass a plain label - the tooltip appends the value and percentage itself.
 */
var TLPieTooltip = (function () {
  var TIP_ID = 'tl-pie-tooltip';

  function tipElement(doc) {
    var el = doc.getElementById(TIP_ID);
    if (el) return el;
    el = doc.createElement('div');
    el.id = TIP_ID;
    el.style.cssText = [
      'position:absolute', 'z-index:10000', 'pointer-events:none',
      'display:none', 'padding:6px 10px', 'border-radius:4px',
      'background:rgba(34,36,42,.94)', 'color:#fff', 'font-size:12px',
      'line-height:1.35', 'white-space:nowrap',
      'box-shadow:0 2px 8px rgba(0,0,0,.25)',
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif'
    ].join(';');
    doc.body.appendChild(el);
    return el;
  }

  // Which segment is under (cssX, cssY), or -1.
  function hitTest(canvas, segments, cutoutPct, cssX, cssY) {
    var rect = canvas.getBoundingClientRect();
    if (!rect.width || !rect.height) return -1;

    // The canvas backing store may differ from its CSS size.
    var x = cssX * (canvas.width / rect.width);
    var y = cssY * (canvas.height / rect.height);

    var cx = canvas.width / 2, cy = canvas.height / 2;
    var outer = Math.min(canvas.width, canvas.height) / 2 - 5;
    var inner = outer * (cutoutPct / 100);

    var dx = x - cx, dy = y - cy;
    var r = Math.sqrt(dx * dx + dy * dy);
    if (r > outer || r < inner) return -1;

    var total = 0, i;
    for (i = 0; i < segments.length; i++) total += Number(segments[i].value) || 0;
    if (total <= 0) return -1;

    // Angle measured clockwise from the top, matching the drawing order.
    var a = Math.atan2(dy, dx) + Math.PI / 2;
    while (a < 0) a += Math.PI * 2;
    while (a >= Math.PI * 2) a -= Math.PI * 2;

    var acc = 0;
    for (i = 0; i < segments.length; i++) {
      var span = ((Number(segments[i].value) || 0) / total) * Math.PI * 2;
      if (a >= acc && a < acc + span) return i;
      acc += span;
    }
    return -1;
  }

  function attach(canvas, segments, opts) {
    if (!canvas || !segments || !segments.length) return;
    opts = opts || {};
    var cutoutPct = opts.percentageInnerCutout != null ? opts.percentageInnerCutout : 45;
    var doc = canvas.ownerDocument;
    var tip = tipElement(doc);

    var total = 0;
    for (var i = 0; i < segments.length; i++) total += Number(segments[i].value) || 0;

    // Re-attaching (charts are redrawn on filter changes) must not stack handlers.
    if (canvas._tlPieMove) {
      canvas.removeEventListener('mousemove', canvas._tlPieMove);
      canvas.removeEventListener('mouseleave', canvas._tlPieLeave);
    }

    function onMove(e) {
      var rect = canvas.getBoundingClientRect();
      var idx = hitTest(canvas, segments, cutoutPct,
                        e.clientX - rect.left, e.clientY - rect.top);
      if (idx < 0) { tip.style.display = 'none'; canvas.style.cursor = ''; return; }

      var seg = segments[idx];
      var value = Number(seg.value) || 0;
      var pct = total > 0 ? Math.round((value / total) * 1000) / 10 : 0;

      tip.innerHTML =
        '<span style="display:inline-block;width:9px;height:9px;border-radius:2px;' +
        'margin-right:7px;vertical-align:middle;background:' + (seg.color || '#ccc') + '"></span>' +
        '<span style="vertical-align:middle">' +
        String(seg.label == null ? '' : seg.label).replace(/</g, '&lt;') +
        ': <b>' + value + '</b> (' + pct + '%)</span>';

      tip.style.display = 'block';

      // Keep the tooltip inside the viewport; flip it left near the right edge.
      var pad = 14;
      var left = e.clientX + doc.documentElement.scrollLeft + pad;
      var top = e.clientY + doc.documentElement.scrollTop + pad;
      if (left + tip.offsetWidth > doc.documentElement.scrollLeft + doc.documentElement.clientWidth) {
        left = e.clientX + doc.documentElement.scrollLeft - tip.offsetWidth - pad;
      }
      tip.style.left = left + 'px';
      tip.style.top = top + 'px';
      canvas.style.cursor = 'pointer';
    }

    function onLeave() { tip.style.display = 'none'; canvas.style.cursor = ''; }

    canvas._tlPieMove = onMove;
    canvas._tlPieLeave = onLeave;
    canvas.addEventListener('mousemove', onMove);
    canvas.addEventListener('mouseleave', onLeave);
  }

  return { attach: attach };
})();
