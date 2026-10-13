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

    /**
     * Spec 82 §9 · Listen-Felder (`liste` => true, z. B. Rollen, Checklisten der Projekt-Akte): field_key => Einträge
     * [id, value, meta, verbindlich]. Die bisherige EINE Liste (canvasWelten) bleibt für die Geschmackswelten unverändert.
     */
    public array $canvasListen = [];

    /** Eingabe je Listen-Feld: field_key => ['value' => …, <sub-key> => …]. */
    public array $canvasNeu = [];

    /** Suchtext je Listen-Feld mit Kontakt-Zusatzfeld (CRM-Kontakte verknüpfen). */
    public array $canvasKontaktSuche = [];

    /** Zeigt das Board nur diese Feldgruppe (Phase der Projekt-Akte) und ohne Reiter. */
    public ?string $canvasNurGruppe = null;

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
            if (($f['type'] ?? '') === 'repeatable' && empty($f['liste'])) {
                return $f['key'];
            }
        }

        return null;
    }

    public function canvasLaden(): void
    {
        $this->canvasForm = [];
        $this->canvasWelten = [];
        $this->canvasListen = [];
        $this->canvasVerbindlich = [];
        if ($this->canvasType === '' || $this->canvasOwnerId === null) {
            return;
        }
        $svc = app(CanvasService::class);
        $canvas = $svc->canvasFor($this->canvasTeam(), $this->canvasType, $this->canvasOwnerType, $this->canvasOwnerId);
        $werte = $svc->werte($canvas, $this->canvasTeam());
        $this->canvasVerbindlich = $svc->verbindlichkeit($canvas);
        foreach ($svc->felder($this->canvasType, $this->canvasTeam()) as $f) {
            if (($f['type'] ?? '') === 'repeatable' && ! empty($f['liste'])) {
                $this->canvasListen[$f['key']] = array_map(fn ($it) => [
                    'id' => $it['id'], 'value' => (string) $it['value'], 'meta' => $it['meta'] ?? [], 'verbindlich' => (bool) ($it['is_binding'] ?? false),
                ], $werte[$f['key']] ?? []);
            } elseif (($f['type'] ?? '') === 'repeatable') {
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

    /** Das Listen-Feld mit diesem Key (nur Felder mit `liste` => true). @return array<string,mixed>|null */
    private function canvasListenFeld(string $key): ?array
    {
        foreach (app(CanvasService::class)->felder($this->canvasType, $this->canvasTeam()) as $f) {
            if ($f['key'] === $key && ($f['type'] ?? '') === 'repeatable' && ! empty($f['liste'])) {
                return $f;
            }
        }

        return null;
    }

    /** Eintrag an ein Listen-Feld hängen: Wert + die Zusatzfelder des Feldes (Text, Zahl, Häkchen, CRM-Kontakt). */
    public function listeHinzu(string $key): void
    {
        $feld = $this->canvasListenFeld($key);
        $neu = $this->canvasNeu[$key] ?? [];
        if ($feld === null || trim((string) ($neu['value'] ?? '')) === '') {
            return;
        }
        $meta = [];
        foreach ((array) ($feld['sub'] ?? []) as $subKey => $def) {
            $typ = is_array($def) ? ($def['type'] ?? 'text') : 'text';
            $wert = $neu[$subKey] ?? null;
            $meta[$subKey] = match ($typ) {
                'check' => (bool) $wert,
                'zahl' => is_numeric($wert) ? 0 + $wert : null,
                'kontakt' => is_numeric($wert) && (int) $wert > 0 ? (int) $wert : null,
                default => trim((string) $wert) !== '' ? trim((string) $wert) : null,
            };
        }
        $svc = app(CanvasService::class);
        $canvas = $svc->canvasFor($this->canvasTeam(), $this->canvasType, $this->canvasOwnerType, $this->canvasOwnerId);
        $svc->addEntry($canvas, $key, (string) $neu['value'], $meta);
        $this->canvasNeu[$key] = [];
        $this->canvasKontaktSuche[$key] = '';
        $this->canvasLaden();
    }

    /** Häkchen (Zusatzfeld Typ „check“) eines Listen-Eintrags umschalten — nur Einträge des EIGENEN Canvas. */
    public function listeUmschalten(int $entryId, string $subKey): void
    {
        $canvas = app(CanvasService::class)->canvasFor($this->canvasTeam(), $this->canvasType, $this->canvasOwnerType, $this->canvasOwnerId);
        $eintrag = $canvas->entries()->whereKey($entryId)->first();
        $feld = $eintrag === null ? null : $this->canvasListenFeld((string) $eintrag->field_key);
        $def = $feld['sub'][$subKey] ?? null;
        if ($eintrag === null || ! is_array($def) || ($def['type'] ?? '') !== 'check') {
            return;
        }
        $meta = $eintrag->meta ?? [];
        $meta[$subKey] = ! ($meta[$subKey] ?? false);
        $eintrag->update(['meta' => $meta]);
        $this->canvasLaden();
    }

    /** Eintrag aus einem Listen-Feld entfernen (nur eigener Canvas). */
    public function listeLoeschen(int $entryId): void
    {
        $this->weltLoeschen($entryId);
    }

    /** CRM-Kontakte zur Suche eines Listen-Feldes (nur Haupt-Team-Kontakte, Treffer mit Name für die Anzeige). */
    public function canvasKontakte(string $key): array
    {
        $suche = trim((string) ($this->canvasKontaktSuche[$key] ?? ''));

        return $suche === '' ? [] : \Platform\FoodAlchemist\Support\CrmKunden::kontakte($this->canvasTeam(), $suche, 6)
            ->map(fn ($k) => ['id' => (int) $k->id, 'name' => (string) $k->full_name])->all();
    }

    /** Kontakt für den nächsten Eintrag wählen: Name wird der Wert, die Id das Zusatzfeld (nur Kontakte des eigenen Haupt-Teams). */
    public function kontaktWaehlen(string $key, int $kontaktId): void
    {
        $team = $this->canvasTeam();
        try {
            \Platform\FoodAlchemist\Support\CrmKunden::pruefe($team, null, $kontaktId);
        } catch (\RuntimeException) {
            return;
        }
        $kontakt = \Platform\Crm\Models\CrmContact::find($kontaktId);
        if ($kontakt === null) {
            return;
        }
        $this->canvasNeu[$key]['value'] = (string) $kontakt->full_name;
        $this->canvasNeu[$key]['crm_contact_id'] = $kontaktId;
        $this->canvasKontaktSuche[$key] = '';
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
        if ($this->canvasNurGruppe !== null) {   // Phase der Projekt-Akte: nur diese Gruppe
            $tpl['felder'] = array_values(array_filter($tpl['felder'], fn ($f) => ($f['group_name'] ?? 'Felder') === $this->canvasNurGruppe));
        }
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
