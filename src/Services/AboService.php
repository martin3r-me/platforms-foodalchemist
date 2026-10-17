<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistAbo;
use Platform\FoodAlchemist\Support\AboSicht;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;

/**
 * Spec 82 §8 / §12 A · Kurator-Aufsatz, Durchstich Schritt 1: Abo = Sammlung des Master-Teams, live und nur
 * lesend beim Mandanten. Eigene Zugriffsregel (InhaltsFreigabeService::freigeben lässt nur Unter-Teams zu).
 *
 * Regeln:
 * - Abos vergibt nur das Master-Team, mit FA-Rolle „Freigeben" (§13 C2).
 * - Abonnent ist immer ein Kunden-Hauptteam, nie ein Unter-Team (§12 A2), nie der Master selbst.
 * - Ein Abo setzt ein gebuchtes Stamm-Paket voraus (Entscheidung 1 = C, 13.10.).
 * - Die Hülle enthält NUR veröffentlichte Fassungs-Zeilen (ist_fassung = 1) samt Unter-Rezepten — nie die
 *   Arbeitszeile des Masters mit ihren ek_* (Leck-Sperre, Bauplan §3b). Ohne Veröffentlichung (Schritt 2) ist
 *   sie leer.
 */
class AboService
{
    public function __construct(
        private FaRechte $rechte,
        private InhaltsFreigabeService $freigaben,
        private PlattformProtokoll $protokoll,
    ) {}

    public function abonnieren(Team $master, Team $mandant, int $sammlungId, ?User $actor): FoodAlchemistAbo
    {
        if (! MasterTeam::istMaster($master)) {
            throw new \RuntimeException('Abos vergibt nur das Master-Team.');
        }
        $this->rechte->pruefe($actor, $master, FaRolle::Freigeben, 'Abos vergeben');
        if ((int) $mandant->id === (int) $master->id) {
            throw new \RuntimeException('Das Master-Team kann sich nicht selbst abonnieren.');
        }
        if ((int) $this->rechte->kundenHauptTeam($mandant)->id !== (int) $mandant->id) {
            throw new \RuntimeException('Ein Abo hat immer das Oberteam (Kunden-Hauptteam), nie ein Unter-Team (Spec 82 §12 A2).');
        }
        if (! StammPaket::istFreigeschaltet($mandant)) {
            throw new \RuntimeException('Ein Abo setzt ein gebuchtes Stamm-Paket voraus (Spec 82, Entscheidung 1 = C).');
        }
        $sammlung = DB::table('foodalchemist_sammlungen')->where('id', $sammlungId)->whereNull('deleted_at')->first();
        if ($sammlung === null || (int) $sammlung->team_id !== (int) $master->id) {
            throw new \RuntimeException('Abonnieren lassen sich nur Sammlungen des Master-Teams.');
        }

        // D8 (Kunden-IP): eine Sammlung mit einem Kunden-Format (origin=kunde) lässt sich nie abonnieren
        $kundenFormate = DB::table('foodalchemist_sammlung_objekte AS o')->join('foodalchemist_formats AS f', 'f.id', '=', 'o.objekt_id')
            ->where('o.sammlung_id', $sammlungId)->where('o.typ', 'format')->where('f.origin', 'kunde')->whereNull('f.deleted_at')->pluck('f.name')->all();
        if ($kundenFormate !== []) {
            throw new \RuntimeException('Kunden-Formate sind nie abonnierbar (Kunden-IP): '.implode(', ', array_slice($kundenFormate, 0, 5)).'. Bitte aus der Sammlung nehmen.');
        }

        $abo = DB::transaction(function () use ($master, $mandant, $sammlungId, $actor) {
            $abo = FoodAlchemistAbo::firstOrNew(['mandant_team_id' => $mandant->id, 'sammlung_id' => $sammlungId]);
            $abo->forceFill([
                'master_team_id' => $master->id,
                'status' => FoodAlchemistAbo::AKTIV,
                'seit' => $abo->status === FoodAlchemistAbo::AKTIV && $abo->seit ? $abo->seit : now(),
                'beendet_at' => null, 'beendet_von' => null,
                'angelegt_von' => $abo->exists ? $abo->angelegt_von : $actor?->id,
            ])->save();

            return $abo;
        });
        $this->neuBerechnen((int) $mandant->id);
        $this->protokoll->schreibe('abo_vergeben', ['master_team_id' => $master->id, 'mandant_team_id' => $mandant->id,
            'sammlung_id' => $sammlungId], $actor, $abo);   // §13 C4: ins echte activity-log, am Abo selbst

        return $abo->refresh();
    }

    /**
     * Abos aus Sicht eines Teams: das Master-Team sieht alle seine, jedes andere Team die seines Kunden-Hauptteams.
     *
     * @return Collection<int, array{id:int, master_team_id:int, mandant_team_id:int, sammlung_id:int, sammlung:string, status:string, seit:?string}>
     */
    public function abos(Team $team, bool $nurAktiv = true): Collection
    {
        $q = DB::table('foodalchemist_abos AS a')
            ->leftJoin('foodalchemist_sammlungen AS s', 's.id', '=', 'a.sammlung_id')
            ->when($nurAktiv, fn ($w) => $w->where('a.status', FoodAlchemistAbo::AKTIV))
            ->orderBy('a.mandant_team_id')->orderBy('a.id')
            ->select('a.*', 's.name AS sammlung_name');
        MasterTeam::istMaster($team)
            ? $q->where('a.master_team_id', $team->id)
            : $q->where('a.mandant_team_id', $this->rechte->kundenHauptTeam($team)->id);

        return $q->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'master_team_id' => (int) $r->master_team_id, 'mandant_team_id' => (int) $r->mandant_team_id,
            'sammlung_id' => (int) $r->sammlung_id, 'sammlung' => (string) ($r->sammlung_name ?? '—'),
            'status' => (string) $r->status, 'seit' => $r->seit,
        ]);
    }

    /** Hülle aller Mandanten neu rechnen (z. B. nach Veröffentlichung, Schritt 2). */
    public function neuBerechnenAlle(): void
    {
        $mandanten = DB::table('foodalchemist_abos')->where('status', FoodAlchemistAbo::AKTIV)->distinct()->pluck('mandant_team_id');
        foreach ($mandanten as $id) {
            $this->neuBerechnen((int) $id);
        }
        DB::table('foodalchemist_abo_objekte')->whereNotIn('mandant_team_id', $mandanten->all() ?: [0])->delete();
        AboSicht::vergessen();
    }

    /**
     * Spec 84 B3 Regel B · Nach einer Verweis-Änderung (Folge, Übernehmen) die Hülle des Mandanten neu rechnen, damit eine nicht mehr
     * referenzierte ältere Fassung wieder herausfällt. Nur, wenn der Mandant Abos hat (sonst keine Abfrage-Last).
     */
    public function huelleNachfuehren(\Platform\Core\Models\Team|int $team): void
    {
        $team = $team instanceof \Platform\Core\Models\Team ? $team : \Platform\Core\Models\Team::find($team);
        if ($team === null) {
            return;
        }
        $mandant = (int) app(FaRechte::class)->hauptTeam($team)->id;
        if (DB::table('foodalchemist_abos')->where('mandant_team_id', $mandant)->where('status', FoodAlchemistAbo::AKTIV)->exists()) {
            $this->neuBerechnen($mandant);
        }
    }

    /** @return array<string, list<int>> gespeicherte Hülle des Mandanten */
    public function neuBerechnen(int $mandantTeamId): array
    {
        $huelle = $this->huelle($mandantTeamId);
        DB::transaction(function () use ($mandantTeamId, $huelle) {
            DB::table('foodalchemist_abo_objekte')->where('mandant_team_id', $mandantTeamId)->delete();
            $zeilen = [];
            foreach ($huelle as $typ => $ids) {
                foreach ($ids as $id) {
                    $zeilen[] = ['mandant_team_id' => $mandantTeamId, 'typ' => $typ, 'objekt_id' => $id];
                }
            }
            foreach (array_chunk($zeilen, 500) as $teil) {
                DB::table('foodalchemist_abo_objekte')->insert($teil);
            }
        });
        AboSicht::vergessen();

        return $huelle;
    }

    /**
     * Hülle eines Mandanten: für jedes Rezept aus seinen aktiven Abo-Sammlungen die NEUESTE veröffentlichte Fassung,
     * dazu deren Unter-Rezepte (Fassungs-Zeilen zeigen auf Fassungs-Zeilen, Schritt 2). Alles, was keine Fassung ist,
     * fällt am Ende heraus — auch wenn ein Unter-Rezept-Verweis versehentlich auf eine Arbeitszeile zeigt.
     *
     * @return array<string, list<int>>
     */
    public function huelle(int $mandantTeamId, ?int $ohneAboId = null, ?array $ohneSammlungsObjekt = null, ?int $nurAboId = null): array
    {
        // „Was bliebe ohne dieses Abo bzw. ohne dieses Sammlungs-Objekt (['sammlung_id','objekt_id'])?" — Kündigung/Zurückziehen, §12 D
        $familienJe = function (string $typ) use ($mandantTeamId, $ohneAboId, $ohneSammlungsObjekt, $nurAboId): array {
            return DB::table('foodalchemist_sammlung_objekte AS o')
                ->join('foodalchemist_abos AS a', 'a.sammlung_id', '=', 'o.sammlung_id')
                ->join('foodalchemist_sammlungen AS s', 's.id', '=', 'o.sammlung_id')
                ->where('a.mandant_team_id', $mandantTeamId)->where('a.status', FoodAlchemistAbo::AKTIV)
                ->when($ohneAboId !== null, fn ($q) => $q->where('a.id', '!=', $ohneAboId))
                ->when($nurAboId !== null, fn ($q) => $q->where('a.id', $nurAboId))   // PR 6b: die Hülle EINES Abos („bleibt über Abo X“)
                ->when($ohneSammlungsObjekt !== null && $typ === ($ohneSammlungsObjekt['typ'] ?? 'recipe'), fn ($q) => $q->whereNot(fn ($w) => $w->where('o.sammlung_id', $ohneSammlungsObjekt['sammlung_id'])
                    ->where('o.objekt_id', $ohneSammlungsObjekt['objekt_id'])))
                ->whereNull('s.deleted_at')->where('o.typ', $typ)
                ->distinct()->pluck('o.objekt_id')->map(fn ($v) => (int) $v)->all();
        };
        $familien = $familienJe('recipe');
        $paketFamilien = $familienJe('paket');
        $konzeptFamilien = $familienJe('concept');
        // Formate (PR 7): Kunden-Formate (origin=kunde) sind NIE abonnierbar (D8, Kunden-IP) — auch dann nicht, wenn sie in einer Sammlung stehen
        $formatFamilien = $familienJe('format');
        if ($formatFamilien !== []) {
            $formatFamilien = DB::table('foodalchemist_formats')->whereIn('id', $formatFamilien)->where(fn ($w) => $w->whereNull('origin')->orWhere('origin', '!=', 'kunde'))
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
        }
        if ($familien === [] && $paketFamilien === [] && $konzeptFamilien === [] && $formatFamilien === []) {
            return [];
        }

        $leer = ['recipe' => [], 'paket' => [], 'concept' => [], 'format' => []];
        $erst = $this->huelleAus($familien, $paketFamilien, $konzeptFamilien, $formatFamilien, $leer);
        // Spec 84 B3 Regel B (Dominique 17.10.): dazu jede ÄLTERE Fassung der abonnierten Familien, auf die noch etwas Eigenes des Mandanten
        // verweist (Rezept, Konzept, Paket, Angebot, Foodbook, Speiseplan, Speisekarte, Produktion, Bestellvorlage, Lager, Darreichung).
        // Als zusätzliche Saat in denselben Baum-Gang — so ziehen auch die alten Unter-Fassungen einer alten Fassung mit (Jus F1 → Fond F1).
        $saat = $this->referenzierteAlteFassungen($mandantTeamId, $erst, [
            'recipe' => $familien, 'paket' => $paketFamilien, 'concept' => $konzeptFamilien, 'format' => $formatFamilien,
        ]);
        if ($saat === $leer) {
            return $erst;
        }

        return $this->huelleAus($familien, $paketFamilien, $konzeptFamilien, $formatFamilien, $saat);
    }

    /**
     * Hülle aus den abonnierten Familien (jüngste Fassung je Familie samt Baum) plus `$saat` (zusätzliche Fassungs-IDs je Art, Regel B).
     *
     * @param  list<int>  $familien
     * @param  list<int>  $paketFamilien
     * @param  list<int>  $konzeptFamilien
     * @param  list<int>  $formatFamilien
     * @param  array{recipe: list<int>, paket: list<int>, concept: list<int>, format: list<int>}  $saat
     * @return array<string, list<int>>
     */
    private function huelleAus(array $familien, array $paketFamilien, array $konzeptFamilien, array $formatFamilien, array $saat): array
    {
        // Konzepte (PR 4, auch kind=paket): je Familie die jüngste Fassung; ihre Slots zeigen auf Fassungen von Paket, eingebettetem
        // Paket-Konzept (rekursiv) und Rezept — alle ziehen in die Hülle mit
        $konzeptFassungen = array_merge($this->neuesteFassungenVon('foodalchemist_concepts', $konzeptFamilien), $saat['concept']);
        $paketFassungen = array_merge($this->neuesteFassungenVon('foodalchemist_packages', $paketFamilien), $saat['paket']);
        $formatFassungen = $this->nurFassungen('foodalchemist_formats', array_merge($this->neuesteFassungenVon('foodalchemist_formats', $formatFamilien), $saat['format']));
        $rezeptSeeds = [];
        // Format-Slots zeigen auf Konzept-Fassungen (und über diese auf Pakete/Rezepte) — sie ziehen in die Konzept-Hülle mit
        if ($formatFassungen !== []) {
            $konzeptFassungen = array_merge($konzeptFassungen, DB::table('foodalchemist_format_slots')->whereIn('format_id', $formatFassungen)->whereNull('deleted_at')
                ->whereNotNull('concept_id')->pluck('concept_id')->map(fn ($v) => (int) $v)->all());
        }
        $offenK = $konzeptFassungen;
        $gesehenK = [];
        while ($offenK !== []) {
            $offenK = array_values(array_diff(array_unique($offenK), $gesehenK));
            if ($offenK === []) {
                break;
            }
            $gesehenK = array_merge($gesehenK, $offenK);
            $slots = DB::table('foodalchemist_concept_slots')->whereIn('concept_id', $offenK)->whereNull('deleted_at')
                ->get(['package_id', 'embedded_concept_id', 'sales_recipe_id']);
            $paketFassungen = array_merge($paketFassungen, $slots->pluck('package_id')->filter()->map(fn ($v) => (int) $v)->all());
            $rezeptSeeds = array_merge($rezeptSeeds, $slots->pluck('sales_recipe_id')->filter()->map(fn ($v) => (int) $v)->all());
            $offenK = $slots->pluck('embedded_concept_id')->filter()->map(fn ($v) => (int) $v)->all();
        }
        $konzeptFassungen = $this->nurFassungen('foodalchemist_concepts', $gesehenK);
        $paketFassungen = $this->nurFassungen('foodalchemist_packages', $paketFassungen);
        // Pakete: ihre Gerichte zeigen auf Rezept-Fassungen und ziehen in die Rezept-Hülle mit
        $paketRezepte = $paketFassungen === [] ? [] : DB::table('foodalchemist_package_dishes')->whereIn('package_id', $paketFassungen)
            ->whereNull('deleted_at')->pluck('sales_recipe_id')->map(fn ($v) => (int) $v)->all();

        $offen = array_merge($this->neuesteFassungen($familien), $saat['recipe'], $rezeptSeeds, $paketRezepte);
        $fertig = [];
        while ($offen !== []) {
            $offen = array_values(array_diff(array_unique($offen), $fertig));
            if ($offen === []) {
                break;
            }
            $fertig = array_merge($fertig, $offen);
            $offen = $this->freigaben->abhaengigkeiten('recipe', $offen)['recipe'] ?? [];
        }

        $nurFassungen = DB::table('foodalchemist_recipes')->whereIn('id', $fertig ?: [0])
            ->where('ist_fassung', true)->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();

        $huelle = [];
        if ($nurFassungen !== []) {
            $huelle['recipe'] = $nurFassungen;
        }
        if ($paketFassungen !== []) {
            $huelle['paket'] = $paketFassungen;
        }
        if ($konzeptFassungen !== []) {
            $huelle['concept'] = $konzeptFassungen;
        }
        if ($formatFassungen !== []) {
            $huelle['format'] = $formatFassungen;
        }

        return $huelle;
    }

    /**
     * Spec 84 B3 Regel B · Ältere Fassungen, auf die noch etwas Eigenes des Mandanten verweist — je Art (recipe | paket | concept | format).
     *
     * - Kandidaten: nicht gelöschte Fassungen der Familien, die in der Hülle stehen (abonniert oder über den Baum erreicht), die noch NICHT in
     *   der Hülle sind (also ältere). Nur gegen diese wenigen IDs wird in den Quellen gesucht (Index auf den Verweis-Spalten).
     * - „Eigenes des Mandanten“ = Team ∈ Kunden-Hauptteam + Nachfahren (die Hülle gehört dem ganzen Mandanten, Kurator 17.10.).
     *   Kind-Tabellen nehmen das Team immer vom Eltern (recipe_ingredients → recipes, concept_slots → concepts, …), Kopf-Tabellen ihr eigenes.
     * - Nicht gezählt: Historie und Protokolle (angebot_uebernahmen, sales_facts, signals …), Attribute der Fassung selbst, Herkunfts-Marker.
     *   Der eingefrorene Angebots-JSON-Stand ebenfalls nicht: das Gericht eines eingefrorenen Angebots folgt nicht und behält seinen Verweis.
     *
     * @param  array<string, list<int>>  $huelle  Hülle aus dem ersten Gang
     * @param  array<string, list<int>>  $familien  abonnierte Familien je Art
     * @return array{recipe: list<int>, paket: list<int>, concept: list<int>, format: list<int>}
     */
    private function referenzierteAlteFassungen(int $mandantTeamId, array $huelle, array $familien): array
    {
        $tabellen = ['recipe' => 'foodalchemist_recipes', 'paket' => 'foodalchemist_packages', 'concept' => 'foodalchemist_concepts', 'format' => 'foodalchemist_formats'];
        $kandidaten = [];
        foreach ($tabellen as $art => $tabelle) {
            $drin = $huelle[$art] ?? [];
            $fam = array_values(array_unique(array_merge($familien[$art] ?? [], $drin === [] ? [] : DB::table($tabelle)->whereIn('id', $drin)->pluck('fassung_von_id')->filter()->map(fn ($v) => (int) $v)->all())));
            $kandidaten[$art] = $fam === [] ? [] : DB::table($tabelle)->whereIn('fassung_von_id', $fam)->where('ist_fassung', true)->whereNull('deleted_at')
                ->when($drin !== [], fn ($q) => $q->whereNotIn('id', $drin))->pluck('id')->map(fn ($v) => (int) $v)->all();
        }
        $saat = ['recipe' => [], 'paket' => [], 'concept' => [], 'format' => []];
        if ($kandidaten === $saat) {
            return $saat;
        }
        $teams = \Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob::teamUndNachfahren($mandantTeamId);

        foreach (self::REFERENZ_QUELLEN as $art => $quellen) {
            if ($kandidaten[$art] === []) {
                continue;
            }
            $treffer = [];
            foreach ($quellen as [$tabelle, $spalte, $eltern]) {
                $treffer = array_merge($treffer, $this->verweiseAus($tabelle, $spalte, $eltern, $kandidaten[$art], $teams));
            }
            // Darreichung: Verweis auf eine Darreichung der Fassung (presentation_id) hält das Rezept selbst
            if ($art === 'recipe') {
                $darreichungen = DB::table('foodalchemist_recipe_presentations')->whereIn('recipe_id', $kandidaten['recipe'])->whereNull('deleted_at')->pluck('recipe_id', 'id')->all();
                if ($darreichungen !== []) {
                    foreach (self::DARREICHUNGS_QUELLEN as [$tabelle, $eltern]) {
                        foreach ($this->verweiseAus($tabelle, 'presentation_id', $eltern, array_map('intval', array_keys($darreichungen)), $teams) as $did) {
                            $treffer[] = (int) $darreichungen[$did];
                        }
                    }
                }
            }
            $saat[$art] = array_values(array_unique(array_intersect($kandidaten[$art], $treffer)));
        }

        return $saat;
    }

    /**
     * Quellen je Art: [Tabelle, Verweis-Spalte, Eltern] — Eltern = [Eltern-Tabelle, FK-Spalte] (Team vom Eltern) oder null (eigene team_id).
     *
     * @var array<string, list<array{0: string, 1: string, 2: ?array{0: string, 1: string}}>>
     */
    private const REFERENZ_QUELLEN = [
        'recipe' => [
            ['foodalchemist_recipe_ingredients', 'referenced_recipe_id', ['foodalchemist_recipes', 'recipe_id']],
            ['foodalchemist_concept_slots', 'sales_recipe_id', ['foodalchemist_concepts', 'concept_id']],
            ['foodalchemist_concept_slots', 'variant_source_recipe_id', ['foodalchemist_concepts', 'concept_id']],
            ['foodalchemist_package_dishes', 'sales_recipe_id', ['foodalchemist_packages', 'package_id']],
            ['foodalchemist_offer_blocks', 'sales_recipe_id', ['foodalchemist_offer_chapters', 'chapter_id']],
            ['foodalchemist_foodbook_blocks', 'sales_recipe_id', ['foodalchemist_foodbook_chapters', 'chapter_id']],
            ['foodalchemist_menu_plan_entries', 'sales_recipe_id', null],
            ['foodalchemist_menu_card_items', 'sales_recipe_id', null],
            ['foodalchemist_production_order_lines', 'recipe_id', null],
            ['foodalchemist_order_template_lines', 'recipe_id', null],
            ['foodalchemist_inventory_batches', 'recipe_id', null],
            ['foodalchemist_inventory_stocks', 'recipe_id', null],
            ['foodalchemist_inventory_movements', 'recipe_id', null],
            ['foodalchemist_inventory_count_lines', 'recipe_id', null],
        ],
        'paket' => [
            ['foodalchemist_concept_slots', 'package_id', ['foodalchemist_concepts', 'concept_id']],
            ['foodalchemist_menu_plan_entries', 'package_id', null],
            ['foodalchemist_order_template_lines', 'paket_id', null],
        ],
        'concept' => [
            ['foodalchemist_concept_slots', 'embedded_concept_id', ['foodalchemist_concepts', 'concept_id']],
            ['foodalchemist_format_slots', 'concept_id', ['foodalchemist_formats', 'format_id']],
            ['foodalchemist_offer_blocks', 'concept_id', ['foodalchemist_offer_chapters', 'chapter_id']],
            ['foodalchemist_foodbook_blocks', 'concept_id', ['foodalchemist_foodbook_chapters', 'chapter_id']],
            ['foodalchemist_offer_concept', 'concept_id', null],
            ['foodalchemist_menu_plan_entries', 'concept_id', null],
            ['foodalchemist_menu_card_items', 'concept_id', null],
            ['foodalchemist_order_template_lines', 'concept_id', null],
        ],
        'format' => [
            ['foodalchemist_concepts', 'format_id', null],
            ['foodalchemist_foodbook_chapters', 'format_id', null],
            ['foodalchemist_offer_chapters', 'format_id', null],
            ['foodalchemist_menu_card_sections', 'format_id', null],
        ],
    ];

    /** Darreichungs-Verweise (presentation_id → recipe_presentations.recipe_id): [Tabelle, Eltern]. */
    private const DARREICHUNGS_QUELLEN = [
        ['foodalchemist_offer_blocks', ['foodalchemist_offer_chapters', 'chapter_id']],
        ['foodalchemist_foodbook_blocks', ['foodalchemist_foodbook_chapters', 'chapter_id']],
        ['foodalchemist_concept_slots', ['foodalchemist_concepts', 'concept_id']],
        ['foodalchemist_menu_plan_entries', null],
        ['foodalchemist_menu_card_items', null],
    ];

    /** @var array<string, bool> Schema-Merker (Spalte vorhanden?) je „tabelle.spalte“ */
    private array $spalteDa = [];

    /**
     * IDs aus `$ids`, auf die `$tabelle.$spalte` von einer Zeile des Mandanten verweist (nicht gelöscht; Team vom Eltern oder eigen).
     *
     * @param  ?array{0: string, 1: string}  $eltern
     * @param  list<int>  $ids
     * @param  list<int>  $teams
     * @return list<int>
     */
    private function verweiseAus(string $tabelle, string $spalte, ?array $eltern, array $ids, array $teams): array
    {
        if (! $this->hatSpalte($tabelle, $spalte)) {
            return [];
        }
        $q = DB::table($tabelle.' AS q')->whereIn('q.'.$spalte, $ids);
        if ($this->hatSpalte($tabelle, 'deleted_at')) {
            $q->whereNull('q.deleted_at');
        }
        if ($eltern !== null) {
            [$et, $fk] = $eltern;
            $q->join($et.' AS e', 'e.id', '=', 'q.'.$fk)->whereIn('e.team_id', $teams);
            if ($this->hatSpalte($et, 'deleted_at')) {
                $q->whereNull('e.deleted_at');
            }
        } else {
            $q->whereIn('q.team_id', $teams);
        }

        return $q->distinct()->pluck('q.'.$spalte)->map(fn ($v) => (int) $v)->all();
    }

    private function hatSpalte(string $tabelle, string $spalte): bool
    {
        return $this->spalteDa[$tabelle.'.'.$spalte] ??= \Illuminate\Support\Facades\Schema::hasColumn($tabelle, $spalte);
    }

    /**
     * Je Familie (Arbeitszeile) die jüngste nicht gelöschte Fassungs-Zeile der Tabelle (concepts | packages).
     *
     * @param  list<int>  $familien
     * @return list<int>
     */
    private function neuesteFassungenVon(string $tabelle, array $familien): array
    {
        if ($familien === []) {
            return [];
        }

        return DB::table($tabelle)->whereIn('fassung_von_id', $familien)
            ->where('ist_fassung', true)->whereNull('deleted_at')
            ->groupBy('fassung_von_id')->selectRaw('MAX(id) AS id')
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Alles, was keine Fassung ist, fällt heraus — auch wenn ein Verweis versehentlich auf eine Arbeitszeile zeigt (Leck-Sperre).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function nurFassungen(string $tabelle, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($tabelle)->whereIn('id', array_unique($ids))->where('ist_fassung', true)->whereNull('deleted_at')
            ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Je Rezept-Familie (Arbeitszeile) die jüngste nicht gelöschte Fassungs-Zeile.
     *
     * @param  list<int>  $familien
     * @return list<int>
     */
    private function neuesteFassungen(array $familien): array
    {
        return DB::table('foodalchemist_recipes')->whereIn('fassung_von_id', $familien)
            ->where('ist_fassung', true)->whereNull('deleted_at')
            ->groupBy('fassung_von_id')->selectRaw('MAX(id) AS id')
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }
}
