<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * Spec 43 — Visueller Struktur-Builder: der Nutzer gestaltet Präsentations-Designs
 * selbst (Block-Palette · Live-Vorschau · Style-Panel · Reorder). Ausgabe = ein
 * wiederverwendbares Design (layout_json + tokens_json), das Outputs über
 * `presentation_design = design:{id}` wählen. Datengebunden → strukturell interna-frei
 * (die Palette kennt keinen EK-Block).
 */
class PraesentationsDesigns extends Component
{
    use WithFileUploads;

    /** @var list<array{block_type:string, style:array}> */
    public array $layout = [];

    /** Upload-Puffer für das Bild des gewählten Bild-Blocks. */
    public $blockImageUpload = null;

    public function updatedBlockImageUpload(): void
    {
        $i = $this->selectedBlockIndex;
        if ($i === null || ! isset($this->layout[$i]) || ($this->layout[$i]['block_type'] ?? '') !== 'image' || $this->blockImageUpload === null) {
            return;
        }
        $this->validate(['blockImageUpload' => 'image|max:8192'], [], ['blockImageUpload' => 'Bild']);
        try {
            $media = app(PresentationDesignService::class)->storeBlockImage($this->team(), $this->blockImageUpload);
            $this->layout[$i]['style']['context_file_id'] = $media['context_file_id'];
            $this->layout[$i]['style']['path'] = $media['path'];
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->reset('blockImageUpload');
    }

    public function blockBildEntfernen(int $i): void
    {
        if (isset($this->layout[$i]) && ($this->layout[$i]['block_type'] ?? '') === 'image') {
            unset($this->layout[$i]['style']['context_file_id'], $this->layout[$i]['style']['path'], $this->layout[$i]['style']['url']);
        }
    }

    /** @var array<string,mixed> */
    public array $tokens = [];

    public ?int $selectedId = null;

    public string $name = '';

    public string $baseSlug = 'editorial';

    /** Form-Scoping: für welche Ausgabeformen das Design im Picker erscheint (leer = alle). */
    public array $outputTypes = [];

    /** Stufe 2: sandboxed Custom-CSS des Designs (CSS-only). */
    public ?string $customCss = null;

    /** FA-KI-Self-Service: Freitext-Wunsch → CSS. */
    public string $cssBrief = '';

    public function cssGenerieren(): void
    {
        $this->resetFeedback();
        try {
            $res = app(PresentationDesignService::class)->generateCss($this->team(), $this->cssBrief);
            $this->customCss = $res['css'];
            $this->status = 'CSS von der KI erzeugt — Live-Vorschau aktualisiert. Zum Sichern „Speichern".';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public ?int $selectedBlockIndex = null;

    /** Vorschau: gegen welche Ausgabeform + welche konkrete Quelle live gerendert wird. */
    public string $previewType = 'foodbook';

    public ?int $previewSourceId = null;

    public ?string $status = null;

    public ?string $fehler = null;

    /** Standard-Style je Block-Typ beim Hinzufügen. */
    private const BLOCK_DEFAULTS = [
        'cover' => ['align' => 'center', 'show_cover_image' => true, 'show_logo' => true],
        'chapter_loop' => ['show_price' => true, 'show_codes' => true, 'dish_columns' => 1, 'show_chapter_image' => true, 'show_dish_photos' => false],
        'dish_list' => ['show_price' => true, 'show_codes' => true],
        'price_summary' => ['mode' => 'pro_person'],
        'preis_aufschluesselung' => [],
        'legend' => [],
        'grid' => [],
        'text' => ['text' => ''],
        'heading' => ['text' => ''],
        'image' => ['context_file_id' => null, 'path' => null, 'alt' => ''],
        'spacer' => ['height' => 24],
        'cta' => [],
    ];

    public const BLOCK_LABELS = [
        'cover' => 'Cover', 'chapter_loop' => 'Kapitel-Schleife', 'dish_list' => 'Gericht-Liste',
        'price_summary' => 'Preis-Summe', 'preis_aufschluesselung' => 'Preis-Aufschlüsselung (Angebot)', 'legend' => 'Legende (LMIV)', 'grid' => 'Wochenraster',
        'text' => 'Text', 'heading' => 'Überschrift', 'image' => 'Bild', 'spacer' => 'Abstand', 'cta' => 'Call-to-Action',
    ];

    public function mount(): void
    {
        $this->neuAusBuiltin('editorial');
        $this->previewSourceId = $this->quellenOptionen()[0]['id'] ?? null;
    }

    /** Vorschau-Form gewechselt → erste passende Quelle vorbelegen. */
    public function updatedPreviewType(): void
    {
        $this->previewSourceId = $this->quellenOptionen()[0]['id'] ?? null;
    }

    // ── Design-Verwaltung ──────────────────────────────────────────────────

    public function neuAusBuiltin(string $slug): void
    {
        $b = app(PresentationDesignService::class)->builtins()[$slug] ?? app(PresentationDesignService::class)->builtins()['editorial'];
        $this->selectedId = null;
        $this->name = $b['name'] . ' (neu)';
        $this->baseSlug = $b['base_slug'];
        $this->layout = app(PresentationDesignService::class)->normalizeLayout($b['layout']);
        $this->tokens = $b['tokens'];
        $this->outputTypes = $b['output_types'] ?? [];
        $this->customCss = null;
        $this->selectedBlockIndex = null;
        $this->resetFeedback();
    }

    public function waehlen(int $id): void
    {
        $team = $this->team();
        $design = app(PresentationDesignService::class)->find($team, $id);
        if ($design === null) {
            return;
        }
        $this->selectedId = (int) $design->id;
        $this->name = (string) $design->name;
        $this->baseSlug = (string) ($design->base_slug ?: 'editorial');
        $this->layout = app(PresentationDesignService::class)->normalizeLayout($design->layout_json ?? []);
        $this->tokens = is_array($design->tokens_json) ? $design->tokens_json : [];
        $this->outputTypes = is_array($design->output_types) ? $design->output_types : [];
        $this->customCss = $design->custom_css;
        $this->selectedBlockIndex = null;
        $this->resetFeedback();
    }

    public function duplizieren(int $id): void
    {
        $team = $this->team();
        try {
            $neu = app(PresentationDesignService::class)->duplicate($team, 'design:' . $id);
            $this->waehlen((int) $neu->id);
            $this->status = 'Design dupliziert.';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function speichern(): void
    {
        $this->resetFeedback();
        $team = $this->team();
        $data = ['name' => $this->name, 'base_slug' => $this->baseSlug, 'output_types' => $this->outputTypes, 'layout_json' => $this->layout, 'tokens_json' => $this->tokens, 'custom_css' => $this->customCss];
        try {
            $design = $this->selectedId
                ? app(PresentationDesignService::class)->update($team, $this->selectedId, $data)
                : app(PresentationDesignService::class)->create($team, $data);
            $this->selectedId = (int) $design->id;
            $this->status = 'Design gespeichert.';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function loeschen(int $id): void
    {
        $team = $this->team();
        try {
            app(PresentationDesignService::class)->delete($team, $id);
            if ($this->selectedId === $id) {
                $this->neuAusBuiltin('editorial');
            }
            $this->status = 'Design gelöscht.';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── Block-Bearbeitung ──────────────────────────────────────────────────

    public function blockHinzufuegen(string $type): void
    {
        if (! in_array($type, PresentationDesignService::BLOCK_TYPES, true)) {
            return;
        }
        $this->layout[] = ['block_type' => $type, 'style' => self::BLOCK_DEFAULTS[$type] ?? []];
        $this->selectedBlockIndex = count($this->layout) - 1;
        $this->resetFeedback();
    }

    public function blockEntfernen(int $index): void
    {
        if (! isset($this->layout[$index])) {
            return;
        }
        array_splice($this->layout, $index, 1);
        $this->selectedBlockIndex = null;
        $this->resetFeedback();
    }

    public function blockWaehlen(int $index): void
    {
        $this->selectedBlockIndex = isset($this->layout[$index]) ? $index : null;
    }

    public function blockVerschieben(int $index, int $richtung): void
    {
        $ziel = $index + $richtung;
        if (! isset($this->layout[$index]) || ! isset($this->layout[$ziel])) {
            return;
        }
        [$this->layout[$index], $this->layout[$ziel]] = [$this->layout[$ziel], $this->layout[$index]];
        $this->selectedBlockIndex = $ziel;
    }

    /** Reorder aus dem Drag-&-Drop (Alpine liefert die neue Index-Reihenfolge). */
    public function bloeckeNeuOrdnen(array $reihenfolge): void
    {
        $neu = [];
        foreach ($reihenfolge as $i) {
            $i = (int) $i;
            if (isset($this->layout[$i])) {
                $neu[] = $this->layout[$i];
            }
        }
        if (count($neu) === count($this->layout)) {
            $this->layout = $neu;
            $this->selectedBlockIndex = null;
        }
    }

    /** Drag-&-Drop: Block von Position $from an Position $to schieben. */
    public function bloeckeNachDrop(int $from, int $to): void
    {
        if (! isset($this->layout[$from])) {
            return;
        }
        $item = array_splice($this->layout, $from, 1);
        if ($item === []) {
            return;
        }
        $to = max(0, min($to, count($this->layout)));
        array_splice($this->layout, $to, 0, $item);
        $this->selectedBlockIndex = $to;
    }

    public function stilSetzen(int $index, string $key, mixed $value): void
    {
        if (! isset($this->layout[$index])) {
            return;
        }
        // Checkbox-Strings normalisieren.
        if ($value === 'true' || $value === 'false') {
            $value = $value === 'true';
        }
        $this->layout[$index]['style'][$key] = $value;
    }

    public function tokenSetzen(string $gruppe, string $key, mixed $value): void
    {
        $this->tokens[$gruppe][$key] = $value;
    }

    // ── intern ─────────────────────────────────────────────────────────────

    private function resetFeedback(): void
    {
        $this->status = null;
        $this->fehler = null;
    }

    private function team(): \Platform\Core\Models\Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }

    /** @return list<array{id:int, name:string, base_slug:?string, owned:bool}> */
    private function designListe(): array
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return [];
        }

        return app(PresentationDesignService::class)->list($team)
            ->map(fn ($d) => [
                'id' => (int) $d->id,
                'name' => (string) $d->name,
                'base_slug' => $d->base_slug,
                'owned' => $d->isOwnedBy($team),
            ])->all();
    }

    /** Quellen der aktuellen Vorschau-Form (Foodbook/Speisekarte/Speiseplan). @return list<array{id:int, label:string}> */
    private function quellenOptionen(): array
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return [];
        }
        [$model, $labelCols] = match ($this->previewType) {
            'speisekarte' => [FoodAlchemistSpeisekarte::class, ['name', 'code']],
            'speiseplan' => [FoodAlchemistSpeiseplan::class, ['name']],
            default => [FoodAlchemistFoodbook::class, ['label', 'code']],
        };

        return $model::visibleToTeam($team)
            ->orderByDesc('id')->limit(50)->get()
            ->map(function ($e) use ($labelCols) {
                foreach ($labelCols as $c) {
                    if (! empty($e->{$c})) {
                        return ['id' => (int) $e->id, 'label' => (string) $e->{$c}];
                    }
                }

                return ['id' => (int) $e->id, 'label' => '#' . $e->id];
            })->all();
    }

    private function vorschauHtml(): ?string
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->previewSourceId === null) {
            return null;
        }
        try {
            $snap = app(PresentationService::class)->designPreview($team, $this->previewType, $this->previewSourceId, $this->layout, $this->tokens, $this->customCss);

            return view('foodalchemist::presentation.show', ['snapshot' => $snap])->render();
        } catch (\Throwable) {
            return null;
        }
    }

    /** URL des Bildes im aktuell gewählten Bild-Block (für die Thumbnail-Anzeige). */
    private function blockImageUrl(): ?string
    {
        $i = $this->selectedBlockIndex;
        if ($i === null || ($this->layout[$i]['block_type'] ?? '') !== 'image') {
            return null;
        }
        $st = $this->layout[$i]['style'] ?? [];
        if (empty($st['context_file_id']) && empty($st['path'])) {
            return null;
        }

        return app(FoodAlchemistMediaService::class)->url($st['context_file_id'] ?? null, $st['path'] ?? null);
    }

    public function render()
    {
        return view('foodalchemist::livewire.settings.praesentations-designs', [
            'designs' => $this->designListe(),
            'quellenOptionen' => $this->quellenOptionen(),
            'blockTypen' => PresentationDesignService::BLOCK_TYPES,
            'blockLabels' => self::BLOCK_LABELS,
            'blockImageUrl' => $this->blockImageUrl(),
            'vorschauHtml' => $this->vorschauHtml(),
        ]);
    }
}
