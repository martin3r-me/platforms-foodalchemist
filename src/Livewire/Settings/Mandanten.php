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

    /** KI-Monatsdeckel-Eingabe je Mandant (Euro als Text, leer = Standard) */
    public array $kiDeckel = [];

    /** „unbegrenzt“ je Mandant */
    public array $kiUnbegrenzt = [];

    public ?string $hinweis = null;

    public ?string $kiFehler = null;

    public function auf(int $teamId): void
    {
        $this->offen = $this->offen === $teamId ? null : $teamId;
        $this->kiFehler = $this->hinweis = null;
        if ($this->offen !== null) {
            $m = app(MandantenService::class)->zeige(\Platform\Core\Models\Team::findOrFail($teamId));
            $this->kiDeckel[$teamId] = $m['ki_deckel_betrag_gesetzt'] !== null ? number_format($m['ki_deckel_betrag_gesetzt'], 2, ',', '') : '';
            $this->kiUnbegrenzt[$teamId] = $m['ki_deckel_unbegrenzt'];
        }
    }

    /** KI-Monatsdeckel des Mandanten speichern (Master-Admin, Dominique 23.10.2026). */
    public function kiDeckelSpeichern(int $teamId): void
    {
        $this->kiFehler = $this->hinweis = null;
        try {
            app(MandantenService::class)->setze(\Platform\Core\Models\Team::findOrFail($teamId), [
                'ki_deckel_eur' => trim((string) ($this->kiDeckel[$teamId] ?? '')),
                'ki_deckel_unbegrenzt' => (bool) ($this->kiUnbegrenzt[$teamId] ?? false),
            ], Auth::user());
            $this->hinweis = 'KI-Deckel gespeichert.';
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->kiFehler = $e->getMessage();
        }
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
