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
