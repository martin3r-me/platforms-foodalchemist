<?php

namespace Platform\FoodAlchemist\Services\Reife;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;

/**
 * Spec 50 · Etappe 7 — Reife eines Foodbooks (Kundenbuch).
 *
 * Struktur folgt den Ampel-Regeln aus {@see \Platform\FoodAlchemist\Services\DataQualityService}:
 * gemessen werden nur Blatt-Kapitel (ein Eltern-Kapitel ist eine Klammer), Inhalt sind nur
 * sichtbare `concept_ref`/`recipe_ref`-Blöcke, ein Kapitel MIT Inhalt aber ohne Hinführung
 * ist ein Hinweis (druckbar, nur nicht ausformuliert). Die entsprechenden Ampel-Metriken
 * werden als Dubletten unterdrückt — hier steht derselbe Befund mit Kapitel-IDs und Weg.
 */
class FoodbookReifeAdapter extends ContainerReifeAdapter
{
    private const INHALT = ['concept_ref', 'recipe_ref'];

    private const HEADER = ['header_neutral', 'header_frei', 'header_frei_preis'];

    private const DQ = [
        'foodbook_kapitel_leer' => 'wichtig',
        'foodbook_kapitel_ohne_text' => 'hinweis',
        'foodbook_ziel_verfehlt' => 'wichtig',
        'foodbook_stale' => 'wichtig',
        'foodbook_skizze_ungeerdet' => 'hinweis',
    ];

    private const DQ_DUBLETTE = [
        'foodbook_kapitel_leer' => 'kapitel_leer',
        'foodbook_kapitel_ohne_text' => 'kapitel_ohne_text',
    ];

    public function artifactType(): string
    {
        return 'foodbook';
    }

    public function messe(Team $team, int $id): ?array
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->with('chapters.blocks')->find($id);
        if ($fb === null) {
            return null;
        }

        $luecken = [];
        $erfuellt = [];
        $args = ['foodbook_id' => (int) $fb->id];

        // ── 1. Kopf ─────────────────────────────────────────────────────────────────
        $this->kopfFeld($luecken, $erfuellt, $fb->label, 'label', 'wichtig', 'Kein Titel.', 'foodalchemist.foodbooks.PUT', $args);
        $this->kopfFeld($luecken, $erfuellt, $fb->description, 'description', 'hinweis',
            'Kein Kundentext auf Buch-Ebene — das Dokument beginnt ohne Einleitung.',
            'foodalchemist.foodbook.KUNDENTEXT_GENERATE', $args);
        $this->kopfFeld($luecken, $erfuellt, $fb->customer, 'customer', 'hinweis',
            'Kein Kunde am Buch — die Kontext-Kaskade (Food-DNA, Brand) hat keinen Anker.',
            'foodalchemist.foodbooks.CUSTOMER_LINK', $args);

        // ── 2. Struktur — Kapitel und Blöcke ────────────────────────────────────────
        $kapitel = $fb->chapters;
        $elternIds = $kapitel->pluck('parent_id')->filter()->map(fn ($i) => (int) $i)->unique();
        $blaetter = $kapitel->filter(fn ($k) => ! $elternIds->contains((int) $k->id));
        $ids = fn ($c) => $c->pluck('id')->map(fn ($i) => (int) $i)->values()->all();

        if ($kapitel->isEmpty()) {
            $luecken[] = $this->luecke('keine_kapitel', 'blockiert',
                'Das Buch hat kein Kapitel — es gibt nichts zu drucken.',
                'foodalchemist.foodbook_kapitel.POST', $args);
        } else {
            $erfuellt[] = 'kapitel';
        }

        $inhalt = fn ($k) => $k->blocks->filter(fn ($b) => in_array($b->type, self::INHALT, true) && (bool) ($b->visible ?? true));
        $leer = $blaetter->filter(fn ($k) => $inhalt($k)->isEmpty());
        $mitInhalt = $blaetter->filter(fn ($k) => $inhalt($k)->isNotEmpty());
        $ohneText = $mitInhalt->filter(fn ($k) => trim((string) $k->description) === '');

        if ($kapitel->isNotEmpty() && $mitInhalt->isEmpty()) {
            $luecken[] = $this->luecke('kein_inhalt', 'blockiert',
                'Kein Kapitel trägt eine Inhalts-Zeile (concept_ref/recipe_ref).',
                'foodalchemist.foodbook_blocks.POST', $args) + ['chapter_ids' => $ids($blaetter)];
        } elseif ($leer->isNotEmpty()) {
            $luecken[] = $this->luecke('kapitel_leer', 'wichtig',
                $leer->count() . ' Kapitel ohne Inhalts-Zeile — im Kundendokument druckt das Kapitel leer.',
                'foodalchemist.foodbook_blocks.POST', $args) + ['chapter_ids' => $ids($leer)];
        } elseif ($blaetter->isNotEmpty()) {
            $erfuellt[] = 'kapitel_leer';
        }
        if ($ohneText->isNotEmpty()) {
            $luecken[] = $this->luecke('kapitel_ohne_text', 'hinweis',
                $ohneText->count() . ' befüllte Kapitel ohne Hinführung — auf die Überschrift folgt direkt die Liste.',
                'foodalchemist.foodbook_kapitel.KUNDENTEXT_GENERATE', $args) + ['chapter_ids' => $ids($ohneText)];
        } elseif ($mitInhalt->isNotEmpty()) {
            $erfuellt[] = 'kapitel_ohne_text';
        }

        $alleBloecke = $kapitel->flatMap(fn ($k) => $k->blocks);
        $refOhneZiel = $alleBloecke->filter(fn ($b) => in_array($b->type, self::INHALT, true)
            && $b->concept_id === null && $b->sales_recipe_id === null);
        if ($refOhneZiel->isNotEmpty()) {
            $luecken[] = $this->luecke('ref_ohne_ziel', 'blockiert',
                $refOhneZiel->count() . ' Referenz-Blöcke zeigen auf kein Konzept und kein Gericht.',
                'foodalchemist.foodbook_blocks.PUT', $args) + ['block_ids' => $ids($refOhneZiel)];
        }
        $headerOhneTitel = $alleBloecke->filter(fn ($b) => in_array($b->type, self::HEADER, true) && trim((string) $b->label) === '');
        if ($headerOhneTitel->isNotEmpty()) {
            $luecken[] = $this->luecke('header_ohne_titel', 'wichtig',
                $headerOhneTitel->count() . ' Header ohne Titel — im Dokument steht eine leere Zeile.',
                'foodalchemist.foodbook_blocks.PUT', $args) + ['block_ids' => $ids($headerOhneTitel)];
        }
        $kapitelOhneTitel = $kapitel->filter(fn ($k) => trim((string) $k->title) === '');
        if ($kapitelOhneTitel->isNotEmpty()) {
            $luecken[] = $this->luecke('kapitel_ohne_titel', 'wichtig',
                $kapitelOhneTitel->count() . ' Kapitel ohne Titel.',
                'foodalchemist.foodbook_kapitel.PUT', $args) + ['chapter_ids' => $ids($kapitelOhneTitel)];
        }

        // ── 3. Gerüst-Coverage + 4. Ampel ───────────────────────────────────────────
        $g = $this->geruest($team, 'foodbook', (int) $fb->id);
        $luecken = array_merge($luecken, $g['luecken']);
        $erfuellt = array_merge($erfuellt, $g['erfuellt']);
        $luecken = array_merge($luecken, $this->ampel($team, 'foodbook', (int) $fb->id, self::DQ, $luecken, self::DQ_DUBLETTE));

        $kennzahlen = [
            'kapitel' => $kapitel->count(),
            'kapitel_blatt' => $blaetter->count(),
            'kapitel_mit_inhalt' => $mitInhalt->count(),
            'inhalts_bloecke' => $alleBloecke->filter(fn ($b) => in_array($b->type, self::INHALT, true))->count(),
            'jahr' => $fb->jahr !== null ? (int) $fb->jahr : null,
        ] + $g['kennzahlen'];

        return $this->ergebnis((string) $fb->label, $fb->status, $luecken, $erfuellt, $g['nicht_messbar'], $kennzahlen);
    }
}
