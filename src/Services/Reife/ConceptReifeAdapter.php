<?php

namespace Platform\FoodAlchemist\Services\Reife;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptOneShotService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\CoverageService;
use Platform\FoodAlchemist\Services\DataQualityService;

/**
 * Spec 50 · Etappe 7 — Reife eines Konzepts (kind=concept, auch Pakete).
 *
 * Komponiert {@see ConceptOneShotService::luecken()} (Kopf + Header-Titel + Slot-Wording —
 * genau das, was `concepts.ENRICH` füllen würde), die Struktur der Slots, die Coverage gegen
 * das Planungs-Gerüst und die Konzept-Metriken der Datenqualitäts-Ampel.
 *
 * Struktur-Regeln (C-2/C-3 der Spec): eine Pflicht-Position ohne Gericht/Paket blockiert —
 * der Preis pro Person rechnet ohne sie, die Zeile fehlt im Menü. Ab zwei belegten Positionen
 * ohne Header fehlt die Gliederung, die jeder Composer-Pfad setzt (Hinweis, keine Warnung).
 */
class ConceptReifeAdapter extends ContainerReifeAdapter
{
    /** Ampel-Metriken je Konzept → Schwere. Slot-Lücke und Wording sind Struktur-Dubletten. */
    private const DQ = [
        'konzept_slot_luecke' => 'blockiert',
        'konzept_ohne_wording' => 'wichtig',
        'konzept_preisband_verletzt' => 'wichtig',
        'konzept_regel_verletzt' => 'wichtig',
        'konzept_dramaturgie' => 'hinweis',
    ];

    private const DQ_DUBLETTE = [
        'konzept_slot_luecke' => 'pflicht_slot_leer',
        'konzept_ohne_wording' => 'slot_wording',
        'konzept_preisband_verletzt' => 'geruest_preis',
        'konzept_regel_verletzt' => 'geruest_regel',
    ];

    public function __construct(
        CoverageService $coverage,
        DataQualityService $dq,
        private ConceptOneShotService $oneShot,
    ) {
        parent::__construct($coverage, $dq);
    }

    public function artifactType(): string
    {
        return 'concept';
    }

    public function messe(Team $team, int $id): ?array
    {
        $c = FoodAlchemistConcept::visibleToTeam($team)->with('slots')->find($id);
        if ($c === null) {
            return null;
        }

        $luecken = [];
        $erfuellt = [];
        $nichtMessbar = [];

        // ── 1. Kopf + KI-fähige Struktur — dieselbe Karte wie concepts.ENRICH ─────────
        $lk = $this->oneShot->luecken($team, $c);
        foreach (ConceptOneShotService::ZIELFELDER_KOPF as $feld) {
            if (! in_array($feld, $lk['kopf'], true)) {
                $erfuellt[] = $feld;

                continue;
            }
            $luecken[] = match ($feld) {
                'target_price_per_person' => $this->luecke('target_price_per_person', 'wichtig',
                    'Kein Zielpreis pro Person — die Preisband-Prüfung hat kein Soll.',
                    'foodalchemist.concepts.ENRICH', ['concept_id' => (int) $c->id]),
                'description' => $this->luecke('description', 'wichtig',
                    'Keine Beschreibung — das Konzept hat keinen Kundentext.',
                    'foodalchemist.concepts.ENRICH', ['concept_id' => (int) $c->id]),
                default => $this->luecke($feld, 'hinweis',
                    "Kopf-Feld «{$feld}» ist leer.",
                    'foodalchemist.concepts.ENRICH', ['concept_id' => (int) $c->id]),
            };
        }
        if ($lk['struktur']['header_titel'] !== []) {
            $luecken[] = $this->luecke('header_titel', 'hinweis',
                count($lk['struktur']['header_titel']) . ' Header tragen noch den Rollen-Namen statt eines Titels.',
                'foodalchemist.concept_wording.GENERATE', ['concept_id' => (int) $c->id])
                + ['slot_ids' => $lk['struktur']['header_titel']];
        } else {
            $erfuellt[] = 'header_titel';
        }
        if ($lk['struktur']['slot_wording'] !== []) {
            $luecken[] = $this->luecke('slot_wording', 'wichtig',
                count($lk['struktur']['slot_wording']) . ' belegte Positionen ohne Kunden-Wording — die Kette fällt auf den internen Pipe-Namen zurück.',
                'foodalchemist.concept_wording.GENERATE', ['concept_id' => (int) $c->id])
                + ['slot_ids' => $lk['struktur']['slot_wording']];
        } else {
            $erfuellt[] = 'slot_wording';
        }

        // ── 2. Struktur der Positionen ──────────────────────────────────────────────
        $positionen = $c->slots->filter(fn ($s) => ! in_array($s->type, ConceptService::STRUKTUR_TYPEN, true));
        $belegt = $positionen->filter(fn ($s) => $s->sales_recipe_id !== null || $s->package_id !== null || $s->embedded_concept_id !== null);
        $pflichtLeer = $positionen->filter(fn ($s) => (bool) $s->is_pflicht
            && $s->sales_recipe_id === null && $s->package_id === null && $s->embedded_concept_id === null);
        $header = $c->slots->filter(fn ($s) => in_array($s->type, ['header', 'header_preis'], true));

        if ($positionen->isEmpty()) {
            $luecken[] = $this->luecke('keine_positionen', 'blockiert',
                'Das Konzept hat keine einzige Position — es gibt nichts zu bepreisen und nichts zu drucken.',
                'foodalchemist.concept_slots.POST', ['concept_id' => (int) $c->id]);
        } elseif ($belegt->isEmpty()) {
            $luecken[] = $this->luecke('keine_belegte_position', 'blockiert',
                'Keine Position ist mit einem Gericht oder Paket belegt.',
                'foodalchemist.concept_slots.PUT', ['concept_id' => (int) $c->id])
                + ['slot_ids' => $positionen->pluck('id')->map(fn ($i) => (int) $i)->values()->all()];
        } else {
            $erfuellt[] = 'belegte_position';
        }
        if ($pflichtLeer->isNotEmpty()) {
            $luecken[] = $this->luecke('pflicht_slot_leer', 'blockiert',
                $pflichtLeer->count() . ' Pflicht-Positionen sind unbelegt — der Preis rechnet ohne sie, die Zeile fehlt im Menü.',
                'foodalchemist.concept_slots.PUT', ['concept_id' => (int) $c->id])
                + ['slot_ids' => $pflichtLeer->pluck('id')->map(fn ($i) => (int) $i)->values()->all()];
        } elseif ($positionen->isNotEmpty()) {
            $erfuellt[] = 'pflicht_slot_leer';
        }
        if ($belegt->count() >= 2 && $header->isEmpty()) {
            $luecken[] = $this->luecke('header_fehlt', 'hinweis',
                'Zwei oder mehr belegte Positionen ohne Header — die Gliederung fehlt (C-2).',
                'foodalchemist.concept_slots.POST', ['concept_id' => (int) $c->id, 'type' => 'header']);
        } elseif ($header->isNotEmpty()) {
            $erfuellt[] = 'header';
        }
        $headerOhneTitel = $header->filter(fn ($s) => trim((string) $s->title) === '');
        if ($headerOhneTitel->isNotEmpty()) {
            $luecken[] = $this->luecke('header_ohne_titel', 'wichtig',
                $headerOhneTitel->count() . ' Header ohne Titel — im Dokument steht eine leere Zeile.',
                'foodalchemist.concept_slots.PUT', ['concept_id' => (int) $c->id])
                + ['slot_ids' => $headerOhneTitel->pluck('id')->map(fn ($i) => (int) $i)->values()->all()];
        }

        // ── 3. Gerüst-Coverage ──────────────────────────────────────────────────────
        $g = $this->geruest($team, 'concept', (int) $c->id);
        $luecken = array_merge($luecken, $g['luecken']);
        $erfuellt = array_merge($erfuellt, $g['erfuellt']);
        $nichtMessbar = array_merge($nichtMessbar, $g['nicht_messbar']);

        // ── 4. Datenqualitäts-Ampel ─────────────────────────────────────────────────
        $luecken = array_merge($luecken, $this->ampel($team, 'concept', (int) $c->id, self::DQ, $luecken, self::DQ_DUBLETTE));

        $kennzahlen = [
            'kind' => (string) ($c->kind ?? 'concept'),
            'positionen' => $positionen->count(),
            'positionen_belegt' => $belegt->count(),
            'header' => $header->count(),
            'price_per_person' => $c->price_per_person_cache !== null ? (float) $c->price_per_person_cache : null,
            'target_price_per_person' => $c->target_price_per_person !== null ? (float) $c->target_price_per_person : null,
        ] + $g['kennzahlen'];

        return $this->ergebnis((string) $c->name, $c->status, $luecken, $erfuellt, $nichtMessbar, $kennzahlen);
    }

    /** Spec 50 · E-3 — {@see ReifeAdapter::sollAspekte()}. */
    public function sollAspekte(): array
    {
        $enrich = 'foodalchemist.concepts.ENRICH';
        $slots = 'foodalchemist.concept_slots.PUT';
        $wording = 'foodalchemist.concept_wording.GENERATE';

        return array_merge([
            ['code' => 'target_price_per_person', 'schwere' => 'wichtig', 'wie' => $enrich],
            ['code' => 'description', 'schwere' => 'wichtig', 'wie' => $enrich],
            ['code' => 'consumer_name', 'schwere' => 'hinweis', 'wie' => $enrich],
            ['code' => 'claim', 'schwere' => 'hinweis', 'wie' => $enrich],
            ['code' => 'keine_positionen', 'schwere' => 'blockiert', 'wie' => 'foodalchemist.concept_slots.POST'],
            ['code' => 'keine_belegte_position', 'schwere' => 'blockiert', 'wie' => $slots],
            ['code' => 'pflicht_slot_leer', 'schwere' => 'blockiert', 'wie' => $slots],
            ['code' => 'slot_wording', 'schwere' => 'wichtig', 'wie' => $wording],
            ['code' => 'header_titel', 'schwere' => 'hinweis', 'wie' => $wording],
            ['code' => 'header_fehlt', 'schwere' => 'hinweis', 'wie' => 'foodalchemist.concept_slots.POST'],
            ['code' => 'header_ohne_titel', 'schwere' => 'wichtig', 'wie' => $slots],
            // Aus der Datenqualitaets-Ampel gespiegelt — kein eigenes Werkzeug, die Ursache
            // liegt jeweils bei einem der Codes darueber.
            ['code' => 'konzept_slot_luecke', 'schwere' => 'blockiert', 'wie' => null, 'bedingt' => 'ampel'],
            ['code' => 'konzept_ohne_wording', 'schwere' => 'wichtig', 'wie' => null, 'bedingt' => 'ampel'],
            ['code' => 'konzept_preisband_verletzt', 'schwere' => 'wichtig', 'wie' => null, 'bedingt' => 'ampel'],
            ['code' => 'konzept_regel_verletzt', 'schwere' => 'wichtig', 'wie' => null, 'bedingt' => 'ampel'],
            ['code' => 'konzept_dramaturgie', 'schwere' => 'hinweis', 'wie' => null, 'bedingt' => 'ampel'],
        ], $this->geruestAspekte());
    }

}
