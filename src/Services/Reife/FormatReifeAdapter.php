<?php

namespace Platform\FoodAlchemist\Services\Reife;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;

/**
 * Spec 50 · Etappe 7 — Reife eines Formats (Konzeptzusammenstellung mit Editionen).
 *
 * Ein Format ohne Edition ist eine leere Hülle: es gibt nichts, das ein Foodbook oder
 * Angebot einfügen könnte (`foodbook.INSERT_FORMAT`). Die Reife der Editionen selbst misst
 * der {@see ConceptReifeAdapter} — hier zählt nur, ob sie da sind und wohin sie zeigen.
 *
 * Kein Gerüst und keine Ampel-Metrik: die Coverage kennt den Owner-Typ `format` nicht
 * ({@see \Platform\FoodAlchemist\Services\CoverageService::coverage()} fiele still in den
 * Concept-Zweig), und die Datenqualitäts-Ampel hat keine Format-Checks — beides wird als
 * `nicht_messbar` ausgewiesen statt erfunden.
 */
class FormatReifeAdapter extends ContainerReifeAdapter
{
    public function artifactType(): string
    {
        return 'format';
    }

    public function messe(Team $team, int $id): ?array
    {
        $f = FoodAlchemistFormat::visibleToTeam($team)->with('slots')->find($id);
        if ($f === null) {
            return null;
        }

        $luecken = [];
        $erfuellt = [];
        $put = 'foodalchemist.formats.PUT';
        $args = ['id' => (int) $f->id];

        // ── 1. Kopf — was FORMAT_PLAN_FROM_BRIEF setzen würde ──────────────────────────
        $this->kopfFeld($luecken, $erfuellt, $f->consumer_name, 'consumer_name', 'wichtig',
            'Kein Kundenname — das Format druckt mit dem internen Namen.', $put, $args);
        $this->kopfFeld($luecken, $erfuellt, $f->claim, 'claim', 'hinweis', 'Kein Claim.', $put, $args);
        $this->kopfFeld($luecken, $erfuellt, $f->story, 'story', 'hinweis', 'Keine Story — dem Format fehlt der Kundentext.', $put, $args);
        $this->kopfFeld($luecken, $erfuellt, $f->origin, 'origin', 'hinweis',
            'Keine Herkunft (eigen/Gruppe/Kunde) — die IP-Regel „Kunden-IP wird nie adaptiert" ist nicht prüfbar.', $put, $args);

        // ── 2. Struktur — Editionen und Blöcke ──────────────────────────────────────
        $editionen = $f->slots->filter(fn ($s) => ! in_array($s->type, FoodAlchemistFormatSlot::STRUKTUR_TYPEN, true));
        $ohneZiel = $editionen->filter(fn ($s) => $s->concept_id === null);
        $header = $f->slots->filter(fn ($s) => $s->type === 'header');
        $headerOhneTitel = $header->filter(fn ($s) => trim((string) $s->title) === '');

        if ($editionen->isEmpty()) {
            $luecken[] = $this->luecke('keine_edition', 'blockiert',
                'Das Format hat keine Edition — es gibt nichts, das ein Foodbook oder Angebot einfügen könnte.',
                'foodalchemist.format_editions.POST', ['format_id' => (int) $f->id]);
        } else {
            $erfuellt[] = 'edition';
        }
        if ($ohneZiel->isNotEmpty()) {
            $luecken[] = $this->luecke('edition_ohne_konzept', 'blockiert',
                $ohneZiel->count() . ' Editions-Blöcke zeigen auf kein Konzept — kein MCP-Werkzeug setzt concept_id nach; Block löschen (format_editions.DELETE) und neu anlegen.',
                null)
                + ['slot_ids' => $ohneZiel->pluck('id')->map(fn ($i) => (int) $i)->values()->all()];
        }
        if ($editionen->count() >= 2 && $header->isEmpty()) {
            $luecken[] = $this->luecke('header_fehlt', 'hinweis',
                'Zwei oder mehr Editionen ohne Header — die Gliederung fehlt.',
                'foodalchemist.format_blocks.POST', ['format_id' => (int) $f->id, 'type' => 'header']);
        } elseif ($header->isNotEmpty()) {
            $erfuellt[] = 'header';
        }
        if ($headerOhneTitel->isNotEmpty()) {
            $luecken[] = $this->luecke('header_ohne_titel', 'wichtig',
                $headerOhneTitel->count() . ' Header ohne Titel.',
                'foodalchemist.format_blocks.PUT', ['format_id' => (int) $f->id])
                + ['slot_ids' => $headerOhneTitel->pluck('id')->map(fn ($i) => (int) $i)->values()->all()];
        }

        $nichtMessbar = [
            ['code' => 'geruest', 'warum' => 'Formate haben kein Planungs-Gerüst — Coverage misst nur Konzept, Foodbook, Speisekarte.'],
        ];
        $kennzahlen = [
            'editionen' => $editionen->count(),
            'editionen_mit_konzept' => $editionen->count() - $ohneZiel->count(),
            'header' => $header->count(),
            'edition_concept_ids' => $editionen->pluck('concept_id')->filter()->map(fn ($i) => (int) $i)->values()->all(),
        ];

        return $this->ergebnis((string) $f->name, $f->status, $luecken, $erfuellt, $nichtMessbar, $kennzahlen);
    }
}
