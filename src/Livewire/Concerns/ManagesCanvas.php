<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\CanvasService;

/**
 * Wiederverwendbarer Canvas-Board-State für Livewire-Komponenten (Food-DNA-Seite,
 * Concepter, Foodbook). Genau EIN Canvas je Hostkomponente. Rendert über das
 * Partial `foodalchemist::livewire.canvas.partials.board`. Vermeidet verschachtelte
 * Livewire-Komponenten (Modal-Lifecycle-Fallen).
 */
trait ManagesCanvas
{
    public string $canvasType = '';

    public string $canvasOwnerType = '';

    public ?int $canvasOwnerId = null;

    /** @var array<string,string> skalare Felder field_key => value */
    public array $canvasForm = [];

    /** @var list<array{id:int,value:string,claim:string,beschreibung:string}> repeatable */
    public array $canvasWelten = [];

    public array $canvasNeuWelt = ['value' => '', 'claim' => '', 'beschreibung' => ''];

    public bool $canvasGespeichert = false;

    protected function canvasInit(string $type, string $ownerType, ?int $ownerId): void
    {
        $this->canvasType = $type;
        $this->canvasOwnerType = $ownerType;
        $this->canvasOwnerId = $ownerId;
        $this->canvasGespeichert = false;
        $this->canvasLaden();
    }

    protected function canvasTeam(): Team
    {
        return Auth::user()->currentTeamRelation;
    }

    private function canvasRepeatableKey(): ?string
    {
        foreach (app(CanvasService::class)->template($this->canvasType)['felder'] as $f) {
            if (($f['typ'] ?? '') === 'repeatable') {
                return $f['key'];
            }
        }

        return null;
    }

    public function canvasLaden(): void
    {
        $this->canvasForm = [];
        $this->canvasWelten = [];
        if ($this->canvasType === '' || $this->canvasOwnerId === null) {
            return;
        }
        $svc = app(CanvasService::class);
        $canvas = $svc->canvasFor($this->canvasTeam(), $this->canvasType, $this->canvasOwnerType, $this->canvasOwnerId);
        $werte = $svc->werte($canvas);
        foreach ($svc->template($this->canvasType)['felder'] as $f) {
            if (($f['typ'] ?? '') === 'repeatable') {
                $this->canvasWelten = array_map(fn ($it) => [
                    'id' => $it['id'], 'value' => (string) $it['value'],
                    'claim' => (string) ($it['meta']['claim'] ?? ''), 'beschreibung' => (string) ($it['meta']['beschreibung'] ?? ''),
                ], $werte[$f['key']] ?? []);
            } else {
                $this->canvasForm[$f['key']] = (string) ($werte[$f['key']] ?? '');
            }
        }
    }

    public function canvasSpeichern(): void
    {
        $svc = app(CanvasService::class);
        $canvas = $svc->canvasFor($this->canvasTeam(), $this->canvasType, $this->canvasOwnerType, $this->canvasOwnerId);
        $svc->saveSkalare($canvas, $this->canvasForm);
        $this->canvasGespeichert = true;
    }

    public function weltHinzu(): void
    {
        $key = $this->canvasRepeatableKey();
        if ($key === null || trim((string) ($this->canvasNeuWelt['value'] ?? '')) === '') {
            return;
        }
        $svc = app(CanvasService::class);
        $canvas = $svc->canvasFor($this->canvasTeam(), $this->canvasType, $this->canvasOwnerType, $this->canvasOwnerId);
        $svc->addEntry($canvas, $key, (string) $this->canvasNeuWelt['value'], [
            'claim' => trim((string) ($this->canvasNeuWelt['claim'] ?? '')) ?: null,
            'beschreibung' => trim((string) ($this->canvasNeuWelt['beschreibung'] ?? '')) ?: null,
        ]);
        $this->canvasNeuWelt = ['value' => '', 'claim' => '', 'beschreibung' => ''];
        $this->canvasLaden();
    }

    public function weltLoeschen(int $entryId): void
    {
        app(CanvasService::class)->removeEntry($entryId);
        $this->canvasLaden();
    }

    /** Template (Titel + Felder gruppiert) fürs Partial. */
    public function canvasTemplateData(): array
    {
        $tpl = app(CanvasService::class)->template($this->canvasType);
        $gruppen = [];
        foreach ($tpl['felder'] as $f) {
            $gruppen[$f['gruppe'] ?? 'Felder'][] = $f;
        }
        $tpl['gruppen'] = $gruppen;

        return $tpl;
    }
}
