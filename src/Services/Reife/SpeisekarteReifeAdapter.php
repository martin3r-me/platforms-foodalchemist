<?php

namespace Platform\FoodAlchemist\Services\Reife;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;

/**
 * Spec 50 · Etappe 7 — Reife einer Speisekarte (à-la-carte-Ausgabe).
 *
 * Struktur wie beim Foodbook: Blatt-Rubriken ohne Referenz-Position drucken leer, ein
 * Referenz-Block ohne Ziel blockiert, ein Header ohne Titel ist eine leere Zeile. Zusätzlich
 * die Wording-Kette: eine Gericht-Position ohne eigenes Wording fällt auf das Gericht zurück —
 * das ist erlaubt, aber `speisekarte_wording.GENERATE` ist genau dafür da (Hinweis).
 *
 * Die Datenqualitäts-Ampel hat keine Speisekarten-Metriken → `nicht_messbar`, nicht erfunden.
 */
class SpeisekarteReifeAdapter extends ContainerReifeAdapter
{
    public function artifactType(): string
    {
        return 'speisekarte';
    }

    public function messe(Team $team, int $id): ?array
    {
        $sk = FoodAlchemistSpeisekarte::visibleToTeam($team)->with('sections.items')->find($id);
        if ($sk === null) {
            return null;
        }

        $luecken = [];
        $erfuellt = [];
        $args = ['speisekarte_id' => (int) $sk->id];
        $ids = fn ($c) => $c->pluck('id')->map(fn ($i) => (int) $i)->values()->all();

        // ── 1. Kopf ─────────────────────────────────────────────────────────────────
        $this->kopfFeld($luecken, $erfuellt, $sk->name, 'name', 'wichtig', 'Kein Name.', 'foodalchemist.speisekarten.PUT', $args);
        $this->kopfFeld($luecken, $erfuellt, $sk->description, 'description', 'hinweis',
            'Kein Kundentext auf Karten-Ebene.', 'foodalchemist.speisekarten.PUT', $args);
        $this->kopfFeld($luecken, $erfuellt, $sk->outlet_id, 'outlet_id', 'hinweis',
            'Kein Outlet — die Betriebs-Kalkulation (Overhead) hat keinen Bezug.', 'foodalchemist.speisekarten.PUT', $args);

        // ── 2. Struktur — Rubriken und Positionen ───────────────────────────────────
        $rubriken = $sk->sections;
        $elternIds = $rubriken->pluck('parent_id')->filter()->map(fn ($i) => (int) $i)->unique();
        $blaetter = $rubriken->filter(fn ($r) => ! $elternIds->contains((int) $r->id));
        $refs = fn ($r) => $r->items->filter(fn ($p) => in_array($p->type, FoodAlchemistSpeisekartePosition::REF_TYPES, true) && (bool) ($p->visible ?? true));

        if ($rubriken->isEmpty()) {
            $luecken[] = $this->luecke('keine_rubriken', 'blockiert',
                'Die Karte hat keine Rubrik — es gibt nichts zu drucken.',
                'foodalchemist.speisekarte_rubrik.POST', $args);
        } else {
            $erfuellt[] = 'rubriken';
        }
        $leer = $blaetter->filter(fn ($r) => $refs($r)->isEmpty());
        $mitInhalt = $blaetter->filter(fn ($r) => $refs($r)->isNotEmpty());
        if ($rubriken->isNotEmpty() && $mitInhalt->isEmpty()) {
            $luecken[] = $this->luecke('kein_inhalt', 'blockiert',
                'Keine Rubrik trägt eine Gericht- oder Menü-Position.',
                'foodalchemist.speisekarte_positionen.POST', $args) + ['section_ids' => $ids($blaetter)];
        } elseif ($leer->isNotEmpty()) {
            $luecken[] = $this->luecke('rubrik_leer', 'wichtig',
                $leer->count() . ' Rubriken ohne Position — auf der Karte steht eine leere Überschrift.',
                'foodalchemist.speisekarte_positionen.POST', $args) + ['section_ids' => $ids($leer)];
        } elseif ($blaetter->isNotEmpty()) {
            $erfuellt[] = 'rubrik_leer';
        }

        $alle = $rubriken->flatMap(fn ($r) => $r->items);
        $refOhneZiel = $alle->filter(fn ($p) => in_array($p->type, FoodAlchemistSpeisekartePosition::REF_TYPES, true)
            && $p->sales_recipe_id === null && $p->concept_id === null);
        if ($refOhneZiel->isNotEmpty()) {
            $luecken[] = $this->luecke('ref_ohne_ziel', 'blockiert',
                $refOhneZiel->count() . ' Positionen zeigen auf kein Gericht und kein Menü.',
                'foodalchemist.speisekarte_positionen.PUT', $args) + ['item_ids' => $ids($refOhneZiel)];
        }
        $headerOhneTitel = $alle->filter(fn ($p) => $p->type === 'header' && trim((string) $p->label) === '');
        if ($headerOhneTitel->isNotEmpty()) {
            $luecken[] = $this->luecke('header_ohne_titel', 'wichtig',
                $headerOhneTitel->count() . ' Header ohne Titel.',
                'foodalchemist.speisekarte_positionen.PUT', $args) + ['item_ids' => $ids($headerOhneTitel)];
        }
        $ohneWording = $alle->filter(fn ($p) => $p->type === 'gericht_ref' && $p->sales_recipe_id !== null
            && trim((string) $p->wording) === '');
        if ($ohneWording->isNotEmpty()) {
            $luecken[] = $this->luecke('position_wording', 'hinweis',
                $ohneWording->count() . ' Gericht-Positionen ohne Karten-Wording — sie drucken mit dem Standard-Wording des Gerichts.',
                'foodalchemist.speisekarte_wording.GENERATE', $args) + ['item_ids' => $ids($ohneWording)];
        } elseif ($alle->where('type', 'gericht_ref')->isNotEmpty()) {
            $erfuellt[] = 'position_wording';
        }
        $rubrikOhneTitel = $rubriken->filter(fn ($r) => trim((string) $r->title) === '');
        if ($rubrikOhneTitel->isNotEmpty()) {
            $luecken[] = $this->luecke('rubrik_ohne_titel', 'wichtig',
                $rubrikOhneTitel->count() . ' Rubriken ohne Titel.',
                'foodalchemist.speisekarte_rubrik.PUT', $args) + ['section_ids' => $ids($rubrikOhneTitel)];
        }

        // ── 3. Gerüst-Coverage ──────────────────────────────────────────────────────
        $g = $this->geruest($team, 'speisekarte', (int) $sk->id);
        $luecken = array_merge($luecken, $g['luecken']);
        $erfuellt = array_merge($erfuellt, $g['erfuellt']);
        $nichtMessbar = array_merge($g['nicht_messbar'], [
            ['code' => 'ampel', 'warum' => 'Die Datenqualitäts-Ampel hat keine Speisekarten-Metriken.'],
        ]);

        $kennzahlen = [
            'rubriken' => $rubriken->count(),
            'rubriken_blatt' => $blaetter->count(),
            'rubriken_mit_inhalt' => $mitInhalt->count(),
            'positionen_ref' => $alle->filter(fn ($p) => in_array($p->type, FoodAlchemistSpeisekartePosition::REF_TYPES, true))->count(),
            'karten_typ' => $sk->karten_typ !== null ? (string) $sk->karten_typ : null,
        ] + $g['kennzahlen'];

        return $this->ergebnis((string) $sk->name, $sk->status, $luecken, $erfuellt, $nichtMessbar, $kennzahlen);
    }

    /** Spec 50 · E-3 — {@see ReifeAdapter::sollAspekte()}. */
    public function sollAspekte(): array
    {
        $put = 'foodalchemist.speisekarten.PUT';
        $pos = 'foodalchemist.speisekarte_positionen.POST';

        return array_merge([
            ['code' => 'name', 'schwere' => 'wichtig', 'wie' => $put],
            ['code' => 'description', 'schwere' => 'hinweis', 'wie' => $put],
            ['code' => 'outlet_id', 'schwere' => 'hinweis', 'wie' => $put],
            ['code' => 'keine_rubriken', 'schwere' => 'blockiert', 'wie' => 'foodalchemist.speisekarte_rubrik.POST'],
            ['code' => 'kein_inhalt', 'schwere' => 'blockiert', 'wie' => $pos],
            ['code' => 'rubrik_leer', 'schwere' => 'wichtig', 'wie' => $pos],
            ['code' => 'ref_ohne_ziel', 'schwere' => 'blockiert', 'wie' => 'foodalchemist.speisekarte_positionen.PUT'],
            ['code' => 'header_ohne_titel', 'schwere' => 'wichtig', 'wie' => 'foodalchemist.speisekarte_positionen.PUT'],
            ['code' => 'rubrik_ohne_titel', 'schwere' => 'wichtig', 'wie' => 'foodalchemist.speisekarte_rubrik.PUT'],
            ['code' => 'position_wording', 'schwere' => 'hinweis', 'wie' => 'foodalchemist.speisekarte_wording.GENERATE'],
        ], $this->geruestAspekte());
    }

}
