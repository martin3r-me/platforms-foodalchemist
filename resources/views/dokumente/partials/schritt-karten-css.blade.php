{{-- Spec 27 Phase 4 — Stil der Schritt-Karten; eine Quelle für alle Druckansichten
     (Produktionsschein, Produktionsblatt, Postenzettel).
     fa-pass Druck-Muster (2026-10-05): Schritte als Tabelle (Nummer links, Text rechts) statt
     float-Kreis — DomPDF setzte den Kreis über den Text. Farben kommen aus der Palette `$c`
     der einbindenden Vorlage; die Rückfallwerte unten sind dieselbe Muster-Palette. --}}
@php($sk = ($c ?? []) + ['accent' => '#0a3dd6', 'accentSoft' => '#e6edfe', 'ink' => '#131a26', 'ink2' => '#4a5466', 'ink3' => '#657084', 'line' => '#dde2ea'])
.anleitung { margin: 4px 0 6px; }
table.schritte { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 0; border-top: 1px solid {{ $sk['line'] }}; }
table.schritte td { border: 0; border-bottom: 1px solid {{ $sk['line'] }}; padding: 3px 5px 3px 0; vertical-align: top; overflow-wrap: anywhere; }
table.schritte tr { page-break-inside: avoid; }
table.schritte td.schritt-nr { width: 6%; text-align: right; padding-right: 7px; color: {{ $sk['accent'] }}; font-weight: bold; font-size: 11px; }
table.schritte td.schritt-text { font-size: 10.5px; color: {{ $sk['ink'] }}; }
table.schritte td.phase-nr { background: {{ $sk['accentSoft'] }}; }
table.schritte td.anleitung-phase { background: {{ $sk['accentSoft'] }}; color: {{ $sk['accent'] }}; font-weight: bold; font-size: 9.5px; padding: 2px 6px; }
.schritt-fotos { margin-top: 4px; font-size: 0; }
/* Fotos über die Höhe begrenzt (Breite folgt dem Seitenverhältnis): Hochformat wird sonst
   seitenhoch, eine feste Breite+Höhe verzerrt in DomPDF (kein object-fit). */
.schritt-foto { display: inline-block; margin: 0 6px 3px 0; vertical-align: top; font-size: 8px; }
.schritt-foto img { display: block; height: 2.4cm; width: auto; max-width: 5cm; border: 1px solid {{ $sk['line'] }}; }
.schritt-foto .cap { display: block; max-width: 3.6cm; font-size: 8px; line-height: 1.2; color: {{ $sk['ink3'] }}; margin-top: 1px; }
.zubereitung-fallback { white-space: pre-line; color: {{ $sk['ink2'] }}; font-size: 10px; margin: 4px 0 6px; padding: 3px 0; border-top: 1px solid {{ $sk['line'] }}; }
.zubereitung-fallback .zf-phase { color: {{ $sk['accent'] }}; font-weight: bold; }
