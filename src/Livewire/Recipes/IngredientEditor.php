<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\Sprungziel;
use Platform\FoodAlchemist\Support\Suche;
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
    use InteractsWithSavedToast;

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

    /**
     * MVP-046: Beim Schließen den Zeiger löschen. Vorher blieb `recipeId` nach dem Schließen
     * unbegrenzt stehen — dieser stale Zeiger war die Voraussetzung dafür, dass ein späterer,
     * fremder Speichern-Klick überhaupt ein unsichtbares Rezept treffen konnte.
     *
     * Nur die Standalone-Instanz: die eingebetteten gehören zu ihrem Editor und verschwinden
     * mit ihm; ihnen den Zeiger wegzunehmen würde ihr eigenes Speichern brechen.
     */
    #[On('modal.closed')]
    public function beiModalClosed(?string $name = null): void
    {
        if ($name === 'zutaten-editor' && ! $this->eingebettet) {
            $this->recipeId = null;
            $this->fehler = null;
        }
    }

    /**
     * @param  array<int, array>  $zeilen  kompletter Client-Stand (Reihenfolge = Position)
     * @param  int|null  $recipeId  Ziel-Rezept des Aufrufs; null = „diese Instanz" (Direktaufruf
     *                              aus dem eigenen Knopf). Ist es gesetzt und passt nicht, wird
     *                              abgewiesen.
     *
     * MVP-046 (P0): DIE Grenze gegen den Datenverlust. Vorher schrieb die Methode bedingungslos
     * auf `$this->recipeId` — und da der Speichern-Klick als ungescopter Window-Broadcast bei
     * JEDER montierten Editor-Instanz ankam, ersetzte `syncIngredients()` den kompletten
     * Zutatensatz eines Rezepts, das der Nutzer gerade nicht einmal sah.
     *
     * Der Client-Guard in der View ist Komfort; diese Prüfung ist die Grenze — ein manipulierter
     * Livewire-Call kommt hier nicht durch. Und sie schweigt nicht: eine verworfene Speicherung
     * ohne Rückmeldung wäre genau der stille Fehlschlag, den das Audit an anderer Stelle rügt.
     */
    public function speichern(array $zeilen, ?int $recipeId = null): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }

        if ($recipeId !== null && $recipeId !== $this->recipeId) {
            $this->fehler = 'Speichern verworfen: der Auftrag gehört zu einem anderen Rezept. '
                .'Bitte den Zutaten-Editor des gemeinten Rezepts benutzen.';

            return;
        }

        try {
            app(RecipeService::class)->syncIngredients($team, $this->recipeId, $zeilen);
            if (! $this->eingebettet) {
                $this->dispatch('modal.close', name: 'zutaten-editor');
            }
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('recipe-selected', id: $this->recipeId);
            $this->savedToast('Gespeichert');   // #1b: neutral — der Save deckt jetzt je Host Stammdaten+Zutaten ab
            // #511: das fehlende Glied im Live-Refresh. syncIngredients hat Kind +
            // transitive Eltern server-seitig bereits neu gerechnet (recomputeAndPropagate);
            // dieses Signal zieht die kosten-abhängigen Panels (Kalkulation, Eltern-Cockpits)
            // gezielt nach — statt sich allein aufs generische Re-Render zu verlassen.
            $betroffen = app(RecipeRecomputeService::class)->betroffeneRezepte($this->recipeId);
            $this->dispatch('kosten-aktualisiert', recipe_id: $this->recipeId, ids: $betroffen);
            // #1b (Konsolidierung): adressierte Host-Rückmeldung. Der einbettende Editor
            // (Rezept-/Gericht-Modal) schließt erst NACH erfolgreichem Zutaten-Save auf dieses
            // Signal hin — so bleibt ein Zutaten-Fehler im offenen Modal sichtbar, statt dass es
            // vorher zuklappt. Cockpit ignoriert es (kein Modal). recipeId adressiert wie MVP-046.
            $this->dispatch('zutaten-persistiert', recipeId: $this->recipeId);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * M4-11: Garverlust-Vorschläge via Gateway (GL-07: nichts persistiert —
     * Alpine merged in die rows, geschrieben wird beim Save mit source=ki).
     *
     * @param  array<int, string>  $zutaten  [index => raw_text]
     * @return array{verluste: array<int, float>, confidence: float}
     */
    public function garverlustVorschlag(array $zutaten): array
    {
        try {
            $vorschlag = app(AiGatewayService::class)
                ->propose('recipe.garverlust', ['zutaten' => $zutaten, 'verluste' => new \stdClass]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();                          // JS-Helfer: leer zurück statt 500

            return ['verluste' => [], 'confidence' => 0.0];
        }
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
        $gp = $team !== null && $gpId !== null ? app(GpService::class)->find($gpId, $team) : null;
        if ($gp === null) {
            return [];
        }
        $preise = app(PriceService::class);

        return app(GpService::class)->lasForGp($gp)
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
                        ? rtrim(rtrim(number_format((float) $la->item->qty, 2, ',', '.'), '0'), ',').' '.($la->item->packaging_unit ?? $la->item->unit_code ?? '')
                        : null,
                    'price' => $preis !== null ? number_format($preis, 2, ',', '.').' €' : null,
                    'vergleichspreis' => $vergleich !== null ? number_format($vergleich['value'], 2, ',', '.').' '.$vergleich['unit'] : null,
                    'match' => $la->structure?->main_ingredient_confidence !== null
                        ? round((float) $la->structure->main_ingredient_confidence * 100).' %'
                        : null,
                ];
            })
            ->sortByDesc('lead')->values()->all();
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
        $treffer = app(ComponentEquivalentService::class)
            ->ersatzHinweise($team, [[$kind, $id]])[$kind.':'.$id] ?? null;

        return $treffer !== null ? $this->ersatzPayload($treffer) : null;
    }

    /** Ersatz-Hinweis fürs Client-row-Format (inkl. Sprung-URL der Gegenseite). */
    private function ersatzPayload(object $treffer): array
    {
        return [
            'kind' => $treffer->kind,
            'id' => $treffer->id,
            'name' => $treffer->kind === 'recipe' ? '↳ '.$treffer->name : $treffer->name,
            'faktor' => $treffer->faktor,
            'url' => $treffer->kind === 'gp'
                ? Sprungziel::gp($treffer->id)
                : Sprungziel::rezept($treffer->id),
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
    /**
     * #9c: die im Rezept sinnvoll dosierbaren Einheiten-Slugs eines GP — Basis (Masse bzw. Volumen)
     * + händisch/KI hinterlegte Formen (Stück/Scheibe/Würfel …). Alles andere (Karton/Eimer/EL …)
     * ist ohne hinterlegte Grammatur nicht umrechenbar → fliegt aus dem Dropdown. Der Client behält
     * zusätzlich immer die aktuell gewählte Einheit sichtbar (Altbestand bricht nicht).
     *
     * @param  array<int, string>  $formSlugs
     * @return array<int, string>
     */
    /**
     * Stufe 1 der T1-Kaskade: Stück-Ertrag eines Sub-Rezepts (Yield ÷ yield_pieces).
     * Gilt bei JEDER Zähl-Einheit und schlägt alles andere — wie im Server-grammFaktor.
     * Bewusst getrennt vom GP-Stückgewicht (Stufe 4b): das galt früher gemeinsam in
     * EINEM Feld und war damit die Stelle, an der „Scheibe = ganzes Stück" entstand.
     */
    private function gProStueckSub(FoodAlchemistRecipeIngredient $z): ?float
    {
        $sub = $z->referencedRecipe;

        return $sub !== null && $sub->yield_pieces !== null && (float) $sub->yield_pieces > 0 && $sub->yield_kg !== null
            ? (float) $sub->yield_kg * 1000 / (float) $sub->yield_pieces
            : null;
    }

    private function erlaubteSlugs(bool $istFluessig, array $formSlugs, bool $hatStueck): array
    {
        $base = $istFluessig ? ['ml', 'l', 'g', 'kg'] : ['g', 'kg'];
        $slugs = array_merge($base, array_values($formSlugs), $hatStueck ? ['stk'] : []);

        return array_values(array_unique($slugs));
    }

    public function browseKatalog(array $gpFilter = [], array $rezFilter = [], string $q = ''): array
    {
        $team = Auth::user()?->currentTeamRelation;
        $leer = ['items' => [], 'total' => 0];
        if ($team === null) {
            return ['gps' => $leer, 'rezepte' => $leer];
        }
        $recompute = app(RecipeRecomputeService::class);
        $suche = mb_strtolower(trim($q));

        $gpQuery = FoodAlchemistGp::visibleToTeam($team)
            // #4 (Dominique 2026-08-27): im Zutaten-Picker nur verwendbare GPs — abgelehnte/gemergte raus
            // (GP hat keinen Entwurf-Zustand; vorläufig + freigegeben bleiben). Wie tauschKandidaten.
            ->whereNotIn('status', ['rejected', 'merged'])
            ->when($suche !== '', fn ($w) => Suche::like($w, 'name', $suche))
            ->when(($gpFilter['wg'] ?? '') !== '', fn ($w) => $w->where('commodity_group_code', $gpFilter['wg']))
            ->when(($gpFilter['sub'] ?? '') !== '', fn ($w) => $w->where('sub_category', $gpFilter['sub']))
            ->when(($gpFilter['condition'] ?? '') !== '', fn ($w) => $w->where('condition', $gpFilter['condition']))
            ->when((bool) ($gpFilter['bio'] ?? false), fn ($w) => $w->where('tag_is_organic', true))
            ->when((bool) ($gpFilter['regional'] ?? false), fn ($w) => $w->where('tag_is_regional', true))
            // 06·H4: „nur Convenience-Highlights" — verengt den Picker auf die kuratierte Haus-Liste
            ->when((bool) ($gpFilter['nur_favoriten'] ?? false), fn ($w) => $w->where('is_favorite', true));
        $gpTotal = (clone $gpQuery)->count();
        $gpModels = $gpQuery->orderBy('name')->limit(200)
            ->get(['id', 'name', 'condition', 'lead_la_supplier_item_id', 'piece_default_g', 'team_id']);
        // Performance: 30× preisProGrammPublic wären ~60 Queries je Tipper — stattdessen EINE
        // Bulk-Query (Ø €/g über aktive kg/l-LAs). Der präzise Lead-Wert kommt beim Parken nach.
        $aktiverPreis = app(PriceService::class)->activePriceSubquery()->toBase();
        $stueckGJeGp = $gpModels->filter(fn ($gp) => $gp->piece_default_g !== null && (float) $gp->piece_default_g > 0)
            ->mapWithKeys(fn ($gp) => [(int) $gp->id => (float) $gp->piece_default_g]);
        // Preis-Vorschau nur über team-sichtbare LAs (eigenes Team + Master-Kette + globaler Seed) —
        // sonst zeigt der Picker fremde Betriebs-Preise, während der Zeilen-EK team-bewusst rechnet.
        // Stk-LAs zählen mit, sobald ein Stückgewicht hinterlegt ist (GL-11-Brücke, wie
        // RecipeRecomputeService::preisProGrammMitBasis) — sonst stand ein stk-bepreister GP
        // (TK-Baguette 1,12 €/Stk) hier preislos in der Liste, obwohl der EK ihn kennt.
        $ekJeGp = TeamScope::applyVisible(
            DB::table('foodalchemist_supplier_items'),
            'foodalchemist_supplier_items.team_id', $team)
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->whereIn('s.gp_id', $gpModels->pluck('id'))->whereNull('s.deleted_at')
            ->whereIn('foodalchemist_supplier_items.unit_code', ['kg', 'l', 'Stk'])
            ->where('foodalchemist_supplier_items.qty', '>', 0)
            ->where('foodalchemist_supplier_items.is_discontinued', false)
            ->select('s.gp_id', 'foodalchemist_supplier_items.qty', 'foodalchemist_supplier_items.unit_code')
            ->selectSub($aktiverPreis, 'aktiver_preis')
            ->get()
            ->filter(fn ($r) => $r->aktiver_preis !== null)
            ->groupBy('gp_id')
            ->map(function ($g, $gpId) use ($stueckGJeGp) {
                $masse = $g->where('unit_code', '!=', 'Stk');
                if ($masse->isNotEmpty()) {                        // kg/l schlägt die Brücke
                    return $masse->avg(fn ($r) => ((float) $r->aktiver_preis) / (((float) $r->qty) * 1000));
                }
                $stueckG = $stueckGJeGp[(int) $gpId] ?? null;

                return $stueckG !== null
                    ? $g->avg(fn ($r) => ((float) $r->aktiver_preis) / (float) $r->qty / $stueckG)
                    : null;
            })
            ->filter(fn ($wert) => $wert !== null);
        // €/Stk je GP — eine Stück-Zeile ist auch ohne Stückgewicht bepreist.
        $stkJeGp = TeamScope::applyVisible(
            DB::table('foodalchemist_supplier_items'),
            'foodalchemist_supplier_items.team_id', $team)
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->whereIn('s.gp_id', $gpModels->pluck('id'))->whereNull('s.deleted_at')
            ->where('foodalchemist_supplier_items.unit_code', 'Stk')
            ->where('foodalchemist_supplier_items.qty', '>', 0)
            ->where('foodalchemist_supplier_items.is_discontinued', false)
            ->select('s.gp_id', 'foodalchemist_supplier_items.qty')
            ->selectSub($aktiverPreis, 'aktiver_preis')
            ->get()
            ->filter(fn ($r) => $r->aktiver_preis !== null)
            ->groupBy('gp_id')
            ->map(fn ($g) => $g->avg(fn ($r) => ((float) $r->aktiver_preis) / (float) $r->qty));
        // #9c (Dominique 2026-08-28): je GP die im Rezept ERLAUBTEN Einheiten (Basis + hinterlegte Formen)
        // — der Dropdown zeigt dann nur Umrechenbares, nicht alle 30 Einheiten („das verwirrt").
        $formenJeGp = FoodAlchemistGpForm::whereIn('gp_id', $gpModels->pluck('id'))
            ->get(['gp_id', 'form_slug', 'gramm'])->groupBy('gp_id');
        $formSlugsJeGp = $formenJeGp->map(fn ($g) => $g->pluck('form_slug')->all());
        $idFuerSlug = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('is_inactive', false)
            ->pluck('id', 'slug');
        $formenGJeGp = $formenJeGp->map(fn ($g) => $g
            ->filter(fn ($f) => $idFuerSlug->has($f->form_slug))
            ->mapWithKeys(fn ($f) => [(int) $idFuerSlug[$f->form_slug] => (float) $f->gramm])
            ->all());
        $gps = $gpModels
            ->map(function ($gp) use ($ekJeGp, $stkJeGp, $formSlugsJeGp, $formenGJeGp) {
                $ek = $ekJeGp[$gp->id] ?? null;
                $istFluessig = str_contains(mb_strtolower($gp->name.' '.($gp->condition ?? '')), 'fluessig');

                return [
                    'type' => 'gp', 'id' => $gp->id, 'name' => $gp->name,
                    'ek_pro_g' => $ek,
                    'preis_label' => $ek !== null ? number_format($ek * 1000, 2, ',', '.').' €/kg' : null,
                    // Spec: Einheit hängt am Produkt (Chilipulver→g, Bier→ml) — Override im Dropdown
                    'einheit_slug' => $istFluessig ? 'ml' : 'g',
                    'einheiten' => $this->erlaubteSlugs($istFluessig, $formSlugsJeGp[$gp->id] ?? [], $gp->piece_default_g !== null),
                    // Wir bieten „stk" im Dropdown an, sobald ein Stückgewicht hinterlegt ist —
                    // dann muss der Client es auch KENNEN, sonst rechnet er die Zeile mit
                    // Faktor 0 (Anteil %/Yield/EK leer), während der Save 25 Stk voll ansetzt.
                    'stueck_gewicht' => $gp->piece_default_g !== null ? (float) $gp->piece_default_g : null,
                    'formen_g' => $formenGJeGp[$gp->id] ?? [],
                    'ek_pro_stk' => $stkJeGp[$gp->id] ?? null,
                ];
            })->values()->all();

        $rezQuery = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            // #4 (Dominique 2026-08-27): im Sub-Rezept-Picker nur reife/freigegebene Basisrezepte —
            // Entwurf/Stub (in Arbeit) + Veraltet raus. Review + Freigegeben bleiben.
            ->whereNotIn('status', ['draft', 'stub', 'deprecated'])
            ->where('id', '!=', (int) $this->recipeId)
            ->when($suche !== '', fn ($w) => Suche::like($w, 'foodalchemist_recipes.name', $suche))
            ->when(($rezFilter['hg'] ?? '') !== '', fn ($w) => $w->whereHas('category', fn ($k) => $k->where('main_group_id', (int) $rezFilter['hg'])))
            ->when(($rezFilter['kat'] ?? '') !== '', fn ($w) => $w->where('category_id', (int) $rezFilter['kat']))
            ->when(($rezFilter['level'] ?? '') !== '', fn ($w) => $w->whereHas('levelSuitabilities', fn ($n) => $n->where('level_slug', $rezFilter['level'])));
        $rezTotal = (clone $rezQuery)->count();
        $rezepte = $rezQuery->with('levelSuitabilities:id,recipe_id,level_slug')->orderBy('name')->limit(200)
            ->get(['id', 'name', 'ek_per_kg_eur', 'yield_kg', 'yield_pieces'])
            ->map(function ($r) {
                $hatStueck = $r->yield_pieces !== null && (float) $r->yield_pieces > 0 && $r->yield_kg !== null;

                return [
                    'type' => 'sub', 'id' => $r->id, 'name' => '↳ '.$r->name,
                    'ek_pro_g' => $r->ek_per_kg_eur !== null ? ((float) $r->ek_per_kg_eur) / 1000 : null,
                    'preis_label' => $r->ek_per_kg_eur !== null ? number_format((float) $r->ek_per_kg_eur, 2, ',', '.').' €/kg' : null,
                    // Stück-Ertrag → Einheit beim Einfügen auf „stk" vorbelegen + g/Stück fürs Live-Rechnen
                    'einheit_slug' => $hatStueck ? 'stk' : 'g',
                    // #9c: Sub-Rezept dosiert man in g/kg (+ stk bei Stück-Ertrag) — nicht in Formen/Gebinden.
                    'einheiten' => array_values(array_filter(['g', 'kg', $hatStueck ? 'stk' : null])),
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
    /**
     * ♻-Ersatz-Tausch: Preis UND Gramm-Umrechnung des neuen Ziels in EINEM Round-Trip.
     * Der ♻-Pfad setzte g_pro_stueck bisher auf null und liess es dort — die Zeile blieb
     * nach dem Tausch auf ein Stück-Produkt rechnerisch blind (Anteil %/Yield/EK leer),
     * bis gespeichert wurde. `ekFuerZiel` bleibt als Ein-Wert-Sicht unberührt (drei
     * Aufrufer, ?float-Vertrag) und delegiert hierhin — eine Regel-Stelle.
     *
     * @return array{ek_pro_g: ?float, ek_pro_stk: ?float, g_pro_stueck: ?float, stueck_gewicht: ?float, formen_g: array<int, float>}
     */
    public function zielDaten(string $typ, int $id): array
    {
        $leer = ['ek_pro_g' => null, 'ek_pro_stk' => null, 'g_pro_stueck' => null, 'stueck_gewicht' => null, 'formen_g' => []];
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return $leer;
        }
        if ($typ === 'gp') {
            $gp = FoodAlchemistGp::visibleToTeam($team)->find($id);
            if ($gp === null) {
                return $leer;
            }
            $idFuerSlug = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('is_inactive', false)
                ->pluck('id', 'slug');

            return [
                'ek_pro_g' => app(RecipeRecomputeService::class)->preisProGrammPublic($gp, $team),
                'ek_pro_stk' => app(RecipeRecomputeService::class)->preisProStueckPublic($gp, $team),
                'g_pro_stueck' => null,                        // GP ist kein Sub-Rezept
                'stueck_gewicht' => $gp->piece_default_g !== null ? (float) $gp->piece_default_g : null,
                'formen_g' => FoodAlchemistGpForm::where('gp_id', $gp->id)->get(['form_slug', 'gramm'])
                    ->filter(fn ($f) => $idFuerSlug->has($f->form_slug))
                    ->mapWithKeys(fn ($f) => [(int) $idFuerSlug[$f->form_slug] => (float) $f->gramm])
                    ->all(),
            ];
        }
        $r = FoodAlchemistRecipe::visibleToTeam($team)->find($id);
        if ($r === null) {
            return $leer;
        }
        $hatStueck = $r->yield_pieces !== null && (float) $r->yield_pieces > 0 && $r->yield_kg !== null;

        return [
            'ek_pro_g' => $r->ek_per_kg_eur !== null ? ((float) $r->ek_per_kg_eur) / 1000 : null,
            'ek_pro_stk' => null,                                  // Sub-Rezepte tragen €/kg, kein €/Stk
            // Stück-Sub: eigener Stück-Ertrag, identisch zur Server-grammFaktor-Regel
            'g_pro_stueck' => $hatStueck ? (float) $r->yield_kg * 1000 / (float) $r->yield_pieces : null,
            'stueck_gewicht' => null,                              // Sub-Rezepte tragen kein GP-Stückgewicht
            'formen_g' => [],                                      // Formen hängen am GP, nicht am Rezept
        ];
    }

    public function ekFuerZiel(string $typ, int $id): ?float
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return null;
        }
        if ($typ === 'gp') {
            $gp = FoodAlchemistGp::visibleToTeam($team)->find($id);

            return $gp !== null ? app(RecipeRecomputeService::class)->preisProGrammPublic($gp, $team) : null;
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

        // Vokabular VOR den Zeilen: die Formen-Map unten braucht slug → unit_vocab_id.
        $einheiten = $team !== null
            ? FoodAlchemistVocabEinheit::visibleToTeam($team)->where('is_inactive', false)
                ->orderBy('sort_order')->get(['id', 'slug', 'display_de', 'dimension', 'default_in_g', 'default_in_ml'])
            : collect();
        $idFuerSlug = $einheiten->pluck('id', 'slug');

        $zeilen = [];
        if ($rezept !== null) {
            // #9c: erlaubte Einheiten je GP der Bestandszeilen (Basis + hinterlegte Formen) für den Dropdown-Filter.
            $gpIds = $rezept->ingredients->pluck('gp_id')->filter()->unique()->all();
            $formenJeGp = $gpIds !== []
                ? FoodAlchemistGpForm::whereIn('gp_id', $gpIds)->get(['gp_id', 'form_slug', 'gramm'])->groupBy('gp_id')
                : collect();
            $formSlugsJeGp = $formenJeGp->map(fn ($g) => $g->pluck('form_slug')->all());
            // Gewicht je Einheit fürs Client-Live-Rechnen — Spiegel des Server-grammFaktor,
            // der die Formen jetzt liest. Ohne diese Map käme der Client bei „scheibe" wieder
            // auf Faktor 0, während der Save korrekt 30 g ansetzt.
            $formenGJeGp = $formenJeGp->map(fn ($g) => $g
                ->filter(fn ($f) => $idFuerSlug->has($f->form_slug))
                ->mapWithKeys(fn ($f) => [(int) $idFuerSlug[$f->form_slug] => (float) $f->gramm])
                ->all());
            $preisVarianten = $recompute->preisVariantenProGrammPublic(
                $rezept->ingredients->pluck('gp')->filter()->unique('id')->values(),
                $team,
            );
            foreach ($rezept->ingredients as $z) {
                $ekProG = null;
                $ekProStk = null;
                $varianten = ['min' => null, 'avg' => null];
                if ($z->gp !== null) {
                    $gpPreise = $preisVarianten[(int) $z->gp->id] ?? ['lead' => null, 'min' => null, 'avg' => null, 'stk' => null];
                    $ekProG = $gpPreise['lead'];
                    $ekProStk = $gpPreise['stk'] ?? null;
                    $varianten = ['min' => $gpPreise['min'], 'avg' => $gpPreise['avg']];
                } elseif ($z->referencedRecipe?->ek_per_kg_eur !== null) {
                    $ekProG = ((float) $z->referencedRecipe->ek_per_kg_eur) / 1000;
                }
                $zeilen[] = [
                    'id' => $z->id,
                    'gp_id' => $z->gp_id,
                    'referenced_recipe_id' => $z->referenced_recipe_id,
                    'ziel_name' => $z->gp?->name ?? ($z->referencedRecipe !== null ? '↳ '.$z->referencedRecipe->name : null),
                    // R5: Sprung-Ziel (neuer Tab — Editor-Stand bleibt unberührt)
                    'ziel_url' => $z->gp_id !== null
                        ? Sprungziel::gp($z->gp_id)
                        : ($z->referenced_recipe_id !== null ? Sprungziel::rezept($z->referenced_recipe_id) : null),
                    'raw_text' => $z->raw_text,
                    'display_name' => $z->display_name,
                    'quantity' => (float) $z->quantity,
                    'quantity_max' => $z->quantity_max !== null ? (float) $z->quantity_max : null,
                    'unit_vocab_id' => $z->unit_vocab_id,
                    // #9c: erlaubte Einheiten dieser Zeile (nur GP-Zeilen; Sub/Frei → null = alle als Fallback)
                    'einheiten' => $z->gp_id !== null
                        ? $this->erlaubteSlugs(
                            str_contains(mb_strtolower(($z->gp->name ?? '').' '.($z->gp->condition ?? '')), 'fluessig'),
                            $formSlugsJeGp[$z->gp_id] ?? [],
                            ($z->gp?->piece_default_g) !== null,
                        )
                        : null,
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
                    // g/Stück fürs Live-Rechnen bei Zähl-Einheiten — GP: hinterlegtes
                    // Stückgewicht, Sub-Rezept: eigener Stück-Ertrag (Yield ÷ yield_pieces).
                    // Spiegelt RecipeRecomputeService::grammFaktor; fehlte hier komplett,
                    // deshalb waren Anteil %/Yield/EK einer stk-Zeile leer.
                    // T1-Kaskade, in Stufen getrennt gespiegelt (siehe gFaktor im Blade):
                    'g_pro_stueck' => $this->gProStueckSub($z),          // 1: Sub-Stück-Ertrag
                    'stueck_gewicht' => $z->gp?->piece_default_g !== null // 4b: NUR bei „stk"
                        ? (float) $z->gp->piece_default_g : null,
                    'formen_g' => $z->gp_id !== null ? ($formenGJeGp[$z->gp_id] ?? []) : [],
                    // €/Stück fürs gewichtsfreie Stück-Rechnen (Spiegel zutatKosten count-Zweig)
                    'ek_pro_stk' => $ekProStk,
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
                ? app(ComponentEquivalentService::class)->ersatzHinweise($team, $paare)
                : [];
            foreach ($zeilen as &$z) {
                $kind = $z['gp_id'] !== null ? 'gp' : ($z['referenced_recipe_id'] !== null ? 'recipe' : null);
                $treffer = $kind !== null ? ($hinweise[$kind.':'.(int) ($z['gp_id'] ?? $z['referenced_recipe_id'])] ?? null) : null;
                $z['ersatz'] = $treffer !== null ? $this->ersatzPayload($treffer) : null;
            }
            unset($z);
        }

        // R18: Filter-Vokabulare für die Seitenspalten (klein genug für einmaliges Mitgeben;
        // der Client verengt Kategorien nach gewählter Hauptgruppe selbst)
        $db = DB::table('foodalchemist_lookup_commodity_groups');

        return view('foodalchemist::livewire.recipes.ingredient-editor', [
            'rezept' => $rezept,
            'zeilenJson' => $zeilen,
            'einheiten' => $einheiten,
            // Phase 5: Typ-Farben (GP / Basisrezept / Gericht) für die Seiten-Listen-Badges
            'typFarben' => $team === null
                ? TeamSettingsService::TYP_FARBEN_DEFAULTS
                : app(TeamSettingsService::class)->typFarben($team),
            // M9-01a: VK-Kontext zeigt die Rollen-Spalte (V-21 — Gesamt-Gericht-Sicht)
            'vkKontext' => (bool) ($rezept?->is_sales_recipe ?? false),
            'browserVokabular' => $team === null ? null : [
                'warengruppen' => TeamScope::applyVisible($db->whereNull('deleted_at'), 'team_id', $team)->orderBy('sort_order')->get(['code', 'name'])->all(),
                'subKategorien' => TeamScope::applyVisible(DB::table('foodalchemist_gps')
                    ->whereNull('deleted_at')->whereNotNull('sub_category'), 'team_id', $team)
                    ->distinct()->orderBy('sub_category')
                    ->get(['commodity_group_code', 'sub_category'])->all(),
                'zustande' => ['frisch', 'TK', 'trocken', 'konserviert'],
                'hauptgruppen' => TeamScope::applyVisible(DB::table('foodalchemist_recipe_main_groups')
                    ->whereNull('deleted_at'), 'team_id', $team)->orderBy('sort_order')->get(['id', 'label'])->all(),
                'kategorien' => TeamScope::applyVisible(DB::table('foodalchemist_recipe_categories')
                    ->whereNull('deleted_at'), 'team_id', $team)->orderBy('label')->get(['id', 'label', 'main_group_id'])->all(),
                'niveaus' => [['slug' => 'haute_cuisine', 'label' => 'Haute'], ['slug' => 'gehoben', 'label' => 'Gehoben'], ['slug' => 'klassisch', 'label' => 'Klassisch']],
            ],
        ]);
    }
}
