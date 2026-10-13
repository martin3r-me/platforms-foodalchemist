<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvas;
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

    /** @var list<array{id:int,value:string,claim:string,description:string}> repeatable */
    public array $canvasWelten = [];

    public array $canvasNeuWelt = ['value' => '', 'claim' => '', 'description' => ''];

    public bool $canvasGespeichert = false;

    /** Spec 82 §7 (nur Team-DNA im Oberteam): Skalar-Feld → verbindlich? */
    public array $canvasVerbindlich = [];

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
        foreach (app(CanvasService::class)->felder($this->canvasType, $this->canvasTeam()) as $f) {
            if (($f['type'] ?? '') === 'repeatable') {
                return $f['key'];
            }
        }

        return null;
    }

    public function canvasLaden(): void
    {
        $this->canvasForm = [];
        $this->canvasWelten = [];
        $this->canvasVerbindlich = [];
        if ($this->canvasType === '' || $this->canvasOwnerId === null) {
            return;
        }
        $svc = app(CanvasService::class);
        $canvas = $svc->canvasFor($this->canvasTeam(), $this->canvasType, $this->canvasOwnerType, $this->canvasOwnerId);
        $werte = $svc->werte($canvas, $this->canvasTeam());
        $this->canvasVerbindlich = $svc->verbindlichkeit($canvas);
        foreach ($svc->felder($this->canvasType, $this->canvasTeam()) as $f) {
            if (($f['type'] ?? '') === 'repeatable') {
                $this->canvasWelten = array_map(fn ($it) => [
                    'id' => $it['id'], 'value' => (string) $it['value'], 'verbindlich' => (bool) ($it['is_binding'] ?? false),
                    'claim' => (string) ($it['meta']['claim'] ?? ''), 'description' => (string) ($it['meta']['description'] ?? ''),
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
        // Spec 82 §7: Felder, die das Oberteam verbindlich vorgibt, schreibt das Unter-Team nicht
        $form = array_diff_key($this->canvasForm, array_flip($this->canvasGesperrteKeys()));
        $svc->saveSkalare($canvas, $form, $this->canvasTeam());
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
            'description' => trim((string) ($this->canvasNeuWelt['description'] ?? '')) ?: null,
        ]);
        $this->canvasNeuWelt = ['value' => '', 'claim' => '', 'description' => ''];
        $this->canvasLaden();
    }

    public function weltLoeschen(int $entryId): void
    {
        // nur Einträge des EIGENEN Canvas: ein Unter-Team entfernt nie etwas vom Oberteam (Spec 82 §7)
        $canvas = app(CanvasService::class)->canvasFor($this->canvasTeam(), $this->canvasType, $this->canvasOwnerType, $this->canvasOwnerId);
        $canvas->entries()->whereKey($entryId)->first()?->delete();
        $this->canvasLaden();
    }

    /**
     * Spec 82 §7 · Vererbung der Team-DNA fürs Board: je Feld Herkunft + verbindlich. Nur an der Team-DNA
     * (food_dna / owner team); überall sonst leer — die anderen Hosts des Boards bleiben unverändert.
     *
     * @return array<string, array{wert: mixed, quelle_team_id: ?int, quelle: ?string, verbindlich: bool, eigen: bool, gesperrt: bool}>
     */
    public function canvasVererbung(): array
    {
        if ($this->canvasType !== 'food_dna' || $this->canvasOwnerType !== 'team') {
            return [];
        }
        $team = $this->canvasTeam();
        $namen = [];
        $out = [];
        foreach (app(\Platform\FoodAlchemist\Services\DnaResolver::class)->foodDna($team) as $key => $z) {
            $quelle = $z['quelle_team_id'];
            if ($quelle !== null && ! isset($namen[$quelle])) {
                $namen[$quelle] = (string) (Team::find($quelle)?->name ?? 'Oberteam');
            }
            $fremd = $quelle !== null && (int) $quelle !== (int) $team->id;
            $out[$key] = [
                'wert' => $z['wert'], 'quelle_team_id' => $quelle, 'quelle' => $quelle !== null ? $namen[$quelle] : null,
                'verbindlich' => $z['verbindlich'], 'eigen' => $quelle !== null && ! $fremd,
                'gesperrt' => $z['verbindlich'] && $fremd && ($z['feld']['type'] ?? '') !== 'repeatable',
            ];
        }

        return $out;
    }

    /** Felder, die dieses Team nicht schreiben darf (vom Oberteam verbindlich vorgegeben). @return list<string> */
    protected function canvasGesperrteKeys(): array
    {
        return array_keys(array_filter($this->canvasVererbung(), fn ($z) => $z['gesperrt']));
    }

    /** Verbindlich-Schalter nur im Oberteam und an der Team-DNA (Spec 82 §7). */
    public function canvasVerbindlichMoeglich(): bool
    {
        return $this->canvasType === 'food_dna' && $this->canvasOwnerType === 'team' && $this->canvasTeam()->parent_team_id === null;
    }

    /** Skalar-Feld verbindlich/nicht verbindlich schalten (Wert muss gefüllt sein). */
    public function verbindlichSchalten(string $key): void
    {
        $this->canvasVerbindlichSetzen(function (FoodAlchemistCanvas $canvas) use ($key) {
            $eintrag = $canvas->entries()->where('field_key', $key)->where('position', 0)->first();
            if ($eintrag === null) {
                return 'Zuerst einen Wert eintragen und speichern, dann verbindlich machen.';
            }
            $eintrag->update(['is_binding' => ! $eintrag->is_binding]);

            return null;
        });
    }

    /** Eintrag eines Repeatable-Feldes verbindlich/nicht verbindlich schalten. */
    public function weltVerbindlichSchalten(int $entryId): void
    {
        $this->canvasVerbindlichSetzen(function (FoodAlchemistCanvas $canvas) use ($entryId) {
            $eintrag = $canvas->entries()->whereKey($entryId)->first();
            if ($eintrag === null) {
                return 'Eintrag nicht gefunden.';
            }
            $eintrag->update(['is_binding' => ! $eintrag->is_binding]);

            return null;
        });
    }

    private function canvasVerbindlichSetzen(callable $fn): void
    {
        $svc = app(CanvasService::class);
        try {
            $svc->pruefeVerbindlichSetzen($this->canvasTeam(), Auth::user());
            if ($this->canvasType !== 'food_dna') {
                throw new \RuntimeException('Verbindlich gibt es nur an der Team-DNA.');
            }
            $hinweis = $fn($svc->canvasFor($this->canvasTeam(), $this->canvasType, $this->canvasOwnerType, $this->canvasOwnerId));
            if ($hinweis !== null) {
                $this->dispatch('fa-saved', message: $hinweis, type: 'error');
            }
        } catch (\Platform\FoodAlchemist\Exceptions\FaRechtFehltException|\RuntimeException $e) {
            $this->dispatch('fa-saved', message: $e->getMessage(), type: 'error');
        }
        $this->canvasLaden();
    }

    /** Gruppen des Boards als Reiter statt untereinander? Hosts überschreiben (Standard: nein). */
    public function canvasReiter(): bool
    {
        return false;
    }

    /** Template (Titel + Felder gruppiert) fürs Partial — Kern- plus Zusatzfelder der Team-Kette. */
    public function canvasTemplateData(): array
    {
        $svc = app(CanvasService::class);
        $tpl = $svc->template($this->canvasType);
        $tpl['felder'] = $svc->felder($this->canvasType, $this->canvasTeam());
        $gruppen = [];
        foreach ($tpl['felder'] as $f) {
            $gruppen[$f['group_name'] ?? 'Felder'][] = $f;
        }
        $tpl['gruppen'] = $gruppen;

        return $tpl;
    }

    /**
     * Aktive Schreibstile des Teams für das `ref_schreibstil`-Feld des Board-Partials.
     * Die Query lebt im Concern (nicht im Blade) — die View ruft nur den Accessor.
     */
    public function canvasSchreibstile()
    {
        return \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::visibleToTeam($this->canvasTeam())
            ->where('is_inactive', false)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
