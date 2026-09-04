<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * M6-03 / D-6 §3.1: VK-Sicht aufs geteilte Rezept-Modell — erzwingt den
 * `verkauf()`-Scope in JEDER Query (Scope-Härte §7.8). Aggregate kommen aus
 * der D-5-Recompute-Pipeline; VK-Mathematik ausschließlich via MargeService.
 * Suche greift auch über Marketing-Namen + Kunden-Wordings (§4.1).
 */
class SalesRecipeService
{
    public function __construct(
        private MargeService $marge,
        private CatalogPricingService $catalogPricing,
    )
    {
    }

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return $this->browserQuery($team, $filters)
            // #6 N+1-Fix: kanonische Relation-Namen — die Browser-Blade liest `dishClass`/`dishMainGroup`.
            // Vorher lief das Eager-Load unter den deprecated Aliassen `speisenKlasse`/`speisenHauptgruppe`,
            // sodass `relationLoaded('dishClass')` false war → 1 Lazy-Query pro Zeile (bei perPage bis 500).
            ->with(['dishClass:id,label,diet_form', 'dishMainGroup:id,code,label'])
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Ebene 2 — betriebsscharfe VK je Rezept für die Listen-Anzeige. Der Unternehmens-Basissatz
     * wird EINMAL aufgelöst (enterpriseBaseRate) und als `preBase` je Zeile in salesNetFor
     * gereicht — keine N× Basissatz-Rechnung über die Seite. Ohne Betrieb leer (die Liste fällt
     * auf die Baseline `sales_net` zurück). Rezepte ohne Darreichung (kein on-the-fly-VK möglich)
     * bleiben ausgespart und zeigen weiter die Baseline.
     *
     * @param  \Illuminate\Support\Collection<int, FoodAlchemistRecipe>  $recipes
     * @return array<int, float>  recipe_id → VK netto im Betrieb
     */
    public function outletVkMap(Team $team, Collection $recipes, ?FoodAlchemistOutlet $outlet): array
    {
        if ($outlet === null || $recipes->isEmpty()) {
            return [];
        }
        $ids = $recipes->pluck('id')->all();
        $preBase = $this->catalogPricing->enterpriseBaseRate($team, $outlet);
        // Standard-Darreichung zuerst, sonst die erste — spiegelt den Fallback des DarreichungResolvers.
        $darr = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::whereIn('recipe_id', $ids)
            ->orderByDesc('is_standard')->orderBy('id')->get()->groupBy('recipe_id');

        $map = [];
        foreach ($recipes as $r) {
            $d = $darr->get($r->id)?->first();
            if ($d === null) {
                continue;
            }
            $vk = $this->catalogPricing->salesNetFor($team, $d, $outlet, $preBase);
            if ($vk !== null) {
                $map[(int) $r->id] = round((float) $vk, 2);
            }
        }

        return $map;
    }

    /**
     * DER Filtervertrag der VK-Sicht — eine Stelle für Tabelle UND Facetten (MVP-048).
     *
     * Vorher lebte der Filtersatz nur in `paginateBrowser()`; die Facetten-Zähler bauten ihre
     * eigenen Queries und kannten Suche, Status und Geschmack gar nicht. Ergebnis: `?class=61&hg=10`
     * versprach Treffer und lieferte 0. Zähler und Zielmenge müssen aus derselben Quelle kommen,
     * sonst laufen sie beim nächsten neuen Filter sofort wieder auseinander.
     */
    private function browserQuery(Team $team, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        // Modell A (Regelwerk_Verkaufsgerichte v1.1): HG = Kategorie (recipes.dish_main_group_id),
        // Klasse = Diätform (recipes.dish_class_id) — beide Achsen unabhängig filterbar.
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id') // R4.4: konzept-lokale Slot-Varianten bleiben aus dem Katalog
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                // Multi-Wort: jedes Token muss treffen (Name / Standard-Wording / Marketing /
                // Kunden-Wording). §4.1 — Treffer dürfen über die Felder verteilt sein.
                foreach (\Platform\FoodAlchemist\Support\Suche::tokens($filters['search']) as $token) {
                    $t = '%' . $token . '%';
                    $q->where(fn ($w) => $w
                        ->whereRaw('LOWER(name) LIKE ?', [$t])
                        ->orWhereRaw('LOWER(COALESCE(sales_wording_standard, \'\')) LIKE ?', [$t])
                        ->orWhereRaw('LOWER(COALESCE(marketing_text, \'\')) LIKE ?', [$t])
                        ->orWhereExists(fn ($e) => $e->from('foodalchemist_recipe_customer_names AS cn')
                            ->whereColumn('cn.recipe_id', 'foodalchemist_recipes.id')->whereNull('cn.deleted_at')
                            ->whereRaw('(LOWER(cn.customer_name) LIKE ? OR LOWER(cn.marketing_name) LIKE ?)', [$t, $t])));
                }
            })
            ->when($filters['hauptgruppe'] ?? null, fn ($q, $hg) => $q->where('dish_main_group_id', $hg))
            ->when($filters['class'] ?? null, fn ($q, $k) => $q->where('dish_class_id', $k))
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['geschmack'] ?? '') !== '', fn ($q) => $q->where('taste_direction', $filters['geschmack']));
    }

    /** 16 VK-Hauptgruppen mit Codes (aktive zuerst nach sort_order, dann Code). */
    public function dishMainGroups(Team $team): Collection
    {
        return FoodAlchemistDishMainGroup::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->orderBy('code')->get();
    }

    /** Gesamtzähler („Alle Hauptgruppen") aus der Tabellenquery — keine Facettensumme (MVP-042/048). */
    public function gesamtCount(Team $team, array $filters = []): int
    {
        return $this->browserQuery($team, array_diff_key($filters, ['hauptgruppe' => 1]))->count();
    }

    /**
     * recipe-Counts je VK-Hauptgruppe (Modell A: direkt über dish_main_group_id).
     *
     * Rechnet mit allen aktiven Filtern AUSSER der eigenen Achse (MVP-048) — eine Hauptgruppe
     * neben einer aktiven Klasse muss die Menge zeigen, die der Klick wirklich liefert.
     *
     * @return array<int, int>
     */
    public function hauptgruppenCounts(Team $team, array $filters = []): array
    {
        return $this->browserQuery($team, array_diff_key($filters, ['hauptgruppe' => 1]))
            ->whereNotNull('dish_main_group_id')
            ->groupBy('dish_main_group_id')
            ->pluck(DB::raw('COUNT(*) AS n'), 'dish_main_group_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    /**
     * recipe-Counts je Diät-Klasse — mit allen aktiven Filtern außer der Klassen-Achse.
     *
     * Die Hauptgruppe bleibt bewusst IM Filtersatz (Baum-Ansicht 2026-07-06: Klassen sind die
     * aufklappbare Ebene unterm aktiven HG-Knoten, also ist die HG dort Kontext und nicht die
     * eigene Achse). Vorher kam sie als separater `?int`-Parameter herein und alle übrigen
     * Filter fehlten ganz.
     *
     * @return array<int, int>
     */
    public function klassenCounts(Team $team, array $filters = []): array
    {
        return $this->browserQuery($team, array_diff_key($filters, ['class' => 1]))
            ->whereNotNull('dish_class_id')
            ->groupBy('dish_class_id')
            ->pluck(DB::raw('COUNT(*) AS n'), 'dish_class_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string, int> */
    public function statusCounts(Team $team): array
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->groupBy('status')->pluck(DB::raw('COUNT(*) AS n'), 'status')->map(fn ($n) => (int) $n)->all();
    }

    /** Detail STRIKT im verkauf()-Scope (Basisrezepte liefern null — §7.8). */
    /**
     * Ids aller Komponenten-Rezepte im Sub-Rezept-Baum (Regelwerk Basisrezepte §4:
     * max. 3 Ebenen), zyklensicher über die Besucht-Liste.
     *
     * @return list<int>
     */
    private function komponentenIds(FoodAlchemistRecipe $recipe, int $maxTiefe = 3): array
    {
        $besucht = [(int) $recipe->id => true];
        $ebene = [(int) $recipe->id];
        $ids = [];

        for ($tiefe = 0; $tiefe < $maxTiefe && $ebene !== []; $tiefe++) {
            $kinder = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::query()
                ->whereIn('recipe_id', $ebene)
                ->whereNotNull('referenced_recipe_id')
                ->whereNull('deleted_at')
                ->distinct()->pluck('referenced_recipe_id')
                ->map(fn ($v) => (int) $v)
                ->reject(fn ($id) => isset($besucht[$id]))
                ->values()->all();

            foreach ($kinder as $id) {
                $besucht[$id] = true;
                $ids[] = $id;
            }
            $ebene = $kinder;
        }

        return $ids;
    }

    /**
     * Beteiligte Posten eines Gerichts — ABGELEITET aus den Komponenten (2026-09-04).
     *
     * Ein Posten ist nie für ein ganzes Gericht zuständig: jede Komponente liegt auf
     * dem Posten ihres Basisrezepts, im Gericht kommt nur die Fertigstellung dazu.
     * Der Editor zeigt die Liste darum read-only neben dem Fertigstellungs-Posten.
     *
     * Bewusst NICHT persistiert — eine Spalte würde beim nächsten Komponententausch
     * driften, und der Produktionsplaner routet ohnehin je Zeile. Spiegelt damit die
     * Spec-30-Doktrin „Posten statt Personen, nie aggregieren": hier wird angezeigt,
     * nicht zusammengefasst.
     *
     * @return Collection<int, array{id:int,name:string,anzahl:int}>
     */
    public function beteiligtePosten(FoodAlchemistRecipe $recipe, int $maxTiefe = 3): Collection
    {
        $rezeptIds = $this->komponentenIds($recipe, $maxTiefe);
        if ($rezeptIds === []) {
            return collect();
        }

        // Explizite Spaltenliste: `recipes` trägt große Text-/JSON-Spalten, die in einer
        // gruppierten Abfrage nichts zu suchen haben.
        return FoodAlchemistRecipe::whereIn('id', $rezeptIds)
            ->whereNotNull('default_station_id')
            ->with('defaultStation:id,name')
            ->get(['id', 'default_station_id'])
            ->groupBy('default_station_id')
            ->map(fn (Collection $gruppe) => [
                'id' => (int) $gruppe->first()->default_station_id,
                'name' => (string) ($gruppe->first()->defaultStation?->name ?? '—'),
                'anzahl' => $gruppe->count(),
            ])
            ->sortByDesc('anzahl')
            ->values();
    }

    /**
     * Produktionszeiten der Komponenten — ABGELEITET, als Orientierung im Editor (2026-09-04).
     *
     * Warum überhaupt: die Auftrags-Explosion erzeugt genau EINE Zeile je Rezept
     * ({@see ProductionOrderService}), jede Komponente wird also eigenständig geplant und
     * bringt ihre eigene Zeit mit. Das Zeitfeld am GERICHT ist deshalb nicht die
     * Gesamtzeit, sondern die Zeit der Fertigstellung. Wer dort die Gesamtzeit einträgt,
     * zählt im selben Auftrag doppelt — einmal über die Komponenten-Zeilen, einmal über
     * die Gericht-Zeile.
     *
     * Bewusst KEINE Planzahl: die belastbare Personenzeit hängt an Menge, Ansätzen und
     * Topf-Deckel und wird von {@see ProductionTimeService} im Auftrag gerechnet. Hier
     * stehen die Stammdaten-Zeiten je Ansatz — plus die Zahl der Komponenten OHNE
     * Zeitangabe, denn die rechnet der Planer stillschweigend als 0.
     *
     * @return array{work_time_min: int, setup_time_min: int, standzeit_min: int, anzahl: int, ohne_zeit: int}
     */
    public function komponentenZeiten(FoodAlchemistRecipe $recipe, int $maxTiefe = 3): array
    {
        $ids = $this->komponentenIds($recipe, $maxTiefe);
        $leer = ['work_time_min' => 0, 'setup_time_min' => 0, 'standzeit_min' => 0, 'anzahl' => 0, 'ohne_zeit' => 0];
        if ($ids === []) {
            return $leer;
        }

        $komponenten = FoodAlchemistRecipe::whereIn('id', $ids)
            ->get(['id', 'work_time_min', 'setup_time_min', 'standzeit_min']);

        return [
            'work_time_min' => (int) round($komponenten->sum(fn ($r) => (float) ($r->work_time_min ?? 0))),
            'setup_time_min' => (int) round($komponenten->sum(fn ($r) => (float) ($r->setup_time_min ?? 0))),
            'standzeit_min' => (int) round($komponenten->sum(fn ($r) => (float) ($r->standzeit_min ?? 0))),
            'anzahl' => $komponenten->count(),
            'ohne_zeit' => $komponenten->filter(fn ($r) => (float) ($r->work_time_min ?? 0) <= 0)->count(),
        ];
    }

    public function detail(Team $team, int $id): ?FoodAlchemistRecipe
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->with([
                'speisenKlasse:id,label,diet_form,dish_main_group_id',
                'dishClass.mainGroup:id,code,label',
                'aufschlagsklasse',
                'vkEinheit:id,slug,display_de',
                'ingredients' => fn ($q) => $q->whereNull('deleted_at')->orderBy('position'),
                // M9-01e: Bio-/Regional-Anteil braucht die GP-Tags; Nährwert-Faktor die Einheit
                'ingredients.gp:id,name,tag_is_organic,tag_is_regional', 'ingredients.referencedRecipe:id,name',
                'ingredients.unit:id,slug,display_de,default_in_g,default_in_ml',
            ])
            ->find($id);
    }

    // ── M6-04: Editor-Schreibpfade (V-07: Mehr-Zeilen-Writes in Transaktionen) ──

    /**
     * Referenzierte Fremdschlüssel der VK-Whitelist und ihre Herkunftstabelle (MVP-050, P0).
     *
     * Eine Whitelist sagt „dieses Feld darf geschrieben werden" — sie sagt nichts darüber, ob
     * der WERT zulässig ist. Genau diese Lücke war der Befund: die IDs kamen per
     * `array_intersect_key` roh aus einem client-kontrollierten Formular, die einzige Prüfung
     * war die Options-Liste im Browser. Jede ID hier wird gegen `visibleToTeam` aufgelöst.
     *
     * Bewusst SICHTBARKEIT und nicht Eigentum: geerbte und globale Vokabeln müssen am eigenen
     * Gericht verwendbar bleiben (Master-Vererbung). Siehe `TeamScope::referenz()`.
     */
    private const VK_REFERENZEN = [
        'dish_class_id' => FoodAlchemistDishClass::class,
        'dish_main_group_id' => FoodAlchemistDishMainGroup::class,
        'markup_class_id' => \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::class,
        'sales_unit_vocab_id' => \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::class,
        // Auto-Produktionsplaner (2026-08-03): Posten-FK team-autorisieren (wie RecipeService),
        // sonst könnte ein Gericht auf einen Fremd-Team-Posten zeigen.
        'default_station_id' => \Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::class,
    ];

    /** Erlaubte VK-Feldgruppen (V-12: Policy-Grenze mitten durchs geteilte Modell). */
    private const VK_FELDER = [
        'name', 'sales_wording_standard', 'dish_class_id', 'markup_class_id',
        // MVP-049: Modell A — die Hauptgruppe ist eine EIGENE Achse am Gericht und musste
        // persistierbar werden. Vorher leitete der Editor sie aus `dishClass.dish_main_group_id`
        // ab (immer NULL, seit die Klassen die vier flachen Diätformen sind) und konnte sie nicht
        // speichern: der Klassifikations-Block war damit funktionslos.
        'dish_main_group_id',
        'sales_unit_vocab_id', 'sales_unit_count', 'sales_quantity_per_unit_g',
        // Rückwärtskompatibler API-Eingang; die Preis-Wahrheit wird unten auf die
        // Standard-Darreichung umgeleitet und anschließend nur zurückgespiegelt.
        'sales_net', 'price_mode', 'price_override_reason',
        'container_warm_vocab_id', 'container_warm_count', 'container_cold_vocab_id', 'container_cold_count',
        'serving_vehicle_vocab_id', 'taste_direction',
        // M9-01: Voll-Editor-Parität — Eigenschaften, Texte, Plating, Notizen
        'marketing_text', 'description', 'work_time_min', 'temperature', 'function',
        'production_depth', 'plating_text', 'notes_manual',
        'additional_costs_eur',                                            // M12: Energie/Nebenkosten je Charge (HK2)
        // Auto-Produktionsplaner (2026-08-03): Parität zum Basisrezept-Editor — sonst verwirft
        // die Whitelist die Werte still und der Planer routet Gericht-Zeilen nie.
        // `setup_time_min` + `max_vorlauf_tage` bewusst NICHT (Entscheid 2026-09-04):
        // Rüstzeit und Vorproduzierbarkeit sind Herstellungs-Eigenschaften und gehören an
        // die Komponenten-Basisrezepte. Am Gericht wären sie nur eine stille Fehlerquelle.
        'default_station_id', 'variable_work_time_min',
        'variable_work_time_basis', 'standzeit_min', 'batch_max_kg', 'batch_max_pieces',
    ];

    public function updateVk(Team $team, int $id, array $in): FoodAlchemistRecipe
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($id);
        if (! $recipe->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Rezept — VK-Pflege nur durchs Besitzer-Team (D1).');
        }

        return DB::transaction(function () use ($team, $recipe, $in) {
            $update = array_intersect_key($in, array_flip(self::VK_FELDER));

            // MVP-050 (P0): Referenzen autorisieren, BEVOR sie geschrieben werden. Der
            // Owner-Guard oben schützt das Rezept — nicht die daran gehängten Fremdschlüssel.
            foreach (self::VK_REFERENZEN as $feld => $modelClass) {
                if (array_key_exists($feld, $update)) {
                    $update[$feld] = TeamScope::referenz($modelClass, $update[$feld], $team, $feld);
                }
            }
            // Entscheid 2026-09-04: als VERKAUFS-Einheit ist nur die kurze Whitelist zulässig
            // (Portion/Stück/kg/l). Ohne diese Prüfung könnte ein MCP-Aufruf eine „Prise"
            // setzen, die die UI gar nicht mehr anbietet — das Tool darf nicht mehr können
            // als der Editor. Ein UNVERÄNDERTER Alt-Wert bleibt erlaubt (Bestandsschutz),
            // sonst bricht an solchen Gerichten das Speichern beliebiger anderer Felder.
            if (array_key_exists('sales_unit_vocab_id', $update)
                && $update['sales_unit_vocab_id'] !== null
                && (int) $update['sales_unit_vocab_id'] !== (int) $recipe->sales_unit_vocab_id) {
                $slug = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::find($update['sales_unit_vocab_id'])?->slug;
                if (! in_array($slug, (array) config('foodalchemist.sales_units', []), true)) {
                    throw new \RuntimeException('Als Verkaufseinheit sind nur Portion, Stück, Kilogramm und Liter zulässig.');
                }
            }

            // Wording/Marketing/Plating manuell editiert → Lineage auf manual (GL-07).
            // Spalten-Muster: <feld>_source + <feld>_ai_confidence (English-Rename).
            foreach (['sales_wording_standard' => 'sales_wording', 'marketing_text' => 'marketing_text', 'plating_text' => 'plating'] as $feld => $praefix) {
                if (array_key_exists($feld, $update) && $update[$feld] !== $recipe->{$feld}) {
                    $update["{$praefix}_source"] = 'manual';
                    $update["{$praefix}_ai_confidence"] = null;
                }
            }
            $recipeUpdate = array_diff_key($update, array_flip(['price_mode', 'price_override_reason']));
            $recipeUpdate['last_modified_by'] = 'vk_editor';
            $recipe->update($recipeUpdate);

            // Umbau-Spec Phase 5: Standard-Darreichung synchron halten — Preis-Wahrheit
            // liegt an der Darreichung, die Legacy-Spalten sind Anzeige-/Kompat-Schicht.
            $this->syncStandardDarreichung($team, $recipe->refresh(), $update);

            // 2026-09-04: `plating_text` ist der Spiegel der ANRICHTE-Schritte (Regelwerk §3.3),
            // genau wie `preparation` der Spiegel der Produktions-Schritte ist. Ein Markdown-Write
            // (Editor-Textarea, KI-Plating, MCP) ist damit ein EINGANG: er wird in Schritte
            // geparst. Hat die Ebene schon Schritte, gewinnen sie — dann wird der Spiegel neu
            // gerendert, sonst stünde im Feld ein Text, den die Anleitung nicht sagt.
            $this->platingSchritteAusMarkdown($recipe->refresh(), $update);

            return $recipe->refresh();
        });
    }

    /** Markdown-Eingang der Anrichte-Ebene — Muster wie {@see RecipeService::schritteAusMarkdown}. */
    private function platingSchritteAusMarkdown(FoodAlchemistRecipe $recipe, array $update): void
    {
        if (! array_key_exists('plating_text', $update) || trim((string) ($update['plating_text'] ?? '')) === '') {
            return;
        }

        $svc = app(RecipeStepService::class);
        $ebene = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::EBENE_ANRICHTEN;
        if ($svc->ausMarkdown($recipe, (string) $update['plating_text'], ebene: $ebene) === 0) {
            $svc->spiegele($recipe);
        }
    }

    /** VK-Felder des Legacy-Editors in die Standard-Darreichung spiegeln (eine Wahrheit). */
    private function syncStandardDarreichung(Team $team, FoodAlchemistRecipe $recipe, array $update): void
    {
        $standard = $recipe->standardPresentation()->first();
        if ($standard === null && $recipe->presentations()->exists()) {
            return; // Varianten ohne Standard-Flag: nichts raten
        }
        if ($standard === null) {
            $standard = app(DarreichungService::class)->ensureStandard($team, $recipe->id, 'fa_ui');
            if ($standard === null) {
                return;
            }
        }
        $map = [
            'sales_quantity_per_unit_g' => 'quantity_per_unit_g',
            'sales_unit_vocab_id' => 'unit_vocab_id',
            'markup_class_id' => 'markup_class_id',
            'container_warm_vocab_id' => 'container_warm_vocab_id',
            'container_cold_vocab_id' => 'container_cold_vocab_id',
            'serving_vehicle_vocab_id' => 'serving_vehicle_vocab_id',
        ];
        $dUpdate = [];
        foreach ($map as $von => $nach) {
            if (array_key_exists($von, $update)) {
                $dUpdate[$nach] = $update[$von];
            }
        }
        if (! array_key_exists('sales_quantity_per_unit_g', $update)
            && (array_key_exists('sales_unit_count', $update) || $standard->quantity_per_unit_g === null)
            && $recipe->yield_kg !== null && (int) $recipe->sales_unit_count > 0) {
            $dUpdate['quantity_per_unit_g'] = round(
                (float) $recipe->yield_kg * 1000 / (int) $recipe->sales_unit_count,
                1,
            );
            $dUpdate['unit_count'] = 1;
        }
        if (array_key_exists('sales_net', $update)) {
            if ($update['sales_net'] === null || $update['sales_net'] === '') {
                $dUpdate['price_mode'] = 'auto';
            } else {
                $dUpdate['price_mode'] = 'fixed';
                $dUpdate['sales_net'] = (float) $update['sales_net'];
                $dUpdate['price_override_reason'] = trim((string) ($update['price_override_reason'] ?? ''))
                    ?: 'Legacy-Übernahme aus Gericht-API';
            }
        }
        if ($dUpdate !== []) {
            app(DarreichungService::class)->aktualisieren($team, $standard->id, $dUpdate);
        }
    }

    /**
     * DoD M6-04: VK anlegen AUS Basisrezept — neues VK-Rezept mit dem
     * Basisrezept als erster Komponente (eine Charge = yield in g), danach
     * GL-02-Recompute über den D-5-Sync (eine Regel-Stelle).
     */
    public function createFromBasis(Team $team, int $basisRecipeId, string $name): FoodAlchemistRecipe
    {
        $basis = FoodAlchemistRecipe::visibleToTeam($team)->basis()->findOrFail($basisRecipeId);
        $recipes = app(RecipeService::class);

        return DB::transaction(function () use ($team, $basis, $name, $recipes) {
            $vk = $recipes->create($team, [
                'name' => $name,
                'is_sales_recipe' => true,
                'markup_class_id' => app(TeamSettingsService::class)->defaultMarkupClassId($team),
            ]);
            $gramm = $basis->yield_kg !== null ? round((float) $basis->yield_kg * 1000, 1) : 1000.0;
            $einheitG = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', 'g')->value('id');

            return $recipes->syncIngredients($team, $vk->id, [[
                'raw_text' => $basis->name,
                'display_name' => $basis->name,
                'quantity' => $gramm,
                'unit_vocab_id' => $einheitG,
                'referenced_recipe_id' => $basis->id,
                'match_method' => 'recipe_ref',
            ]]);
        });
    }

    /** Leeres Verkaufsrezept (Gericht) ohne erste Komponente — Komponenten/Stück-Basisrezepte kommen im Editor dazu. */
    public function createLeer(Team $team, string $name): FoodAlchemistRecipe
    {
        return app(RecipeService::class)->create($team, [
            'name' => $name,
            'is_sales_recipe' => true,
            'markup_class_id' => app(TeamSettingsService::class)->defaultMarkupClassId($team),
        ]);
    }

    // V-19: Regen-Programme (zeilenbasiert)

    public function upsertRegeneration(Team $team, int $recipeId, array $in, ?int $id = null): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        $werte = [
            'component_label' => trim((string) ($in['component_label'] ?? '')) ?: 'Gesamt',
            'device_vocab_id' => $in['device_vocab_id'] ?? null,
            'temp_c' => $in['temp_c'] ?? null,
            'duration_min' => $in['duration_min'] ?? null,
            'core_temp_c' => $in['core_temp_c'] ?? null,
            'note' => $in['note'] ?? null,
            'source' => 'manual', 'ai_confidence' => null, 'ai_reasoning' => null,      // manual gewinnt (GL-07)
            'updated_at' => now(),
        ];
        if ($id !== null) {
            DB::table('foodalchemist_recipe_regenerations')->where('id', $id)->where('recipe_id', $recipe->id)->update($werte);

            return;
        }
        DB::table('foodalchemist_recipe_regenerations')->insert($werte + [
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $recipe->team_id,
            'recipe_id' => $recipe->id,
            'sort_order' => (int) DB::table('foodalchemist_recipe_regenerations')
                ->where('recipe_id', $recipe->id)->whereNull('deleted_at')->max('sort_order') + 1,
            'created_at' => now(),
        ]);
    }

    public function deleteRegeneration(Team $team, int $recipeId, int $id): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_regenerations')->where('id', $id)->where('recipe_id', $recipeId)
            ->update(['deleted_at' => now()]);
    }

    /** @param list<int> $ids neue Reihenfolge */
    public function reorderRegenerations(Team $team, int $recipeId, array $ids): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        DB::transaction(function () use ($recipeId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                DB::table('foodalchemist_recipe_regenerations')
                    ->where('id', (int) $id)->where('recipe_id', $recipeId)->update(['sort_order' => $i]);
            }
        });
    }

    // Verwendungsnachweise (Kunde × Marketing-Name, team-eigen)

    public function addCustomerName(Team $team, int $recipeId, string $kunde, string $marketingName, ?string $note = null): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_customer_names')->updateOrInsert(
            ['recipe_id' => $recipe->id, 'customer_name' => trim($kunde)],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id,
                'marketing_name' => trim($marketingName), 'note' => $note, 'deleted_at' => null,
                'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function deleteCustomerName(Team $team, int $recipeId, int $id): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_customer_names')->where('id', $id)->where('recipe_id', $recipeId)
            ->update(['deleted_at' => now()]);
    }

    /**
     * VK-Layer lösen (D-6): entfernt NUR das Verkaufsgericht selbst — die referenzierten
     * Basisrezepte und GPs bleiben unangetastet (sie sind eigene Datensätze, das Gericht
     * hält nur Zutaten-Verweise darauf). Gelöscht werden die recipe-Row (verkauf-Scope),
     * ihre Zutaten-Verweise und die VK-Facetten (Darreichungen inkl. Deltas, Kunden-Wordings,
     * Regenerationen, Niveau-/Sektor-Eignungen). Alles Soft-Delete in einer Transaktion.
     *
     * Guard: hängt das Gericht noch in einem Foodbook-Block, Concept-Slot oder Speiseplan-
     * Eintrag, wird abgebrochen (kein stilles Verwaisen) — dort erst lösen.
     */
    public function deleteDish(Team $team, int $recipeId): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Gericht — Löschen nur durchs Besitzer-Team (D1).');
        }

        // Referenz-Guard — Schema::hasTable-gesichert, weil nicht jede Umgebung alle Module
        // ausgerollt hat (z. B. Speiseplan-Tabellen fehlen auf der aktuellen Master-DB).
        $refs = [];
        foreach ([
            [\Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::class, 'Foodbook'],
            [\Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot::class, 'Konzept'],
            [\Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag::class, 'Speiseplan'],
        ] as [$model, $label]) {
            $table = (new $model)->getTable();
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }
            if (($n = $model::where('sales_recipe_id', $recipe->id)->count()) > 0) {
                $refs[] = "{$n}× {$label}";
            }
        }
        if ($refs !== []) {
            throw new \RuntimeException('Gericht wird noch verwendet (' . implode(', ', $refs) . ') — dort erst entfernen.');
        }

        DB::transaction(function () use ($recipe) {
            $darIds = $recipe->presentations()->pluck('id');
            if ($darIds->isNotEmpty()) {
                \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta::whereIn('presentation_id', $darIds)->delete();
            }
            $recipe->presentations()->delete();
            $recipe->customerNames()->delete();
            $recipe->regenerations()->delete();
            $recipe->levelSuitabilities()->delete();
            $recipe->sectorSuitabilities()->delete();
            $recipe->ingredients()->delete();
            $recipe->delete();
        });
    }

    /** @return list<string> Autocomplete, team-scoped (§7.7) */
    public function distinctCustomerNames(Team $team): array
    {
        return DB::table('foodalchemist_recipe_customer_names')
            ->where('team_id', $team->id)->whereNull('deleted_at')
            ->distinct()->orderBy('customer_name')->pluck('customer_name')->all();
    }

    /**
     * VERKAUFT-ALS-Box + Marge-Cockpit in einem Aufruf (alles abgeleitet, GL-02 I9):
     * g/Einheit = Primär-Eingabe oder aus Yield/Anzahl; VK-Daten via MargeService.
     *
     * @return array{verkauft_als: ?array, vk: array, marge: ?array, pro_einheit: ?array, formel_fehlt: bool}
     */
    public function cockpit(FoodAlchemistRecipe $r, ?Team $team = null, ?FoodAlchemistOutlet $outlet = null): array
    {
        $anzahl = $r->sales_unit_count !== null ? (int) $r->sales_unit_count : null;
        $mengeProEinheitG = $r->sales_quantity_per_unit_g !== null
            ? (float) $r->sales_quantity_per_unit_g
            : ($r->yield_kg !== null && $anzahl !== null && $anzahl > 0 ? round((float) $r->yield_kg * 1000 / $anzahl, 1) : null);

        $verkauftAls = $anzahl !== null || $mengeProEinheitG !== null ? [
            'anzahl' => $anzahl,
            'unit' => $r->salesUnit?->display_de ?? $r->salesUnit?->slug ?? 'Einheit',
            'g_pro_einheit' => $mengeProEinheitG,
            'yield_kg' => $r->yield_kg !== null ? (float) $r->yield_kg : null,
        ] : null;

        $formelFehlt = false;
        $vk = ['sales_net' => $r->sales_net !== null ? (float) $r->sales_net : null, 'source' => 'leer', 'vorschlag' => null];
        $mwst = $team !== null ? (float) app(TeamSettingsService::class)->mwst($team)['ermaessigt'] : 0.0;
        $standard = $r->standardPresentation()->first();
        if ($team !== null && $standard !== null) {
            $catalog = $this->catalogPricing->catalogPrice($team, $standard, $outlet);
            $vk = [
                'sales_net' => $catalog['sales_net'],
                'source' => $catalog['price_mode'],
                'vorschlag' => $catalog['calculated_sales_net'] !== null ? [
                    'sales_net' => $catalog['calculated_sales_net'],
                    'sales_gross' => $catalog['calculated_sales_net'] * (1 + $catalog['vat_rate'] / 100),
                    'vat_rate' => $catalog['vat_rate'],
                    'formel' => sprintf('MEK × Basissatz %.3f × Klassenfaktor %.1f%%',
                        $catalog['base_factor'], $catalog['class_factor_pct']),
                    'source' => $catalog['base_source'],
                ] : null,
            ];
            $mwst = (float) $catalog['vat_rate'];
        }
        $ekBasis = $standard?->ek_portion !== null
            ? (float) $standard->ek_portion
            : ($r->ek_total_eur !== null ? (float) $r->ek_total_eur : null);
        $marge = $this->marge->marge($vk['sales_net'], $ekBasis);

        // Spec 28 §6.1: Food-Cost-Ampel mitliefern, damit die VK-Editor-Kachel den Wareneinsatz
        // gegen ETWAS messen kann. Ohne Team gibt es keine Ziel-Quote → `unbekannt` statt geraten
        // (der Aufrufer kennt sein Team; der Service holt sich hier keins über die Hintertür).
        $zielPct = $team !== null ? app(TeamSettingsService::class)->zielWareneinsatzPct($team) : null;
        $wePct = $marge['wareneinsatz_pct'] ?? null;

        return [
            'verkauft_als' => $verkauftAls,
            'vk' => $vk,
            'sales_gross' => $vk['sales_net'] !== null ? round($vk['sales_net'] * (1 + $mwst / 100), 2) : null,
            'vat_rate' => $mwst,
            'marge' => $marge,
            'pro_einheit' => $this->marge->proEinheit(
                $vk['sales_net'],
                $standard !== null ? max(1, (int) ($standard->unit_count ?: 1)) : null,
                $mwst,
            ),
            'formel_fehlt' => $formelFehlt,
            'ziel_pct' => $zielPct,
            'ampel' => $this->marge->weAmpel($wePct !== null ? (float) $wePct : null, (float) ($zielPct ?? 0)),
        ];
    }
}
