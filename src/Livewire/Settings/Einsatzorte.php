<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Wissens-Modul #469: Einsatzorte/Layer (Bindungs-Ziele fürs Wissen).
 * Bereiche (grob) + KI-Prompts (fein, aus der Registry). Registry-abgeleitet →
 * kein Anlegen; pflegbar = aktiv/inaktiv + Label/Beschreibung. Der Gateway lädt
 * gebundenes Wissen bei Prompt X (exakt) oder dessen Bereich (Präfix).
 */
class Einsatzorte extends Component
{
    public ?int $editId = null;

    public array $form = [];

    public ?string $fehler = null;

    /**
     * Schreibrecht auf eine Layer-Zeile: eigene immer, global (team_id NULL) nur als
     * Master-Team, fremde niemals. Dasselbe Modell wie in `Wissenskategorien` und
     * `Knowledge\Browser` — hier fehlte es ganz.
     *
     * Warum das zählt: `toggleActive()` nahm die ID direkt vom Client. Ein Layer ist ein
     * BINDUNGS-ZIEL; abgeschaltet verliert jeder daran gebundene Prompt sein Wissen. Ein
     * Kind-Team konnte damit die KI-Versorgung aller Teams stilllegen.
     *
     * Lesen bleibt gemeinsam (die Layer sind Registry-abgeleitet und gelten für alle) —
     * gescopet ist nur das Schreiben.
     */
    private function darfAendern(?int $zeileTeamId): bool
    {
        $team = Auth::user()?->currentTeamRelation;
        $istMaster = $team !== null && $team->parent_team_id === null;

        return TeamScope::owns($zeileTeamId, $team) || ($zeileTeamId === null && $istMaster);
    }

    public function edit(int $id): void
    {
        $z = DB::table('foodalchemist_knowledge_layers')->where('id', $id)->first();
        if ($z === null || ! $this->darfAendern($z->team_id)) {
            $this->fehler = 'Geerbter/globaler Einsatzort — pflegen kann ihn nur das Besitzer-Team.';

            return;
        }
        $this->fehler = null;
        $this->editId = $id;
        $this->form = ['label' => $z->label, 'description' => $z->description];
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form', 'fehler');
    }

    public function save(): void
    {
        if (trim((string) ($this->form['label'] ?? '')) === '') {
            return;
        }
        // Erneut prüfen statt auf edit() zu vertrauen: editId ist eine Livewire-Property
        // und damit vom Client setzbar — ein Guard nur beim Laden wäre keiner.
        $z = DB::table('foodalchemist_knowledge_layers')->where('id', $this->editId)->first(['team_id']);
        if ($z === null || ! $this->darfAendern($z->team_id)) {
            $this->fehler = 'Geerbter/globaler Einsatzort — umbenennen kann nur das Besitzer-Team.';

            return;
        }
        DB::table('foodalchemist_knowledge_layers')->where('id', $this->editId)->update([
            'label' => trim($this->form['label']),
            'description' => ($this->form['description'] ?? '') !== '' ? trim($this->form['description']) : null,
            'updated_at' => now(),
        ]);
        $this->cancel();
    }

    public function toggleActive(int $id): void
    {
        $z = DB::table('foodalchemist_knowledge_layers')->where('id', $id)->first(['active', 'team_id']);
        if ($z === null || ! $this->darfAendern($z->team_id)) {
            $this->fehler = 'Geerbter/globaler Einsatzort — aktiv/inaktiv setzt nur das Besitzer-Team.';

            return;
        }
        DB::table('foodalchemist_knowledge_layers')->where('id', $id)
            ->update(['active' => ! $z->active, 'updated_at' => now()]);
    }

    public function render()
    {
        $rows = DB::table('foodalchemist_knowledge_layers')->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('label')->get();
        // Bindungs-Zähler je Layer
        $counts = DB::table('foodalchemist_knowledge_bindings')->whereNull('deleted_at')
            ->where('binding_type', 'layer')
            ->select('target_key', DB::raw('COUNT(*) as n'))->groupBy('target_key')->pluck('n', 'target_key');

        return view('foodalchemist::livewire.settings.einsatzorte', [
            'bereiche' => $rows->where('kind', 'bereich')->values(),
            'prompts' => $rows->where('kind', 'prompt')->values(),
            'bindCounts' => $counts,
        ]);
    }
}
