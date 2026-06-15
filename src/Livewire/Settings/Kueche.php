<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * M7-07: Küchen-Profil (Team-Einstellung) — Soft-Default-Schicht des
 * Generators; explizite Richtungs-Parameter haben Vorrang (D-5 §4.3).
 */
class Kueche extends Component
{
    public string $kuechenTyp = '';

    /** Phase 5: Typ-Farben (Hex) — GP / Basisrezept / Gericht. */
    public array $typFarben = ['gp' => '#7c3aed', 'basisrezept' => '#0d9488', 'gericht' => '#d97706'];

    public ?string $meldung = null;

    public function mount(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $svc = app(TeamSettingsService::class);
        $this->kuechenTyp = $svc->kuechenTyp($team) ?? '';
        $this->typFarben = $svc->typFarben($team);
    }

    public function speichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        app(TeamSettingsService::class)->update($team, [
            'kuechen_typ' => isset(TeamSettingsService::KUECHEN_TYPEN[$this->kuechenTyp]) ? $this->kuechenTyp : null,
            'typ_farben' => $this->sanitizeFarben(),
        ]);
        $this->meldung = 'Gespeichert — der Generator nutzt das Profil ab dem nächsten Lauf.';
    }

    /** Nur valide #rrggbb-Werte je bekanntem Typ übernehmen (Rest fällt auf Default zurück). */
    private function sanitizeFarben(): array
    {
        $out = [];
        foreach (TeamSettingsService::TYP_FARBEN_DEFAULTS as $key => $default) {
            $wert = $this->typFarben[$key] ?? null;
            $out[$key] = (is_string($wert) && preg_match('/^#[0-9a-fA-F]{6}$/', $wert)) ? strtolower($wert) : $default;
        }

        return $out;
    }

    public function farbenZuruecksetzen(): void
    {
        $this->typFarben = TeamSettingsService::TYP_FARBEN_DEFAULTS;
    }

    public function render()
    {
        return view('foodalchemist::livewire.settings.kueche', [
            'typen' => TeamSettingsService::KUECHEN_TYPEN,
            'farbTypen' => ['gp' => 'Produkt (GP)', 'basisrezept' => 'Basisrezept', 'gericht' => 'Gericht (VK)'],
        ]);
    }
}
