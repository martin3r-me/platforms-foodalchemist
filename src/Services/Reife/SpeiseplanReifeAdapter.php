<?php

namespace Platform\FoodAlchemist\Services\Reife;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Spec 50 · Etappe 7 — Reife eines Speiseplans (GV-Zyklus).
 *
 * Spec 57 · 0.8: der Kopf hat seit b369eebc ein MCP-Werkzeug (`foodalchemist.speiseplaene.PUT`) —
 * die Kopf-Lücken zeigen darum darauf. Vorher standen sie mit `wie: null`, als gäbe es keins.
 *
 * C-9 (Spec): Header-/Text-Struktur ist im Speiseplan nicht anwendbar (Raster aus Woche ×
 * Mahlzeit, keine Dramaturgie) → `nicht_messbar` statt Lücke. Coverage kennt den Owner-Typ
 * `speiseplan` nicht (fiele still in den Concept-Zweig) → ebenfalls `nicht_messbar`.
 */
class SpeiseplanReifeAdapter extends ContainerReifeAdapter
{
    public function artifactType(): string
    {
        return 'speiseplan';
    }

    public function messe(Team $team, int $id): ?array
    {
        $sp = FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->find($id);
        if ($sp === null) {
            return null;
        }

        $luecken = [];
        $erfuellt = [];
        $ids = fn ($c) => $c->pluck('id')->map(fn ($i) => (int) $i)->values()->all();

        // ── 1. Kopf — über speiseplaene.PUT pflegbar ──────────────────────────────────
        $put = 'foodalchemist.speiseplaene.PUT';
        $putArgs = ['id' => (int) $sp->id];
        $this->kopfFeld($luecken, $erfuellt, $sp->name, 'name', 'wichtig', 'Kein Name.', $put, $putArgs);
        $this->kopfFeld($luecken, $erfuellt, $sp->start_date, 'start_date', 'wichtig',
            'Kein Startdatum — Einträge lassen sich nicht auf Kalendertage abbilden (AUSROLLEN).', $put, $putArgs);
        // `default_pax` hat DB-Default 100 (Migration 2026_08_01_000010) — nie NULL, also hier nicht
        // messbar; der Wert steht in den Kennzahlen, damit der Agent den Default erkennt.
        $this->kopfFeld($luecken, $erfuellt, $sp->budget_wareneinsatz, 'budget_wareneinsatz', 'hinweis',
            'Kein Wareneinsatz-Budget — die Kosten-Ampel des Plans hat kein Soll.', $put, $putArgs);
        $this->kopfFeld($luecken, $erfuellt, $sp->outlet_id, 'outlet_id', 'hinweis',
            'Kein Outlet — Betriebs-Kalkulation und Aushang haben keinen Bezug.', $put, $putArgs);

        // ── 2. Struktur — Linien und Einträge ───────────────────────────────────────
        $linien = $sp->lines;
        $eintraege = $sp->entries;
        $args = ['speiseplan_id' => (int) $sp->id];

        if ($linien->isEmpty()) {
            $luecken[] = $this->luecke('keine_linien', 'blockiert',
                'Der Plan hat keine Linie — ohne Zeile kein Raster.',
                'foodalchemist.speiseplan_linien.POST', $args);
        } else {
            $erfuellt[] = 'linien';
        }
        if ($eintraege->isEmpty()) {
            $luecken[] = $this->luecke('keine_eintraege', 'blockiert',
                'Der Plan hat keinen Eintrag — es gibt nichts zu produzieren und nichts auszuhängen.',
                'foodalchemist.speiseplan_eintraege.POST', $args);
        } else {
            $erfuellt[] = 'eintraege';
        }
        $ohneZiel = $eintraege->filter(fn ($e) => $e->concept_id === null && $e->package_id === null && $e->sales_recipe_id === null);
        if ($ohneZiel->isNotEmpty()) {
            $luecken[] = $this->luecke('eintrag_ohne_ziel', 'blockiert',
                $ohneZiel->count() . ' Einträge zeigen auf kein Konzept, Paket oder Gericht.',
                'foodalchemist.speiseplan_eintraege.POST', $args) + ['entry_ids' => $ids($ohneZiel)];
        }
        if ($linien->isNotEmpty() && $eintraege->isNotEmpty()) {
            $linienIds = $linien->pluck('id')->map(fn ($i) => (int) $i);
            $leereLinien = $linien->filter(fn ($l) => $eintraege->where('line_id', $l->id)->isEmpty());
            if ($leereLinien->isNotEmpty()) {
                $luecken[] = $this->luecke('linie_leer', 'hinweis',
                    $leereLinien->count() . ' Linien ohne Eintrag.',
                    'foodalchemist.speiseplan_eintraege.POST', $args) + ['line_ids' => $ids($leereLinien)];
            } else {
                $erfuellt[] = 'linie_leer';
            }
            $ohneLinie = $eintraege->filter(fn ($e) => $e->line_id === null || ! $linienIds->contains((int) $e->line_id));
            if ($ohneLinie->isNotEmpty()) {
                // Spec 57 · Paket 5: lösbar über speiseplan_eintraege.PUT (line_id setzen).
                $luecken[] = $this->luecke('eintrag_ohne_linie', 'wichtig',
                    $ohneLinie->count() . ' Einträge hängen an keiner Linie.', 'foodalchemist.speiseplan_eintraege.PUT') + ['entry_ids' => $ids($ohneLinie)];
            }
        }

        $nichtMessbar = [
            ['code' => 'struktur_text', 'warum' => 'C-9: Header/Text sind im Speiseplan-Raster nicht anwendbar.'],
            ['code' => 'geruest', 'warum' => 'Coverage kennt den Owner-Typ speiseplan nicht — kein Soll-Vergleich.'],
            ['code' => 'ampel', 'warum' => 'Die Datenqualitäts-Ampel hat keine Speiseplan-Metriken.'],
            ['code' => 'default_pax', 'warum' => 'DB-Default 100 — „nicht gesetzt" ist vom bewussten Wert nicht unterscheidbar.'],
        ];
        $kennzahlen = [
            'linien' => $linien->count(),
            'eintraege' => $eintraege->count(),
            'wochen' => $sp->cycle_weeks !== null ? (int) $sp->cycle_weeks : null,
            'default_pax' => $sp->default_pax !== null ? (int) $sp->default_pax : null,
        ];

        return $this->ergebnis((string) $sp->name, $sp->status, $luecken, $erfuellt, $nichtMessbar, $kennzahlen);
    }

    /**
     * Spec 50 · E-3 — {@see ReifeAdapter::sollAspekte()}.
     *
     * Spec 57 · 0.8: die Kopf-Felder zeigen auf `speiseplaene.PUT` (gibt es seit b369eebc).
     */
    public function sollAspekte(): array
    {
        $eintraege = 'foodalchemist.speiseplan_eintraege.POST';
        $put = 'foodalchemist.speiseplaene.PUT';

        return [
            ['code' => 'name', 'schwere' => 'wichtig', 'wie' => $put],
            ['code' => 'start_date', 'schwere' => 'wichtig', 'wie' => $put],
            ['code' => 'budget_wareneinsatz', 'schwere' => 'hinweis', 'wie' => $put],
            ['code' => 'outlet_id', 'schwere' => 'hinweis', 'wie' => $put],
            ['code' => 'keine_linien', 'schwere' => 'blockiert', 'wie' => 'foodalchemist.speiseplan_linien.POST'],
            ['code' => 'keine_eintraege', 'schwere' => 'blockiert', 'wie' => $eintraege],
            ['code' => 'eintrag_ohne_ziel', 'schwere' => 'blockiert', 'wie' => $eintraege],
            ['code' => 'linie_leer', 'schwere' => 'hinweis', 'wie' => $eintraege, 'bedingt' => 'linien_und_eintraege'],
            ['code' => 'eintrag_ohne_linie', 'schwere' => 'wichtig', 'wie' => 'foodalchemist.speiseplan_eintraege.PUT', 'bedingt' => 'linien_und_eintraege'],
        ];
    }

}
