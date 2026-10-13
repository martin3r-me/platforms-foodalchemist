<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\MandantenService;
use Platform\FoodAlchemist\Services\MandantenStammblattService;

/**
 * Spec 82 §9/§13 C1 — Mandanten mit Stammblatt: ein Blick je Mandant (Status, Modus, CRM-Firma, Bereiche, Stamm-Paket, Abos,
 * Unter-Teams, Kontingente, Gebühren). Nur lesend und NUR Verwaltungsspalten (§13 G): nie Rezepte, Preise, Foodbooks, DNA oder Teamprofile.
 * Sichtbar für Plattform-Admin und Admin im Master-Team (Settings\Index blendet die Sektion sonst aus; der Dienst prüft erneut).
 */
class Mandanten extends Component
{
    public ?int $offen = null;

    public function mount(): void
    {
        abort_unless(app(MandantenService::class)->darfVerwaltung(Auth::user()), 404);
    }

    public function auf(int $teamId): void
    {
        $this->offen = $this->offen === $teamId ? null : $teamId;
    }

    public function render()
    {
        $blaetter = [];
        $fehler = null;
        try {
            $blaetter = app(MandantenStammblattService::class)->alle(Auth::user());
        } catch (FaRechtFehltException $e) {
            $fehler = $e->getMessage();
        }

        return view('foodalchemist::livewire.settings.mandanten', ['blaetter' => $blaetter, 'fehler' => $fehler]);
    }
}
