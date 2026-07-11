<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\DB;
use Livewire\Component;

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

    public function edit(int $id): void
    {
        $z = DB::table('foodalchemist_knowledge_layers')->where('id', $id)->first();
        if ($z === null) {
            return;
        }
        $this->editId = $id;
        $this->form = ['label' => $z->label, 'description' => $z->description];
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form');
    }

    public function save(): void
    {
        if (trim((string) ($this->form['label'] ?? '')) === '') {
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
        $z = DB::table('foodalchemist_knowledge_layers')->where('id', $id)->first(['active']);
        if ($z !== null) {
            DB::table('foodalchemist_knowledge_layers')->where('id', $id)
                ->update(['active' => ! $z->active, 'updated_at' => now()]);
        }
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
