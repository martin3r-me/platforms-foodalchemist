<?php

namespace Platform\FoodAlchemist\Livewire\Verwaltung;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanPreis;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\GebuehrenZaehler;
use Platform\FoodAlchemist\Services\PlanNutzungService;
use Platform\FoodAlchemist\Services\PlanPreisService;

/**
 * Spec 82 §13 B5/D — Gebühren-Preispflege. Eingebettet in die Plattform-Verwaltung des Hosts
 * (`<livewire:foodalchemist.verwaltung.plan-preise />`): Der Host bettet ein, das Modul besitzt Tabelle, Dienst, Rechteprüfung und Oberfläche.
 * Nur Plattform-Admin. Erst „Auswirkung prüfen“ (Mehr-/Minderbetrag je aktivem Mandant), dann anlegen. Append-only, mit „gültig ab“.
 */
class PlanPreise extends Component
{
    public string $baustein = 'user';

    public string $betrag = '';

    public string $gueltigAb = '';

    public string $notiz = '';

    public bool $auswirkungOffen = false;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(): void
    {
        $this->gueltigAb = now()->toDateString();
    }

    public function pruefen(): void
    {
        $this->fehler = $this->meldung = null;
        $this->auswirkungOffen = false;
        if (! $this->istAdmin()) {
            return;
        }
        if (! is_numeric(str_replace(',', '.', $this->betrag))) {
            $this->fehler = 'Der Betrag je Monat muss eine Zahl sein.';

            return;
        }
        $this->auswirkungOffen = true;
    }

    public function abbrechen(): void
    {
        $this->auswirkungOffen = false;
    }

    public function anlegen(): void
    {
        $this->fehler = $this->meldung = null;
        try {
            app(PlanPreisService::class)->setze($this->baustein, str_replace(',', '.', $this->betrag), $this->gueltigAb ?: null, $this->notiz, Auth::user());
            $this->meldung = 'Preis angelegt, gültig ab '.\Carbon\Carbon::parse($this->gueltigAb ?: now())->format('d.m.Y').'.';
            $this->auswirkungOffen = false;
            $this->betrag = $this->notiz = '';
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function istAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null && ! $user->isAiUser() && app(FaRechte::class)->istPlattformAdmin($user);
    }

    public function render()
    {
        $admin = $this->istAdmin();
        $preise = app(PlanPreisService::class);
        $nutzung = app(PlanNutzungService::class);
        $auswirkung = null;
        if ($admin && $this->auswirkungOffen && is_numeric(str_replace(',', '.', $this->betrag))) {
            try {
                $auswirkung = $nutzung->auswirkung($this->baustein, str_replace(',', '.', $this->betrag));
            } catch (\RuntimeException $e) {
                $this->fehler = $e->getMessage();
            }
        }
        $mandanten = [];
        if ($admin) {
            foreach (app(GebuehrenZaehler::class)->abrechenbareMandanten() as $m) {
                $mandanten[] = $nutzung->monat($m, Auth::user());
            }
        }

        return view('foodalchemist::livewire.verwaltung.plan-preise', [
            'admin' => $admin,
            'bausteine' => FoodAlchemistPlanPreis::BAUSTEINE,
            'aktuell' => $admin ? $preise->aktuell() : [],
            'historie' => $admin ? array_slice($preise->historie(), 0, 15) : [],
            'auswirkung' => $auswirkung,
            'mandanten' => $mandanten,
        ]);
    }
}
