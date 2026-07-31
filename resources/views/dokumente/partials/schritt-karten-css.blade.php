{{-- Spec 27 Phase 4 — Stil der Schritt-Karten; eine Quelle für alle Druckansichten.
     Bewusst nur Tabellen-freies Block-Layout: DomPDF rendert Flexbox nicht, darum
     float/inline-block statt display:flex. --}}
.anleitung { margin-top: 6px; }
.anleitung-phase { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #6d28d9; font-weight: bold; margin: 8px 0 2px; }
.schritt { margin-bottom: 6px; page-break-inside: avoid; }
.schritt:after { content: ""; display: block; clear: both; }
.schritt-nr { float: left; width: 18px; height: 18px; margin-right: 8px; text-align: center; line-height: 18px;
    font-size: 10px; font-weight: bold; color: #6d28d9; background: #ede9fe; border-radius: 9px; }
.schritt-body { margin-left: 26px; }
.schritt-text { font-size: 12px; }
.schritt-fotos { margin-top: 4px; }
.schritt-foto { display: inline-block; margin: 0 6px 6px 0; vertical-align: top; }
.schritt-foto img { width: 120px; height: 84px; object-fit: cover; border: 1px solid #e5e7eb; border-radius: 4px; }
.schritt-foto .cap { display: block; font-size: 9px; color: #9ca3af; max-width: 120px; }
.zubereitung-fallback { white-space: pre-line; color: #6b7280; font-size: 11px; margin-top: 4px; }
