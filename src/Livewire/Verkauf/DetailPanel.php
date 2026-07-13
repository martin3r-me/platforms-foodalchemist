<?php

namespace Platform\FoodAlchemist\Livewire\Verkauf;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * M6-03 / 13_REFERENZ N2: VK-DetailPanel — Titel + Komponentenliste, Status/
 * HG/Diät, VERKAUFT-ALS-Box (Orange), KPI-Karten (EK GESAMT · VK NETTO mit
 * Quelle · VK BRUTTO Highlight · WARENEINSATZ % · Reihe 2 pro Einheit),
 * Formel-Klartext aus der Klasse, Beschreibung/Marketing, Zutaten-Kurzliste.
 * Alle Zahlen aus SalesRecipeService::cockpit (MargeService Single-Source).
 * ✨ Klassifizieren + Kohärenz-Check folgen mit M6-05.
 */
class DetailPanel extends Component
{
    public ?int $recipeId = null;

    public function mount(?int $recipeId = null): void
    {
        $this->recipeId = $recipeId;
    }

    #[On('vk-recipe-selected')]
    public function zeige(int $id): void
    {
        $this->recipeId = $id;
    }

    #[On('recipe-gespeichert')]
    public function aktualisiere(): void
    {
        // Editor-Save → Cockpit neu rendern (Kontext bleibt)
    }

    // ── M6-05: GL-07-Proposal-Flow (Klassifikation + Rollen) ────────────

    /** @var ?array{klasse_id: ?int, klasse_name: ?string, confidence: float, reasoning: ?string} */
    public ?array $klasseVorschlag = null;

    /** @var ?array{rollen: array<int, string>, confidence: float, reasoning: ?string} */
    public ?array $rollenVorschlag = null;

    public ?string $kiFehler = null;

    public function ai_klassifizieren(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->kiFehler = null;
        try {
            $this->klasseVorschlag = app(\Platform\FoodAlchemist\Services\SpeisenKlassenService::class)
                ->classify($team, $this->recipeId);
        } catch (\RuntimeException $e) {
            $this->kiFehler = $e->getMessage();
        }
    }

    public function accept_klasse(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || $this->klasseVorschlag === null || $this->klasseVorschlag['klasse_id'] === null) {
            return;                                                  // null-Klassifikation ⇒ kein Schreibversuch (§7.5)
        }
        try {
            app(\Platform\FoodAlchemist\Services\SpeisenKlassenService::class)->acceptKlasse(
                $team, $this->recipeId, $this->klasseVorschlag['klasse_id'],
                $this->klasseVorschlag['confidence'], $this->klasseVorschlag['reasoning'],
                $this->klasseVorschlag['call_log_id'] ?? null,
            );
        } catch (\RuntimeException $e) {
            $this->kiFehler = $e->getMessage();

            return;
        }
        $this->klasseVorschlag = null;
        $this->dispatch('recipe-gespeichert');
    }

    public function reject_klasse(): void
    {
        $this->klasseVorschlag = null;                               // reject lässt Fachdaten unberührt (§7.5)
    }

    public function ai_rollen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->kiFehler = null;
        try {
            $this->rollenVorschlag = app(\Platform\FoodAlchemist\Services\SpeisenKlassenService::class)
                ->verteileRollen($team, $this->recipeId);
        } catch (\RuntimeException $e) {
            $this->kiFehler = $e->getMessage();
        }
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
        $this->dispatch('recipe-gespeichert');
    }

    public function reject_rollen(): void
    {
        $this->rollenVorschlag = null;
    }

    // ── D-6 §5.x (MVP): Foodpairing am VK-Rezept — gleiche Bausteine wie M5 ──

    /** @var array<string, bool> lazy Sektionen (nur offene rechnen, P-1) */
    public array $offen = [];

    public string $ankerSuche = '';

    public ?string $fehlerAnker = null;

    public function toggleSektion(string $sektion): void
    {
        if (in_array($sektion, ['anker', 'pairing', 'kohaerenz', 'heber', 'nachbarn'], true)) {
            $this->offen[$sektion] = ! ($this->offen[$sektion] ?? false);
        }
    }

    // ── D-6 §5.x: Kohärenz-Judge + Teller-Heber (CoherenceService, gecacht) ──

    public function pruefeKohaerenz(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->kiFehler = null;
        try {
            app(\Platform\FoodAlchemist\Services\CoherenceService::class)->judge($team, $this->recipeId);
        } catch (\RuntimeException $e) {
            $this->kiFehler = $e->getMessage();
        }
    }

    public function schlageHeberVor(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->kiFehler = null;
        try {
            app(\Platform\FoodAlchemist\Services\CoherenceService::class)->tellerHeber($team, $this->recipeId);
        } catch (\RuntimeException $e) {
            $this->kiFehler = $e->getMessage();
        }
    }

    public function ankerVerknuepfen(int $ankerId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\PairingService::class)->setRecipeAnker($team, $this->recipeId, $ankerId);
            $this->ankerSuche = '';
        } catch (\RuntimeException $e) {
            $this->fehlerAnker = $e->getMessage();
        }
    }

    public function ankerLoesen(int $ankerId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->recipeId !== null) {
            app(\Platform\FoodAlchemist\Services\PairingService::class)->removeRecipeAnker($team, $this->recipeId, $ankerId);
        }
    }

    // ── M9-01k: Sektor-/Niveau-Eignung pflegen + ✨ Eignung + ✨ Marketing (Panel) ──

    /** @var ?array{typ: string, slugs: array<string, string>, confidence: float} */
    public ?array $eignungVorschlag = null;

    public function eignungSetzen(string $typ, string $slug): void
    {
        $this->fachAktion(fn ($team) => app(\Platform\FoodAlchemist\Services\RecipeService::class)
            ->setzeEignung($team, $this->recipeId, $typ, $slug, 'manual'));
    }

    public function eignungEntfernen(string $typ, string $slug): void
    {
        $this->fachAktion(fn ($team) => app(\Platform\FoodAlchemist\Services\RecipeService::class)
            ->entferneEignung($team, $this->recipeId, $typ, $slug));
    }

    public function kiEignung(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->kiFehler = null;
        $r = app(SalesRecipeService::class)->detail($team, $this->recipeId);
        $gateway = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class);
        $kontext = ['name' => $r->name, 'komponenten' => $r->ingredients->map(fn ($z) => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name)->all()];
        $vokabular = \Platform\FoodAlchemist\Services\RecipeService::eignungVokabular();

        try {
            $slugs = [];
            $conf = 0.0;
            foreach (['sektor' => ['recipe.sektor', 'sektoren'], 'level' => ['recipe.level', 'niveaus']] as $typ => [$prompt, $schluessel]) {
                $v = $gateway->propose($prompt, $kontext + ['vokabular' => $vokabular[$typ]['slugs']]);
                $conf = max($conf, $v->confidence);
                foreach ((array) ($v->werte[$schluessel] ?? []) as $slug => $urteil) {
                    if (in_array($slug, $vokabular[$typ]['slugs'], true) && (($urteil['eignung'] ?? null) === 'geeignet')) {
                        $slugs[$slug] = $typ;
                    }
                }
            }
        } catch (\RuntimeException $e) {
            $this->kiFehler = $e->getMessage();

            return;
        }
        if ($slugs === []) {
            $this->kiFehler = 'KI lieferte keine verwertbare Eignung — echter Provider nötig.';

            return;
        }
        $this->eignungVorschlag = ['slugs' => $slugs, 'confidence' => max(0.0, min(1.0, $conf))];
    }

    public function eignungUebernehmen(): void
    {
        if ($this->eignungVorschlag === null) {
            return;
        }
        $this->fachAktion(function ($team) {
            foreach ($this->eignungVorschlag['slugs'] as $slug => $typ) {
                app(\Platform\FoodAlchemist\Services\RecipeService::class)
                    ->setzeEignung($team, $this->recipeId, $typ, $slug, 'ai_inferred', $this->eignungVorschlag['confidence']);
            }
            $this->eignungVorschlag = null;
        });
    }

    public function eignungVerwerfen(): void
    {
        $this->eignungVorschlag = null;
    }

    // Marketing-Text-Pflege am Gericht entfällt (UX-Umbau 2026-07-03): der
    // kundenspezifische Text wird am Foodbook-Block gepflegt (Livewire\Foodbooks\Index::kiKundentext).

    private function fachAktion(\Closure $tu): void
    {
        $this->kiFehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            $tu($team);
        } catch (\RuntimeException $e) {
            $this->kiFehler = $e->getMessage();
        }
    }

    public function render(SalesRecipeService $verkauf)
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null ? $verkauf->detail($team, $this->recipeId) : null;
        $pairing = app(\Platform\FoodAlchemist\Services\PairingService::class);

        return view('foodalchemist::livewire.verkauf.detail-panel', [
            'rezept' => $rezept,
            'cockpit' => $rezept !== null ? $verkauf->cockpit($rezept) : null,
            // D-6 §5.x: Kern-Anker · Kohäsions-Score · Pairing-Section (lazy)
            'kernAnker' => $rezept !== null ? $pairing->recipeAnkers($rezept->id) : collect(),
            'kohaesion' => $rezept !== null && ($this->offen['anker'] ?? false) ? $pairing->recipeCohesion($rezept) : null,
            'pairings' => $rezept !== null && ($this->offen['pairing'] ?? false) ? $pairing->recipePairings($rezept->id) : null,
            'ankerKandidaten' => $this->ankerSuche !== ''
                ? TeamScope::applyVisible(\Illuminate\Support\Facades\DB::table('foodalchemist_vocab_pairing_anchors')
                    ->whereRaw('LOWER(slug) LIKE ?', ['%' . mb_strtolower($this->ankerSuche) . '%'])
                    ->whereNull('deleted_at'), 'team_id', $team)->orderBy('slug')->limit(6)->get(['id', 'slug', 'display_de'])
                : collect(),
            // D-6 §5.x: Judge-Achse (gecacht) + deterministische Aroma-Nachbarn (lazy)
            'kohaerenzStatus' => $rezept !== null && (($this->offen['kohaerenz'] ?? false) || ($this->offen['heber'] ?? false))
                ? app(\Platform\FoodAlchemist\Services\CoherenceService::class)->status($team, $rezept->id)
                : null,
            'nachbarn' => $rezept !== null && ($this->offen['nachbarn'] ?? false)
                ? $pairing->componentSuggestions($rezept)
                : null,
            // M9-01k: Eignungen + Vokabular für die Pflege-Selects
            'niveauEignungen' => $rezept !== null ? $rezept->levelSuitabilities()->get() : collect(),
            'sektorEignungen' => $rezept !== null ? $rezept->sectorSuitabilities()->get() : collect(),
            'eignungVokabular' => \Platform\FoodAlchemist\Services\RecipeService::eignungVokabular(),
        ]);
    }
}
