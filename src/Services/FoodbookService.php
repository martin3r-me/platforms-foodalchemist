<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;

/**
 * M11-02 / Doc 15 §9.3 + D-8: Foodbook-Service — Mappe + Kapitel-BAUM + Blöcke.
 *
 * Preis-Modell: jeder Block liefert einen Per-Person-Preis (concept_ref = Concept-
 * €/Person [person-unabhängig], recipe_ref = sales_net × Menge). Ein Kapitel summiert
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
            ->withCount('chapters')
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(label) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(customer, \'\')) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['phase'] ?? '') !== '', fn ($q) => $q->where('phase', $filters['phase'])) // R4.3
            ->orderByDesc('jahr')->orderBy('label')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistFoodbook
    {
        return FoodAlchemistFoodbook::visibleToTeam($team)
            ->with(['chapters' => fn ($q) => $q->orderBy('position'),
                'chapters.blocks' => fn ($q) => $q->orderBy('position'),
                'chapters.blocks.concept:id,name,price_per_person_cache',
                'chapters.blocks.dish:id,name,sales_net',
                'crmCompany', 'crmContact',   // #369: CRM-Kunde-Link
                'serviceMoments', 'targetGroups', 'defaultEventType', 'defaultServingForm']) // Spec 19 E3.3: Bedarf-Defaults
            ->find($id);
    }

    // ── Foodbook ────────────────────────────────────────────────────────────

    private const FELDER = ['code', 'label', 'jahr', 'customer', 'personen', 'status', 'description', 'note', 'crm_company_id', 'crm_contact_id', 'writing_style_id', 'kundentyp', 'default_niveau', 'default_convenience', 'default_event_type_id', 'default_serving_form_id', 'target_food_cost_pct', 'food_cost_tolerance_pp'];

    public function create(Team $team, array $in): FoodAlchemistFoodbook
    {
        return FoodAlchemistFoodbook::create([
            'team_id' => $team->id,
            'label' => trim((string) ($in['label'] ?? 'Neues Foodbook')) ?: 'Neues Foodbook',
            'customer' => $in['customer'] ?? null,
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

    // ── Spec 19 E3.3: Bedarf — Foodbook-Default-Dimensionen (kaskadieren als Boden) ──

    /** Default-Einsatzmoment (Tagesablauf) an/abwählen — 1–n-Pivot foodbook_service_moments. */
    public function toggleEinsatzmoment(Team $team, int $fbId, int $momentId): void
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($fbId);
        $this->guard($fb, $team);
        $fb->serviceMoments()->toggle([$momentId]);
    }

    /** Default-Zielgruppe an/abwählen — 1–n-Pivot foodbook_target_groups (Entscheidung 4). */
    public function toggleZielgruppe(Team $team, int $fbId, int $targetGroupId): void
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($fbId);
        $this->guard($fb, $team);
        $fb->targetGroups()->toggle([$targetGroupId]);
    }

    // ── Spec 19 E3.5: Zielgruppen-Vokabular (MCP-Lesefläche + Anlage) ──

    /**
     * Team-sichtbares Zielgruppen-Vokabular (eigenes Team + Master-Kette), sortiert.
     * Für `zielgruppen.GET` und die Bedarf-Sektion. Read-only.
     */
    public function zielgruppenListe(Team $team, bool $inklInaktiv = true): Collection
    {
        return FoodAlchemistTargetGroup::visibleToTeam($team)
            ->when(! $inklInaktiv, fn ($q) => $q->where('is_inactive', false))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Neue Zielgruppe anlegen (immer team-eigen). Dedup gegen das eigene Team
     * (unique(team_id,name) — ein Kind-Team darf einen Master-Namen bewusst
     * überschreiben). Wirft RuntimeException bei leerem/doppeltem Namen.
     */
    public function zielgruppeAnlegen(Team $team, array $in): FoodAlchemistTargetGroup
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Name der Zielgruppe ist Pflicht.');
        }
        $doppelt = FoodAlchemistTargetGroup::where('team_id', $team->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        if ($doppelt) {
            throw new \RuntimeException("Zielgruppe «{$name}» existiert bereits.");
        }

        return FoodAlchemistTargetGroup::create([
            'team_id' => $team->id,
            'name' => $name,
            'description' => trim((string) ($in['description'] ?? '')) ?: null,
            'sort_order' => (int) ($in['sort_order'] ?? 100),
            'is_inactive' => false,
        ]);
    }

    /**
     * Kreative Leitplanken auflösen: die effektive Guideline für Generierung + Vorschläge.
     * Kaskade (spezifisch gewinnt): Kapitel/Konzept-Niveau (concept.level) → Foodbook-Default
     * → Segment (aus Küchen-Typ). Niveau kanonisiert (haute → haute_cuisine). Convenience:
     * Foodbook-Default → Segment. Kundentyp = Foodbook-Feld (kein Fallback). So kann ein
     * Foodbook basic/hochwertig/premium tragen (Niveau je Kapitel), mit Foodbook-Default als Boden.
     *
     * Spec 19 E3.4: DER Auflösungs-Punkt für Vorschläge, Kickoff, Canvas, Anlage-Stempel. Wird
     * ein Kapitel übergeben, kaskadieren die Dimensions-Keys Kapitel(+Eltern) → Foodbook → Segment
     * (Segment-Boden nur niveau/convenience). `zielgruppen` kaskadiert über die M1-Pivots
     * (Kapitel-Stempel schlägt Foodbook-Default). Eventtyp/Servierform/Einsatzmomente lösen
     * vorerst nur auf Foodbook-Ebene auf — die Kapitel-Overrides sind M3-Spalten (E4.1) und werden
     * dort in dieser Kaskade ergänzt. `quellen` protokolliert je Dimension die gewinnende Ebene.
     *
     * @return array{kundentyp: ?string, niveau: ?string, convenience: ?string, niveau_quelle: ?string,
     *     zielgruppen: list<array{id:int, name:string}>, event_type_id: ?int, serving_form_id: ?int,
     *     service_moment_ids: list<int>, quellen: array<string, ?string>}
     */
    public function leitplanken(Team $team, FoodAlchemistFoodbook $fb, ?FoodAlchemistConcept $concept = null, ?FoodAlchemistFoodbookKapitel $kapitel = null): array
    {
        $segment = app(TeamSettingsService::class)->segment($team);
        $kapitelNiveau = TeamSettingsService::normNiveau($concept?->level);

        $niveau = $kapitelNiveau ?? $fb->default_niveau ?? ($segment['niveau'] ?? null);
        $niveauQuelle = $kapitelNiveau !== null ? 'kapitel'
            : ($fb->default_niveau !== null ? 'foodbook'
            : (($segment['niveau'] ?? null) !== null ? 'segment' : null));

        // Zielgruppen: erstes Kapitel im Pfad (Kapitel → Eltern → …) mit eigener Stempelung
        // gewinnt, sonst Foodbook-Default.
        [$zielgruppen, $zgQuelle] = $this->zielgruppenKaskade($fb, $kapitel);

        // Eventtyp/Servierform/Einsatzmomente: vorerst Foodbook-Default (Kapitel-Override = M3/E4.1).
        $eventTypeId = $fb->default_event_type_id !== null ? (int) $fb->default_event_type_id : null;
        $servingFormId = $fb->default_serving_form_id !== null ? (int) $fb->default_serving_form_id : null;
        $serviceMomentIds = $fb->serviceMoments->map(fn ($m) => (int) $m->id)->values()->all();

        return [
            'kundentyp' => $fb->kundentyp,
            'niveau' => $niveau,
            'convenience' => $fb->default_convenience ?? ($segment['convenience'] ?? null),
            'niveau_quelle' => $niveauQuelle,
            'zielgruppen' => $zielgruppen,
            'event_type_id' => $eventTypeId,
            'serving_form_id' => $servingFormId,
            'service_moment_ids' => $serviceMomentIds,
            'quellen' => [
                'niveau' => $niveauQuelle,
                'zielgruppen' => $zgQuelle,
                'event_type_id' => $eventTypeId !== null ? 'foodbook' : null,
                'serving_form_id' => $servingFormId !== null ? 'foodbook' : null,
                'service_moment_ids' => $serviceMomentIds !== [] ? 'foodbook' : null,
            ],
        ];
    }

    /**
     * Spec 19 E3.4: Zielgruppen-Kaskade. Läuft das Kapitel und seine Eltern hoch; das erste mit
     * eigener Stempelung gewinnt (Quelle 'kapitel'). Findet sich keine, greift der Foodbook-Default
     * ('foodbook'). Nirgends gesetzt ⇒ leer + null. Zyklus-Schutz via `$besucht` (Baum ist über
     * `moveKapitel` acyclisch, Guard aus Vorsicht).
     *
     * @return array{0: list<array{id:int, name:string}>, 1: ?string} [zielgruppen, quelle]
     */
    private function zielgruppenKaskade(FoodAlchemistFoodbook $fb, ?FoodAlchemistFoodbookKapitel $kapitel): array
    {
        $node = $kapitel;
        $besucht = [];
        while ($node !== null && ! isset($besucht[(int) $node->id])) {
            $besucht[(int) $node->id] = true;
            $zg = $node->targetGroups->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name])->values()->all();
            if ($zg !== []) {
                return [$zg, 'kapitel'];
            }
            $node = $node->parent;
        }
        $fbZg = $fb->targetGroups->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name])->values()->all();

        return $fbZg !== [] ? [$fbZg, 'foodbook'] : [[], null];
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
            ->where('foodbook_id', $foodbookId)->orderBy('position')->get(['id', 'title', 'parent_id']);
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

    public function addKapitel(Team $team, int $foodbookId, array $in = [], ?int $parentId = null): FoodAlchemistFoodbookKapitel
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        if ($parentId !== null && ! FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->whereKey($parentId)->exists()) {
            throw new \RuntimeException('parent_id gehört nicht zu diesem Foodbook.');
        }

        return FoodAlchemistFoodbookKapitel::create([
            'team_id' => $fb->team_id, 'foodbook_id' => $fb->id, 'parent_id' => $parentId ?: null,
            'title' => trim((string) ($in['title'] ?? 'Neues Kapitel')) ?: 'Neues Kapitel',
            'price_mode' => $in['price_mode'] ?? 'auto',
            'position' => (int) FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)
                ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
                ->max('position') + 1,
        ]);
    }

    /**
     * Phase 3a: „Struktur anwenden" — die Planungs-Gerüst-Slots als Kapitel des Foodbooks
     * materialisieren (Slot = Kapitel, Dominiques Kopplung). Je Slot ohne (gültige) chapter_id
     * ein Kapitel anlegen (Titel = Slot-Label) + slot.chapter_id setzen. **Idempotent**: bereits
     * verknüpfte Slots werden übersprungen; ein Slot, dessen Kapitel gelöscht wurde, wird neu
     * angelegt. Danach matcht CoverageService robust per chapter_id (nicht mehr Label-fragil).
     *
     * @return array{kein_geruest: bool, angelegt: int, uebersprungen: int, protokoll: list<array{slot:string, status:string, chapter_id:?int, ziele_uebernommen?:list<string>}>}
     */
    public function strukturAusGeruest(Team $team, int $foodbookId): array
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        $frames = app(PlanningFrameService::class);
        $frame = $frames->find('foodbook', $foodbookId);
        if ($frame === null || (int) $frame->slots()->count() === 0) {
            return ['kein_geruest' => true, 'angelegt' => 0, 'uebersprungen' => 0, 'protokoll' => []];
        }
        $vorhandene = array_map('intval', $fb->chapters()->pluck('id')->all());
        $angelegt = 0;
        $uebersprungen = 0;
        $protokoll = [];
        foreach ($frame->slots()->orderBy('position')->get() as $slot) {
            if ($slot->chapter_id !== null && in_array((int) $slot->chapter_id, $vorhandene, true)) {
                $uebersprungen++;
                $protokoll[] = ['slot' => $slot->label, 'status' => 'vorhanden', 'chapter_id' => (int) $slot->chapter_id];

                continue;
            }
            $kapitel = $this->addKapitel($team, $foodbookId, ['title' => $slot->label]);
            // Spec 19 E4.1: Slot-Ziele einmalig aufs neue Kapitel stempeln (die Ziele
            // wandern vom flachen Slot ans Kapitel). Nur gesetzte Slot-Felder übernehmen.
            $ziele = array_filter([
                'target_count' => $slot->target_count,
                'price_anchor' => $slot->price_anchor,
                'price_min' => $slot->price_min,
                'price_max' => $slot->price_max,
            ], fn ($v) => $v !== null);
            $uebernommen = [];
            if ($ziele !== []) {
                $kapitel->update($ziele);
                $uebernommen = array_keys($ziele);
            }
            $frames->updateSlot($team, $slot->id, ['chapter_id' => $kapitel->id]);
            $vorhandene[] = (int) $kapitel->id;
            $angelegt++;
            $protokoll[] = ['slot' => $slot->label, 'status' => 'angelegt', 'chapter_id' => (int) $kapitel->id, 'ziele_uebernommen' => $uebernommen];
        }

        return ['kein_geruest' => false, 'angelegt' => $angelegt, 'uebersprungen' => $uebersprungen, 'protokoll' => $protokoll];
    }

    /**
     * Phase 3 (Weg B): ein vorgeschlagenes Gericht in den Slot ÜBERNEHMEN. Doktrin-treu —
     * das Slot-Kapitel trägt EIN Konzept (concept_ref); übernommene Gerichte werden dessen
     * Konzept-Slots. Erstes Übernehmen legt das Draft-Konzept + den concept_ref-Block an,
     * weitere hängen an. Duplikate werden übersprungen. Setzt „Struktur anwenden" voraus.
     *
     * @return array{concept_id:int, chapter_id:int, schon_drin:bool}
     */
    public function uebernehmeVorschlag(Team $team, int $foodbookId, int $slotId, int $recipeId): array
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        $slot = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::findOrFail($slotId);
        if ($slot->chapter_id === null) {
            throw new \RuntimeException('Slot ist noch nicht als Kapitel angelegt — erst „Struktur anwenden".');
        }
        $kapitel = $this->ownedKapitel($team, (int) $slot->chapter_id);

        // Spec 19 E1.5: kapitelweite Dedup VOR jeder Anlage. Das Gericht gilt als „schon drin",
        // wenn es Slot IRGENDEINES Konzepts (concept_ref) ODER ein direkter recipe_ref-Block im
        // Kapitel ist (Union beider Wege — der alte Check sah nur EIN Konzept). Treffer ⇒ nichts
        // anlegen (auch kein leeres Konzept); concept_id = führendes Kapitel-Konzept oder 0.
        if ($this->gerichtImKapitel($kapitel, $recipeId)) {
            $vorhanden = $kapitel->blocks()->where('type', 'concept_ref')->whereNotNull('concept_id')->orderBy('position')->first();

            return ['concept_id' => (int) ($vorhanden->concept_id ?? 0), 'chapter_id' => (int) $slot->chapter_id, 'schon_drin' => true];
        }

        $block = $kapitel->blocks()->where('type', 'concept_ref')->whereNotNull('concept_id')->orderBy('position')->first();
        if ($block === null) {
            // Leitstelle: das neue Kapitel-Konzept erbt das Foodbook-Niveau (concept.level, im Concepter-
            // Vokabular). Kapitel kann es dort überschreiben (basic/hochwertig/premium). null = erbt weiter.
            $niveau = \Platform\FoodAlchemist\Services\TeamSettingsService::denormNiveauFuerConcept($this->leitplanken($team, $fb)['niveau']);
            $concept = $this->concepts->create($team, array_filter([
                'name' => trim((string) ($slot->label ?: $kapitel->title)) ?: 'Konzept',
                'status' => 'draft',
                'level' => $niveau,
            ], fn ($v) => $v !== null));
            $concept->update(['created_via' => 'foodbook_slot']);
            $this->addBlock($team, (int) $slot->chapter_id, ['type' => 'concept_ref', 'concept_id' => $concept->id]);
            $conceptId = (int) $concept->id;
        } else {
            $conceptId = (int) $block->concept_id;
        }

        $cslot = $this->concepts->addSlot($team, $conceptId, ['role' => $slot->label]);
        $this->concepts->fillSlot($team, $cslot->id, ['sales_recipe_id' => $recipeId, 'type' => 'gericht']);

        return ['concept_id' => $conceptId, 'chapter_id' => (int) $slot->chapter_id, 'schon_drin' => false];
    }

    /**
     * Kapitelweite Dedup-Prüfung (Spec 19 E1.5): steckt das VK-Gericht schon im Kapitel —
     * als Slot IRGENDEINES per concept_ref hängenden Konzepts ODER als direkter recipe_ref-Block?
     * Union über beide Anlage-Wege (Paket-Konzept + Einzel-Gericht). Nur Kapitel-lokal; die
     * quer-Kapitel-Meldung ist WEICH und bleibt `uebernehmeGericht` (E7.2) vorbehalten.
     */
    private function gerichtImKapitel(FoodAlchemistFoodbookKapitel $kapitel, int $recipeId): bool
    {
        if ($kapitel->blocks()->where('type', 'recipe_ref')->where('sales_recipe_id', $recipeId)->exists()) {
            return true;
        }
        $conceptIds = $kapitel->blocks()->where('type', 'concept_ref')->whereNotNull('concept_id')->pluck('concept_id');
        if ($conceptIds->isEmpty()) {
            return false;
        }

        return \Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot::whereIn('concept_id', $conceptIds)
            ->where('sales_recipe_id', $recipeId)->exists();
    }

    private const KAPITEL_FELDER = [
        'title', 'consumer_title', 'claim', 'description', 'price_per_person', 'price_mode',
        // SOLL-Ziele (Spec 19, M3) — release_* NICHT hier (setzt kapitelFreigeben, E7.3)
        'target_count', 'price_anchor', 'price_min', 'price_max', 'niveau',
        'service_moment_id', 'serving_form_id', 'pricing_mode', 'target_food_cost_pct',
    ];

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
     * Block-Typen. Ursprüngliche Doktrin (Dominique 2026-06-13): „Foodbook komponiert
     * Concepts, KEINE Einzel-Gerichte" — die Gericht-Ebene war Sache des Concepters
     * (GP→Rezept→Gericht→Concept→Foodbook). **Teilrevidiert Spec 19 (Dominique 2026-07-23,
     * Entscheidung 5):** Ein Kapitel trägt jetzt 0–n Concepts (Paket, €/Gast) UND 0–n
     * direkte Einzel-Gerichte als `recipe_ref`-Block (€/Position). Damit ist „Weg B
     * exklusiv" nicht mehr gültig. `recipe_ref` referenziert per `sales_recipe_id` ein
     * echtes VK-Gericht (`verkauf()`-Scope, KEINE konzept-lokale Slot-Variante) — Validierung
     * siehe `pruefeRecipeRef()`. Lesepfade (blockPreis/kapitelAggregat/dokBlockLabel) kannten
     * recipe_ref bereits; hier wird nur der Schreibpfad freigeschaltet. Wahl-Gruppen A|B|C
     * bleiben (zwischen Concepts wie zwischen Gerichten).
     */
    public const BLOCK_TYPES = ['concept_ref', 'recipe_ref', 'header_neutral', 'header_frei', 'header_frei_preis', 'spacer', 'text', 'image'];

    private const BLOCK_FELDER = ['type', 'level', 'visible', 'label', 'wording', 'customer_text', 'interne_bemerkung',
        'variant_group_id', 'concept_id', 'sales_recipe_id', 'quantity', 'unit_vocab_id', 'price_value', 'price_basis', 'height', 'header_source', 'payload_json'];

    public function addBlock(Team $team, int $kapitelId, array $in): FoodAlchemistFoodbookBlock
    {
        $k = $this->ownedKapitel($team, $kapitelId);
        $daten = array_intersect_key($in, array_flip(self::BLOCK_FELDER));
        $daten['type'] = in_array($in['type'] ?? '', self::BLOCK_TYPES, true) ? $in['type'] : 'text';
        if ($daten['type'] === 'recipe_ref') {
            $this->pruefeRecipeRef($team, $daten['sales_recipe_id'] ?? null);
        }
        $daten['team_id'] = $k->team_id;
        $daten['position'] = (int) $k->blocks()->max('position') + 1;

        return $k->blocks()->create($daten);
    }

    public function updateBlock(Team $team, int $blockId, array $in): FoodAlchemistFoodbookBlock
    {
        $block = $this->ownedBlock($team, $blockId);
        $daten = array_intersect_key($in, array_flip(self::BLOCK_FELDER));
        // recipe_ref-Guard: greift, wenn der Block (neu oder bereits) recipe_ref ist und ein
        // sales_recipe_id gesetzt/geändert wird — validiert das effektive Gericht.
        $effTyp = array_key_exists('type', $daten) ? $daten['type'] : $block->type;
        if ($effTyp === 'recipe_ref' && array_key_exists('sales_recipe_id', $daten)) {
            $this->pruefeRecipeRef($team, $daten['sales_recipe_id']);
        }
        $block->update($daten);

        return $block->refresh();
    }

    /**
     * Schreibpfad-Validierung für `recipe_ref`-Blöcke (Spec 19 E1.1). Spiegelt den
     * Picker-Scope `gerichtKandidaten`: das referenzierte Gericht muss dem Team sichtbar
     * sein, ein echtes VK-Gericht (`verkauf()`) und darf KEINE konzept-lokale Slot-Variante
     * (`variant_source_recipe_id`) sein.
     */
    private function pruefeRecipeRef(Team $team, ?int $salesRecipeId): void
    {
        if ($salesRecipeId === null) {
            throw new \RuntimeException('recipe_ref-Block braucht ein sales_recipe_id (VK-Gericht).');
        }
        $ok = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')
            ->whereKey($salesRecipeId)->exists();
        if (! $ok) {
            throw new \RuntimeException("sales_recipe_id {$salesRecipeId} ist kein gültiges, sichtbares VK-Gericht (keine Slot-Variante).");
        }
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
                    'min_persons' => max(1, (int) ($row['min_persons'] ?? 1)),
                    'price' => (float) ($row['price'] ?? 0),
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
                ['slug' => 'format.menue_paket', 'label' => 'Menü-Paket', 'type' => 'header_frei_preis', 'price_basis' => 'person'],
                ['slug' => 'format.buffet_paket', 'label' => 'Buffet-Paket', 'type' => 'header_frei_preis', 'price_basis' => 'person'],
                ['slug' => 'format.flat_rate', 'label' => 'Flat-Rate', 'type' => 'header_frei_preis', 'price_basis' => 'pauschal'],
                ['slug' => 'format.staffelpreis_block', 'label' => 'Staffelpreis-Block', 'type' => 'header_frei_preis', 'price_basis' => 'staffel'],
            ],
            'Intern (nicht sichtbar)' => [
                ['slug' => 'intern.kalkulation', 'label' => 'Interne Kalkulation', 'type' => 'header_neutral', 'visible' => false],
                ['slug' => 'intern.personal', 'label' => 'Personal', 'type' => 'header_neutral', 'visible' => false],
                ['slug' => 'intern.logistik', 'label' => 'Logistik', 'type' => 'header_neutral', 'visible' => false],
                ['slug' => 'intern.equipment', 'label' => 'Equipment', 'type' => 'header_neutral', 'visible' => false],
                ['slug' => 'intern.bemerkungen', 'label' => 'Bemerkungen', 'type' => 'header_neutral', 'visible' => false],
            ],
        ];
    }

    // ── Aggregat / Preis (M11 Cockpit) ──────────────────────────────────────────

    /**
     * Preis-Beitrag eines Blocks (Jarvis-Parität): liefert Per-Person-Anteil (vk/ek)
     * UND einen Pauschal-Anteil (flach, nicht ×Pax).
     *  - recipe_ref  → sales_net/ek_total × Menge; `price_basis` steuert die Achse:
     *                  person (Default) = Per-Person · pauschal = flacher Anteil (€/Position,
     *                  kein ×Pax; EK bleibt hier ungezählt, WE-Ampel meldet „partiell", E4.4)
     *  - concept_ref → Concept-€/Person (person-unabhängig)
     *  - header_frei_preis: person→Per-Person · staffel→Per-Person (nach Pax aufgelöst) · pauschal→flach
     *
     * @return array{vk_pp: float, ek_pp: float, pauschal: float}
     */
    public function blockPreis(FoodAlchemistFoodbookBlock $block, ?int $pax = null): array
    {
        if ($block->type === 'concept_ref' && $block->concept) {
            $cockpit = $this->concepts->preisCockpit($block->concept);

            return ['vk_pp' => (float) $cockpit['price_per_person'], 'ek_pp' => (float) $cockpit['ek_per_person'], 'pauschal' => 0.0];
        }
        if ($block->type === 'recipe_ref' && $block->dish) {
            $faktor = $block->quantity !== null ? (float) $block->quantity : 1.0;
            $vk = round((float) ($block->dish->sales_net ?? 0) * $faktor, 2);
            $ek = round((float) ($block->dish->ek_total_eur ?? 0) * $faktor, 2);

            // Spec 19 E1.2: Einzel-Gericht pauschal (€/Position, flach) vs. per-Person (€/Gast).
            // Pauschal → VK in den flachen Anteil (kein ×Pax); EK bleibt ungezählt (WE-Ampel
            // meldet „partiell", E4.4), konsistent zu header_frei_preis/pauschal.
            if ($block->price_basis === 'pauschal') {
                return ['vk_pp' => 0.0, 'ek_pp' => 0.0, 'pauschal' => $vk];
            }

            return ['vk_pp' => $vk, 'ek_pp' => $ek, 'pauschal' => 0.0];
        }
        if ($block->type === 'header_frei_preis') {
            return match ($block->price_basis) {
                'pauschal' => ['vk_pp' => 0.0, 'ek_pp' => 0.0, 'pauschal' => (float) ($block->price_value ?? 0)],
                'staffel' => ['vk_pp' => $this->resolveStaffel($block, $pax), 'ek_pp' => 0.0, 'pauschal' => 0.0],
                default => ['vk_pp' => (float) ($block->price_value ?? 0), 'ek_pp' => 0.0, 'pauschal' => 0.0], // person
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
            return (float) $stufen->sortBy('min_persons')->first()->price;
        }
        $treffer = $stufen->where('min_persons', '<=', $pax)->sortByDesc('min_persons')->first();

        return (float) ($treffer?->price ?? $stufen->sortBy('min_persons')->first()->price);
    }

    /**
     * Rekursives Kapitel-Aggregat: sichtbare Blöcke + Unterkapitel. Per-Person (vk/ek)
     * getrennt vom Pauschal-Anteil. Manuell gesetzter `preis_pro_person` übersteuert
     * die Per-Person-VK-Summe (EK + Pauschal bleiben gerechnet).
     *
     * @return array{vk_pro_person: float, ek_pro_person: float, pauschal: float, food_cost_percent: ?float}
     */
    public function kapitelAggregat(Team $team, FoodAlchemistFoodbookKapitel $kapitel, ?int $pax = null): array
    {
        $kapitel->loadMissing(['blocks' => fn ($q) => $q->where('visible', true),
            'blocks.concept:id,name,price_per_person_cache', 'blocks.dish:id,sales_net,ek_total_eur',
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
            $ek += $kindAgg['ek_per_person'];
            $pauschal += $kindAgg['pauschal'];
        }

        if ($kapitel->price_mode === 'manuell' && $kapitel->price_per_person !== null) {
            $vk = (float) $kapitel->price_per_person;
        }

        return [
            'vk_pro_person' => round($vk, 2),
            'ek_per_person' => round($ek, 2),
            'pauschal' => round($pauschal, 2),
            'food_cost_percent' => $vk > 0 ? round($ek / $vk * 100, 1) : null,
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
        foreach ($fb->chapters()->whereNull('parent_id')->get() as $top) {
            $agg = $this->kapitelAggregat($team, $top, $pax);
            $vk += $agg['vk_pro_person'];
            $ek += $agg['ek_per_person'];
            $pauschal += $agg['pauschal'];
        }

        return [
            'vk_pro_person' => round($vk, 2),
            'ek_per_person' => round($ek, 2),
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
    public function dokumentDaten(Team $team, FoodAlchemistFoodbook $fb, bool $intern = false): array
    {
        $fb->loadMissing([
            'chapters' => fn ($q) => $q->orderBy('position'),
            'chapters.blocks' => fn ($q) => $q->where('visible', true)->orderBy('position'),
            // Wording-Kette: Slots (inkl. Paket-Gerichte) fürs Auflösen der Gericht-Zeilen
            'chapters.blocks.concept.slots.dish:id,name,sales_wording_standard',
            'chapters.blocks.concept.slots.package.dishes.dish:id,name,sales_wording_standard',
            'chapters.blocks.dish:id,name,sales_wording_standard',
            'crmCompany', 'crmContact',
        ]);
        $pax = $fb->personen;
        $byParent = $fb->chapters->groupBy(fn ($k) => $k->parent_id ?? 0);
        $wording = app(WordingResolver::class);

        $rows = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$rows, $team, $pax, $wording, $intern) {
            foreach ($byParent[$parentId] ?? [] as $k) {
                $bloecke = [];
                foreach ($k->blocks as $b) {
                    $label = $this->dokBlockLabel($b);
                    if ($label === null || $label === '') {
                        continue; // spacer/image/leerer Header
                    }
                    // Untertitel: kundentext zusätzlich, wenn er nicht schon das Label ist (Legacy-Doppelrolle)
                    $untertitel = trim((string) $b->customer_text);
                    $untertitel = ($untertitel !== '' && $untertitel !== $label) ? $untertitel : null;
                    // concept_ref: Gerichte des Concepts mit aufgelöster Wording-Kette als Kundenzeilen
                    $gerichte = ($b->type === 'concept_ref' && $b->concept !== null)
                        ? $wording->gerichtZeilen($b->concept, $b)
                        : [];
                    // Block-Preis für die Preis-links-Spalte (Referenz-Layout „x € pro Person").
                    $bp = $this->blockPreis($b, $pax);
                    $bloecke[] = ['type' => $b->type, 'label' => $label, 'untertitel' => $untertitel,
                        'gerichte' => $gerichte, 'ist_header' => str_starts_with((string) $b->type, 'header'),
                        'preis_pp' => (float) $bp['vk_pp'], 'pauschal' => (float) $bp['pauschal']];
                }
                $agg = $this->kapitelAggregat($team, $k, $pax);
                $row = [
                    'title' => $k->consumer_title ?: $k->title,
                    'title_intern' => $k->title,           // interner Titel für die Projektleitung-Sicht
                    'anker' => 'k' . $k->id,               // Navleiste-Sprungziel (klickbar in HTML + PDF)
                    'depth' => $depth,
                    'bloecke' => $bloecke,
                    'vk_pro_person' => $agg['vk_pro_person'],
                ];
                if ($intern) {
                    // Marge nur in der internen Projektion (Projektleitung/Vertrieb) — NIE im Kundendokument.
                    $row['ek_pro_person'] = $agg['ek_per_person'];
                    $row['food_cost_percent'] = $agg['food_cost_percent'];
                }
                $rows[] = $row;
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return [
            'fb' => $fb,
            'intern' => $intern,
            'kapitel' => $rows,
            'gesamt' => $this->gesamt($team, $fb),
            // #369: CRM-Firma bevorzugt, sonst Freitext-kunde; Kontaktperson separat.
            'customer' => $fb->crmCompany?->display_name ?: $fb->customer,
            'kontakt' => $fb->crmContact?->display_name,
            // Kundendokument-Vollständigkeit: gesetzlicher MwSt-Satz + Stand-Datum.
            'mwst' => app(TeamSettingsService::class)->mwst($team),
            'stand' => $fb->updated_at,
            // PDF-Redesign: pro-Foodbook-Marke (Farbe/Band/Logo/Cover/Footer), DomPDF-taugliche base64-Bilder.
            'branding' => $this->brandingDaten($fb),
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
            str_starts_with((string) $b->type, 'header') => $b->customer_text ?: null,
            $b->type === 'text' => $b->customer_text ?: null,
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
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when($categoryId !== null, fn ($q) => $q->whereIn('category_id', $this->concepts->descendantIds($team, $categoryId)))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'price_per_person_cache', 'category_id']);
    }

    /** Einzelne Gerichte (VK-Rezepte) für den recipe_ref-Picker. */
    public function gerichtKandidaten(Team $team, string $suche, int $limit = 20): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id') // R4.4: Slot-Varianten sind konzept-lokal, nicht pickbar
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'sales_net']);
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
            return ['customer' => null, 'briefing' => null, 'personen' => null, 'concepts' => [], 'kapitel' => []];
        }

        $conceptNamen = collect();
        foreach ($fb->chapters as $k) {
            foreach ($k->blocks as $b) {
                if ($b->type === 'concept_ref' && $b->concept) {
                    $conceptNamen->push($b->concept->name);
                }
            }
        }

        return [
            'customer' => $fb->customer,
            'briefing' => $fb->description,
            'personen' => $fb->personen,
            'concepts' => $conceptNamen->unique()->values()->all(),
            'kapitel' => $fb->chapters->pluck('title')->values()->all(),
        ];
    }

    // ── Branding (pro Foodbook) ─────────────────────────────────────────────────
    //
    // UI-agnostische API: der Branding/CI-Tab im Cockpit (separate Session) UND MCP/Console
    // rufen dieselben Methoden. Owner-Guard wie überall (D1). Bilder liegen auf der
    // public-Disk; fürs PDF werden sie in dokumentDaten als base64 kodiert (DomPDF-tauglich).

    private const BRANDING_STORAGE_DISK = 'public';

    /** Setzt Farb-/Text-Marke. $in: brand_color, band_color, footer_text (jeweils optional). */
    public function setBranding(Team $team, int $foodbookId, array $in): FoodAlchemistFoodbook
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);

        $daten = [];
        if (array_key_exists('brand_color', $in)) {
            $daten['brand_color'] = $this->normHexOderThrow($in['brand_color'], 'brand_color') ?? '#6d28d9';
        }
        if (array_key_exists('band_color', $in)) {
            // Leer → null (Blade leitet dann aus brand_color ab).
            $daten['band_color'] = $this->normHexOderThrow($in['band_color'], 'band_color', erlaubeLeer: true);
        }
        if (array_key_exists('footer_text', $in)) {
            $t = trim((string) $in['footer_text']);
            $daten['footer_text'] = $t !== '' ? $t : null;
        }
        if ($daten !== []) {
            $fb->update($daten);
        }

        return $fb->refresh();
    }

    public function storeLogo(Team $team, int $foodbookId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $foodbookId, $file, 'logo_path');
    }

    public function storeCover(Team $team, int $foodbookId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $foodbookId, $file, 'cover_image_path');
    }

    public function clearLogo(Team $team, int $foodbookId): FoodAlchemistFoodbook
    {
        return $this->loescheBrandingBild($team, $foodbookId, 'logo_path');
    }

    public function clearCover(Team $team, int $foodbookId): FoodAlchemistFoodbook
    {
        return $this->loescheBrandingBild($team, $foodbookId, 'cover_image_path');
    }

    private function speichereBrandingBild(Team $team, int $foodbookId, UploadedFile $file, string $spalte): string
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);

        $alt = (string) $fb->{$spalte};
        if ($alt !== '' && Storage::disk(self::BRANDING_STORAGE_DISK)->exists($alt)) {
            Storage::disk(self::BRANDING_STORAGE_DISK)->delete($alt);
        }
        $pfad = $file->store("foodalchemist/branding/{$foodbookId}", self::BRANDING_STORAGE_DISK);
        $fb->update([$spalte => $pfad]);

        return $pfad;
    }

    private function loescheBrandingBild(Team $team, int $foodbookId, string $spalte): FoodAlchemistFoodbook
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);

        $alt = (string) $fb->{$spalte};
        if ($alt !== '' && Storage::disk(self::BRANDING_STORAGE_DISK)->exists($alt)) {
            Storage::disk(self::BRANDING_STORAGE_DISK)->delete($alt);
        }
        $fb->update([$spalte => null]);

        return $fb->refresh();
    }

    /**
     * Marken-Tokens fürs Dokument-Blade. Logo/Cover als base64-Data-URI (DomPDF lädt keine
     * http-URLs, enable_remote ist aus) — funktioniert im HTML- wie im PDF-Pfad. band leer →
     * aus brand_color, footer null → Blade nutzt Default-Zeile.
     *
     * @return array{color:string, band:string, logo:?string, cover:?string, footer:?string}
     */
    private function brandingDaten(FoodAlchemistFoodbook $fb): array
    {
        $color = ($fb->brand_color ?? '') !== '' ? $fb->brand_color : '#6d28d9';

        return [
            'color' => $color,
            'band' => ($fb->band_color ?? '') !== '' ? $fb->band_color : $color,
            'logo' => $this->alsDataUri($fb->logo_path),
            'cover' => $this->alsDataUri($fb->cover_image_path),
            'footer' => ($fb->footer_text ?? '') !== '' ? $fb->footer_text : null,
        ];
    }

    private function alsDataUri(?string $pfad): ?string
    {
        $pfad = (string) $pfad;
        if ($pfad === '' || ! Storage::disk(self::BRANDING_STORAGE_DISK)->exists($pfad)) {
            return null;
        }
        $mime = Storage::disk(self::BRANDING_STORAGE_DISK)->mimeType($pfad) ?: 'image/png';
        $bytes = Storage::disk(self::BRANDING_STORAGE_DISK)->get($pfad);

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /** Hex-Validierung wie Settings\Kueche::sanitizeFarben. erlaubeLeer=true → '' ⇒ null. */
    private function normHexOderThrow($wert, string $feld, bool $erlaubeLeer = false): ?string
    {
        $v = trim((string) $wert);
        if ($v === '') {
            if ($erlaubeLeer) {
                return null;
            }
            throw new \RuntimeException("Farbe {$feld} darf nicht leer sein.");
        }
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            throw new \RuntimeException("Ungültige Farbe für {$feld}: \"{$v}\" (erwartet #RRGGBB).");
        }

        return strtolower($v);
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
