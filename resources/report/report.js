// SPDX-License-Identifier: MIT
// The report's interactive parts: the dependency map, its details panel, and the sortable table.
// Inlined into every report, so it has no dependencies and reads its data from #untangled-data.
(function () {
  'use strict';

  var data = JSON.parse(document.getElementById('untangled-data').textContent);
  var SVG = 'http://www.w3.org/2000/svg';
  var units = data.units;
  var byId = {};
  units.forEach(function (unit) {
    unit.out = [];
    unit.in = [];
    byId[unit.id] = unit;
  });
  data.edges.forEach(function (edge) {
    byId[edge.from].out.push(edge.to);
    byId[edge.to].in.push(edge.from);
  });
  // A large graph draws only its most connected parts (data.drawn); the rest stay in the
  // details panel and the tables, so the map stays readable and quick to lay out.
  var drawn = data.drawn ? data.drawn.map(function (id) { return byId[id]; }) : units;
  var isDrawn = {};
  drawn.forEach(function (unit) { isDrawn[unit.id] = true; });
  var drawnEdges = data.edges.filter(function (edge) { return isDrawn[edge.from] && isDrawn[edge.to]; });

  // ---- Layout: a deterministic force-directed layout (Fruchterman-Reingold) ----

  function layout(nodes, edges) {
    var n = nodes.length;
    var k = 80;
    var golden = Math.PI * (3 - Math.sqrt(5));
    nodes.forEach(function (node, i) {
      node.index = i;
      // Sunflower spiral start: deterministic, and evenly spread.
      var r = k * 0.6 * Math.sqrt(i + 0.5);
      node.x = r * Math.cos(i * golden);
      node.y = r * Math.sin(i * golden);
    });
    if (n < 2) return;
    var links = edges.map(function (edge) { return [byId[edge.from], byId[edge.to]]; });
    var iterations = n <= 200 ? 300 : Math.max(60, Math.floor(60000 / n));
    var temperature = k * Math.sqrt(n) / 2;
    var cooling = temperature / (iterations + 1);
    var gravity = 0.3 * Math.max(1, n / 50);
    var dx = new Float64Array(n);
    var dy = new Float64Array(n);
    for (var step = 0; step < iterations; step++) {
      dx.fill(0);
      dy.fill(0);
      for (var i = 0; i < n; i++) {
        var a = nodes[i];
        for (var j = i + 1; j < n; j++) {
          var b = nodes[j];
          var x = a.x - b.x;
          var y = a.y - b.y;
          var d2 = x * x + y * y || 0.01;
          var f = (k * k) / d2;
          dx[i] += x * f; dy[i] += y * f;
          dx[j] -= x * f; dy[j] -= y * f;
        }
      }
      links.forEach(function (link) {
        var s = link[0], t = link[1];
        var x = s.x - t.x, y = s.y - t.y;
        var d = Math.sqrt(x * x + y * y) || 0.01;
        var f = d / k;
        dx[s.index] -= x * f; dy[s.index] -= y * f;
        dx[t.index] += x * f; dy[t.index] += y * f;
      });
      for (var m = 0; m < n; m++) {
        var node = nodes[m];
        // A pull to the centre keeps unconnected parts from drifting away: repulsion from all n
        // parts grows with n, so it grows with n too, keeping loose parts within about 13 k.
        dx[m] -= node.x * gravity;
        dy[m] -= node.y * gravity;
        var len = Math.sqrt(dx[m] * dx[m] + dy[m] * dy[m]) || 1;
        var move = Math.min(len, temperature);
        node.x += (dx[m] / len) * move;
        node.y += (dy[m] / len) * move;
      }
      temperature -= cooling;
    }
    // Then push apart circles that still overlap, leaving room for a short label.
    for (var pass = 0; pass < 40; pass++) {
      var moved = false;
      for (var p = 0; p < n; p++) {
        for (var q = p + 1; q < n; q++) {
          var u = nodes[p], v = nodes[q];
          var ox = v.x - u.x, oy = v.y - u.y;
          var dist = Math.sqrt(ox * ox + oy * oy) || 0.01;
          var min = radius(u) + radius(v) + 18;
          if (dist >= min) continue;
          var push = (min - dist) / 2;
          u.x -= (ox / dist) * push; u.y -= (oy / dist) * push;
          v.x += (ox / dist) * push; v.y += (oy / dist) * push;
          moved = true;
        }
      }
      if (!moved) break;
    }
  }

  // ---- Colour: instability on one sequential hue, read from the CSS ramp ----

  function hexToRgb(hex) {
    hex = hex.trim().replace('#', '');
    return [0, 2, 4].map(function (i) { return parseInt(hex.substr(i, 2), 16); });
  }
  function rampColour(value, styles) {
    if (value === null) return styles.getPropertyValue('--none').trim();
    var from = hexToRgb(styles.getPropertyValue('--ramp-0'));
    var to = hexToRgb(styles.getPropertyValue('--ramp-1'));
    var rgb = from.map(function (c, i) { return Math.round(c + (to[i] - c) * value); });
    return 'rgb(' + rgb.join(',') + ')';
  }

  function radius(unit) {
    return Math.min(26, 7 + 3 * Math.sqrt(unit.fan_in));
  }

  function shortName(id) {
    if (data.clustered) return id;
    var slash = id.lastIndexOf('/');
    return slash === -1 ? id : id.slice(slash + 1);
  }

  function el(name, attributes, parent) {
    var node = document.createElementNS(SVG, name);
    Object.keys(attributes || {}).forEach(function (key) { node.setAttribute(key, attributes[key]); });
    if (parent) parent.appendChild(node);
    return node;
  }

  function html(tag, text, className) {
    var node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  }

  function plural(count, singular, pluralForm) {
    return count + ' ' + (count === 1 ? singular : (pluralForm || singular + 's'));
  }

  // ---- The map ----

  var canvas = document.getElementById('map-canvas');
  var details = document.getElementById('map-details');
  var showAll = drawn.length <= 40;
  var view = { x: 0, y: 0, scale: 1 };
  var svg, scene, nodeEls = {}, edgeEls = [], selected = null;

  if (units.length === 0) {
    canvas.appendChild(html('div', 'There are no parts to draw.', 'map-empty'));
  } else {
    buildMap();
  }

  function buildMap() {
    layout(drawn, drawnEdges);
    svg = el('svg', { role: 'img', 'aria-label': 'Map of ' + plural(drawn.length, 'part') + ' and the dependencies between them' }, canvas);
    var defs = el('defs', {}, svg);
    [['arrow', 'var(--edge)'], ['arrow-cycle', 'var(--critical)']].forEach(function (marker) {
      var m = el('marker', { id: marker[0], viewBox: '0 0 10 10', refX: '9', refY: '5', markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse' }, defs);
      el('path', { d: 'M0,1 L9,5 L0,9 z', style: 'fill:' + marker[1] }, m);
    });
    scene = el('g', {}, svg);
    var edgeLayer = el('g', {}, scene);
    var nodeLayer = el('g', {}, scene);
    var styles = getComputedStyle(document.documentElement);

    drawnEdges.forEach(function (edge) {
      var s = byId[edge.from], t = byId[edge.to];
      var line = el('line', { class: 'edge' + (edge.cycle ? ' cycle' : ''), 'marker-end': edge.cycle ? 'url(#arrow-cycle)' : 'url(#arrow)' }, edgeLayer);
      edgeEls.push({ el: line, from: s, to: t });
    });
    drawn.forEach(function (unit) {
      var g = el('g', { class: 'node' + (unit.cycle ? ' in-cycle' : '') + (showAll ? '' : ' hide-label'), tabindex: '0', role: 'button', 'aria-label': unit.id }, nodeLayer);
      el('circle', { r: radius(unit), fill: rampColour(unit.instability, styles) }, g);
      var label = el('text', { x: radius(unit) + 4, y: 4 }, g);
      label.textContent = shortName(unit.id);
      g.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(unit.id); }
      });
      g.addEventListener('pointerenter', function () { showTooltip(unit); });
      g.addEventListener('pointerleave', hideTooltip);
      nodeEls[unit.id] = g;
    });
    // Recolour when the viewer switches between light and dark.
    if (window.matchMedia) {
      var scheme = window.matchMedia('(prefers-color-scheme: dark)');
      var recolour = function () {
        var current = getComputedStyle(document.documentElement);
        drawn.forEach(function (unit) { nodeEls[unit.id].firstChild.setAttribute('fill', rampColour(unit.instability, current)); });
      };
      if (scheme.addEventListener) scheme.addEventListener('change', recolour);
    }
    positionAll();
    fit();
    enablePanZoom();
  }

  function positionNode(unit) {
    nodeEls[unit.id].setAttribute('transform', 'translate(' + unit.x.toFixed(1) + ',' + unit.y.toFixed(1) + ')');
  }

  function positionEdge(edge) {
    // Stop the line at the target's rim so the arrowhead stays visible.
    var x = edge.to.x - edge.from.x, y = edge.to.y - edge.from.y;
    var d = Math.sqrt(x * x + y * y) || 1;
    var r1 = radius(edge.from), r2 = radius(edge.to) + 2;
    edge.el.setAttribute('x1', (edge.from.x + (x / d) * r1).toFixed(1));
    edge.el.setAttribute('y1', (edge.from.y + (y / d) * r1).toFixed(1));
    edge.el.setAttribute('x2', (edge.to.x - (x / d) * r2).toFixed(1));
    edge.el.setAttribute('y2', (edge.to.y - (y / d) * r2).toFixed(1));
  }

  function positionAll() {
    drawn.forEach(positionNode);
    edgeEls.forEach(positionEdge);
  }

  function applyView() {
    scene.setAttribute('transform', 'translate(' + view.x + ',' + view.y + ') scale(' + view.scale + ')');
  }

  function fit() {
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    drawn.forEach(function (u) {
      minX = Math.min(minX, u.x - 30); maxX = Math.max(maxX, u.x + 120);
      minY = Math.min(minY, u.y - 30); maxY = Math.max(maxY, u.y + 30);
    });
    var width = canvas.clientWidth || 800, height = canvas.clientHeight || 560;
    view.scale = Math.min(1.6, Math.min(width / (maxX - minX), height / (maxY - minY)));
    view.x = (width - (maxX + minX) * view.scale) / 2;
    view.y = (height - (maxY + minY) * view.scale) / 2;
    applyView();
  }

  function toScene(clientX, clientY) {
    var box = svg.getBoundingClientRect();
    return { x: (clientX - box.left - view.x) / view.scale, y: (clientY - box.top - view.y) / view.scale };
  }

  function zoomAt(clientX, clientY, factor) {
    var box = svg.getBoundingClientRect();
    var px = clientX - box.left, py = clientY - box.top;
    var scale = Math.max(0.1, Math.min(6, view.scale * factor));
    view.x = px - ((px - view.x) * scale) / view.scale;
    view.y = py - ((py - view.y) * scale) / view.scale;
    view.scale = scale;
    applyView();
  }

  function enablePanZoom() {
    var pointers = {};
    var drag = null;
    var pinch = null;
    svg.addEventListener('wheel', function (event) {
      event.preventDefault();
      zoomAt(event.clientX, event.clientY, Math.exp(-event.deltaY * 0.0015));
    }, { passive: false });
    svg.addEventListener('pointerdown', function (event) {
      pointers[event.pointerId] = { x: event.clientX, y: event.clientY };
      var ids = Object.keys(pointers);
      if (ids.length === 2) {
        var a = pointers[ids[0]], b = pointers[ids[1]];
        pinch = { distance: Math.hypot(a.x - b.x, a.y - b.y) };
        drag = null;
        return;
      }
      var target = event.target.closest('.node');
      var unit = target ? drawn.find(function (u) { return nodeEls[u.id] === target; }) : null;
      var start = toScene(event.clientX, event.clientY);
      drag = { unit: unit, start: start, clientX: event.clientX, clientY: event.clientY, viewX: view.x, viewY: view.y, moved: false, offsetX: unit ? unit.x - start.x : 0, offsetY: unit ? unit.y - start.y : 0 };
      svg.setPointerCapture(event.pointerId);
      if (!unit) svg.classList.add('panning');
    });
    svg.addEventListener('pointermove', function (event) {
      if (!pointers[event.pointerId]) return;
      pointers[event.pointerId] = { x: event.clientX, y: event.clientY };
      var ids = Object.keys(pointers);
      if (pinch && ids.length === 2) {
        var a = pointers[ids[0]], b = pointers[ids[1]];
        var distance = Math.hypot(a.x - b.x, a.y - b.y);
        zoomAt((a.x + b.x) / 2, (a.y + b.y) / 2, distance / (pinch.distance || distance));
        pinch.distance = distance;
        return;
      }
      if (!drag) return;
      if (Math.abs(event.clientX - drag.clientX) + Math.abs(event.clientY - drag.clientY) > 3) drag.moved = true;
      if (!drag.moved) return;
      hideTooltip();
      if (drag.unit) {
        var p = toScene(event.clientX, event.clientY);
        drag.unit.x = p.x + drag.offsetX;
        drag.unit.y = p.y + drag.offsetY;
        positionNode(drag.unit);
        edgeEls.forEach(function (edge) { if (edge.from === drag.unit || edge.to === drag.unit) positionEdge(edge); });
      } else {
        view.x = drag.viewX + event.clientX - drag.clientX;
        view.y = drag.viewY + event.clientY - drag.clientY;
        applyView();
      }
    });
    function end(event) {
      delete pointers[event.pointerId];
      if (Object.keys(pointers).length < 2) pinch = null;
      svg.classList.remove('panning');
      // A press that did not move is a click: on a part it selects it, on the background it clears.
      if (drag && !drag.moved && event.type === 'pointerup') select(drag.unit ? drag.unit.id : null);
      drag = null;
    }
    svg.addEventListener('pointerup', end);
    svg.addEventListener('pointercancel', end);
  }

  // ---- Tooltip ----

  var tooltip = html('div', undefined, 'tooltip');
  tooltip.setAttribute('role', 'tooltip');
  canvas.appendChild(tooltip);

  function showTooltip(unit) {
    tooltip.textContent = '';
    tooltip.appendChild(html('strong', unit.id));
    tooltip.appendChild(html('div', 'Relied on by ' + plural(unit.fan_in, 'part') + ', relies on ' + plural(unit.fan_out, 'part') + '.'));
    if (unit.cycle) tooltip.appendChild(html('div', 'In cycle ' + unit.cycle + '.'));
    tooltip.style.display = 'block';
    var box = nodeEls[unit.id].getBoundingClientRect();
    var frame = canvas.getBoundingClientRect();
    var left = box.right - frame.left + 8;
    if (left + 280 > frame.width) left = Math.max(8, box.left - frame.left - tooltip.offsetWidth - 8);
    tooltip.style.left = left + 'px';
    tooltip.style.top = Math.max(8, box.top - frame.top) + 'px';
  }

  function hideTooltip() {
    tooltip.style.display = 'none';
  }

  // ---- Selection and the details panel ----

  function formatInstability(value) {
    return value === null ? 'none' : value.toFixed(2);
  }

  function linkList(ids) {
    var list = html('ul');
    ids.slice().sort().forEach(function (id) {
      var item = html('li');
      var button = html('button', id, 'link-button mono');
      button.type = 'button';
      button.addEventListener('click', function () { select(id); });
      item.appendChild(button);
      list.appendChild(item);
    });
    return list;
  }

  function metric(list, label, value, sentence) {
    var dt = html('dt');
    dt.appendChild(document.createTextNode(label + ': '));
    dt.appendChild(html('span', String(value), 'num'));
    list.appendChild(dt);
    list.appendChild(html('dd', sentence));
  }

  function renderDetails(unit) {
    details.textContent = '';
    if (!unit) {
      details.appendChild(html('h3', 'Pick a part'));
      details.appendChild(html('p', 'Click a circle on the map, or a name in the tables below, to see what it relies on and what relies on it.', 'muted'));
      return;
    }
    details.appendChild(html('h3', unit.id, 'mono'));
    if (!isDrawn[unit.id]) details.appendChild(html('p', 'Not drawn on the map, which shows only the ' + drawn.length + ' parts with the most connections.', 'muted small'));
    if (unit.members) details.appendChild(html('p', plural(unit.members.length, 'file') + ' in this group.', 'muted small'));
    if (unit.cycle) {
      var cycle = data.cycles[unit.cycle - 1];
      var note = html('div', undefined, 'cycle-note');
      note.appendChild(html('strong', 'In cycle ' + unit.cycle + ' with ' + plural(cycle.size - 1, 'other part') + '.'));
      note.appendChild(html('div', data.plain.cycle));
      details.appendChild(note);
    }
    var dl = html('dl');
    metric(dl, 'Relied on by', plural(unit.fan_in, 'part'), data.plain.fan_in);
    metric(dl, 'Relies on', plural(unit.fan_out, 'part'), data.plain.fan_out);
    if (data.clustered) {
      metric(dl, 'Files relying on it', unit.ca, data.plain.ca);
      metric(dl, 'Files it relies on', unit.ce, data.plain.ce);
    }
    metric(dl, 'Instability', formatInstability(unit.instability), data.plain.instability);
    metric(dl, 'Packages', unit.external, data.plain.external);
    if (unit.unresolved > 0) metric(dl, 'Untraced', unit.unresolved, data.plain.unresolved);
    details.appendChild(dl);
    if (unit.out.length) {
      details.appendChild(html('h4', 'Relies on'));
      details.appendChild(linkList(unit.out));
    }
    if (unit.in.length) {
      details.appendChild(html('h4', 'Relied on by'));
      details.appendChild(linkList(unit.in));
    }
  }

  function select(id) {
    var unit = id === null ? null : byId[id];
    selected = unit || null;
    renderDetails(selected);
    if (!svg) return;
    canvas.classList.toggle('focused', !!selected);
    drawn.forEach(function (u) {
      var near = !!selected && (u === selected || selected.out.indexOf(u.id) !== -1 || selected.in.indexOf(u.id) !== -1);
      nodeEls[u.id].classList.toggle('near', near);
      nodeEls[u.id].classList.toggle('selected', u === selected);
    });
    edgeEls.forEach(function (edge) {
      edge.el.classList.toggle('near', !!selected && (edge.from === selected || edge.to === selected));
    });
    if (selected && isDrawn[selected.id]) {
      // Bring the part into view if it is off screen.
      var width = canvas.clientWidth, height = canvas.clientHeight;
      var sx = selected.x * view.scale + view.x, sy = selected.y * view.scale + view.y;
      if (sx < 20 || sy < 20 || sx > width - 20 || sy > height - 20) {
        view.x = width / 2 - selected.x * view.scale;
        view.y = height / 2 - selected.y * view.scale;
        applyView();
      }
    }
  }

  // ---- Toolbar ----

  var names = document.getElementById('map-names');
  units.forEach(function (unit) {
    var option = document.createElement('option');
    option.value = unit.id;
    names.appendChild(option);
  });
  document.getElementById('map-search').addEventListener('change', function (event) {
    var query = event.target.value.trim().toLowerCase();
    if (!query) return;
    var match = byId[event.target.value] || units.find(function (u) { return u.id.toLowerCase().indexOf(query) !== -1; });
    if (match) select(match.id);
  });
  var labelsButton = document.getElementById('map-labels');
  labelsButton.setAttribute('aria-pressed', String(showAll));
  labelsButton.addEventListener('click', function () {
    showAll = !showAll;
    labelsButton.setAttribute('aria-pressed', String(showAll));
    drawn.forEach(function (unit) { nodeEls[unit.id].classList.toggle('hide-label', !showAll); });
  });
  document.getElementById('map-reset').addEventListener('click', function () {
    select(null);
    if (svg) fit();
  });
  document.getElementById('map-dot').addEventListener('click', function () {
    var link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([data.dot], { type: 'text/vnd.graphviz' }));
    link.download = 'dependencies.dot';
    document.body.appendChild(link);
    link.click();
    setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 0);
  });

  // Names in the tables and lists open that part on the map.
  document.querySelectorAll('[data-unit]').forEach(function (button) {
    button.addEventListener('click', function () {
      select(button.getAttribute('data-unit'));
      document.getElementById('map').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  // ---- Sortable table ----

  var table = document.getElementById('parts-table');
  if (table) {
    var headers = table.querySelectorAll('th[data-key]');
    headers.forEach(function (th, column) {
      th.querySelector('button').addEventListener('click', function () {
        var ascending = th.getAttribute('aria-sort') !== 'ascending';
        headers.forEach(function (other) { other.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
        var body = table.tBodies[0];
        var rows = Array.prototype.slice.call(body.rows);
        var numeric = th.getAttribute('data-key') !== 'id';
        rows.sort(function (a, b) {
          var x = cellValue(a.cells[column], numeric), y = cellValue(b.cells[column], numeric);
          var order = numeric ? x - y : (x < y ? -1 : x > y ? 1 : 0);
          return ascending ? order : -order;
        });
        rows.forEach(function (row) { body.appendChild(row); });
      });
    });
  }

  function cellValue(cell, numeric) {
    var raw = cell.hasAttribute('data-value') ? cell.getAttribute('data-value') : cell.textContent.trim();
    return numeric ? parseFloat(raw) : raw;
  }
})();
