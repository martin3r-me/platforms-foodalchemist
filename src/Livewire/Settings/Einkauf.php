<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
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

    /** @var array{name:string,code:string,type:string,note:string} */
    public array $lagerNeu = [
        'name' => '',
        'code' => '',
        'type' => 'warehouse',
        'note' => '',
    ];

    /** @var array<int,array{name:string,code:string,type:string,note:string,is_active:bool}> */
    public array $lagerEdit = [];

    public function mount(): void
    {
        $settings = app(TeamSettingsService::class)->for($this->team());
        $this->strategie = ($settings->lead_la_strategie ?? LeadLaStrategie::GuenstigsterPreis)->value;
        $this->prioritaeten = $settings->lead_la_prioritaeten ?? [];
        $this->ausweichKette = (bool) ($settings->show_fallback_chain ?? false);
        $this->strategiePerWg = is_array($settings->lead_la_strategie_per_wg ?? null) ? $settings->lead_la_strategie_per_wg : [];
        $this->ladeLagerForm();
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
            'show_fallback_chain' => $this->ausweichKette,
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

    public function lagerAnlegen(): void
    {
        $team = $this->team();
        $name = trim((string) ($this->lagerNeu['name'] ?? ''));
        if ($name === '') {
            $this->fehler = 'Bitte einen Lagernamen eingeben.';
            return;
        }

        $hatLager = FoodAlchemistInventoryLocation::where('team_id', $team->id)->exists();
        FoodAlchemistInventoryLocation::create([
            'team_id' => $team->id,
            'name' => $name,
            'code' => trim((string) ($this->lagerNeu['code'] ?? '')) ?: null,
            'type' => $this->lagerTyp((string) ($this->lagerNeu['type'] ?? 'warehouse')),
            'is_default' => ! $hatLager,
            'is_active' => true,
            'note' => trim((string) ($this->lagerNeu['note'] ?? '')) ?: null,
        ]);

        $this->lagerNeu = ['name' => '', 'code' => '', 'type' => 'warehouse', 'note' => ''];
        $this->fehler = null;
        $this->meldung = 'Lagerort angelegt.';
        $this->ladeLagerForm();
    }

    public function lagerSpeichern(int $id): void
    {
        $team = $this->team();
        $location = FoodAlchemistInventoryLocation::where('team_id', $team->id)->findOrFail($id);
        $input = $this->lagerEdit[$id] ?? [];
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $this->fehler = 'Bitte einen Lagernamen eingeben.';
            return;
        }

        $location->update([
            'name' => $name,
            'code' => trim((string) ($input['code'] ?? '')) ?: null,
            'type' => $this->lagerTyp((string) ($input['type'] ?? 'warehouse')),
            'is_active' => (bool) ($input['is_active'] ?? false),
            'note' => trim((string) ($input['note'] ?? '')) ?: null,
        ]);

        $this->fehler = null;
        $this->meldung = 'Lagerort gespeichert.';
        $this->ladeLagerForm();
    }

    public function lagerStandardSetzen(int $id): void
    {
        $team = $this->team();
        DB::transaction(function () use ($team, $id) {
            $location = FoodAlchemistInventoryLocation::where('team_id', $team->id)->lockForUpdate()->findOrFail($id);
            FoodAlchemistInventoryLocation::where('team_id', $team->id)->update(['is_default' => false]);
            $location->is_default = true;
            $location->is_active = true;
            $location->save();
        });

        $this->fehler = null;
        $this->meldung = 'Standardlager gesetzt.';
        $this->ladeLagerForm();
    }

    public function lagerEntfernen(int $id): void
    {
        $team = $this->team();
        $location = FoodAlchemistInventoryLocation::where('team_id', $team->id)->withCount('stocks')->findOrFail($id);
        if ($location->stocks()->where('qty_base', '!=', 0)->exists()) {
            $location->is_active = false;
            $location->save();
            $this->meldung = 'Lagerort hat Bestand und wurde deaktiviert.';
        } else {
            $location->delete();
            $this->meldung = 'Lagerort entfernt.';
        }

        if ((bool) $location->is_default) {
            $next = FoodAlchemistInventoryLocation::where('team_id', $team->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->first();
            if ($next !== null) {
                $this->lagerStandardSetzen((int) $next->id);
                return;
            }
        }

        $this->fehler = null;
        $this->ladeLagerForm();
    }

    public function render()
    {
        $team = $this->team();
        $lieferanten = FoodAlchemistSupplier::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('name')->get(['id', 'name', 'team_id']);

        $matrix = app(StammLieferantService::class)->matrixFor($team)
            ->groupBy(fn ($z) => $z->commodity_group_code ?? '');
        $lagerorte = FoodAlchemistInventoryLocation::where('team_id', $team->id)
            ->withCount('stocks')
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('foodalchemist::livewire.settings.einkauf', [
            'team' => $team,
            'strategien' => LeadLaStrategie::cases(),
            'lieferanten' => $lieferanten,
            'lieferantenNamen' => $lieferanten->pluck('name', 'id'),
            'matrix' => $matrix,
            'warengruppen' => app(VocabularyService::class)->listWarengruppen($team),
            'lagerorte' => $lagerorte,
            'lagerTypen' => $this->lagerTypen(),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }

    private function ladeLagerForm(): void
    {
        $team = $this->team();
        $this->lagerEdit = FoodAlchemistInventoryLocation::where('team_id', $team->id)->get()
            ->mapWithKeys(fn ($l) => [
                (int) $l->id => [
                    'name' => (string) $l->name,
                    'code' => (string) ($l->code ?? ''),
                    'type' => (string) ($l->type ?? 'warehouse'),
                    'note' => (string) ($l->note ?? ''),
                    'is_active' => (bool) $l->is_active,
                ],
            ])->all();
    }

    /** @return array<string,string> */
    private function lagerTypen(): array
    {
        return [
            'warehouse' => 'Lager',
            'cooling' => 'Kühlhaus',
            'freezer' => 'TK-Lager',
            'dry' => 'Trockenlager',
            'production' => 'Produktion',
        ];
    }

    private function lagerTyp(string $type): string
    {
        return array_key_exists($type, $this->lagerTypen()) ? $type : 'warehouse';
    }
}
