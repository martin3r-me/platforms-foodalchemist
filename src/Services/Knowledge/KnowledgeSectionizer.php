<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

/**
 * W1-5 · Schritt 1: zerlegt ein Wissens-Dokument in §-genaue Abschnitte.
 *
 * WARUM ÜBERHAUPT: `KnowledgeEmbeddingService` embeddet heute EIN Doc als EINEN Vektor über
 * `title + mb_substr(content_md, 0, 2000)`. Gemessen (Recall-Probe 2026-09-03) ist das Fenster
 * NICHT der Engpass — 8000 statt 2000 machte den Recall schlechter (72 % → 68 %, Verdünnung).
 * Der Engpass ist die Körnung: ein Mittelwert-Vektor über ein ganzes Dossier hat keine scharfe
 * Signatur. Deshalb Abschnitte → Chunks, und der `heading_path` wandert MIT in den Vektor:
 * »§4 Sub-Rezept-Hierarchie« nennt kein Gericht, matcht über den Pfad aber »Steinpilz-Rahmsauce«.
 *
 * REGELBASIS IST GEMESSEN, nicht geraten (demo, 598 aktive Docs, 2.192.967 Zeichen):
 *   · 48 von 49 `regelwerk`-Docs tragen `## §n` IM BODY — der Plan nahm an, die §-Nummer stünde
 *     nur im Titel und baute darum eine Absatz-Notregel als Hauptpfad ein. Falsch: die
 *     Überschriften-Regel trägt den Regelwerks-Korpus.
 *   · alle übrigen Kategorien haben `##`-Überschriften, nur `## §` fehlt.
 *   · NUR 5 Docs im ganzen Korpus haben überhaupt keine `##` — die Absatz-Notregel ist ein
 *     Randfall, kein Hauptpfad.
 *
 * KEINE DB-BERÜHRUNG: `sectionize()` ist rein. Das macht die Regeln ohne Migration testbar und
 * erlaubt den `--dry-run`, den die Spec vor dem ersten Schreiben verlangt.
 */
class KnowledgeSectionizer
{
    /** Absatz-Notregel für Docs ohne jede Überschrift (gemessen: 5 Stück). */
    public const ABSATZ_MAX_CHARS = 2500;

    /** `sections.anchor` ist varchar(32) — MySQL erzwingt das, SQLite nicht. Defensiv kappen. */
    public const ANCHOR_MAX = 32;

    /** `sections.heading_path` ist varchar(255). */
    public const HEADING_PATH_MAX = 255;

    /**
     * Zerlegt ein Doc in Abschnitte.
     *
     * @param  object  $doc  braucht: id, slug, category, title, content_md
     * @return list<array{ord:int,anchor:string,heading_path:string,kind:string,title:?string,body_md:string,char_count:int,content_hash:string}>
     */
    public function sectionize(object $doc): array
    {
        $md = (string) ($doc->content_md ?? '');
        $kategorie = (string) ($doc->category ?? '');
        $abschnitte = [];
        $ord = 0;

        [$frontmatter, $rest] = $this->trenneFrontmatter($md);
        if ($frontmatter !== null) {
            $abschnitte[] = $this->baue($ord++, 'meta', '', 'meta', null, $frontmatter);
        }

        $treffer = [];
        preg_match_all('/^(#{2,6})[ \t]+(.+?)[ \t]*$/mu', $rest, $treffer, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        if ($treffer === []) {
            // Randfall (5 Docs): keine Überschrift → Absätze. Im Regelwerk bleibt der Inhalt
            // `normativ`, sonst würde `ladeRegelwerke(kind='normativ')` für dieses Doc LEER
            // liefern — und `ConformanceService::isEmpty() → return ''` merkt das nicht.
            foreach ($this->absaetze($rest) as $i => $stueck) {
                $abschnitte[] = $this->baue(
                    $ord++,
                    'abs-' . ($i + 1),
                    '',
                    $kategorie === 'regelwerk' ? 'normativ' : 'prosa',
                    null,
                    $stueck,
                );
            }

            return $abschnitte;
        }

        // Text VOR der ersten Überschrift (Status-Blöcke, Provenienz-Vorspann) — eigener
        // `lead`-Abschnitt, damit er nicht dem ersten § zugeschlagen wird und dessen
        // Vektor verwässert.
        $ersterOffset = (int) $treffer[0][0][1];
        $lead = trim(mb_strcut($rest, 0, $ersterOffset));
        if ($lead !== '') {
            $abschnitte[] = $this->baue($ord++, 'lead', '', $this->kindFuer($kategorie, '', $lead), null, $lead);
        }

        $pfad = [];                                   // Ebene => Überschrift, für heading_path
        foreach ($treffer as $i => $t) {
            $ebene = mb_strlen($t[1][0]);             // ## = 2, ### = 3, …
            $ueberschrift = trim($t[2][0]);
            $start = (int) $t[0][1] + strlen($t[0][0]);
            $ende = isset($treffer[$i + 1]) ? (int) $treffer[$i + 1][0][1] : strlen($rest);
            $body = trim(substr($rest, $start, $ende - $start));

            $pfad = array_filter($pfad, fn ($e) => $e < $ebene, ARRAY_FILTER_USE_KEY);
            $pfad[$ebene] = $ueberschrift;
            ksort($pfad);
            $headingPath = implode(' > ', $pfad);

            // Ein Abschnitt ohne eigenen Text ist eine reine Gliederungs-Überschrift
            // (z. B. »## §1 Naming-Syntax« direkt vor »### §1.0«). Der Vektor wäre leer;
            // der Pfad lebt in den Kind-Abschnitten weiter.
            if ($body === '') {
                continue;
            }

            $abschnitte[] = $this->baue(
                $ord++,
                $this->anchorFuer($ueberschrift),
                $headingPath,
                $this->kindFuer($kategorie, $ueberschrift, $body),
                $ueberschrift,
                $body,
            );
        }

        return $abschnitte;
    }

    /**
     * `kind` — die Klassifikation, an der `ConformanceService::ladeRegelwerke()` hängt.
     *
     * ENTSCHEIDENDE REGEL: im `regelwerk` ist ALLES normativ ausser Changelog/Notizen. Zwei
     * Belege, beide aus dem Bestand und nicht aus dem Gefühl:
     *   · W3-3-Messung: die »Referenz«-Tabellen der Regelwerke sind normativ (21 % des
     *     Prüfblocks) — eine »tabellenlastig → referenz«-Regel würde also genau das
     *     Pflichtwissen abwerten. 169 Docs sind tabellenlastig.
     *   · CLAUDE.md zum GP-Regelwerk: »Beispiele pro Warengruppe (§19) sind verbindlich«.
     *     Ein `kind='beispiel'` wäre im Regelwerk also eine Herabstufung mit Folgen.
     */
    private function kindFuer(string $kategorie, string $ueberschrift, string $body): string
    {
        if ($this->istChangelog($ueberschrift)) {
            return 'changelog';
        }
        if ($kategorie === 'regelwerk') {
            return 'normativ';
        }
        if (preg_match('/^(anhang|referenz|quellen|literatur)/iu', $ueberschrift) === 1) {
            return 'referenz';
        }
        if (preg_match('/^(beispiel|beispiele)/iu', $ueberschrift) === 1) {
            return 'beispiel';
        }

        return 'prosa';
    }

    private function istChangelog(string $ueberschrift): bool
    {
        return preg_match('/^(changelog|notizen|änderungen|aenderungen|historie)/iu', $ueberschrift) === 1;
    }

    /**
     * `anchor` — die adressierbare Kennung. `§6.1` wenn die Überschrift eine §-Nummer trägt,
     * sonst ein gekappter Slug. NICHT aus dem Doc-Slug abgeleitet: dort ist das §-Segment
     * ambig (`…-10-anti-patterns` = §10 vs. `…-10-12-naming…` = §1.0–§1.2).
     */
    private function anchorFuer(string $ueberschrift): string
    {
        if ($this->istChangelog($ueberschrift)) {
            return 'changelog';
        }
        if (preg_match('/§\s*(\d+(?:\.\d+)*[a-z]?)/u', $ueberschrift, $m) === 1) {
            return mb_substr('§' . $m[1], 0, self::ANCHOR_MAX);
        }
        $slug = preg_replace('/[^a-z0-9]+/u', '-', mb_strtolower($ueberschrift));

        return mb_substr(trim((string) $slug, '-'), 0, self::ANCHOR_MAX) ?: 'abschnitt';
    }

    /** @return array{0: ?string, 1: string} Frontmatter (ohne Marker) und Rest. */
    private function trenneFrontmatter(string $md): array
    {
        $md = ltrim($md);
        if (! str_starts_with($md, '---')) {
            return [null, $md];
        }
        $ende = mb_strpos($md, "\n---", 3);
        if ($ende === false) {
            return [null, $md];
        }
        $fm = trim(mb_substr($md, 3, $ende - 3));
        $rest = ltrim(mb_substr($md, $ende + 4));

        return [$fm !== '' ? $fm : null, $rest];
    }

    /** @return list<string> */
    private function absaetze(string $text): array
    {
        $out = [];
        $puffer = '';
        foreach (preg_split('/\n{2,}/u', trim($text)) ?: [] as $absatz) {
            $absatz = trim((string) $absatz);
            if ($absatz === '') {
                continue;
            }
            if ($puffer !== '' && mb_strlen($puffer) + mb_strlen($absatz) + 2 > self::ABSATZ_MAX_CHARS) {
                $out[] = $puffer;
                $puffer = $absatz;

                continue;
            }
            $puffer = $puffer === '' ? $absatz : $puffer . "\n\n" . $absatz;
        }
        if ($puffer !== '') {
            $out[] = $puffer;
        }

        return $out === [] ? [trim($text)] : $out;
    }

    /** @return array{ord:int,anchor:string,heading_path:string,kind:string,title:?string,body_md:string,char_count:int,content_hash:string} */
    private function baue(int $ord, string $anchor, string $headingPath, string $kind, ?string $title, string $body): array
    {
        return [
            'ord' => $ord,
            'anchor' => mb_substr($anchor, 0, self::ANCHOR_MAX),
            'heading_path' => mb_substr($headingPath, 0, self::HEADING_PATH_MAX),
            'kind' => $kind,
            'title' => $title !== null ? mb_substr($title, 0, 255) : null,
            'body_md' => $body,
            'char_count' => mb_strlen($body),
            'content_hash' => hash('sha256', $kind . '|' . $anchor . '|' . $body),
        ];
    }
}
