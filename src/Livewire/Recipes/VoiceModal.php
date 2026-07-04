<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
use Platform\FoodAlchemist\Services\VoiceCommandService;

/**
 * M7-10: 🎙 Voice-Interface — zweiter Bedienweg (UI bleibt parallel).
 * MediaRecorder (Opus mono, whisper-Vorbild) → Livewire-Upload → sync STT
 * (D8-Fassade) → agentischer Tool-Loop (Tier D, M8-01-Tools) → Antwort +
 * UI-Aktionen; Schreibaktionen NUR als Proposal mit Bestätigen-Button (GL-07).
 */
class VoiceModal extends Component
{
    use WithFileUploads;

    public $audio = null;                                            // Livewire-Upload (Blob aus MediaRecorder)

    public ?string $transcript = null;

    public ?array $ergebnis = null;

    public ?string $fehler = null;

    #[On('voice-modal.oeffnen')]
    public function oeffnen(): void
    {
        $this->reset('audio', 'transcript', 'ergebnis', 'fehler');
        $this->dispatch('modal.open', name: 'voice-modal');
    }

    public function updatedAudio(): void
    {
        $this->fehler = null;
        try {
            $this->transcript = app(SttServiceContract::class)
                ->transcribe(file_get_contents($this->audio->getRealPath()), $this->audio->getMimeType() ?: 'audio/webm');
            $this->verarbeite();
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Test-/Tipp-Pfad: Transkript direkt verarbeiten (auch als Fallback-Eingabe). */
    public function verarbeiteText(string $text): void
    {
        $this->fehler = null;
        $this->transcript = trim($text);
        if ($this->transcript !== '') {
            $this->verarbeite();
        }
    }

    private function verarbeite(): void
    {
        try {
            $this->ergebnis = app(VoiceCommandService::class)->verarbeite((string) $this->transcript);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        foreach ($this->ergebnis['aktionen'] as $aktion) {
            if (in_array($aktion['typ'], ['recipe', 'verkaufsrezept'], true)) {
                $this->dispatch($aktion['typ'] === 'recipe' ? 'recipe-selected' : 'vk-recipe-selected', id: $aktion['id']);
            }
        }
    }

    /** GL-07: Proposal aus dem Sprachbefehl BESTÄTIGEN (sprechen → Proposal → bestätigen). */
    public function proposalUebernehmen(int $index): void
    {
        $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation;
        $p = $this->ergebnis['proposals'][$index] ?? null;
        if ($team === null || $p === null || $p['klasse_id'] === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\SpeisenKlassenService::class)->acceptKlasse(
                $team, (int) $p['recipe_id'], (int) $p['klasse_id'],
                (float) ($p['confidence'] ?? 0), $p['reasoning'] ?? null, $p['call_log_id'] ?? null,
            );
            $this->ergebnis['proposals'][$index]['accepted'] = true;
            $this->dispatch('recipe-gespeichert');
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        return view('foodalchemist::livewire.recipes.voice-modal');
    }
}
