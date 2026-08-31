<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapterImage;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * #380 Composer — Angebot-Aufbau nach Foodbook-Vorbild, aber in EIGENEN Tabellen
 * (offer_chapters/offer_blocks). Spiegelt die Kompositions-Engine von
 * {@see FoodbookService} (Kapitel/Block-CRUD, blockPreis, kapitelAggregat, lebendes
 * Format-Kapitel) offer-scoped. Preis-Contracts werden WIEDERVERWENDET, nicht dupliziert:
 * ConceptService::preisCockpit (concept_ref), DarreichungResolver (recipe_ref),
 * FoodAlchemistFormat::priceRange (Format-Alternativen), WordingResolver (Gäste-Zeilen).
 *
 * Format-Kapitel (Kapitel.format_id): rendert die Editionen des Formats LIVE. Wie es in
 * den Angebotspreis einfällt, steuert `format_price_mode`:
 *  - additiv      = Kunde bekommt ALLE Editionen (Tages-VA) → Σ €/Person der Editions-Concepts
 *  - alternativen = Auswahl (Showcase) → Preis-Range min–max, KEIN additiver Summand
 */
class OfferCompositionService
{
    public function __construct(private ConceptService $concepts) {}

    // ── Kapitel ─────────────────────────────────────────────────────────────

    /** @return list<array{id:int, title:string, parent_id:?int, depth:int}> Pre-Order */
    public function kapitelTree(Team $team, int $offerId): array
    {
        $alle = FoodAlchemistOfferChapter::visibleToTeam($team)
            ->where('offer_id', $offerId)->orderBy('position')->get(['id', 'title', 'parent_id', 'format_id']);
        $byParent = $alle->groupBy(fn ($k) => $k->parent_id ?? 0);
        $out = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$out) {
            foreach ($byParent[$parentId] ?? [] as $k) {
                $out[] = ['id' => (int) $k->id, 'title' => $k->title, 'parent_id' => $k->parent_id !== null ? (int) $k->parent_id : null, 'depth' => $depth];
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    public function addKapitel(Team $team, int $offerId, array $in = [], ?int $parentId = null): FoodAlchemistOfferChapter
    {
        $offer = $this->ownedOffer($team, $offerId);
        if ($parentId !== null && ! FoodAlchemistOfferChapter::where('offer_id', $offer->id)->whereKey($parentId)->exists()) {
            throw new \RuntimeException('parent_id gehört nicht zu diesem Angebot.');
        }

        return FoodAlchemistOfferChapter::create([
            'team_id' => $offer->team_id, 'offer_id' => $offer->id, 'parent_id' => $parentId ?: null,
            'title' => trim((string) ($in['title'] ?? 'Neues Kapitel')) ?: 'Neues Kapitel',
            'consumer_title' => $in['consumer_title'] ?? null,
            'price_mode' => in_array($in['price_mode'] ?? '', FoodAlchemistOfferChapter::PRICE_MODES, true) ? $in['price_mode'] : 'auto',
            'position' => (int) FoodAlchemistOfferChapter::where('offer_id', $offer->id)
                ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
                ->max('position') + 1,
        ]);
    }

    /** Get-or-create: erstes Top-Level-Kapitel des Angebots (für Backfill + Voll-Kaskade-Anbindung). */
    public function defaultKapitel(Team $team, int $offerId): FoodAlchemistOfferChapter
    {
        $offer = $this->ownedOffer($team, $offerId);
        $vorhanden = FoodAlchemistOfferChapter::where('offer_id', $offer->id)->whereNull('format_id')
            ->orderBy('position')->first();

        return $vorhanden ?? $this->addKapitel($team, $offerId, ['title' => 'Menü']);
    }

    /** Format-Kapitel (live) anlegen: Identität aus dem Format, format_id gesetzt. */
    public function insertFormatKapitel(Team $team, int $offerId, int $formatId, ?int $parentId = null): FoodAlchemistOfferChapter
    {
        $offer = $this->ownedOffer($team, $offerId);
        $format = FoodAlchemistFormat::visibleToTeam($team)->findOrFail($formatId);
        if ($parentId !== null && ! FoodAlchemistOfferChapter::where('offer_id', $offer->id)->whereKey($parentId)->exists()) {
            throw new \RuntimeException('parent_id gehört nicht zu diesem Angebot.');
        }

        return FoodAlchemistOfferChapter::create([
            'team_id' => $offer->team_id, 'offer_id' => $offer->id, 'parent_id' => $parentId ?: null,
            'title' => $format->name,
            'consumer_title' => $format->consumer_name ?: null,
            'claim' => $format->claim ?: null,
            'description' => $format->story ?: null,
            'format_id' => $format->id,
            'format_price_mode' => 'additiv',
            'position' => (int) FoodAlchemistOfferChapter::where('offer_id', $offer->id)
                ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
                ->max('position') + 1,
        ]);
    }

    private const KAPITEL_FELDER = ['title', 'consumer_title', 'claim', 'description', 'price_mode', 'price_per_person',
        'personen', 'serving_form_id', 'service_moment_id', 'writing_style_id', 'is_struktur', 'creative_mode',
        'target_count', 'price_anchor', 'price_min', 'price_max', 'target_food_cost_pct'];

    public function updateKapitel(Team $team, int $id, array $in): FoodAlchemistOfferChapter
    {
        $k = $this->ownedKapitel($team, $id);
        $daten = array_intersect_key($in, array_flip(self::KAPITEL_FELDER));
        if (isset($daten['price_mode']) && ! in_array($daten['price_mode'], FoodAlchemistOfferChapter::PRICE_MODES, true)) {
            unset($daten['price_mode']);
        }
        $k->update($daten);

        return $k->refresh();
    }

    /** Nur für Format-Kapitel: additiv|alternativen umschalten. */
    public function setFormatPriceMode(Team $team, int $id, string $mode): FoodAlchemistOfferChapter
    {
        $k = $this->ownedKapitel($team, $id);
        if ($k->format_id === null || ! in_array($mode, FoodAlchemistOfferChapter::FORMAT_PRICE_MODES, true)) {
            throw new \RuntimeException('Preis-Modus nur für Format-Kapitel (additiv|alternativen).');
        }
        $k->update(['format_price_mode' => $mode]);

        return $k->refresh();
    }

    public function deleteKapitel(Team $team, int $id): void
    {
        $this->ownedKapitel($team, $id)->delete();
    }

    /** @param list<int> $ids */
    public function reorderKapitel(Team $team, int $offerId, array $ids): void
    {
        $this->ownedOffer($team, $offerId);
        DB::transaction(function () use ($offerId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistOfferChapter::where('id', (int) $id)->where('offer_id', $offerId)->update(['position' => $i]);
            }
        });
    }

    /** Verschieben mit Zyklus-Schutz (kein Knoten unter eigenen Nachfahren). Spiegelt FoodbookService::moveKapitel. */
    public function moveKapitel(Team $team, int $id, ?int $newParentId): void
    {
        $k = $this->ownedKapitel($team, $id);
        if ($newParentId !== null) {
            if ($newParentId === $id || in_array($newParentId, $this->descendantChapterIds($team, (int) $k->offer_id, $id), true)) {
                throw new \RuntimeException('Zyklus: Kapitel kann nicht unter einen eigenen Nachfahren.');
            }
        }
        $k->update(['parent_id' => $newParentId ?: null]);
    }

    /** @return list<int> Alle Nachfahren-Kapitel-IDs (transitiv) — nutzt kapitelTree. */
    private function descendantChapterIds(Team $team, int $offerId, int $chapterId): array
    {
        $kinder = [];
        foreach ($this->kapitelTree($team, $offerId) as $row) {
            $kinder[$row['parent_id'] ?? 0][] = $row['id'];
        }
        $ids = [];
        $stack = $kinder[$chapterId] ?? [];
        while ($stack) {
            $id = array_pop($stack);
            $ids[] = $id;
            foreach ($kinder[$id] ?? [] as $kid) {
                $stack[] = $kid;
            }
        }

        return $ids;
    }

    /**
     * Board: manuellen Kapitel-Fortschritt setzen (offen|in_arbeit|fertig). Direkt persistiert,
     * team-gescoped via ownedKapitel; Vokabular-Pflicht (FORTSCHRITT_STUFEN). Spiegelt Foodbook-Board.
     */
    public function kapitelFortschritt(Team $team, int $id, string $wert): FoodAlchemistOfferChapter
    {
        $k = $this->ownedKapitel($team, $id);
        if (! in_array($wert, FoodAlchemistOfferChapter::FORTSCHRITT_STUFEN, true)) {
            throw new \RuntimeException('Unbekannte Fortschritt-Stufe.');
        }
        $k->update(['fortschritt' => $wert]);

        return $k->refresh();
    }

    // ── Blöcke ──────────────────────────────────────────────────────────────

    private const BLOCK_FELDER = ['type', 'level', 'visible', 'label', 'wording', 'customer_text', 'interne_bemerkung',
        'concept_id', 'sales_recipe_id', 'quantity', 'unit_vocab_id', 'presentation_id', 'price_value', 'price_basis', 'height', 'payload_json'];

    public function addBlock(Team $team, int $chapterId, array $in): FoodAlchemistOfferBlock
    {
        $k = $this->ownedKapitel($team, $chapterId);
        if ($k->format_id !== null) {
            throw new \RuntimeException('Format-Kapitel trägt keine eigenen Blöcke (Inhalt kommt live aus dem Format).');
        }
        $daten = array_intersect_key($in, array_flip(self::BLOCK_FELDER));
        $daten['type'] = in_array($in['type'] ?? '', FoodAlchemistOfferBlock::BLOCK_TYPES, true) ? $in['type'] : 'text';
        if ($daten['type'] === 'concept_ref') {
            $this->pruefeConceptRef($team, $daten['concept_id'] ?? null);
        }
        if ($daten['type'] === 'recipe_ref') {
            $this->pruefeRecipeRef($team, $daten['sales_recipe_id'] ?? null);
        }
        $daten['team_id'] = $k->team_id;
        $daten['position'] = (int) $k->blocks()->max('position') + 1;

        return $k->blocks()->create($daten);
    }

    public function updateBlock(Team $team, int $blockId, array $in): FoodAlchemistOfferBlock
    {
        $block = $this->ownedBlock($team, $blockId);
        $daten = array_intersect_key($in, array_flip(self::BLOCK_FELDER));
        $effTyp = array_key_exists('type', $daten) ? $daten['type'] : $block->type;
        if ($effTyp === 'recipe_ref' && array_key_exists('sales_recipe_id', $daten)) {
            $this->pruefeRecipeRef($team, $daten['sales_recipe_id']);
        }
        $block->update($daten);

        return $block->refresh();
    }

    public function deleteBlock(Team $team, int $blockId): void
    {
        $this->ownedBlock($team, $blockId)->delete();
    }

    /** @param list<int> $ids */
    public function reorderBlocks(Team $team, int $chapterId, array $ids): void
    {
        $this->ownedKapitel($team, $chapterId);
        DB::transaction(function () use ($chapterId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistOfferBlock::where('id', (int) $id)->where('chapter_id', $chapterId)->update(['position' => $i]);
            }
        });
    }

    /**
     * Wording-Kette: Per-Gericht-Override eines concept_ref-Blocks
     * (payload_json['wording_overrides'][slot_id]) setzen/löschen — oberste Stufe der Kette.
     * Spiegelt FoodbookService::setBlockSlotWording.
     */
    public function setBlockSlotWording(Team $team, int $blockId, int $slotId, ?string $text): FoodAlchemistOfferBlock
    {
        $block = $this->ownedBlock($team, $blockId);
        $payload = $block->payload_json ?? [];
        $overrides = $payload['wording_overrides'] ?? [];
        $text = trim((string) $text);
        if ($text === '') {
            unset($overrides[(string) $slotId], $overrides[$slotId]);
        } else {
            $overrides[(string) $slotId] = $text;
        }
        $payload['wording_overrides'] = $overrides;
        $block->update(['payload_json' => $payload]);

        return $block->refresh();
    }

    /** Wahl-Gruppe „A|B|C": nächste freie Gruppen-ID im Kapitel. Spiegelt FoodbookService::nextVariantGroupId. */
    public function nextVariantGroupId(Team $team, int $chapterId): int
    {
        $this->ownedKapitel($team, $chapterId);

        return (int) FoodAlchemistOfferBlock::where('chapter_id', $chapterId)->max('variant_group_id') + 1;
    }

    /** @param list<int> $blockIds */
    public function setVariantGroup(Team $team, array $blockIds, ?int $groupId): void
    {
        foreach ($blockIds as $id) {
            $block = $this->ownedBlock($team, (int) $id);
            $block->update(['variant_group_id' => $groupId]);
        }
    }

    /** Block-Sichtbarkeit setzen (toggle-Ziel aus dem Editor). Spiegelt Foodbook-Index::blockSichtbar. */
    public function blockSichtbar(Team $team, int $id, bool $visible): FoodAlchemistOfferBlock
    {
        return $this->updateBlock($team, $id, ['visible' => $visible]);
    }

    /** Block-Ebene relativ verschieben, geklemmt auf 0..2. Spiegelt Foodbook-Index::blockEbene. */
    public function blockEbene(Team $team, int $id, int $delta): FoodAlchemistOfferBlock
    {
        $block = $this->ownedBlock($team, $id);

        return $this->updateBlock($team, $id, ['level' => max(0, min(2, (int) $block->level + $delta))]);
    }

    // ── Kapitel-Bilder (Spec 43 Bild-Epic, offer-scoped) ──────────────────────

    /** Kapitel-Bild setzen (überschreibt das Concept-Titelbild im Kapitel-Band). Spiegelt FoodbookService::setKapitelImage. */
    public function setKapitelImage(Team $team, int $chapterId, UploadedFile $file): string
    {
        $k = $this->ownedKapitel($team, $chapterId);
        app(FoodAlchemistMediaService::class)->delete($k->image_context_file_id, (string) $k->image_path, $team);
        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist.offer_chapter', $chapterId, "foodalchemist/offer_chapter/{$chapterId}",
        );
        $k->update(['image_context_file_id' => $media['context_file_id'], 'image_path' => $media['path']]);

        return $media['path'];
    }

    public function clearKapitelImage(Team $team, int $chapterId): FoodAlchemistOfferChapter
    {
        $k = $this->ownedKapitel($team, $chapterId);
        app(FoodAlchemistMediaService::class)->delete($k->image_context_file_id, (string) $k->image_path, $team);
        $k->update(['image_context_file_id' => null, 'image_path' => null]);

        return $k->refresh();
    }

    /** Weiteres Galeriebild (neben dem Kapitel-Bild) ans Angebot-Kapitel hängen. Spiegelt FoodbookService::addKapitelGalleryImage. */
    public function addKapitelGalleryImage(Team $team, int $chapterId, UploadedFile $file): FoodAlchemistOfferChapterImage
    {
        $k = $this->ownedKapitel($team, $chapterId);
        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist.offer_chapter', $chapterId, "foodalchemist/offer_chapter/{$chapterId}/gallery",
        );

        return FoodAlchemistOfferChapterImage::create([
            'team_id' => $k->team_id,
            'chapter_id' => $chapterId,
            'context_file_id' => $media['context_file_id'],
            'path' => $media['path'],
            'sort_order' => (int) $k->images()->max('sort_order') + 1,
        ]);
    }

    public function removeKapitelGalleryImage(Team $team, int $imageId): void
    {
        $img = FoodAlchemistOfferChapterImage::findOrFail($imageId);
        $this->ownedKapitel($team, (int) $img->chapter_id); // Owner-Guard übers Kapitel
        app(FoodAlchemistMediaService::class)->delete($img->context_file_id, (string) $img->path, $team);
        $img->delete();
    }

    // ── Preis ───────────────────────────────────────────────────────────────

    /**
     * Preis eines Blocks (spiegelt FoodbookService::blockPreis; ohne Staffel).
     *
     * @return array{vk_pp: float, ek_pp: float, pauschal: float}
     */
    public function blockPreis(FoodAlchemistOfferBlock $block, ?FoodAlchemistOutlet $outlet = null): array
    {
        if ($block->type === 'concept_ref' && $block->concept) {
            $cockpit = $this->concepts->preisCockpit($block->concept, $outlet);

            return ['vk_pp' => (float) $cockpit['price_per_person'], 'ek_pp' => (float) $cockpit['ek_per_person'], 'pauschal' => 0.0];
        }
        if ($block->type === 'recipe_ref' && $block->dish) {
            $faktor = $block->quantity !== null ? (float) $block->quantity : 1.0;
            $baseVk = $outlet !== null
                ? (app(DarreichungResolver::class)->vkNettoMitQuelle($block->dish, $outlet)['vk'] ?? (float) ($block->dish->sales_net ?? 0))
                : (float) ($block->dish->sales_net ?? 0);
            $vk = round($baseVk * $faktor, 2);
            $ek = round((float) ($block->dish->ek_total_eur ?? 0) * $faktor, 2);
            if ($block->price_basis === 'pauschal') {
                return ['vk_pp' => 0.0, 'ek_pp' => 0.0, 'pauschal' => $vk];
            }

            return ['vk_pp' => $vk, 'ek_pp' => $ek, 'pauschal' => 0.0];
        }
        if ($block->type === 'header_preis') {
            return $block->price_basis === 'pauschal'
                ? ['vk_pp' => 0.0, 'ek_pp' => 0.0, 'pauschal' => (float) ($block->price_value ?? 0)]
                : ['vk_pp' => (float) ($block->price_value ?? 0), 'ek_pp' => 0.0, 'pauschal' => 0.0];
        }

        return ['vk_pp' => 0.0, 'ek_pp' => 0.0, 'pauschal' => 0.0];
    }

    /**
     * Kapitel-Aggregat (spiegelt FoodbookService::kapitelAggregat) + Format-Kapitel-Logik.
     * Format-Kapitel: additiv = Σ Editions-price_per_person_cache (+ ek_per_person_cache);
     * alternativen = Range (vk_pro_person=null, preis_range gesetzt, kein additiver Summand).
     *
     * @return array{vk_pro_person: ?float, ek_pro_person: float, pauschal: float, food_cost_percent: ?float, preis_range: ?array}
     */
    public function kapitelAggregat(Team $team, FoodAlchemistOfferChapter $kapitel, ?FoodAlchemistOutlet $outlet = null): array
    {
        if ($kapitel->format_id !== null) {
            $format = $kapitel->relationLoaded('format') ? $kapitel->format : $kapitel->format()->with('slots.concept:id,price_per_person_cache,ek_per_person_cache')->first();
            if ($format === null) {
                return ['vk_pro_person' => null, 'ek_pro_person' => 0.0, 'pauschal' => 0.0, 'food_cost_percent' => null, 'preis_range' => null];
            }
            if (($kapitel->format_price_mode ?? 'additiv') === 'alternativen') {
                return ['vk_pro_person' => null, 'ek_pro_person' => 0.0, 'pauschal' => 0.0, 'food_cost_percent' => null, 'preis_range' => $format->priceRange()];
            }
            // additiv: alle Concept-Editionen summieren
            $vk = 0.0;
            $ek = 0.0;
            foreach ($format->slots as $fs) {
                if ($fs->type === 'concept' && $fs->concept !== null) {
                    $vk += (float) ($fs->concept->price_per_person_cache ?? 0);
                    $ek += (float) ($fs->concept->ek_per_person_cache ?? 0);
                }
            }

            return ['vk_pro_person' => round($vk, 2), 'ek_pro_person' => round($ek, 2), 'pauschal' => 0.0,
                'food_cost_percent' => $vk > 0 ? round($ek / $vk * 100, 1) : null, 'preis_range' => null];
        }

        $kapitel->loadMissing(['blocks' => fn ($q) => $q->where('visible', true),
            'blocks.concept:id,name,price_per_person_cache', 'blocks.dish:id,sales_net,ek_total_eur', 'children']);
        $vk = 0.0;
        $ek = 0.0;
        $pauschal = 0.0;
        foreach ($kapitel->blocks as $block) {
            $p = $this->blockPreis($block, $outlet);
            $vk += $p['vk_pp'];
            $ek += $p['ek_pp'];
            $pauschal += $p['pauschal'];
        }
        foreach ($kapitel->children as $kind) {
            $kindAgg = $this->kapitelAggregat($team, $kind, $outlet);
            $vk += (float) ($kindAgg['vk_pro_person'] ?? 0);
            $ek += $kindAgg['ek_pro_person'];
            $pauschal += $kindAgg['pauschal'];
        }
        if ($kapitel->price_mode === 'manuell' && $kapitel->price_per_person !== null) {
            $vk = (float) $kapitel->price_per_person;
        }

        return ['vk_pro_person' => round($vk, 2), 'ek_pro_person' => round($ek, 2), 'pauschal' => round($pauschal, 2),
            'food_cost_percent' => $vk > 0 ? round($ek / $vk * 100, 1) : null, 'preis_range' => null];
    }

    // ── Read-Struktur (EINE Quelle für Editor-Menü-Ansicht + Kalkulation + Dokument) ──

    /**
     * Vollständige Kundensicht-Komposition. `$intern=true` liefert zusätzlich EK/Marge (Editor/Vertrieb),
     * NIE fürs Kundendokument. Gäste-Zeilen kommen aus WordingResolver::gerichtZeilen (geteilte Kette).
     */
    public function komposition(Team $team, FoodAlchemistAngebot $angebot, ?FoodAlchemistOutlet $outlet = null, bool $intern = false): array
    {
        $wording = app(WordingResolver::class);
        $alle = FoodAlchemistOfferChapter::visibleToTeam($team)->where('offer_id', $angebot->id)
            ->with([
                'blocks' => fn ($q) => $q->where('visible', true)->orderBy('position'),
                'blocks.concept.slots.dish:id,name,sales_wording_standard,sales_net,ek_total_eur,dish_class_id,spec_is_vegan,spec_is_vegetarian,spec_contains_pork,spec_contains_beef,allergens_confidence',
                'blocks.concept.slots.package.dishes.dish:id,name,sales_wording_standard',
                'blocks.concept.slots.embeddedConcept:id,name,consumer_name,price_per_person_cache',
                'blocks.concept.images',
                'blocks.dish:id,name,sales_wording_standard,sales_net,ek_total_eur',
                'format.slots' => fn ($q) => $q->orderBy('position'),
                'format.slots.concept.slots.dish:id,name,sales_wording_standard',
                'format.images',
                'images',
            ])->orderBy('position')->get();

        $byParent = $alle->groupBy(fn ($k) => $k->parent_id ?? 0);
        $angebotPax = max(0, (int) ($angebot->personen ?? 0));
        $kapitel = [];
        $summeVk = 0.0;
        $summeEk = 0.0;
        $summePauschal = 0.0;
        $summeGesamtVk = 0.0;
        $summeGesamtEk = 0.0;

        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$kapitel, &$summeVk, &$summeEk, &$summePauschal, &$summeGesamtVk, &$summeGesamtEk, $team, $outlet, $intern, $wording, $angebotPax) {
            foreach ($byParent[$parentId] ?? [] as $k) {
                $agg = $this->kapitelAggregat($team, $k, $outlet);
                // Per-Kapitel-Pax (Q1: Σ Kapitel-Pax × €/P): eigene Pax überschreibt, sonst erbt es die Angebots-Pax.
                $effPax = $k->personen !== null ? max(0, (int) $k->personen) : $angebotPax;
                $kapGesamt = round((float) ($agg['vk_pro_person'] ?? 0) * $effPax + (float) ($agg['pauschal'] ?? 0), 2);
                $row = [
                    'id' => (int) $k->id,
                    'title' => $k->consumer_title ?: $k->title,
                    'title_intern' => $k->title,
                    'text' => trim((string) $k->description) ?: null,
                    'anker' => 'k' . $k->id,
                    'depth' => $depth,
                    'parent_id' => $k->parent_id !== null ? (int) $k->parent_id : null,
                    'ist_format' => $k->format_id !== null,
                    'format_id' => $k->format_id !== null ? (int) $k->format_id : null,
                    'format_price_mode' => $k->format_price_mode ?? 'additiv',
                    'price_mode' => $k->price_mode,
                    'pax' => $effPax,
                    'eigene_pax' => $k->personen !== null,
                    'gesamt' => $kapGesamt,
                    'vk_pro_person' => $agg['vk_pro_person'],
                    'pauschal' => $agg['pauschal'],
                    'preis_range' => $agg['preis_range'],
                    'bloecke' => [],
                    'editionen' => [],
                ];
                if ($intern) {
                    $row['ek_pro_person'] = $agg['ek_pro_person'];
                    $row['food_cost_percent'] = $agg['food_cost_percent'];
                    // Bild-Epic (offer-scoped, wie normalizeFoodbook): Kapitel-Bild + Galerie, sonst Concept-Fallback.
                    // Rohe Identifier (context_file_id + path) — Auflösung zur data-URI liegt beim Konsumenten.
                    $bilder = $this->kapitelBilder($k);
                    $row['bild'] = $bilder[0] ?? null;
                    $row['bilder'] = array_slice($bilder, 0, 6);
                }

                if ($k->format_id !== null && $k->format !== null) {
                    foreach ($k->format->slots as $fs) {
                        if ($fs->type === 'concept' && $fs->concept !== null) {
                            $ed = $fs->concept;
                            $edZeile = ['typ' => 'concept', 'name' => $ed->consumer_name ?: $ed->name, 'claim' => $ed->claim ?: null,
                                'text' => trim((string) $ed->description) ?: null,
                                'preis_pp' => $ed->price_per_person_cache !== null ? (float) $ed->price_per_person_cache : null,
                                'einzelpreise' => $ed->istEinzelpreis(), 'gerichte' => $wording->gerichtZeilen($ed)];
                            if ($intern) {
                                $edZeile['ek_pp'] = $ed->ek_per_person_cache !== null ? (float) $ed->ek_per_person_cache : null;
                            }
                            $row['editionen'][] = $edZeile;
                        } elseif (in_array($fs->type, ['header', 'text', 'spacer'], true)) {
                            $row['editionen'][] = ['typ' => $fs->type, 'name' => $fs->title, 'text' => $fs->text_content,
                                'claim' => null, 'preis_pp' => null, 'einzelpreise' => false, 'gerichte' => []];
                        }
                    }
                } else {
                    foreach ($k->blocks as $b) {
                        $bp = $this->blockPreis($b, $outlet);
                        $bZeile = [
                            'id' => (int) $b->id,
                            'type' => $b->type,
                            'label' => $this->blockLabel($b),
                            'wording' => $b->wording,
                            'ist_header' => str_starts_with((string) $b->type, 'header'),
                            'concept_id' => $b->concept_id !== null ? (int) $b->concept_id : null,
                            'preis_pp' => (float) $bp['vk_pp'],
                            'pauschal' => (float) $bp['pauschal'],
                            'preis_einheit' => match ($b->type) { 'concept_ref' => 'gast', 'recipe_ref' => 'position', default => null },
                            'einzelpreise' => $b->type === 'concept_ref' && $b->concept !== null && $b->concept->istEinzelpreis(),
                            'gerichte' => ($b->type === 'concept_ref' && $b->concept !== null) ? $wording->gerichtZeilen($b->concept, null) : [],
                            'height' => $b->height,
                        ];
                        if ($intern) {
                            $bZeile['ek_pp'] = (float) $bp['ek_pp'];
                        }
                        $row['bloecke'][] = $bZeile;
                    }
                }

                // Additive Summe: Format-Alternativen (vk_pro_person=null) fallen NICHT ein.
                $summeVk += (float) ($agg['vk_pro_person'] ?? 0);
                $summeEk += $agg['ek_pro_person'];
                $summePauschal += $agg['pauschal'];
                // Gesamt-Summe pax-korrekt (Q1): Σ Kapitel-Pax × €/P (+ Pauschale je Kapitel).
                $summeGesamtVk += $kapGesamt;
                $summeGesamtEk += (float) ($agg['ek_pro_person'] ?? 0) * $effPax;

                $kapitel[] = $row;
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return [
            'kapitel' => $kapitel,
            'summe' => [
                'vk_pro_person' => round($summeVk, 2), 'ek_pro_person' => round($summeEk, 2), 'pauschal' => round($summePauschal, 2),
                'pax' => $angebotPax,
                'gesamt_vk' => round($summeGesamtVk, 2),   // Σ Kapitel-Pax × €/P + Pauschalen (pax-korrekt)
                'gesamt_ek' => round($summeGesamtEk, 2),
            ],
        ];
    }

    /**
     * Preis-Einheiten fürs Kalkulations-Rewrite ({@see AngebotService::kalkulation}):
     * die Concepts, die voll bekostet werden (concept_ref + additiv-Format-Editionen), plus
     * einfache Pauschal-/Personen-Zuschläge (recipe_ref/header_preis) und die Alternativen-Ranges.
     *
     * @return array{concepts: Collection<int,FoodAlchemistConcept>, vk_pp_extra: float, ek_pp_extra: float, flat_total: float, alternativen: list<array{name:string, min:?float, max:?float}>}
     */
    public function preisEinheiten(Team $team, FoodAlchemistAngebot $angebot, ?FoodAlchemistOutlet $outlet = null): array
    {
        $alle = FoodAlchemistOfferChapter::visibleToTeam($team)->where('offer_id', $angebot->id)
            ->with(['blocks' => fn ($q) => $q->where('visible', true),
                'blocks.concept', 'blocks.dish:id,sales_net,ek_total_eur',
                'format.slots.concept'])->get();

        $angebotPax = max(0, (int) ($angebot->personen ?? 0));
        $concepts = collect();
        $units = [];   // [{concept, pax}] — Concept-Einheit mit ihrer effektiven Kapitel-Pax (für costConcept)
        $vkExtra = 0.0;
        $ekExtra = 0.0;
        $flat = 0.0;
        $alternativen = [];

        foreach ($alle as $k) {
            $effPax = $k->personen !== null ? max(0, (int) $k->personen) : $angebotPax;
            if ($k->format_id !== null) {
                if ($k->format === null) {
                    continue;
                }
                if (($k->format_price_mode ?? 'additiv') === 'alternativen') {
                    $range = $k->format->priceRange();
                    $alternativen[] = ['name' => $k->consumer_title ?: $k->title, 'min' => $range['min'] ?? null, 'max' => $range['max'] ?? null];

                    continue;
                }
                foreach ($k->format->slots as $fs) {
                    if ($fs->type === 'concept' && $fs->concept !== null) {
                        $concepts->push($fs->concept);
                        $units[] = ['concept' => $fs->concept, 'pax' => $effPax];
                    }
                }

                continue;
            }
            foreach ($k->blocks as $b) {
                if ($b->type === 'concept_ref' && $b->concept !== null) {
                    $concepts->push($b->concept);
                    $units[] = ['concept' => $b->concept, 'pax' => $effPax];
                } elseif ($b->type === 'recipe_ref' && $b->dish !== null) {
                    $bp = $this->blockPreis($b, $outlet);
                    $vkExtra += $bp['vk_pp'];
                    $ekExtra += $bp['ek_pp'];
                    $flat += $bp['pauschal'];
                } elseif ($b->type === 'header_preis') {
                    $bp = $this->blockPreis($b, $outlet);
                    $vkExtra += $bp['vk_pp'];
                    $flat += $bp['pauschal'];
                }
            }
        }

        return ['concepts' => $concepts->values(), 'units' => $units, 'vk_pp_extra' => round($vkExtra, 2),
            'ek_pp_extra' => round($ekExtra, 2), 'flat_total' => round($flat, 2), 'alternativen' => $alternativen];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Kapitel-Bildmaterial (roh) für die Editor-/Kundensicht — spiegelt normalizeFoodbook:
     * 1) Kapitel-eigenes Bild (image_*) + Kapitel-Galerie (images()-Relation),
     * 2) Fallback: erstes concept_ref-Konzept mit Bildmaterial (Titelbild + Concept-Galerie).
     *
     * @return list<array{context_file_id:?int, path:?string}>
     */
    private function kapitelBilder(FoodAlchemistOfferChapter $k): array
    {
        $liste = [];
        if ($k->image_context_file_id || $k->image_path) {
            $liste[] = ['context_file_id' => $k->image_context_file_id, 'path' => $k->image_path];
        }
        foreach ($k->images ?? [] as $ci) {
            if ($ci->context_file_id || $ci->path) {
                $liste[] = ['context_file_id' => $ci->context_file_id, 'path' => $ci->path];
            }
        }
        if ($liste === []) {
            foreach ($k->blocks as $b) {
                if ($b->type !== 'concept_ref' || $b->concept === null) {
                    continue;
                }
                if ($b->concept->image_context_file_id || $b->concept->image_path) {
                    $liste[] = ['context_file_id' => $b->concept->image_context_file_id, 'path' => $b->concept->image_path];
                }
                foreach ($b->concept->images ?? [] as $gi) {
                    if ($gi->context_file_id || $gi->path) {
                        $liste[] = ['context_file_id' => $gi->context_file_id, 'path' => $gi->path];
                    }
                }
                if ($liste !== []) {
                    break; // erstes Konzept mit Bildmaterial gewinnt
                }
            }
        }

        return $liste;
    }

    private function blockLabel(FoodAlchemistOfferBlock $block): ?string
    {
        return match ($block->type) {
            'concept_ref' => (string) ($block->wording ?: ($block->label ?: ($block->concept?->consumer_name ?: $block->concept?->name))),
            'recipe_ref' => (string) ($block->wording ?: ($block->label ?: $block->dish?->name)),
            'header', 'header_preis' => (string) ($block->label ?? ''),
            'text' => (string) ($block->customer_text ?? $block->label ?? ''),
            default => $block->label,
        };
    }

    private function pruefeConceptRef(Team $team, ?int $conceptId): void
    {
        if ($conceptId === null || ! FoodAlchemistConcept::visibleToTeam($team)->whereKey($conceptId)->exists()) {
            throw new \RuntimeException('concept_ref-Block braucht ein sichtbares Concept.');
        }
    }

    private function pruefeRecipeRef(Team $team, ?int $salesRecipeId): void
    {
        if ($salesRecipeId === null) {
            throw new \RuntimeException('recipe_ref-Block braucht ein sales_recipe_id (VK-Gericht).');
        }
        $ok = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')->whereKey($salesRecipeId)->exists();
        if (! $ok) {
            throw new \RuntimeException("sales_recipe_id {$salesRecipeId} ist kein gültiges, sichtbares VK-Gericht.");
        }
    }

    private function ownedOffer(Team $team, int $id): FoodAlchemistAngebot
    {
        $offer = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($id);
        if (! $offer->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Angebot — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $offer;
    }

    private function ownedKapitel(Team $team, int $id): FoodAlchemistOfferChapter
    {
        $k = FoodAlchemistOfferChapter::visibleToTeam($team)->findOrFail($id);
        if (! $k->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Angebot — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $k;
    }

    private function ownedBlock(Team $team, int $id): FoodAlchemistOfferBlock
    {
        $block = FoodAlchemistOfferBlock::visibleToTeam($team)->findOrFail($id);
        if (! $block->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Angebot — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $block;
    }
}
