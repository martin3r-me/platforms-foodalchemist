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

    /** @var list<array{title:string, text:string}> Freie Kapitel (Hygiene, Ausgabe-Regeln …) */
    public array $kapitel = [];

    /** @var list<array{gericht:string, fehlt:list<string>}>|null  null = noch nicht geprüft */
    public ?array $luecken = null;

    public function mount(string $type, int $quelleId): void
    {
        $this->type = $type;
        $this->quelleId = $quelleId;
        try {
            $this->kapitel = array_values(app(HandbuchService::class)->status($this->team(), $type, $quelleId)['kapitel']);
        } catch (\Throwable) {
            $this->kapitel = [];
        }
    }

    public function kapitelNeu(): void
    {
        if (count($this->kapitel) < 20) {
            $this->kapitel[] = ['title' => '', 'text' => ''];
        }
    }

    public function kapitelEntfernen(int $index): void
    {
        unset($this->kapitel[$index]);
        $this->kapitel = array_values($this->kapitel);
    }

    public function veroeffentlichen(): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        try {
            $res = app(HandbuchService::class)->publish($this->team(), $this->type, $this->quelleId, ['expires_at' => $this->gueltigBis, 'kapitel' => $this->kapitel]);
            $this->luecken = $res['luecken'];
            $this->hinweis = 'Handbuch veröffentlicht — der Link ist aktiv.';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function pruefen(): void
    {
        $this->fehler = null;
        try {
            $this->luecken = app(HandbuchService::class)->luecken($this->team(), $this->type, $this->quelleId);
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
