<?php

namespace Platform\FoodAlchemist\Services\Reife;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Spec 50 · Etappe 7 — Reife eines Speiseplans (GV-Zyklus).
 *
 * Der Speiseplan ist der eine Container ohne MCP-Kopf-Werkzeug: `speiseplan.PLAN_FROM_BRIEF`
 * legt an, danach gibt es nur Linien und Einträge. Kopf-Lücken stehen darum mit `wie: null`
 * — der Agent sieht sie, ohne einen Weg zu erfinden; geschlossen werden sie in der UI.
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

        // ── 1. Kopf — kein PUT-Werkzeug, darum `wie: null` ──────────────────────────
        $this->kopfFeld($luecken, $erfuellt, $sp->name, 'name', 'wichtig', 'Kein Name.', null);
        $this->kopfFeld($luecken, $erfuellt, $sp->start_date, 'start_date', 'wichtig',
            'Kein Startdatum — Einträge lassen sich nicht auf Kalendertage abbilden (AUSROLLEN).', null);
        // `default_pax` hat DB-Default 100 (Migration 2026_08_01_000010) — nie NULL, also hier nicht
        // messbar; der Wert steht in den Kennzahlen, damit der Agent den Default erkennt.
        $this->kopfFeld($luecken, $erfuellt, $sp->budget_wareneinsatz, 'budget_wareneinsatz', 'hinweis',
            'Kein Wareneinsatz-Budget — die Kosten-Ampel des Plans hat kein Soll.', null);
        $this->kopfFeld($luecken, $erfuellt, $sp->outlet_id, 'outlet_id', 'hinweis',
            'Kein Outlet — Betriebs-Kalkulation und Aushang haben keinen Bezug.', null);

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
                $luecken[] = $this->luecke('eintrag_ohne_linie', 'wichtig',
                    $ohneLinie->count() . ' Einträge hängen an keiner Linie.', null) + ['entry_ids' => $ids($ohneLinie)];
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
     * Auffaellig und richtig so: die Kopf-Felder tragen alle `wie: null`. Es gibt kein
     * MCP-Werkzeug, das den Kopf eines Speiseplans setzt — der Agent sieht die Lücke,
     * ohne dass ihm ein Tool-Name vorgegaukelt wird, den er nicht aufrufen kann.
     */
    public function sollAspekte(): array
    {
        $eintraege = 'foodalchemist.speiseplan_eintraege.POST';

        return [
            ['code' => 'name', 'schwere' => 'wichtig', 'wie' => null],
            ['code' => 'start_date', 'schwere' => 'wichtig', 'wie' => null],
            ['code' => 'budget_wareneinsatz', 'schwere' => 'hinweis', 'wie' => null],
            ['code' => 'outlet_id', 'schwere' => 'hinweis', 'wie' => null],
            ['code' => 'keine_linien', 'schwere' => 'blockiert', 'wie' => 'foodalchemist.speiseplan_linien.POST'],
            ['code' => 'keine_eintraege', 'schwere' => 'blockiert', 'wie' => $eintraege],
            ['code' => 'eintrag_ohne_ziel', 'schwere' => 'blockiert', 'wie' => $eintraege],
            ['code' => 'linie_leer', 'schwere' => 'hinweis', 'wie' => $eintraege, 'bedingt' => 'linien_und_eintraege'],
            ['code' => 'eintrag_ohne_linie', 'schwere' => 'wichtig', 'wie' => null, 'bedingt' => 'linien_und_eintraege'],
        ];
    }

}
