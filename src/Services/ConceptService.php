<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSektorEignung;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKlasse;

/**
 * M10-03/04/05 / Doc 15 §M10: Concept = Slot-Gerüst über mehrere Rollen
 * (z. B. „Grill-Buffet"). Jeder Slot ist mit GENAU EINEM gefüllt: Paket
 * (austauschbar) ODER festes Gericht.
 *
 * Preis (M10-04): Concept-Preis = Σ der GESPEICHERTEN Paket-Preise (+ feste
 * Gerichte) — ein Paket-Tausch ändert nur die Differenz, KEIN Kaskaden-
 * Recompute der ganzen GP→Rezept→Gericht-Kette.
 *
 * Vorlage (M10-05, D-CON-7): Vorlage = Kopie-Quelle. „Aus Vorlage starten" forkt
 * das Slot-Gerüst; das Concept lebt danach eigenständig (Vorlage zieht NICHT
 * durch). Paket bleibt dagegen Referenz (Änderung schlägt durch).
 */
class ConceptService
{
    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistConcept::visibleToTeam($team)
            ->standardisiert()   // #380: angebots-lokale Entwürfe gehören nicht in den Katalog
            ->withCount('slots')
            ->when(($filters['vorlagen'] ?? false), fn ($q) => $q->vorlagen(), fn ($q) => $q->echte())
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(anlass, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['klasse'] ?? '') !== '', fn ($q) => $q->where('klasse', $filters['klasse']))
            ->when(($filters['category'] ?? null) === 'none', fn ($q) => $q->whereNull('category_id'))
            ->when(is_numeric($filters['category'] ?? null), fn ($q) => $q
                ->whereIn('category_id', $this->descendantIds($team, (int) $filters['category'])))
            // Facetten-Filter (Umbau-Spec Phase 4b)
            ->when(is_numeric($filters['servierform'] ?? null), fn ($q) => $q->where('servierform_id', (int) $filters['servierform']))
            ->when(is_numeric($filters['eventtyp'] ?? null), fn ($q) => $q->where('eventtyp_id', (int) $filters['eventtyp']))
            ->when(is_numeric($filters['einsatzmoment'] ?? null), fn ($q) => $q
                ->whereHas('einsatzmomente', fn ($w) => $w->where('foodalchemist_einsatzmomente.id', (int) $filters['einsatzmoment'])))
            ->when(is_numeric($filters['saison'] ?? null), fn ($q) => $q
                ->whereHas('saisons', fn ($w) => $w->where('foodalchemist_saisons.id', (int) $filters['saison'])))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistConcept
    {
        return FoodAlchemistConcept::visibleToTeam($team)
            ->with([
                'slots' => fn ($q) => $q->orderBy('position'),
                'slots.paket:id,name,rolle,klasse,preis_pro_person,ek_pro_person,wareneinsatz_prozent,preis_modus,preis_stale',
                'slots.gericht:id,name,vk_netto,ek_total_eur,speisen_klasse_id,spec_is_vegan,spec_is_vegetarian,spec_is_gluten_free,spec_is_lactose_free,spec_is_halal,spec_contains_pork,spec_contains_beef,allergene_konfidenz',
                'slots.gericht.speisenKlasse:id,bezeichnung',
                'slots.einheit:id,slug,display_de',
                'slots.geschirrItem:id,bezeichnung,leihpreis,einheit',
                'slots.geschirrAltItem:id,bezeichnung,leihpreis,einheit',
                'vorlageQuelle:id,name',
            ])
            ->find($id);
    }

    public function create(Team $team, array $in): FoodAlchemistConcept
    {
        return FoodAlchemistConcept::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neues Concept')) ?: 'Neues Concept',
            'anlass' => $in['anlass'] ?? null,
            'niveau' => $in['niveau'] ?? null,
            'klasse' => $this->norm($in['klasse'] ?? null),
            'status' => $in['status'] ?? 'draft',
            'is_vorlage' => (bool) ($in['is_vorlage'] ?? false),
        ]);
    }

    /** Concept-Status setzen (draft|aktiv|archiviert) — Inline-Pflege aus dem Browser. */
    public function setStatus(Team $team, int $id, string $status): void
    {
        if (! in_array($status, ['draft', 'aktiv', 'archiviert'], true)) {
            throw new \RuntimeException("Unbekannter Concept-Status [{$status}].");
        }
        FoodAlchemistConcept::visibleToTeam($team)->findOrFail($id)->update(['status' => $status]);
    }

    // M10R-1/3: VK-Parität-Metadaten + Konsumenten-Felder + KI-Brief am Concept editierbar.
    private const FELDER = [
        'name', 'konsumenten_name', 'anlass', 'niveau', 'klasse', 'geschmacksrichtung',
        'schreibstil_id', 'category_id', 'status', 'beschreibung', 'zusatztext', 'note',
        'brief', 'zielpreis_pro_person', 'diaet_vorgabe', 'struktur_vorgabe', 'saison', 'zielgruppe',
        'preis_modus', 'preis_pro_person_manuell',
        'servierform_id', 'eventtyp_id', // Facetten (Umbau-Spec Phase 4)
    ];

    /** Felder, die leer („" / 0) als NULL gespeichert werden (FK/optional). */
    private const FELDER_NULLBAR = [
        'category_id', 'schreibstil_id', 'zielpreis_pro_person', 'preis_pro_person_manuell',
        'servierform_id', 'eventtyp_id',
    ];

    public function update(Team $team, int $id, array $in): FoodAlchemistConcept
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($concept, $team);
        $update = array_intersect_key($in, array_flip(self::FELDER));
        foreach (self::FELDER_NULLBAR as $feld) {
            if (array_key_exists($feld, $update) && ($update[$feld] === '' || $update[$feld] === null
                || (in_array($feld, ['category_id', 'schreibstil_id'], true) && (int) $update[$feld] === 0))) {
                $update[$feld] = null;
            }
        }
        if (array_key_exists('klasse', $update)) {
            $update['klasse'] = $this->norm($update['klasse']);
        }
        $concept->update($update);

        return $concept->refresh();
    }

    // ── Facetten: Mehrfach-Dimensionen (Umbau-Spec Phase 4) ─────────────────

    /** @param  list<int>  $ids */
    public function syncEinsatzmomente(Team $team, int $id, array $ids): void
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($concept, $team);
        $concept->einsatzmomente()->sync(array_map('intval', $ids));
    }

    /** @param  list<int>  $ids */
    public function syncSaisons(Team $team, int $id, array $ids): void
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($concept, $team);
        $concept->saisons()->sync(array_map('intval', $ids));
    }

    // ── Sektor-Eignung (Politur · VK-Parität §10.8, mehrwertig wie Rezept) ───

    /** @return list<string> aktive Sektor-Slugs des Concepts. */
    public function sektorEignungSlugs(FoodAlchemistConcept $concept): array
    {
        return $concept->sektorEignungen()->pluck('sektor_slug')->all();
    }

    /** Sektor zuweisen — reaktiviert soft-deleted Zeilen (wie RecipeService::setzeEignung, R11). */
    public function setzeSektorEignung(Team $team, int $conceptId, string $slug): void
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $this->guardOwner($concept, $team);
        $slug = trim($slug);
        if ($slug === '') {
            return;
        }
        $row = FoodAlchemistConceptSektorEignung::withTrashed()
            ->where('concept_id', $conceptId)->where('sektor_slug', $slug)->first();
        if ($row !== null) {
            if ($row->trashed()) {
                $row->restore();
            }

            return;
        }
        FoodAlchemistConceptSektorEignung::create([
            'team_id' => $concept->team_id, 'concept_id' => $conceptId,
            'sektor_slug' => $slug, 'quelle' => 'manual',
        ]);
    }

    public function entferneSektorEignung(Team $team, int $conceptId, string $slug): void
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $this->guardOwner($concept, $team);
        FoodAlchemistConceptSektorEignung::where('concept_id', $conceptId)
            ->where('sektor_slug', trim($slug))->delete();
    }

    /** Distinkte verwendete Klassen (Filter) + freies Klasse-Vokabular (§10.3). */
    public function klassen(Team $team): array
    {
        $verwendet = FoodAlchemistConcept::visibleToTeam($team)
            ->whereNotNull('klasse')->distinct()->orderBy('klasse')->pluck('klasse')->all();
        $vokabular = FoodAlchemistVocabKlasse::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->pluck('name')->all();

        return collect($verwendet)->merge($vokabular)->unique()->values()->all();
    }

    public function delete(Team $team, int $id): void
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($concept, $team);

        // GT-FB-4 / V-06: referenziertes Concept nicht still löschen — erst aus den Foodbooks nehmen.
        $fbs = $this->verwendetInFoodbooks($team, $id);
        if ($fbs->isNotEmpty()) {
            throw new \RuntimeException('Concept wird in '.$fbs->count().' Foodbook(s) verwendet — dort zuerst entfernen.');
        }

        $concept->delete();
    }

    // ── Slots ────────────────────────────────────────────────────────────

    public function addSlot(Team $team, int $conceptId, array $in = []): FoodAlchemistConceptSlot
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $this->guardOwner($concept, $team);

        $slot = $concept->slots()->create([
            'team_id' => $concept->team_id,
            'rolle' => $this->norm($in['rolle'] ?? null),
            'titel' => $this->norm($in['titel'] ?? null),
            'is_pflicht' => (bool) ($in['is_pflicht'] ?? true),
            'position' => (int) ($concept->slots()->max('position') ?? -1) + 1,
        ]);
        $this->refreshCache($concept);

        return $slot;
    }

    public function updateSlot(Team $team, int $slotId, array $in): FoodAlchemistConceptSlot
    {
        $slot = $this->ownedSlot($team, $slotId);
        $slot->update([
            'rolle' => array_key_exists('rolle', $in) ? $this->norm($in['rolle']) : $slot->rolle,
            'titel' => array_key_exists('titel', $in) ? $this->norm($in['titel']) : $slot->titel,
            'is_pflicht' => array_key_exists('is_pflicht', $in) ? (bool) $in['is_pflicht'] : $slot->is_pflicht,
        ]);

        return $slot->refresh();
    }

    /** B3: Struktur-Block-Typen (keine Preis-Position) — analog Foodbook-Block. */
    public const STRUKTUR_TYPEN = ['text', 'spacer', 'header', 'header_preis'];

    /**
     * B3: Struktur-Block (Text/Leerzeile/Überschrift/Überschrift+Preis) als Position anlegen —
     * wie ein Foodbook-Block, aber am Concept. Trägt nicht zum Wareneinsatz/VK bei.
     */
    public function addBlock(Team $team, int $conceptId, string $type, array $in = []): FoodAlchemistConceptSlot
    {
        if (! in_array($type, self::STRUKTUR_TYPEN, true)) {
            throw new \RuntimeException('Unbekannter Block-Typ.');
        }
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $this->guardOwner($concept, $team);

        return $concept->slots()->create([
            'team_id' => $concept->team_id,
            'type' => $type,
            'titel' => $this->norm($in['titel'] ?? null),
            'text_inhalt' => $this->norm($in['text_inhalt'] ?? null),
            'hoehe' => $type === 'spacer' ? ($in['hoehe'] ?? 'mittel') : null,
            'preis_wert' => $type === 'header_preis' ? ($in['preis_wert'] ?? null) : null,
            'preis_basis' => $type === 'header_preis' ? ($in['preis_basis'] ?? 'person') : null,
            'is_pflicht' => false,
            'position' => (int) ($concept->slots()->max('position') ?? -1) + 1,
        ]);
    }

    /** B3: Inhalt eines Struktur-Blocks pflegen (Titel/Text/Höhe/Preis). */
    public function updateBlock(Team $team, int $slotId, array $in): FoodAlchemistConceptSlot
    {
        $slot = $this->ownedSlot($team, $slotId);
        $update = [];
        foreach (['titel', 'text_inhalt', 'hoehe', 'preis_basis'] as $f) {
            if (array_key_exists($f, $in)) {
                $update[$f] = $this->norm($in[$f]);
            }
        }
        if (array_key_exists('preis_wert', $in)) {
            $update['preis_wert'] = ($in['preis_wert'] !== '' && $in['preis_wert'] !== null) ? (float) $in['preis_wert'] : null;
        }
        if ($update !== []) {
            $slot->update($update);
        }

        return $slot->refresh();
    }

    /**
     * Befüllt einen Slot mit GENAU EINEM: Paket ODER festem Gericht.
     * Das jeweils andere wird geleert (Invariante „genau eines").
     */
    public function fillSlot(Team $team, int $slotId, array $in): FoodAlchemistConceptSlot
    {
        $slot = $this->ownedSlot($team, $slotId);

        if (! empty($in['paket_id'])) {
            $slot->update([
                'type' => 'paket',
                'paket_id' => (int) $in['paket_id'],
                'vk_recipe_id' => null, 'menge' => null, 'einheit_vocab_id' => null,
            ]);
        } elseif (! empty($in['vk_recipe_id'])) {
            $slot->update([
                // B2: Gericht (VK) ODER Basisrezept — beide referenzieren vk_recipe_id, `type` unterscheidet.
                'type' => ($in['type'] ?? null) === 'basisrezept' ? 'basisrezept' : 'gericht',
                'vk_recipe_id' => (int) $in['vk_recipe_id'],
                'menge' => $in['menge'] ?? null, 'einheit_vocab_id' => $in['einheit_vocab_id'] ?? null,
                'paket_id' => null,
            ]);
        } else {
            $slot->update(['type' => 'gericht', 'paket_id' => null, 'vk_recipe_id' => null, 'menge' => null, 'einheit_vocab_id' => null]);
        }
        $this->refreshCache($slot->concept);

        return $slot->refresh();
    }

    /** Concept-übergreifendes Wording: Brand-Voice-Anzeigename einer Position setzen/leeren. */
    public function setSlotWording(Team $team, int $slotId, ?string $text): FoodAlchemistConceptSlot
    {
        $slot = $this->ownedSlot($team, $slotId);
        $text = $text !== null ? trim($text) : null;
        $slot->update(['wording' => $text === '' ? null : $text]);

        return $slot->refresh();
    }

    /**
     * #388: Geschirr-Zuordnung je Gericht-Slot. $rolle ∈ haupt|alt; $itemId=null = entfernen.
     * Item muss team-sichtbar sein (FoodAlchemistGeschirrItem::visibleToTeam).
     */
    public function setSlotGeschirr(Team $team, int $slotId, string $rolle, ?int $itemId): FoodAlchemistConceptSlot
    {
        $slot = $this->ownedSlot($team, $slotId);
        if ($itemId !== null && ! \Platform\FoodAlchemist\Models\FoodAlchemistGeschirrItem::visibleToTeam($team)->whereKey($itemId)->exists()) {
            throw new \RuntimeException('Geschirr-Artikel nicht sichtbar.');
        }
        $spalte = $rolle === 'alt' ? 'geschirr_alt_item_id' : 'geschirr_item_id';
        $slot->update([$spalte => $itemId]);

        return $slot->refresh();
    }

    /** Inline-Pflege Menge + Einheit einer Gericht-/Basisrezept-Position (Zeilen-Editor). */
    public function setSlotMengeEinheit(Team $team, int $slotId, ?float $menge, ?int $einheitId = null): FoodAlchemistConceptSlot
    {
        $slot = $this->ownedSlot($team, $slotId);
        $slot->update([
            'menge' => $menge,
            'einheit_vocab_id' => $einheitId ?: null,
        ]);
        $this->refreshCache($slot->concept);

        return $slot->refresh();
    }

    public function removeSlot(Team $team, int $slotId): void
    {
        $slot = $this->ownedSlot($team, $slotId);
        $concept = $slot->concept;
        $slot->delete();
        $this->refreshCache($concept);
    }

    /** @param list<int> $ids neue Reihenfolge */
    public function reorderSlots(Team $team, int $conceptId, array $ids): void
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $this->guardOwner($concept, $team);
        DB::transaction(function () use ($conceptId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistConceptSlot::where('id', (int) $id)->where('concept_id', $conceptId)->update(['position' => $i]);
            }
        });
    }

    /**
     * Politur F-11: „Wo verwendet?" — Foodbooks, die dieses Concept über einen
     * Block referenzieren (Bibliotheks-Sicht über Portfolios/Jahre, §10.8).
     */
    public function verwendetInFoodbooks(Team $team, int $conceptId): \Illuminate\Support\Collection
    {
        $foodbookIds = DB::table('foodalchemist_foodbook_blocks as b')
            ->join('foodalchemist_foodbook_kapitel as k', 'k.id', '=', 'b.kapitel_id')
            ->where('b.concept_id', $conceptId)->whereNull('b.deleted_at')
            ->distinct()->pluck('k.foodbook_id');

        return FoodAlchemistFoodbook::visibleToTeam($team)
            ->whereIn('id', $foodbookIds)->orderBy('bezeichnung')
            ->get(['id', 'bezeichnung', 'jahr', 'kunde', 'status']);
    }

    /** Austauschbare Pakete für einen Slot = gleiche Rolle (M13-Vorstufe). */
    /**
     * B4: Aus markierten Gericht-/Basisrezept-Positionen ein wiederverwendbares Paket bilden —
     * die Positionen werden durch EINE Paket-Position ersetzt. Struktur-Blöcke/Pakete in der
     * Auswahl werden ignoriert (nur vk_recipe_id-Positionen wandern ins Paket).
     */
    public function bildePaketAusPositionen(Team $team, int $conceptId, array $slotIds, string $name, ?string $rolle = null): FoodAlchemistConceptSlot
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $this->guardOwner($concept, $team);

        return DB::transaction(function () use ($team, $concept, $slotIds, $name, $rolle) {
            $slots = $concept->slots()->whereIn('id', $slotIds)->whereNotNull('vk_recipe_id')
                ->orderBy('position')->get();
            if ($slots->isEmpty()) {
                throw new \RuntimeException('Keine Gericht-/Basisrezept-Positionen ausgewählt.');
            }
            $minPos = (int) $slots->min('position');

            $paketSvc = app(PaketService::class);
            // auto-Preis: das gebildete Paket = Σ der Gericht-Preise → Concept-Summe bleibt unverändert.
            $paket = $paketSvc->create($team, ['name' => trim($name) !== '' ? trim($name) : 'Paket', 'rolle' => $rolle, 'preis_modus' => 'auto']);
            $paketSvc->syncGerichte($team, $paket->id, $slots->map(fn ($s) => [
                'vk_recipe_id' => $s->vk_recipe_id, 'menge' => $s->menge, 'einheit_vocab_id' => $s->einheit_vocab_id,
            ])->values()->all());

            $concept->slots()->whereIn('id', $slots->pluck('id'))->delete();
            $neu = $concept->slots()->create([
                'team_id' => $concept->team_id, 'type' => 'paket', 'paket_id' => $paket->id,
                'rolle' => $rolle, 'position' => $minPos, 'is_pflicht' => true,
            ]);
            $this->refreshCache($concept->refresh());

            return $neu;
        });
    }

    public function tauschbarePakete(Team $team, FoodAlchemistConceptSlot $slot): Collection
    {
        return FoodAlchemistPaket::visibleToTeam($team)
            ->where('is_inactive', false)
            ->when($slot->rolle, fn ($q, $rolle) => $q->where('rolle', $rolle))
            ->orderBy('name')
            ->get(['id', 'name', 'rolle', 'preis_pro_person']);
    }

    // ── M10-04: Live-Output-Preis (Σ gespeicherte Paket-Preise) ─────────

    /**
     * Concept-Preis = Σ Slot-Preise aus den GESPEICHERTEN Paket-Preisen
     * (+ feste Gerichte). KEIN Kaskaden-Recompute — ein Tausch ändert nur die
     * betroffene Zeile.
     *
     * @return array{zeilen: list<array>, preis_pro_person: float, ek_pro_person: float, hat_stale: bool, hat_leer: bool, hat_ek_luecke: bool}
     */
    public function preisCockpit(FoodAlchemistConcept $concept): array
    {
        $concept->loadMissing(['slots' => fn ($q) => $q->orderBy('position'),
            'slots.einheit:id,slug,dimension,default_in_g',
            'slots.paket:id,name,preis_pro_person,ek_pro_person,preis_stale',
            'slots.gericht:id,name,vk_netto,ek_total_eur,vk_anzahl_einheiten,vk_menge_pro_einheit_g,yield_kg,ertrag_stueck']);

        $zeilen = [];
        $vkTotal = 0.0;
        $ekTotal = 0.0;
        $hatStale = false;
        $hatLeer = false;
        $hatEkLuecke = false;

        foreach ($concept->slots as $slot) {
            if (in_array($slot->type, self::STRUKTUR_TYPEN, true)) {
                continue; // Struktur-Blöcke (Text/Leerzeile/Header) sind keine Preis-Positionen
            }
            if ($slot->paket_id !== null && $slot->paket) {
                $vk = (float) ($slot->paket->preis_pro_person ?? 0);
                $ek = (float) ($slot->paket->ek_pro_person ?? 0);
                $hatStale = $hatStale || (bool) $slot->paket->preis_stale;
                $zeilen[] = ['slot_id' => $slot->id, 'typ' => 'paket', 'rolle' => $slot->rolle, 'wording' => $slot->wording,
                    'label' => $slot->paket->name, 'preis' => $vk, 'ek' => round($ek, 2), 'ek_fehlt' => false, 'stale' => (bool) $slot->paket->preis_stale];
            } elseif ($slot->vk_recipe_id !== null && $slot->gericht) {
                // Umbau-Spec Phase 5: geltende Darreichung auflösen (explizit → Konzept-Form → Standard)
                $slot->setRelation('concept', $concept);
                $dar = app(DarreichungResolver::class)->fuerSlot($slot);
                $darPortionG = $dar?->menge_pro_einheit_g !== null ? (float) $dar->menge_pro_einheit_g : null;
                // Einheit-abhängige Mengen-Umrechnung — EINE Stelle (Konsistenz zu ConcepterAggregate/Paket).
                $pae = ConcepterAggregateService::portionsAequivalent(
                    $slot->menge !== null ? (float) $slot->menge : null,
                    $slot->einheit,
                    $slot->gericht,
                    $darPortionG,
                );
                $ekFehlt = $pae === null;       // Gramm-Position ohne Portionsgewicht → ehrlich „unbekannt"
                // Teiler von ek_total: Stück-Modus (kg↔Stück) → ertrag_stueck, sonst Portionszahl.
                $stueck = ConcepterAggregateService::stueckModus($slot->einheit, $slot->gericht);
                $anzahl = $stueck
                    ? (float) $slot->gericht->ertrag_stueck
                    : max(1, (int) ($slot->gericht->vk_anzahl_einheiten ?? 1));
                $vk = $ekFehlt ? 0.0 : (float) ($dar?->vk_netto ?? $slot->gericht->vk_netto ?? 0) * $pae;
                $ek = $ekFehlt ? 0.0
                    : (($dar?->ek_portion !== null && ! $stueck)
                        ? (float) $dar->ek_portion * $pae
                        : (float) ($slot->gericht->ek_total_eur ?? 0) / $anzahl * $pae);
                $hatEkLuecke = $hatEkLuecke || $ekFehlt;
                $zeilen[] = ['slot_id' => $slot->id, 'typ' => 'gericht', 'rolle' => $slot->rolle, 'wording' => $slot->wording,
                    'label' => $slot->gericht->name, 'preis' => round($vk, 2),
                    'ek' => $ekFehlt ? null : round($ek, 2), 'ek_fehlt' => $ekFehlt, 'stale' => false];
            } else {
                $vk = 0.0;
                $ek = 0.0;
                $hatLeer = true;
                $zeilen[] = ['slot_id' => $slot->id, 'typ' => 'leer', 'rolle' => $slot->rolle,
                    'label' => $slot->titel ?? '(leer)', 'preis' => null, 'ek' => null, 'ek_fehlt' => false, 'stale' => false];
            }
            $vkTotal += $vk;
            $ekTotal += $ek;
        }

        $summe = round($vkTotal, 2);
        // Manueller Concept-VK (z. B. Lunchbuffet, Preis auf EK-Basis) überschreibt die Summe; EK bleibt aus den Positionen.
        $manuell = ($concept->preis_modus ?? 'auto') === 'manuell' && $concept->preis_pro_person_manuell !== null;
        $preis = $manuell ? round((float) $concept->preis_pro_person_manuell, 2) : $summe;

        return [
            'zeilen' => $zeilen,
            'preis_pro_person' => $preis,
            'summe_pro_person' => $summe,        // berechnete Summe der Positionen (auch im manuellen Modus, zur Anzeige)
            'preis_modus' => $manuell ? 'manuell' : 'auto',
            'ek_pro_person' => round($ekTotal, 2),
            'hat_stale' => $hatStale,
            'hat_leer' => $hatLeer,
            'hat_ek_luecke' => $hatEkLuecke,   // ≥1 Gramm-Position ohne Portionsgewicht → EK unvollständig
        ];
    }

    /**
     * Mengen-Hochrechnung für eine GEGEBENE Pax-Zahl — je Gericht (aus den
     * Paketen + fest gesetzte Gerichte) `menge` pro Person × Pax. Das Concept
     * ist person-UNABHÄNGIG (D-CON-5) — die Pax kommt vom Aufruf (Foodbook/Angebot,
     * M11), nicht vom Concept. `menge` = Menge pro Person in der Einheit.
     *
     * @return list<array{rolle:?string, paket:?string, gericht:string, menge_pro_person:?float, einheit:?string, gesamt_menge:?float}>
     */
    public function mengenHochrechnung(FoodAlchemistConcept $concept, ?int $personen = null): array
    {
        $concept->loadMissing([
            'slots' => fn ($q) => $q->orderBy('position'),
            'slots.paket.gerichte.gericht:id,name',
            'slots.paket.gerichte.einheit:id,slug,display_de',
            'slots.gericht:id,name', 'slots.einheit:id,slug,display_de',
        ]);

        $zeilen = [];
        foreach ($concept->slots as $slot) {
            if ($slot->paket_id !== null && $slot->paket) {
                foreach ($slot->paket->gerichte as $bg) {
                    $mpp = $bg->menge !== null ? (float) $bg->menge : null;
                    $zeilen[] = [
                        'rolle' => $slot->rolle, 'paket' => $slot->paket->name,
                        'gericht' => $bg->gericht?->name ?? '—',
                        'menge_pro_person' => $mpp,
                        'einheit' => $bg->einheit?->display_de ?? $bg->einheit?->slug,
                        'gesamt_menge' => $mpp !== null && $personen !== null ? round($mpp * $personen, 2) : null,
                    ];
                }
            } elseif ($slot->vk_recipe_id !== null && $slot->gericht) {
                $mpp = $slot->menge !== null ? (float) $slot->menge : null;
                $zeilen[] = [
                    'rolle' => $slot->rolle, 'paket' => null,
                    'gericht' => $slot->gericht->name,
                    'menge_pro_person' => $mpp,
                    'einheit' => $slot->einheit?->display_de ?? $slot->einheit?->slug,
                    'gesamt_menge' => $mpp !== null && $personen !== null ? round($mpp * $personen, 2) : null,
                ];
            }
        }

        return $zeilen;
    }

    /**
     * C-09: Allergen-/Diät-Rollup über ALLE Gerichte des Concepts (aus den
     * Paketen + feste Gerichte). „all"-Flags (vegan/vegetarisch/halal/glutenfrei/
     * laktosefrei) gelten nur, wenn ALLE Gerichte sie erfüllen; „enthält"-Flags
     * (Schwein/Rind) bei MIND. EINEM. Konfidenz = schwächstes Glied. Liest die
     * GL-08-Spec-Flags am Rezept — keine eigene Aggregation (eine Regel-Stelle).
     *
     * @return array{n_gerichte:int, is_vegan:bool, is_vegetarian:bool, is_halal:bool, is_gluten_free:bool, is_lactose_free:bool, contains_pork:bool, contains_beef:bool, konfidenz:string}
     */
    public function allergenRollup(FoodAlchemistConcept $concept): array
    {
        $concept->loadMissing([
            'slots.paket.gerichte.gericht:id,spec_is_vegan,spec_is_vegetarian,spec_is_halal,spec_is_gluten_free,spec_is_lactose_free,spec_contains_pork,spec_contains_beef,allergene_konfidenz',
            'slots.gericht:id,spec_is_vegan,spec_is_vegetarian,spec_is_halal,spec_is_gluten_free,spec_is_lactose_free,spec_contains_pork,spec_contains_beef,allergene_konfidenz',
        ]);

        $gerichte = collect();
        foreach ($concept->slots as $slot) {
            if ($slot->paket) {
                $gerichte = $gerichte->merge($slot->paket->gerichte->pluck('gericht')->filter());
            }
            if ($slot->gericht) {
                $gerichte->push($slot->gericht);
            }
        }

        // M10R-1: kanonische Rollup-Stelle (eine Regel) — Output-Form unverändert.
        return app(ConcepterAggregateService::class)->allergenRollupFromGerichte($gerichte);
    }

    private function refreshCache(FoodAlchemistConcept $concept): void
    {
        // M10R-1: Preis-Cache + Voll-Aggregat-Caches (Nährwerte/Person, Arbeitszeit, EK).
        $agg = app(ConcepterAggregateService::class)->conceptAggregat($concept);
        $concept->update([
            'preis_pro_person_cache' => $this->preisCockpit($concept)['preis_pro_person'],
            'naehrwerte_cache' => $agg['naehrwerte'],
            'arbeitszeit_min_cache' => $agg['arbeitszeit_min'],
            'ek_pro_person_cache' => $agg['ek_pro_person'],
        ]);
    }

    // ── M10-05: Vorlage = Fork ─────────────────────────────────────────────

    /** „Aus Vorlage starten" — forkt das Slot-Gerüst in ein neues, eigenständiges Concept. */
    public function forkVonVorlage(Team $team, int $vorlageId, string $name): FoodAlchemistConcept
    {
        $vorlage = FoodAlchemistConcept::visibleToTeam($team)->with('slots')->findOrFail($vorlageId);

        return DB::transaction(function () use ($team, $vorlage, $name) {
            $neu = FoodAlchemistConcept::create([
                'team_id' => $team->id, 'name' => $name,
                'anlass' => $vorlage->anlass, 'niveau' => $vorlage->niveau,
                'status' => 'draft', 'is_vorlage' => false, 'vorlage_quelle_id' => $vorlage->id,
            ]);
            foreach ($vorlage->slots as $slot) {
                $neu->slots()->create([
                    'team_id' => $team->id, 'rolle' => $slot->rolle, 'titel' => $slot->titel,
                    'position' => $slot->position, 'is_pflicht' => $slot->is_pflicht,
                    'paket_id' => $slot->paket_id,          // Paket bleibt Referenz (zieht durch)
                    'vk_recipe_id' => $slot->vk_recipe_id, 'menge' => $slot->menge,
                    'einheit_vocab_id' => $slot->einheit_vocab_id,
                ]);
            }
            $this->refreshCache($neu);

            return $neu->refresh();
        });
    }

    /** C-13: Concept duplizieren (Stamm + Slots, eigenständig, „(Kopie)"). */
    public function duplicate(Team $team, int $id): FoodAlchemistConcept
    {
        $orig = FoodAlchemistConcept::visibleToTeam($team)->with('slots')->findOrFail($id);

        return DB::transaction(function () use ($team, $orig) {
            $felder = array_intersect_key($orig->attributesToArray(), array_flip([
                'konsumenten_name', 'anlass', 'niveau', 'klasse', 'geschmacksrichtung', 'schreibstil_id',
                'category_id', 'beschreibung', 'zusatztext', 'brief', 'diaet_vorgabe', 'struktur_vorgabe',
                'saison', 'zielgruppe', 'zielpreis_pro_person', 'is_vorlage',
            ]));
            $neu = FoodAlchemistConcept::create($felder + [
                'team_id' => $team->id, 'name' => $orig->name . ' (Kopie)', 'status' => 'draft',
            ]);
            foreach ($orig->slots as $slot) {
                $neu->slots()->create([
                    'team_id' => $team->id, 'rolle' => $slot->rolle, 'titel' => $slot->titel,
                    'position' => $slot->position, 'is_pflicht' => $slot->is_pflicht,
                    'paket_id' => $slot->paket_id, 'vk_recipe_id' => $slot->vk_recipe_id,
                    'menge' => $slot->menge, 'einheit_vocab_id' => $slot->einheit_vocab_id,
                ]);
            }
            $this->refreshCache($neu);

            return $neu->refresh();
        });
    }

    /** „Als Vorlage speichern" — friert das aktuelle Slot-Gerüst als neue Vorlage ein. */
    public function alsVorlageSpeichern(Team $team, int $conceptId, ?string $name = null): FoodAlchemistConcept
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $vorlage = $this->forkVonVorlage($team, $conceptId, $name ?: ($concept->name . ' (Vorlage)'));
        $vorlage->update(['is_vorlage' => true, 'vorlage_quelle_id' => null, 'status' => 'aktiv']);

        return $vorlage->refresh();
    }

    // ── M13: Zielpreis-Konfigurator (Modus im Concept-Editor) ───────────────

    /**
     * Schlägt eine Paket-Kombination vor, die dem Zielpreis (€/Person) am nächsten
     * kommt. Greift NUR an Paket-Slots (gleiche Rolle = `tauschbarePakete`); feste
     * Gerichte sind Fixkosten. Deterministisch: exhaustiv bei kleiner Kombinatorik,
     * sonst greedy Hill-Climb. Persistiert NICHTS — erst `zielpreisAnwenden`.
     *
     * @return array{vorschlag: array<int,int>, preis: float, aktuell: float, ziel: float,
     *               aenderungen: int, min: float, max: float, fix: float}
     */
    public function zielpreisVorschlag(Team $team, int $conceptId, float $ziel): array
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->with('slots')->findOrFail($conceptId);

        $fix = 0.0;
        $slots = [];   // adjustable: ['slot_id', 'current_paket_id', 'kandidaten' => [paket_id => preis]]
        foreach ($concept->slots as $slot) {
            $kandidaten = $this->tauschbarePakete($team, $slot)
                ->mapWithKeys(fn ($p) => [(int) $p->id => (float) ($p->preis_pro_person ?? 0)])->all();
            // aktuelles Paket aufnehmen, falls (z. B. inaktiv) nicht in den Kandidaten
            if ($slot->paket_id !== null && ! isset($kandidaten[$slot->paket_id]) && $slot->paket) {
                $kandidaten[(int) $slot->paket_id] = (float) ($slot->paket->preis_pro_person ?? 0);
            }
            if (! empty($kandidaten)) {
                $slots[] = ['slot_id' => (int) $slot->id, 'current' => $slot->paket_id !== null ? (int) $slot->paket_id : null, 'kandidaten' => $kandidaten];
            } elseif ($slot->vk_recipe_id !== null && $slot->gericht) {
                // Umbau-Spec Phase 5: aufgelöste Darreichung gewinnt (Fixanteil des Zielpreis-Solvers)
                $slot->setRelation('concept', $concept);
                $darVk = app(DarreichungResolver::class)->vkNettoFuerSlot($slot);
                $fix += (float) ($darVk ?? 0) * ($slot->menge !== null ? (float) $slot->menge : 1.0);
            }
        }

        $aktuell = $this->preisCockpit($concept)['preis_pro_person'];
        $zielRest = $ziel - $fix;

        // erreichbare Spanne (Σ min/max je Slot)
        $min = $fix;
        $max = $fix;
        foreach ($slots as $s) {
            $min += min($s['kandidaten']);
            $max += max($s['kandidaten']);
        }

        $wahl = $this->loeseZielpreis($slots, $zielRest);  // [slot_id => paket_id]
        $preis = $fix;
        foreach ($wahl as $slotId => $paketId) {
            $s = collect($slots)->firstWhere('slot_id', $slotId);
            $preis += $s['kandidaten'][$paketId];
        }
        $aenderungen = collect($slots)->filter(fn ($s) => ($wahl[$s['slot_id']] ?? null) !== $s['current'])->count();

        return [
            'vorschlag' => $wahl,
            'preis' => round($preis, 2),
            'aktuell' => $aktuell,
            'ziel' => round($ziel, 2),
            'aenderungen' => $aenderungen,
            'min' => round($min, 2),
            'max' => round($max, 2),
            'fix' => round($fix, 2),
        ];
    }

    /**
     * Kombinations-Optimierung: eine Kandidaten-ID je Slot so wählen, dass die Summe
     * dem Rest-Ziel am nächsten kommt. Exhaustiv (≤ 4000 Kombis), sonst greedy.
     *
     * @param  list<array{slot_id:int, current:?int, kandidaten:array<int,float>}>  $slots
     * @return array<int,int> slot_id => paket_id
     */
    private function loeseZielpreis(array $slots, float $zielRest): array
    {
        if (empty($slots)) {
            return [];
        }
        $kombis = array_product(array_map(fn ($s) => count($s['kandidaten']), $slots));

        if ($kombis > 0 && $kombis <= 4000) {
            $bestGap = INF;
            $best = [];
            $listen = array_map(fn ($s) => array_keys($s['kandidaten']), $slots);
            $n = count($slots);
            for ($i = 0; $i < $kombis; $i++) {
                $rest = $i;
                $sum = 0.0;
                $wahl = [];
                for ($j = 0; $j < $n; $j++) {
                    $anz = count($listen[$j]);
                    $idx = $rest % $anz;
                    $rest = intdiv($rest, $anz);
                    $pid = $listen[$j][$idx];
                    $wahl[$slots[$j]['slot_id']] = $pid;
                    $sum += $slots[$j]['kandidaten'][$pid];
                }
                $gap = abs($sum - $zielRest);
                if ($gap < $bestGap - 1e-9) {
                    $bestGap = $gap;
                    $best = $wahl;
                }
            }

            return $best;
        }

        // Greedy Hill-Climb: Start = aktuelles (oder günstigstes) Paket je Slot
        $wahl = [];
        $sum = 0.0;
        foreach ($slots as $s) {
            $pid = $s['current'] !== null && isset($s['kandidaten'][$s['current']])
                ? $s['current'] : array_key_first($s['kandidaten']);
            $wahl[$s['slot_id']] = $pid;
            $sum += $s['kandidaten'][$pid];
        }
        for ($iter = 0; $iter < 200; $iter++) {
            $bestGap = abs($sum - $zielRest);
            $move = null;
            foreach ($slots as $s) {
                foreach ($s['kandidaten'] as $pid => $preis) {
                    if ($pid === $wahl[$s['slot_id']]) {
                        continue;
                    }
                    $neu = $sum - $s['kandidaten'][$wahl[$s['slot_id']]] + $preis;
                    if (abs($neu - $zielRest) < $bestGap - 1e-9) {
                        $bestGap = abs($neu - $zielRest);
                        $move = ['slot' => $s['slot_id'], 'pid' => $pid, 'sum' => $neu];
                    }
                }
            }
            if ($move === null) {
                break;
            }
            $wahl[$move['slot']] = $move['pid'];
            $sum = $move['sum'];
        }

        return $wahl;
    }

    /** Wendet einen Zielpreis-Vorschlag an (Paket-Tausch je Slot). */
    public function zielpreisAnwenden(Team $team, int $conceptId, array $vorschlag): FoodAlchemistConcept
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $this->guardOwner($concept, $team);
        foreach ($vorschlag as $slotId => $paketId) {
            $this->fillSlot($team, (int) $slotId, ['paket_id' => (int) $paketId]);
        }

        return $concept->refresh();
    }

    // ── M10c-B: Kategorien (Baum) ──────────────────────────────────────────

    /**
     * Flache, vorsortierte Kategorie-Liste mit Tiefe (Pre-Order) — die UI rendert
     * daraus Baum (Einrückung) und Select. Enthält `ancestors` + `has_children`
     * für <x-foodalchemist::tree>.
     *
     * @return list<array{id:int, name:string, parent_id:?int, depth:int, label:string, ancestors:list<int>, has_children:bool}>
     */
    public function categoriesFlat(Team $team): array
    {
        $alle = FoodAlchemistConceptCategory::visibleToTeam($team)
            ->orderBy('position')->orderBy('name')->get(['id', 'name', 'parent_id']);

        return $this->flacherBaum($alle);
    }

    /**
     * Rollup-Anzahl Concepts je Kategorie (Kategorie + alle Unterkategorien) — passend zum
     * descendant-inklusiven Filter in paginateBrowser. Speist die Count-Badges der Kategorie-
     * Sidebar (gleiche Optik wie Basisrezepte-/Gerichte-Browser). Nur >0 wird zurückgegeben.
     *
     * @return array<int, int>
     */
    public function categoryCounts(Team $team, bool $vorlagen = false): array
    {
        $direkt = FoodAlchemistConcept::visibleToTeam($team)
            ->when($vorlagen, fn ($q) => $q->vorlagen(), fn ($q) => $q->echte())
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) as c')
            ->groupBy('category_id')
            ->pluck('c', 'category_id')->all();

        $counts = [];
        foreach ($this->categoriesFlat($team) as $row) {
            $self = (int) ($direkt[$row['id']] ?? 0);
            if ($self === 0) {
                continue; // Kinder tragen ihre eigenen Counts über ihre ancestors bei
            }
            $counts[$row['id']] = ($counts[$row['id']] ?? 0) + $self;
            foreach ($row['ancestors'] as $anc) {
                $counts[$anc] = ($counts[$anc] ?? 0) + $self;
            }
        }

        return $counts;
    }

    /**
     * Pre-Order-Walk über eine Baum-Sammlung (Items mit id/name/parent_id) → flache Liste
     * mit Tiefe, Vorfahren-Kette und Kinder-Flag. Eingabe-Format für <x-foodalchemist::tree>.
     *
     * @param  Collection<int, object>  $alle
     * @return list<array{id:int, name:string, parent_id:?int, depth:int, label:string, ancestors:list<int>, has_children:bool}>
     */
    private function flacherBaum(Collection $alle): array
    {
        $byParent = $alle->groupBy(fn ($c) => $c->parent_id ?? 0);
        $out = [];
        $walk = function ($parentId, int $depth, array $ancestors) use (&$walk, $byParent, &$out) {
            foreach ($byParent[$parentId] ?? [] as $c) {
                $id = (int) $c->id;
                $out[] = [
                    'id' => $id, 'name' => $c->name,
                    'parent_id' => $c->parent_id !== null ? (int) $c->parent_id : null,
                    'depth' => $depth, 'label' => str_repeat('— ', $depth) . $c->name,
                    'ancestors' => $ancestors,
                    'has_children' => isset($byParent[$id]),
                ];
                $walk($id, $depth + 1, [...$ancestors, $id]);
            }
        };
        $walk(0, 0, []);

        return $out;
    }

    /** Kategorie-ID + alle Nachfahren (Filter inkl. Untergruppen). @return list<int> */
    public function descendantIds(Team $team, int $categoryId): array
    {
        $kinder = [];
        foreach ($this->categoriesFlat($team) as $row) {
            $kinder[$row['parent_id'] ?? 0][] = $row['id'];
        }
        $ids = [];
        $stack = [$categoryId];
        while ($stack) {
            $id = array_pop($stack);
            $ids[] = $id;
            foreach ($kinder[$id] ?? [] as $kid) {
                $stack[] = $kid;
            }
        }

        return $ids;
    }

    public function createCategory(Team $team, string $name, ?int $parentId = null): FoodAlchemistConceptCategory
    {
        $name = trim($name);
        $maxPos = FoodAlchemistConceptCategory::where('team_id', $team->id)
            ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
            ->max('position');

        return FoodAlchemistConceptCategory::create([
            'team_id' => $team->id,
            'name' => $name !== '' ? $name : 'Neue Kategorie',
            'parent_id' => $parentId ?: null,
            'position' => (int) $maxPos + 1,
        ]);
    }

    public function renameCategory(Team $team, int $id, string $name): void
    {
        $cat = FoodAlchemistConceptCategory::visibleToTeam($team)->findOrFail($id);
        $this->guardOwnerCategory($cat, $team);
        $name = trim($name);
        if ($name !== '') {
            $cat->update(['name' => $name]);
        }
    }

    /** Löschen: Kinder + zugeordnete Concepts an den Eltern hängen, dann löschen. */
    public function deleteCategory(Team $team, int $id): void
    {
        $cat = FoodAlchemistConceptCategory::visibleToTeam($team)->findOrFail($id);
        $this->guardOwnerCategory($cat, $team);
        DB::transaction(function () use ($cat) {
            FoodAlchemistConceptCategory::where('parent_id', $cat->id)->update(['parent_id' => $cat->parent_id]);
            FoodAlchemistConcept::where('category_id', $cat->id)->update(['category_id' => $cat->parent_id]);
            $cat->delete();
        });
    }

    // ── Klassen (Baum, §10.3) ──────────────────────────────────────────────

    /**
     * Flache, vorsortierte Klasse-Liste (Baum) — wie categoriesFlat, über das
     * Klasse-Vokabular. `concepts.klasse` referenziert per Name-String (frei wählbar).
     *
     * @return list<array{id:int, name:string, parent_id:?int, depth:int, label:string, ancestors:list<int>, has_children:bool}>
     */
    public function klassenFlat(Team $team): array
    {
        $alle = FoodAlchemistVocabKlasse::visibleToTeam($team)
            ->where('is_inactive', false)
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'parent_id']);

        return $this->flacherBaum($alle);
    }

    public function createKlasse(Team $team, string $name, ?int $parentId = null): FoodAlchemistVocabKlasse
    {
        $name = trim($name);
        $name = $name !== '' ? $name : 'Neue Klasse';

        // Slug team-eindeutig (vocab_klassen.unique[team_id, slug]) — bei Kollision suffixen.
        $basis = \Illuminate\Support\Str::slug($name) ?: 'klasse';
        $slug = $basis;
        $i = 2;
        while (FoodAlchemistVocabKlasse::where('team_id', $team->id)->where('slug', $slug)->exists()) {
            $slug = $basis.'-'.$i++;
        }

        $maxSort = FoodAlchemistVocabKlasse::where('team_id', $team->id)
            ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
            ->max('sort_order');

        return FoodAlchemistVocabKlasse::create([
            'team_id' => $team->id,
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $parentId ?: null,
            'sort_order' => (int) $maxSort + 1,
        ]);
    }

    public function renameKlasse(Team $team, int $id, string $name): void
    {
        $k = FoodAlchemistVocabKlasse::visibleToTeam($team)->findOrFail($id);
        $this->guardOwnerKlasse($k, $team);
        $name = trim($name);
        if ($name !== '') {
            $k->update(['name' => $name]);
        }
    }

    /** Löschen: Kinder an den Eltern hängen, dann löschen. `concepts.klasse` (String) bleibt unberührt. */
    public function deleteKlasse(Team $team, int $id): void
    {
        $k = FoodAlchemistVocabKlasse::visibleToTeam($team)->findOrFail($id);
        $this->guardOwnerKlasse($k, $team);
        DB::transaction(function () use ($k) {
            FoodAlchemistVocabKlasse::where('parent_id', $k->id)->update(['parent_id' => $k->parent_id]);
            $k->delete();
        });
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function guardOwnerCategory(FoodAlchemistConceptCategory $cat, Team $team): void
    {
        if (! $cat->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Kategorie — Pflege nur durchs Besitzer-Team (D1).');
        }
    }

    private function guardOwnerKlasse(FoodAlchemistVocabKlasse $klasse, Team $team): void
    {
        if (! $klasse->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Klasse — Pflege nur durchs Besitzer-Team (D1).');
        }
    }

    private function ownedSlot(Team $team, int $slotId): FoodAlchemistConceptSlot
    {
        $slot = FoodAlchemistConceptSlot::with('concept')->findOrFail($slotId);
        if ($slot->concept === null || ! in_array((int) $slot->concept->team_id, FoodAlchemistConcept::teamAncestryIds($team), true)) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Slot nicht sichtbar.');
        }
        $this->guardOwner($slot->concept, $team);

        return $slot;
    }

    private function guardOwner(FoodAlchemistConcept $concept, Team $team): void
    {
        if (! $concept->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Concept — Pflege nur durchs Besitzer-Team (D1).');
        }
    }

    private function norm(?string $v): ?string
    {
        $v = $v !== null ? trim($v) : null;

        return $v === '' ? null : $v;
    }
}
