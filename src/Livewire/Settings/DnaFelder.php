<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvasTemplate;
use Platform\FoodAlchemist\Services\CanvasService;

/**
 * Spec 82 §7 (Schritt 6) · Pflegeseite der DNA-ZUSATZFELDER: das Oberteam ergänzt je Canvas-Typ eigene Felder
 * (Kern-Felder bleiben fest, Variante A). Die Felder erscheinen im Board (Food DNA, Kunden-DNA, Foodbook, Concept,
 * Angebot) — für das Oberteam und seine Unter-Teams — und fließen in den KI-Kontext. Feldgruppen sind die Reiter
 * der Team-DNA. Nur im Oberteam; ausgeschaltete oder gelöschte Felder behalten ihre Werte, werden aber nicht mehr gelesen.
 */
class DnaFelder extends Component
{
    /** Spec 65: Bereich settings.dna-felder je Team — Sofort-Aktionen, Leiste Bearbeiten → Fertig. */
    use Concerns\MitEinstellungsSperre;

    protected function sperrBereich(): string
    {
        return 'dna-felder';
    }

    /** Typ → Beschriftung, in der Reihenfolge der Kaskade. */
    public const TYPEN = [
        'food_dna' => 'Team-DNA', 'kunde_dna' => 'Kunden-DNA', 'angebot' => 'Angebot', 'foodbook' => 'Foodbook', 'concept' => 'Concept',
    ];

    public string $typ = 'food_dna';

    /** @var array{key: string, label: string, group_name: string, type: string} */
    public array $form = ['key' => '', 'label' => '', 'group_name' => '', 'type' => 'longtext'];

    public ?string $fehler = null;

    public function updatedTyp(): void
    {
        $this->fehler = null;
    }

    public function anlegen(): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || ! array_key_exists($this->typ, self::TYPEN)) {
            return;
        }
        try {
            app(CanvasService::class)->zusatzfeldAnlegen($team, $this->typ, trim($this->form['key']), $this->form['label'], [
                'group_name' => trim($this->form['group_name']) !== '' ? trim($this->form['group_name']) : null,
                'type' => $this->form['type'],
                'position' => (int) (FoodAlchemistCanvasTemplate::where('team_id', $team->id)->where('canvas_type', $this->typ)->max('position') ?? 0) + 10,
            ]);
            $this->form = ['key' => '', 'label' => '', 'group_name' => '', 'type' => 'longtext'];
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function aktivSchalten(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $feld = $team === null ? null : FoodAlchemistCanvasTemplate::where('team_id', $team->id)->find($id);
        if ($feld !== null) {
            app(CanvasService::class)->zusatzfeldAktiv($team, $id, ! $feld->active);
        }
    }

    public function loeschen(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && FoodAlchemistCanvasTemplate::where('team_id', $team->id)->whereKey($id)->exists()) {
            app(CanvasService::class)->zusatzfeldLoeschen($team, $id);
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $felder = $team === null ? collect() : FoodAlchemistCanvasTemplate::where('team_id', $team->id)->where('canvas_type', $this->typ)
            ->orderBy('position')->orderBy('id')->get();
        $svc = app(CanvasService::class);

        return view('foodalchemist::livewire.settings.dna-felder', [
            'sperr' => $this->sperrZustand(),
            'typen' => self::TYPEN,
            'felder' => $felder,
            'kernFelder' => $svc->template($this->typ)['felder'],
        ]);
    }
}
