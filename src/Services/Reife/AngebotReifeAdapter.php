<?php

namespace Platform\FoodAlchemist\Services\Reife;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;

/**
 * Spec 50 · Etappe 7 — Reife eines Angebots (kundengebundene Instanz).
 *
 * Kopf = der Brief (Anlass, Personen, Datum, Budget) plus der Kundentext (`description`).
 * Den Kundentext schlägt {@see \Platform\FoodAlchemist\Services\AngebotService::kiKundentextVorschlag()}
 * vor — seit C-7 auch über `angebot.KUNDENTEXT_GENERATE`; persistiert wird er über `angebote.PUT`.
 * Struktur = Kapitel/Blöcke wie im Foodbook (Blatt-Kapitel ohne Inhalt, Ref ohne Ziel,
 * Header ohne Titel). Ein Angebot ohne Kapitel UND ohne angebots-lokales Konzept hat keine
 * Menü-Substanz — das blockiert, denn der Beleg hätte keine Position.
 *
 * Seit C-7 misst der Adapter zusätzlich, ob ein vorhandenes Planungs-Gerüst überhaupt in
 * Kapitel übersetzt wurde ({@see \Platform\FoodAlchemist\Services\OfferCompositionService::strukturAusGeruest()}).
 * Das ist NICHT die Coverage: gemessen wird, ob die geplanten Slots als Kapitel existieren,
 * nicht ob sie inhaltlich belegt sind. Den Soll-Ist-Abgleich kann {@see \Platform\FoodAlchemist\Services\CoverageService}
 * für `offer` weiterhin nicht — er kennt den Owner-Typ nicht. Ebenso hat die
 * Datenqualitäts-Ampel keine Angebots-Metriken → beides bleibt `nicht_messbar`.
 */
class AngebotReifeAdapter extends ContainerReifeAdapter
{
    private const INHALT = ['concept_ref', 'recipe_ref'];

    private const HEADER = ['header', 'header_preis'];

    public function artifactType(): string
    {
        return 'angebot';
    }

    public function messe(Team $team, int $id): ?array
    {
        $a = FoodAlchemistAngebot::visibleToTeam($team)->with(['chapters.blocks', 'concepts'])->find($id);
        if ($a === null) {
            return null;
        }

        $luecken = [];
        $erfuellt = [];
        $put = 'foodalchemist.angebote.PUT';
        $args = ['id' => (int) $a->id];
        $ids = fn ($c) => $c->pluck('id')->map(fn ($i) => (int) $i)->values()->all();

        // ── 1. Kopf — Brief + Kundentext ────────────────────────────────────────────
        $this->kopfFeld($luecken, $erfuellt, $a->occasion, 'occasion', 'wichtig', 'Kein Anlass — die Kontext-Kaskade hat keinen Anker.', $put, $args);
        $this->kopfFeld($luecken, $erfuellt, $a->personen, 'personen', 'blockiert', 'Keine Personenzahl — kein Gesamtpreis, keine Produktion.', $put, $args);
        $this->kopfFeld($luecken, $erfuellt, $a->event_date, 'event_date', 'wichtig', 'Kein Veranstaltungsdatum — Saison und Produktion ohne Bezug.', $put, $args);
        $this->kopfFeld($luecken, $erfuellt, $a->budget, 'budget', 'hinweis', 'Kein Budget — der Preis hat kein Soll.', $put, $args);
        $this->kopfFeld($luecken, $erfuellt, $a->description, 'description', 'hinweis',
            'Kein Kundentext — das Angebot beginnt ohne Anschreiben. Vorschlag über '
            . 'angebot.KUNDENTEXT_GENERATE, Übernehmen über angebote.PUT.', $put, $args);
        $this->kopfFeld($luecken, $erfuellt, $a->crm_company_id, 'crm_company_id', 'hinweis',
            'Keine CRM-Firma verknüpft.', 'foodalchemist.angebote.CUSTOMER_LINK', $args);

        // ── 2. Struktur — Kapitel, Blöcke, angebots-lokale Konzepte ─────────────────
        $kapitel = $a->chapters;
        $elternIds = $kapitel->pluck('parent_id')->filter()->map(fn ($i) => (int) $i)->unique();
        $blaetter = $kapitel->filter(fn ($k) => ! $elternIds->contains((int) $k->id));
        $inhalt = fn ($k) => $k->blocks->filter(fn ($b) => in_array($b->type, self::INHALT, true) && (bool) ($b->visible ?? true));
        $mitInhalt = $blaetter->filter(fn ($k) => $inhalt($k)->isNotEmpty());
        $leer = $blaetter->filter(fn ($k) => $inhalt($k)->isEmpty());
        $lokaleKonzepte = $a->concepts;

        if ($kapitel->isEmpty() && $lokaleKonzepte->isEmpty()) {
            $luecken[] = $this->luecke('keine_substanz', 'blockiert',
                'Weder Kapitel noch angebots-lokales Konzept — der Beleg hätte keine Position.',
                'foodalchemist.offer_chapter.POST', ['offer_id' => (int) $a->id]);
        } elseif ($kapitel->isEmpty()) {
            $luecken[] = $this->luecke('keine_kapitel', 'wichtig',
                'Konzepte liegen am Angebot, aber kein Kapitel ordnet sie — das Dokument hat keine Gliederung.',
                'foodalchemist.offer_chapter.POST', ['offer_id' => (int) $a->id]);
        } else {
            $erfuellt[] = 'kapitel';
            if ($mitInhalt->isEmpty()) {
                $luecken[] = $this->luecke('kein_inhalt', 'blockiert',
                    'Kein Kapitel trägt eine Inhalts-Zeile (concept_ref/recipe_ref).',
                    'foodalchemist.offer_block.POST', ['offer_id' => (int) $a->id]) + ['chapter_ids' => $ids($blaetter)];
            } elseif ($leer->isNotEmpty()) {
                $luecken[] = $this->luecke('kapitel_leer', 'wichtig',
                    $leer->count() . ' Kapitel ohne Inhalts-Zeile — im Angebot druckt das Kapitel leer.',
                    'foodalchemist.offer_block.POST', ['offer_id' => (int) $a->id]) + ['chapter_ids' => $ids($leer)];
            } else {
                $erfuellt[] = 'kapitel_leer';
            }
        }

        $alle = $kapitel->flatMap(fn ($k) => $k->blocks);
        $refOhneZiel = $alle->filter(fn ($b) => in_array($b->type, self::INHALT, true) && $b->concept_id === null && $b->sales_recipe_id === null);
        if ($refOhneZiel->isNotEmpty()) {
            $luecken[] = $this->luecke('ref_ohne_ziel', 'blockiert',
                $refOhneZiel->count() . ' Referenz-Blöcke zeigen auf kein Konzept und kein Gericht.',
                'foodalchemist.offer_block.PUT', ['offer_id' => (int) $a->id]) + ['block_ids' => $ids($refOhneZiel)];
        }
        $headerOhneTitel = $alle->filter(fn ($b) => in_array($b->type, self::HEADER, true) && trim((string) $b->label) === '');
        if ($headerOhneTitel->isNotEmpty()) {
            $luecken[] = $this->luecke('header_ohne_titel', 'wichtig',
                $headerOhneTitel->count() . ' Header ohne Titel.',
                'foodalchemist.offer_block.PUT', ['offer_id' => (int) $a->id]) + ['block_ids' => $ids($headerOhneTitel)];
        }
        $kapitelOhneTitel = $kapitel->filter(fn ($k) => trim((string) $k->title) === '');
        if ($kapitelOhneTitel->isNotEmpty()) {
            $luecken[] = $this->luecke('kapitel_ohne_titel', 'wichtig',
                $kapitelOhneTitel->count() . ' Kapitel ohne Titel.',
                'foodalchemist.offer_chapter.PUT', ['offer_id' => (int) $a->id]) + ['chapter_ids' => $ids($kapitelOhneTitel)];
        }
        $ohneText = $mitInhalt->filter(fn ($k) => trim((string) $k->description) === '');
        if ($ohneText->isNotEmpty()) {
            $luecken[] = $this->luecke('kapitel_ohne_text', 'hinweis',
                $ohneText->count() . ' befüllte Kapitel ohne Hinführung.',
                'foodalchemist.offer_chapter.PUT', ['offer_id' => (int) $a->id]) + ['chapter_ids' => $ids($ohneText)];
        } elseif ($mitInhalt->isNotEmpty()) {
            $erfuellt[] = 'kapitel_ohne_text';
        }

        // ── 3. Gerüst — geplante Slots, die noch kein Kapitel sind (C-7) ───────────
        $frame = app(\Platform\FoodAlchemist\Services\PlanningFrameService::class)->find('offer', (int) $a->id);
        $slots = $frame !== null ? $frame->slots()->orderBy('position')->get() : collect();
        $geruestMessbar = $slots->isNotEmpty();
        if ($geruestMessbar) {
            $kapitelIds = $kapitel->pluck('id')->map(fn ($i) => (int) $i)->all();
            $offen = $slots->filter(fn ($s) => $s->chapter_id === null || ! in_array((int) $s->chapter_id, $kapitelIds, true));
            if ($offen->isNotEmpty()) {
                $luecken[] = $this->luecke('geruest_nicht_materialisiert', 'wichtig',
                    $offen->count() . ' von ' . $slots->count() . ' Gerüst-Positionen sind noch kein Kapitel — '
                    . 'die geplanten Titel stehen nicht im Kundendokument.',
                    'foodalchemist.offer.STRUKTUR_AUS_GERUEST', ['offer_id' => (int) $a->id])
                    + ['slots' => $offen->pluck('label')->values()->all()];
            } else {
                $erfuellt[] = 'geruest_nicht_materialisiert';
            }
        }

        // ── 4. Preis ────────────────────────────────────────────────────────────────
        $vorlaeufig = false;
        if ($a->total_price === null && $a->calculated_total_price === null) {
            $luecken[] = $this->luecke('preis', 'wichtig',
                'Kein Gesamtpreis — weder gerechnet noch gesetzt.', 'foodalchemist.angebote.RECOMPUTE', $args);
        } else {
            $erfuellt[] = 'preis';
            $vorlaeufig = $a->price_calculated_at === null;
        }

        $nichtMessbar = [
            ['code' => 'geruest_belegung', 'warum' => 'CoverageService kennt den Owner-Typ offer nicht — ob die '
                . 'Gerüst-Ziele inhaltlich erfüllt sind, lässt sich am Angebot nicht abgleichen.'],
            ['code' => 'ampel', 'warum' => 'Die Datenqualitäts-Ampel hat keine Angebots-Metriken.'],
        ];
        if (! $geruestMessbar) {
            $nichtMessbar[] = ['code' => 'geruest_nicht_materialisiert',
                'warum' => 'Kein Planungs-Gerüst am Angebot — es gibt keine geplanten Positionen, die fehlen könnten.'];
        }
        $kennzahlen = [
            'kapitel' => $kapitel->count(),
            'kapitel_mit_inhalt' => $mitInhalt->count(),
            'lokale_konzepte' => $lokaleKonzepte->count(),
            'geruest_slots' => $slots->count(),
            'personen' => $a->personen !== null ? (int) $a->personen : null,
            'total_price' => $a->total_price !== null ? (float) $a->total_price : null,
            'calculated_total_price' => $a->calculated_total_price !== null ? (float) $a->calculated_total_price : null,
        ];

        return $this->ergebnis((string) $a->name, $a->status, $luecken, $erfuellt, $nichtMessbar, $kennzahlen, $vorlaeufig);
    }

    /** Spec 50 · E-3 — {@see ReifeAdapter::sollAspekte()}. */
    public function sollAspekte(): array
    {
        $put = 'foodalchemist.angebote.PUT';
        $block = 'foodalchemist.offer_block.POST';

        return [
            ['code' => 'occasion', 'schwere' => 'wichtig', 'wie' => $put],
            ['code' => 'personen', 'schwere' => 'blockiert', 'wie' => $put],
            ['code' => 'event_date', 'schwere' => 'wichtig', 'wie' => $put],
            ['code' => 'budget', 'schwere' => 'hinweis', 'wie' => $put],
            ['code' => 'description', 'schwere' => 'hinweis', 'wie' => $put],
            ['code' => 'crm_company_id', 'schwere' => 'hinweis', 'wie' => 'foodalchemist.angebote.CUSTOMER_LINK'],
            ['code' => 'keine_substanz', 'schwere' => 'blockiert', 'wie' => 'foodalchemist.offer_chapter.POST'],
            ['code' => 'keine_kapitel', 'schwere' => 'wichtig', 'wie' => 'foodalchemist.offer_chapter.POST'],
            ['code' => 'kein_inhalt', 'schwere' => 'blockiert', 'wie' => $block],
            ['code' => 'kapitel_leer', 'schwere' => 'wichtig', 'wie' => $block],
            ['code' => 'ref_ohne_ziel', 'schwere' => 'blockiert', 'wie' => 'foodalchemist.offer_block.PUT'],
            ['code' => 'header_ohne_titel', 'schwere' => 'wichtig', 'wie' => 'foodalchemist.offer_block.PUT'],
            ['code' => 'kapitel_ohne_titel', 'schwere' => 'wichtig', 'wie' => 'foodalchemist.offer_chapter.PUT'],
            ['code' => 'kapitel_ohne_text', 'schwere' => 'hinweis', 'wie' => 'foodalchemist.offer_chapter.PUT'],
            // C-7: seit die Slot-Labels Kapitel werden koennen, ist auch messbar, ob es passiert ist.
            ['code' => 'geruest_nicht_materialisiert', 'schwere' => 'wichtig', 'wie' => 'foodalchemist.offer.STRUKTUR_AUS_GERUEST', 'bedingt' => 'geruest'],
            ['code' => 'preis', 'schwere' => 'wichtig', 'wie' => 'foodalchemist.angebote.RECOMPUTE'],
        ];
    }

}
