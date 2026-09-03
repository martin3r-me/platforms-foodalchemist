<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

/**
 * W1-5 · Schritt 2: schneidet Abschnitte in embeddbare Chunks.
 *
 * DER KERN steht im `embed_text`: `{Kategorie} · {Doc-Titel} · {heading_path}` wandert VOR das
 * Fenster. Damit trägt jeder Vektor seinen Ort im Dokument — genau der Fix für »Discovery
 * surfacet kein prozedurales Wissen«: der Body von §4 nennt kein Gericht, aber
 * »regelwerk · Regelwerk Basisrezepte §4 · §4 Sub-Rezept-Hierarchie« matcht die Anfrage
 * »Steinpilz-Rahmsauce braucht ein Sub-Rezept«.
 *
 * WARUM 900/1400/150 und nicht mehr: die Recall-Probe hat gemessen, dass ein GRÖSSERES Fenster
 * den Recall SENKT (2000 → 8000 Zeichen: 72 % → 68 %). Verdünnung. Ein Chunk soll ein Gedanke
 * sein, kein Kapitel.
 *
 * `pairing` wird bewusst NICHT gechunkt: dessen `embedText()` baut eine kuratierte
 * Oberflächenform (Zutat + Partner-Namen), die durch Zerschneiden zerstört würde. Das ist die
 * einzige Kategorie mit eigenem Embed-Pfad — sie bleibt Doc-granular.
 */
class KnowledgeChunker
{
    /** Zielgrösse. Ein Chunk = ein Gedanke. */
    public const ZIEL = 900;

    /** Harte Obergrenze; darüber wird immer geschnitten. */
    public const MAX = 1400;

    /** Überlappung, damit ein Satz an der Schnittkante nicht seinen Kontext verliert. */
    public const OVERLAP = 150;

    /** Kategorie mit eigenem, kuratiertem Embed-Text — nicht chunken. */
    public const NICHT_CHUNKEN = ['pairing'];

    /** `chunks.entity_key` ist varchar(64) und UNIQUE. */
    public const ENTITY_KEY_MAX = 64;

    /**
     * @param  object  $doc  braucht: id, category, title
     * @param  list<array<string,mixed>>  $sections  Ausgabe des Sectionizers, mit `id` je Abschnitt
     * @return list<array<string,mixed>>
     */
    public function chunk(object $doc, array $sections): array
    {
        $kategorie = (string) ($doc->category ?? '');
        if (in_array($kategorie, self::NICHT_CHUNKEN, true)) {
            return [];
        }

        $docTitel = trim((string) ($doc->title ?? ''));
        $regal = $this->regalFuer($kategorie);
        $out = [];
        $nr = 0;

        foreach ($sections as $s) {
            // `changelog` und `meta` kosten Vektoren und tragen kein Fachwissen. Gemessen:
            // Changelogs sind 33.015 Zeichen = 20 % des importierten Korpus.
            if (in_array((string) ($s['kind'] ?? ''), ['changelog', 'meta'], true)) {
                continue;
            }
            $body = trim((string) ($s['body_md'] ?? ''));
            if ($body === '') {
                continue;
            }

            $kopf = implode(' · ', array_filter([
                $kategorie,
                $docTitel,
                (string) ($s['heading_path'] ?? '') !== '' ? (string) $s['heading_path'] : ($s['title'] ?? null),
            ]));

            foreach ($this->fenster($body) as $f) {
                $key = (int) $doc->id . '#' . str_pad((string) $nr, 3, '0', STR_PAD_LEFT);
                $text = $kopf . "\n\n" . $f['text'];
                $out[] = [
                    'knowledge_section_id' => $s['id'] ?? null,
                    'knowledge_document_id' => (int) $doc->id,
                    'category' => mb_substr($kategorie, 0, 32),
                    'regal' => $regal,
                    'ord' => $nr,
                    'entity_key' => mb_substr($key, 0, self::ENTITY_KEY_MAX),
                    'embed_text' => $text,
                    'char_start' => $f['start'],
                    'char_end' => $f['ende'],
                    'char_count' => mb_strlen($text),
                    'content_hash' => hash('sha256', $text),
                ];
                $nr++;
            }
        }

        return $out;
    }

    /**
     * Drei Regale als METADATA-Filter, nicht als drei `entity_type`s.
     *
     * Grund: `searchMerged` schleift schon über `searchPartitions()` — drei entity_types wären
     * 3 × N Queries UND 3 × N Query-Embeddings. Der Contract liefert `metadata`
     * (EmbeddingStoreContract:42), also filtert man dort.
     */
    private function regalFuer(string $kategorie): string
    {
        return match ($kategorie) {
            'regelwerk' => 'regelwerk',
            'workflow' => 'workflow',
            default => 'material',
        };
    }

    /**
     * Schneidet an der grössten sinnvollen Fuge unterhalb von MAX: erst Absatz, dann Satz,
     * dann hart. Ein Abschnitt ≤ MAX bleibt GENAU EIN Chunk — sonst würde ein kurzes,
     * scharfes § künstlich in zwei schwächere Vektoren zerfallen.
     *
     * @return list<array{text:string,start:int,ende:int}>
     */
    private function fenster(string $text): array
    {
        $len = mb_strlen($text);
        if ($len <= self::MAX) {
            return [['text' => $text, 'start' => 0, 'ende' => $len]];
        }

        $out = [];
        $pos = 0;
        while ($pos < $len) {
            $rest = $len - $pos;
            if ($rest <= self::MAX) {
                $out[] = ['text' => mb_substr($text, $pos), 'start' => $pos, 'ende' => $len];
                break;
            }
            $schnitt = $this->fuge($text, $pos, self::ZIEL, self::MAX);
            $out[] = ['text' => trim(mb_substr($text, $pos, $schnitt - $pos)), 'start' => $pos, 'ende' => $schnitt];
            // Überlappung: der nächste Chunk beginnt VOR der Fuge, damit ein
            // zerschnittener Gedanke in beiden Vektoren vollständig vorkommt.
            $pos = max($pos + 1, $schnitt - self::OVERLAP);
        }

        return $out;
    }

    /** Beste Fuge zwischen ZIEL und MAX ab $pos; fällt auf $pos+MAX zurück. */
    private function fuge(string $text, int $pos, int $ziel, int $max): int
    {
        $suchfeld = mb_substr($text, $pos, $max);
        foreach (["\n\n", "\n", '. ', '; ', ', ', ' '] as $trenner) {
            $letzte = mb_strrpos($suchfeld, $trenner);
            if ($letzte !== false && $letzte >= $ziel * 0.6) {
                return $pos + $letzte + mb_strlen($trenner);
            }
        }

        return $pos + $max;
    }
}
