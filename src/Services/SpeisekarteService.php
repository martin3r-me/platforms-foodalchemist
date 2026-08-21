<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Services\Concerns\PruefstOutletZuordnung;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik;

/**
 * Speisekarte-Service — Karte + Rubrik-BAUM + Positionen. Dritte Ausgabeform neben
 * Foodbook (Catering) und Speiseplan (GV): die Gastronomie-à-la-carte-Karte.
 *
 * Preis-Modell (Gastro): jede Position trägt einen FLACHEN VK (€/Position, kein ×Pax).
 *  - gericht_ref  → VK aus der Darreichung (Glas/Flasche/Portion) bzw. Standard-Darreichung
 *                   des Rezepts (DarreichungResolver), Legacy-Fallback recipes.sales_net.
 *  - menue_ref    → Concept-€/Person (Fix-Menü / Mehrgänger) über ConceptService::preisCockpit.
 *  - price_mode='manuell' übersteuert mit price_value.
 *
 * Scope-Härte: visibleToTeam in JEDER Query; Schreiben nur durchs Besitzer-Team (D1).
 */
class SpeisekarteService
{
    use PruefstOutletZuordnung;

    public function __construct(
        private ConceptService $concepts,
        private DarreichungResolver $darreichung,
        private WordingResolver $wording,
    ) {
    }

    // ── Karte ────────────────────────────────────────────────────────────────

    private const FELDER = [
        'code', 'name', 'status', 'outlet_id', 'karten_typ', 'gueltig_von', 'gueltig_bis',
        'preis_anzeige_brutto', 'description', 'note', 'kundentyp', 'default_niveau',
        'default_convenience', 'writing_style_id',
        'crm_company_id', 'crm_contact_id',
    ];

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistSpeisekarte::visibleToTeam($team)
            ->withCount('sections')
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['karten_typ'] ?? '') !== '', fn ($q) => $q->where('karten_typ', $filters['karten_typ']))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistSpeisekarte
    {
        return FoodAlchemistSpeisekarte::visibleToTeam($team)
            ->with([
                'sections' => fn ($q) => $q->orderBy('position'),
                'sections.items' => fn ($q) => $q->orderBy('position'),
                'sections.items.dish:id,name,sales_net,ek_total_eur',
                'sections.items.concept:id,name,price_per_person_cache',
                'outlet', 'crmCompany', 'crmContact',
            ])
            ->find($id);
    }

    public function create(Team $team, array $in): FoodAlchemistSpeisekarte
    {
        // Spec 33 P2: fremde Betriebe fallen hier raus — `outlet_id` zeigt auf ein Team-Vokabular
        // und wird vom Datensatz-Guard nicht mit erfasst.
        $in = $this->pruefeOutlet($team, $in);

        return FoodAlchemistSpeisekarte::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neue Speisekarte')) ?: 'Neue Speisekarte',
            'status' => AusgabeStatus::normalisiere($in['status'] ?? null)->value,
            'karten_typ' => in_array($in['karten_typ'] ?? '', FoodAlchemistSpeisekarte::KARTEN_TYPEN, true) ? $in['karten_typ'] : 'alacarte',
            'outlet_id' => $in['outlet_id'] ?? null,
            'crm_company_id' => $in['crm_company_id'] ?? null,
            'crm_contact_id' => $in['crm_contact_id'] ?? null,
            // Spec 33 P1: Fenster schon beim Anlegen — sonst muss man jede Ausgabe zweimal
            // anfassen, und ein Import/MCP-Aufruf verliert die Angabe stillschweigend.
            'gueltig_von' => ($in['gueltig_von'] ?? '') !== '' ? $in['gueltig_von'] : null,
            'gueltig_bis' => ($in['gueltig_bis'] ?? '') !== '' ? $in['gueltig_bis'] : null,
            'preis_anzeige_brutto' => $in['preis_anzeige_brutto'] ?? true,
            'description' => $in['description'] ?? null,
        ]);
    }

    /**
     * Spec 33: Status durch den Enum (P0) und leere Datumsfelder zu NULL (P1) — eine Regel,
     * ein Ort, damit auch MCP-Aufrufe abgedeckt sind und nicht nur das Formular.
     */
    private function normalisiereFelder(array $update): array
    {
        if (array_key_exists('status', $update)) {
            $update['status'] = AusgabeStatus::normalisiere((string) $update['status'])->value;
        }

        foreach (['gueltig_von', 'gueltig_bis'] as $datum) {
            if (array_key_exists($datum, $update) && ($update[$datum] === '' || $update[$datum] === false)) {
                $update[$datum] = null;
            }
        }

        return $update;
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistSpeisekarte
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($id);
        $this->guard($karte, $team);
        $karte->update($this->normalisiereFelder(
            $this->pruefeOutlet($team, array_intersect_key($in, array_flip(self::FELDER))),
        ));

        return $karte->refresh();
    }

    public function verknuepfeKunde(Team $team, int $id, ?int $companyId, ?int $contactId): FoodAlchemistSpeisekarte
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
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($id);
        $this->guard($karte, $team);
        $karte->delete();
    }

    /**
     * Karte duplizieren (Wechsel-/Saison-/Tageskarte aus einer Basis). Kopiert Rubrik-Baum +
     * Positionen; neuer Entwurf, code=null, Name „… (Kopie)". Overrides überschreiben Kopf-Felder.
     */
    public function dupliziere(Team $team, int $id, array $overrides = []): FoodAlchemistSpeisekarte
    {
        $quelle = FoodAlchemistSpeisekarte::visibleToTeam($team)
            ->with(['sections' => fn ($q) => $q->orderBy('position'), 'sections.items' => fn ($q) => $q->orderBy('position')])
            ->findOrFail($id);
        $this->guard($quelle, $team);

        $kopfFelder = [
            'karten_typ', 'outlet_id', 'preis_anzeige_brutto', 'description', 'kundentyp',
            'default_niveau', 'default_convenience', 'writing_style_id',
            'brand_color', 'band_color', 'logo_path', 'cover_image_path', 'footer_text',
        ];

        return DB::transaction(function () use ($quelle, $team, $overrides, $kopfFelder) {
            // Auch hier durch den Guard: die Quelle kann über die Team-Kette sichtbar sein und
            // einen Betrieb tragen, der dem kopierenden Team nicht gehört.
            $neu = FoodAlchemistSpeisekarte::create($this->pruefeOutlet($team, array_merge(
                ['team_id' => $team->id, 'name' => $quelle->name . ' (Kopie)', 'status' => AusgabeStatus::Entwurf->value, 'code' => null],
                array_intersect_key($quelle->only($kopfFelder), array_flip($kopfFelder)),
                array_intersect_key($overrides, array_flip(array_merge(self::FELDER, ['name']))),
            )));

            // Rubriken flach kopieren (parent_id in 2. Pass), dann Positionen.
            $map = [];
            foreach ($quelle->sections as $r) {
                $kopie = FoodAlchemistSpeisekarteRubrik::create([
                    'team_id' => $neu->team_id, 'menu_card_id' => $neu->id, 'parent_id' => null,
                    'position' => $r->position, 'title' => $r->title, 'consumer_title' => $r->consumer_title,
                    'claim' => $r->claim, 'description' => $r->description, 'art' => $r->art,
                    'preis_anzeige' => $r->preis_anzeige,
                ]);
                $map[$r->id] = $kopie->id;
            }
            foreach ($quelle->sections as $r) {
                if ($r->parent_id !== null && isset($map[$r->parent_id])) {
                    FoodAlchemistSpeisekarteRubrik::whereKey($map[$r->id])->update(['parent_id' => $map[$r->parent_id]]);
                }
                foreach ($r->items as $pos) {
                    FoodAlchemistSpeisekartePosition::create([
                        'team_id' => $neu->team_id, 'section_id' => $map[$r->id], 'position' => $pos->position,
                        'type' => $pos->type, 'level' => $pos->level, 'visible' => $pos->visible, 'label' => $pos->label,
                        'consumer_text' => $pos->consumer_text, 'interne_bemerkung' => $pos->interne_bemerkung,
                        'variant_group_id' => $pos->variant_group_id, 'sales_recipe_id' => $pos->sales_recipe_id,
                        'concept_id' => $pos->concept_id, 'presentation_id' => $pos->presentation_id,
                        'wording' => $pos->wording, 'price_mode' => $pos->price_mode, 'price_value' => $pos->price_value,
                        'height' => $pos->height, 'payload_json' => $pos->payload_json,
                    ]);
                }
            }

            return $neu->refresh();
        });
    }

    // ── Rubrik-Baum ────────────────────────────────────────────────────────────

    /** @return list<array{id:int, title:string, parent_id:?int, art:string, depth:int}> Pre-Order */
    public function rubrikTree(Team $team, int $karteId): array
    {
        $alle = FoodAlchemistSpeisekarteRubrik::visibleToTeam($team)
            ->where('menu_card_id', $karteId)->orderBy('position')->get(['id', 'title', 'parent_id', 'art']);
        $byParent = $alle->groupBy(fn ($r) => $r->parent_id ?? 0);
        $out = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$out) {
            foreach ($byParent[$parentId] ?? [] as $r) {
                $out[] = ['id' => (int) $r->id, 'title' => $r->title, 'parent_id' => $r->parent_id !== null ? (int) $r->parent_id : null, 'art' => $r->art, 'depth' => $depth];
                $walk((int) $r->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    public function addRubrik(Team $team, int $karteId, array $in = [], ?int $parentId = null): FoodAlchemistSpeisekarteRubrik
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($karteId);
        $this->guard($karte, $team);
        if ($parentId !== null && ! FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karte->id)->whereKey($parentId)->exists()) {
            throw new \RuntimeException('parent_id gehört nicht zu dieser Speisekarte.');
        }

        return FoodAlchemistSpeisekarteRubrik::create([
            'team_id' => $karte->team_id,
            'menu_card_id' => $karte->id,
            'parent_id' => $parentId ?: null,
            'title' => trim((string) ($in['title'] ?? 'Neue Rubrik')) ?: 'Neue Rubrik',
            'consumer_title' => $in['consumer_title'] ?? null,
            'art' => in_array($in['art'] ?? '', FoodAlchemistSpeisekarteRubrik::ARTEN, true) ? $in['art'] : 'speisen',
            'position' => (int) FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karte->id)
                ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
                ->max('position') + 1,
        ]);
    }

    private const RUBRIK_FELDER = ['title', 'consumer_title', 'claim', 'description', 'art', 'preis_anzeige', 'status'];

    /**
     * Rubrik für einen Voll-Kaskade-Frame-Slot (P4): findet die Rubrik per Titel oder legt sie an
     * (idempotent — ein zweiter Kaskaden-Lauf mintet keine Dublette). Gibt die Rubrik-ID zurück.
     */
    public function rubrikFuerSlot(Team $team, int $karteId, string $title): int
    {
        $title = trim($title) !== '' ? trim($title) : 'Rubrik';
        $bestehend = FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karteId)
            ->where('title', $title)->whereNull('deleted_at')->first(['id']);
        if ($bestehend !== null) {
            return (int) $bestehend->id;
        }

        return (int) $this->addRubrik($team, $karteId, ['title' => $title])->id;
    }

    public function updateRubrik(Team $team, int $id, array $in): FoodAlchemistSpeisekarteRubrik
    {
        $rubrik = $this->ownedRubrik($team, $id);
        $rubrik->update(array_intersect_key($in, array_flip(self::RUBRIK_FELDER)));

        return $rubrik->refresh();
    }

    public function moveRubrik(Team $team, int $id, ?int $newParentId): void
    {
        $rubrik = $this->ownedRubrik($team, $id);
        if ($newParentId !== null) {
            $ziel = $this->ownedRubrik($team, $newParentId);
            if ($ziel->menu_card_id !== $rubrik->menu_card_id) {
                throw new \RuntimeException('Ziel-Rubrik gehört zu einer anderen Karte.');
            }
            // Zyklus-Schutz: Ziel darf kein Nachfahre der bewegten Rubrik sein.
            if ($this->istNachfahre($rubrik->menu_card_id, (int) $rubrik->id, $newParentId)) {
                throw new \RuntimeException('Verschieben würde einen Zyklus erzeugen.');
            }
        }
        $rubrik->update(['parent_id' => $newParentId ?: null]);
    }

    /** @param list<int> $ids */
    public function reorderRubriken(Team $team, int $karteId, ?int $parentId, array $ids): void
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($karteId);
        $this->guard($karte, $team);
        DB::transaction(function () use ($karteId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistSpeisekarteRubrik::where('id', (int) $id)->where('menu_card_id', $karteId)->update(['position' => $i]);
            }
        });
    }

    public function deleteRubrik(Team $team, int $id): void
    {
        $this->ownedRubrik($team, $id)->delete();
    }

    // ── Positionen ──────────────────────────────────────────────────────────────

    private const POSITION_FELDER = [
        'type', 'level', 'visible', 'label', 'consumer_text', 'interne_bemerkung', 'variant_group_id',
        'sales_recipe_id', 'concept_id', 'presentation_id', 'wording', 'price_mode', 'price_value',
        'height', 'payload_json',
    ];

    public function addPosition(Team $team, int $rubrikId, array $in): FoodAlchemistSpeisekartePosition
    {
        $rubrik = $this->ownedRubrik($team, $rubrikId);
        $daten = array_intersect_key($in, array_flip(self::POSITION_FELDER));
        $daten['type'] = in_array($in['type'] ?? '', FoodAlchemistSpeisekartePosition::TYPES, true) ? $in['type'] : 'text';
        if ($daten['type'] === 'gericht_ref') {
            $this->pruefeGerichtRef($team, $daten['sales_recipe_id'] ?? null);
        }
        if ($daten['type'] === 'menue_ref') {
            $this->pruefeMenueRef($team, $daten['concept_id'] ?? null);
        }
        $daten['team_id'] = $rubrik->team_id;
        $daten['position'] = (int) $rubrik->items()->max('position') + 1;

        return $rubrik->items()->create($daten);
    }

    public function updatePosition(Team $team, int $positionId, array $in): FoodAlchemistSpeisekartePosition
    {
        $pos = $this->ownedPosition($team, $positionId);
        $daten = array_intersect_key($in, array_flip(self::POSITION_FELDER));
        $effTyp = array_key_exists('type', $daten) ? $daten['type'] : $pos->type;
        if ($effTyp === 'gericht_ref' && array_key_exists('sales_recipe_id', $daten)) {
            $this->pruefeGerichtRef($team, $daten['sales_recipe_id']);
        }
        if ($effTyp === 'menue_ref' && array_key_exists('concept_id', $daten)) {
            $this->pruefeMenueRef($team, $daten['concept_id']);
        }
        $pos->update($daten);

        return $pos->refresh();
    }

    /**
     * gericht_ref-Guard: das referenzierte Gericht/Getränk muss dem Team sichtbar sein,
     * ein echtes VK-Gericht (`verkauf()`) und keine konzept-lokale Slot-Variante.
     */
    private function pruefeGerichtRef(Team $team, ?int $salesRecipeId): void
    {
        if ($salesRecipeId === null) {
            throw new \RuntimeException('gericht_ref-Position braucht ein sales_recipe_id (VK-Gericht).');
        }
        $ok = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')
            ->whereKey($salesRecipeId)->exists();
        if (! $ok) {
            throw new \RuntimeException("sales_recipe_id {$salesRecipeId} ist kein gültiges, sichtbares VK-Gericht.");
        }
    }

    /** menue_ref-Guard: das referenzierte Concept muss dem Team sichtbar + ein echtes Concept sein. */
    private function pruefeMenueRef(Team $team, ?int $conceptId): void
    {
        if ($conceptId === null) {
            throw new \RuntimeException('menue_ref-Position braucht ein concept_id (Fix-Menü).');
        }
        $ok = FoodAlchemistConcept::visibleToTeam($team)->echte()->whereKey($conceptId)->exists();
        if (! $ok) {
            throw new \RuntimeException("concept_id {$conceptId} ist kein gültiges, sichtbares Concept.");
        }
    }

    public function deletePosition(Team $team, int $positionId): void
    {
        $this->ownedPosition($team, $positionId)->delete();
    }

    /** @param list<int> $ids */
    public function reorderPositionen(Team $team, int $rubrikId, array $ids): void
    {
        $this->ownedRubrik($team, $rubrikId);
        DB::transaction(function () use ($rubrikId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistSpeisekartePosition::where('id', (int) $id)->where('section_id', $rubrikId)->update(['position' => $i]);
            }
        });
    }

    /**
     * Werkstrang M Phase C (Spec 40 §6): eine Position in eine ANDERE Rubrik derselben Karte schieben.
     * `section_id` steht bewusst NICHT in POSITION_FELDER (Update-Whitelist) — darum diese eigene Methode:
     * team-scoped, beide Rubriken zur selben `menu_card_id`, `section_id` setzen + `position = max+1` der
     * Ziel-Rubrik (ans Ende), transaktional. No-op wenn Ziel = aktuelle Rubrik.
     */
    public function movePosition(Team $team, int $positionId, int $newSectionId): void
    {
        $pos = $this->ownedPosition($team, $positionId);
        $ziel = $this->ownedRubrik($team, $newSectionId);
        $quelle = $this->ownedRubrik($team, (int) $pos->section_id);
        if ((int) $ziel->menu_card_id !== (int) $quelle->menu_card_id) {
            throw new \RuntimeException('Ziel-Rubrik gehört zu einer anderen Karte.');
        }
        if ((int) $pos->section_id === (int) $newSectionId) {
            return;   // schon dort
        }
        DB::transaction(function () use ($pos, $newSectionId) {
            $maxPos = (int) (FoodAlchemistSpeisekartePosition::where('section_id', $newSectionId)->max('position') ?? -1) + 1;
            $pos->section_id = $newSectionId;
            $pos->position = $maxPos;
            $pos->save();
        });
    }

    /** Wahl-Gruppe „A|B|C": nächste freie Gruppen-ID in der Rubrik. */
    public function nextVariantGroupId(Team $team, int $rubrikId): int
    {
        $this->ownedRubrik($team, $rubrikId);

        return (int) FoodAlchemistSpeisekartePosition::where('section_id', $rubrikId)->max('variant_group_id') + 1;
    }

    // ── Preis ────────────────────────────────────────────────────────────────

    /**
     * Netto-VK einer Position (flach, €/Position). Manuell übersteuert; sonst je Typ
     * aus Darreichung (Gericht/Getränk) bzw. Concept-€/Person (Fix-Menü).
     *
     * @return array{vk: ?float, quelle: string} quelle ∈ manuell|darreichung|legacy|concept|keine
     */
    public function positionPreis(FoodAlchemistSpeisekartePosition $pos): array
    {
        if ($pos->price_mode === 'manuell') {
            return ['vk' => $pos->price_value !== null ? (float) $pos->price_value : null, 'quelle' => 'manuell'];
        }

        if ($pos->type === 'gericht_ref') {
            // Expliziter Darreichungs-Override (Glas/Flasche/Portion) hat Vorrang.
            if ($pos->presentation_id) {
                $darr = FoodAlchemistRecipeDarreichung::find($pos->presentation_id);
                if ($darr?->sales_net !== null) {
                    return ['vk' => (float) $darr->sales_net, 'quelle' => 'darreichung'];
                }
            }
            $dish = $pos->relationLoaded('dish') ? $pos->dish : $pos->dish()->first();
            if ($dish) {
                return $this->darreichung->vkNettoMitQuelle($dish);
            }
        }

        if ($pos->type === 'menue_ref') {
            $concept = $pos->relationLoaded('concept') ? $pos->concept : $pos->concept()->first();
            if ($concept) {
                $cockpit = $this->concepts->preisCockpit($concept);

                return ['vk' => (float) $cockpit['price_per_person'], 'quelle' => 'concept'];
            }
        }

        return ['vk' => null, 'quelle' => 'keine'];
    }

    // ── Picker-Kandidaten ────────────────────────────────────────────────────

    /** Einzelne Gerichte/Getränke (VK-Rezepte) für den gericht_ref-Picker. */
    public function gerichtKandidaten(Team $team, string $suche, int $limit = 20, ?int $hauptgruppe = null, ?int $dishClassId = null): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when($hauptgruppe !== null, fn ($q) => $q->where('dish_main_group_id', $hauptgruppe))
            ->when($dishClassId !== null, fn ($q) => $q->where('dish_class_id', $dishClassId))
            // Werkstrang M Phase B: dish_class_id + diet_form additiv mitgeben (Picker-Diät-Label);
            // andere Aufrufer lesen weiter nur id/name/sales_net.
            ->with(['dishClass:id,diet_form'])
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'sales_net', 'dish_class_id']);
    }

    /** Concepts (Fix-Menüs) für den menue_ref-Picker. */
    public function conceptKandidaten(Team $team, string $suche, int $limit = 20): Collection
    {
        return FoodAlchemistConcept::visibleToTeam($team)->echte()
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'price_per_person_cache']);
    }

    // ── Dokument / Druck (Stufe B) ───────────────────────────────────────────

    /**
     * Druckbare Speisekarte: Rubrik-Baum (Pre-Order) mit Positionen inkl. Wording-Name,
     * Allergen-/Zusatzstoff-Fußnoten-Codes (ALL-MAXIMAL) und Netto-/Brutto-VK. Sammelt
     * die tatsächlich vorkommenden Kennzeichnungs-Codes für die Legende (§ Kennzeichnung).
     *
     * Codes: Allergene = Buchstaben in EU-Reihenfolge, Zusatzstoffe = Nummern, `*` = Spuren.
     * Preis: Brutto = Netto × (1 + MwSt) — Gastro-Default regulärer Satz (In-Haus-Verzehr).
     */
    public function dokumentDaten(Team $team, FoodAlchemistSpeisekarte $karte): array
    {
        $agg = app(ConcepterAggregateService::class);
        $marge = app(MargeService::class);
        $mwstArr = app(TeamSettingsService::class)->mwst($team);
        $mwstSatz = (float) ($mwstArr['regulaer'] ?? 19.0);
        $brutto = (bool) $karte->preis_anzeige_brutto;

        // Code-Register (voll), Nutzung wird gesammelt.
        $allergenCode = [];
        $i = 0;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $slug => $label) {
            $allergenCode[$slug] = ['code' => chr(65 + $i), 'label' => $label];
            $i++;
        }
        $zusatzCode = [];
        $j = 1;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE as $slug => $label) {
            $zusatzCode[$slug] = ['code' => (string) $j, 'label' => $label];
            $j++;
        }
        $usedAlg = [];
        $usedZus = [];

        // Codes einer Position aus der Allergen-/Zusatzstoff-Aggregation ihrer Gerichte.
        $codesFuer = function (FoodAlchemistSpeisekartePosition $pos) use ($agg, $allergenCode, $zusatzCode, &$usedAlg, &$usedZus): array {
            $k = $agg->kennzeichnungFromGerichte($this->positionGerichte($pos));
            $codes = [];
            foreach ($k['allergene'] as $a) {
                if ($a['status'] === 'enthalten' || $a['status'] === 'spuren') {
                    $usedAlg[$a['slug']] = true;
                    $codes[] = $allergenCode[$a['slug']]['code'] . ($a['status'] === 'spuren' ? '*' : '');
                }
            }
            foreach ($k['zusatzstoffe'] as $z) {
                if ($z['status'] === 'ja') {
                    $usedZus[$z['slug']] = true;
                    $codes[] = $zusatzCode[$z['slug']]['code'];
                }
            }

            return $codes;
        };

        // Rubrik-Baum in Pre-Order; je Position Name (Wording) + Codes + Preis.
        $sections = $karte->relationLoaded('sections') ? $karte->sections : $karte->sections()->with(['items.dish', 'items.concept'])->get();
        $byParent = $sections->groupBy(fn ($r) => $r->parent_id ?? 0);

        $rubriken = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$rubriken, $codesFuer, $marge, $mwstSatz) {
            foreach ($byParent[$parentId] ?? [] as $rubrik) {
                $positionen = [];
                foreach ($rubrik->items->where('visible', true)->sortBy('position') as $pos) {
                    $preis = $this->positionPreis($pos);
                    $einheit = $marge->proEinheit($preis['vk'], 1, $mwstSatz);
                    $positionen[] = [
                        'typ' => $pos->type,
                        'name' => $this->positionName($pos),
                        'consumer_text' => $pos->consumer_text,
                        // Werkstrang M Phase D: Wahl-Gruppe daten-fertig mitgeben (Grouping-Optik im Renderer folgt).
                        'variant_group_id' => $pos->variant_group_id !== null ? (int) $pos->variant_group_id : null,
                        'codes' => in_array($pos->type, ['gericht_ref', 'menue_ref'], true) ? $codesFuer($pos) : [],
                        'vk_netto' => $preis['vk'],
                        'vk_brutto' => $einheit['vk_brutto_pro_einheit'] ?? null,
                        'preis_quelle' => $preis['quelle'],
                        // Fix-Menü: die Gänge zum Auflisten unter dem Menü-Titel.
                        'gaenge' => $pos->type === 'menue_ref' ? $this->menueGaenge($pos) : [],
                        // Getränke/Wein: Metadaten (Jahrgang/Region/Rebsorte) aus payload_json.
                        'wein' => $this->weinMeta($pos),
                    ];
                }
                $rubriken[] = [
                    'id' => (int) $rubrik->id,
                    'title' => $rubrik->consumer_title ?: $rubrik->title,
                    'claim' => $rubrik->claim,
                    'art' => $rubrik->art,
                    'preis_anzeige' => $rubrik->preis_anzeige,
                    'depth' => $depth,
                    'positionen' => $positionen,
                ];
                $walk((int) $rubrik->id, $depth + 1);
            }
        };
        $walk(0, 0);

        $legendeAlg = [];
        foreach ($allergenCode as $slug => $cl) {
            if (isset($usedAlg[$slug])) {
                $legendeAlg[] = $cl;
            }
        }
        $legendeZus = [];
        foreach ($zusatzCode as $slug => $cl) {
            if (isset($usedZus[$slug])) {
                $legendeZus[] = $cl;
            }
        }

        return [
            'karte' => $karte,
            'rubriken' => $rubriken,
            'legende' => ['allergene' => $legendeAlg, 'zusatzstoffe' => $legendeZus],
            'brutto' => $brutto,
            'mwstSatz' => $mwstSatz,
            'branding' => $this->brandingDaten($karte),
            'erzeugt' => now()->format('d.m.Y'),
        ];
    }

    /**
     * Gerichte einer Position für die Allergen-/Zusatzstoff-Aggregation:
     * gericht_ref → das eine Gericht/Getränk; menue_ref → alle Gänge des Concepts.
     */
    public function positionGerichte(FoodAlchemistSpeisekartePosition $pos): Collection
    {
        if ($pos->type === 'gericht_ref') {
            $dish = $pos->relationLoaded('dish') ? $pos->dish : $pos->dish()->first();

            return $dish ? collect([$dish]) : collect();
        }
        if ($pos->type === 'menue_ref') {
            $concept = FoodAlchemistConcept::with(['slots.package.dishes.gericht', 'slots.dish'])->find($pos->concept_id);
            if ($concept === null) {
                return collect();
            }
            $gerichte = collect();
            foreach ($concept->slots as $slot) {
                if ($slot->package) {
                    $gerichte = $gerichte->merge($slot->package->dishes->pluck('gericht')->filter());
                }
                if ($slot->dish) {
                    $gerichte->push($slot->dish);
                }
            }

            return $gerichte->filter()->unique('id')->values();
        }

        return collect();
    }

    /**
     * Gänge eines Fix-Menüs (menue_ref) als Kunden-Zeilen — Wording-aufgelöst über den
     * Concepter (WordingResolver::gerichtZeilen). Leer, wenn kein Concept.
     *
     * @return list<array{type:string, text:string, einrueckung:int}>
     */
    private function menueGaenge(FoodAlchemistSpeisekartePosition $pos): array
    {
        if ($pos->type !== 'menue_ref' || $pos->concept_id === null) {
            return [];
        }
        $concept = FoodAlchemistConcept::with(['slots.package.dishes.gericht', 'slots.dish'])->find($pos->concept_id);
        if ($concept === null) {
            return [];
        }

        return array_map(
            fn ($z) => ['type' => $z['type'], 'text' => $z['text'], 'einrueckung' => $z['einrueckung'] ?? 0],
            $this->wording->gerichtZeilen($concept),
        );
    }

    /**
     * Wein-/Getränke-Metadaten aus payload_json (Jahrgang, Region, Rebsorte, Winzer) — für
     * die Weinkarte. Nur nicht-leere Felder.
     *
     * @return array<string, string>
     */
    private function weinMeta(FoodAlchemistSpeisekartePosition $pos): array
    {
        $payload = $pos->payload_json ?? [];
        $wein = $payload['wein'] ?? [];
        $out = [];
        foreach (['jahrgang', 'region', 'rebsorte', 'winzer'] as $feld) {
            $v = trim((string) ($wein[$feld] ?? ''));
            if ($v !== '') {
                $out[$feld] = $v;
            }
        }

        return $out;
    }

    /** Kunden-Anzeigename einer Position über die Wording-Kette (Override → Standard → Name). */
    private function positionName(FoodAlchemistSpeisekartePosition $pos): ?string
    {
        if ($pos->wording !== null && trim($pos->wording) !== '') {
            return trim($pos->wording);
        }
        if ($pos->type === 'gericht_ref') {
            $dish = $pos->relationLoaded('dish') ? $pos->dish : $pos->dish()->first();

            return $dish ? ($this->wording->fuerGericht($dish)['text'] ?? $dish->name) : $pos->label;
        }
        if ($pos->type === 'menue_ref') {
            $concept = $pos->relationLoaded('concept') ? $pos->concept : $pos->concept()->first();

            return $concept?->name ?? $pos->label;
        }

        return $pos->label;
    }

    // ── Branding / CI (Stufe C) ──────────────────────────────────────────────

    /** Marken-Farben + Footer setzen. Leere band_color ⇒ Ableitung aus brand_color im Blade. */
    public function setBranding(Team $team, int $karteId, array $in): FoodAlchemistSpeisekarte
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($karteId);
        $this->guard($karte, $team);

        $daten = [];
        if (array_key_exists('brand_color', $in)) {
            $daten['brand_color'] = $this->normHexOderThrow($in['brand_color'], 'brand_color') ?? '#6d28d9';
        }
        if (array_key_exists('band_color', $in)) {
            $daten['band_color'] = $this->normHexOderThrow($in['band_color'], 'band_color', erlaubeLeer: true);
        }
        if (array_key_exists('footer_text', $in)) {
            $t = trim((string) $in['footer_text']);
            $daten['footer_text'] = $t !== '' ? $t : null;
        }
        if ($daten !== []) {
            $karte->update($daten);
        }

        return $karte->refresh();
    }

    public function storeLogo(Team $team, int $karteId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $karteId, $file, 'logo_path');
    }

    public function storeCover(Team $team, int $karteId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $karteId, $file, 'cover_image_path');
    }

    public function clearLogo(Team $team, int $karteId): FoodAlchemistSpeisekarte
    {
        return $this->loescheBrandingBild($team, $karteId, 'logo_path');
    }

    public function clearCover(Team $team, int $karteId): FoodAlchemistSpeisekarte
    {
        return $this->loescheBrandingBild($team, $karteId, 'cover_image_path');
    }

    private function speichereBrandingBild(Team $team, int $karteId, UploadedFile $file, string $spalte): string
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($karteId);
        $this->guard($karte, $team);

        $contextSpalte = $this->brandingContextSpalte($spalte);
        $alt = (string) $karte->{$spalte};
        app(FoodAlchemistMediaService::class)->delete($karte->{$contextSpalte}, $alt, $team);

        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file,
            $team,
            'foodalchemist.speisekarte',
            $karteId,
            "foodalchemist/branding/menu_card/{$karteId}",
        );
        $pfad = $media['path'];
        $karte->update([
            $spalte => $pfad,
            $contextSpalte => $media['context_file_id'],
        ]);

        return $pfad;
    }

    private function loescheBrandingBild(Team $team, int $karteId, string $spalte): FoodAlchemistSpeisekarte
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($karteId);
        $this->guard($karte, $team);

        $contextSpalte = $this->brandingContextSpalte($spalte);
        $alt = (string) $karte->{$spalte};
        app(FoodAlchemistMediaService::class)->delete($karte->{$contextSpalte}, $alt, $team);
        $karte->update([
            $spalte => null,
            $contextSpalte => null,
        ]);

        return $karte->refresh();
    }

    /**
     * Marken-Tokens fürs Dokument/die Präsentation. Logo/Cover als base64-Data-URI (DomPDF lädt
     * keine http-URLs) — funktioniert im HTML- wie im PDF-Pfad. band leer → aus brand_color.
     *
     * @return array{color:string, band:string, logo:?string, cover:?string, footer:?string}
     */
    public function brandingDaten(FoodAlchemistSpeisekarte $karte): array
    {
        $color = ($karte->brand_color ?? '') !== '' ? $karte->brand_color : '#6d28d9';

        return [
            'color' => $color,
            'band' => ($karte->band_color ?? '') !== '' ? $karte->band_color : $color,
            'logo' => app(FoodAlchemistMediaService::class)->dataUri($karte->logo_context_file_id, $karte->logo_path),
            'cover' => app(FoodAlchemistMediaService::class)->dataUri($karte->cover_context_file_id, $karte->cover_image_path),
            'footer' => ($karte->footer_text ?? '') !== '' ? $karte->footer_text : null,
        ];
    }

    private function brandingContextSpalte(string $spalte): string
    {
        return match ($spalte) {
            'logo_path' => 'logo_context_file_id',
            'cover_image_path' => 'cover_context_file_id',
            default => throw new \InvalidArgumentException("Unbekannte Branding-Bildspalte: {$spalte}"),
        };
    }

    /** Hex-Validierung. erlaubeLeer=true → '' ⇒ null (Blade leitet aus brand_color ab). */
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

    // ── KI-Wording (Stufe E) ─────────────────────────────────────────────────

    /**
     * KI-Vorschlag für den Anzeige-Namen/Beschreibungstext einer Position in Brand-Voice.
     * Zwei-stufig: schlägt nur vor (schreibt nichts) — Übernehmen ist ein menschlicher Akt.
     * Läuft über den Core-Contract (AiGatewayService), Prompt-Key `foodbook.kundentext`.
     *
     * @return array{text: string, confidence: ?float, call_log_id: ?int}
     */
    public function kiWordingVorschlag(Team $team, int $positionId): array
    {
        $pos = $this->ownedPosition($team, $positionId);
        $rubrik = $this->ownedRubrik($team, $pos->section_id);
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($rubrik->menu_card_id);

        $roh = $pos->type === 'gericht_ref'
            ? ($pos->dish?->name ?? $pos->label)
            : ($pos->concept?->name ?? $pos->label);

        $proposal = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
            'foodbook.kundentext',
            [
                'ebene' => 'speisekarte_position',
                'aufgabe' => 'Formuliere einen appetitlichen, kurzen Gast-Namen (ggf. + knappe Beschreibung) für diese Speisekarten-Position.',
                'gericht_roh' => $roh,
                'rubrik' => $rubrik->consumer_title ?: $rubrik->title,
                'karte' => $karte->name,
                'leitplanken' => array_filter([
                    'niveau' => $karte->default_niveau,
                    'kundentyp' => $karte->kundentyp,
                ]),
                'briefing_ist' => $pos->wording,
            ],
            [
                'target_table' => 'foodalchemist_menu_card_items',
                'target_id' => (int) $pos->id,
            ],
        );

        $text = trim((string) ($proposal->werte['text'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Die KI hat keinen Text geliefert — bitte erneut versuchen.');
        }

        return ['text' => $text, 'confidence' => $proposal->confidence, 'call_log_id' => $proposal->callLogId];
    }

    /**
     * KI-Vorschlag für einen Einleitungs-/Beschreibungstext der Karte (Brand-Voice).
     * Ebenfalls zwei-stufig; schreibt nichts.
     *
     * @return array{text: string, confidence: ?float, call_log_id: ?int}
     */
    public function kiKartenText(Team $team, int $karteId): array
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)
            ->with(['sections.items.dish:id,name'])
            ->findOrFail($karteId);
        $this->guard($karte, $team);

        $gerichte = $karte->sections->flatMap->items
            ->where('type', 'gericht_ref')->map(fn ($p) => $p->wording ?: $p->dish?->name)
            ->filter()->take(12)->values()->all();

        $proposal = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
            'foodbook.kundentext',
            [
                'ebene' => 'speisekarte',
                'aufgabe' => 'Formuliere einen kurzen, einladenden Einleitungstext für diese Speisekarte in Brand-Voice.',
                'karte' => $karte->name,
                'gerichte' => $gerichte,
                'leitplanken' => array_filter([
                    'niveau' => $karte->default_niveau,
                    'kundentyp' => $karte->kundentyp,
                ]),
                'briefing_ist' => $karte->description,
            ],
            [
                'target_table' => 'foodalchemist_menu_cards',
                'target_id' => (int) $karte->id,
            ],
        );

        $text = trim((string) ($proposal->werte['text'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Die KI hat keinen Text geliefert — bitte erneut versuchen.');
        }

        return ['text' => $text, 'confidence' => $proposal->confidence, 'call_log_id' => $proposal->callLogId];
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    private function ownedRubrik(Team $team, int $id): FoodAlchemistSpeisekarteRubrik
    {
        $rubrik = FoodAlchemistSpeisekarteRubrik::visibleToTeam($team)->findOrFail($id);
        if (! $rubrik->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Speisekarte — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $rubrik;
    }

    private function ownedPosition(Team $team, int $id): FoodAlchemistSpeisekartePosition
    {
        $pos = FoodAlchemistSpeisekartePosition::visibleToTeam($team)->findOrFail($id);
        if (! $pos->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Speisekarte — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $pos;
    }

    private function guard(FoodAlchemistSpeisekarte $karte, Team $team): void
    {
        if (! $karte->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Speisekarte — Pflege nur durchs Besitzer-Team (D1).');
        }
    }

    /** Prüft, ob $kandidatId ein Nachfahre von $rubrikId ist (Zyklus-Schutz beim Verschieben). */
    private function istNachfahre(int $karteId, int $rubrikId, int $kandidatId): bool
    {
        $kinder = FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karteId)
            ->where('parent_id', $rubrikId)->pluck('id')->all();
        foreach ($kinder as $kind) {
            if ((int) $kind === $kandidatId || $this->istNachfahre($karteId, (int) $kind, $kandidatId)) {
                return true;
            }
        }

        return false;
    }
}
