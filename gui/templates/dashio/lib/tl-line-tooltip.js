/**
 * Hover tooltips for the line charts.
 *
 * The Chart.js bundled with the dashio template is the original 2013 release,
 * which has no tooltip support at all - there is no `tooltipTemplate` option to
 * turn on. Rather than upgrade the library (every .Doughnut()/.Bar()/.Line()
 * call site in the app uses its v1 API), this replicates the exact geometry the
 * `Line()` chart uses to plot its point dots and hit-tests the pointer against
 * those positions. The mirror of `tl-pie-tooltip.js`.
 *
 * Geometry mirrored from Chart.js `Line()` (gui/templates/dashio/lib/chart-master/Chart.js):
 *   xPos(iteration) = yAxisPosX + valueHop * iteration
 *   yPos(iteration) = xAxisPosY - calculateOffset(value, calculatedScale, scaleHop)
 *   calculateOffset  = (scaleHop * steps) * clamp((value - graphMin) / (steps * stepValue), 0, 1)
 * where scaleHeight / calculatedScale / valueHop / yAxisPosX / xAxisPosY come
 * from calculateDrawingSizes(), getValueBounds(), calculateScale() and
 * calculateXAxisSize() of the same file.
 *
 * Usage:
 *   TLLineTooltip.attach(canvas, labels, data, {color: '#4ECDC4'});
 *
 * `labels` and `data` are the arrays handed to .Line(). Optional opts.radius
 * (in canvas backing pixels) controls how close the pointer must be to a dot.
 */
var TLLineTooltip = (function () {
  var TIP_ID = 'tl-line-tooltip';

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

  // Decimal places Chart.js uses when formatting the Y-axis tick labels.
  function getDecimalPlaces(num) {
    if (num % 1 !== 0) { return num.toString().split('.')[1].length; }
    return 0;
  }

  // Replicates the pivot values of the Chart.js v1 `Line()` chart so the point
  // dots can be located without CSS parsing. Returns the dot centres in canvas
  // backing-store pixels, or null when the chart cannot be hit-tested.
  function geometry(canvas, ctx, labels, data) {
    if (!labels || !labels.length || !data || labels.length !== data.length) return null;
    if (labels.length < 2) return null; // valueHop would divide by zero

    var W = canvas.width, H = canvas.height;
    var SFS = 12; // config.scaleFontSize
    var SFont = "normal " + SFS + 'px ' + "'Arial'"; // config.scaleFontStyle + size + family

    ctx.font = SFont;

    // calculateDrawingSizes()
    var maxSize = H, widestXLabel = 1, i;
    for (i = 0; i < labels.length; i++) {
      var w = ctx.measureText(labels[i]).width;
      if (w > widestXLabel) widestXLabel = w;
    }
    if (W / labels.length < widestXLabel) {
      // Chart.js feeds rotateLabels (=45, a degree-looking value) straight into
      // Math.cos/sin, i.e. as RADIANS. Mirror that verbatim so the branch stays
      // pixel-exact under every geometry, not just the 90-degree branch the
      // Event Viewer (30 labels) always falls into.
      if (W / labels.length < Math.cos(45) * widestXLabel) {
        maxSize -= widestXLabel; // rotateLabels = 90
      } else {
        maxSize -= Math.sin(45) * widestXLabel;
      }
    } else {
      maxSize -= SFS;
    }
    maxSize -= 5;
    var labelHeight = SFS;
    maxSize -= labelHeight;
    var scaleHeight = maxSize;

    // getValueBounds() - same sentinel init as Chart.js, so an all-zero dataset
    // keeps an hoverable baseline (upperValue = Number.MIN_VALUE > 0).
    var upperValue = Number.MIN_VALUE, lowerValue = Number.MAX_VALUE;
    for (i = 0; i < data.length; i++) {
      var v = Number(data[i]);
      if (isFinite(v)) {
        if (v > upperValue) upperValue = v;
        if (v < lowerValue) lowerValue = v;
      }
    }
    if (!isFinite(upperValue) || !isFinite(lowerValue) || upperValue - lowerValue <= 0) return null;
    var maxSteps = Math.floor(scaleHeight / (labelHeight * 0.66));
    var minSteps = Math.floor(scaleHeight / labelHeight * 0.5);

    // calculateScale()
    var valueRange = upperValue - lowerValue;
    var rangeOrderOfMagnitude = Math.floor(Math.log(valueRange) / Math.LN10);
    var graphMin = Math.floor(lowerValue / Math.pow(10, rangeOrderOfMagnitude)) * Math.pow(10, rangeOrderOfMagnitude);
    var graphMax = Math.ceil(upperValue / Math.pow(10, rangeOrderOfMagnitude)) * Math.pow(10, rangeOrderOfMagnitude);
    var graphRange = graphMax - graphMin;
    var stepValue = Math.pow(10, rangeOrderOfMagnitude);
    var numberOfSteps = Math.round(graphRange / stepValue);
    while (numberOfSteps < minSteps || numberOfSteps > maxSteps) {
      if (numberOfSteps < minSteps) { stepValue /= 2; numberOfSteps = Math.round(graphRange / stepValue); }
      else { stepValue *= 2; numberOfSteps = Math.round(graphRange / stepValue); }
    }
    var scaleHop = Math.floor(scaleHeight / numberOfSteps);

    // calculateXAxisSize() - longest Y tick label width
    ctx.font = SFont;
    var longestText = 1;
    var decimals = getDecimalPlaces(stepValue);
    for (i = 1; i <= numberOfSteps; i++) {
      var tick = String((graphMin + stepValue * i).toFixed(decimals));
      var m = ctx.measureText(tick).width;
      if (m > longestText) longestText = m;
    }
    longestText += 10;
    var xAxisLength = W - longestText - widestXLabel;
    var valueHop = Math.floor(xAxisLength / (labels.length - 1));
    var yAxisPosX = W - widestXLabel / 2 - xAxisLength;
    var xAxisPosY = scaleHeight + SFS / 2;

    // calculateOffset()
    var outerValue = numberOfSteps * stepValue;
    function offset(val, graphMin, outerValue) {
      var adj = val - graphMin;
      var factor = adj / outerValue;
      if (factor > 1) factor = 1;
      if (factor < 0) factor = 0;
      return (scaleHop * numberOfSteps) * factor;
    }

    var points = [];
    for (i = 0; i < data.length; i++) {
      var val = Number(data[i]);
      if (!isFinite(val)) val = 0;
      points.push({
        x: yAxisPosX + valueHop * i,
        y: xAxisPosY - offset(val, graphMin, outerValue),
        value: val,
        label: String(labels[i] == null ? '' : labels[i])
      });
    }
    return points;
  }

  // Nearest point within `radius` of (x, y), or null.
  function hitTest(points, radius, x, y) {
    var best = null, bestD = radius * radius;
    for (var i = 0; i < points.length; i++) {
      var dx = points[i].x - x, dy = points[i].y - y;
      var d = dx * dx + dy * dy;
      if (d <= bestD) { bestD = d; best = points[i]; }
    }
    return best;
  }

  function attach(canvas, labels, data, opts) {
    if (!canvas || !labels || !labels.length || !data) return;
    opts = opts || {};
    var radius = opts.radius != null ? opts.radius : 10;
    var color = opts.color || '#4ECDC4';
    var doc = canvas.ownerDocument;
    var tip = tipElement(doc);
    var ctx = canvas.getContext('2d');
    var pts = geometry(canvas, ctx, labels, data);

    // Re-attaching (charts are redrawn on filter changes) must not stack handlers.
    if (canvas._tlLineMove) {
      canvas.removeEventListener('mousemove', canvas._tlLineMove);
      canvas.removeEventListener('mouseleave', canvas._tlLineLeave);
    }

    function onMove(e) {
      var rect = canvas.getBoundingClientRect();
      if (!pts || !rect.width || !rect.height) return;
      // Convert CSS-space pointer into canvas backing-store coordinates.
      var x = (e.clientX - rect.left) * (canvas.width / rect.width);
      var y = (e.clientY - rect.top) * (canvas.height / rect.height);
      var hit = hitTest(pts, radius, x, y);
      if (!hit) { tip.style.display = 'none'; canvas.style.cursor = ''; return; }

      tip.innerHTML =
        '<span style="display:inline-block;width:9px;height:9px;border-radius:2px;' +
        'margin-right:7px;vertical-align:middle;background:' + color + '"></span>' +
        '<span style="vertical-align:middle">' +
        hit.label.replace(/</g, '&lt;') +
        ': <b>' + hit.value + '</b></span>';

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

    canvas._tlLineMove = onMove;
    canvas._tlLineLeave = onLeave;
    canvas.addEventListener('mousemove', onMove);
    canvas.addEventListener('mouseleave', onLeave);
  }

  return { attach: attach };
})();