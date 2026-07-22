/**
 * Pairing-Netz — Empfehler-Rendering, keine Layout-Berechnung hier.
 *
 * Positionen (x/y) kommen fertig aus PairingService::pairingNetz() (dish-
 * zentrisches Sektor-Layout, PHP, deterministisch). D3 übernimmt nur Kurven-
 * Rendering (d3-shape), Gewichts-Skalierung (d3-scale) und Zoom/Pan (d3-zoom).
 * Keine forceSimulation, kein Tick-Loop, kein Node-Drag.
 *
 * Modell: Zentrum = Gericht, Innenring = Kern-Anker, aussen die Pairing-
 * KANDIDATEN in Typ-Sektoren (erprobt/aroma/kontrast), unten komplementäre
 * BASISREZEPTE. Typ-Filter (Chips) blenden Kandidaten + Basisrezepte je Typ
 * ein/aus — reine Sichtbarkeit, kein Neuzeichnen.
 */
import { select } from 'd3-selection';
import { zoom } from 'd3-zoom';
import { line as d3line, curveNatural } from 'd3-shape';
import { scaleLinear } from 'd3-scale';

const TYP_FARBE = { erprobt: '#d6409f', aroma: '#f59e0b', kontrast: '#06b6d4' };
const TYP_DASH = { erprobt: null, aroma: '5 4', kontrast: '1 4' };
const TYP_FILL = { erprobt: '#fbcfe8', aroma: '#fde68a', kontrast: '#a5f3fc' };

export function pairingNetzGraph(config) {
  return {
    nodes: config.nodes || [],
    edges: config.edges || [],
    mode: config.mode || 'modal',
    canvasW: config.canvasW || 1000,
    canvasH: config.canvasH || 760,
    onNodeClick: config.onNodeClick || null,
    // Typ-Filter: erprobt an, aroma/kontrast zuschaltbar (Default aus meta).
    typAktiv: Object.assign({ erprobt: true, aroma: false, kontrast: false }, config.typDefault || {}),
    hoverId: null,

    _svg: null,
    _rootG: null,
    _edgeSel: null,
    _nodeSel: null,
    _byId: null,

    init() {
      if (!this.nodes.length) return;
      const el = this.$el.querySelector('[data-fa-netz-mount]');
      if (!el) return;
      if (this.mode === 'preview') this._layoutPreview();
      this._byId = new Map(this.nodes.map((n) => [n.id, n]));
      this._svg = select(el);
      this._buildViewBox();
      this._rootG = this._svg.append('g').attr('data-fa-netz-root', '');
      this._drawEdges();
      this._drawNodes();
      this._applyVisibility();
      if (this.mode === 'modal') this._enableZoom();
    },

    destroy() {
      if (!this._svg) return;
      this._svg.on('.zoom', null);
      this._svg.selectAll('*').remove();
    },

    toggleTyp(typ) {
      this.typAktiv[typ] = !this.typAktiv[typ];
      this._applyVisibility();
    },

    // ── Sichtbarkeit nach Typ (Chips) ──────────────────────────────────
    _typVisible(typ) {
      return typ ? this.typAktiv[typ] !== false : true;
    },

    _nodeVisible(d) {
      if (d.kind === 'kandidat' || d.kind === 'basisrezept') return this._typVisible(d.typ);

      return true;
    },

    _edgeVisible(d) {
      if (d.kind === 'kandidat' || d.kind === 'basis') return this._typVisible(d.typ);

      return true;
    },

    _applyVisibility() {
      if (this._nodeSel) this._nodeSel.style('display', (d) => (this._nodeVisible(d) ? null : 'none'));
      if (this._edgeSel) this._edgeSel.style('display', (d) => (this._edgeVisible(d) ? null : 'none'));
    },

    // ── Preview: kompakter Hub (Kern-Anker Innenkreis, erprobt-Kandidaten aussen) ──
    _layoutPreview() {
      const cx = this.canvasW / 2;
      const cy = this.canvasH / 2;
      const anker = this.nodes.filter((n) => n.kind === 'anker');
      const kand = this.nodes.filter((n) => n.kind === 'kandidat');
      const zentrum = this.nodes.find((z) => z.kind === 'zentrum');
      if (zentrum) { zentrum.x = cx; zentrum.y = cy; }
      const ring = (list, r) => {
        const n = Math.max(1, list.length);
        list.forEach((node, i) => {
          const w = (2 * Math.PI * i) / n - Math.PI / 2;
          node.x = cx + r * Math.cos(w);
          node.y = cy + r * Math.sin(w);
        });
      };
      ring(anker, 78);
      ring(kand, 150);
    },

    _buildViewBox() {
      const pad = this.mode === 'preview' ? 44 : 130; // Reserve für radiale Aussen-Labels
      const vis = this.nodes.filter((n) => this._nodeVisible(n));
      const xs = vis.map((n) => n.x);
      const ys = vis.map((n) => n.y);
      const minX = Math.min(...xs) - pad;
      const maxX = Math.max(...xs) + pad;
      const minY = Math.min(...ys) - pad;
      const maxY = Math.max(...ys) + pad;
      this._svg.attr('viewBox', `${minX} ${minY} ${maxX - minX} ${maxY - minY}`);
    },

    // ── Kanten ──────────────────────────────────────────────────────────
    _drawEdges() {
      const lineGen = d3line().curve(curveNatural);
      const cx = this.canvasW / 2;
      const cy = this.canvasH / 2;
      const g = this._rootG.append('g').attr('data-fa-edges', '');
      this._edgeSel = g
        .selectAll('path')
        .data(this.edges)
        .enter()
        .append('path')
        .attr('fill', 'none')
        .attr('d', (d) => this._edgePath(d, cx, cy, lineGen))
        .attr('stroke', (d) => this._edgeColor(d))
        .attr('stroke-width', (d) => this._edgeWidth(d))
        .attr('stroke-dasharray', (d) => this._edgeDash(d))
        .style('opacity', (d) => this._edgeOpacity(d))
        .style('transition', 'opacity .15s');
    },

    _edgePath(d, cx, cy, lineGen) {
      const s = this._byId.get(d.source);
      const t = this._byId.get(d.target);
      if (!s || !t) return '';
      if (d.kind === 'zentrum_anker') return lineGen([[s.x, s.y], [t.x, t.y]]);
      // Sanfte Wölbung vom Zentrum weg, damit parallele Kanten nicht verschmelzen.
      const mx = (s.x + t.x) / 2;
      const my = (s.y + t.y) / 2;
      const dist = Math.hypot(mx - cx, my - cy) || 1;
      const len = Math.hypot(t.x - s.x, t.y - s.y);
      const bow = len * 0.08;
      const ctrl = [mx + ((mx - cx) / dist) * bow, my + ((my - cy) / dist) * bow];

      return lineGen([[s.x, s.y], ctrl, [t.x, t.y]]);
    },

    _edgeColor(d) {
      if (d.typ) return TYP_FARBE[d.typ] || '#9ca3af';

      return '#9ca3af';
    },

    _edgeDash(d) {
      if (d.kind === 'basis') return '3 4';
      if (d.typ) return TYP_DASH[d.typ];

      return null;
    },

    _edgeWidth(d) {
      if (d.kind === 'zentrum_anker') return 0.8;
      if (d.kind === 'basis') return 1;
      if (d.weight == null) return 1.4;

      return scaleLinear().domain([0.4, 1]).range([1, 3]).clamp(true)(d.weight);
    },

    _edgeOpacity(d) {
      if (d.kind === 'zentrum_anker') return 0.14;
      if (d.kind === 'basis') return 0.5;

      return 0.7;
    },

    // ── Knoten ──────────────────────────────────────────────────────────
    _drawNodes() {
      const g = this._rootG.append('g').attr('data-fa-nodes', '');
      this._nodeSel = g
        .selectAll('g.fa-node')
        .data(this.nodes, (d) => d.id)
        .enter()
        .append('g')
        .attr('class', 'fa-node')
        .attr('transform', (d) => `translate(${d.x},${d.y})`)
        .style('cursor', (d) => (d.kind === 'basisrezept' ? 'pointer' : 'default'))
        .on('mouseenter', (event, d) => this._setHover(d.id))
        .on('mouseleave', () => this._setHover(null))
        .on('click', (event, d) => {
          if (d.kind === 'basisrezept' && typeof this.onNodeClick === 'function') {
            this.onNodeClick(parseInt(String(d.id).replace('b:', ''), 10));
          }
        });

      this._nodeSel
        .append('circle')
        .attr('r', (d) => this._radius(d))
        .attr('fill', (d) => this._fill(d))
        .attr('stroke', (d) => this._stroke(d))
        .attr('stroke-width', (d) => (d.kind === 'zentrum' || (d.kind === 'anker' && d.kern) ? 2.5 : 1.4))
        .style('transition', 'opacity .15s');

      this._nodeSel.append('title').text((d) => this._title(d));

      this._nodeSel
        .append('text')
        .attr('text-anchor', (d) => this._labelAnchor(d))
        .attr('x', (d) => this._labelX(d))
        .attr('y', (d) => this._labelY(d))
        .attr('dominant-baseline', (d) => (this._istRadial(d) ? 'middle' : 'auto'))
        .attr('font-size', (d) => this._fontSize(d))
        .style('paint-order', 'stroke')
        .style('stroke', 'rgba(255,255,255,.9)')
        .style('stroke-width', (d) => (this.mode === 'preview' ? '2.5px' : '3.5px'))
        .style('fill', (d) => this._labelFill(d))
        .style('font-weight', (d) => (d.kind === 'zentrum' ? '600' : '500'))
        .text((d) => this._labelText(d));
    },

    // Schriftgrössen in viewBox-Einheiten. Das Modal skaliert den 1200er-Canvas
    // auf ~0.6 runter, daher sind die Werte gross gewählt, damit auf dem Schirm
    // ~13-16px ankommen. Preview zeigt ~1:1, dort etwas kleiner.
    _fontSize(d) {
      if (this.mode === 'preview') return d.kind === 'zentrum' ? 13 : 11;
      if (d.kind === 'zentrum') return 22;
      if (d.kind === 'anker') return 19;

      return 17; // kandidat + basisrezept
    },

    // Kandidaten + Basisrezepte tragen ihr Label RADIAL nach aussen (Foodpairing-
    // Ordnung: horizontaler Text, links/rechts je Ringseite verankert) — so
    // kollidieren sie nicht, auch wenn viele nebeneinander auf dem Ring sitzen.
    _istRadial(d) {
      return d.kind === 'kandidat' || d.kind === 'basisrezept';
    },

    _radialUnit(d) {
      const dx = d.x - this.canvasW / 2;
      const dy = d.y - this.canvasH / 2;
      const len = Math.hypot(dx, dy) || 1;

      return { ux: dx / len, uy: dy / len, right: dx >= 0 };
    },

    _labelAnchor(d) {
      if (!this._istRadial(d)) return 'middle';

      return this._radialUnit(d).right ? 'start' : 'end';
    },

    _labelX(d) {
      if (!this._istRadial(d)) return 0;
      const u = this._radialUnit(d);

      return u.ux * (this._radius(d) + 5) + (u.right ? 3 : -3);
    },

    _labelY(d) {
      if (!this._istRadial(d)) return this._labelDy(d);
      const u = this._radialUnit(d);

      return u.uy * (this._radius(d) + 5);
    },

    _radius(d) {
      if (d.kind === 'zentrum') return this.mode === 'preview' ? 22 : 30;
      if (d.kind === 'anker') return this.mode === 'preview' ? 8 : 11;
      if (d.kind === 'basisrezept') return 9;

      return 7; // kandidat
    },

    _fill(d) {
      if (d.kind === 'zentrum') return '#fdba74';
      if (d.kind === 'anker') return '#ddd6fe';        // Violett — deutlich von erprobt (rosa) unterschieden
      if (d.kind === 'basisrezept') return '#86efac';

      return TYP_FILL[d.typ] || '#e5e7eb'; // kandidat
    },

    _stroke(d) {
      if (d.kind === 'zentrum') return '#ea580c';
      if (d.kind === 'anker') return '#7c3aed';        // Violett
      if (d.kind === 'basisrezept') return '#16a34a';

      return TYP_FARBE[d.typ] || '#9ca3af'; // kandidat
    },

    _title(d) {
      if (d.kind === 'anker') return (d.label || d.slug || '') + ' (Kern-Anker)';
      if (d.kind === 'kandidat') return `${d.label} — ${d.typ}${d.cover > 1 ? ` · passt zu ${d.cover} Ankern` : ''}`;
      if (d.kind === 'basisrezept') return `${d.label} — komplementär (${d.typ} über ${d.via})`;

      return d.label || '';
    },

    _labelText(d) {
      if (d.kind === 'zentrum') return this.mode === 'preview' ? '' : this._trunc(d.label, 32);
      if (d.kind === 'basisrezept') return this._trunc(d.label, 22);
      if (d.kind === 'anker') return '★ ' + (d.slug || d.label || '');

      return d.slug || d.label || ''; // kandidat
    },

    _trunc(s, n) {
      if (!s) return '';

      return s.length > n ? s.slice(0, n - 1) + '…' : s;
    },

    _labelDy(d) {
      const r = this._radius(d);

      return d.y > this.canvasH / 2 ? r + 12 : -(r + 6);
    },

    _labelFill(d) {
      if (d.kind === 'zentrum') return '#111827';
      if (d.kind === 'anker') return '#6d28d9';        // Violett, passend zum Anker-Knoten
      if (d.kind === 'basisrezept') return '#166534';
      if (d.kind === 'kandidat') return TYP_FARBE[d.typ] || '#4b5563';

      return '#4b5563';
    },

    _setHover(id) {
      this.hoverId = id;
      if (!this._edgeSel) return;
      this._edgeSel.style('opacity', (d) => {
        const base = this._edgeOpacity(d);
        if (id == null) return base;

        return d.source === id || d.target === id ? Math.min(1, base + 0.35) : base * 0.12;
      });
      this._nodeSel.select('circle').style('opacity', (n) => {
        if (id == null || n.id === id) return 1;
        const nb = this.edges.some((e) => (e.source === id && e.target === n.id) || (e.target === id && e.source === n.id));

        return nb ? 1 : 0.35;
      });
    },

    _enableZoom() {
      const z = zoom().scaleExtent([0.5, 4]).on('zoom', (event) => this._rootG.attr('transform', event.transform));
      this._svg.call(z);
    },
  };
}
