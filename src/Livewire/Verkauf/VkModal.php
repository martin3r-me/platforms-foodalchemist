<?php

namespace Platform\FoodAlchemist\Livewire\Verkauf;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * M6-04 / D-6 §4.2–4.5: VK-Editor — Anlage-Modus (»VK aus Basisrezept«,
 * DoD) + Edit-Modus mit Sektionen Stammdaten/VK-Wording, Klassifikation
 * (HG → Klasse zweistufig), Verkaufseinheit (Anzahl primär ⇒ g/Einheit
 * abgeleitet), Verkaufs-Block (AK, MwSt, VK-netto-manuell mit Vorschlags-
 * Vorschau), Container (Behälter warm/kalt + Vehikel), Regeneration
 * (V-19-Zeilen-CRUD + ↑↓) und Verwendungsnachweise. Schreibpfade laufen
 * durch SalesRecipeService (V-07-Transaktionen, V-12-Feld-Gate).
 * ✨-KI-Aktionen (Wording/Marketing/Klassifizieren) folgen mit M6-05.
 */
class VkModal extends Component
{
    public ?int $recipeId = null;                                    // null = Anlage-Modus

    /** @var array<string, mixed> */
    public array $form = [];

    public ?int $hauptgruppeId = null;                               // UI-Kaskade für den Klassen-Select

    // Anlage-Modus
    public string $neuName = '';

    public string $basisSuche = '';

    public ?int $basisId = null;

    // V-19-Regen-Zeile (Formular)
    /** @var array<string, mixed> */
    public array $regenForm = [];

    public ?int $regenEditId = null;

    // Verwendungsnachweis
    public string $kundeName = '';

    public string $kundeMarketing = '';

    public ?string $fehler = null;

    #[On('vk-modal.oeffnen')]
    public function oeffnen(?int $id = null): void
    {
        $this->formZuruecksetzen();
        $this->recipeId = $id;
        $this->fehler = null;
        if ($id !== null) {
            $team = Auth::user()?->currentTeamRelation;
            $r = $team !== null ? app(SalesRecipeService::class)->detail($team, $id) : null;
            if ($r === null) {
                return;
            }
            $this->form = [
                'name' => $r->name,
                'vk_wording_standard' => $r->vk_wording_standard,
                'geschmacksrichtung' => $r->geschmacksrichtung,
                'speisen_klasse_id' => $r->speisen_klasse_id,
                'aufschlagsklasse_id' => $r->aufschlagsklasse_id,
                'mwst_satz' => $r->mwst_satz,
                'vk_netto' => $r->vk_netto,
                'vk_einheit_vocab_id' => $r->vk_einheit_vocab_id,
                'vk_anzahl_einheiten' => $r->vk_anzahl_einheiten,
                'vk_menge_pro_einheit_g' => $r->vk_menge_pro_einheit_g,
                'behaelter_warm_vocab_id' => $r->behaelter_warm_vocab_id,
                'behaelter_warm_anzahl' => $r->behaelter_warm_anzahl,
                'behaelter_kalt_vocab_id' => $r->behaelter_kalt_vocab_id,
                'behaelter_kalt_anzahl' => $r->behaelter_kalt_anzahl,
                'servier_vehikel_vocab_id' => $r->servier_vehikel_vocab_id,
            ];
            $this->hauptgruppeId = $r->speisenKlasse?->dish_main_group_id;
        }
        $this->dispatch('modal.open', name: 'vk-modal');
    }

    // P-2-State-Leak-Schutz wie RecipeModal/GeneratorModal: Reset beim ÖFFNEN
    // (modal.closed ist ein Alpine-window-Event, das Livewire nicht erreicht)
    private function formZuruecksetzen(): void
    {
        $this->reset(['recipeId', 'form', 'hauptgruppeId', 'neuName', 'basisSuche', 'basisId', 'regenForm', 'regenEditId', 'kundeName', 'kundeMarketing', 'fehler']);
    }

    public function updatedHauptgruppeId(): void
    {
        $this->form['speisen_klasse_id'] = null;                     // Kaskade Reset-korrekt (§4.1)
    }

    public function anlegen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || trim($this->neuName) === '' || $this->basisId === null) {
            $this->fehler = 'Name und Basisrezept wählen.';

            return;
        }
        try {
            $vk = app(SalesRecipeService::class)->createFromBasis($team, $this->basisId, trim($this->neuName));
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->oeffnen($vk->id);                                     // direkt in den Edit-Modus
        $this->dispatch('recipe-gespeichert');
        $this->dispatch('vk-recipe-selected', id: $vk->id);
    }

    public function speichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            app(SalesRecipeService::class)->updateVk($team, $this->recipeId, array_map(
                fn ($v) => $v === '' ? null : $v, $this->form,
            ));
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('recipe-gespeichert');
        $this->dispatch('modal.close', name: 'vk-modal');
    }

    // ── V-19: Regen-Zeilen ───────────────────────────────────────────────

    public function regenBearbeiten(int $id): void
    {
        $zeile = DB::table('foodalchemist_recipe_regenerations')->where('id', $id)->where('recipe_id', $this->recipeId)->first();
        if ($zeile !== null) {
            $this->regenEditId = $id;
            $this->regenForm = [
                'komponente_label' => $zeile->komponente_label, 'geraet_vocab_id' => $zeile->geraet_vocab_id,
                'temp_c' => $zeile->temp_c, 'dauer_min' => $zeile->dauer_min, 'kerntemp_c' => $zeile->kerntemp_c,
                'hinweis' => $zeile->hinweis,
            ];
        }
    }

    public function regenSpeichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        app(SalesRecipeService::class)->upsertRegeneration($team, $this->recipeId, array_map(
            fn ($v) => $v === '' ? null : $v, $this->regenForm,
        ), $this->regenEditId);
        $this->regenForm = [];
        $this->regenEditId = null;
    }

    public function regenLoeschen(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->recipeId !== null) {
            app(SalesRecipeService::class)->deleteRegeneration($team, $this->recipeId, $id);
        }
    }

    public function regenSchieben(int $id, int $richtung): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $ids = DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $this->recipeId)
            ->whereNull('deleted_at')->orderBy('sort_order')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        $ziel = $pos !== false ? $pos + $richtung : false;
        if ($pos === false || $ziel < 0 || $ziel >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];
        app(SalesRecipeService::class)->reorderRegenerations($team, $this->recipeId, $ids);
    }

    // ── Verwendungsnachweise ─────────────────────────────────────────────

    public function kundeHinzufuegen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || trim($this->kundeName) === '' || trim($this->kundeMarketing) === '') {
            return;
        }
        app(SalesRecipeService::class)->addCustomerName($team, $this->recipeId, $this->kundeName, $this->kundeMarketing);
        $this->kundeName = '';
        $this->kundeMarketing = '';
    }

    public function kundeLoeschen(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->recipeId !== null) {
            app(SalesRecipeService::class)->deleteCustomerName($team, $this->recipeId, $id);
        }
    }

    public function render(SalesRecipeService $verkauf)
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null ? $verkauf->detail($team, $this->recipeId) : null;

        return view('foodalchemist::livewire.verkauf.vk-modal', [
            'rezept' => $rezept,
            'cockpit' => $rezept !== null ? $verkauf->cockpit($rezept) : null,
            'hauptgruppen' => $team !== null ? $verkauf->dishMainGroups($team) : collect(),
            'klassen' => $this->hauptgruppeId !== null
                ? FoodAlchemistDishClass::where('dish_main_group_id', $this->hauptgruppeId)->orderBy('bezeichnung')->get(['id', 'bezeichnung', 'diaetform'])
                : collect(),
            'aufschlagsklassen' => FoodAlchemistMarkupClass::where('is_inactive', false)->orderBy('code')->get(['id', 'code', 'bezeichnung', 'rohaufschlag_pct', 'formel_typ']),
            'einheiten' => $team !== null ? FoodAlchemistVocabEinheit::visibleToTeam($team)->where('is_inactive', false)->orderBy('slug')->get(['id', 'slug', 'display_de']) : collect(),
            'behaelter' => DB::table('foodalchemist_vocab_behaelter')->whereNull('deleted_at')->orderBy('gruppe')->orderBy('sort_order')->get(['id', 'name', 'gruppe', 'is_inactive']),
            'geraete' => DB::table('foodalchemist_vocab_regen_geraete')->whereNull('deleted_at')->orderBy('sort_order')->get(['id', 'name', 'is_inactive']),
            'vehikel' => DB::table('foodalchemist_vocab_serviervehikel')->whereNull('deleted_at')->orderBy('gruppe')->orderBy('sort_order')->get(['id', 'name', 'gruppe', 'is_inactive']),
            'regenZeilen' => $this->recipeId !== null
                ? DB::table('foodalchemist_recipe_regenerations AS rr')
                    ->leftJoin('foodalchemist_vocab_regen_geraete AS g', 'g.id', '=', 'rr.geraet_vocab_id')
                    ->where('rr.recipe_id', $this->recipeId)->whereNull('rr.deleted_at')
                    ->orderBy('rr.sort_order')->get(['rr.id', 'rr.komponente_label', 'rr.temp_c', 'rr.dauer_min', 'rr.kerntemp_c', 'rr.hinweis', 'g.name AS geraet'])
                : collect(),
            'kunden' => $this->recipeId !== null
                ? DB::table('foodalchemist_recipe_customer_names')->where('recipe_id', $this->recipeId)->whereNull('deleted_at')->orderBy('customer_name')->get()
                : collect(),
            'basisTreffer' => $this->recipeId === null && trim($this->basisSuche) !== '' && $team !== null
                ? FoodAlchemistRecipe::visibleToTeam($team)->basis()
                    ->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower(trim($this->basisSuche)) . '%'])
                    ->orderBy('name')->limit(6)->get(['id', 'name', 'yield_kg', 'ek_total_eur'])
                : collect(),
        ]);
    }
}
