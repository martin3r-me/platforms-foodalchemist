<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * M1-01 / D-1 §4: Settings-Gerüst — vertikale Sektions-Navigation, jede Sektion
 * eine eigene URL (V-17: kein Tab-State-Verlust). Die Sektionen selbst sind
 * eigenständige Livewire-Komponenten (Isolation, lazy pro Route).
 *
 * Edit-Gating macht jede Sektion zeilen-genau über Curate::canCurate (M1-08);
 * das Gerüst zeigt Kind-Teams nur den Read-only-Hinweis (D1: geerbter Katalog).
 */
class Index extends Component
{
    public string $sektion = 'einheiten';

    /** @var array<string, array{label: string, hint: string}> */
    public const SEKTIONEN = [
        'einheiten' => ['label' => 'Einheiten', 'hint' => 'Gramm-/ml-Defaults, Stück-Gewichte (GL-02/GL-11)'],
        'warengruppen' => ['label' => 'Warengruppen & Sub-Kategorien', 'hint' => '§3-Codes fix · Sub-Kategorien-Housekeeping'],
        'taxonomie' => ['label' => 'Rezept-Taxonomie', 'hint' => 'Hauptgruppen + Kategorien (M4-Browser-Bäume)'],
        'konzept-taxonomie' => ['label' => 'Konzept-Taxonomie', 'hint' => 'Kategorie- + Klasse-Baum über den Concepts (Filter-Achse, Foodbook-Picker)'],
        'einkauf' => ['label' => 'Einkauf & Lead-LA', 'hint' => 'Lead-Strategie (V-27) · Stamm-Lieferanten-Matrix'],
        'kalkulation' => ['label' => 'Kalkulation', 'hint' => 'Gar-/Putzverlust-, MwSt-Defaults, Rundung (GL-02)'],
        // #379+ (2026-06-16): 'herstellkosten' zog ins Controlling-Zentrum (Kalkulations-Werkstatt) um.
        //   Der Editor wird dort eingebettet; /einstellungen/herstellkosten leitet via mount() dorthin um.
        'kueche' => ['label' => 'Küchen-Profil', 'hint' => 'Mandanten-Tendenz für den Generator (M7-07, Hooks gewinnen)'],
        'ki' => ['label' => 'KI', 'hint' => 'Provider · Tiering (V-01) · Nutzung · Kill-Switch (M7-08)'],
        'vk-taxonomie' => ['label' => 'VK-Taxonomie', 'hint' => 'Speisen-Hauptgruppen → Klassen mit Rezept-Zählern (D-6 §4.6)'],
        // R5 (Dominique): eigene Seiten statt Sammel-Sektion — mit Anlegen/Bearbeiten
        'aufschlagsklassen' => ['label' => 'Aufschlagsklassen', 'hint' => 'Rohaufschlag/MwSt editierbar (GT-8) · W-1-Kennzeichnung'],
        'schreibstile' => ['label' => 'Schreibstile', 'hint' => 'Sprach-Duktus = Prompt-Material (GL-06) · anlegen + bearbeiten'],
        'behaelter' => ['label' => 'Behälter & Geräte', 'hint' => 'Behälter · Regen-Geräte · Servier-Vehikel · Koch-Equipment'],
    ];

    public function mount(string $sektion = 'einheiten'): void
    {
        // #379+ (2026-06-16): Herstellkosten ist ins Controlling-Zentrum (Kalkulations-Werkstatt)
        // umgezogen — alte Deep-Links/Lesezeichen sauber dorthin umleiten statt 404.
        if ($sektion === 'herstellkosten') {
            $this->redirect(route('foodalchemist.kalkulation.index'), navigate: true);

            return;
        }

        abort_unless(array_key_exists($sektion, self::SEKTIONEN), 404);
        $this->sektion = $sektion;
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.settings.index', [
            'sektionen' => self::SEKTIONEN,
            'istKindTeam' => $team !== null && $team->parent_team_id !== null,
        ])->layout('platform::layouts.app');
    }
}
