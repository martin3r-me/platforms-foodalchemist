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

// Inspire-Umbau — Stern-Stufen der gemessenen Foodpairing-Harmonie:
// stern3 = ★★★ (Inspire L3, Best-Match) · stern2 = ★★ (Inspire L2, Good-Match) ·
// stern1 = ★ (schwächste, aktuell leer). Gold-Gradient auf schwarzem Editor-Grund.
const TYP_FARBE = { stern3: '#fcd34d', stern2: '#f59e0b', stern1: '#94a3b8', kontrast: '#22d3ee' };
const TYP_DASH = { stern3: null, stern2: '5 4', stern1: '2 4', kontrast: '1 4' };
const TYP_FILL = { stern3: 'rgba(252,211,77,.24)', stern2: 'rgba(245,158,11,.18)', stern1: 'rgba(148,163,184,.14)', kontrast: 'rgba(34,211,238,.15)' };
const LEVEL_SYM = { 3: '★★★', 2: '★★', 1: '★', 0: '⇄' };

export function pairingNetzGraph(config) {
  return {
    nodes: config.nodes || [],
    edges: config.edges || [],
    mode: config.mode || 'modal',
    canvasW: config.canvasW || 1000,
    canvasH: config.canvasH || 760,
    onNodeClick: config.onNodeClick || null,
    // Composer: Klick auf einen Kandidaten nimmt dessen Anker in die Auswahl auf.
    // Nur der Composer übergibt den Callback — Detail-Panel/Modal lassen ihn null.
    onKandidatClick: config.onKandidatClick || null,
    // Filter: alle Stern-Stufen an (Default aus meta).
    typAktiv: Object.assign({ stern3: true, stern2: true, stern1: true }, config.typDefault || {}),
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
      // Zeichen-Reihenfolge: Anker-Verbindungen (Brücke + direktes Pairing) ZULETZT = obenauf,
      // damit die violette Beziehung klar über dem Kandidaten-Gewirr liegt (stabiler Sort).
      const zLayer = (d) => (d.kind === 'bridge' || d.kind === 'anker_anker' ? 2 : d.kind === 'zentrum_anker' ? 0 : 1);
      const drawEdges = this.edges.map((e, i) => [e, i]).sort((a, b) => (zLayer(a[0]) - zLayer(b[0])) || (a[1] - b[1])).map((p) => p[0]);
      this._edgeSel = g
        .selectAll('path')
        .data(drawEdges)
        .enter()
        .append('path')
        .attr('fill', 'none')
        .attr('d', (d) => this._edgePath(d, cx, cy, lineGen))
        .attr('stroke', (d) => this._edgeColor(d))
        .attr('stroke-width', (d) => this._edgeWidth(d))
        .attr('stroke-dasharray', (d) => this._edgeDash(d))
        .style('opacity', (d) => this._edgeOpacity(d))
        .style('transition', 'opacity .15s');
      // Hover-Tooltip auf den Anker↔Anker-Kanten: „X ↔ Y · ★★★ Best-Match".
      this._edgeSel.append('title').text((d) => this._edgeTitle(d));

      // Stärke-Marker auf den Anker-Verbindungen (Foodpairing-Stil: Best/Good/Match).
      // Deutlich distinkte Stufen: grosser Grössensprung + Best heller Ring + Match blasser,
      // damit man gross/mittel/klein klar auseinanderhält.
      const tierR = { best: 11, good: 6.5, match: 3.2 };
      const tierOp = { best: 1, good: 0.82, match: 0.5 };
      const marks = drawEdges.filter((d) => this._edgeTier(d) && this._edgeMidpoint(d, cx, cy));
      const mSel = g.selectAll('circle.fa-strength').data(marks).enter().append('circle')
        .attr('class', 'fa-strength')
        .attr('cx', (d) => this._edgeMidpoint(d, cx, cy)[0])
        .attr('cy', (d) => this._edgeMidpoint(d, cx, cy)[1])
        .attr('r', (d) => tierR[this._edgeTier(d)])
        .attr('fill', (d) => this._edgeColor(d))
        .attr('stroke', (d) => (this._edgeTier(d) === 'best' ? '#ede9fe' : '#0b1120'))
        .attr('stroke-width', (d) => (this._edgeTier(d) === 'best' ? 2.2 : 1))
        .style('opacity', (d) => tierOp[this._edgeTier(d)]);
      mSel.append('title').text((d) => this._edgeTitle(d));
    },

    // Stärke-Stufe einer Anker-Verbindung → Best/Good/Match (Foodpairing-3-Stufen).
    // Direktes Pairing über die Stern-Stufe; Brücke über die Anzahl geteilter Partner.
    _edgeTier(d) {
      if (d.kind === 'anker_anker') return d.level >= 3 ? 'best' : (d.level >= 2 ? 'good' : 'match');
      if (d.kind === 'bridge') {
        const n = d.shared || 0;

        return n >= 5 ? 'best' : (n >= 3 ? 'good' : 'match');
      }

      return null;
    },

    // Punkt auf der (leicht gewölbten) Verbindungslinie — Sitz des Stärke-Markers.
    _edgeMidpoint(d, cx, cy) {
      const s = this._byId.get(d.source);
      const t = this._byId.get(d.target);
      if (!s || !t) return null;
      const mx = (s.x + t.x) / 2;
      const my = (s.y + t.y) / 2;
      const dist = Math.hypot(mx - cx, my - cy) || 1;
      const len = Math.hypot(t.x - s.x, t.y - s.y);
      const bow = len * 0.08;

      return [mx + ((mx - cx) / dist) * bow, my + ((my - cy) / dist) * bow];
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
      if (d.kind === 'bridge') return '#a78bfa'; // Brücke (geteilte Partner) = anker-violett
      if (d.typ) return TYP_FARBE[d.typ] || '#9ca3af';

      return '#9ca3af';
    },

    _edgeDash(d) {
      if (d.kind === 'anker_anker') return null; // direktes Pairing → durchgezogen
      if (d.kind === 'bridge') return null;       // Brücke → durchgezogen (Dicke trägt die Aussage)
      if (d.kind === 'basis') return '3 4';
      if (d.typ) return TYP_DASH[d.typ];

      return null;
    },

    _edgeWidth(d) {
      // Anker↔Anker: Dicke nach Stern-Stufe (★★★ dick … ★ dünn) — deine 1/2/3-Matrix.
      if (d.kind === 'anker_anker') return { 3: 3.2, 2: 2.2, 1: 1.3 }[d.level] || 2;
      // Brücke: Dicke nach Anzahl GETEILTER Partner (mehr gemeinsame Partner = stärkere Verbindung).
      if (d.kind === 'bridge') return scaleLinear().domain([1, 6]).range([1.2, 4.2]).clamp(true)(d.shared || 1);
      if (d.kind === 'zentrum_anker') return 0.8;
      if (d.kind === 'basis') return 1;
      if (d.weight == null) return 1.4;

      return scaleLinear().domain([0.4, 1]).range([1, 3]).clamp(true)(d.weight);
    },

    _edgeOpacity(d) {
      if (d.kind === 'anker_anker') return 0.85; // Kern-Aussage → präsent (vs. zentrum_anker 0.14)
      if (d.kind === 'bridge') return 0.78;
      if (d.kind === 'zentrum_anker') return 0.14;
      if (d.kind === 'basis') return 0.5;

      return 0.7;
    },

    // Tooltip der inneren Kanten: direktes Pairing (anker_anker) ODER Brücke über geteilte Partner.
    _edgeTitle(d) {
      const s = this._byId.get(d.source);
      const t = this._byId.get(d.target);
      if (!s || !t) return '';
      const name = (n) => n.label || n.slug || '';
      if (d.kind === 'anker_anker') {
        const wort = { 3: 'Best-Match', 2: 'Good-Match', 1: 'Match' }[d.level] || '';

        return `${name(s)} ↔ ${name(t)} · ${LEVEL_SYM[d.level] || ''} ${wort}`.trim();
      }
      if (d.kind === 'bridge') {
        const liste = (d.partners || []).join(', ');

        return `${name(s)} ↔ ${name(t)} · verbunden über ${d.shared} Partner${liste ? ': ' + liste : ''}`;
      }

      return '';
    },

    // Klickbar (Cursor + Click): Basisrezept immer (öffnet Rezept), Kandidat nur wenn
    // ein onKandidatClick vorliegt (Composer-Modus). Alles andere ist inert.
    _clickable(d) {
      if (d.kind === 'basisrezept') return true;
      if (d.kind === 'kandidat') return typeof this.onKandidatClick === 'function';

      return false;
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
        .style('cursor', (d) => this._clickable(d) ? 'pointer' : 'default')
        .on('mouseenter', (event, d) => this._setHover(d.id))
        .on('mouseleave', () => this._setHover(null))
        .on('click', (event, d) => {
          if (d.kind === 'basisrezept' && typeof this.onNodeClick === 'function') {
            this.onNodeClick(parseInt(String(d.id).replace('b:', ''), 10));
          } else if (d.kind === 'kandidat' && typeof this.onKandidatClick === 'function') {
            this.onKandidatClick(parseInt(String(d.id).replace('k:', ''), 10));
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
        .attr('dominant-baseline', (d) => (this._istRadial(d) || d.kind === 'zentrum' ? 'middle' : 'auto'))
        .attr('font-size', (d) => this._fontSize(d))
        .style('paint-order', 'stroke')
        .style('stroke', 'rgba(2,6,23,.72)') // dunkler Halo für helle Schrift auf schwarzem Grund
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
      // Brücken-Zutat (bedient ≥2 Kern-Anker) = grösser, als Verbindungs-Hub sichtbar.
      if (d.kind === 'kandidat' && (d.cover || 0) >= 2) return 10;

      return 7; // kandidat
    },

    _fill(d) {
      if (d.kind === 'zentrum') return '#fdba74';
      if (d.kind === 'anker') return d.orphan ? '#fde68a' : '#ddd6fe'; // Orphan = warm (passt nicht), sonst Violett
      if (d.kind === 'basisrezept') return '#86efac';

      return TYP_FILL[d.typ] || '#e5e7eb'; // kandidat
    },

    _stroke(d) {
      if (d.kind === 'zentrum') return '#ea580c';
      if (d.kind === 'anker') return d.orphan ? '#d97706' : '#7c3aed'; // Orphan = Bernstein-Warn-Ring
      if (d.kind === 'basisrezept') return '#16a34a';

      return TYP_FARBE[d.typ] || '#9ca3af'; // kandidat
    },

    _title(d) {
      if (d.kind === 'anker') {
        const base = (d.label || d.slug || '') + ' (Kern-Anker)';
        if (d.orphan) return base + ' — passt (noch) nicht zu den anderen';
        if (d.fit != null) return base + ` — Fit ${d.fit}%`;

        return base;
      }
      if (d.kind === 'kandidat') {
        const sym = LEVEL_SYM[d.level] || d.typ;
        return `${d.label} — ${sym}${d.cover > 1 ? ` · passt zu ${d.cover} Ankern` : ''}`;
      }
      if (d.kind === 'basisrezept') return `${d.label} — komplementär (${d.typ} über ${d.via})`;

      return d.label || '';
    },

    _labelText(d) {
      if (d.kind === 'zentrum') return ''; // Titel steht schon im Modal-Header / ist aus Kontext bekannt
      if (d.kind === 'basisrezept') return this._trunc(d.label, 38);
      if (d.kind === 'anker') return d.label || d.slug || '';

      return d.label || d.slug || ''; // kandidat — Anzeigename (display_de) statt technischem Slug
    },

    _trunc(s, n) {
      if (!s) return '';

      return s.length > n ? s.slice(0, n - 1) + '…' : s;
    },

    _labelDy(d) {
      const r = this._radius(d);
      if (d.kind === 'zentrum') return 0; // Titel MITTIG auf dem Quell-Kreis (freie Mitte, kollidiert mit keinem Anker)

      return d.y > this.canvasH / 2 ? r + 12 : -(r + 6);
    },

    _labelFill(d) {
      // Helle Schrift auf schwarzem Editor-Grund.
      if (d.kind === 'zentrum') return '#f8fafc';
      if (d.kind === 'anker') return '#c4b5fd';        // helles Violett, passend zum Anker-Knoten
      if (d.kind === 'basisrezept') return '#86efac';
      if (d.kind === 'kandidat') return TYP_FARBE[d.typ] || '#cbd5e1';

      return '#cbd5e1';
    },

    _setHover(id) {
      this.hoverId = id;
      if (!this._edgeSel) return;
      this._edgeSel.style('opacity', (d) => {
        const base = this._edgeOpacity(d);
        if (id == null) return base;

        return d.source === id || d.target === id ? Math.min(1, base + 0.45) : base * 0.05;
      });
      const isDimmed = (n) => {
        if (id == null || n.id === id) return false;
        const nb = this.edges.some((e) => (e.source === id && e.target === n.id) || (e.target === id && e.source === n.id));

        return !nb;
      };
      this._nodeSel.select('circle').style('opacity', (n) => (isDimmed(n) ? 0.15 : 1));
      this._nodeSel.select('text').style('opacity', (n) => (isDimmed(n) ? 0.2 : 1));
      this._nodeSel
        .select('circle')
        .attr('stroke-width', (d) => {
          const dflt = d.kind === 'zentrum' || (d.kind === 'anker' && d.kern) ? 2.5 : 1.4;

          return id != null && d.id === id ? dflt + 1.5 : dflt;
        });
    },

    _enableZoom() {
      const z = zoom().scaleExtent([0.5, 4]).on('zoom', (event) => this._rootG.attr('transform', event.transform));
      this._svg.call(z);
    },
  };
}
