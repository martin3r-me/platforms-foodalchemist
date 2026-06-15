<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\StammLieferantService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\VocabularyService;
use RuntimeException;

/**
 * M1-05 / V-27: Einkauf — Lead-LA-Strategie des Teams (+ Ausweich-Kette-Toggle).
 * Jedes Team entscheidet für sich (kein D1-Vererbungs-Gating — Settings sind team-eigen).
 * M1-06 ergänzt hier die Stamm-Lieferanten-Matrix.
 */
class Einkauf extends Component
{
    public string $strategie = 'guenstigster_preis';

    /** @var array<int> geordnete supplier_ids für prioritaets_kette */
    public array $prioritaeten = [];

    public bool $ausweichKette = false;

    public string $neuerPrioLieferant = '';

    public ?string $meldung = null;

    public ?string $fehler = null;

    /** M1-06: Add-Selects der Matrix, key = WG-Code ('' = global) */
    public array $stammNeu = [];

    /** Phase 3: WG-Strategie-Override, key = WG-Code, '' = keine Override (globale Strategie gilt). */
    public array $strategiePerWg = [];

    public function mount(): void
    {
        $settings = app(TeamSettingsService::class)->for($this->team());
        $this->strategie = ($settings->lead_la_strategie ?? LeadLaStrategie::GuenstigsterPreis)->value;
        $this->prioritaeten = $settings->lead_la_prioritaeten ?? [];
        $this->ausweichKette = (bool) ($settings->ausweich_kette_anzeigen ?? false);
        $this->strategiePerWg = is_array($settings->lead_la_strategie_per_wg ?? null) ? $settings->lead_la_strategie_per_wg : [];
    }

    public function speichern(): void
    {
        // Nur gültige Strategie-Overrides behalten; leere = global (Default).
        $gueltig = array_map(fn ($c) => $c->value, LeadLaStrategie::cases());
        $perWg = collect($this->strategiePerWg)
            ->filter(fn ($v) => is_string($v) && in_array($v, $gueltig, true))
            ->all();

        app(TeamSettingsService::class)->update($this->team(), [
            'lead_la_strategie' => LeadLaStrategie::from($this->strategie),
            'lead_la_strategie_per_wg' => $perWg ?: null,
            'lead_la_prioritaeten' => array_values(array_map('intval', $this->prioritaeten)),
            'ausweich_kette_anzeigen' => $this->ausweichKette,
        ]);
        $this->meldung = 'Gespeichert — wirkt ab sofort auf die Lead-LA-Wahl (M3-06).';
    }

    public function prioHinzu(): void
    {
        $id = (int) $this->neuerPrioLieferant;
        if ($id > 0 && ! in_array($id, array_map('intval', $this->prioritaeten), true)) {
            $this->prioritaeten[] = $id;
        }
        $this->neuerPrioLieferant = '';
    }

    public function prioEntfernen(int $index): void
    {
        unset($this->prioritaeten[$index]);
        $this->prioritaeten = array_values($this->prioritaeten);
    }

    public function prioHoch(int $index): void
    {
        if ($index > 0) {
            [$this->prioritaeten[$index - 1], $this->prioritaeten[$index]] = [$this->prioritaeten[$index], $this->prioritaeten[$index - 1]];
        }
    }

    // ── M1-06: Stamm-Lieferanten-Matrix ─────────────────────────────────

    public function stammSetzen(string $wgCode): void
    {
        $supplierId = (int) ($this->stammNeu[$wgCode] ?? 0);
        if ($supplierId <= 0) {
            return;
        }
        try {
            app(StammLieferantService::class)->setStamm($this->team(), $supplierId, $wgCode === '' ? null : $wgCode);
            $this->stammNeu[$wgCode] = '';
            $this->fehler = null;
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function stammEntfernen(int $supplierId, string $wgCode): void
    {
        try {
            app(StammLieferantService::class)->unsetStamm($this->team(), $supplierId, $wgCode === '' ? null : $wgCode);
            $this->fehler = null;
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $team = $this->team();
        $lieferanten = FoodAlchemistSupplier::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('name')->get(['id', 'name', 'team_id']);

        $matrix = app(StammLieferantService::class)->matrixFor($team)
            ->groupBy(fn ($z) => $z->warengruppe_code ?? '');

        return view('foodalchemist::livewire.settings.einkauf', [
            'team' => $team,
            'strategien' => LeadLaStrategie::cases(),
            'lieferanten' => $lieferanten,
            'lieferantenNamen' => $lieferanten->pluck('name', 'id'),
            'matrix' => $matrix,
            'warengruppen' => app(VocabularyService::class)->listWarengruppen($team),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
