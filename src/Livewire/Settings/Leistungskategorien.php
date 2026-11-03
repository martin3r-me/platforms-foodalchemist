<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Konfigurator\LeistungsKategorieService;

/**
 * Spec 94b · Stufe 7a: Leistungskategorien pflegen (Personal, Equipment, Logistik … und eigene). Eigene anlegen, umbenennen,
 * sortieren, deaktivieren; geerbte nur für dieses Team (und seine Unter-Teams) aus- und wieder einschalten.
 */
class Leistungskategorien extends Component
{
    /** Spec 65: Bereich settings.leistungskategorien je Team — Sofort-Aktionen, Leiste Bearbeiten → Fertig. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'leistungskategorien';
    }

    public ?int $editId = null;

    public array $form = ['name' => '', 'sort_order' => 0];

    public string $neuName = '';

    public ?string $fehler = null;

    public function edit(int $id, LeistungsKategorieService $svc): void
    {
        $eintrag = $svc->liste($this->team())->first(fn ($e) => $e->kategorie->id === $id && $e->eigen);
        if ($eintrag === null) {
            return;
        }
        $this->editId = $id;
        $this->fehler = null;
        $this->form = ['name' => $eintrag->kategorie->name, 'sort_order' => $eintrag->kategorie->sort_order];
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form', 'fehler');
    }

    public function save(LeistungsKategorieService $svc): void
    {
        $this->tue(fn () => $svc->aendern($this->team(), (int) $this->editId, $this->form)) && $this->cancel();
    }

    public function create(LeistungsKategorieService $svc): void
    {
        $this->tue(fn () => $svc->anlegen($this->team(), $this->neuName)) && $this->reset('neuName');
    }

    public function schalten(int $id, bool $aktiv, LeistungsKategorieService $svc): void
    {
        $this->tue(fn () => $svc->schalten($this->team(), $id, $aktiv));
    }

    public function render(LeistungsKategorieService $svc)
    {
        return view('foodalchemist::livewire.settings.leistungskategorien', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'liste' => $svc->liste($this->team()),
        ]);
    }

    private function tue(callable $aktion): bool
    {
        $this->fehler = null;
        try {
            $aktion();
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return false;
        }

        return true;
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
