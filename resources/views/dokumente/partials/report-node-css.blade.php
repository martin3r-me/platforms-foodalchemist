@php($brand = $brand ?? '#6d28d9')
{{-- Styles des Rezept-Knotens (report-recipe-node). Geteilt von report/speiseplan/
     speisekarte — wer das Partial einbindet, MUSS dieses CSS mit einbinden. --}}
        /* ── Kaskaden-Identität ───────────────────────────────────────────────
           Ein Basisrezept ist keine eigenständige Seite, sondern eine Komponente.
           Sichtbar gemacht durch: Adress-Badge (K3 / K3.1), Typ-Chip, Herkunftszeile
           und einen linken Balken. Einrückung allein trug das nicht — im PDF war sie
           bisher komplett abgeschaltet, dort standen alle Ebenen flach untereinander. */
        .recipe-node { margin: 0 0 4px; }
        .node-head { page-break-after: avoid; page-break-inside: avoid; }
        .node-kicker { margin: 0 0 2px; }
        .node-title { margin: 0 0 1px; }
        .kennzahlen { font-size: 10px; color: #374151; background: #f9fafb; border: 1px solid #e5e7eb; padding: 3px 7px; margin: 4px 0 7px; }
        .addr { display: inline-block; white-space: nowrap; background: {{ $brand }}; color: #fff; font-size: 9.5px; font-weight: 700; letter-spacing: .04em; padding: 1px 6px; margin-right: 5px; vertical-align: 2px; }
        .chip { display: inline-block; border: 1px solid #d1d5db; background: #f9fafb; color: #374151; font-size: 8.5px; text-transform: uppercase; letter-spacing: .06em; padding: 1px 6px; margin-right: 4px; vertical-align: 2px; }
        .chip-dish { background: #111827; border-color: #111827; color: #fff; }
        .chip-base { background: #f5f3ff; border-color: {{ $brand }}; color: #5b21b6; }
        .from-line { font-size: 9.5px; color: #6b7280; margin: 2px 0 6px; }
        .from-line strong { color: #374151; font-weight: 600; }
        .recipe-node.depth-1, .recipe-node.depth-2, .recipe-node.depth-3, .recipe-node.depth-4 {
            border-left: 3px solid #ddd6fe; padding-left: 9px; margin-left: 0; margin-top: 16px; padding-top: 2px;
        }
        .keep { page-break-inside: avoid; }
        .recipe-node.depth-1 { border-left-color: #a78bfa; }
        .recipe-node.depth-2 { border-left-color: #c4b5fd; margin-left: 9px; }
        .recipe-node.depth-3 { border-left-color: #ddd6fe; margin-left: 18px; }
        .recipe-node.depth-4 { border-left-color: #ede9fe; margin-left: 27px; }
        .recipe-node.depth-1 > .node-head .node-title,
        .recipe-node.depth-2 > .node-head .node-title { font-size: 13px; margin: 0; border-top: 0; padding-top: 0; }

        /* ── Anleitung ────────────────────────────────────────────────────────
           Schritte dicht als Tabelle (Nr/Phase links, Text rechts), die Fotos danach
           als EINE Reihe statt je Schritt ein halbseitiger Kasten. Das war der größte
           Papierfresser: 3 Schritte belegten vorher fast eine ganze Seite. */
        .steps { border-top: 1px solid #e5e7eb; margin: 4px 0 6px; }
        .step-row { border-bottom: 1px solid #f3f4f6; padding: 3px 0 3px 0; page-break-inside: avoid; }
        .step-nr { display: inline-block; width: 0.75cm; color: {{ $brand }}; font-weight: 700; }
        .step-nr.hat-foto { width: auto; min-width: 0.42cm; margin-right: 0.28cm; padding: 0 3px; background: {{ $brand }}; color: #fff; text-align: center; }
        .step-phase { font-style: italic; color: #4b5563; }
        .photo-strip { margin: 4px -3px 9px; font-size: 0; page-break-inside: avoid; }
        .photo-strip .ps-item { display: inline-block; width: 24.6%; margin: 0 3px 5px; vertical-align: top; }
        .photo-strip .ps-item img { display: block; width: 100%; max-height: 2.9cm; border: 1px solid #e5e7eb; }
        .photo-strip .ps-cap { display: block; font-size: 8px; color: #6b7280; line-height: 1.2; margin-top: 1px; }
        .photo-strip .ps-cap strong { color: {{ $brand }}; }
        .photo-missing { display: block; border: 1px dashed #d1d5db; background: #f9fafb; color: #9ca3af; font-size: 8px; padding: 10px 4px; text-align: center; }

        /* Zutaten-Tabelle: eigene Rahmen, damit der Kaskaden-Anhang auch in
           Dokumenten ohne generische Tabellen-Styles lesbar bleibt. */
        table.zutaten { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 5px 0 9px; }
        table.zutaten th, table.zutaten td { border: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        table.zutaten th { background: #f9fafb; color: #374151; font-size: 8.5px; text-transform: uppercase; letter-spacing: .05em; }
        table.zutaten td.num, table.zutaten th.num { text-align: right; }
        table.zutaten .sum-line td { border-top: 2px solid #9ca3af; font-weight: 700; background: #f9fafb; }
