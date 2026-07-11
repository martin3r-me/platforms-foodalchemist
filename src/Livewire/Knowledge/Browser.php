<?php

namespace Platform\FoodAlchemist\Livewire\Knowledge;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;

/**
 * Wissens-Modul #469 — Pflege-Browser. v1: Doc-CRUD + Aliase + sichtbare Verdrahtung.
 * v2: Bindungen editierbar (Doc → KI-Layer / Warengruppe) + Rückwärts-Traceability
 * („was hängt an KI-Layer/Warengruppe"). Spec: 15_GITHUB/_Wissensmodul_Spec.md.
 */
class Browser extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'kat')]
    public string $filterCategory = '';

    #[Url(as: 'status')]
    public string $filterStatus = 'all';

    /** Semantik-Suche (#469): Embedding-Recall statt SQL-LIKE, wenn ein Provider verfügbar ist. */
    #[Url(as: 'sem')]
    public bool $semantic = false;

    #[Url(as: 'doc')]
    public ?int $selectedId = null;

    public array $form = [];

    public string $newAlias = '';

    /** v2: neue Bindung (Doc → Einsatzort/Layer). Eine Achse. */
    public array $newBinding = ['target_key' => '', 'mode' => 'discovery'];

    /** v2: Rückwärts-Ansicht — was hängt an diesem Einsatzort. */
    public string $traceTarget = '';

    public ?string $fehler = null;

    public bool $creating = false;

    public function select(int $id): void
    {
        $this->creating = false;
        $this->fehler = null;
        $doc = DB::table('foodalchemist_knowledge_documents')->where('id', $id)->first();
        if ($doc === null) {
            return;
        }
        $this->selectedId = $id;
        $this->form = [
            'title' => $doc->title,
            'category' => $doc->category,
            'active' => (bool) $doc->active,
            'content_md' => $doc->content_md,
        ];
    }

    public function neu(): void
    {
        $this->creating = true;
        $this->selectedId = null;
        $this->fehler = null;
        $this->form = [
            'title' => '',
            'category' => (string) DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')
                ->where('active', true)->orderBy('sort_order')->value('slug'),
            'active' => true,
            'content_md' => '',
        ];
    }

    public function save(): void
    {
        $title = trim((string) ($this->form['title'] ?? ''));
        $category = trim((string) ($this->form['category'] ?? ''));
        if ($title === '' || $category === '') {
            $this->fehler = 'Titel und Kategorie sind Pflicht.';

            return;
        }
        $content = (string) ($this->form['content_md'] ?? '');
        $payload = [
            'title' => $title,
            'category' => $category,
            'active' => (bool) ($this->form['active'] ?? true),
            'content_md' => $content,
            'char_count' => Str::length($content),
            'content_hash' => hash('sha256', $content),
            'updated_at' => now(),
        ];

        if ($this->creating) {
            $slug = Str::slug($title, '-');
            $base = $slug;
            $i = 2;
            while (DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->exists()) {
                $slug = $base . '-' . $i++;
            }
            $id = DB::table('foodalchemist_knowledge_documents')->insertGetId($payload + [
                'uuid' => (string) Str::uuid7(),
                'team_id' => Auth::user()?->currentTeamRelation?->id,
                'slug' => $slug,
                'version' => 1,
                'source_path' => null,
                'created_via' => 'ui',
                'created_at' => now(),
            ]);
            $this->creating = false;
            $this->selectedId = $id;
        } else {
            DB::table('foodalchemist_knowledge_documents')->where('id', $this->selectedId)
                ->update($payload + ['version' => DB::raw('version + 1')]);
        }
        $this->fehler = null;
    }

    public function toggleActive(int $id): void
    {
        $doc = DB::table('foodalchemist_knowledge_documents')->where('id', $id)->first(['active']);
        if ($doc !== null) {
            DB::table('foodalchemist_knowledge_documents')->where('id', $id)
                ->update(['active' => ! $doc->active, 'updated_at' => now()]);
            if ($this->selectedId === $id) {
                $this->form['active'] = ! $doc->active;
            }
        }
    }

    public function addAlias(): void
    {
        $alias = Str::slug(trim($this->newAlias), '_');
        if ($alias === '' || $this->selectedId === null) {
            return;
        }
        $exists = DB::table('foodalchemist_knowledge_aliases')
            ->where('alias_slug', $alias)->where('knowledge_document_id', $this->selectedId)->exists();
        if (! $exists) {
            DB::table('foodalchemist_knowledge_aliases')->insert([
                'alias_slug' => $alias,
                'knowledge_document_id' => $this->selectedId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->newAlias = '';
    }

    public function removeAlias(int $aliasId): void
    {
        DB::table('foodalchemist_knowledge_aliases')->where('id', $aliasId)->delete();
    }

    /** v2: Bindung anlegen (Doc → Einsatzort/Layer) = „einbinden" aus dem Modul. */
    public function addBinding(): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $target = trim((string) ($this->newBinding['target_key'] ?? ''));
        if ($target === '') {
            $this->fehler = 'Bitte einen Einsatzort wählen.';

            return;
        }
        $mode = $this->newBinding['mode'] ?: 'discovery';
        $exists = DB::table('foodalchemist_knowledge_bindings')->whereNull('deleted_at')
            ->where('knowledge_document_id', $this->selectedId)
            ->where('binding_type', 'layer')->where('target_key', $target)->exists();
        if ($exists) {
            $this->fehler = 'Diese Bindung gibt es schon.';

            return;
        }
        DB::table('foodalchemist_knowledge_bindings')->insert([
            'uuid' => (string) Str::uuid7(),
            'team_id' => Auth::user()?->currentTeamRelation?->id,
            'knowledge_document_id' => $this->selectedId,
            'binding_type' => 'layer',
            'target_key' => $target,
            'mode' => $mode,
            'weight' => 0,
            'active' => true,
            'source' => 'ui',
            'created_by' => Auth::id(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->newBinding = ['target_key' => '', 'mode' => 'discovery'];
        $this->fehler = null;
    }

    public function removeBinding(int $bindingId): void
    {
        DB::table('foodalchemist_knowledge_bindings')->where('id', $bindingId)->delete();
    }

    public function render()
    {
        $kategorien = DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('label')->get();

        $suche = trim($this->search);
        $spalten = ['id', 'slug', 'title', 'category', 'active', 'char_count'];

        // Semantik-Modus (#469): Embedding-Recall, sofern aktiviert, Query nicht leer
        // und ein Provider verfügbar ist. Sonst graceful Fallback auf SQL-LIKE + Hinweis.
        $semanticNote = null;
        $semanticIds = null;
        $semanticAktiv = false;
        if ($this->semantic && $suche !== '') {
            $svc = app(KnowledgeEmbeddingService::class);
            if ($svc->isProviderAvailable()) {
                $semanticAktiv = true;
                $semanticIds = $svc->searchDocIds($suche, 50);
                if ($semanticIds === []) {
                    $semanticNote = 'Keine semantischen Treffer — evtl. ist der Korpus noch nicht indiziert '
                        . '(php artisan foodalchemist:knowledge-embed).';
                }
            } else {
                $semanticNote = 'Semantische Suche nicht verfügbar (kein Embedding-Provider) — es wird die Textsuche genutzt.';
            }
        }

        $basis = DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at')
            ->when($this->filterCategory !== '', fn ($q) => $q->where('category', $this->filterCategory))
            ->when($this->filterStatus === 'active', fn ($q) => $q->where('active', true))
            ->when($this->filterStatus === 'inactive', fn ($q) => $q->where('active', false));

        if ($semanticAktiv) {
            // Score-Reihenfolge in PHP herstellen (DB-agnostisch, kein FIELD()).
            // Kategorie-/Status-Filter greifen weiter, der LIKE-Filter entfällt (Recall-Zweck).
            if ($semanticIds === null || $semanticIds === []) {
                $docs = collect();
            } else {
                $rows = $basis->whereIn('id', $semanticIds)->get($spalten)->keyBy('id');
                $docs = collect($semanticIds)->map(fn ($id) => $rows->get($id))->filter()->values();
            }
        } else {
            $docs = $basis
                ->when($suche !== '', function ($q) use ($suche) {
                    $s = '%' . $suche . '%';
                    $q->where(fn ($w) => $w->where('title', 'like', $s)->orWhere('slug', 'like', $s)->orWhere('content_md', 'like', $s));
                })
                ->orderBy('category')->orderBy('title')
                ->get($spalten);
        }

        $selected = $this->selectedId !== null
            ? DB::table('foodalchemist_knowledge_documents')->where('id', $this->selectedId)->first()
            : null;

        $aliases = $selected
            ? DB::table('foodalchemist_knowledge_aliases')->where('knowledge_document_id', $selected->id)
                ->orderBy('alias_slug')->get()
            : collect();

        $bindings = $selected
            ? DB::table('foodalchemist_knowledge_bindings')->whereNull('deleted_at')
                ->where('knowledge_document_id', $selected->id)
                ->orderBy('binding_type')->get()
            : collect();

        $routings = $selected
            ? DB::table('foodalchemist_knowledge_routings')->where('category', $selected->category)
                ->where('mode', '!=', 'none')->orderBy('feature')->get()
            : collect();

        // v2-Ziele: pflegbare Einsatzorte/Layer
        $layers = DB::table('foodalchemist_knowledge_layers')->whereNull('deleted_at')
            ->where('active', true)->orderBy('sort_order')->orderBy('label')->get();
        $layerLabels = $layers->pluck('label', 'slug');

        // v2: Rückwärts-Ansicht — welche Docs hängen am gewählten Einsatzort
        $traceResults = $this->traceTarget !== ''
            ? DB::table('foodalchemist_knowledge_bindings as b')
                ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
                ->whereNull('b.deleted_at')->where('b.active', true)
                ->where('b.binding_type', 'layer')->where('b.target_key', $this->traceTarget)
                ->whereNull('d.deleted_at')
                ->orderBy('d.title')
                ->get(['d.id', 'd.title', 'd.category', 'b.mode'])
            : collect();

        return view('foodalchemist::livewire.knowledge.browser', [
            'kategorien' => $kategorien,
            'docs' => $docs,
            'selected' => $selected,
            'aliases' => $aliases,
            'bindings' => $bindings,
            'routings' => $routings,
            'layers' => $layers,
            'layerLabels' => $layerLabels,
            'traceResults' => $traceResults,
            'semanticNote' => $semanticNote,
            'semanticAktiv' => $semanticAktiv,
        ])->layout('platform::layouts.app');
    }
}
