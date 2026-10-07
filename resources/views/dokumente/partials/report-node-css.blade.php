@php($brand = $brand ?? '#0a3dd6')
{{-- Styles des Rezept-Knotens (report-recipe-node). Geteilt von report/speiseplan/
     speisekarte — wer das Partial einbindet, MUSS dieses CSS mit einbinden. --}}
        /* ── Kaskaden-Identität ───────────────────────────────────────────────
           Ein Basisrezept ist keine eigenständige Seite, sondern eine Komponente.
           Sichtbar gemacht durch: Adress-Badge (K3 / K3.1), Typ-Chip, Herkunftszeile
           und einen linken Balken. Einrückung allein trug das nicht — im PDF war sie
           bisher komplett abgeschaltet, dort standen alle Ebenen flach untereinander. */
        .recipe-node { margin: 0 0 4px; }
        .node-head { page-break-after: avoid; page-break-inside: avoid; }
        .node-kicker { margin: 10px 0 4px; line-height: 1.5; }
        .node-title { margin: 0 0 1px; }
        .kennzahlen { font-size: 9.5px; color: #4a5466; background: #f3f5f8; border: 1px solid #dde2ea; padding: 2px 6px; margin: 3px 0 5px; }
        .addr { display: inline-block; white-space: nowrap; background: {{ $brand }}; color: #fff; font-size: 9.5px; font-weight: 700; letter-spacing: .04em; padding: 1px 6px; margin-right: 5px; vertical-align: middle; }
        .chip { display: inline-block; border: 1px solid #c5ccd8; background: #f3f5f8; color: #4a5466; font-size: 8.5px; letter-spacing: .02em; padding: 1px 6px; margin-right: 4px; vertical-align: middle; }
        .chip-dish { background: #131a26; border-color: #131a26; color: #fff; }
        .chip-base { background: #fff; border-color: {{ $brand }}; color: {{ $brand }}; }
        .from-line { font-size: 9.5px; color: #657084; margin: 2px 0 6px; }
        .from-line strong { color: #131a26; font-weight: bold; }
        .recipe-node.depth-1, .recipe-node.depth-2, .recipe-node.depth-3, .recipe-node.depth-4 {
            border-left: 3px solid #dde2ea; padding-left: 9px; margin-left: 0; margin-top: 11px; padding-top: 1px;
        }
        .keep { page-break-inside: avoid; }
        .recipe-node.depth-1 { border-left-color: {{ $brand }}; }
        .recipe-node.depth-2 { border-left-color: #8c96a8; margin-left: 9px; }
        .recipe-node.depth-3 { border-left-color: #b4bccb; margin-left: 18px; }
        .recipe-node.depth-4 { border-left-color: #dde2ea; margin-left: 27px; }
        .recipe-node.depth-1 > .node-head .node-title,
        .recipe-node.depth-2 > .node-head .node-title { font-size: 13px; margin: 0; border-top: 0; padding-top: 0; }

        /* ── Anleitung ────────────────────────────────────────────────────────
           Schritte dicht als Tabelle (Nr/Phase links, Text rechts), die Fotos danach
           als EINE Reihe statt je Schritt ein halbseitiger Kasten. Das war der größte
           Papierfresser: 3 Schritte belegten vorher fast eine ganze Seite. */
        .steps { border-top: 1px solid #dde2ea; margin: 4px 0 6px; }
        .step-row { border-bottom: 1px solid #ebeef3; padding: 2px 0; page-break-inside: avoid; }
        .step-nr { display: inline-block; width: 0.75cm; color: {{ $brand }}; font-weight: 700; }
        .step-phase { font-style: italic; color: #4a5466; }
        .photo-strip { margin: 4px -3px 9px; font-size: 0; page-break-inside: avoid; }
        .photo-strip .ps-item { display: inline-block; width: 24.6%; margin: 0 3px 5px; vertical-align: top; }
        .photo-strip .ps-item img { display: block; width: 100%; max-height: 2.9cm; border: 1px solid #dde2ea; }
        .photo-strip .ps-cap { display: block; font-size: 8px; color: #657084; line-height: 1.2; margin-top: 1px; }
        .photo-strip .ps-cap strong { color: {{ $brand }}; }
        .photo-missing { display: block; border: 1px dashed #c5ccd8; background: #f3f5f8; color: #657084; font-size: 8px; padding: 10px 4px; text-align: center; }

        /* Zutaten-Tabelle: eigene Rahmen, damit der Kaskaden-Anhang auch in
           Dokumenten ohne generische Tabellen-Styles lesbar bleibt. */
        table.zutaten { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 3px 0 6px; }
        table.zutaten th, table.zutaten td { border: 1px solid #dde2ea; padding: 2px 5px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        table.zutaten th { background: #f3f5f8; color: #4a5466; font-size: 8.5px; font-weight: bold; letter-spacing: .02em; }
        table.zutaten td.num, table.zutaten th.num { text-align: right; }
        table.zutaten .sum-line td { border-top: 2px solid #657084; font-weight: 700; background: #f3f5f8; }
