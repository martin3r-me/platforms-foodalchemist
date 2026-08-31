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
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdeaGroup;
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
    use PruefstOutletZuordnung;

    public function __construct(private ConceptService $concepts)
    {
    }

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        // Große JSON-Spalten (Snapshots/Settings) NICHT in die sortierte Listen-Query ziehen —
        // sonst puffert MySQLs filesort die Blobs mit → „Out of sort memory" (Spec 43-Regression).
        return FoodAlchemistFoodbook::visibleToTeam($team)
            ->select($this->browserSpalten('foodalchemist_foodbooks'))
            ->with('crmCompany')
            ->withCount('chapters')
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(label) LIKE ?', [$s])
                    ->orWhereHas('crmCompany', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$s]))
                    ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['phase'] ?? '') !== '', fn ($q) => $q->where('phase', $filters['phase'])) // R4.3
            ->orderByDesc('jahr')->orderBy('label')
            ->paginate($perPage);
    }

    /**
     * Spalten für sortierte Listen-Queries OHNE die großen JSON-Blobs (Snapshots/Settings) —
     * verhindert MySQL-„Out of sort memory" beim filesort. Statisch gecacht je Tabelle.
     *
     * @return list<string>
     */
    private function browserSpalten(string $table): array
    {
        static $cache = [];
        if (! isset($cache[$table])) {
            $exclude = ['presentation_snapshot_json', 'presentation_settings_json', 'preview_snapshot_json'];
            $all = \Illuminate\Support\Facades\Schema::getColumnListing($table);
            $cols = array_values(array_diff($all, $exclude));
            $cache[$table] = $cols !== [] ? array_map(fn ($c) => $table . '.' . $c, $cols) : [$table . '.*'];
        }

        return $cache[$table];
    }

    public function detail(Team $team, int $id): ?FoodAlchemistFoodbook
    {
        return FoodAlchemistFoodbook::visibleToTeam($team)
            ->with(['chapters' => fn ($q) => $q->orderBy('position'),
                'chapters.blocks' => fn ($q) => $q->orderBy('position'),
                'chapters.blocks.concept:id,name,price_per_person_cache,price_display',   // price_display → Editor-Chip istEinzelpreis
                'chapters.blocks.dish:id,name,sales_net',
                'crmCompany', 'crmContact',   // #369: CRM-Kunde-Link
                'serviceMoments', 'targetGroups', 'defaultEventType', 'defaultServingForm']) // Spec 19 E3.3: Bedarf-Defaults
            ->find($id);
    }

    // ── Foodbook ────────────────────────────────────────────────────────────

    private const FELDER = ['code', 'label', 'jahr', 'gueltig_von', 'gueltig_bis', 'outlet_id', 'personen', 'status', 'description', 'note', 'crm_company_id', 'crm_contact_id', 'writing_style_id', 'kundentyp', 'default_niveau', 'default_convenience', 'default_event_type_id', 'default_serving_form_id', 'target_food_cost_pct', 'food_cost_tolerance_pp', 'creative_mode_default'];

    public function create(Team $team, array $in): FoodAlchemistFoodbook
    {
        // Spec 33 P2: fremde Betriebe fallen hier raus — `outlet_id` zeigt auf ein Team-Vokabular
        // und wird vom Datensatz-Guard nicht mit erfasst.
        $in = $this->pruefeOutlet($team, $in);

        return FoodAlchemistFoodbook::create([
            'team_id' => $team->id,
            'label' => trim((string) ($in['label'] ?? 'Neues Foodbook')) ?: 'Neues Foodbook',
            // Spec 33 P2: der Betrieb am Foodbook-KOPF (das Kapitel-Outlet bleibt ein Tag).
            'outlet_id' => $in['outlet_id'] ?? null,
            'crm_company_id' => $in['crm_company_id'] ?? null,
            'crm_contact_id' => $in['crm_contact_id'] ?? null,
            // Spec 33 P1: Fenster schon beim Anlegen — sonst muss man jede Ausgabe zweimal
            // anfassen, und ein Import/MCP-Aufruf verliert die Angabe stillschweigend.
            'gueltig_von' => ($in['gueltig_von'] ?? '') !== '' ? $in['gueltig_von'] : null,
            'gueltig_bis' => ($in['gueltig_bis'] ?? '') !== '' ? $in['gueltig_bis'] : null,
            'jahr' => $in['jahr'] ?? null,
            'personen' => $in['personen'] ?? null,
            'status' => AusgabeStatus::normalisiere($in['status'] ?? null)->value,
            'description' => $in['description'] ?? null,
            'creative_mode_default' => $in['creative_mode_default'] ?? null,   // Spec 19 E9.1
        ]);
    }

    /**
     * Spec 33: eingehende Werte auf das gültige Format bringen — eine Regel, ein Ort, und
     * damit auch für MCP-Aufrufe gültig, nicht nur für das Formular.
     *
     * - Status durch den Enum (P0): `status` steht in FELDER und war ohne das mit jedem
     *   beliebigen String beschreibbar — so kam ein `final` in den Bestand.
     * - Leere Datumsfelder zu NULL (P1): das Formular liefert `''` für ein nicht gesetztes
     *   Datum, und MySQL nimmt das in einer DATE-Spalte im Strict Mode nicht an.
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

    public function update(Team $team, int $id, array $in): FoodAlchemistFoodbook
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($id);
        $this->guard($fb, $team);
        $fb->update($this->normalisiereFelder(
            $this->pruefeOutlet($team, array_intersect_key($in, array_flip(self::FELDER))),
        ));

        return $fb->refresh();
    }

    /**
     * Tiefe Kopie eines Foodbooks: Kopf (inkl. Branding) → Kapitel (2-Pass parent_id) → Blöcke → Staffeln.
     * Zurückgesetzt: code, Status=Entwurf, alle presentation- und preview- und Kapitel-Snapshot-Felder
     * (Freigabe/Versand sind pro Dokument). Muster wie SpeisekarteService::dupliziere.
     */
    public function dupliziere(Team $team, int $id): FoodAlchemistFoodbook
    {
        $quelle = FoodAlchemistFoodbook::visibleToTeam($team)
            ->with(['chapters' => fn ($q) => $q->orderBy('position'), 'chapters.blocks' => fn ($q) => $q->orderBy('position'), 'chapters.blocks.staffel'])
            ->findOrFail($id);
        $this->guard($quelle, $team);

        // Kopf: FELDER + Branding; Name = label (NICHT name).
        $kopfFelder = array_merge(self::FELDER, ['brand_color', 'band_color', 'logo_path', 'cover_image_path', 'footer_text']);

        return DB::transaction(function () use ($quelle, $team, $kopfFelder) {
            $neu = FoodAlchemistFoodbook::create($this->pruefeOutlet($team, array_merge(
                array_intersect_key($quelle->only($kopfFelder), array_flip($kopfFelder)),
                ['team_id' => $team->id, 'label' => $quelle->label . ' (Kopie)', 'status' => AusgabeStatus::Entwurf->value, 'code' => null],
            )));

            // Kapitel flach kopieren (parent_id im 2. Pass), dann Blöcke + Staffeln.
            $map = [];
            foreach ($quelle->chapters as $k) {
                $kopie = FoodAlchemistFoodbookKapitel::create(array_merge(
                    array_intersect_key($k->only(self::KAPITEL_FELDER), array_flip(self::KAPITEL_FELDER)),
                    [
                        'team_id' => $neu->team_id, 'foodbook_id' => $neu->id, 'parent_id' => null,
                        'position' => $k->position, 'status' => 'draft',
                        // Format-Bindung + Kapitel-Hero mitkopieren (Snapshot/Freigabe bewusst NICHT).
                        'format_id' => $k->format_id, 'image_context_file_id' => $k->image_context_file_id, 'image_path' => $k->image_path,
                    ],
                ));
                $map[$k->id] = $kopie->id;
            }
            foreach ($quelle->chapters as $k) {
                if ($k->parent_id !== null && isset($map[$k->parent_id])) {
                    FoodAlchemistFoodbookKapitel::whereKey($map[$k->id])->update(['parent_id' => $map[$k->parent_id]]);
                }
                foreach ($k->blocks as $b) {
                    $blk = FoodAlchemistFoodbookBlock::create(array_merge(
                        array_intersect_key($b->only(self::BLOCK_FELDER), array_flip(self::BLOCK_FELDER)),
                        // presentation_id fehlt bewusst in BLOCK_FELDER → für die Kopie explizit mitnehmen.
                        ['team_id' => $neu->team_id, 'chapter_id' => $map[$k->id], 'position' => $b->position, 'presentation_id' => $b->presentation_id],
                    ));
                    foreach ($b->staffel as $st) {
                        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlockStaffel::create([
                            'team_id' => $neu->team_id, 'block_id' => $blk->id,
                            'position' => $st->position, 'min_persons' => $st->min_persons, 'price' => $st->price,
                        ]);
                    }
                }
            }

            return $neu->refresh();
        });
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

    /**
     * Spec 19 E4.6: Zielgruppen eines Kapitels setzen (PUT-Semantik — `sync` auf die
     * genaue Liste; leeres Array = alle entfernen). Kapitel-Zielgruppen überschreiben
     * den Foodbook-Default in der Kaskade (leitplanken()/zielgruppenKaskade). Die IDs
     * müssen team-sichtbares Vokabular sein (Vokabular-Pflicht, Entscheidung 6) — der
     * MCP-Guard prüft das VOR dem Aufruf; hier nur Ownership übers Kapitel.
     *
     * @param  list<int>  $ids
     */
    public function setKapitelZielgruppen(Team $team, int $kapitelId, array $ids): void
    {
        $k = $this->ownedKapitel($team, $kapitelId);
        $k->targetGroups()->sync(array_values(array_unique(array_map('intval', $ids))));
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

        // Kreativ-Modus (E9.1): Kaskade Kapitel → Foodbook → Code-Default 'hybrid'.
        [$kreativModus, $kmQuelle] = $this->kreativModusRoh($fb, $kapitel);

        return [
            'kundentyp' => $fb->kundentyp,
            'niveau' => $niveau,
            'convenience' => $fb->default_convenience ?? ($segment['convenience'] ?? null),
            'niveau_quelle' => $niveauQuelle,
            'zielgruppen' => $zielgruppen,
            'event_type_id' => $eventTypeId,
            'serving_form_id' => $servingFormId,
            'service_moment_ids' => $serviceMomentIds,
            'creative_mode' => $kreativModus,
            'quellen' => [
                'niveau' => $niveauQuelle,
                'zielgruppen' => $zgQuelle,
                'event_type_id' => $eventTypeId !== null ? 'foodbook' : null,
                'serving_form_id' => $servingFormId !== null ? 'foodbook' : null,
                'service_moment_ids' => $serviceMomentIds !== [] ? 'foodbook' : null,
                'creative_mode' => $kmQuelle,
            ],
        ];
    }

    /**
     * Spec 19 E9.1: Kreativ-Modus-Kaskade (roh, ohne Team-Guard — für leitplanken()).
     * Kapitel.creative_mode → Foodbook.creative_mode_default → CREATIVE_MODE_DEFAULT ('hybrid').
     * Unbekannte/ungültige Werte fallen auf den Default zurück (weiche Prüfung, Vokabular-Pflicht).
     *
     * @return array{0: string, 1: string} [modus, quelle('kapitel'|'foodbook'|'default')]
     */
    private function kreativModusRoh(FoodAlchemistFoodbook $fb, ?FoodAlchemistFoodbookKapitel $kapitel): array
    {
        $gueltig = FoodAlchemistFoodbookKapitel::CREATIVE_MODES;
        $kapModus = $kapitel?->creative_mode;
        if ($kapModus !== null && in_array($kapModus, $gueltig, true)) {
            return [$kapModus, 'kapitel'];
        }
        $fbModus = $fb->creative_mode_default;
        if ($fbModus !== null && in_array($fbModus, $gueltig, true)) {
            return [$fbModus, 'foodbook'];
        }

        return [FoodAlchemistFoodbookKapitel::CREATIVE_MODE_DEFAULT, 'default'];
    }

    /**
     * Spec 19 E9.1: aufgelöster Kreativ-Modus eines Kapitels (team-geguarded, öffentlich).
     * Der Auflösungs-Punkt für den Kreativ-Tab (E9.4), die Pairing-Inspiration (E9.2) und MCP.
     *
     * @return array{modus: string, quelle: string, optionen: list<string>}
     */
    public function kreativModus(Team $team, FoodAlchemistFoodbookKapitel $kapitel): array
    {
        $kapitel = $this->ownedKapitel($team, (int) $kapitel->id);
        [$modus, $quelle] = $this->kreativModusRoh($kapitel->foodbook, $kapitel);

        return ['modus' => $modus, 'quelle' => $quelle, 'optionen' => FoodAlchemistFoodbookKapitel::CREATIVE_MODES];
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

    /**
     * Spec 19 E4.2: aufgelöste SOLL-Sicht eines Kapitels — die Mengen-/Preis-/WE-Ziele mit
     * Vererbung **Kapitel → Eltern → Slot → Foodbook**. Pro Feld gewinnt der erste nicht-leere
     * Wert entlang der Kette; `quellen[<feld>]` nennt die Herkunfts-Ebene
     * ('kapitel'|'eltern'|'slot'|'foodbook'|null). Ergänzt `leitplanken()` (das die
     * Zielgruppen/Dimensionen liefert) um die kapitel-scoped SOLL-Ziele. DER SOLL-Punkt für
     * `pruefeKapitel` (E4.3), `wareneinsatzAmpel` (E4.4) und die Kapitel-Planung-Rail (E5.3).
     *
     * Feld-Ebenen: target_count/price_anchor/price_min/price_max kennen Slot-Fallback (der flache
     * Slot trug die Ziele vor der E4.1-Stempelung); niveau/serving_form_id/target_food_cost_pct
     * kennen Foodbook-Default; service_moment_id/pricing_mode nur die Kapitel-Kette (Foodbook führt
     * Einsatzmomente als 1–n-Pivot, kein Einzel-Default).
     *
     * @return array{
     *     target_count: ?int, price_anchor: ?float, price_min: ?float, price_max: ?float,
     *     niveau: ?string, serving_form_id: ?int, service_moment_id: ?int, pricing_mode: ?string,
     *     target_food_cost_pct: ?float, quellen: array<string, ?string>
     * }
     */
    public function kapitelZiele(Team $team, FoodAlchemistFoodbookKapitel $kapitel): array
    {
        $kapitel = $this->ownedKapitel($team, (int) $kapitel->id);
        $fb = $kapitel->foodbook;

        // Kapitel-Kette: self + Eltern hoch (Zyklus-Guard aus Vorsicht — moveKapitel hält den Baum acyclisch).
        $kette = [];
        $node = $kapitel;
        $besucht = [];
        while ($node !== null && ! isset($besucht[(int) $node->id])) {
            $besucht[(int) $node->id] = true;
            $kette[] = $node;
            $node = $node->parent;
        }

        // Ist-Bezug: der flache Planungs-Slot dieses Kapitels (Slot-Ziele als Fallback, falls das
        // Kapitel-Feld leer ist — z.B. nach manuellem Reset oder bei Kapiteln ohne Stempelung).
        $slot = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::where('chapter_id', $kapitel->id)->first();

        $quellen = [];
        $gesetzt = static fn ($w): bool => $w !== null && $w !== '';
        $resolve = function (string $feld, $slotWert, $fbWert) use ($kette, $slot, $gesetzt, &$quellen) {
            foreach ($kette as $i => $k) {
                if ($gesetzt($k->{$feld})) {
                    $quellen[$feld] = $i === 0 ? 'kapitel' : 'eltern';

                    return $k->{$feld};
                }
            }
            if ($slot !== null && $gesetzt($slotWert)) {
                $quellen[$feld] = 'slot';

                return $slotWert;
            }
            if ($gesetzt($fbWert)) {
                $quellen[$feld] = 'foodbook';

                return $fbWert;
            }
            $quellen[$feld] = null;

            return null;
        };

        $targetCount = $resolve('target_count', $slot?->target_count, null);
        $priceAnchor = $resolve('price_anchor', $slot?->price_anchor, null);
        $priceMin = $resolve('price_min', $slot?->price_min, null);
        $priceMax = $resolve('price_max', $slot?->price_max, null);
        $niveau = $resolve('niveau', null, $fb?->default_niveau);
        $servingFormId = $resolve('serving_form_id', null, $fb?->default_serving_form_id);
        $serviceMomentId = $resolve('service_moment_id', null, null);
        $pricingMode = $resolve('pricing_mode', null, null);
        $targetFoodCostPct = $resolve('target_food_cost_pct', null, $fb?->target_food_cost_pct);

        return [
            'target_count' => $targetCount !== null ? (int) $targetCount : null,
            'price_anchor' => $priceAnchor !== null ? (float) $priceAnchor : null,
            'price_min' => $priceMin !== null ? (float) $priceMin : null,
            'price_max' => $priceMax !== null ? (float) $priceMax : null,
            'niveau' => $niveau !== null ? (string) $niveau : null,
            'serving_form_id' => $servingFormId !== null ? (int) $servingFormId : null,
            'service_moment_id' => $serviceMomentId !== null ? (int) $serviceMomentId : null,
            'pricing_mode' => $pricingMode !== null ? (string) $pricingMode : null,
            'target_food_cost_pct' => $targetFoodCostPct !== null ? (float) $targetFoodCostPct : null,
            'quellen' => $quellen,
        ];
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
     * Spec 19 E4.5: Backfill — stempelt Slot-Ziele auf BESTEHENDE Slot↔Kapitel-Kopplungen,
     * die vor E4.1 entstanden sind (`strukturAusGeruest` stempelt nur bei NEU-Anlage; Slots,
     * die schon vor E4.1 ein Kapitel hatten, tragen ihre Ziele nie ans Kapitel weiter). Für
     * jeden Slot mit gesetztem chapter_id (Kapitel team-eigen) werden die SOLL-Felder
     * target_count/price_anchor/price_min/price_max übernommen — aber NUR die, die am Kapitel
     * noch NULL sind (bereits gesetzte Kapitel-Ziele bleiben unangetastet). Damit **idempotent**:
     * ein zweiter Lauf findet alles gefüllt und schreibt nichts. $apply=false = Dry-Run (nur
     * Protokoll, kein Write). Team-scoped über `visibleToTeam` + `isOwnedBy` (nur eigene Kapitel).
     *
     * @return array{slots_geprueft:int, kapitel_gestempelt:int, felder_gesetzt:int, protokoll: list<array{chapter_id:int, slot:string, felder:list<string>}>}
     */
    public function backfillSlotZiele(Team $team, ?int $foodbookId = null, bool $apply = false): array
    {
        $felder = ['target_count', 'price_anchor', 'price_min', 'price_max'];

        $kapitelQuery = FoodAlchemistFoodbookKapitel::visibleToTeam($team);
        if ($foodbookId !== null) {
            $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
            $this->guard($fb, $team);
            $kapitelQuery->where('foodbook_id', $foodbookId);
        }
        $kapitel = $kapitelQuery->get()
            ->filter(fn (FoodAlchemistFoodbookKapitel $k) => $k->isOwnedBy($team))
            ->keyBy('id');

        $slotsGeprueft = 0;
        $gestempelt = 0;
        $felderGesetzt = 0;
        $protokoll = [];

        if ($kapitel->isEmpty()) {
            return ['slots_geprueft' => 0, 'kapitel_gestempelt' => 0, 'felder_gesetzt' => 0, 'protokoll' => []];
        }

        $slots = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::whereIn('chapter_id', $kapitel->keys()->all())
            ->orderBy('position')->get();

        foreach ($slots as $slot) {
            $k = $kapitel->get((int) $slot->chapter_id);
            if ($k === null) {
                continue;
            }
            $slotsGeprueft++;
            $ziele = [];
            foreach ($felder as $feld) {
                if ($k->{$feld} === null && $slot->{$feld} !== null) {
                    $ziele[$feld] = $slot->{$feld};
                }
            }
            if ($ziele === []) {
                continue;
            }
            if ($apply) {
                $k->update($ziele);
            }
            $gestempelt++;
            $felderGesetzt += count($ziele);
            $protokoll[] = ['chapter_id' => (int) $k->id, 'slot' => (string) $slot->label, 'felder' => array_keys($ziele)];
        }

        return ['slots_geprueft' => $slotsGeprueft, 'kapitel_gestempelt' => $gestempelt, 'felder_gesetzt' => $felderGesetzt, 'protokoll' => $protokoll];
    }

    /**
     * Phase 3 (Weg B): ein vorgeschlagenes Gericht in den Slot ÜBERNEHMEN. Doktrin-treu —
     * das Slot-Kapitel trägt EIN Konzept (concept_ref); übernommene Gerichte werden dessen
     * Konzept-Slots. Erstes Übernehmen legt das Draft-Konzept + den concept_ref-Block an,
     * weitere hängen an. Duplikate werden übersprungen. Setzt „Struktur anwenden" voraus.
     *
     * Spec 19 E7.2: dünner, BIT-IDENTISCHER Wrapper um `uebernehmeGericht` — löst nur
     * Slot→Kapitel auf und delegiert mit $conceptId=null (= heutiges „führendes Kapitel-Konzept"-
     * Verhalten). fb-Guard-Reihenfolge bleibt (fb → guard → slot) für unveränderte Exceptions.
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

        return $this->uebernehmeGericht($team, $foodbookId, (int) $slot->chapter_id, $recipeId, $slot->label, 'foodbook_slot');
    }

    /**
     * Spec 19 E7.2 — KERN der Gericht-Übernahme (aus `uebernehmeVorschlag` extrahiert). Nimmt ein
     * VK-Gericht in ein Kapitel-Konzept auf:
     *  - $conceptId = null  → heutiges Verhalten: führendes Kapitel-Konzept (concept_ref) finden ODER
     *    neu anlegen (Draft, Niveau via leitplanken, created_via = $createdVia) + concept_ref-Block.
     *  - $conceptId gesetzt → Gericht gezielt in DIESES Konzept (E7.3 Paket-Weg). Ownership guardet
     *    `ConceptService::addSlot` selbst (visibleToTeam + guardOwner).
     * Dedup ist kapitelweit (Konzept-Slots ∪ recipe_ref-Blöcke) — quer-Kapitel bleibt WEICH (kapitelFreigeben).
     *
     * @return array{concept_id:int, chapter_id:int, schon_drin:bool}
     */
    public function uebernehmeGericht(
        Team $team,
        int $foodbookId,
        int $chapterId,
        int $recipeId,
        ?string $rolle = null,
        string $createdVia = 'foodbook_slot',
        ?int $conceptId = null
    ): array {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        $kapitel = $this->ownedKapitel($team, $chapterId);

        // Spec 19 E1.5: kapitelweite Dedup VOR jeder Anlage. Das Gericht gilt als „schon drin",
        // wenn es Slot IRGENDEINES Konzepts (concept_ref) ODER ein direkter recipe_ref-Block im
        // Kapitel ist (Union beider Wege — der alte Check sah nur EIN Konzept). Treffer ⇒ nichts
        // anlegen (auch kein leeres Konzept); concept_id = führendes Kapitel-Konzept oder 0.
        if ($this->gerichtImKapitel($kapitel, $recipeId)) {
            $vorhanden = $kapitel->blocks()->where('type', 'concept_ref')->whereNotNull('concept_id')->orderBy('position')->first();

            return ['concept_id' => (int) ($vorhanden->concept_id ?? 0), 'chapter_id' => $chapterId, 'schon_drin' => true];
        }

        if ($conceptId !== null) {
            // Gezieltes Ziel-Konzept (E7.3): kein concept_ref-Block anlegen — den setzt der Aufrufer.
            $zielConceptId = $conceptId;
        } else {
            $block = $kapitel->blocks()->where('type', 'concept_ref')->whereNotNull('concept_id')->orderBy('position')->first();
            if ($block === null) {
                // Leitstelle: das neue Kapitel-Konzept erbt das Foodbook-Niveau (concept.level, im Concepter-
                // Vokabular). Kapitel kann es dort überschreiben (basic/hochwertig/premium). null = erbt weiter.
                $niveau = \Platform\FoodAlchemist\Services\TeamSettingsService::denormNiveauFuerConcept($this->leitplanken($team, $fb)['niveau']);
                $concept = $this->concepts->create($team, array_filter([
                    'name' => trim((string) ($rolle ?: $kapitel->title)) ?: 'Konzept',
                    'status' => 'draft',
                    'level' => $niveau,
                ], fn ($v) => $v !== null));
                $concept->update(['created_via' => $createdVia]);
                $this->addBlock($team, $chapterId, ['type' => 'concept_ref', 'concept_id' => $concept->id]);
                $zielConceptId = (int) $concept->id;
            } else {
                $zielConceptId = (int) $block->concept_id;
            }
        }

        $cslot = $this->concepts->addSlot($team, $zielConceptId, ['role' => $rolle]);
        $this->concepts->fillSlot($team, $cslot->id, ['sales_recipe_id' => $recipeId, 'type' => 'gericht']);

        return ['concept_id' => $zielConceptId, 'chapter_id' => $chapterId, 'schon_drin' => false];
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

    /**
     * Spec 19 E7.3 — Kapitel-Go „Anlegen". Materialisiert die Kreativ-Skizzen (`dish_ideas`/
     * `dish_idea_groups`) eines Kapitels in echte Sortiments-Objekte. **Transaktional + idempotent**:
     *  - **Paket-Gruppe → EIN Konzept** (name/target_price_per_person aus der Gruppe; Stempel
     *    serving_form_id/event_type_id [FK], Einsatzmomente-Pivot, Zielgruppen via
     *    `concept_target_groups`-Pivot; level via `denormNiveauFuerConcept`; created_via='kapitel_freigabe')
     *    + concept_ref-Block + je Bestands-Mitglied ein Konzept-Slot (über `uebernehmeGericht` mit
     *    $conceptId — kapitelweite Dedup inklusive). Freitext-Mitglieder → Queue (E7.4).
     *  - **Einzel-Idee + Bestand-Ref → recipe_ref-Block** (sales_recipe_id, opt. Servierform greift
     *    additiv über `DarreichungResolver::fuerBlock`). Bereits vorhandener Block ⇒ weich übersprungen.
     *  - **Freitext-Idee** (kein sales_recipe_id) → `generation_status='queued'`; die eigentliche
     *    KI-Erstellung + Graceful-ohne-Provider ist E7.4 (hier nur markiert, Go scheitert NIE daran).
     *
     * Idempotenz: nur Skizzen mit `status='entwurf'` werden angefasst; Gruppen reusen ihr
     * `materialized_concept_id`. Ein zweiter Lauf findet alles freigegeben/queued und legt nichts
     * doppelt an (partielle Materialisierung ist der DoD-Fall). Setzt released_* + Anlage-Protokoll;
     * die `LogsActivity`-Trait des Kapitels loggt den released_*-Write.
     *
     * @return array{kapitel_id:int, konzepte:list<int>, bloecke_einzel:int, materialisiert:int, queued:int, uebersprungen:int, protokoll:list<array<string,mixed>>}
     */
    public function kapitelFreigeben(Team $team, int $kapitelId, ?string $note = null, ?int $userId = null): array
    {
        $kapitel = $this->ownedKapitel($team, $kapitelId);
        $fb = $kapitel->foodbook;
        $fbId = (int) $kapitel->foodbook_id;

        // Aufgelöster Stempel-Kontext (Spec 19 §KI-Führung): leitplanken() liefert Zielgruppen +
        // Foodbook-Dimensionen, kapitelZiele() die kapitel-scoped SOLL-Überschreibungen.
        $leit = $this->leitplanken($team, $fb, null, $kapitel);
        $ziele = $this->kapitelZiele($team, $kapitel);
        $servingFormId = $ziele['serving_form_id'];                                  // Kapitel→Eltern→Foodbook
        $eventTypeId = $leit['event_type_id'];                                       // Foodbook-Default
        $momentIds = $ziele['service_moment_id'] !== null ? [$ziele['service_moment_id']] : $leit['service_moment_ids'];
        $zgIds = array_values(array_map(static fn ($z) => (int) $z['id'], $leit['zielgruppen']));
        $niveau = TeamSettingsService::denormNiveauFuerConcept($ziele['niveau'] ?? $leit['niveau']);

        $konzepte = [];
        $materialisiert = 0;
        $queued = 0;
        $uebersprungen = 0;
        $bloeckeEinzel = 0;
        $protokoll = [];

        DB::transaction(function () use (
            $team, $kapitel, $kapitelId, $fbId, $servingFormId, $eventTypeId, $momentIds, $zgIds, $niveau, $note, $userId,
            &$konzepte, &$materialisiert, &$queued, &$uebersprungen, &$bloeckeEinzel, &$protokoll
        ) {
            // ── Paket-Gruppen → Konzepte ────────────────────────────────────────
            $gruppen = FoodAlchemistDishIdeaGroup::where('team_id', $team->id)
                ->where('chapter_id', $kapitelId)
                ->orderBy('position')->orderBy('id')->get();

            foreach ($gruppen as $gruppe) {
                $members = FoodAlchemistDishIdea::where('team_id', $team->id)
                    ->where('group_id', $gruppe->id)
                    ->where('status', 'entwurf')
                    ->orderBy('position')->orderBy('id')->get();
                if ($members->isEmpty() && $gruppe->materialized_concept_id === null) {
                    $uebersprungen++;
                    $protokoll[] = ['typ' => 'paket', 'gruppe_id' => (int) $gruppe->id, 'status' => 'leer_uebersprungen'];

                    continue;
                }

                // Konzept reusen (partieller Re-Run) ODER neu anlegen + stempeln.
                if ($gruppe->materialized_concept_id !== null) {
                    $concept = FoodAlchemistConcept::find($gruppe->materialized_concept_id);
                } else {
                    $concept = null;
                }
                if ($concept === null) {
                    $concept = $this->concepts->create($team, array_filter([
                        'name' => trim((string) $gruppe->name) ?: 'Paket',
                        'status' => 'draft',
                        'level' => $niveau,
                    ], static fn ($v) => $v !== null));
                    $concept->update(array_filter([
                        'created_via' => 'kapitel_freigabe',
                        'target_price_per_person' => $gruppe->target_price_pp,
                        'serving_form_id' => $servingFormId,
                        'event_type_id' => $eventTypeId,
                    ], static fn ($v) => $v !== null));
                    if ($momentIds !== []) {
                        $concept->serviceMoments()->sync($momentIds);
                    }
                    if ($zgIds !== []) {
                        $concept->targetGroups()->sync($zgIds);        // concept_target_groups (Entscheidung 6)
                    }
                    $gruppe->update(['materialized_concept_id' => $concept->id]);
                }
                $konzepte[] = (int) $concept->id;

                // concept_ref-Block anlegen, falls noch keiner auf dieses Konzept zeigt (idempotent).
                if (! $kapitel->blocks()->where('type', 'concept_ref')->where('concept_id', $concept->id)->exists()) {
                    $this->addBlock($team, $kapitelId, ['type' => 'concept_ref', 'concept_id' => $concept->id]);
                }

                foreach ($members as $idee) {
                    if ($idee->sales_recipe_id !== null) {
                        // Bestands-Mitglied → Konzept-Slot (kapitelweite Dedup via uebernehmeGericht).
                        $this->uebernehmeGericht($team, $fbId, $kapitelId, (int) $idee->sales_recipe_id, $idee->title, 'kapitel_freigabe', (int) $concept->id);
                        $cslot = FoodAlchemistConceptSlot::where('concept_id', $concept->id)
                            ->where('sales_recipe_id', $idee->sales_recipe_id)->orderByDesc('id')->first();
                        $idee->update([
                            'status' => 'freigegeben',
                            'materialized_at' => now(),
                            'materialized_ref' => ['concept_id' => (int) $concept->id, 'concept_slot_id' => (int) ($cslot->id ?? 0)],
                        ]);
                        $materialisiert++;
                        $protokoll[] = ['typ' => 'paket', 'gruppe_id' => (int) $gruppe->id, 'idee_id' => (int) $idee->id, 'status' => 'slot', 'concept_id' => (int) $concept->id];
                    } else {
                        // Freitext-Mitglied → KI-Queue (E7.4 erstellt das Rezept + füllt den Slot).
                        $idee->update(['generation_status' => 'queued']);
                        $queued++;
                        $protokoll[] = ['typ' => 'paket', 'gruppe_id' => (int) $gruppe->id, 'idee_id' => (int) $idee->id, 'status' => 'queued', 'concept_id' => (int) $concept->id];
                    }
                }
            }

            // ── Einzel-Ideen → recipe_ref-Blöcke ────────────────────────────────
            $einzel = FoodAlchemistDishIdea::where('team_id', $team->id)
                ->where('chapter_id', $kapitelId)
                ->whereNull('group_id')
                ->where('status', 'entwurf')
                ->orderBy('position')->orderBy('id')->get();

            foreach ($einzel as $idee) {
                if ($idee->sales_recipe_id !== null) {
                    $vorhanden = $kapitel->blocks()->where('type', 'recipe_ref')->where('sales_recipe_id', $idee->sales_recipe_id)->first();
                    if ($vorhanden !== null) {
                        // Weiche kapitelweite Dedup: Gericht liegt schon als Block — nur verknüpfen.
                        // `created=false` ⇒ Undo (E7.5) räumt diesen VORBESTEHENDEN Block NICHT weg.
                        $idee->update([
                            'status' => 'freigegeben',
                            'materialized_at' => now(),
                            'materialized_ref' => ['block_id' => (int) $vorhanden->id, 'created' => false],
                        ]);
                        $uebersprungen++;
                        $protokoll[] = ['typ' => 'einzel', 'idee_id' => (int) $idee->id, 'status' => 'block_vorhanden', 'block_id' => (int) $vorhanden->id];

                        continue;
                    }
                    $block = $this->addBlock($team, $kapitelId, [
                        'type' => 'recipe_ref',
                        'sales_recipe_id' => (int) $idee->sales_recipe_id,
                    ]);
                    // `created=true` ⇒ Undo (E7.5) löscht diesen frisch angelegten Block wieder.
                    $idee->update([
                        'status' => 'freigegeben',
                        'materialized_at' => now(),
                        'materialized_ref' => ['block_id' => (int) $block->id, 'created' => true],
                    ]);
                    $bloeckeEinzel++;
                    $materialisiert++;
                    $protokoll[] = ['typ' => 'einzel', 'idee_id' => (int) $idee->id, 'status' => 'block', 'block_id' => (int) $block->id];
                } else {
                    // Freitext-Einzel-Idee → KI-Queue (E7.4).
                    $idee->update(['generation_status' => 'queued']);
                    $queued++;
                    $protokoll[] = ['typ' => 'freitext', 'idee_id' => (int) $idee->id, 'status' => 'queued'];
                }
            }

            $ergebnis = [
                'kapitel_id' => $kapitelId,
                'konzepte' => array_values(array_unique($konzepte)),
                'bloecke_einzel' => $bloeckeEinzel,
                'materialisiert' => $materialisiert,
                'queued' => $queued,
                'uebersprungen' => $uebersprungen,
            ];
            $kapitel->update([
                'released_at' => now(),
                'released_by' => $userId,
                'release_note' => $note,
                'release_result' => $ergebnis,
            ]);
        });

        return [
            'kapitel_id' => $kapitelId,
            'konzepte' => array_values(array_unique($konzepte)),
            'bloecke_einzel' => $bloeckeEinzel,
            'materialisiert' => $materialisiert,
            'queued' => $queued,
            'uebersprungen' => $uebersprungen,
            'protokoll' => $protokoll,
        ];
    }

    /**
     * Spec 19 E7.5 — „Anlage zurückziehen". Macht {@see kapitelFreigeben} rückgängig, solange
     * das Kapitel noch bearbeitbar ist: **draft + created_via='kapitel_freigabe' + kein
     * Snapshot/Versand**. Räumt NUR weg, was die Anlage selbst erzeugt hat:
     *  - **Anlage-Konzepte** (created_via='kapitel_freigabe' & status='draft') samt ihrer
     *    Slots + concept_ref-Blöcke; die Paket-Gruppe verliert ihr `materialized_concept_id`.
     *  - **frische recipe_ref-Blöcke** der Einzel-/Freitext-Ideen (`materialized_ref.created===true`);
     *    per Dedup nur VERKNÜPFTE Bestands-Blöcke (`created===false`) bleiben stehen.
     *  - **Ideen** kehren auf `status='entwurf'` zurück (materialized_* geleert, `queued`/`erstellt`
     *    → generation_status null). KI-generierte Rezepte (`generated_recipe_id`) bleiben als
     *    Draft erhalten — kein stiller Datenverlust (Spec 19 §3).
     * Konzepte, die der User seit der Anlage bearbeitet/aktiviert hat (status ≠ draft) oder
     * die nicht aus der Anlage stammen, bleiben unangetastet und werden weich gemeldet.
     * Transaktional + idempotent (zweiter Lauf findet `released_at=null` → no-op). Setzt
     * released_* zurück; der LogsActivity-Trait des Kapitels loggt den Write.
     *
     * @return array{kapitel_id:int, status:string, konzepte_geloescht:int, bloecke_geloescht:int, ideen_zurueckgesetzt:int, uebersprungen:int, protokoll:list<array<string,mixed>>}
     */
    public function anlageZuruckziehen(Team $team, int $kapitelId): array
    {
        $kapitel = $this->ownedKapitel($team, $kapitelId);

        if ($kapitel->released_at === null) {
            return ['kapitel_id' => $kapitelId, 'status' => 'nichts_anzulegen', 'konzepte_geloescht' => 0, 'bloecke_geloescht' => 0, 'ideen_zurueckgesetzt' => 0, 'uebersprungen' => 0, 'protokoll' => []];
        }
        // Harte Grenze (Spec 19 UX 4): ab Snapshot/Versand ist die Anlage eingefroren.
        if ($kapitel->snapshot_at !== null || $kapitel->status === 'sent') {
            throw new \RuntimeException('Kapitel bereits versendet/eingefroren — Anlage kann nicht zurückgezogen werden.');
        }

        $konzepteGeloescht = 0;
        $bloeckeGeloescht = 0;
        $ideenReset = 0;
        $uebersprungen = 0;
        $protokoll = [];

        DB::transaction(function () use (
            $team, $kapitel, $kapitelId,
            &$konzepteGeloescht, &$bloeckeGeloescht, &$ideenReset, &$uebersprungen, &$protokoll
        ) {
            // ── 1) Materialisierte Ideen zurücksetzen (+ frische Einzel-/Freitext-Blöcke löschen) ──
            $ideen = FoodAlchemistDishIdea::where('team_id', $team->id)
                ->where('chapter_id', $kapitelId)
                ->where('status', 'freigegeben')
                ->get();
            foreach ($ideen as $idee) {
                $ref = $idee->materialized_ref ?? [];
                if (isset($ref['block_id']) && ($ref['created'] ?? true)) {
                    $block = FoodAlchemistFoodbookBlock::where('id', (int) $ref['block_id'])
                        ->where('chapter_id', $kapitelId)->where('type', 'recipe_ref')->first();
                    if ($block !== null) {
                        $block->delete();
                        $bloeckeGeloescht++;
                    }
                }
                // Paket-Slots verschwinden mit dem Konzept (Schritt 3) — hier nur die Idee lösen.
                $idee->update([
                    'status' => 'entwurf',
                    'materialized_at' => null,
                    'materialized_ref' => null,
                    'generation_status' => in_array($idee->generation_status, ['queued', 'erstellt'], true) ? null : $idee->generation_status,
                ]);
                $ideenReset++;
                $protokoll[] = ['typ' => 'idee', 'idee_id' => (int) $idee->id, 'status' => 'zurueckgesetzt'];
            }

            // Freitext-Ideen, die nur in der Queue landeten (nie freigegeben) → entwarten.
            $queued = FoodAlchemistDishIdea::where('team_id', $team->id)
                ->where('chapter_id', $kapitelId)
                ->where('status', 'entwurf')
                ->where('generation_status', 'queued')
                ->get();
            foreach ($queued as $idee) {
                $idee->update(['generation_status' => null]);
                $ideenReset++;
                $protokoll[] = ['typ' => 'idee', 'idee_id' => (int) $idee->id, 'status' => 'queue_geleert'];
            }

            // ── 2) Anlage-Konzepte der Paket-Gruppen löschen ──
            $gruppen = FoodAlchemistDishIdeaGroup::where('team_id', $team->id)
                ->where('chapter_id', $kapitelId)
                ->whereNotNull('materialized_concept_id')
                ->get();
            foreach ($gruppen as $gruppe) {
                $concept = FoodAlchemistConcept::find($gruppe->materialized_concept_id);
                if ($concept !== null && $concept->created_via === 'kapitel_freigabe' && $concept->status === 'draft') {
                    FoodAlchemistFoodbookBlock::where('chapter_id', $kapitelId)
                        ->where('type', 'concept_ref')->where('concept_id', $concept->id)->delete();
                    FoodAlchemistConceptSlot::where('concept_id', $concept->id)->delete();
                    $concept->delete();
                    $konzepteGeloescht++;
                    $protokoll[] = ['typ' => 'paket', 'gruppe_id' => (int) $gruppe->id, 'concept_id' => (int) $concept->id, 'status' => 'konzept_geloescht'];
                    $gruppe->update(['materialized_concept_id' => null]);
                } else {
                    // Bearbeitet/aktiviert oder fremd → stehenlassen, nur weich melden.
                    $uebersprungen++;
                    $protokoll[] = ['typ' => 'paket', 'gruppe_id' => (int) $gruppe->id, 'concept_id' => (int) ($concept->id ?? 0), 'status' => 'konzept_behalten'];
                }
            }

            // ── 3) Anlage-Stand am Kapitel löschen ──
            $kapitel->update([
                'released_at' => null,
                'released_by' => null,
                'release_note' => null,
                'release_result' => null,
            ]);
        });

        return [
            'kapitel_id' => $kapitelId,
            'status' => 'zurueckgezogen',
            'konzepte_geloescht' => $konzepteGeloescht,
            'bloecke_geloescht' => $bloeckeGeloescht,
            'ideen_zurueckgesetzt' => $ideenReset,
            'uebersprungen' => $uebersprungen,
            'protokoll' => $protokoll,
        ];
    }

    /**
     * Spec 19 E7.4 — Kapitel-Go-Nachlauf: reiht je queued Freitext-Skizze einen
     * `MaterializeIdeaJob` ein (GenerateRecipeJob-Muster → database-Queue auf demo).
     * Menschlich getriggert (Anlage-Modal E7.5) — KEIN MCP-Trigger (Spec §Lockstep:
     * „Kapitel-Go OHNE MCP-Trigger"). Bestands-Refs sind beim Go schon materialisiert;
     * nur echte Freitext-Skizzen (sales_recipe_id null, generation_status='queued')
     * kommen in die KI-Queue. Der eigentliche Erdungs-Schritt läuft graceful im Job
     * (`materialisiereFreitextIdee`) — ohne Provider bleibt die Skizze queued.
     *
     * @return array{dispatched:int, ids:list<int>}
     */
    public function verarbeiteFreitextQueue(Team $team, int $kapitelId, ?int $userId = null): array
    {
        $this->ownedKapitel($team, $kapitelId);
        $ids = FoodAlchemistDishIdea::where('team_id', $team->id)
            ->where('chapter_id', $kapitelId)
            ->where('generation_status', 'queued')
            ->whereNull('sales_recipe_id')
            ->whereNull('materialized_at')
            ->orderBy('position')->orderBy('id')
            ->pluck('id')->map(static fn ($i) => (int) $i)->all();

        foreach ($ids as $id) {
            \Platform\FoodAlchemist\Jobs\MaterializeIdeaJob::dispatch($team->id, $userId ?? 0, $id);
        }

        return ['dispatched' => count($ids), 'ids' => $ids];
    }

    /**
     * Spec 19 E7.4 — Freitext-Queue-Prozessor (L7/L8). Erdet EINE queued Freitext-Skizze
     * in ein echtes VK-Gericht. Der `RecipeGeneratorService` (vkModus) IST die
     * Anpassungs-Schleife: er groundet die freie Idee gegen den verfügbaren Bestand
     * (kandidatenPool/#507) bzw. mintet LA-First eine tentative GP (Spec §„Erst kreativ,
     * dann erden"). Das erzeugte Gericht wird materialisiert — Paket-Mitglied → Konzept-Slot,
     * Einzel → recipe_ref-Block — und die Skizze auf `erstellt`/`freigegeben` gesetzt.
     *
     * **Graceful (DoD):** Ohne LLM-Provider bzw. bei Team-Kill-Switch wirft `propose()`
     * typisiert (KiNichtVerfuegbar/KiDeaktiviert) → die Skizze bleibt `queued`
     * („wartet auf KI", retrybar), der Go scheitert NIE. Jeder andere Fehler →
     * `fehlgeschlagen` (Original bleibt in `dish_ideas` erhalten — kein stiller
     * Kreativitätsverlust). In BEIDEN Fehlerfällen fliegt KEINE Exception hoch,
     * der Queue-Batch läuft weiter.
     *
     * @return array{status:string, idea_id:int, recipe_id?:int, hinweis?:string, fehler?:string}
     */
    public function materialisiereFreitextIdee(Team $team, int $ideaId): array
    {
        $idee = FoodAlchemistDishIdea::visibleToTeam($team)->find($ideaId);
        if ($idee === null || ! $idee->isOwnedBy($team)) {
            throw new \RuntimeException('Skizze nicht gefunden oder geerbt (D1).');
        }
        // Nur echte, noch offene Freitext-Skizzen erden; Bestands-Refs / bereits materialisierte überspringen.
        if ($idee->sales_recipe_id !== null || $idee->generation_status !== 'queued' || $idee->materialized_at !== null) {
            return ['status' => 'uebersprungen', 'idea_id' => (int) $idee->id];
        }
        $kapitelId = (int) $idee->chapter_id;
        if ($kapitelId <= 0) {
            // Konzept-owned Skizze — die Freitext-Queue läuft nur kapitelweit (Kapitel-Go).
            return ['status' => 'uebersprungen', 'idea_id' => (int) $idee->id];
        }
        $kapitel = $this->ownedKapitel($team, $kapitelId);
        $fb = $kapitel->foodbook;
        $fbId = (int) $kapitel->foodbook_id;

        // Erdungs-Kontext (Spec §KI-Führung): kapitel-aufgelöstes Niveau steuert den Generator.
        $ziele = $this->kapitelZiele($team, $kapitel);
        $leit = $this->leitplanken($team, $fb, null, $kapitel);
        $beschreibung = trim(implode(' — ', array_filter([
            (string) $idee->title, (string) $idee->description,
        ]))) ?: (string) $idee->title;
        $parameter = array_filter([
            'niveau' => $ziele['niveau'] ?? $leit['niveau'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        try {
            $gen = app(RecipeGeneratorService::class)->generiere($team, $beschreibung, $parameter, null, true);
            $recipe = $gen['recipe'] ?? null;
            if ($recipe === null) {
                throw new \RuntimeException('Generierung lieferte kein Rezept.');
            }

            $ref = DB::transaction(function () use ($team, $idee, $kapitelId, $fbId, $recipe) {
                // Paket-Mitglied → Slot im Gruppen-Konzept (via uebernehmeGericht, kapitelweite Dedup);
                // sonst Einzel → recipe_ref-Block am Kapitel.
                $gruppe = $idee->group_id !== null
                    ? FoodAlchemistDishIdeaGroup::where('team_id', $team->id)->find($idee->group_id)
                    : null;
                if ($gruppe !== null && $gruppe->materialized_concept_id !== null) {
                    $this->uebernehmeGericht($team, $fbId, $kapitelId, (int) $recipe->id, $idee->title, 'kapitel_freigabe_ki', (int) $gruppe->materialized_concept_id);
                    $cslot = FoodAlchemistConceptSlot::where('concept_id', $gruppe->materialized_concept_id)
                        ->where('sales_recipe_id', $recipe->id)->orderByDesc('id')->first();

                    return ['concept_id' => (int) $gruppe->materialized_concept_id, 'concept_slot_id' => (int) ($cslot->id ?? 0)];
                }
                $block = $this->addBlock($team, $kapitelId, ['type' => 'recipe_ref', 'sales_recipe_id' => (int) $recipe->id]);

                return ['block_id' => (int) $block->id];
            });

            $idee->update([
                'generation_status' => 'erstellt',
                'status' => 'freigegeben',
                'generated_recipe_id' => (int) $recipe->id,
                'materialized_at' => now(),
                'materialized_ref' => $ref,
                'source_meta' => array_merge($idee->source_meta ?? [], [
                    'erdung' => 'ki_generiert',
                    'original_titel' => (string) $idee->title,        // kein stiller Kreativitätsverlust
                    'generated_recipe_id' => (int) $recipe->id,
                ]),
            ]);

            return ['status' => 'erstellt', 'idea_id' => (int) $idee->id, 'recipe_id' => (int) $recipe->id];
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException | \Platform\FoodAlchemist\Exceptions\KiDeaktiviertException $e) {
            // Graceful: kein Provider / Kill-Switch → bleibt queued (retrybar), Go scheitert nicht.
            $idee->update(['source_meta' => array_merge($idee->source_meta ?? [], [
                'generation_hinweis' => 'wartet auf KI',
            ])]);

            return ['status' => 'wartet_ki', 'idea_id' => (int) $idee->id, 'hinweis' => $e->getMessage()];
        } catch (\Throwable $e) {
            // Jeder andere Fehler (unbrauchbares KI-Rezept, Grounding) → fehlgeschlagen; Original bleibt.
            $idee->update([
                'generation_status' => 'fehlgeschlagen',
                'source_meta' => array_merge($idee->source_meta ?? [], [
                    'generation_fehler' => mb_substr($e->getMessage(), 0, 500),
                ]),
            ]);

            return ['status' => 'fehlgeschlagen', 'idea_id' => (int) $idee->id, 'fehler' => $e->getMessage()];
        }
    }

    private const KAPITEL_FELDER = [
        'title', 'consumer_title', 'claim', 'description', 'price_per_person', 'price_mode',
        // SOLL-Ziele (Spec 19, M3) — release_* NICHT hier (setzt kapitelFreigeben, E7.3)
        'target_count', 'price_anchor', 'price_min', 'price_max', 'niveau',
        'service_moment_id', 'serving_form_id', 'pricing_mode', 'target_food_cost_pct',
        'creative_mode',   // Kreativ-Modus-Override (Spec 19, E9.1)
        'writing_style_id',   // #2: Schreibstil-Override pro Kapitel (NULL = Concept-Standard erben)
        'is_struktur',   // Textkapitel/Sektion (kein eigenes Food) — Dominique 2026-08-27
    ];

    public function updateKapitel(Team $team, int $id, array $in): FoodAlchemistFoodbookKapitel
    {
        $k = $this->ownedKapitel($team, $id);
        $felder = array_intersect_key($in, array_flip(self::KAPITEL_FELDER));
        // #2: leere Dropdown-Auswahl ('' aus dem Blade) = kein Override → NULL (FK-safe).
        if (array_key_exists('writing_style_id', $felder)) {
            $felder['writing_style_id'] = ($felder['writing_style_id'] === '' || $felder['writing_style_id'] === null)
                ? null : (int) $felder['writing_style_id'];
        }
        $k->update($felder);

        return $k->refresh();
    }

    // ── Spec 43 (Bild-Epic): Kapitel-Bild (überschreibt das Concept-Titelbild im Kapitel-Band) ──

    public function setKapitelImage(Team $team, int $kapitelId, UploadedFile $file): string
    {
        $k = $this->ownedKapitel($team, $kapitelId);
        app(FoodAlchemistMediaService::class)->delete($k->image_context_file_id, (string) $k->image_path, $team);
        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist.foodbook_chapter', $kapitelId, "foodalchemist/chapter/{$kapitelId}",
        );
        $k->update(['image_context_file_id' => $media['context_file_id'], 'image_path' => $media['path']]);

        return $media['path'];
    }

    public function clearKapitelImage(Team $team, int $kapitelId): FoodAlchemistFoodbookKapitel
    {
        $k = $this->ownedKapitel($team, $kapitelId);
        app(FoodAlchemistMediaService::class)->delete($k->image_context_file_id, (string) $k->image_path, $team);
        $k->update(['image_context_file_id' => null, 'image_path' => null]);

        return $k->refresh();
    }

    /** Weiteres Galeriebild (neben dem Kapitel-Bild) ans Foodbook-Kapitel hängen. */
    public function addKapitelGalleryImage(Team $team, int $kapitelId, UploadedFile $file): \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage
    {
        $k = $this->ownedKapitel($team, $kapitelId);
        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist.foodbook_chapter', $kapitelId, "foodalchemist/chapter/{$kapitelId}/gallery",
        );

        return \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage::create([
            'team_id' => $k->team_id,
            'chapter_id' => $kapitelId,
            'context_file_id' => $media['context_file_id'],
            'path' => $media['path'],
            'sort_order' => (int) $k->images()->max('sort_order') + 1,
        ]);
    }

    public function removeKapitelGalleryImage(Team $team, int $imageId): void
    {
        $img = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage::findOrFail($imageId);
        $this->ownedKapitel($team, (int) $img->chapter_id); // Owner-Guard übers Kapitel
        app(FoodAlchemistMediaService::class)->delete($img->context_file_id, (string) $img->path, $team);
        $img->delete();
    }

    /**
     * #2: das WORDING aller concept_ref-Blöcke eines Kapitels im KAPITEL-Schreibstil neu betexten
     * und foodbook-LOKAL als Block-Override (payload_json['wording_overrides']) speichern (Snapshot).
     * Das Concept bleibt unangetastet — nur der Foodbook-Block trägt den Kapitel-Stil-Text.
     *
     * Der Stil = Kapitel-Override (`writing_style_id`); ist keiner gesetzt, gibt es nichts zu
     * überschreiben (dann erbt das Kapitel den Concept-Standard live) → 0 zurück, kein LLM-Call.
     * Nutzt denselben `concept.wording`-Prompt + `sprach_duktus`-Kontext wie der Concepter.
     *
     * @return int Anzahl neu betexteter concept_ref-Blöcke
     */
    public function kapitelWordingRegenerieren(Team $team, int $kapitelId): int
    {
        $k = $this->ownedKapitel($team, $kapitelId);
        $k->loadMissing(['writingStyle', 'blocks.concept.slots.dish:id,name,sales_wording_standard',
            // Paket-in-Kapitel-Wording: auch die Gerichte eingebetteter Pakete mit-betexten.
            'blocks.concept.slots.embeddedConcept.slots.dish:id,name,sales_wording_standard']);
        $stil = $k->writingStyle;
        if ($stil === null) {
            return 0; // kein Kapitel-Override → nichts zu snapshotten (Standard erbt live)
        }

        $gateway = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class);
        $n = 0;
        foreach ($k->blocks as $block) {
            if ($block->type !== 'concept_ref' || $block->concept === null) {
                continue;
            }
            // Positionen = direkte Gericht-Slots UND die Gerichte eingebetteter Pakete (deren Slot-IDs) —
            // damit das Kapitel-Wording auch die Paket-Gerichte betextet (Dominique-Fund).
            $positionen = [];
            foreach ($block->concept->slots as $s) {
                if ($s->sales_recipe_id !== null && $s->dish) {
                    $positionen[] = ['slot_id' => $s->id, 'name' => $s->dish->name, 'sales_wording_standard' => $s->dish->sales_wording_standard ?? null];
                } elseif ($s->embedded_concept_id !== null && $s->embeddedConcept) {
                    foreach ($s->embeddedConcept->slots as $ps) {
                        if ($ps->sales_recipe_id !== null && $ps->dish) {
                            $positionen[] = ['slot_id' => $ps->id, 'name' => $ps->dish->name, 'sales_wording_standard' => $ps->dish->sales_wording_standard ?? null];
                        }
                    }
                }
            }
            if ($positionen === []) {
                continue;
            }
            $kontext = [
                'concept' => $block->concept->name,
                'occasion' => $block->concept->occasion,
                'class' => $block->concept->class,
                // #2 + Schreibstil-Fix: der KAPITEL-Stil steuert die Tonalität (sprach_duktus, nicht nur Name).
                'schreibstil' => $stil->name,
                'schreibstil_anweisung' => trim((string) $stil->sprach_duktus) ?: null,
                'schreibstil_beispiele' => trim((string) $stil->beispiele_md) ?: null,
                'positionen' => $positionen,
            ];
            try {
                $vorschlag = $gateway->propose('concept.wording', $kontext, [
                    'food_dna_foodbook_id' => $k->foodbook_id,
                    'food_dna_concept_id' => $block->concept->id,
                    'target_table' => 'foodalchemist_foodbook_blocks', 'target_id' => $block->id,
                ]);
            } catch (\Throwable) {
                continue; // fail-soft: ein Block kippt nicht die ganze Runde
            }
            // Intro → Block-Beschreibung (kundensichtbar, foodbook-lokal).
            $intro = $vorschlag->werte['intro'] ?? null;
            if (is_string($intro) && trim($intro) !== '') {
                $this->updateBlock($team, $block->id, ['customer_text' => trim($intro)]);
            }
            // Gericht-Wordings → foodbook-lokaler Override je Slot (Snapshot, überschreibt Concept-Kette).
            foreach (($vorschlag->werte['slots'] ?? []) as $slotId => $text) {
                if (is_string($text) && trim($text) !== '') {
                    $this->setBlockSlotWording($team, $block->id, (int) $slotId, trim($text));
                }
            }
            $n++;
        }

        return $n;
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
    public function blockPreis(FoodAlchemistFoodbookBlock $block, ?int $pax = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
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
    public function kapitelAggregat(Team $team, FoodAlchemistFoodbookKapitel $kapitel, ?int $pax = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $kapitel->loadMissing(['blocks' => fn ($q) => $q->where('visible', true),
            'blocks.concept:id,name,price_per_person_cache', 'blocks.dish:id,sales_net,ek_total_eur',
            'blocks.staffel', 'children']);

        $vk = 0.0;
        $ek = 0.0;
        $pauschal = 0.0;
        foreach ($kapitel->blocks as $block) {
            $p = $this->blockPreis($block, $pax, $outlet);
            $vk += $p['vk_pp'];
            $ek += $p['ek_pp'];
            $pauschal += $p['pauschal'];
        }
        foreach ($kapitel->children as $kind) {
            $kindAgg = $this->kapitelAggregat($team, $kind, $pax, $outlet);
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
     * Spec 19 E4.4: Wareneinsatz-Ampel eines Kapitels. **IST** = tatsächliche Food-Cost-%
     * aus `kapitelAggregat()` (EK ÷ VK der Per-Person-Anteile, rekursiv über Nachfahren).
     * **SOLL** = Ziel-Wareneinsatz mit Kaskade Kapitel → Eltern → Foodbook (via `kapitelZiele`)
     * → Team-Setting (`zielWareneinsatzPct`, 30 %-Default). Toleranz = `food_cost_tolerance_pp`
     * des Foodbooks (Code-Default 5,0 pp).
     *
     * Ampel: `gruen` IST ≤ Ziel · `gelb` IST ≤ Ziel+Toleranz · `rot` darüber · `unbekannt`
     * ohne IST (kein Per-Person-VK). **Partiell-Hinweis:** Pauschal-Anteile (header_frei_preis/
     * pauschal, recipe_ref/pauschal) tragen VK, aber ihr EK bleibt ungezählt (`blockPreis`, E1.2)
     * → die IST-Quote unterschätzt den echten Food-Cost. `partiell=true` markiert das, damit die
     * Kalkulations-Rail (E5.3) und `coverage.GET` (E4.6) den Vorbehalt sichtbar machen.
     *
     * @return array{status: string, ist_pct: ?float, ziel_pct: float, toleranz_pp: float,
     *               quelle: string, partiell: bool}
     */
    public function wareneinsatzAmpel(Team $team, FoodAlchemistFoodbook $fb, FoodAlchemistFoodbookKapitel $kapitel, ?int $pax = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        // kapitelZiele zuerst — erzwingt Team-Scope + Ownership (ownedKapitel) vor jeder Rechnung.
        $ziele = $this->kapitelZiele($team, $kapitel);
        $ziel = $ziele['target_food_cost_pct'];
        $quelle = $ziele['quellen']['target_food_cost_pct'] ?? null;
        if ($ziel === null) {
            // Ebene 2: ohne Kapitel-Ziel folgt die Soll-Quote der Betriebsbrille (Betriebs-Ziel-WE).
            $ziel = app(TeamSettingsService::class)->zielWareneinsatzPct($team, $outlet);
            $quelle = 'settings';
        }
        $ziel = (float) $ziel;

        $agg = $this->kapitelAggregat($team, $kapitel, $pax, $outlet);
        $ist = $agg['food_cost_percent']; // ?float

        $tol = $fb->food_cost_tolerance_pp !== null ? (float) $fb->food_cost_tolerance_pp : 5.0;

        return [
            'status' => $this->weAmpelStatus($ist, $ziel, $tol),
            'ist_pct' => $ist,
            'ziel_pct' => round($ziel, 2),
            'toleranz_pp' => round($tol, 2),
            'quelle' => $quelle ?? 'settings',
            'partiell' => $agg['pauschal'] > 0,
        ];
    }

    /**
     * Spec 19 E8.2 — Portfolio-Wareneinsatz-Ampel des GANZEN Foodbooks (Rail-Kalkulation-
     * Panel, Kopf-Modus). Gleiche Ampel-Logik wie {@see wareneinsatzAmpel}, aber IST aus dem
     * Foodbook-Gesamt ({@see gesamt}: Σ Top-Kapitel Per-Person, EK ÷ VK) statt je Kapitel.
     * **SOLL** = Foodbook-Ziel (`target_food_cost_pct`) → Team-Setting-Default (30 %);
     * Toleranz = `food_cost_tolerance_pp` (Code-Default 5,0 pp). **Partiell** = Pauschal-Anteil
     * im Gesamt (EK ungezählt → IST unterschätzt), analog zur Kapitel-Ampel.
     *
     * @return array{status: string, ist_pct: ?float, ziel_pct: float, toleranz_pp: float, quelle: string, partiell: bool}
     */
    public function foodbookWareneinsatzAmpel(Team $team, FoodAlchemistFoodbook $fb, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $gesamt = $this->gesamt($team, $fb, $outlet);
        $vk = $gesamt['vk_pro_person'];
        $ek = $gesamt['ek_per_person'];
        $ist = $vk > 0 ? round($ek / $vk * 100, 1) : null;

        if ($fb->target_food_cost_pct !== null) {
            $ziel = (float) $fb->target_food_cost_pct;
            $quelle = 'foodbook';
        } else {
            // Ebene 2: Soll-Quote folgt der Betriebsbrille.
            $ziel = app(TeamSettingsService::class)->zielWareneinsatzPct($team, $outlet);
            $quelle = 'settings';
        }
        $tol = $fb->food_cost_tolerance_pp !== null ? (float) $fb->food_cost_tolerance_pp : 5.0;

        return [
            'status' => $this->weAmpelStatus($ist, $ziel, $tol),
            'ist_pct' => $ist,
            'ziel_pct' => round($ziel, 2),
            'toleranz_pp' => round($tol, 2),
            'quelle' => $quelle,
            'partiell' => $gesamt['pauschal'] > 0,
        ];
    }

    /**
     * Gemeinsame Ampel-Klassifikation (E4.4/E8.2): `gruen` IST ≤ Ziel · `gelb` IST ≤ Ziel+Toleranz
     * · `rot` darüber · `unbekannt` ohne IST. Wird von Kapitel- ({@see wareneinsatzAmpel}) und
     * Portfolio-Ampel ({@see foodbookWareneinsatzAmpel}) geteilt (identische Grenzen).
     */
    private function weAmpelStatus(?float $ist, float $ziel, float $tol): string
    {
        if ($ist === null) {
            return 'unbekannt';
        }
        if ($ist <= $ziel) {
            return 'gruen';
        }

        return $ist <= $ziel + $tol ? 'gelb' : 'rot';
    }

    /**
     * Foodbook-Gesamt: (Σ Top-Kapitel Per-Person × Pax) + Pauschal-Anteile. Erst HIER
     * wird die Gästezahl bindend (F-12, D-CON-5).
     *
     * @return array{vk_pro_person: float, ek_pro_person: float, pauschal: float, personen: ?int, gesamt_vk: ?float, gesamt_ek: ?float, food_cost_percent: ?float}
     */
    public function gesamt(Team $team, FoodAlchemistFoodbook $fb, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $pax = $fb->personen;
        $vk = 0.0;
        $ek = 0.0;
        $pauschal = 0.0;
        foreach ($fb->chapters()->whereNull('parent_id')->get() as $top) {
            $agg = $this->kapitelAggregat($team, $top, $pax, $outlet);
            $vk += $agg['vk_pro_person'];
            $ek += $agg['ek_per_person'];
            $pauschal += $agg['pauschal'];
        }

        $vkPp = round($vk, 2);
        $ekPp = round($ek, 2);

        return [
            'vk_pro_person' => $vkPp,
            'ek_per_person' => $ekPp,
            'pauschal' => round($pauschal, 2),
            'personen' => $pax,
            'gesamt_vk' => $pax !== null ? round($vk * $pax + $pauschal, 2) : null,
            'gesamt_ek' => $pax !== null ? round($ek * $pax, 2) : null,
            // Wareneinsatz % auf denselben gerundeten Per-Person-Werten wie die Anzeige (F-12),
            // damit die Zahl exakt der bisherigen Blade-Rechnung ek_pp / vk_pp * 100 entspricht.
            'food_cost_percent' => $vkPp > 0 ? round($ekPp / $vkPp * 100, 1) : null,
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
    /**
     * @param  list<int>  $kapitelFilter  #3: leer = alle Kapitel; sonst nur diese Kapitel-IDs. Ein
     *                                     selektiertes Kapitel, dessen Eltern rausgefiltert wurde,
     *                                     wird auf die oberste Ebene gehoben (sonst nie erreicht).
     * @param  bool  $mitKaskade  #3: zusätzlich den Produktions-Baum je Gericht anhängen (EK nur intern).
     */
    public function dokumentDaten(Team $team, FoodAlchemistFoodbook $fb, bool $intern = false, array $kapitelFilter = [], bool $mitKaskade = false, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $fb->loadMissing([
            'chapters' => fn ($q) => $q->orderBy('position'),
            'chapters.blocks' => fn ($q) => $q->where('visible', true)->orderBy('position'),
            // Wording-Kette: Slots (inkl. Paket-Gerichte) fürs Auflösen der Gericht-Zeilen
            'chapters.blocks.concept.slots.dish:id,name,sales_wording_standard',
            'chapters.blocks.concept.slots.package.dishes.dish:id,name,sales_wording_standard',
            // eingebettetes Paket (kind=paket-Concept) + dessen Gerichte für die Menü-Auflösung
            'chapters.blocks.concept.slots.embeddedConcept:id,name,consumer_name,price_per_person_cache',
            'chapters.blocks.concept.slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
            'chapters.blocks.concept.slots.embeddedConcept.slots.package.dishes.dish:id,name,sales_wording_standard',
            // E8.3: recipe_ref braucht sales_net/ek_total_eur für die €/Position-Preisspalte (blockPreis) — sonst rendert der Preis leer.
            'chapters.blocks.dish:id,name,sales_wording_standard,sales_net,ek_total_eur',
            // B (2026-08-31): LEBENDES Format-Kapitel — Identität + Editionen live aus dem Format. Editionen =
            // format_slots (type=concept) → Concept; die Wording-Kette der Editions-Concepte wie bei chapters.blocks.
            'chapters.format', 'chapters.format.images',
            'chapters.format.slots' => fn ($q) => $q->orderBy('position'),
            'chapters.format.slots.concept.slots.dish:id,name,sales_wording_standard',
            'chapters.format.slots.concept.slots.package.dishes.dish:id,name,sales_wording_standard',
            'chapters.format.slots.concept.slots.embeddedConcept:id,name,consumer_name,price_per_person_cache',
            'chapters.format.slots.concept.slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
            'chapters.format.slots.concept.slots.embeddedConcept.slots.package.dishes.dish:id,name,sales_wording_standard',
            'crmCompany', 'crmContact',
        ]);
        $pax = $fb->personen;

        // #3: Kapitel-Filter — nur ausgewählte Kapitel rendern; rausgefilterte Eltern hochziehen.
        $chapters = $fb->chapters;
        if ($kapitelFilter !== []) {
            $erlaubt = array_flip(array_map('intval', $kapitelFilter));
            $chapters = $chapters->filter(fn ($k) => isset($erlaubt[(int) $k->id]))->values();
            $vorhanden = array_flip($chapters->pluck('id')->map(fn ($v) => (int) $v)->all());
            $byParent = $chapters->groupBy(fn ($k) => ($k->parent_id !== null && isset($vorhanden[(int) $k->parent_id])) ? (int) $k->parent_id : 0);
        } else {
            $byParent = $chapters->groupBy(fn ($k) => $k->parent_id ?? 0);
        }
        $wording = app(WordingResolver::class);

        $rows = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$rows, $team, $pax, $wording, $intern, $outlet) {
            foreach ($byParent[$parentId] ?? [] as $k) {
                // B (2026-08-31): LEBENDES Format-Kapitel — Identität + Editionen LIVE aus dem Format
                // rendern (statt der Kapitel-Blöcke). Editionen = format_slots (type=concept) → Concept
                // (Wording-Kette wie sonst); header/text/spacer = Struktur-Editionen. Showcase:
                // vk_pro_person=null (Editionen sind Alternativen → Preis-Range, KEIN additiver Summand).
                if ($k->format_id !== null) {
                    $format = $k->format;
                    if ($format === null) {
                        // Reconciliation: Format weg (soft-deleted) → leerer Platzhalter, kein Fehler.
                        $rows[] = ['title' => $k->consumer_title ?: $k->title, 'title_intern' => $k->title,
                            'text' => trim((string) $k->description) ?: null, 'anker' => 'k' . $k->id, 'depth' => $depth,
                            'bloecke' => [], 'ist_format' => true, 'editionen' => [], 'vk_pro_person' => null];
                        $walk((int) $k->id, $depth + 1);

                        continue;
                    }
                    $editionen = [];
                    foreach ($format->slots as $fs) {
                        if ($fs->type === 'concept' && $fs->concept !== null) {
                            $ed = $fs->concept;
                            $editionen[] = [
                                'typ' => 'concept',
                                'name' => $ed->consumer_name ?: $ed->name,
                                'claim' => $ed->claim ?: null,
                                'text' => trim((string) $ed->description) ?: null,
                                'preis_pp' => $ed->price_per_person_cache !== null ? (float) $ed->price_per_person_cache : null,
                                'einzelpreise' => $ed->istEinzelpreis(),
                                'gerichte' => $wording->gerichtZeilen($ed),
                            ];
                        } elseif (in_array($fs->type, ['header', 'text', 'spacer'], true)) {
                            $editionen[] = ['typ' => $fs->type, 'name' => $fs->title, 'text' => $fs->text_content,
                                'claim' => null, 'preis_pp' => null, 'einzelpreise' => false, 'gerichte' => []];
                        }
                    }
                    $hero = $format->images->firstWhere('is_hero', true);
                    $rows[] = [
                        'title' => $k->consumer_title ?: ($format->consumer_name ?: $format->name),
                        'title_intern' => $k->title ?: $format->name,
                        'text' => trim((string) ($k->description ?: $format->story)) ?: null,
                        'anker' => 'k' . $k->id,
                        'depth' => $depth,
                        'bloecke' => [],
                        'ist_format' => true,
                        'claim' => $format->claim,
                        'hero' => $hero?->dataUri(),
                        'preis_range' => $format->priceRange(),
                        'editionen' => $editionen,
                        'vk_pro_person' => null,   // Showcase — Editionen sind Alternativen, nicht additiv
                    ];
                    $walk((int) $k->id, $depth + 1);

                    continue;
                }
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
                    $bp = $this->blockPreis($b, $pax, $outlet);
                    // E8.3: €/Gast vs. €/Position — Preis-Einheit typ-getrieben (spiegelt LeitstelleService::preiseBaum):
                    // concept_ref = Paket → pro Gast · recipe_ref = Einzelgericht → pro Position · sonst null (Header/Text).
                    $preisEinheit = match ($b->type) {
                        'concept_ref' => 'gast',
                        'recipe_ref' => 'position',
                        default => null,
                    };
                    $bloecke[] = ['type' => $b->type, 'label' => $label, 'untertitel' => $untertitel,
                        'gerichte' => $gerichte, 'ist_header' => str_starts_with((string) $b->type, 'header'),
                        'preis_pp' => (float) $bp['vk_pp'], 'pauschal' => (float) $bp['pauschal'],
                        'preis_einheit' => $preisEinheit,
                        // Preisdarstellung (2026-08-25): einzel-Concept → Concept-Summenpreis ausblenden,
                        // stattdessen zeigt jede Gericht-Zeile ihren eigenen Preis (aus gerichtZeilen).
                        'einzelpreise' => $b->type === 'concept_ref' && $b->concept !== null && $b->concept->istEinzelpreis(),
                        // #5b: Einzelgericht-Block trägt seine Rezept-ID → per-Gericht-Codes am Block-Label.
                        'recipe_id' => $b->type === 'recipe_ref' ? ($b->sales_recipe_id !== null ? (int) $b->sales_recipe_id : null) : null,
                        'codes' => []];
                }
                $agg = $this->kapitelAggregat($team, $k, $pax, $outlet);
                $row = [
                    'title' => $k->consumer_title ?: $k->title,
                    'title_intern' => $k->title,           // interner Titel für die Projektleitung-Sicht
                    // Spec 03 · L2b: die Kapitel-Hinführung. Das Feld existierte im Schema und war
                    // über MCP schreibbar, wurde aber von keiner Projektion gelesen — ein Kundentext,
                    // der nie beim Kunden ankam. Gleiche Rolle wie `fb->description` auf Buch-Ebene.
                    'text' => trim((string) $k->description) ?: null,
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

        // #5b: §-Kennzeichnung PRO GERICHT (Dominique 2026-08-25: nicht pro Konzept) — jede
        // Gericht-Zeile trägt ihre eigenen Allergen-/Zusatzstoff-Codes aus der Gericht-Deklaration;
        // die Legende sammelt nur, was auf den Gerichten tatsächlich vorkommt (LMIV/ZZulV).
        $kzAgg = app(\Platform\FoodAlchemist\Services\ConcepterAggregateService::class);
        $katalog = $kzAgg->kennzeichnungKatalog();
        // Alle Gericht-IDs: aus den concept_ref-Gericht-Zeilen UND den recipe_ref-Block-Labels.
        $recipeIds = collect($rows)->flatMap(function ($r) {
            $ausBloecke = collect($r['bloecke'])->flatMap(fn ($b) => collect($b['gerichte'])->pluck('recipe_id')->push($b['recipe_id'] ?? null));
            // B: Format-Editionen tragen ihre Gericht-Zeilen (LMIV-Codes je Gericht) ausserhalb der bloecke.
            $ausEditionen = collect($r['editionen'] ?? [])->flatMap(fn ($e) => collect($e['gerichte'])->pluck('recipe_id'));

            return $ausBloecke->concat($ausEditionen);
        })->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $usedAlg = [];
        $usedZus = [];
        if ($recipeIds !== []) {
            $dishes = FoodAlchemistRecipe::whereIn('id', $recipeIds)->get()->keyBy('id');
            // Normale Closure (kein arrow fn) — $usedAlg/$usedZus MÜSSEN by-ref laufen, damit die
            // Legende die real vorkommenden Codes sammelt (arrow fn würde sie by value kopieren).
            $codesFuer = function (?int $rid) use ($dishes, $kzAgg, $katalog, &$usedAlg, &$usedZus): array {
                return ($rid !== null && $dishes->get($rid) !== null)
                    ? $kzAgg->gerichtCodes($dishes->get($rid), $usedAlg, $usedZus, $katalog) : [];
            };
            foreach ($rows as $ri => $row) {
                foreach ($row['bloecke'] as $bi => $blk) {
                    // recipe_ref: Codes am Block-Label (Einzelgericht ist selbst ein Gericht).
                    $rows[$ri]['bloecke'][$bi]['codes'] = $codesFuer($blk['recipe_id'] ?? null);
                    // concept_ref: Codes je Gericht-Zeile.
                    foreach ($blk['gerichte'] as $gi => $g) {
                        $rows[$ri]['bloecke'][$bi]['gerichte'][$gi]['codes'] = $codesFuer(isset($g['recipe_id']) ? (int) $g['recipe_id'] : null);
                    }
                }
                // B: LEBENDES Format-Kapitel — Codes je Editions-Gericht (LMIV, pro Gericht).
                foreach ($row['editionen'] ?? [] as $ei => $ed) {
                    foreach ($ed['gerichte'] as $gi => $g) {
                        $rows[$ri]['editionen'][$ei]['gerichte'][$gi]['codes'] = $codesFuer(isset($g['recipe_id']) ? (int) $g['recipe_id'] : null);
                    }
                }
            }
        }
        $legende = $kzAgg->kennzeichnungLegende($usedAlg, $usedZus, $katalog);

        // #3: optionaler Produktions-Kaskaden-Anhang. Gericht-IDs aus den (gefilterten) Kapitel-
        // Blöcken: recipe_ref = direktes Gericht, concept_ref = Slot-/Paket-Gerichte. Je Gericht der
        // rekursive Baum aus ReportExportService (wiederverwendet report-recipe-node). EK nur intern
        // (ek/preise/lieferanten an $intern gebunden) → Kundensicht zeigt Struktur+Mengen ohne Kosten.
        $kaskaden = [];
        if ($mitKaskade) {
            $gerichtIds = [];
            foreach ($chapters as $k) {
                foreach ($k->blocks as $b) {
                    if ($b->type === 'recipe_ref' && $b->dish !== null) {
                        $gerichtIds[] = (int) $b->dish->id;
                    } elseif ($b->type === 'concept_ref' && $b->concept !== null) {
                        foreach ($b->concept->slots as $slot) {
                            if ($slot->dish !== null) {
                                $gerichtIds[] = (int) $slot->dish->id;
                            }
                            foreach ($slot->package?->dishes ?? [] as $pg) {
                                if ($pg->dish !== null) {
                                    $gerichtIds[] = (int) $pg->dish->id;
                                }
                            }
                        }
                    }
                }
            }
            $kOpt = [
                'stammdaten' => true, 'zutaten' => true, 'kaskade' => true,
                'steps' => false, 'sensorik' => false, 'produktion' => false, 'bilder' => false,
                'deklaration' => false, 'naehrwerte' => false, 'notizen' => false,
                'preise' => $intern, 'lieferanten' => $intern, 'ek' => $intern, 'intern' => $intern,
            ];
            $report = app(\Platform\FoodAlchemist\Services\ReportExportService::class);
            foreach (array_values(array_unique($gerichtIds)) as $gid) {
                try {
                    $d = $report->rezeptDaten($team, $gid, $kOpt);
                    $kaskaden[] = ['name' => $d['name'], 'recipe' => $d['recipe'], 'optionen' => $kOpt];
                } catch (\Throwable) {
                    // fail-soft: ein nicht ladbares Gericht kippt den Druck nicht
                }
            }
        }

        return [
            'fb' => $fb,
            'intern' => $intern,
            'kapitel' => $rows,
            'gesamt' => $this->gesamt($team, $fb, $outlet),
            // #5b: §-Kennzeichnungs-Legende (nur real vorkommende Allergene/Zusatzstoffe) — ganz unten im Dokument.
            'legende' => $legende,
            // CRM-only: Kontaktperson separat.
            'customer' => $fb->crmCompany?->display_name,
            'kontakt' => $fb->crmContact?->display_name,
            // Kundendokument-Vollständigkeit: gesetzlicher MwSt-Satz + Stand-Datum.
            'mwst' => app(TeamSettingsService::class)->mwst($team),
            'stand' => $fb->updated_at,
            // PDF-Redesign: pro-Foodbook-Marke (Farbe/Band/Logo/Cover/Footer), DomPDF-taugliche base64-Bilder.
            'branding' => $this->brandingDaten($fb),
            // #3: Produktions-Kaskaden-Anhang (leer, wenn nicht angefordert).
            'kaskaden' => $kaskaden,
            // #3: volle Kapitel-Liste (ungefiltert) + aktiver Filter — für den Kapitel-Picker im Dokument.
            'alle_kapitel' => $fb->chapters->map(fn ($k) => ['id' => (int) $k->id, 'title' => $k->consumer_title ?: $k->title])->values()->all(),
            'aktive_kapitel' => array_map('intval', $kapitelFilter),
        ];
    }

    /**
     * F3: schöne Einzel-Concept-„Karte" (Druck/PDF) — die fehlende hübsche Einzel-Ausgabe
     * des Concepters (bisher gab es dort nur den technischen Report). Foodbook-styled:
     * Konsumenten-Titel + Claim + Hinführung + die aufgelösten Menü-Zeilen (gleiche
     * Wording-Kette wie Foodbook/Format) + €/Gast. Klein gehalten: reine Menü-Sicht
     * (kein EK/Marge — das ist die Kunden-Ausgabe). Team-scoped über visibleToTeam.
     *
     * @return array{
     *     concept: FoodAlchemistConcept, titel: string, claim: ?string, text: ?string,
     *     preis_pp: ?float, gerichte: list<array<string, mixed>>, mwst: ?array, stand: mixed
     * }
     */
    public function conceptKarteDaten(Team $team, int $conceptId): array
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)
            ->with([
                'slots' => fn ($q) => $q->orderBy('position'),
                'slots.dish:id,name,sales_wording_standard',
                'slots.package.dishes.dish:id,name,sales_wording_standard',
                'slots.embeddedConcept:id,name,consumer_name,price_per_person_cache',
                'slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
                'slots.embeddedConcept.slots.package.dishes.dish:id,name,sales_wording_standard',
            ])
            ->findOrFail($conceptId);

        $wording = app(WordingResolver::class);

        return [
            'concept' => $concept,
            'titel' => $concept->consumer_name ?: $concept->name,
            'claim' => $concept->claim,
            'text' => trim((string) $concept->description) ?: null,
            'preis_pp' => $concept->price_per_person_cache !== null ? (float) $concept->price_per_person_cache : null,
            // Preisdarstellung (2026-08-25): einzel → Concept-Summenpreis ausblenden, Preise je Gericht-Zeile.
            'einzelpreise' => $concept->istEinzelpreis(),
            'gerichte' => $wording->gerichtZeilen($concept),
            'mwst' => app(TeamSettingsService::class)->mwst($team),
            'stand' => $concept->updated_at,
        ];
    }

    public function vorschauSnapshot(FoodAlchemistFoodbook $fb): ?array
    {
        $snapshot = $fb->preview_snapshot_json;
        if (! is_array($snapshot)) {
            return null;
        }

        $snapshot['snapshot_at'] = $fb->preview_snapshot_at;

        return $snapshot;
    }

    public function vorschauSnapshotAktualisieren(Team $team, int $foodbookId): array
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);

        $snapshot = $this->dokumentDaten($team, $fb);
        unset($snapshot['fb']);
        $snapshot['snapshot_version'] = 1;

        $fb->forceFill([
            'preview_snapshot_json' => $snapshot,
            'preview_snapshot_at' => now(),
        ])->save();

        return $snapshot;
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
    /**
     * UX 2026-07-25 (Dominique): der Concept-Picker filtert auf die Concepter-DIMENSIONEN
     * (Eventtyp/Servierform/Einsatzmoment/Saison) — nicht mehr auf Kategorie/Klasse (Konzept-Taxonomie
     * ausgemustert). Filter-Logik gespiegelt aus ConceptService::paginateBrowser (4c-Facetten).
     *
     * @param array{eventtyp?:?int, servierform?:?int, einsatzmoment?:?int, season?:?int} $facetten
     */
    public function conceptKandidaten(Team $team, string $suche, int $limit = 20, array $facetten = []): Collection
    {
        // #3: NUR Konzepte (kind=concept) — Pakete haben einen eigenen Picker-Reiter (paketKandidaten).
        return FoodAlchemistConcept::visibleToTeam($team)->echte()->konzepte()
            ->where('status', 'active') // Picker zeigt nur aktive (keine Entwürfe/archivierten; Status berücksichtigt)
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when(! empty($facetten['eventtyp']), fn ($q) => $q->where('event_type_id', (int) $facetten['eventtyp']))
            ->when(! empty($facetten['servierform']), fn ($q) => $q->where('serving_form_id', (int) $facetten['servierform']))
            ->when(! empty($facetten['einsatzmoment']), fn ($q) => $q->whereHas('serviceMoments', fn ($w) => $w->where('foodalchemist_service_moments.id', (int) $facetten['einsatzmoment'])))
            ->when(! empty($facetten['season']), fn ($q) => $q->whereHas('seasons', fn ($w) => $w->where('foodalchemist_seasons.id', (int) $facetten['season'])))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'price_per_person_cache', 'event_type_id', 'serving_form_id']);
    }

    /**
     * #3: Paket-Kandidaten (kind=paket-Concepts) für den Foodbook-Picker — eigener Reiter neben
     * Concept + Format. Dieselbe Filterkette wie {@see conceptKandidaten}; zeigt `consumer_name`
     * (Kundenname) statt des internen Namens („Neues Paket"). Ein Paket wird wie ein Concept als
     * concept_ref-Block gebucht (paket = kind=paket-Concept, concept_id trägt beide Arten).
     */
    public function paketKandidaten(Team $team, string $suche, int $limit = 20, array $facetten = []): Collection
    {
        return FoodAlchemistConcept::visibleToTeam($team)->echte()->pakete()
            ->where('status', 'active')
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::likeAny($q, ['name', "COALESCE(consumer_name, '')"], $suche))
            ->when(! empty($facetten['eventtyp']), fn ($q) => $q->where('event_type_id', (int) $facetten['eventtyp']))
            ->when(! empty($facetten['servierform']), fn ($q) => $q->where('serving_form_id', (int) $facetten['servierform']))
            ->when(! empty($facetten['einsatzmoment']), fn ($q) => $q->whereHas('serviceMoments', fn ($w) => $w->where('foodalchemist_service_moments.id', (int) $facetten['einsatzmoment'])))
            ->when(! empty($facetten['season']), fn ($q) => $q->whereHas('seasons', fn ($w) => $w->where('foodalchemist_seasons.id', (int) $facetten['season'])))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'consumer_name', 'price_per_person_cache', 'event_type_id', 'serving_form_id']);
    }

    /** Einzelne Gerichte (VK-Rezepte) für den recipe_ref-Picker. */
    public function gerichtKandidaten(Team $team, string $suche, int $limit = 20, ?int $hauptgruppe = null, ?int $dishClassId = null): Collection
    {
        // Modell A: HG = Kategorie (recipes.dish_main_group_id), Untergruppe = Diät-Klasse
        // (recipes.dish_class_id, z. B. „Vorspeise Vegan"). Beide Achsen filtern den Picker
        // (Browsen, wenn der Name unbekannt ist); dishClassId ist die feinere.
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id') // R4.4: Slot-Varianten sind konzept-lokal, nicht pickbar
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when($hauptgruppe !== null, fn ($q) => $q->where('dish_main_group_id', $hauptgruppe))
            ->when($dishClassId !== null, fn ($q) => $q->where('dish_class_id', $dishClassId))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'sales_net']);
    }

    // ── Format buchen (F5) — WIE EIN CONCEPT, kein Live-Format-Sonderweg ──────────

    /**
     * Format-Umbau F5: Formate für den „Format einfügen"-Picker (Kunden-IP-gefiltert).
     * Kunden-IP-Guard: ein fremdes Kunden-Format (origin=kunde, anderer Kunde als das
     * Foodbook) wird ausgeblendet, sobald der Foodbook-Kunde bekannt ist.
     */
    public function formatKandidaten(Team $team, FoodAlchemistFoodbook $fb, string $suche, int $limit = 20): Collection
    {
        $fbKunde = mb_strtolower(trim((string) ($fb->customer ?? '')));

        return \Platform\FoodAlchemist\Models\FoodAlchemistFormat::visibleToTeam($team)
            ->where('status', '!=', 'archiviert')
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->orderBy('name')->limit(50)
            ->get(['id', 'name', 'consumer_name', 'origin', 'customer', 'status'])
            ->reject(fn ($f) => $f->origin === 'kunde' && trim((string) $f->customer) !== ''
                && $fbKunde !== '' && mb_strtolower(trim((string) $f->customer)) !== $fbKunde)
            ->take($limit)->values();
    }

    /**
     * Format-Umbau F5: ein Format ins Foodbook buchen — WIE EIN CONCEPT, NICHT über den
     * entfernten Live-Format-Sonderweg (kein `format_id` am Kapitel, kein ist_format-Renderzweig).
     * Das Format wird eine SEKTION (Struktur-Kapitel, is_struktur — Titel/Kundentitel/Hinführung aus
     * dem Format, kein eigenes Food); JE KONZEPT entsteht ein Unterkapitel (Dominique 2026-08-27):
     *  - concept-Slot  → eigenes Unterkapitel (Titel = Konzept-Name) mit einem LIVE concept_ref-Block
     *  - header-Slot   → header_frei-Block auf der Sektion (Rahmentext)
     *  - text-Slot     → text-Block auf der Sektion
     *  - spacer-Slot   → spacer-Block auf der Sektion
     * „Snapshot" passiert erst beim Kunden-Versand (snapshot_json), nichts wird hier eingefroren.
     * Kunden-IP-Guard + Status-Guard (versendete/archivierte Bücher sind zu). Kein Recompute nötig
     * (die Kapitel-Aggregation läuft wie bei jedem anderen Kapitel über die Blöcke).
     */
    public function insertFormatAlsKapitel(Team $team, int $foodbookId, int $formatId, ?int $parentId = null): FoodAlchemistFoodbookKapitel
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        // Status ist auf AusgabeStatus gecastet → über statusWert()->value vergleichen ('versendet'
        // normalisiert auf 'aktiv', bleibt der Vollständigkeit halber gelistet).
        if (in_array($fb->statusWert()->value, ['versendet', 'archiviert'], true)) {
            throw new \RuntimeException('Foodbook ist ' . $fb->statusWert()->value . ' — kein Kapitel mehr einfügbar.');
        }
        if ($parentId !== null && ! FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->whereKey($parentId)->exists()) {
            throw new \RuntimeException('parent_id gehört nicht zu diesem Foodbook.');
        }
        $format = \Platform\FoodAlchemist\Models\FoodAlchemistFormat::visibleToTeam($team)
            ->with(['slots' => fn ($q) => $q->orderBy('position'), 'slots.concept:id,name,consumer_name'])
            ->findOrFail($formatId);

        // Kunden-IP: ein Kunden-Format nie in ein Buch eines ANDEREN Kunden (CLAUDE.md).
        if ($format->origin === 'kunde' && trim((string) $format->customer) !== '') {
            $fbKunde = trim((string) ($fb->customer ?? ''));
            if ($fbKunde !== '' && mb_strtolower($fbKunde) !== mb_strtolower(trim((string) $format->customer))) {
                throw new \RuntimeException('Kunden-IP: Format „' . $format->name . '" gehört ' . $format->customer
                    . ' — nicht in ein Buch von ' . $fbKunde . ' einfügbar.');
            }
        }

        return DB::transaction(function () use ($team, $fb, $format, $parentId) {
            // B (Dominique 2026-08-31): LEBENDES Format-Kapitel — die Sektion trägt `format_id` und rendert
            // Identität (Marken-Titel/Story) + Editionen + Struktur LIVE aus dem Format ({@see dokumentDaten}).
            // KEINE Einmal-Expansion in Unterkapitel/Blöcke mehr (das war C, 2026-08-27): Editionen rein/raus
            // im Format wirken jetzt sofort in ALLEN eingefügten Foodbooks durch. Der Versand-Snapshot friert
            // das Ergebnis (PresentationService::publish → dokumentDaten) beim Publish ohnehin ein.
            $sektion = $this->addKapitel($team, $fb->id, ['title' => $format->name], $parentId);
            $sektion->update([
                'format_id' => $format->id,                    // Live-Referenz auf das Format
                'consumer_title' => $format->consumer_name,    // Marketing-Titel (PDF); Fallback im Render
                'description' => $format->story,               // Sektions-Hinführung (Story); Fallback im Render
                'is_struktur' => true,                         // Sektion: kein eigenes additives Food
            ]);

            return $sektion->refresh();
        });
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
            'customer' => $fb->crmCompany?->display_name,
            'briefing' => $fb->description,
            'personen' => $fb->personen,
            'concepts' => $conceptNamen->unique()->values()->all(),
            'kapitel' => $fb->chapters->pluck('title')->values()->all(),
        ];
    }

    // ── Spec 03 · L2: KI-Kundentext ─────────────────────────────────────────────
    //
    // Löst den M11-08-Platzhalter oben ab: `kiAndockKontext` sammelte die Eingaben,
    // hier läuft der Call. Bewusst NUR Vorschlag — geschrieben wird nichts, auch nicht
    // `description`. Übernehmen ist ein eigener, menschlicher Akt in der Fläche
    // (Backup-Lehre 2026-06-30: ein Generator, der ein handgeschriebenes Kundentext-Feld
    // still überschreibt, vernichtet Arbeit, die nirgends versioniert ist).

    /**
     * Kundentext-Vorschlag für die Foodbook-Einleitung. Persistiert NICHTS außer der
     * Audit-Zeile des Gateways (GL-07 I3). Ohne gebundenen Provider wirft `propose()`
     * typisiert (KiNichtVerfuegbar/KiDeaktiviert) — hier bewusst NICHT geschluckt,
     * die Fläche formuliert die Meldung.
     *
     * @return array{text: string, confidence: ?float, call_log_id: ?int}
     */
    public function kiKundentextVorschlag(Team $team, int $foodbookId): array
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);                                    // D1: Schreiben/Erzeugen nur durchs Besitzer-Team

        // Workstream W (MCP-Steuerbarkeit D7): Kundentext an Cross-Cutting-Fakten erden (Anti-Marker
        // etc.) — Guardrail fürs kundensichtbare Wording. Wirkt Web + MCP (Parität).
        $wissen = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class)
            ->contextFor('foodbook.kundentext', (string) ($fb->label ?: 'Foodbook'));

        $proposal = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
            'foodbook.kundentext',
            $this->kundentextKontext($team, $fb),
            [
                'food_dna_foodbook_id' => (int) $fb->id,
                // Ebene 2 der DNA-Kette: der Endkunde des Foodbooks (Muster wie kiKundentext am Block)
                'food_dna_crm_company_id' => $fb->crm_company_id !== null ? (int) $fb->crm_company_id : null,
                'target_table' => 'foodalchemist_foodbooks',
                'target_id' => (int) $fb->id,
                'knowledge' => $wissen['block'] ?? null,
                'knowledge_used' => $wissen['files_used'] ?? null,
            ],
        );

        $text = trim((string) ($proposal->werte['text'] ?? ''));
        if ($text === '') {
            // Leere Antwort NICHT als Erfolg verkaufen — sonst zeigt die Vorschau ein leeres
            // Kästchen und „Übernehmen" würde das Feld leeren.
            throw new \RuntimeException('Die KI hat keinen Text geliefert — bitte erneut versuchen.');
        }

        return ['text' => $text, 'confidence' => $proposal->confidence, 'call_log_id' => $proposal->callLogId];
    }

    /**
     * Spec 03 · L2b — dasselbe für die **Kapitel**-Ebene (`foodbook_chapters.description`).
     * Eigener Einstieg, aber derselbe Prompt-Key: die Ebene steht im Kontext (`ebene`),
     * nicht im Key. Persistiert ebenfalls nichts — Übernehmen bleibt ein menschlicher Akt.
     *
     * @return array{text: string, confidence: ?float, call_log_id: ?int}
     */
    public function kiKapitelKundentextVorschlag(Team $team, int $kapitelId): array
    {
        $k = $this->ownedKapitel($team, $kapitelId);           // D1: Pflege nur durchs Besitzer-Team
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($k->foodbook_id);

        // Workstream W (D7): dieselbe Cross-Cutting-Erdung wie am Buch-Kundentext (geteilter Prompt-Key).
        $wissen = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class)
            ->contextFor('foodbook.kundentext', (string) ($k->title ?: $fb->label ?: 'Kapitel'));

        $proposal = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
            'foodbook.kundentext',
            $this->kapitelKundentextKontext($team, $fb, $k),
            [
                'food_dna_foodbook_id' => (int) $fb->id,
                'food_dna_crm_company_id' => $fb->crm_company_id !== null ? (int) $fb->crm_company_id : null,
                // Ziel ist das KAPITEL, nicht das Buch — die Audit-Zeile muss zeigen, welches
                // Feld der Vorschlag füllen soll (sonst sind n Kapitel-Calls nicht unterscheidbar).
                'target_table' => 'foodalchemist_foodbook_chapters',
                'target_id' => (int) $k->id,
                'knowledge' => $wissen['block'] ?? null,
                'knowledge_used' => $wissen['files_used'] ?? null,
            ],
        );

        $text = trim((string) ($proposal->werte['text'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Die KI hat keinen Text geliefert — bitte erneut versuchen.');
        }

        return ['text' => $text, 'confidence' => $proposal->confidence, 'call_log_id' => $proposal->callLogId];
    }

    /**
     * Kontext-Vertrag des Kundentexts: WAS im Angebot steht (Gliederung über die
     * Wording-Kette — dieselben Kunden-Labels wie im PDF, nicht die internen Namen)
     * + WIE es gerahmt ist (Leitplanken) + das Roh-Briefing als Umformungs-Vorlage.
     * Die Marken-/Schreibstil-Kette hängt `AiGatewayService::propose` selbst an.
     *
     * @return array<string, mixed>
     */
    private function kundentextKontext(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $voll = $this->detail($team, (int) $fb->id) ?? $fb;

        $gliederung = [];
        foreach ($voll->chapters as $k) {
            $gliederung[] = $this->kundentextKapitelZeile($k);
            if (count($gliederung) >= 20) {
                break;
            }
        }

        $briefing = trim((string) $voll->description);

        return [
            'ebene' => 'foodbook',
            'titel' => $voll->label,
            'kunde' => $voll->crmCompany?->display_name,
            'personen' => $voll->personen,
            'briefing_ist' => $briefing !== '' ? $briefing : null,
            'gliederung' => $gliederung,
            'leitplanken' => $this->leitplanken($team, $voll),
        ];
    }

    /**
     * Kontext-Vertrag der Kapitel-Ebene. Drei Unterschiede zur Buch-Ebene, jeder mit Grund:
     *  · Die `gliederung` ist auf DIESES Kapitel geschnitten (plus seine Unterkapitel — ein
     *    Eltern-Kapitel ist eine Klammer, sein Inhalt hängt darunter). Die Nachbar-Kapitel
     *    gehören nicht dazu: die Hinführung soll das Kapitel eröffnen, nicht das Buch.
     *  · `briefing_ist` ist der **Kapitel**-Text, falls schon einer steht (Umformen statt
     *    Neuschreiben, wie auf der Buch-Ebene). Das Buch-Briefing kommt getrennt als
     *    `rahmen_einleitung` mit — damit die Hinführung nicht die Einleitung wiederholt.
     *  · `leitplanken` werden MIT Kapitel aufgelöst (Zielgruppen-/Kreativ-Kaskade
     *    Kapitel → Foodbook), sonst schriebe die KI gegen den Buch-Default.
     *
     * @return array<string, mixed>
     */
    private function kapitelKundentextKontext(Team $team, FoodAlchemistFoodbook $fb, FoodAlchemistFoodbookKapitel $k): array
    {
        $gliederung = [$this->kundentextKapitelZeile($k)];
        foreach ($k->children()->limit(19)->get() as $kind) {
            $gliederung[] = $this->kundentextKapitelZeile($kind);
        }

        $kapitelText = trim((string) $k->description);
        $rahmen = trim((string) $fb->description);

        // Fix (a) / #2: der KAPITEL-Schreibstil-Override dominiert (falls gesetzt) — sonst hängt die
        // Cascade in propose() den Foodbook-/Team-Default an. So folgt die Kapitel-Hinführung dem
        // Kapitel-Stil, konsistent zum Kapitel-Wording. sprach_duktus, nicht nur der Name.
        $stil = $k->writingStyle;
        $stilKontext = $stil !== null ? array_filter([
            'schreibstil' => $stil->name,
            'schreibstil_anweisung' => trim((string) $stil->sprach_duktus) ?: null,
            'schreibstil_beispiele' => trim((string) $stil->beispiele_md) ?: null,
        ], fn ($v) => $v !== null) : [];

        return [
            'ebene' => 'kapitel',
            'titel' => trim((string) ($k->consumer_title ?: $k->title)),
            'foodbook_titel' => $fb->label,
            'kunde' => $fb->crmCompany?->display_name,
            'personen' => $fb->personen,
            'briefing_ist' => $kapitelText !== '' ? $kapitelText : null,
            'rahmen_einleitung' => $rahmen !== '' ? $rahmen : null,
            'gliederung' => $gliederung,
            'leitplanken' => $this->leitplanken($team, $fb, null, $k),
        ] + $stilKontext;
    }

    /**
     * Eine Kapitel-Zeile der KI-Gliederung: Kunden-Label des Kapitels + seine sichtbaren
     * Positionen über die Wording-Kette. Von beiden Ebenen geteilt, damit ein Kapitel in
     * der Buch- und in der Kapitel-Sicht dasselbe zeigt.
     *
     * @return array{kapitel: string, positionen: list<string>}
     */
    private function kundentextKapitelZeile(FoodAlchemistFoodbookKapitel $k): array
    {
        $positionen = [];
        foreach ($k->blocks as $b) {
            if (! $b->visible) {
                continue;                                            // Export-Filter gilt auch für die KI-Sicht
            }
            $t = trim((string) $this->dokBlockLabel($b));
            if ($t !== '') {
                $positionen[] = $t;
            }
        }

        return [
            'kapitel' => trim((string) ($k->consumer_title ?: $k->title)),
            // Deckel gegen Prompt-Aufblähung: ein 60-Positionen-Buffet-Kapitel braucht
            // die KI nicht vollständig, um den Bogen zu spannen.
            'positionen' => array_slice(array_values(array_unique($positionen)), 0, 12),
        ];
    }

    // ── Branding (pro Foodbook) ─────────────────────────────────────────────────
    //
    // UI-agnostische API: der Branding/CI-Tab im Cockpit (separate Session) UND MCP/Console
    // rufen dieselben Methoden. Owner-Guard wie überall (D1). Bilder laufen fuer neue Uploads
    // ueber Core ContextFiles (zentraler Disk/S3), alte public-Pfade bleiben lesbar.

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

        $contextSpalte = $this->brandingContextSpalte($spalte);
        $alt = (string) $fb->{$spalte};
        app(FoodAlchemistMediaService::class)->delete($fb->{$contextSpalte}, $alt, $team);

        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file,
            $team,
            'foodalchemist.foodbook',
            $foodbookId,
            "foodalchemist/branding/{$foodbookId}",
        );
        $pfad = $media['path'];
        $fb->update([
            $spalte => $pfad,
            $contextSpalte => $media['context_file_id'],
        ]);

        return $pfad;
    }

    private function loescheBrandingBild(Team $team, int $foodbookId, string $spalte): FoodAlchemistFoodbook
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);

        $contextSpalte = $this->brandingContextSpalte($spalte);
        $alt = (string) $fb->{$spalte};
        app(FoodAlchemistMediaService::class)->delete($fb->{$contextSpalte}, $alt, $team);
        $fb->update([
            $spalte => null,
            $contextSpalte => null,
        ]);

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
            'logo' => app(FoodAlchemistMediaService::class)->dataUri($fb->logo_context_file_id, $fb->logo_path),
            'cover' => app(FoodAlchemistMediaService::class)->dataUri($fb->cover_context_file_id, $fb->cover_image_path),
            'footer' => ($fb->footer_text ?? '') !== '' ? $fb->footer_text : null,
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
