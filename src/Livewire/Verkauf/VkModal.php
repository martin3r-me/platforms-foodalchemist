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
                // M9-01: Voll-Editor-Parität (Texte/Eigenschaften/Plating/Notizen)
                'marketing_text' => $r->marketing_text,
                'beschreibung' => $r->beschreibung,
                'arbeitszeit_min' => $r->arbeitszeit_min,
                'nebenkosten_eur' => $r->nebenkosten_eur,             // M-K8: direkte Einzelkosten → HK2 (#379)
                'temperatur' => $r->temperatur,
                'funktion' => $r->funktion,
                'fertigungstiefe' => $r->fertigungstiefe,
                'plating_text' => $r->plating_text,
                'notizen_manual' => $r->notizen_manual,
            ];
            $this->hauptgruppeId = $r->speisenKlasse?->dish_main_group_id;
        }
        $this->dispatch('modal.open', name: 'vk-modal');
    }

    // P-2-State-Leak-Schutz wie RecipeModal/GeneratorModal: Reset beim ÖFFNEN
    // (modal.closed ist ein Alpine-window-Event, das Livewire nicht erreicht)
    private function formZuruecksetzen(): void
    {
        $this->reset(['recipeId', 'form', 'hauptgruppeId', 'neuName', 'basisSuche', 'basisId', 'regenForm', 'regenEditId', 'kundeName', 'kundeMarketing', 'fehler', 'rollenVorschlag', 'regenVorschlaege']);
    }

    public function updatedHauptgruppeId(): void
    {
        $this->form['speisen_klasse_id'] = null;                     // Kaskade Reset-korrekt (§4.1)
    }

    public function anlegen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || trim($this->neuName) === '') {
            $this->fehler = 'Bitte einen Namen eingeben.';

            return;
        }
        try {
            // Basisrezept ist OPTIONAL: mit → ganze Charge als erste Komponente; ohne → leeres Gericht.
            $svc = app(SalesRecipeService::class);
            $vk = $this->basisId !== null
                ? $svc->createFromBasis($team, $this->basisId, trim($this->neuName))
                : $svc->createLeer($team, trim($this->neuName));
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

    // ── M9-01i: ✨-Aktionen — Vorschlag in die Form-Felder (Save = Accept, RecipeModal-Muster) ──

    /** Re-Mount-Zähler für den eingebetteten Zutaten-Editor (Client-rows). */
    public int $zutatenVersion = 0;

    /** @var ?array{rollen: array<int, string>, confidence: float, begruendung: ?string} */
    public ?array $rollenVorschlag = null;

    public function ki(string $aktion): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehler = null;
        $r = app(SalesRecipeService::class)->detail($team, $this->recipeId);
        $gateway = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class);
        $kontext = [
            'name' => $this->form['name'] ?? $r->name,
            'vk_wording_standard' => $this->form['vk_wording_standard'] ?? null,
            'komponenten' => $r->ingredients->map(fn ($z) => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name)->all(),
            'speisen_klasse' => $r->speisenKlasse?->bezeichnung,
        ];

        try {
            match ($aktion) {
                'wording' => $this->uebernehmeText('vk_wording_standard', $gateway->propose('vk.wording', $kontext)),
                'marketing' => $this->uebernehmeText('marketing_text', $gateway->propose('vk.marketing', $kontext)),
                'plating' => $this->uebernehmePlating($gateway->propose('vk.plating', $kontext + ['portion_g' => $this->form['vk_menge_pro_einheit_g'] ?? null])),
                'eigenschaften' => $this->uebernehmeEigenschaften($gateway, $kontext),
                'behaelter' => $this->uebernehmeBehaelter($gateway->propose('vk.behaelter', $kontext + [
                    'vokabular' => DB::table('foodalchemist_vocab_behaelter')->whereNull('deleted_at')->where('is_inactive', false)->pluck('name', 'id')->all(),
                ])),
                'vehikel' => $this->uebernehmeVehikel($gateway->propose('vk.servier_vehikel', $kontext + [
                    'vokabular' => DB::table('foodalchemist_vocab_serviervehikel')->whereNull('deleted_at')->where('is_inactive', false)->pluck('name', 'id')->all(),
                ])),
                default => null,
            };
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function uebernehmeText(string $feld, \Platform\FoodAlchemist\Services\Ai\AiProposal $v): void
    {
        $wert = $v->werte[$feld] ?? null;
        if (is_string($wert) && trim($wert) !== '') {
            $this->form[$feld] = trim($wert);
        } else {
            $this->fehler = 'KI lieferte keinen verwertbaren Text — echter Provider nötig.';
        }
    }

    private function uebernehmePlating(\Platform\FoodAlchemist\Services\Ai\AiProposal $v): void
    {
        $wert = $v->werte['zubereitung'] ?? $v->werte['plating_text'] ?? null;   // Registry-Schema: {zubereitung}
        if (is_string($wert) && trim($wert) !== '') {
            $this->form['plating_text'] = trim($wert);
        } else {
            $this->fehler = 'KI lieferte keinen verwertbaren Plating-Text — echter Provider nötig.';
        }
    }

    private function uebernehmeEigenschaften(\Platform\FoodAlchemist\Services\Ai\AiGatewayService $ki, array $kontext): void
    {
        $v = $ki->propose('recipe.eigenschaften', $kontext + [
            'arbeitszeit_min' => $this->form['arbeitszeit_min'] ?? null,
            'temperatur' => $this->form['temperatur'] ?? null,
            'funktion' => $this->form['funktion'] ?? null,
        ]);
        foreach (['arbeitszeit_min', 'temperatur', 'funktion'] as $feld) {
            if (! empty($v->werte[$feld])) {
                $this->form[$feld] = $v->werte[$feld];
            }
        }
        $g = $ki->propose('recipe.geschmack', $kontext + ['geschmacksrichtung' => $this->form['geschmacksrichtung'] ?? null]);
        if (in_array($g->werte['geschmacksrichtung'] ?? null, ['suess', 'herzhaft', 'neutral'], true)) {
            $this->form['geschmacksrichtung'] = $g->werte['geschmacksrichtung'];
        }
    }

    private function uebernehmeBehaelter(\Platform\FoodAlchemist\Services\Ai\AiProposal $v): void
    {
        $gueltig = DB::table('foodalchemist_vocab_behaelter')->whereNull('deleted_at')->pluck('id')->flip();
        $gesetzt = false;
        foreach (['warm', 'kalt'] as $seite) {
            $id = $v->werte["behaelter_{$seite}_id"] ?? null;
            if ($id !== null && isset($gueltig[(int) $id])) {
                $this->form["behaelter_{$seite}_vocab_id"] = (int) $id;
                $anzahl = $v->werte["behaelter_{$seite}_anzahl"] ?? null;
                $this->form["behaelter_{$seite}_anzahl"] = is_numeric($anzahl) ? (int) $anzahl : null;
                $gesetzt = true;
            }
        }
        if (! $gesetzt) {
            $this->fehler = 'KI lieferte keinen gültigen Behälter-Vorschlag — echter Provider nötig.';
        }
    }

    private function uebernehmeVehikel(\Platform\FoodAlchemist\Services\Ai\AiProposal $v): void
    {
        $id = $v->werte['servier_vehikel_id'] ?? null;
        if ($id !== null && DB::table('foodalchemist_vocab_serviervehikel')->whereNull('deleted_at')->where('id', (int) $id)->exists()) {
            $this->form['servier_vehikel_vocab_id'] = (int) $id;
        } else {
            $this->fehler = 'KI lieferte kein gültiges Servier-Vehikel — echter Provider nötig.';
        }
    }

    // ── M9-01i: ✨ Regeneration — Programm-Liste als Vorschlag, Übernahme je Zeile (GL-07) ──

    /** @var array<int, array> */
    public array $regenVorschlaege = [];

    public function kiRegeneration(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehler = null;
        $r = app(SalesRecipeService::class)->detail($team, $this->recipeId);
        $v = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('vk.regeneration', [
            'name' => $r->name,
            'komponenten' => $r->ingredients->map(fn ($z) => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name)->all(),
            'vokabular' => DB::table('foodalchemist_vocab_regen_geraete')->whereNull('deleted_at')->where('is_inactive', false)->pluck('name', 'id')->all(),
        ]);
        $gueltig = DB::table('foodalchemist_vocab_regen_geraete')->whereNull('deleted_at')->pluck('id')->flip();
        $this->regenVorschlaege = collect((array) ($v->werte['programme'] ?? []))
            ->filter(fn ($z) => is_array($z) && ! empty($z['komponente_label']))
            ->map(fn ($z) => [
                'komponente_label' => (string) $z['komponente_label'],
                'geraet_vocab_id' => isset($z['geraet_id']) && isset($gueltig[(int) $z['geraet_id']]) ? (int) $z['geraet_id'] : null,
                'temp_c' => is_numeric($z['temp_c'] ?? null) ? (int) $z['temp_c'] : null,
                'dauer_min' => is_numeric($z['dauer_min'] ?? null) ? (int) $z['dauer_min'] : null,
                'kerntemp_c' => is_numeric($z['kerntemp_c'] ?? null) ? (int) $z['kerntemp_c'] : null,
                'hinweis' => is_string($z['hinweis'] ?? null) ? $z['hinweis'] : null,
            ])->values()->all();
        if ($this->regenVorschlaege === []) {
            $this->fehler = 'KI lieferte keine verwertbaren Regenerations-Programme — echter Provider nötig.';
        }
    }

    public function regenVorschlagUebernehmen(int $idx): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $zeile = $this->regenVorschlaege[$idx] ?? null;
        if ($team === null || $this->recipeId === null || $zeile === null) {
            return;
        }
        app(SalesRecipeService::class)->upsertRegeneration($team, $this->recipeId, $zeile, null);
        unset($this->regenVorschlaege[$idx]);
        $this->regenVorschlaege = array_values($this->regenVorschlaege);
    }

    // ── M9-01a: 🎭 Rollen verteilen (V-21) — Proposal-Box über den Zutaten ──

    public function ai_rollen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehler = null;
        $this->rollenVorschlag = app(\Platform\FoodAlchemist\Services\SpeisenKlassenService::class)
            ->verteileRollen($team, $this->recipeId);
    }

    public function accept_rollen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || $this->rollenVorschlag === null || $this->rollenVorschlag['rollen'] === []) {
            return;
        }
        app(\Platform\FoodAlchemist\Services\SpeisenKlassenService::class)
            ->acceptRollen($team, $this->recipeId, $this->rollenVorschlag['rollen']);
        $this->rollenVorschlag = null;
        $this->zutatenVersion++;                                     // Editor-rows neu vom Server
        $this->dispatch('recipe-gespeichert');
    }

    public function reject_rollen(): void
    {
        $this->rollenVorschlag = null;
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

    /** ✨ Sensorik: KI bewertet das GEGARTE Gericht (Zutaten + Zubereitung) → Recipe-Sensorik-Tabellen. */
    public function sensorikBewerten(): void
    {
        if ($this->recipeId !== null) {
            app(\Platform\FoodAlchemist\Services\SensorikService::class)->bewerteRezept($this->recipeId, true);
        }
    }

    public function render(SalesRecipeService $verkauf)
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null ? $verkauf->detail($team, $this->recipeId) : null;

        // M9-01d/e: Nährwerte pro Stück + Bio-/Regional-Anteil (Gramm-gewichtet über GP-Tags)
        $gProStueck = null;
        $anteile = ['bio' => null, 'regional' => null];
        if ($rezept !== null) {
            $cockpitTmp = $verkauf->cockpit($rezept);
            $gProStueck = $cockpitTmp['verkauft_als']['g_pro_einheit'] ?? null;
            $totalG = 0.0;
            $summen = ['bio' => 0.0, 'regional' => 0.0];
            foreach ($rezept->ingredients as $z) {
                $faktor = (float) ($z->einheit?->default_in_g ?? $z->einheit?->default_in_ml ?? 0);
                $g = (float) $z->menge * $faktor;
                if ($g <= 0 || $z->is_optional) {
                    continue;
                }
                $totalG += $g;
                if ($z->gp?->is_organic) {
                    $summen['bio'] += $g;
                }
                if ($z->gp?->is_regional) {
                    $summen['regional'] += $g;
                }
            }
            if ($totalG > 0) {
                $anteile = ['bio' => round(100 * $summen['bio'] / $totalG, 1), 'regional' => round(100 * $summen['regional'] / $totalG, 1)];
            }
        }

        return view('foodalchemist::livewire.verkauf.vk-modal', [
            'rezept' => $rezept,
            'cockpit' => $rezept !== null ? ($cockpitTmp ?? $verkauf->cockpit($rezept)) : null,
            'gProStueck' => $gProStueck,
            'anteile' => $anteile,
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
            'sensorik' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\SensorikService::class)->fuerRezept($rezept->id) : null,
            'komposition' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\SensorikService::class)->gerichtKomposition($rezept->id) : null,
            'pairing' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\PairingService::class)->panelRecipe($rezept) : null,
        ]);
    }
}
