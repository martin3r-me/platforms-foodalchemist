<?php

namespace Platform\FoodAlchemist\Livewire\Knowledge;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeSearchService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Wissens-Modul #469 — Pflege-Browser. v1: Doc-CRUD + Aliase + sichtbare Verdrahtung.
 * v2: Bindungen editierbar (Doc → KI-Layer / Warengruppe) + Rückwärts-Traceability
 * („was hängt an KI-Layer/Warengruppe"). Spec: 15_GITHUB/_Wissensmodul_Spec.md.
 */
class Browser extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'kat')]
    public string $filterCategory = '';

    #[Url(as: 'status')]
    public string $filterStatus = 'all';

    /** Semantik-Suche (#469): Semantik ergänzt dieselbe Textsuche wie im Generator und MCP. */
    #[Url(as: 'sem')]
    public bool $semantic = false;

    #[Url(as: 'doc')]
    public ?int $selectedId = null;

    public array $form = [];

    public string $newAlias = '';

    /** v2: neue Bindung (Doc → Einsatzort/Layer). Eine Achse. */

    /** v2: Rückwärts-Ansicht — was hängt an diesem Einsatzort. */
    /**
     * Spec 52 · F7 — der KANON am Dossier, die Gegenrichtung zur Wissenssteuerung.
     *
     * Dort steht die Packliste je Prompt-Key („was gehört in recipe.generator?"). Hier steht
     * die andere Frage, die ein Kurator genauso oft hat: „in welchen Prompts ist DIESES
     * Dossier verbindlich?" — und wie er es dorthin bekommt.
     *
     * Dass die fehlte, ist mir erst aufgefallen, als Dominique vor genau diesem Panel stand
     * und fragte „wo kann man das denn dem Kanon einstellen?" (2026-09-08). Ich hatte den
     * wirkungslosen „+ einbinden"-Knopf entfernt und nichts an seine Stelle gesetzt — die
     * H7-Asymmetrie andersherum.
     */
    public string $kanonPromptKey = '';

    public string $kanonMode = 'pflicht';

    public string $traceTarget = '';

    public string $previewPromptKey = 'recipe.generator';
    public string $previewQuery = '';
    public array $previewAxes = [];
    public string $previewLevel = '';
    public string $previewOccasion = '';
    public string $previewSector = '';
    public ?array $knowledgePreview = null;
    public ?string $previewError = null;


    public ?string $fehler = null;

    /** Nicht-blockierende Rückmeldung (Dossier über dem Deckel, Dossier inaktiv). */
    public ?string $hinweis = null;

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
        $doc = $this->sichtbaresDoc($id, ['id', 'team_id', 'slug']);
        if ($doc === null || ! TeamScope::mayWrite($doc->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = 'Fremdes Wissen — Aliasse und Verbindungen pflegt Besitzer bzw. Master-Team.';

            return null;
        }

        return $doc;
    }

    /**
     * Deep-Link/Bookmark auf ein Doc: `#[Url(as: 'doc')]` hydratisiert $selectedId aus
     * `?doc=`, ruft aber select() NICHT auf — dann bliebe $form leer und der Editor
     * (rendert bei $selected) griffe auf $form['title'] eines leeren Arrays zu (500).
     * Hier laden wir das Form nach, sodass ein direkter Link das Doc korrekt öffnet.
     */
    public function mount(): void
    {
        if (! request()->has('sem')) {
            $this->semantic = (bool) config('foodalchemist.semantic_search.enabled', false);
        }

        if ($this->selectedId !== null) {
            $this->select($this->selectedId);
        }
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
            'art' => $doc->art ?? '',
            'geltung' => array_map(fn ($v) => implode(', ', $v), json_decode($doc->geltung ?? '[]', true) ?: []),
            'datenwerte' => array_map(fn ($row) => array_replace($row, ['geltung' => array_map(fn ($v) => implode(', ', $v), $row['geltung'] ?? [])]), json_decode($doc->datenwerte ?? '[]', true) ?: []),
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
            'art' => '',
            'geltung' => [], 'datenwerte' => [],
            'active' => true,
            'content_md' => '',
        ];
    }

    public function addDatenwert(): void
    {
        $this->form['datenwerte'][] = ['kennzahl' => '', 'min' => '', 'max' => '', 'einheit' => '', 'bezug' => '', 'quelle' => '', 'geltung' => []];
    }

    public function removeDatenwert(int $index): void
    {
        unset($this->form['datenwerte'][$index]);
        $this->form['datenwerte'] = array_values($this->form['datenwerte']);
    }

    public function save(): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) { $this->fehler = 'Kein Team im Kontext.'; return; }
        try {
            $service = app(\Platform\FoodAlchemist\Services\KnowledgeService::class);
            if ($this->creating) {
                $doc = $service->create($team, $this->form + ['source' => 'ui']);
                $this->reembed((int) $doc->id);
            } else {
                $owned = $this->eigenesDoc((int) $this->selectedId);
                if ($owned === null) return;
                $doc = $service->update($team, $owned->slug, $this->form);
            }
            $this->select((int) $doc->id);
            $this->savedToast('Wissensdokument gespeichert');
        } catch (\RuntimeException $e) { $this->fehler = $e->getMessage(); }
    }

    public function toggleActive(int $id): void
    {
        $doc = DB::table('foodalchemist_knowledge_documents')->where('id', $id)->first(['active', 'team_id']);
        if ($doc === null) {
            return;
        }
        if (! TeamScope::mayWrite($doc->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = 'Fremdes Wissen — nur Besitzer bzw. Master-Team kann (de)aktivieren.';

            return;
        }
        DB::table('foodalchemist_knowledge_documents')->where('id', $id)
            ->update(['active' => ! $doc->active, 'updated_at' => now()]);
        if ($this->selectedId === $id) {
            $this->form['active'] = ! $doc->active;
        }
        // Aktivieren → embedden, Deaktivieren → purgen (queueDocument gated intern auf active).
        $this->reembed($id);
    }

    /**
     * Recall-Index (A1) nach einem Schreib-/Aktivierungs-Vorgang nachziehen. Der Browser
     * schreibt per DB::table (kein Eloquent-Observer), darum expliziter Aufruf.
     * {@see KnowledgeEmbeddingService::queueDocument} gated intern auf active (embed) bzw.
     * inaktiv/gelöscht (purge). Async über die Queue, no-op ohne Provider (Sandbox).
     */
    private function reembed(int $id): void
    {
        $doc = DB::table('foodalchemist_knowledge_documents')->where('id', $id)->first();
        if ($doc !== null) {
            app(KnowledgeEmbeddingService::class)->queueDocument($doc);
        }
    }

    /**
     * Wissensdokument endgültig löschen (HARD-Delete). Zweck ist das Aufräumen des Korpus —
     * ein Soft-Delete ließe die Zeilen liegen und verfehlte genau das. Kein Undo.
     *
     * Was mitgeht:
     *  - Aliase, Bindungen und trend_meta hängen per FK `cascadeOnDelete` am Dokument → die
     *    DB räumt sie mit. Neu hinzukommende Kind-Tabellen fallen automatisch mit, solange sie
     *    denselben FK deklarieren (kein zu pflegender Hand-Delete-Katalog).
     *  - Der Semantik-Index (Core `core_embeddings`) hat KEINEN FK → wird über die Core-API
     *    explizit entfernt, sonst blieben verwaiste Vektoren zurück. Best-effort: ein fehlender
     *    Provider/Store darf das Löschen nicht blockieren (Index-Rest löst ohne Doc nie auf).
     *
     * Nur das Besitzer-Team darf löschen — Master/geerbtes/globales Wissen ist read-only (wie
     * save()/toggleActive()). Global geseedete Docs (team_id NULL, u.a. die 767 Pairing-Docs)
     * sind damit nicht löschbar; die `nullOnDelete`-Kante an den Pairing-Ankern ist unerreichbar.
     */
    public function delete(int $id): void
    {
        $doc = $this->sichtbaresDoc($id, ['id', 'team_id', 'slug']);
        if ($doc === null) {
            return;
        }
        if (! TeamScope::mayWrite($doc->team_id, Auth::user()?->currentTeamRelation)) {
            $this->fehler = 'Fremdes Wissen — nur Besitzer bzw. Master-Team kann löschen.';

            return;
        }

        // D12: Löschen (inkl. Index-Bereinigung) liegt jetzt im KnowledgeService — Browser + MCP knowledge.DELETE teilen den Weg.
        app(\Platform\FoodAlchemist\Services\KnowledgeService::class)->delete(Auth::user()->currentTeamRelation, (string) $doc->slug);

        if ($this->selectedId === $id) {
            $this->selectedId = null;
            $this->form = [];
            $this->vorschau = true;
        }
        $this->creating = false;
        $this->fehler = null;
        $this->savedToast('Wissensdokument gelöscht');
    }

    public function addAlias(): void
    {
        if (trim($this->newAlias) === '' || $this->selectedId === null) {
            return;
        }
        $doc = $this->eigenesDoc($this->selectedId);          // MVP-037
        if ($doc === null) {
            return;
        }
        // D12: Alias-Anlage liegt jetzt im KnowledgeService (geteilt mit MCP knowledge.ALIAS add).
        try {
            app(\Platform\FoodAlchemist\Services\KnowledgeService::class)
                ->addAlias(Auth::user()->currentTeamRelation, (string) $doc->slug, $this->newAlias);
        } catch (\RuntimeException) {
            return;
        }
        $this->newAlias = '';
    }

    public function removeAlias(int $aliasId): void
    {
        // MVP-037: Eigentumsprüfung liegt jetzt im KnowledgeService (geteilt mit MCP knowledge.ALIAS remove).
        try {
            app(\Platform\FoodAlchemist\Services\KnowledgeService::class)
                ->removeAlias(Auth::user()->currentTeamRelation, $aliasId);
        } catch (\RuntimeException) {
            // still: fremde/unbekannte Alias-ID wird ignoriert (wie vorher).
        }
    }

    /**
     * ENTFERNT (Spec 52 · F3, 2026-09-08) — „Einbinden" gibt es nicht mehr.
     *
     * Hier stand `addBinding()`: ein ROHER Insert in `foodalchemist_knowledge_bindings`, an
     * `KnowledgeService::bindLayer()` vorbei und damit ohne dessen Layer-Prüfung und
     * Soft-Delete-Revive (Befund `F`: derselbe Schreibvorgang zweimal implementiert, einmal
     * mit Garantien und einmal ohne).
     *
     * Der Grund fürs Löschen ist aber nicht die Doppelung, sondern Befund `J`: die
     * Oberfläche hat den Kurator angewiesen, Dossiers „an einen Einsatzort zu binden" — an
     * `recipe.generator` und `vk.generator` bewirkte das nachweislich NICHTS, weil der Kanon
     * gewinnt. Seit F2 bewirkt es überall nichts. Ein Knopf, der nichts tut, ist schlimmer
     * als kein Knopf.
     *
     * Verbindlich machen geht jetzt über den Kanon, suchbar machen über das Routing —
     * beides in der Wissenssteuerung. {@see removeBinding()} bleibt als Aufräumweg.
     */
    /**
     * Alt-Bindung lösen — der einzige verbliebene Weg an dieser Tabelle in der Oberfläche.
     * Bleibt, weil der Rückweg offen bleiben muss: beim Abschalten standen auf demo 9
     * Alt-Bindungen, alle wirkungslos, die jemand loswerden können soll.
     */
    public function removeBinding(int $bindingId): void
    {
        // MVP-037: Eltern-Doc über die Binding-ID auflösen und Eigentum prüfen (wie removeAlias).
        $docId = DB::table('foodalchemist_knowledge_bindings')->where('id', $bindingId)->value('knowledge_document_id');
        if ($docId === null || $this->eigenesDoc((int) $docId) === null) {
            return;
        }
        DB::table('foodalchemist_knowledge_bindings')->where('id', $bindingId)->delete();
    }

    /**
     * Dossier in den Kanon eines Prompt-Keys aufnehmen.
     *
     * Über `KnowledgeCanonService::set()` und NICHT per Insert: Tenancy, Enum-Prüfung, der
     * Changelog-Guard und der Deckel-Hinweis leben dort. Der Browser hatte für Bindungen
     * einen eigenen rohen Schreibpfad — genau die Doppelung (Befund `F`), die hier nicht
     * wieder entstehen soll.
     */
    public function kanonAdd(): void
    {
        $this->fehler = null;
        if ($this->selectedId === null) {
            return;
        }
        $key = trim($this->kanonPromptKey);
        if ($key === '') {
            $this->fehler = 'Bitte einen Prompt-Key wählen.';

            return;
        }

        $doc = $this->sichtbaresDoc($this->selectedId, ['id', 'slug']);
        $team = Auth::user()?->currentTeamRelation;
        if ($doc === null || $team === null) {
            return;
        }

        try {
            $ergebnis = app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)
                ->set($team, ['scope' => 'prompt_key', 'scope_key' => $key, 'slug' => (string) $doc->slug,
                    'mode' => $this->kanonMode]);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        // Hinweise (Dossier über dem Deckel, Dossier inaktiv) sind KEINE Fehler, aber sie
        // dürfen nicht verschwinden — sonst sieht ein halb wirksamer Eintrag aus wie ein
        // ganzer.
        $this->hinweis = $ergebnis['hinweise'] !== [] ? implode(' · ', $ergebnis['hinweise']) : null;
        $this->kanonPromptKey = '';
        $this->savedToast();
    }

    /** Dossier aus dem Kanon eines Prompt-Keys nehmen (soft, reversibel). */
    public function kanonRemove(string $promptKey): void
    {
        $this->fehler = null;
        if ($this->selectedId === null) {
            return;
        }
        $doc = $this->sichtbaresDoc($this->selectedId, ['id', 'slug']);
        $team = Auth::user()?->currentTeamRelation;
        if ($doc === null || $team === null) {
            return;
        }

        try {
            app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)
                ->remove($team, 'prompt_key', $promptKey, (string) $doc->slug);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->savedToast();
    }

    public function previewKnowledge(): void
    {
        $this->knowledgePreview = null;
        $this->previewError = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->previewError = 'Kein Team im Kontext.';
            return;
        }
        try {
            $this->knowledgePreview = app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgePreviewService::class)->preview(
                $team, $this->previewPromptKey, $this->previewQuery, [
                    ...$this->previewAxes, 'level' => $this->previewLevel, 'occasion' => $this->previewOccasion, 'sektor' => $this->previewSector,
                ],
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->previewError = $exception->getMessage();
        }
    }

    public function render()
    {
        $kategorien = DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('label')->get();

        $suche = trim($this->search);
        $spalten = ['id', 'slug', 'title', 'category', 'active', 'char_count'];
        // Spec 52/H1: die Art steuert, WIE das Dossier benutzt werden darf. Vor der Migration
        // (frische Umgebung, Deploy-Reihenfolge) darf die Liste deshalb nicht sterben.
        if (\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_knowledge_documents', 'art')) {
            $spalten[] = 'art';
        }

        // Semantik-Modus (#469): Embedding-Recall, sofern aktiviert, Query nicht leer
        // und ein Provider verfügbar ist. Sonst graceful Fallback auf SQL-LIKE + Hinweis.
        $semanticNote = null;
        $semanticAktiv = false;
        if ($this->semantic && $suche !== '') {
            $semanticAktiv = app(KnowledgeEmbeddingService::class)->isProviderAvailable();
            if (! $semanticAktiv) {
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

        if ($suche !== '') {
            $hits = app(KnowledgeSearchService::class)->search($basis, $suche, 100, $semanticAktiv, Auth::user()?->currentTeamRelation);
            $docs = collect($hits)->map(static fn ($hit) => (object) $hit);
        } else {
            $docs = $basis->orderBy('category')->orderBy('title')->get($spalten);
        }

        $selected = $this->selectedId !== null
            ? $this->sichtbaresDoc($this->selectedId)          // MVP-036: nur Sichtbares ins Detail
            : null;

        // Besitzer-Team — oder das Master-Team am globalen Bestand (Entscheid 2026-09-11:
        // global heisst „gehört dem Master", nicht „gehört niemandem"). Steuert die
        // Sichtbarkeit des Löschen-Buttons; delete() prüft dasselbe nochmal serverseitig
        // (nie der Client-Sichtbarkeit vertrauen).
        $editable = $selected !== null
            && TeamScope::mayWrite($selected->team_id, Auth::user()?->currentTeamRelation);

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

        // #469 Chip-Wahrheit, Spec 52/A4 KORRIGIERT: die alte Fassung prüfte nur, ob der Slug
        // in der 7er-Kernliste steht — ohne den MODUS anzusehen. Damit behauptete sie bei
        // 158 von 165 cross_cutting-Dossiers, sie würden nicht geladen, obwohl die Generatoren
        // die Kategorie längst per `discovery` ziehen (über den ganzen Korpus, nicht über die
        // 7er-Liste). Zusätzlich sind genau diese 7 Originale seit Welle 2 DEAKTIVIERT.
        //
        // Ehrliche Auflösung, in derselben Reihenfolge wie die Laufzeit:
        //   · irgendeine `discovery`-Route auf die Kategorie → auffindbar, kein Hinweis;
        //   · nur `always`-Routen → dann entscheidet die je Feature aufgelöste Slug-Liste
        //     (`crossCuttingSlugs()` mit dem config-Override), nicht die rohe Konstante.
        $wissenService = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class);
        $ccDiscovery = $routings->contains(fn ($r) => (string) $r->mode === 'discovery');
        $ccAlwaysFeatures = $routings->filter(fn ($r) => (string) $r->mode === 'always')
            ->pluck('feature')->map(fn ($f) => (string) $f)->all();
        $ccTraegtSlug = $selected !== null && array_filter(
            $ccAlwaysFeatures,
            fn (string $f) => in_array($selected->slug, $wissenService->crossCuttingSlugs($f), true),
        ) !== [];

        $autoGeladen = $selected === null ? null
            : ($selected->category === 'cross_cutting'
                ? ($ccDiscovery || $ccTraegtSlug || $ccAlwaysFeatures === [])
                : true);

        /*
         * Spec 52 — die Rückwärts-Sicht fragt den KANON, nicht die Bindungen.
         *
         * Bis 2026-09-11 las diese Fläche `knowledge_bindings` und bot die „Einsatzorte" aus
         * `knowledge_layers` zur Auswahl an. Beide sind seit Paket 3 wirkungslos: die Laufzeit
         * liest keine Bindung mehr. Die Fläche zeigte damit neun Zeilen, die nichts steuern —
         * und anders als der Block darüber sagte sie das NICHT. Eine Kurations-Oberfläche, die
         * in eine tote Struktur weist, ist schädlicher als gar keine (Befund J).
         *
         * Die FRAGE bleibt richtig — „was hängt an diesem Arbeitsschritt?". Nur die Quelle war
         * falsch. Jetzt: die Prompt-Keys mit Kanon zur Auswahl, und darunter die Dossiers, die
         * dieser Schritt verbindlich bekommt.
         */
        $traceKeys = DB::table('foodalchemist_knowledge_canon')
            ->where('scope', 'prompt_key')->where('active', true)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', Auth::user()?->currentTeamRelation?->id))
            ->distinct()->orderBy('scope_key')->pluck('scope_key');

        $traceResults = $this->traceTarget !== '' && Auth::user()?->currentTeamRelation !== null
            ? app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)
                ->documentsFor('prompt_key', $this->traceTarget, Auth::user()->currentTeamRelation)
                ->map(fn ($d) => (object) ['id' => $d->document_id, 'title' => $d->title,
                    'category' => $d->category, 'mode' => $d->mode ?? null])
                ->sortBy('title')->values()
            : collect();

        return view('foodalchemist::livewire.knowledge.browser', [
            'inhaltHtml' => $this->vorschau ? $this->inhaltGerendert() : null,
            'dossierHinweis' => $selected
                ? app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)
                    ->groessenHinweis((int) $selected->char_count, (string) ($selected->content_md ?? ''))
                : null,
            'frontmatter' => $this->vorschau ? $this->frontmatter() : [],
            'kategorien' => $kategorien,
            'docs' => $docs,
            'selected' => $selected,
            'editable' => $editable,
            'aliases' => $aliases,
            'bindings' => $bindings,
            'routings' => $routings,
            'autoGeladen' => $autoGeladen,
            // Spec 52/A4: der Hinweis muss sagen, WELCHE Features die Kategorie fest laden —
            // sonst steht dort eine Behauptung ohne Adresse.
            'ccAlwaysFeatures' => $ccAlwaysFeatures,
            'traceKeys' => $traceKeys,
            'traceResults' => $traceResults,
            'semanticNote' => $semanticNote,
            'semanticAktiv' => $semanticAktiv,
            // Spec 52 · F7 — die Kanon-Sicht AM DOSSIER (Gegenrichtung zur Wissenssteuerung,
            // die vom Prompt-Key ausgeht). `include_inactive`, weil eine stillgelegte Zeile
            // Kuration ist und nicht verschwinden darf.
            'kanonZeilen' => ($selected === null || ($kanonTeam = Auth::user()?->currentTeamRelation) === null)
                ? []
                : collect(app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)
                    ->list($kanonTeam, 'prompt_key', null, null, includeInactive: true))
                    ->where('slug', $selected->slug)->sortBy('scope_key')->values()->all(),
            'promptKeys' => array_keys((array) config('foodalchemist.prompts', [])),
            // Kanon setzen ist KURATION, nicht Inhalts-Edit: es geht auch an geerbtem
            // Master-/Vault-Wissen (der Doc-Inhalt wird nicht angefasst) — anders als
            // `$editable`, das Besitz verlangt. Die harten Regeln (Tenancy, global nur
            // Master, Changelog-Guard) erzwingt `KnowledgeCanonService::set()`; hier steht
            // nur, ob überhaupt ein Team im Kontext ist.
            'darfKanon' => $selected !== null && Auth::user()?->currentTeamRelation !== null,
        ])->layout('platform::layouts.app');
    }
}
