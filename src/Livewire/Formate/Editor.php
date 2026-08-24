<?php

namespace Platform\FoodAlchemist\Livewire\Formate;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\WordingResolver;

/**
 * Format-Modul (Phase B): Voll-Editor-Modal (Concepter-Stil, Fullscreen-Dark).
 * Tabs: Identität (Marketing-Kern) · Editionen (bestehende Concepts zuordnen) ·
 * Marketing-Bilder (Hero + Galerie) · Notizen. Struktur-Edits (attach/detach/Bild)
 * persistieren sofort über den FormatService; „Speichern" sichert die Identität.
 */
class Editor extends Component
{
    use WithFileUploads;

    public ?int $id = null;

    public string $tab = 'identitaet';

    public const TABS = ['identitaet', 'editionen', 'bilder', 'notizen'];

    /** @var array<string, mixed> */
    public array $form = [];

    public string $editionSuche = '';

    /** Phase D: Name der inline neu anzulegenden Edition (Concepter 2.0). */
    public string $neueEditionName = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $bildUpload = null;

    public ?string $fehler = null;

    #[On('formate-editor.oeffnen')]
    public function oeffnen(?int $id): void
    {
        $this->reset(['form', 'editionSuche', 'bildUpload', 'fehler']);
        $this->id = $id;
        $this->tab = 'identitaet';
        if ($id === null) {
            return;
        }
        $f = app(FormatService::class)->detail($this->team(), $id);
        if ($f === null) {
            $this->id = null;

            return;
        }
        $this->form = [
            'name' => $f->name,
            'consumer_name' => $f->consumer_name ?? '',
            'claim' => $f->claim ?? '',
            'story' => $f->story ?? '',
            'origin' => $f->origin ?? '',
            'customer' => $f->customer ?? '',
            'status' => $f->status,
            'note' => $f->note ?? '',
        ];
        $this->dispatch('modal.open', name: 'formate-editor');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    #[On('modal.closed')]
    public function beimSchliessen(?string $name = null): void
    {
        if ($name === 'formate-editor') {
            $this->reset(['form', 'editionSuche', 'bildUpload', 'fehler']);
            $this->id = null;
        }
    }

    public function speichern(FormatService $formats): void
    {
        if ($this->id === null) {
            return;
        }
        $this->fehler = null;
        try {
            $formats->update($this->team(), $this->id, $this->form);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    // ── Editionen ────────────────────────────────────────────────────────────

    public function editionZuordnen(int $conceptId, FormatService $formats): void
    {
        if ($this->id === null) {
            return;
        }
        try {
            $formats->attachEdition($this->team(), $this->id, $conceptId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    public function editionLoesen(int $conceptId, FormatService $formats): void
    {
        try {
            $formats->detachEdition($this->team(), $conceptId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    /** Edition eine Position nach oben/unten (dir = -1|1). */
    public function editionVerschieben(int $conceptId, int $dir, FormatService $formats): void
    {
        if ($this->id === null) {
            return;
        }
        $ids = FoodAlchemistConcept::where('format_id', $this->id)
            ->orderBy('format_position')->orderBy('name')->pluck('id')->all();
        $pos = array_search($conceptId, $ids, true);
        if ($pos === false) {
            return;
        }
        $neu = $pos + $dir;
        if ($neu < 0 || $neu >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$neu]] = [$ids[$neu], $ids[$pos]];
        $formats->reorderEditions($this->team(), $this->id, $ids);
        $this->dispatch('formate-gespeichert');
    }

    /** Phase D: Kunden-Wording einer Edition (Unterkapitel) pflegen — Titel/Claim/Hinführung. */
    public function editionWordingSpeichern(int $conceptId, string $field, ?string $value, FormatService $formats): void
    {
        if ($this->id === null || ! in_array($field, ['consumer_name', 'claim', 'description'], true)) {
            return;
        }
        try {
            $formats->updateEditionWording($this->team(), $this->id, $conceptId, [$field => $value]);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    /** Phase D: eine neue Edition inline anlegen (mit Auto-Sektions-Gerüst). */
    public function neueEdition(FormatService $formats): void
    {
        if ($this->id === null) {
            return;
        }
        try {
            $formats->createEdition($this->team(), $this->id, $this->neueEditionName, true);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->neueEditionName = '';
        $this->dispatch('formate-gespeichert');
    }

    // ── Marketing-Bilder ──────────────────────────────────────────────────────

    public function updatedBildUpload(FormatService $formats): void
    {
        if ($this->id === null || $this->bildUpload === null) {
            return;
        }
        $this->fehler = null;
        try {
            $this->validate(['bildUpload' => 'image|max:8192']);
            $formats->storeImage($this->team(), $this->id, $this->bildUpload);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->reset('bildUpload');
            throw $e;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->reset('bildUpload');
        $this->dispatch('formate-gespeichert');
    }

    public function heroSetzen(int $imageId, FormatService $formats): void
    {
        try {
            $formats->setHero($this->team(), $imageId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    public function bildLoeschen(int $imageId, FormatService $formats): void
    {
        try {
            $formats->clearImage($this->team(), $imageId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    public function bildCaption(int $imageId, string $caption, FormatService $formats): void
    {
        try {
            $formats->setImageCaption($this->team(), $imageId, $caption);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    public function render(FormatService $formats)
    {
        $format = $this->id !== null ? $formats->detail($this->team(), $this->id) : null;

        // Editionen-Picker: team-eigene, standardisierte Concepts, die noch keinem
        // ANDEREN Format zugeordnet sind (frei ODER schon in diesem Format).
        $kandidaten = collect();
        if ($this->id !== null && $this->tab === 'editionen') {
            $team = $this->team();
            $kandidaten = FoodAlchemistConcept::visibleToTeam($team)
                ->konzepte()   // Kaskade: Editionen sind Konzepte, keine Pakete
                ->where('team_id', $team->id)
                ->standardisiert()
                ->whereNull('format_id')   // nur freie Concepts; zugeordnete stehen in der Editionen-Liste
                ->when($this->editionSuche !== '', fn ($q) => $q->where('name', 'like', '%' . $this->editionSuche . '%'))
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'status', 'format_id', 'price_per_person_cache']);
        }

        // Phase D: Live-Vorschau je Edition (Sektionen + Gerichte via WordingResolver) —
        // dieselbe Auflösung wie im Foodbook-Render, damit die Vorschau exakt dem Kunden-Dokument gleicht.
        $editionMenus = [];
        if ($this->id !== null && $this->tab === 'editionen' && $format !== null) {
            $wording = app(WordingResolver::class);
            $eds = FoodAlchemistConcept::where('format_id', $this->id)
                ->with(['slots.dish:id,name,sales_wording_standard', 'slots.package.dishes.dish:id,name,sales_wording_standard'])
                ->get();
            foreach ($eds as $ed) {
                $editionMenus[$ed->id] = $wording->gerichtZeilen($ed);
            }
        }

        return view('foodalchemist::livewire.formate.editor', [
            'format' => $format,
            'kandidaten' => $kandidaten,
            'editionMenus' => $editionMenus,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
