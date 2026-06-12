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
     * Alpine merged in die rows, geschrieben wird beim Save mit quelle=ki).
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
                    'bezeichnung' => $la->item?->designation ?? '—',
                    'marke' => $la->item?->brand ?? null,
                    'vpe' => $la->item?->qty !== null
                        ? rtrim(rtrim(number_format((float) $la->item->qty, 2, ',', '.'), '0'), ',') . ' ' . ($la->item->packaging_unit ?? $la->item->unit_code ?? '')
                        : null,
                    'preis' => $preis !== null ? number_format($preis, 2, ',', '.') . ' €' : null,
                    'vergleichspreis' => $vergleich !== null ? number_format($vergleich['wert'], 2, ',', '.') . ' ' . $vergleich['einheit'] : null,
                    'match' => $la->structure?->hauptzutat_konfidenz !== null
                        ? round((float) $la->structure->hauptzutat_konfidenz * 100) . ' %'
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
            ->when($suche !== '', fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', ['%' . $suche . '%']))
            ->when(($gpFilter['wg'] ?? '') !== '', fn ($w) => $w->where('warengruppe_code', $gpFilter['wg']))
            ->when(($gpFilter['sub'] ?? '') !== '', fn ($w) => $w->where('sub_kategorie', $gpFilter['sub']))
            ->when(($gpFilter['zustand'] ?? '') !== '', fn ($w) => $w->where('zustand', $gpFilter['zustand']))
            ->when((bool) ($gpFilter['bio'] ?? false), fn ($w) => $w->where('is_organic', true))
            ->when((bool) ($gpFilter['regional'] ?? false), fn ($w) => $w->where('is_regional', true));
        $gpTotal = (clone $gpQuery)->count();
        $gpModels = $gpQuery->orderBy('name')->limit(30)
            ->get(['id', 'name', 'zustand', 'lead_la_supplier_item_id', 'stk_default_g', 'team_id']);
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
                    'typ' => 'gp', 'id' => $gp->id, 'name' => $gp->name,
                    'ek_pro_g' => $ek,
                    'preis_label' => $ek !== null ? number_format($ek * 1000, 2, ',', '.') . ' €/kg' : null,
                    // Spec: Einheit hängt am Produkt (Chilipulver→g, Bier→ml) — Override im Dropdown
                    'einheit_slug' => str_contains(mb_strtolower($gp->name . ' ' . ($gp->zustand ?? '')), 'fluessig') ? 'ml' : 'g',
                ];
            })->values()->all();

        $rezQuery = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->where('id', '!=', (int) $this->recipeId)
            ->when($suche !== '', fn ($w) => $w->whereRaw('LOWER(foodalchemist_recipes.name) LIKE ?', ['%' . $suche . '%']))
            ->when(($rezFilter['hg'] ?? '') !== '', fn ($w) => $w->whereHas('kategorie', fn ($k) => $k->where('main_group_id', (int) $rezFilter['hg'])))
            ->when(($rezFilter['kat'] ?? '') !== '', fn ($w) => $w->where('kategorie_id', (int) $rezFilter['kat']))
            ->when(($rezFilter['niveau'] ?? '') !== '', fn ($w) => $w->whereHas('niveauEignungen', fn ($n) => $n->where('niveau_slug', $rezFilter['niveau'])));
        $rezTotal = (clone $rezQuery)->count();
        $rezepte = $rezQuery->with('niveauEignungen:id,recipe_id,niveau_slug')->orderBy('name')->limit(30)
            ->get(['id', 'name', 'ek_per_kg_eur'])
            ->map(fn ($r) => [
                'typ' => 'sub', 'id' => $r->id, 'name' => '↳ ' . $r->name,
                'ek_pro_g' => $r->ek_per_kg_eur !== null ? ((float) $r->ek_per_kg_eur) / 1000 : null,
                'preis_label' => $r->ek_per_kg_eur !== null ? number_format((float) $r->ek_per_kg_eur, 2, ',', '.') . ' €/kg' : null,
                'einheit_slug' => 'g',
                'niveaus' => $r->niveauEignungen->pluck('niveau_slug')->values()->all(),
            ])->values()->all();

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
                    'menge' => (float) $z->menge,
                    'menge_max' => $z->menge_max !== null ? (float) $z->menge_max : null,
                    'einheit_vocab_id' => $z->einheit_vocab_id,
                    'garverlust_pct' => $z->garverlust_pct !== null ? (float) $z->garverlust_pct : null,
                    'putzverlust_pct' => $z->putzverlust_pct !== null ? (float) $z->putzverlust_pct : null,
                    'is_optional' => (bool) $z->is_optional,
                    'note' => $z->note,
                    'rolle' => $z->rolle,
                    'ist_wertgebend' => (bool) $z->ist_wertgebend,
                    'lineage' => $z->match_method?->value,
                    'ek_pro_g' => $ekProG,
                    'ek_pro_g_min' => $varianten['min'],
                    'ek_pro_g_avg' => $varianten['avg'],
                ];
            }
        }

        $einheiten = $team !== null
            ? FoodAlchemistVocabEinheit::visibleToTeam($team)->where('is_inactive', false)
                ->orderBy('sort_order')->get(['id', 'slug', 'display_de', 'dimension', 'default_in_g', 'default_in_ml'])
            : collect();

        // R18: Filter-Vokabulare für die Seitenspalten (klein genug für einmaliges Mitgeben;
        // der Client verengt Kategorien nach gewählter Hauptgruppe selbst)
        $db = \Illuminate\Support\Facades\DB::table('foodalchemist_lookup_warengruppen');

        return view('foodalchemist::livewire.recipes.ingredient-editor', [
            'rezept' => $rezept,
            'zeilenJson' => $zeilen,
            'einheiten' => $einheiten,
            // M9-01a: VK-Kontext zeigt die Rollen-Spalte (V-21 — Gesamt-Gericht-Sicht)
            'vkKontext' => (bool) ($rezept?->ist_verkaufsrezept ?? false),
            'browserVokabular' => $team === null ? null : [
                'warengruppen' => $db->whereNull('deleted_at')->orderBy('sort_order')->get(['code', 'name'])->all(),
                'subKategorien' => \Illuminate\Support\Facades\DB::table('foodalchemist_gps')
                    ->whereNull('deleted_at')->whereNotNull('sub_kategorie')
                    ->distinct()->orderBy('sub_kategorie')
                    ->get(['warengruppe_code', 'sub_kategorie'])->all(),
                'zustande' => ['frisch', 'TK', 'trocken', 'konserviert'],
                'hauptgruppen' => \Illuminate\Support\Facades\DB::table('foodalchemist_recipe_main_groups')
                    ->whereNull('deleted_at')->orderBy('sort_order')->get(['id', 'bezeichnung'])->all(),
                'kategorien' => \Illuminate\Support\Facades\DB::table('foodalchemist_recipe_categories')
                    ->whereNull('deleted_at')->orderBy('bezeichnung')->get(['id', 'bezeichnung', 'main_group_id'])->all(),
                'niveaus' => [['slug' => 'haute_cuisine', 'label' => 'Haute'], ['slug' => 'gehoben', 'label' => 'Gehoben'], ['slug' => 'klassisch', 'label' => 'Klassisch']],
            ],
        ]);
    }
}
