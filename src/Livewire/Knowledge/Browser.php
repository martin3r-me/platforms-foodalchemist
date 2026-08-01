<?php

namespace Platform\FoodAlchemist\Livewire\Knowledge;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Support\TeamScope;

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

    /**
     * Spec 28 / E15: Vorschau des Markdown statt nur Roh-Text im Textfeld.
     *
     * Der Umschalter ist eine Livewire-Eigenschaft und keine Alpine-Variable: das Textfeld
     * bindet aufgeschoben (`wire:model`), der Inhalt wird also mit DIESEM Klick mitgeschickt.
     * Eine reine Client-Umschaltung würde die Vorschau auf dem zuletzt gespeicherten Stand
     * zeigen — und es gibt keinen Markdown-Parser im Browser.
     *
     * Standard ist die LESE-Ansicht: Wissen wird viel öfter nachgeschlagen als geschrieben.
     * Der Rohtext ist die Bearbeitung, nicht der Normalfall — deshalb `true`.
     */
    public bool $vorschau = true;

    /**
     * Gerendertes Markdown. `html_input: 'strip'` ist Absicht: Wissens-Dokumente kommen auch
     * aus PDF-Destillaten und Importen — rohes HTML aus einer solchen Quelle gehört nicht
     * ungeprüft in die Seite. Links werden zusätzlich auf sichere Schemata begrenzt.
     */
    public function inhaltGerendert(): string
    {
        $md = $this->ohneDoppelteUeberschrift(
            $this->ohneFrontmatter((string) ($this->form['content_md'] ?? ''))
        );

        return $md === '' ? '' : \Illuminate\Support\Str::markdown($md, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Der YAML-Kopf gehört nicht in den Fließtext. Die Wissens-Dokumente tragen ihn (typ, zweck,
     * verwendbar_in_skills …) — CommonMark kennt ihn nicht und würde `---` als Trennstrich und
     * die Zeilen darunter als Absatz rendern. Die Vorschau begann deshalb mit „typ: … zweck: …".
     */
    private function ohneFrontmatter(string $md): string
    {
        return preg_replace('/\A\s*---\R.*?\R---\R?/s', '', $md) ?? $md;
    }

    /**
     * Fast jedes Dokument beginnt mit `# <Titel>` — derselbe Text, der in der Lese-Ansicht schon
     * als Überschrift über dem Text steht. Zweimal dasselbe kostet nur Platz, also fällt die
     * erste H1 weg, WENN sie dem Titel entspricht. Eine abweichende H1 bleibt: sie trägt dann
     * Information (z. B. ein anderer Werkstitel als der Datensatz-Name).
     */
    private function ohneDoppelteUeberschrift(string $md): string
    {
        $titel = trim((string) ($this->form['title'] ?? ''));
        if ($titel === '' || ! preg_match('/\A\s*#\s+(.+?)\s*\R/u', $md, $t)) {
            return $md;
        }

        $normal = static fn (string $s): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? $s);

        return $normal($t[1]) === $normal($titel)
            ? (preg_replace('/\A\s*#\s+.+?\R\s*/u', '', $md) ?? $md)
            : $md;
    }

    /**
     * Die Kopf-Felder als flache Liste für die Metadaten-Zeile über der Vorschau. Bewusst kein
     * YAML-Parser: die Köpfe sind flach (`schlüssel: wert`), Listen bleiben als Rohtext stehen.
     * Ein halb geparster verschachtelter Baum wäre irreführender als der sichtbare Rohwert.
     */
    public function frontmatter(): array
    {
        $md = (string) ($this->form['content_md'] ?? '');
        if (! preg_match('/\A\s*---\R(.*?)\R---/s', $md, $m)) {
            return [];
        }
        $felder = [];
        foreach (preg_split('/\R/', $m[1]) as $zeile) {
            if (preg_match('/^([a-z0-9_]+):\s*(.*)$/i', trim($zeile), $kv) && $kv[2] !== '') {
                $felder[$kv[1]] = $kv[2];
            }
        }

        return $felder;
    }

    /**
     * MVP-036/037: Ein sichtbares Dokument laden (global + eigenes Team/Master-Kette). Rohe
     * DB::table-Query, deshalb über TeamScope::applyVisible statt eines Model-Scopes. Gibt null,
     * wenn nicht sichtbar — die Aufrufer setzen dann keinen State.
     */
    private function sichtbaresDoc(int $id, array $spalten = ['*']): ?object
    {
        return TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at'),
            'team_id', Auth::user()?->currentTeamRelation
        )->where('id', $id)->first($spalten);
    }

    /**
     * Ein EIGENES Dokument für Schreibaktionen an Aliassen/Bindungen (MVP-037). Vorher schrieben
     * diese Aktionen ohne jede Eigentumsprüfung und löschten Kind-IDs quer über alle Teams.
     */
    private function eigenesDoc(int $id): ?object
    {
        $doc = $this->sichtbaresDoc($id, ['id', 'team_id']);
        if ($doc === null || ! TeamScope::owns($doc->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = 'Geerbtes/Master-Wissen — Aliasse und Bindungen pflegt nur das Besitzer-Team.';

            return null;
        }

        return $doc;
    }

    public function select(int $id): void
    {
        $this->creating = false;
        $this->fehler = null;
        $doc = $this->sichtbaresDoc($id);
        if ($doc === null) {
            return;
        }
        $this->selectedId = $id;
        // Jedes geöffnete Dokument beginnt in der Lese-Ansicht — auch wenn am vorigen noch
        // geschrieben wurde. Sonst landet man beim Nachschlagen im Rohtext.
        $this->vorschau = true;
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
        // Umgekehrt beim Anlegen: die Vorschau eines leeren Dokuments zeigt nichts.
        $this->vorschau = false;
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
            $besitz = DB::table('foodalchemist_knowledge_documents')->where('id', $this->selectedId)->first(['team_id']);
            if ($besitz === null || ! TeamScope::owns($besitz->team_id, Auth::user()?->currentTeamRelation)) {
                $this->fehler = 'Geerbtes/Master-Wissen — nur das Besitzer-Team kann bearbeiten.';

                return;
            }
            DB::table('foodalchemist_knowledge_documents')->where('id', $this->selectedId)
                ->update($payload + ['version' => DB::raw('version + 1')]);
        }
        $this->fehler = null;
    }

    public function toggleActive(int $id): void
    {
        $doc = DB::table('foodalchemist_knowledge_documents')->where('id', $id)->first(['active', 'team_id']);
        if ($doc === null) {
            return;
        }
        if (! TeamScope::owns($doc->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = 'Geerbtes/Master-Wissen — nur das Besitzer-Team kann (de)aktivieren.';

            return;
        }
        DB::table('foodalchemist_knowledge_documents')->where('id', $id)
            ->update(['active' => ! $doc->active, 'updated_at' => now()]);
        if ($this->selectedId === $id) {
            $this->form['active'] = ! $doc->active;
        }
    }

    public function addAlias(): void
    {
        $alias = Str::slug(trim($this->newAlias), '_');
        if ($alias === '' || $this->selectedId === null) {
            return;
        }
        if ($this->eigenesDoc($this->selectedId) === null) {          // MVP-037
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
        // MVP-037: Eltern-Doc über die Alias-ID auflösen und Eigentum prüfen — vorher löschte
        // die Methode jede beliebige Alias-ID quer über alle Teams.
        $docId = DB::table('foodalchemist_knowledge_aliases')->where('id', $aliasId)->value('knowledge_document_id');
        if ($docId === null || $this->eigenesDoc((int) $docId) === null) {
            return;
        }
        DB::table('foodalchemist_knowledge_aliases')->where('id', $aliasId)->delete();
    }

    /** v2: Bindung anlegen (Doc → Einsatzort/Layer) = „einbinden" aus dem Modul. */
    public function addBinding(): void
    {
        if ($this->selectedId === null) {
            return;
        }
        if ($this->eigenesDoc($this->selectedId) === null) {          // MVP-037
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
        // MVP-037: Eltern-Doc über die Binding-ID auflösen und Eigentum prüfen (wie removeAlias).
        $docId = DB::table('foodalchemist_knowledge_bindings')->where('id', $bindingId)->value('knowledge_document_id');
        if ($docId === null || $this->eigenesDoc((int) $docId) === null) {
            return;
        }
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

        // MVP-036: Liste team-scopen (global + eigenes Team/Master-Kette) — vorher las sie
        // teamübergreifend, obwohl save()/toggleActive() schon korrekt scopen.
        $basis = TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at'),
            'team_id', Auth::user()?->currentTeamRelation
        )
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
            ? $this->sichtbaresDoc($this->selectedId)          // MVP-036: nur Sichtbares ins Detail
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

        // #469 Chip-Wahrheit: der Kategorie-Routing-Chip allein ist irreführend, weil die
        // Laufzeit für cross_cutting NUR die fest verdrahtete 7er-Kernliste lädt
        // (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING), nicht jedes cross_cutting-Doc.
        // false = trotz Kategorie-Route NICHT automatisch geladen (nur via Bindung wirksam).
        // true = wird/kann geladen; null = keine Auswahl.
        $autoGeladen = $selected === null ? null
            : ($selected->category === 'cross_cutting'
                ? in_array($selected->slug, \Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING, true)
                : true);

        // v2-Ziele: pflegbare Einsatzorte/Layer
        $layers = DB::table('foodalchemist_knowledge_layers')->whereNull('deleted_at')
            ->where('active', true)->orderBy('sort_order')->orderBy('label')->get();
        $layerLabels = $layers->pluck('label', 'slug');

        // v2: Rückwärts-Ansicht — welche Docs hängen am gewählten Einsatzort
        $traceResults = $this->traceTarget !== ''
            ? TeamScope::applyVisible(
                DB::table('foodalchemist_knowledge_bindings as b')
                    ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
                    ->whereNull('b.deleted_at')->where('b.active', true)
                    ->where('b.binding_type', 'layer')->where('b.target_key', $this->traceTarget)
                    ->whereNull('d.deleted_at'),
                'd.team_id', Auth::user()?->currentTeamRelation          // MVP-036: Rückansicht nur eigene/globale Docs
            )
                ->orderBy('d.title')
                ->get(['d.id', 'd.title', 'd.category', 'b.mode'])
            : collect();

        return view('foodalchemist::livewire.knowledge.browser', [
            'inhaltHtml' => $this->vorschau ? $this->inhaltGerendert() : null,
            'frontmatter' => $this->vorschau ? $this->frontmatter() : [],
            'kategorien' => $kategorien,
            'docs' => $docs,
            'selected' => $selected,
            'aliases' => $aliases,
            'bindings' => $bindings,
            'routings' => $routings,
            'autoGeladen' => $autoGeladen,
            'layers' => $layers,
            'layerLabels' => $layerLabels,
            'traceResults' => $traceResults,
            'semanticNote' => $semanticNote,
            'semanticAktiv' => $semanticAktiv,
        ])->layout('platform::layouts.app');
    }
}
