<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Wissens-Modul #469: Pflege des Kategorien-Vokabulars (knowledge_categories).
 * Kategorien klassifizieren Wissens-Docs (such-/filterbar) und tragen die grobe
 * Routing-Ebene (Feature × Kategorie). Slug = weiche Referenz auf knowledge_documents.category.
 * Löschen nur, wenn keine Docs die Kategorie nutzen — sonst deaktivieren (V-06).
 */
class Wissenskategorien extends Component
{
    public ?int $editId = null;

    public array $form = [];

    public array $neu = ['label' => '', 'description' => ''];

    public ?string $fehler = null;

    public function edit(int $id): void
    {
        $zeile = DB::table('foodalchemist_knowledge_categories')->where('id', $id)->first();
        if ($zeile === null) {
            return;
        }
        $this->editId = $id;
        $this->fehler = null;
        $this->form = [
            'label' => $zeile->label,
            'description' => $zeile->description,
            'sort_order' => $zeile->sort_order,
        ];
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form', 'fehler');
    }

    public function save(): void
    {
        if (trim((string) ($this->form['label'] ?? '')) === '') {
            $this->fehler = 'Label ist Pflicht.';

            return;
        }
        DB::table('foodalchemist_knowledge_categories')->where('id', $this->editId)->update([
            'label' => trim($this->form['label']),
            'description' => ($this->form['description'] ?? '') !== '' ? trim($this->form['description']) : null,
            'sort_order' => (int) ($this->form['sort_order'] ?? 0),
            'updated_at' => now(),
        ]);
        $this->cancel();
    }

    public function create(): void
    {
        $label = trim($this->neu['label']);
        if ($label === '') {
            $this->fehler = 'Label ist Pflicht.';

            return;
        }
        $slug = Str::slug($label, '_');
        $teamId = Auth::user()?->currentTeamRelation?->id;
        $exists = DB::table('foodalchemist_knowledge_categories')
            ->where('slug', $slug)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $teamId))
            ->whereNull('deleted_at')->exists();
        if ($exists) {
            $this->fehler = "Kategorie «{$slug}» existiert schon.";

            return;
        }
        $maxSort = (int) DB::table('foodalchemist_knowledge_categories')->max('sort_order');
        DB::table('foodalchemist_knowledge_categories')->insert([
            'uuid' => (string) Str::uuid7(),
            'team_id' => $teamId,
            'slug' => $slug,
            'label' => $label,
            'description' => ($this->neu['description'] ?? '') !== '' ? trim($this->neu['description']) : null,
            'sort_order' => $maxSort + 10,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->reset('neu', 'fehler');
    }

    public function toggleActive(int $id): void
    {
        $zeile = DB::table('foodalchemist_knowledge_categories')->where('id', $id)->first(['active']);
        if ($zeile !== null) {
            DB::table('foodalchemist_knowledge_categories')->where('id', $id)
                ->update(['active' => ! $zeile->active, 'updated_at' => now()]);
        }
    }

    public function delete(int $id): void
    {
        $zeile = DB::table('foodalchemist_knowledge_categories')->where('id', $id)->first(['slug']);
        if ($zeile === null) {
            return;
        }
        $nDocs = DB::table('foodalchemist_knowledge_documents')
            ->where('category', $zeile->slug)->whereNull('deleted_at')->count();
        if ($nDocs > 0) {
            $this->fehler = "«{$zeile->slug}» wird von {$nDocs} Wissens-Dok(s) genutzt — erst umhängen oder deaktivieren.";

            return;
        }
        DB::table('foodalchemist_knowledge_categories')->where('id', $id)->delete();
        $this->fehler = null;
    }

    public function render()
    {
        $rows = DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('label')->get();
        // Nutzungs-Zähler je Kategorie (Docs)
        $counts = DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at')
            ->select('category', DB::raw('COUNT(*) as n'))->groupBy('category')->pluck('n', 'category');

        return view('foodalchemist::livewire.settings.wissenskategorien', [
            'kategorien' => $rows,
            'docCounts' => $counts,
        ]);
    }
}
