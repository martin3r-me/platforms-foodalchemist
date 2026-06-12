<?php

namespace Platform\FoodAlchemist\Livewire\Verkauf;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\SalesRecipeService;

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

    /** @var ?array{klasse_id: ?int, klasse_name: ?string, confidence: float, begruendung: ?string} */
    public ?array $klasseVorschlag = null;

    /** @var ?array{rollen: array<int, string>, confidence: float, begruendung: ?string} */
    public ?array $rollenVorschlag = null;

    public ?string $kiFehler = null;

    public function ai_klassifizieren(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->kiFehler = null;
        $this->klasseVorschlag = app(\Platform\FoodAlchemist\Services\SpeisenKlassenService::class)
            ->classify($team, $this->recipeId);
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
                $this->klasseVorschlag['confidence'], $this->klasseVorschlag['begruendung'],
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
        $this->dispatch('recipe-gespeichert');
    }

    public function reject_rollen(): void
    {
        $this->rollenVorschlag = null;
    }

    public function render(SalesRecipeService $verkauf)
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null ? $verkauf->detail($team, $this->recipeId) : null;

        return view('foodalchemist::livewire.verkauf.detail-panel', [
            'rezept' => $rezept,
            'cockpit' => $rezept !== null ? $verkauf->cockpit($rezept) : null,
        ]);
    }
}
