<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * R5 (Dominique): Schreibstile als EIGENE Settings-Seite mit Anlegen +
 * Inline-Edit — vorher nur Lese-Liste in der VK-Taxonomie. `sprach_duktus`
 * ist Prompt-Material (GL-06-Feld-Hülle), darum hier voll editierbar.
 * Lösch-Schutz V-06: nur deaktivieren, nie löschen.
 */
class Schreibstile extends Component
{
    public ?int $editId = null;

    public array $form = [];

    public array $neu = ['name' => '', 'sprach_duktus' => '', 'description' => ''];

    public ?string $fehler = null;

    public function edit(int $id): void
    {
        $zeile = DB::table('foodalchemist_writing_styles')->where('id', $id)->first();
        if ($zeile === null) {
            return;
        }
        $this->editId = $id;
        $this->fehler = null;
        $this->form = ['name' => $zeile->name, 'sprach_duktus' => $zeile->sprach_duktus, 'description' => $zeile->description, 'sort_order' => $zeile->sort_order];
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form', 'fehler');
    }

    public function save(): void
    {
        if (trim((string) ($this->form['name'] ?? '')) === '' || trim((string) ($this->form['sprach_duktus'] ?? '')) === '') {
            $this->fehler = 'Name und Sprach-Duktus sind Pflicht (Prompt-Material).';

            return;
        }
        $besitz = DB::table('foodalchemist_writing_styles')->where('id', $this->editId)->first(['team_id']);
        if ($besitz === null || ! TeamScope::owns($besitz->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = 'Geerbter/Master-Schreibstil — nur das Besitzer-Team kann ändern.';

            return;
        }
        DB::table('foodalchemist_writing_styles')->where('id', $this->editId)->update([
            'name' => trim($this->form['name']),
            'sprach_duktus' => trim($this->form['sprach_duktus']),
            'description' => $this->form['description'] ?: null,
            'sort_order' => (int) ($this->form['sort_order'] ?? 0),
            'updated_at' => now(),
        ]);
        $this->cancel();
    }

    public function create(): void
    {
        $name = trim($this->neu['name']);
        if ($name === '' || trim($this->neu['sprach_duktus']) === '') {
            $this->fehler = 'Name und Sprach-Duktus sind Pflicht (Prompt-Material).';

            return;
        }
        $slug = Str::slug($name, '_');
        if (DB::table('foodalchemist_writing_styles')->where('slug', $slug)->whereNull('deleted_at')->exists()) {
            $this->fehler = "Stil «{$name}» existiert schon ({$slug}).";

            return;
        }
        DB::table('foodalchemist_writing_styles')->insert([
            'uuid' => (string) Str::uuid7(),
            'team_id' => Auth::user()?->currentTeamRelation?->id,
            'slug' => $slug,
            'name' => $name,
            'sprach_duktus' => trim($this->neu['sprach_duktus']),
            'description' => $this->neu['description'] ?: null,
            'sort_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->reset('neu', 'fehler');
    }

    public function toggleInactive(int $id): void
    {
        $zeile = DB::table('foodalchemist_writing_styles')->where('id', $id)->first(['is_inactive', 'team_id']);
        if ($zeile === null) {
            return;
        }
        if (! TeamScope::owns($zeile->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = 'Geerbter/Master-Schreibstil — nur das Besitzer-Team kann ändern.';

            return;
        }
        DB::table('foodalchemist_writing_styles')->where('id', $id)
            ->update(['is_inactive' => ! $zeile->is_inactive, 'updated_at' => now()]);
    }

    /** Phase 5: hart löschen, wenn nicht von Concepts/Foodbooks referenziert (sonst locked). */
    public function delete(int $id): void
    {
        $zeile = DB::table('foodalchemist_writing_styles')->where('id', $id)->first(['team_id', 'name']);
        if ($zeile === null) {
            return;
        }
        if (! TeamScope::owns($zeile->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = 'Geerbter/Master-Schreibstil — nur das Besitzer-Team kann löschen.';

            return;
        }
        $nConcept = \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_concepts')
            ? DB::table('foodalchemist_concepts')->whereNull('deleted_at')->where('writing_style_id', $id)->count() : 0;
        $nFoodbook = \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_foodbooks')
            ? DB::table('foodalchemist_foodbooks')->whereNull('deleted_at')->where('writing_style_id', $id)->count() : 0;
        if ($nConcept + $nFoodbook > 0) {
            $this->fehler = "Wird von {$nConcept} Concept(s) + {$nFoodbook} Foodbook(s) genutzt — erst umhängen oder deaktivieren.";

            return;
        }
        DB::table('foodalchemist_writing_styles')->where('id', $id)->delete();
        $this->fehler = null;
    }

    public function render()
    {
        return view('foodalchemist::livewire.settings.schreibstile', [
            'stile' => TeamScope::applyVisible(
                DB::table('foodalchemist_writing_styles')->whereNull('deleted_at'),
                'team_id', Auth::user()?->currentTeamRelation
            )->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }
}
