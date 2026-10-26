<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\SupplierRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Marken\MarkenPflegeService;

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3 — Block „Marke & Rolle“ im Lieferanten-Stammblatt: Rolle (Hersteller/Händler/offen), Kurzname für die Klammer und
 * die Marken-Kürzel des Lieferanten. Globale Regel-Daten: pflegen nur Plattform-Admin und Admin im Master-Team (MandantenService::darfVerwaltung),
 * alle anderen sehen den Block lesend. Geschrieben wird nur über {@see MarkenPflegeService} (prüft das Recht auf dem Server).
 */
class LieferantMarken extends Component
{
    #[Locked]
    public int $supplierId;

    public string $rolle = 'offen';

    public string $kurzname = '';

    /** @var array{kuerzel: string, marke: string, typ: string} */
    public array $neu = ['kuerzel' => '', 'marke' => '', 'typ' => FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE, 'position' => FoodAlchemistSupplierMarke::POSITION_ANFANG];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(int $supplierId): void
    {
        $this->supplierId = $supplierId;
        $l = $this->lieferant();
        $this->rolle = ($l->rolle ?? SupplierRolle::Offen)->value;
        $this->kurzname = (string) ($l->kurzname ?? '');
    }

    public function rolleSpeichern(): void
    {
        $this->schreibe(function (MarkenPflegeService $pflege) {
            $l = $pflege->rolleSetzen(Auth::user(), $this->lieferant(), $this->rolle, $this->kurzname);
            $this->kurzname = (string) ($l->kurzname ?? '');

            return 'Rolle gespeichert.';
        });
    }

    public function markeAnlegen(): void
    {
        $this->schreibe(function (MarkenPflegeService $pflege) {
            $pflege->kuerzelSetzen(Auth::user(), $this->supplierId, $this->neu['kuerzel'], $this->neu['marke'], $this->neu['typ'], $this->neu['position'] ?? FoodAlchemistSupplierMarke::POSITION_ANFANG);
            $this->neu = ['kuerzel' => '', 'marke' => '', 'typ' => FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE, 'position' => FoodAlchemistSupplierMarke::POSITION_ANFANG];

            return 'Kürzel gespeichert.';
        });
    }

    public function markeLoeschen(string $kuerzel): void
    {
        $this->schreibe(fn (MarkenPflegeService $pflege) => $pflege->kuerzelEntfernen(Auth::user(), $this->supplierId, $kuerzel) ? 'Kürzel entfernt.' : 'Kürzel nicht gefunden.');
    }

    /** Recht prüft der Dienst (Server); fachliche Fehler werden Meldung, fehlendes Recht 403. */
    private function schreibe(callable $tu): void
    {
        abort_unless(app(MarkenPflegeService::class)->darf(Auth::user()), 403, 'Rolle, Kurzname und Marken-Kürzel pflegen nur Plattform-Admin und Master-Admin.');
        try {
            $this->meldung = $tu(app(MarkenPflegeService::class));
            $this->fehler = null;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
            $this->meldung = null;
        }
    }

    public function darfPflegen(): bool
    {
        return app(MarkenPflegeService::class)->darf(Auth::user());
    }

    private function lieferant(): FoodAlchemistSupplier
    {
        $team = Auth::user()?->currentTeamRelation;
        abort_if($team === null, 403);

        return FoodAlchemistSupplier::visibleToTeam($team)->findOrFail($this->supplierId);   // nur sichtbare Lieferanten (wie das Stammblatt)
    }

    public function render()
    {
        return view('foodalchemist::livewire.suppliers.lieferant-marken', [
            'darf' => $this->darfPflegen(),
            'marken' => FoodAlchemistSupplierMarke::query()->where('supplier_id', $this->supplierId)->orderBy('kuerzel')->get(),
            'rollen' => collect(SupplierRolle::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all(),
        ]);
    }
}
