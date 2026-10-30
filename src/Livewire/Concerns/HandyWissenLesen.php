<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeSearchService;
use Platform\FoodAlchemist\Services\Knowledge\WissensSichtbarkeit;
use Platform\FoodAlchemist\Support\HandyLesemodus;

/**
 * #1003 Teil 8 · Wissen am Handy (Knowledge\Browser): Dossiers als Karten mit Suche und Kategorie, Antippen öffnet das
 * Dossier lesbar über die ganze Breite. Nur lesen: nur AKTIVE Dossiers in derselben Sicht wie am Computer
 * (WissensSichtbarkeit::lesbarOderAlt, Detail über sichtbaresDoc), Suche nach dem Wortlaut ohne Embedding-Aufruf (keine KI).
 * Markdown mit demselben Sanitizer wie am Computer (inhaltGerendert: html_input strip, nur sichere Links); Tabellen bekommen
 * einen eigenen Scroll-Rahmen, interne [[Verweise]] erscheinen als Text. Die Komponente ruft handyWissen() am Anfang von render().
 */
trait HandyWissenLesen
{
    use HandyLesebereich;

    /** Am Handy laufen Öffnen, Zurück und Blättern; Anlegen, Speichern, Aktivieren, Löschen, Aliase und Kanon nicht. */
    public function handyLeseMethoden(): array
    {
        return ['select', 'handyZurueck', 'gotoPage', 'nextPage', 'previousPage', 'setPage', 'resetPage'];
    }

    public function handyZurueck(): void
    {
        $this->selectedId = null;
        $this->form = [];
    }

    private function handyWissenAktiv(): bool
    {
        return HandyLesemodus::anfrageVomHandy();
    }

    private function handyWissen()
    {
        $team = Auth::user()?->currentTeamRelation;
        $sicht = fn () => WissensSichtbarkeit::lesbarOderAlt(
            DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at'), 'team_id', $team, ablaufAusnahme: self::ABLAUF_AUSNAHME
        )->where('active', true);

        $dossier = $this->selectedId !== null ? $sicht()->where('id', $this->selectedId)->first() : null;
        if ($dossier !== null && ! isset($this->form['content_md'])) {
            $this->select((int) $dossier->id);   // Inhalt laden, falls nur die Auswahl gesetzt ist
        }

        $kategorien = DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')->orderBy('sort_order')->orderBy('label')->get();
        $mitDossiers = $sicht()->distinct()->pluck('category')->flip();
        $basis = $sicht()->when($this->filterCategory !== '', fn ($q) => $q->where('category', $this->filterCategory));
        $suche = trim($this->search);

        return view('foodalchemist::livewire.handy.wissen', [
            'dossier' => $dossier,
            'dossierFehlt' => $this->selectedId !== null && $dossier === null,
            'html' => $dossier !== null ? $this->handyMarkdown() : '',
            'kategorien' => $kategorien->filter(fn ($k) => isset($mitDossiers[$k->slug]))->pluck('label', 'slug')->all(),
            'treffer' => $dossier !== null ? null : ($suche !== ''
                ? collect(app(KnowledgeSearchService::class)->search($basis, $suche, 100, false, $team))->map(fn ($h) => (object) $h)
                : $basis->orderBy('category')->orderBy('title')->orderBy('id')->paginate(self::JE_SEITE, ['id', 'title', 'category', 'char_count'], self::SEITE)->onEachSide(1)),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    /**
     * Gerendert wie am Computer (inhaltGerendert); interne [[Ziel|Text]]-Verweise vorher als Text, Tabellen danach in einem
     * eigenen Scroll-Rahmen — so kann ein breites Dossier die Seite nie seitlich sprengen.
     */
    private function handyMarkdown(): string
    {
        $md = (string) ($this->form['content_md'] ?? '');
        $this->form['content_md'] = preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u',
            fn ($m) => trim($m[2] ?? '') !== '' ? trim($m[2]) : trim(basename(str_replace('\\', '/', $m[1]))), $md) ?? $md;
        $html = $this->inhaltGerendert();
        $this->form['content_md'] = $md;

        return str_replace(['<table>', '</table>'], ['<div class="overflow-x-auto max-w-full" data-fa-tabelle-rahmen><table>', '</table></div>'], $html);
    }
}
