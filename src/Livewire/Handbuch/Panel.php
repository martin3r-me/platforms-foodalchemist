<?php

namespace Platform\FoodAlchemist\Livewire\Handbuch;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\HandbuchService;

/**
 * Spec 86 — Handbuch-Link neben dem Kundenlink: veröffentlichen (eingefroren), zurückziehen, Lücken sehen.
 * Eigenes Nested-Livewire, wird im Veröffentlichen-Bereich von Foodbook/Speisekarte/Angebot/Speiseplan
 * eingebettet (`type` + `quelleId`, per wire:key an das Dokument gebunden).
 */
class Panel extends Component
{
    public string $type = 'foodbook';

    public int $quelleId = 0;

    public ?string $gueltigBis = null;

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function mount(string $type, int $quelleId): void
    {
        $this->type = $type;
        $this->quelleId = $quelleId;
    }

    public function veroeffentlichen(): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        try {
            app(HandbuchService::class)->publish($this->team(), $this->type, $this->quelleId, ['expires_at' => $this->gueltigBis]);
            $this->hinweis = 'Handbuch veröffentlicht — der Link ist aktiv.';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function zurueckziehen(): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        try {
            app(HandbuchService::class)->withdraw($this->team(), $this->type, $this->quelleId);
            $this->hinweis = 'Handbuch zurückgezogen — der Link ist jetzt inaktiv (404).';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $status = null;
        try {
            $status = app(HandbuchService::class)->status($this->team(), $this->type, $this->quelleId);
        } catch (\Throwable $e) {
            $this->fehler ??= $e->getMessage();
        }
        if ($this->gueltigBis === null && ($status['expires_at'] ?? null) !== null) {
            $this->gueltigBis = substr((string) $status['expires_at'], 0, 10);
        }

        return view('foodalchemist::livewire.handbuch.panel', ['status' => $status]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
