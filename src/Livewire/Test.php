<?php

/**
 * Test Livewire Component
 * 
 * Test-Seite für Entwicklung und Demonstration.
 * 
 * WICHTIG FÜR LLMs:
 * - Diese Seite dient als Test/Beispiel
 * - Zeigt alle UI-Komponenten und Patterns
 * - Kann später gelöscht oder umbenannt werden
 * 
 * ANPASSUNGEN:
 * - Füge Test-Funktionalität hinzu
 * - Zeige verschiedene UI-Komponenten
 * - Teste Formulare, Modals, etc.
 */

namespace Platform\FoodAlchemist\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class Test extends Component
{
    /**
     * Test-Properties
     * 
     * Beispiel-Properties für Tests
     */
    public $testValue = 'Test';
    public $testNumber = 42;
    public $testBoolean = true;

    /**
     * M0-09-Demo: GL-07-Lebenszyklus für den ki-header-Baustein —
     * deterministischer Fake-Vorschlag (echter Gateway kommt mit M0-14).
     */
    public ?string $kiDemoWert = null;
    public ?string $kiDemoQuelle = null;
    public ?float $kiDemoConfidence = null;
    public ?array $kiDemoProposal = null;

    public function ai_kiDemo(): void
    {
        // Propose: persistiert NICHTS (GL-07 I3), liefert nur das Vorschlags-DTO
        $this->kiDemoProposal = [
            'wert' => 'fruchtig-herb',
            'confidence' => 0.87,
            'begruendung' => 'Fake-Vorschlag (Demo): dominante Zutaten Limette + Wacholder.',
        ];
    }

    public function accept_kiDemo(): void
    {
        if ($this->kiDemoQuelle === 'manual' || !$this->kiDemoProposal) {
            return; // Override-First: KI überschreibt nie manual (GL-07 I2)
        }
        $this->kiDemoWert = $this->kiDemoProposal['wert'];
        $this->kiDemoQuelle = 'ki';
        $this->kiDemoConfidence = min(1.0, max(0.0, $this->kiDemoProposal['confidence'])); // Clamp (GL-07 I5)
        $this->kiDemoProposal = null;
    }

    public function clear_kiDemo(): void
    {
        // Wert + komplette Lineage → NULL (GL-07 clear)
        $this->kiDemoWert = null;
        $this->kiDemoQuelle = null;
        $this->kiDemoConfidence = null;
        $this->kiDemoProposal = null;
    }

    public function manual_kiDemo(): void
    {
        $this->kiDemoWert = 'herzhaft (von Hand gepflegt)';
        $this->kiDemoQuelle = 'manual';
        $this->kiDemoConfidence = null;
        $this->kiDemoProposal = null;
    }

    /** M0-10-Demo: tri-state-Baustein — ein Array-Binding (P-4), Sync deferred. */
    public array $triDemo = [
        'glutenhaltiges_getreide' => 'enthalten',
        'eier' => 'spuren',
        'milch' => 'nicht_enthalten',
        'senf' => 'unbekannt',
    ];

    /**
     * Render-Methode
     */
    public function render()
    {
        $user = Auth::user();

        return view('foodalchemist::livewire.test', [
            'user' => $user,
        ])->layout('platform::layouts.app');
    }

    /**
     * Test-Methode
     * 
     * Beispiel-Methode für Tests
     * 
     * HINWEIS: noticable_type und noticable_id müssen gesetzt werden,
     * da die Datenbank-Spalten NOT NULL sind (morphs() erstellt standardmäßig NOT NULL).
     * Für Test-Zwecke verwenden wir stdClass als Dummy-Klasse.
     */
    public function testAction()
    {
        $this->dispatch('notifications:store', [
            'title' => 'Test erfolgreich',
            'message' => 'Die Test-Aktion wurde ausgeführt.',
            'notice_type' => 'success',
            // WICHTIG: noticable_type und noticable_id müssen gesetzt werden
            // da die Datenbank-Spalten NOT NULL sind
            'noticable_type' => \stdClass::class, // Dummy-Klasse für Tests
            'noticable_id' => 0, // Dummy-ID für Tests
            // In echten Anwendungen würde man hier ein echtes Model verwenden:
            // 'noticable_type' => SomeModel::class,
            // 'noticable_id' => $someModel->id,
        ]);
    }
}
