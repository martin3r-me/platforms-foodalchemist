<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * M11-02 / Doc 15 §9.3 + D-8: Foodbook-Service — Mappe + Kapitel-BAUM + Blöcke.
 *
 * Preis-Modell: jeder Block liefert einen Per-Person-Preis (concept_ref = Concept-
 * €/Person [person-unabhängig], recipe_ref = vk_netto × Menge). Ein Kapitel summiert
 * rekursiv über Blöcke + Unterkapitel (`kapitelAggregat`). Der **Gesamtpreis** =
 * Σ Top-Kapitel × **Pax am Foodbook** (F-12, D-CON-5) — erst hier wird die
 * Gästezahl bindend, nicht am Concept.
 *
 * Scope-Härte: visibleToTeam in JEDER Query; Schreiben nur durchs Besitzer-Team.
 */
class FoodbookService
{
    public function __construct(private ConceptService $concepts)
    {
    }

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistFoodbook::visibleToTeam($team)
            ->withCount('kapitel')
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(label) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(kunde, \'\')) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('jahr')->orderBy('label')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistFoodbook
    {
        return FoodAlchemistFoodbook::visibleToTeam($team)
            ->with(['kapitel' => fn ($q) => $q->orderBy('position'),
                'kapitel.blocks' => fn ($q) => $q->orderBy('position'),
                'kapitel.blocks.concept:id,name,preis_pro_person_cache',
                'kapitel.blocks.gericht:id,name,vk_netto',
                'crmCompany', 'crmContact'])   // #369: CRM-Kunde-Link
            ->find($id);
    }

    // ── Foodbook ────────────────────────────────────────────────────────────

    private const FELDER = ['code', 'label', 'jahr', 'kunde', 'personen', 'status', 'description', 'note', 'crm_company_id', 'crm_contact_id'];

    public function create(Team $team, array $in): FoodAlchemistFoodbook
    {
        return FoodAlchemistFoodbook::create([
            'team_id' => $team->id,
            'label' => trim((string) ($in['label'] ?? 'Neues Foodbook')) ?: 'Neues Foodbook',
            'kunde' => $in['kunde'] ?? null,
            'jahr' => $in['jahr'] ?? null,
            'personen' => $in['personen'] ?? null,
            'status' => $in['status'] ?? 'draft',
            'description' => $in['description'] ?? null,
        ]);
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistFoodbook
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($id);
        $this->guard($fb, $team);
        $fb->update(array_intersect_key($in, array_flip(self::FELDER)));

        return $fb->refresh();
    }

    // ── #369: CRM-Kunde-Link (MVP, nur verlinken) — class_exists-geschützt (Modul läuft ohne crm) ──

    public function verknuepfeKunde(Team $team, int $id, ?int $companyId, ?int $contactId): FoodAlchemistFoodbook
    {
        return $this->update($team, $id, ['crm_company_id' => $companyId, 'crm_contact_id' => $contactId]);
    }

    public function crmVerfuegbar(): bool
    {
        return class_exists(\Platform\Crm\Services\CompanyLinkService::class);
    }

    public function sucheFirmen(string $suche, int $limit = 10): Collection
    {
        $suche = trim($suche);
        if ($suche === '' || ! $this->crmVerfuegbar()) {
            return collect();
        }

        return app(\Platform\Crm\Services\CompanyLinkService::class)->searchCompanies($suche, $limit);
    }

    public function sucheKontakte(string $suche, int $limit = 10): Collection
    {
        $suche = trim($suche);
        if ($suche === '' || ! class_exists(\Platform\Crm\Services\ContactLinkService::class)) {
            return collect();
        }

        return app(\Platform\Crm\Services\ContactLinkService::class)->searchContacts($suche, $limit);
    }

    public function delete(Team $team, int $id): void
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($id);
        $this->guard($fb, $team);
        $fb->delete();
    }

    // ── Kapitel-Baum ──────────────────────────────────────────────────────────

    /** @return list<array{id:int, titel:string, parent_id:?int, depth:int}> Pre-Order */
    public function kapitelTree(Team $team, int $foodbookId): array
    {
        $alle = FoodAlchemistFoodbookKapitel::visibleToTeam($team)
            ->where('foodbook_id', $foodbookId)->orderBy('position')->get(['id', 'titel', 'parent_id']);
        $byParent = $alle->groupBy(fn ($k) => $k->parent_id ?? 0);
        $out = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$out) {
            foreach ($byParent[$parentId] ?? [] as $k) {
                $out[] = ['id' => (int) $k->id, 'titel' => $k->titel, 'parent_id' => $k->parent_id !== null ? (int) $k->parent_id : null, 'depth' => $depth];
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    public function addKapitel(Team $team, int $foodbookId, array $in = [], ?int $parentId = null): FoodAlchemistFoodbookKapitel
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);

        return FoodAlchemistFoodbookKapitel::create([
            'team_id' => $fb->team_id, 'foodbook_id' => $fb->id, 'parent_id' => $parentId ?: null,
            'titel' => trim((string) ($in['titel'] ?? 'Neues Kapitel')) ?: 'Neues Kapitel',
            'preis_modus' => $in['preis_modus'] ?? 'auto',
            'position' => (int) FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)
                ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
                ->max('position') + 1,
        ]);
    }

    private const KAPITEL_FELDER = ['titel', 'konsumententitel', 'claim', 'description', 'preis_pro_person', 'preis_modus'];

    public function updateKapitel(Team $team, int $id, array $in): FoodAlchemistFoodbookKapitel
    {
        $k = $this->ownedKapitel($team, $id);
        $k->update(array_intersect_key($in, array_flip(self::KAPITEL_FELDER)));

        return $k->refresh();
    }

    /** Verschieben mit Zyklus-Schutz (kein Knoten unter eigenen Nachfahren). */
    public function moveKapitel(Team $team, int $id, ?int $newParentId): void
    {
        $k = $this->ownedKapitel($team, $id);
        if ($newParentId !== null) {
            if ($newParentId === $id || in_array($newParentId, $this->descendantKapitelIds($team, $k->foodbook_id, $id), true)) {
                throw new \RuntimeException('Zyklus: Kapitel kann nicht unter einen eigenen Nachfahren.');
            }
        }
        $k->update(['parent_id' => $newParentId ?: null]);
    }

    /** @param list<int> $ids */
    public function reorderKapitel(Team $team, int $foodbookId, ?int $parentId, array $ids): void
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        DB::transaction(function () use ($foodbookId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistFoodbookKapitel::where('id', (int) $id)->where('foodbook_id', $foodbookId)->update(['position' => $i]);
            }
        });
    }

    public function deleteKapitel(Team $team, int $id): void
    {
        $this->ownedKapitel($team, $id)->delete();
    }

    private function descendantKapitelIds(Team $team, int $foodbookId, int $kapitelId): array
    {
        $kinder = [];
        foreach ($this->kapitelTree($team, $foodbookId) as $row) {
            $kinder[$row['parent_id'] ?? 0][] = $row['id'];
        }
        $ids = [];
        $stack = $kinder[$kapitelId] ?? [];
        while ($stack) {
            $id = array_pop($stack);
            $ids[] = $id;
            foreach ($kinder[$id] ?? [] as $kid) {
                $stack[] = $kid;
            }
        }

        return $ids;
    }

    // ── Blöcke ────────────────────────────────────────────────────────────────

    /**
     * Block-Typen. **Foodbook komponiert Concepts, KEINE Einzel-Gerichte** (Dominique
     * 2026-06-13) — die Gericht-Ebene ist Sache des Concepters (GP→Rezept→Gericht→Concept→
     * Foodbook). Daher kein `recipe_ref` im Angebot; die Spalte/Relation bleibt nur als
     * Schema-Altlast (Jarsvis hatte keine Concept-Ebene). Wahl-Gruppen A|B|C = zwischen Concepts.
     */
    public const BLOCK_TYPES = ['concept_ref', 'header_neutral', 'header_frei', 'header_frei_preis', 'spacer', 'text', 'image'];

    private const BLOCK_FELDER = ['type', 'ebene', 'sichtbar', 'label', 'wording', 'kundentext', 'interne_bemerkung',
        'variant_group_id', 'concept_id', 'vk_recipe_id', 'quantity', 'unit_vocab_id', 'preis_wert', 'preis_basis', 'hoehe', 'header_source', 'payload_json'];

    public function addBlock(Team $team, int $kapitelId, array $in): FoodAlchemistFoodbookBlock
    {
        $k = $this->ownedKapitel($team, $kapitelId);
        $daten = array_intersect_key($in, array_flip(self::BLOCK_FELDER));
        $daten['type'] = in_array($in['type'] ?? '', self::BLOCK_TYPES, true) ? $in['type'] : 'text';
        $daten['team_id'] = $k->team_id;
        $daten['position'] = (int) $k->blocks()->max('position') + 1;

        return $k->blocks()->create($daten);
    }

    public function updateBlock(Team $team, int $blockId, array $in): FoodAlchemistFoodbookBlock
    {
        $block = $this->ownedBlock($team, $blockId);
        $block->update(array_intersect_key($in, array_flip(self::BLOCK_FELDER)));

        return $block->refresh();
    }

    /**
     * Wording-Kette: Per-Gericht-Override eines concept_ref-Blocks
     * (payload_json['wording_overrides'][slot_id]) setzen/löschen — die oberste
     * Stufe der Kette Foodbook → Konzept → Standard → Name.
     */
    public function setBlockSlotWording(Team $team, int $blockId, int $slotId, ?string $text): FoodAlchemistFoodbookBlock
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

    public function deleteBlock(Team $team, int $blockId): void
    {
        $this->ownedBlock($team, $blockId)->delete();
    }

    /** @param list<int> $ids */
    public function reorderBlocks(Team $team, int $kapitelId, array $ids): void
    {
        $this->ownedKapitel($team, $kapitelId);
        DB::transaction(function () use ($kapitelId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistFoodbookBlock::where('id', (int) $id)->where('chapter_id', $kapitelId)->update(['position' => $i]);
            }
        });
    }

    /** Wahl-Gruppe „A|B|C": nächste freie Gruppen-ID im Kapitel. */
    public function nextVariantGroupId(Team $team, int $kapitelId): int
    {
        $this->ownedKapitel($team, $kapitelId);

        return (int) FoodAlchemistFoodbookBlock::where('chapter_id', $kapitelId)->max('variant_group_id') + 1;
    }

    /** @param list<int> $blockIds */
    public function setVariantGroup(Team $team, array $blockIds, ?int $groupId): void
    {
        foreach ($blockIds as $id) {
            $block = $this->ownedBlock($team, (int) $id);
            $block->update(['variant_group_id' => $groupId]);
        }
    }

    /**
     * Staffelpreise eines header_frei_preis-Blocks setzen (Vollersatz).
     *
     * @param  array<int, array{min_personen:int, preis:float}>  $rows
     */
    public function setStaffel(Team $team, int $blockId, array $rows): void
    {
        $block = $this->ownedBlock($team, $blockId);
        DB::transaction(function () use ($block, $rows) {
            $block->staffel()->forceDelete();
            $i = 0;
            foreach ($rows as $row) {
                $block->staffel()->create([
                    'team_id' => $block->team_id,
                    'min_personen' => max(1, (int) ($row['min_personen'] ?? 1)),
                    'preis' => (float) ($row['preis'] ?? 0),
                    'position' => $i++,
                ]);
            }
        });
    }

    /**
     * Header-Presets für den „+ Inhalt"-Picker (Jarvis-Parität).
     *
     * @return array<string, list<array{slug:string, label:string, type:string, preis_basis?:string, sichtbar?:bool}>>
     */
    public static function headerPresets(): array
    {
        $gang = fn ($slug, $label) => ['slug' => "gang.$slug", 'label' => $label, 'type' => 'header_neutral'];
        $zeit = fn ($slug, $label) => ['slug' => "zeit.$slug", 'label' => $label, 'type' => 'header_neutral'];

        return [
            'Gänge / Service' => [
                $gang('get_together', 'Get-together'), $gang('aperitif', 'Aperitif'), $gang('flying', 'Flying'),
                $gang('vorspeisen', 'Vorspeisen'), $gang('suppen', 'Suppen'), $gang('zwischengang', 'Zwischengang'),
                $gang('hauptgang', 'Hauptgang'), $gang('beilagen', 'Beilagen'), $gang('dessert', 'Dessert'),
                $gang('kaese', 'Käse'), $gang('buffet', 'Buffet'), $gang('fingerfood', 'Fingerfood'),
                $gang('snacks', 'Snacks'), $gang('late_night', 'Late Night'), $gang('getraenke', 'Getränke'),
                $gang('kaffee_tee', 'Kaffee & Tee'),
            ],
            'Tageszeit' => [
                $zeit('breakfast', 'Breakfast'), $zeit('brunch', 'Brunch'), $zeit('lunch', 'Lunch'),
                $zeit('coffee_break', 'Coffee Break'), $zeit('dinner', 'Dinner'), $zeit('after_work', 'After Work'),
            ],
            'Konzept / Format (+ Preis)' => [
                ['slug' => 'format.menue_paket', 'label' => 'Menü-Paket', 'type' => 'header_frei_preis', 'preis_basis' => 'person'],
                ['slug' => 'format.buffet_paket', 'label' => 'Buffet-Paket', 'type' => 'header_frei_preis', 'preis_basis' => 'person'],
                ['slug' => 'format.flat_rate', 'label' => 'Flat-Rate', 'type' => 'header_frei_preis', 'preis_basis' => 'pauschal'],
                ['slug' => 'format.staffelpreis_block', 'label' => 'Staffelpreis-Block', 'type' => 'header_frei_preis', 'preis_basis' => 'staffel'],
            ],
            'Intern (nicht sichtbar)' => [
                ['slug' => 'intern.kalkulation', 'label' => 'Interne Kalkulation', 'type' => 'header_neutral', 'sichtbar' => false],
                ['slug' => 'intern.personal', 'label' => 'Personal', 'type' => 'header_neutral', 'sichtbar' => false],
                ['slug' => 'intern.logistik', 'label' => 'Logistik', 'type' => 'header_neutral', 'sichtbar' => false],
                ['slug' => 'intern.equipment', 'label' => 'Equipment', 'type' => 'header_neutral', 'sichtbar' => false],
                ['slug' => 'intern.bemerkungen', 'label' => 'Bemerkungen', 'type' => 'header_neutral', 'sichtbar' => false],
            ],
        ];
    }

    // ── Aggregat / Preis (M11 Cockpit) ──────────────────────────────────────────

    /**
     * Preis-Beitrag eines Blocks (Jarvis-Parität): liefert Per-Person-Anteil (vk/ek)
     * UND einen Pauschal-Anteil (flach, nicht ×Pax).
     *  - recipe_ref  → vk/ek = vk_netto/ek_total × Menge (Per-Person)
     *  - concept_ref → Concept-€/Person (person-unabhängig)
     *  - header_frei_preis: person→Per-Person · staffel→Per-Person (nach Pax aufgelöst) · pauschal→flach
     *
     * @return array{vk_pp: float, ek_pp: float, pauschal: float}
     */
    public function blockPreis(FoodAlchemistFoodbookBlock $block, ?int $pax = null): array
    {
        if ($block->type === 'concept_ref' && $block->concept) {
            $cockpit = $this->concepts->preisCockpit($block->concept);

            return ['vk_pp' => (float) $cockpit['preis_pro_person'], 'ek_pp' => (float) $cockpit['ek_pro_person'], 'pauschal' => 0.0];
        }
        if ($block->type === 'recipe_ref' && $block->gericht) {
            $faktor = $block->quantity !== null ? (float) $block->quantity : 1.0;

            return ['vk_pp' => round((float) ($block->gericht->vk_netto ?? 0) * $faktor, 2),
                'ek_pp' => round((float) ($block->gericht->ek_total_eur ?? 0) * $faktor, 2), 'pauschal' => 0.0];
        }
        if ($block->type === 'header_frei_preis') {
            return match ($block->preis_basis) {
                'pauschal' => ['vk_pp' => 0.0, 'ek_pp' => 0.0, 'pauschal' => (float) ($block->preis_wert ?? 0)],
                'staffel' => ['vk_pp' => $this->resolveStaffel($block, $pax), 'ek_pp' => 0.0, 'pauschal' => 0.0],
                default => ['vk_pp' => (float) ($block->preis_wert ?? 0), 'ek_pp' => 0.0, 'pauschal' => 0.0], // person
            };
        }

        return ['vk_pp' => 0.0, 'ek_pp' => 0.0, 'pauschal' => 0.0];
    }

    /** Staffel-Auflösung: höchste Stufe mit min_personen ≤ Pax (ohne Pax die niedrigste). */
    public function resolveStaffel(FoodAlchemistFoodbookBlock $block, ?int $pax): float
    {
        $stufen = $block->relationLoaded('staffel') ? $block->staffel : $block->staffel()->get();
        if ($stufen->isEmpty()) {
            return 0.0;
        }
        if ($pax === null) {
            return (float) $stufen->sortBy('min_personen')->first()->preis;
        }
        $treffer = $stufen->where('min_personen', '<=', $pax)->sortByDesc('min_personen')->first();

        return (float) ($treffer?->preis ?? $stufen->sortBy('min_personen')->first()->preis);
    }

    /**
     * Rekursives Kapitel-Aggregat: sichtbare Blöcke + Unterkapitel. Per-Person (vk/ek)
     * getrennt vom Pauschal-Anteil. Manuell gesetzter `preis_pro_person` übersteuert
     * die Per-Person-VK-Summe (EK + Pauschal bleiben gerechnet).
     *
     * @return array{vk_pro_person: float, ek_pro_person: float, pauschal: float, wareneinsatz_prozent: ?float}
     */
    public function kapitelAggregat(Team $team, FoodAlchemistFoodbookKapitel $kapitel, ?int $pax = null): array
    {
        $kapitel->loadMissing(['blocks' => fn ($q) => $q->where('sichtbar', true),
            'blocks.concept:id,name,preis_pro_person_cache', 'blocks.gericht:id,vk_netto,ek_total_eur',
            'blocks.staffel', 'children']);

        $vk = 0.0;
        $ek = 0.0;
        $pauschal = 0.0;
        foreach ($kapitel->blocks as $block) {
            $p = $this->blockPreis($block, $pax);
            $vk += $p['vk_pp'];
            $ek += $p['ek_pp'];
            $pauschal += $p['pauschal'];
        }
        foreach ($kapitel->children as $kind) {
            $kindAgg = $this->kapitelAggregat($team, $kind, $pax);
            $vk += $kindAgg['vk_pro_person'];
            $ek += $kindAgg['ek_pro_person'];
            $pauschal += $kindAgg['pauschal'];
        }

        if ($kapitel->preis_modus === 'manuell' && $kapitel->preis_pro_person !== null) {
            $vk = (float) $kapitel->preis_pro_person;
        }

        return [
            'vk_pro_person' => round($vk, 2),
            'ek_pro_person' => round($ek, 2),
            'pauschal' => round($pauschal, 2),
            'wareneinsatz_prozent' => $vk > 0 ? round($ek / $vk * 100, 1) : null,
        ];
    }

    /**
     * Foodbook-Gesamt: (Σ Top-Kapitel Per-Person × Pax) + Pauschal-Anteile. Erst HIER
     * wird die Gästezahl bindend (F-12, D-CON-5).
     *
     * @return array{vk_pro_person: float, ek_pro_person: float, pauschal: float, personen: ?int, gesamt_vk: ?float, gesamt_ek: ?float}
     */
    public function gesamt(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $pax = $fb->personen;
        $vk = 0.0;
        $ek = 0.0;
        $pauschal = 0.0;
        foreach ($fb->kapitel()->whereNull('parent_id')->get() as $top) {
            $agg = $this->kapitelAggregat($team, $top, $pax);
            $vk += $agg['vk_pro_person'];
            $ek += $agg['ek_pro_person'];
            $pauschal += $agg['pauschal'];
        }

        return [
            'vk_pro_person' => round($vk, 2),
            'ek_pro_person' => round($ek, 2),
            'pauschal' => round($pauschal, 2),
            'personen' => $pax,
            'gesamt_vk' => $pax !== null ? round($vk * $pax + $pauschal, 2) : null,
            'gesamt_ek' => $pax !== null ? round($ek * $pax, 2) : null,
        ];
    }

    // ── #384/Folge: versendbares Foodbook/Portfolio-Dokument ───────────────────

    /**
     * Daten fürs versendbare Foodbook-Dokument (Druck/PDF): Kapitel-Baum (Pre-Order,
     * Tiefe) mit NUR sichtbaren Blöcken (Export-Filter `sichtbar`) + Kunden-Labels
     * (konsumententitel/kundentext), pro Kapitel der Per-Person-Preis, + Gesamt.
     * interne_bemerkung wird NIE ausgegeben (Kundensicht).
     *
     * @return array{fb:FoodAlchemistFoodbook, kapitel:list<array>, gesamt:array, kunde:?string}
     */
    public function dokumentDaten(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $fb->loadMissing([
            'kapitel' => fn ($q) => $q->orderBy('position'),
            'kapitel.blocks' => fn ($q) => $q->where('sichtbar', true)->orderBy('position'),
            // Wording-Kette: Slots (inkl. Paket-Gerichte) fürs Auflösen der Gericht-Zeilen
            'kapitel.blocks.concept.slots.gericht:id,name,vk_wording_standard',
            'kapitel.blocks.concept.slots.paket.gerichte.gericht:id,name,vk_wording_standard',
            'kapitel.blocks.gericht:id,name,vk_wording_standard',
            'crmCompany', 'crmContact',
        ]);
        $pax = $fb->personen;
        $byParent = $fb->kapitel->groupBy(fn ($k) => $k->parent_id ?? 0);
        $wording = app(WordingResolver::class);

        $rows = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$rows, $team, $pax, $wording) {
            foreach ($byParent[$parentId] ?? [] as $k) {
                $bloecke = [];
                foreach ($k->blocks as $b) {
                    $label = $this->dokBlockLabel($b);
                    if ($label === null || $label === '') {
                        continue; // spacer/image/leerer Header
                    }
                    // Untertitel: kundentext zusätzlich, wenn er nicht schon das Label ist (Legacy-Doppelrolle)
                    $untertitel = trim((string) $b->kundentext);
                    $untertitel = ($untertitel !== '' && $untertitel !== $label) ? $untertitel : null;
                    // concept_ref: Gerichte des Concepts mit aufgelöster Wording-Kette als Kundenzeilen
                    $gerichte = ($b->type === 'concept_ref' && $b->concept !== null)
                        ? $wording->gerichtZeilen($b->concept, $b)
                        : [];
                    $bloecke[] = ['typ' => $b->type, 'label' => $label, 'untertitel' => $untertitel,
                        'gerichte' => $gerichte, 'ist_header' => str_starts_with((string) $b->type, 'header')];
                }
                $agg = $this->kapitelAggregat($team, $k, $pax);
                $rows[] = [
                    'titel' => $k->konsumententitel ?: $k->titel,
                    'depth' => $depth,
                    'bloecke' => $bloecke,
                    'vk_pro_person' => $agg['vk_pro_person'],
                ];
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return [
            'fb' => $fb,
            'kapitel' => $rows,
            'gesamt' => $this->gesamt($team, $fb),
            // #369: CRM-Firma bevorzugt, sonst Freitext-kunde; Kontaktperson separat.
            'kunde' => $fb->crmCompany?->display_name ?: $fb->kunde,
            'kontakt' => $fb->crmContact?->display_name,
            // Kundendokument-Vollständigkeit: gesetzlicher MwSt-Satz + Stand-Datum.
            'mwst' => app(TeamSettingsService::class)->mwst($team),
            'stand' => $fb->updated_at,
        ];
    }

    /**
     * Kunden-Label eines Blocks — concept_ref/recipe_ref über die Wording-Kette
     * (WordingResolver: wording → kundentext-Legacy → Standard → Name);
     * header/text behalten kundentext als Inhalt; spacer/image => null.
     */
    private function dokBlockLabel(FoodAlchemistFoodbookBlock $b): ?string
    {
        return match (true) {
            in_array($b->type, ['concept_ref', 'recipe_ref'], true) => app(WordingResolver::class)->blockTitel($b)['text'],
            str_starts_with((string) $b->type, 'header') => $b->kundentext ?: null,
            $b->type === 'text' => $b->kundentext ?: null,
            default => null,
        };
    }

    // ── Picker (für den Editor) ─────────────────────────────────────────────

    /**
     * Concepts (echte, keine Vorlagen) für den concept_ref-Picker — optional gefiltert nach
     * Concept-Kategorie (descendant-inklusiv, FB-1/GT-FB-7).
     */
    public function conceptKandidaten(Team $team, string $suche, ?int $categoryId = null, int $limit = 20): Collection
    {
        return FoodAlchemistConcept::visibleToTeam($team)->echte()
            ->when($suche !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($suche) . '%']))
            ->when($categoryId !== null, fn ($q) => $q->whereIn('category_id', $this->concepts->descendantIds($team, $categoryId)))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'preis_pro_person_cache', 'category_id']);
    }

    /** Einzelne Gerichte (VK-Rezepte) für den recipe_ref-Picker. */
    public function gerichtKandidaten(Team $team, string $suche, int $limit = 20): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->when($suche !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($suche) . '%']))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'vk_netto']);
    }

    /**
     * M11-08: Andock-Kontext für die spätere KI-Text-Generierung (Einleitung/Kapitel) —
     * assembliert NUR die Eingaben, KEIN LLM-Call (Befüllung extern/später, blockiert).
     * Quelle: Kunde + Briefing (description) + die referenzierten Concepts + Kapitel-Titel.
     * Der echte Canvas-Wissen-Link folgt mit D10; bis dahin ist `briefing` der lose Text.
     *
     * @return array{kunde: ?string, briefing: ?string, personen: ?int, concepts: list<string>, kapitel: list<string>}
     */
    public function kiAndockKontext(Team $team, int $foodbookId): array
    {
        $fb = $this->detail($team, $foodbookId);
        if ($fb === null) {
            return ['kunde' => null, 'briefing' => null, 'personen' => null, 'concepts' => [], 'kapitel' => []];
        }

        $conceptNamen = collect();
        foreach ($fb->kapitel as $k) {
            foreach ($k->blocks as $b) {
                if ($b->type === 'concept_ref' && $b->concept) {
                    $conceptNamen->push($b->concept->name);
                }
            }
        }

        return [
            'kunde' => $fb->kunde,
            'briefing' => $fb->description,
            'personen' => $fb->personen,
            'concepts' => $conceptNamen->unique()->values()->all(),
            'kapitel' => $fb->kapitel->pluck('titel')->values()->all(),
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function ownedKapitel(Team $team, int $id): FoodAlchemistFoodbookKapitel
    {
        $k = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->findOrFail($id);
        if (! $k->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Foodbook — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $k;
    }

    private function ownedBlock(Team $team, int $id): FoodAlchemistFoodbookBlock
    {
        $block = FoodAlchemistFoodbookBlock::visibleToTeam($team)->findOrFail($id);
        if (! $block->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Foodbook — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $block;
    }

    private function guard(FoodAlchemistFoodbook $fb, Team $team): void
    {
        if (! $fb->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Foodbook — Pflege nur durchs Besitzer-Team (D1).');
        }
    }
}
