<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * M4-07/08 / P-8: Zutaten-Editor — Alpine-first: Tippen/Reorder/Add laufen
 * komplett im Client (rows-Array), Zeilen-EK + Summen live (ek_pro_g vom
 * Server vorgerechnet, T3-Quelle); Server-Sync erst bei „Speichern"
 * (RecipeService::syncIngredients = EINE Transaktion + EIN Recompute).
 *
 * Ehrliche Grenze (P-8-Abweichungstabelle): Client-EK ist eine Live-Näherung
 * über default_in_g/ml — count-Einheiten + Brücken rechnet erst der Save-
 * Recompute (Zeile zeigt dann den Server-Wert).
 */
class IngredientEditor extends Component
{
    public ?int $recipeId = null;

    public ?string $fehler = null;

    /** Editor-Parität: eingebettet im Voll-Editor (ohne Modal-Hülle, eine Quelle). */
    public bool $eingebettet = false;

    public function mount(?int $recipeId = null, bool $eingebettet = false): void
    {
        $this->recipeId = $recipeId;
        $this->eingebettet = $eingebettet;
    }

    #[On('zutaten-editor.oeffnen')]
    public function oeffnen(int $id): void
    {
        if ($this->eingebettet) {
            return;                                                  // Modal-Event geht nur an die Modal-Instanz
        }
        $this->fehler = null;
        $this->recipeId = $id;
        $this->dispatch('modal.open', name: 'zutaten-editor');
    }

    /** @param array<int, array> $zeilen kompletter Client-Stand (Reihenfolge = Position) */
    public function speichern(array $zeilen): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }

        try {
            app(RecipeService::class)->syncIngredients($team, $this->recipeId, $zeilen);
            if (! $this->eingebettet) {
                $this->dispatch('modal.close', name: 'zutaten-editor');
            }
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('recipe-selected', id: $this->recipeId);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * M4-11: Garverlust-Vorschläge via Gateway (GL-07: nichts persistiert —
     * Alpine merged in die rows, geschrieben wird beim Save mit source=ki).
     *
     * @param array<int, string> $zutaten [index => raw_text]
     * @return array{verluste: array<int, float>, confidence: float}
     */
    public function garverlustVorschlag(array $zutaten): array
    {
        $vorschlag = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)
            ->propose('recipe.garverlust', ['zutaten' => $zutaten, 'verluste' => new \stdClass]);
        $verluste = [];
        foreach (($vorschlag->werte['verluste'] ?? []) as $idx => $pct) {
            if (is_numeric($pct)) {
                $verluste[(int) $idx] = max(0.0, min(60.0, (float) $pct));  // Clamp lt. Prompt-Spez
            }
        }

        return ['verluste' => $verluste, 'confidence' => max(0.0, min(1.0, $vorschlag->confidence))];
    }

    /**
     * GP-Peek (D-5 §4.2.3 / Ist-App): Lieferantenartikel hinter dem GP —
     * Lieferant · Art.-Nr · Bezeichnung · Marke · VPE · Preis · Vergleichspreis
     * · Match, ★ = Lead-LA. Ohne Editor-Verlust (Alpine klappt auf).
     */
    #[Renderless]
    public function gpArtikel(?int $gpId): array
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = $team !== null && $gpId !== null ? app(\Platform\FoodAlchemist\Services\GpService::class)->find($gpId, $team) : null;
        if ($gp === null) {
            return [];
        }
        $preise = app(\Platform\FoodAlchemist\Services\PriceService::class);

        return app(\Platform\FoodAlchemist\Services\GpService::class)->lasForGp($gp)
            ->map(function ($la) use ($gp, $preise) {
                $preis = $la->price?->price !== null ? (float) $la->price->price : null;
                $vergleich = $la->item !== null ? $preise->vergleichspreis($la->item, $preis) : null;

                return [
                    'lead' => $la->item !== null && (int) $la->item->id === (int) $gp->lead_la_supplier_item_id,
                    'lieferant' => $la->supplier?->name ?? '—',
                    'artikelnr' => $la->item?->article_number ?? '—',
                    'label' => $la->item?->designation ?? '—',
                    'marke' => $la->item?->brand ?? null,
                    'vpe' => $la->item?->qty !== null
                        ? rtrim(rtrim(number_format((float) $la->item->qty, 2, ',', '.'), '0'), ',') . ' ' . ($la->item->packaging_unit ?? $la->item->unit_code ?? '')
                        : null,
                    'price' => $preis !== null ? number_format($preis, 2, ',', '.') . ' €' : null,
                    'vergleichspreis' => $vergleich !== null ? number_format($vergleich['value'], 2, ',', '.') . ' ' . $vergleich['unit'] : null,
                    'match' => $la->structure?->main_ingredient_confidence !== null
                        ? round((float) $la->structure->main_ingredient_confidence * 100) . ' %'
                        : null,
                ];
            })
            ->sortByDesc('lead')->values()->all();
    }

    /**
     * R5 (Dominique): EK-Varianten je GP — günstigster LA-Preis + Ø über alle
     * LAs (€/g via GL-11-Vergleichspreis), neben der Lead-Strategie-Spalte.
     *
     * @return array{min: ?float, avg: ?float}
     */
    private function ekVarianten(\Platform\FoodAlchemist\Models\FoodAlchemistGp $gp): array
    {
        $preise = app(\Platform\FoodAlchemist\Services\PriceService::class);
        $werte = app(\Platform\FoodAlchemist\Services\GpService::class)->lasForGp($gp)
            ->map(fn ($la) => $la->item !== null
                ? $preise->preisProGramm($la->item, $la->price?->price !== null ? (float) $la->price->price : null)
                : null)
            ->filter(fn ($v) => $v !== null)->values();

        return [
            'min' => $werte->isNotEmpty() ? (float) $werte->min() : null,
            'avg' => $werte->isNotEmpty() ? (float) $werte->avg() : null,
        ];
    }

    /**
     * Ersatz-Hinweis für eine client-seitig neue/getauschte Zeile (Katalog-Äquivalenz,
     * GP↔Rezept / GP↔GP) — die initialen Zeilen bekommen ihn gebündelt in render().
     * Faktor ist richtungsaufgelöst (neue Menge = Menge × faktor).
     */
    #[Renderless]
    public function ersatzFuer(?int $gpId, ?int $subId): ?array
    {
        $team = Auth::user()?->currentTeamRelation;
        $kind = $gpId !== null ? 'gp' : ($subId !== null ? 'recipe' : null);
        if ($team === null || $kind === null) {
            return null;
        }
        $id = (int) ($gpId ?? $subId);
        $treffer = app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)
            ->ersatzHinweise($team, [[$kind, $id]])[$kind . ':' . $id] ?? null;

        return $treffer !== null ? $this->ersatzPayload($treffer) : null;
    }

    /** Ersatz-Hinweis fürs Client-row-Format (inkl. Sprung-URL der Gegenseite). */
    private function ersatzPayload(object $treffer): array
    {
        return [
            'kind' => $treffer->kind,
            'id' => $treffer->id,
            'name' => $treffer->kind === 'recipe' ? '↳ ' . $treffer->name : $treffer->name,
            'faktor' => $treffer->faktor,
            'url' => $treffer->kind === 'gp'
                ? \Platform\FoodAlchemist\Support\Sprungziel::gp($treffer->id)
                : \Platform\FoodAlchemist\Support\Sprungziel::rezept($treffer->id),
        ];
    }

    /** GP-/Sub-Picker (M4-08): liefert Auto-Fill-Daten inkl. ek_pro_g. */
    #[Renderless]
    public function sucheZiel(string $suche): array
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return [];
        }

        return app(RecipeService::class)->sucheZutatenZiel($team, $suche, $this->recipeId);
    }

    /**
     * R18 (Drei-Spalten-Browser): GPs + Basisrezepte als FLACHE, serverseitig
     * gefilterte Listen — stapelbare Filter statt Baum, das zentrale Suchfeld
     * wirkt als Textfilter auf BEIDE Listen. Ein Roundtrip für beide Spalten.
     */
    #[Renderless]
    public function browseKatalog(array $gpFilter = [], array $rezFilter = [], string $q = ''): array
    {
        $team = Auth::user()?->currentTeamRelation;
        $leer = ['items' => [], 'total' => 0];
        if ($team === null) {
            return ['gps' => $leer, 'rezepte' => $leer];
        }
        $recompute = app(RecipeRecomputeService::class);
        $suche = mb_strtolower(trim($q));

        $gpQuery = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)
            ->when($suche !== '', fn ($w) => \Platform\FoodAlchemist\Support\Suche::like($w, 'name', $suche))
            ->when(($gpFilter['wg'] ?? '') !== '', fn ($w) => $w->where('commodity_group_code', $gpFilter['wg']))
            ->when(($gpFilter['sub'] ?? '') !== '', fn ($w) => $w->where('sub_category', $gpFilter['sub']))
            ->when(($gpFilter['condition'] ?? '') !== '', fn ($w) => $w->where('condition', $gpFilter['condition']))
            ->when((bool) ($gpFilter['bio'] ?? false), fn ($w) => $w->where('tag_is_organic', true))
            ->when((bool) ($gpFilter['regional'] ?? false), fn ($w) => $w->where('tag_is_regional', true));
        $gpTotal = (clone $gpQuery)->count();
        $gpModels = $gpQuery->orderBy('name')->limit(200)
            ->get(['id', 'name', 'condition', 'lead_la_supplier_item_id', 'piece_default_g', 'team_id']);
        // Performance: 30× preisProGrammPublic wären ~60 Queries je Tipper — stattdessen EINE
        // Bulk-Query (Ø €/g über aktive kg/l-LAs). Der präzise Lead-Wert kommt beim Parken nach.
        $aktiverPreis = app(\Platform\FoodAlchemist\Services\PriceService::class)->activePriceSubquery()->toBase();
        $ekJeGp = \Illuminate\Support\Facades\DB::table('foodalchemist_supplier_items')
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->whereIn('s.gp_id', $gpModels->pluck('id'))->whereNull('s.deleted_at')
            ->whereIn('foodalchemist_supplier_items.unit_code', ['kg', 'l'])
            ->where('foodalchemist_supplier_items.qty', '>', 0)
            ->where('foodalchemist_supplier_items.is_discontinued', false)
            ->select('s.gp_id', 'foodalchemist_supplier_items.qty')
            ->selectSub($aktiverPreis, 'aktiver_preis')
            ->get()
            ->filter(fn ($r) => $r->aktiver_preis !== null)
            ->groupBy('gp_id')
            ->map(fn ($g) => $g->avg(fn ($r) => ((float) $r->aktiver_preis) / (((float) $r->qty) * 1000)));
        $gps = $gpModels
            ->map(function ($gp) use ($ekJeGp) {
                $ek = $ekJeGp[$gp->id] ?? null;

                return [
                    'type' => 'gp', 'id' => $gp->id, 'name' => $gp->name,
                    'ek_pro_g' => $ek,
                    'preis_label' => $ek !== null ? number_format($ek * 1000, 2, ',', '.') . ' €/kg' : null,
                    // Spec: Einheit hängt am Produkt (Chilipulver→g, Bier→ml) — Override im Dropdown
                    'einheit_slug' => str_contains(mb_strtolower($gp->name . ' ' . ($gp->condition ?? '')), 'fluessig') ? 'ml' : 'g',
                ];
            })->values()->all();

        $rezQuery = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->where('id', '!=', (int) $this->recipeId)
            ->when($suche !== '', fn ($w) => \Platform\FoodAlchemist\Support\Suche::like($w, 'foodalchemist_recipes.name', $suche))
            ->when(($rezFilter['hg'] ?? '') !== '', fn ($w) => $w->whereHas('category', fn ($k) => $k->where('main_group_id', (int) $rezFilter['hg'])))
            ->when(($rezFilter['kat'] ?? '') !== '', fn ($w) => $w->where('category_id', (int) $rezFilter['kat']))
            ->when(($rezFilter['level'] ?? '') !== '', fn ($w) => $w->whereHas('levelSuitabilities', fn ($n) => $n->where('level_slug', $rezFilter['level'])));
        $rezTotal = (clone $rezQuery)->count();
        $rezepte = $rezQuery->with('levelSuitabilities:id,recipe_id,level_slug')->orderBy('name')->limit(200)
            ->get(['id', 'name', 'ek_per_kg_eur', 'yield_kg', 'yield_pieces'])
            ->map(function ($r) {
                $hatStueck = $r->yield_pieces !== null && (float) $r->yield_pieces > 0 && $r->yield_kg !== null;

                return [
                    'type' => 'sub', 'id' => $r->id, 'name' => '↳ ' . $r->name,
                    'ek_pro_g' => $r->ek_per_kg_eur !== null ? ((float) $r->ek_per_kg_eur) / 1000 : null,
                    'preis_label' => $r->ek_per_kg_eur !== null ? number_format((float) $r->ek_per_kg_eur, 2, ',', '.') . ' €/kg' : null,
                    // Stück-Ertrag → Einheit beim Einfügen auf „stk" vorbelegen + g/Stück fürs Live-Rechnen
                    'einheit_slug' => $hatStueck ? 'stk' : 'g',
                    'g_pro_stueck' => $hatStueck ? (float) $r->yield_kg * 1000 / (float) $r->yield_pieces : null,
                    'niveaus' => $r->levelSuitabilities->pluck('level_slug')->values()->all(),
                ];
            })->values()->all();

        return [
            'gps' => ['items' => $gps, 'total' => $gpTotal],
            'rezepte' => ['items' => $rezepte, 'total' => $rezTotal],
        ];
    }

    /** R18: präziser Lead-€/g fürs geparkte Ziel (T3-Logik) — die Listen tragen nur den Bulk-Ø. */
    #[Renderless]
    public function ekFuerZiel(string $typ, int $id): ?float
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return null;
        }
        if ($typ === 'gp') {
            $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->find($id);

            return $gp !== null ? app(RecipeRecomputeService::class)->preisProGrammPublic($gp) : null;
        }
        $r = FoodAlchemistRecipe::visibleToTeam($team)->find($id);

        return $r?->ek_per_kg_eur !== null ? ((float) $r->ek_per_kg_eur) / 1000 : null;
    }

    public function render(RecipeRecomputeService $recompute)
    {
        $team = Auth::user()?->currentTeamRelation;
        // M6-04 / D-6 §6: sicht-neutral laden — EIN Editor für Basis- UND VK-Sicht
        $rezept = $team !== null && $this->recipeId !== null
            ? app(RecipeService::class)->detailAnySicht($team, $this->recipeId)
            : null;

        $zeilen = [];
        if ($rezept !== null) {
            foreach ($rezept->ingredients as $z) {
                $ekProG = null;
                $varianten = ['min' => null, 'avg' => null];
                if ($z->gp !== null) {
                    $ekProG = $recompute->preisProGrammPublic($z->gp);
                    $varianten = $this->ekVarianten($z->gp);          // R5: günstigster + Ø über alle LAs
                } elseif ($z->referencedRecipe?->ek_per_kg_eur !== null) {
                    $ekProG = ((float) $z->referencedRecipe->ek_per_kg_eur) / 1000;
                }
                $zeilen[] = [
                    'id' => $z->id,
                    'gp_id' => $z->gp_id,
                    'referenced_recipe_id' => $z->referenced_recipe_id,
                    'ziel_name' => $z->gp?->name ?? ($z->referencedRecipe !== null ? '↳ ' . $z->referencedRecipe->name : null),
                    // R5: Sprung-Ziel (neuer Tab — Editor-Stand bleibt unberührt)
                    'ziel_url' => $z->gp_id !== null
                        ? \Platform\FoodAlchemist\Support\Sprungziel::gp($z->gp_id)
                        : ($z->referenced_recipe_id !== null ? \Platform\FoodAlchemist\Support\Sprungziel::rezept($z->referenced_recipe_id) : null),
                    'raw_text' => $z->raw_text,
                    'display_name' => $z->display_name,
                    'quantity' => (float) $z->quantity,
                    'quantity_max' => $z->quantity_max !== null ? (float) $z->quantity_max : null,
                    'unit_vocab_id' => $z->unit_vocab_id,
                    'cooking_loss_pct' => $z->cooking_loss_pct !== null ? (float) $z->cooking_loss_pct : null,
                    'trimming_loss_pct' => $z->trimming_loss_pct !== null ? (float) $z->trimming_loss_pct : null,
                    'is_optional' => (bool) $z->is_optional,
                    'note' => $z->note,
                    'role' => $z->role,
                    'is_value_relevant' => (bool) $z->is_value_relevant,
                    'lineage' => $z->match_method?->value,
                    'ek_pro_g' => $ekProG,
                    'ek_pro_g_min' => $varianten['min'],
                    'ek_pro_g_avg' => $varianten['avg'],
                    'ersatz' => null,                                 // Äquivalenz-Katalog — gebündelt unten
                ];
            }

            // Ersatz-Hinweise (⇄ make-or-buy / Artikel-Ersatz) für ALLE Zeilen in einer Query
            $paare = collect($zeilen)
                ->map(fn ($z) => $z['gp_id'] !== null
                    ? ['gp', (int) $z['gp_id']]
                    : ($z['referenced_recipe_id'] !== null ? ['recipe', (int) $z['referenced_recipe_id']] : null))
                ->filter()->values()->all();
            $hinweise = $team !== null && $paare !== []
                ? app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)->ersatzHinweise($team, $paare)
                : [];
            foreach ($zeilen as &$z) {
                $kind = $z['gp_id'] !== null ? 'gp' : ($z['referenced_recipe_id'] !== null ? 'recipe' : null);
                $treffer = $kind !== null ? ($hinweise[$kind . ':' . (int) ($z['gp_id'] ?? $z['referenced_recipe_id'])] ?? null) : null;
                $z['ersatz'] = $treffer !== null ? $this->ersatzPayload($treffer) : null;
            }
            unset($z);
        }

        $einheiten = $team !== null
            ? FoodAlchemistVocabEinheit::visibleToTeam($team)->where('is_inactive', false)
                ->orderBy('sort_order')->get(['id', 'slug', 'display_de', 'dimension', 'default_in_g', 'default_in_ml'])
            : collect();

        // R18: Filter-Vokabulare für die Seitenspalten (klein genug für einmaliges Mitgeben;
        // der Client verengt Kategorien nach gewählter Hauptgruppe selbst)
        $db = \Illuminate\Support\Facades\DB::table('foodalchemist_lookup_commodity_groups');

        return view('foodalchemist::livewire.recipes.ingredient-editor', [
            'rezept' => $rezept,
            'zeilenJson' => $zeilen,
            'einheiten' => $einheiten,
            // Phase 5: Typ-Farben (GP / Basisrezept / Gericht) für die Seiten-Listen-Badges
            'typFarben' => $team === null
                ? \Platform\FoodAlchemist\Services\TeamSettingsService::TYP_FARBEN_DEFAULTS
                : app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->typFarben($team),
            // M9-01a: VK-Kontext zeigt die Rollen-Spalte (V-21 — Gesamt-Gericht-Sicht)
            'vkKontext' => (bool) ($rezept?->is_sales_recipe ?? false),
            'browserVokabular' => $team === null ? null : [
                'warengruppen' => TeamScope::applyVisible($db->whereNull('deleted_at'), 'team_id', $team)->orderBy('sort_order')->get(['code', 'name'])->all(),
                'subKategorien' => TeamScope::applyVisible(\Illuminate\Support\Facades\DB::table('foodalchemist_gps')
                    ->whereNull('deleted_at')->whereNotNull('sub_category'), 'team_id', $team)
                    ->distinct()->orderBy('sub_category')
                    ->get(['commodity_group_code', 'sub_category'])->all(),
                'zustande' => ['frisch', 'TK', 'trocken', 'konserviert'],
                'hauptgruppen' => TeamScope::applyVisible(\Illuminate\Support\Facades\DB::table('foodalchemist_recipe_main_groups')
                    ->whereNull('deleted_at'), 'team_id', $team)->orderBy('sort_order')->get(['id', 'label'])->all(),
                'kategorien' => TeamScope::applyVisible(\Illuminate\Support\Facades\DB::table('foodalchemist_recipe_categories')
                    ->whereNull('deleted_at'), 'team_id', $team)->orderBy('label')->get(['id', 'label', 'main_group_id'])->all(),
                'niveaus' => [['slug' => 'haute_cuisine', 'label' => 'Haute'], ['slug' => 'gehoben', 'label' => 'Gehoben'], ['slug' => 'klassisch', 'label' => 'Klassisch']],
            ],
        ]);
    }
}
