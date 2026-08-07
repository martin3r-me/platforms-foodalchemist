<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Wissens-Modul #469: Pflege des Kategorien-Vokabulars (knowledge_categories).
 * Kategorien klassifizieren Wissens-Docs (such-/filterbar) und tragen die grobe
 * Routing-Ebene (Feature × Kategorie). Slug = weiche Referenz auf knowledge_documents.category.
 * Löschen nimmt die Kategorie SAMT aller darin liegenden (löschbaren) Docs mit — echtes
 * Aufräumen; kein Undo (siehe delete()).
 */
class Wissenskategorien extends Component
{
    use InteractsWithSavedToast;

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

    /**
     * Kategorie SAMT aller darin liegenden Docs endgültig löschen (HARD, kein Undo) — echtes
     * Aufräumen. Vorher blockierte diese Methode bei genutzter Kategorie; Dominique will den
     * Korpus lieferantenweise wirklich leeren, nicht nur deaktivieren.
     *
     * Reichweite (Mandanten-sicher):
     *  - Eigene Docs (team_id = aktives Team) werden immer gelöscht.
     *  - Global geseedete Docs (team_id NULL, u.a. die Pairing-Docs) NUR wenn das aktive Team
     *    das Master-Team ist (kein parent) — sonst könnte ein Kind-Team den geteilten
     *    BHG-Korpus für alle wegräumen.
     *  - Docs FREMDER Teams werden nie angefasst. Bleiben dadurch welche an der Kategorie
     *    hängen, wird die Kategorie-Zeile bewusst NICHT entfernt (mit Hinweis).
     *
     * Was mitgeht: Aliase/Bindungen/trend_meta cascaden per FK am Doc; der Semantik-Index
     * (core_embeddings, kein FK) wird pro Doc explizit über die Core-API entfernt (best-effort).
     */
    public function delete(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $istMaster = $team !== null && $team->parent_team_id === null;

        $kat = DB::table('foodalchemist_knowledge_categories')->where('id', $id)
            ->first(['id', 'slug', 'label', 'team_id']);
        if ($kat === null) {
            return;
        }

        // Kategorie-Zeile: eigene ODER (global nur als Master). Geerbte/fremde bleiben tabu.
        $darfKategorie = TeamScope::owns($kat->team_id, $team) || ($kat->team_id === null && $istMaster);
        if (! $darfKategorie) {
            $this->fehler = "«{$kat->slug}» ist geerbtes/globales Vokabular — nur das Master-Team kann es löschen.";

            return;
        }

        // Löschbare Docs bestimmen (eigene immer; global nur als Master; fremde nie).
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('category', $kat->slug)->whereNull('deleted_at')
            ->where(function ($q) use ($team, $istMaster) {
                $q->whereRaw('1 = 0');                          // neutrale Basis, falls nichts löschbar
                if ($team !== null) {
                    $q->orWhere('team_id', $team->id);
                }
                if ($istMaster) {
                    $q->orWhereNull('team_id');
                }
            })
            ->get(['id', 'team_id']);

        if ($docs->isNotEmpty()) {
            $this->purgeEmbeddings($docs);          // best-effort, wirft nie → DB-Delete läuft immer
            DB::table('foodalchemist_knowledge_documents')->whereIn('id', $docs->pluck('id')->all())->delete();
        }

        // Hängen noch Docs fremder Teams an der Kategorie? Dann Kategorie NICHT entfernen.
        $rest = DB::table('foodalchemist_knowledge_documents')
            ->where('category', $kat->slug)->whereNull('deleted_at')->count();
        if ($rest > 0) {
            $this->fehler = "{$docs->count()} Dok(s) gelöscht; {$rest} Dok(s) anderer Teams bleiben — Kategorie «{$kat->slug}» nicht entfernt.";

            return;
        }

        DB::table('foodalchemist_knowledge_categories')->where('id', $id)->delete();
        $this->fehler = null;
        $this->savedToast("Kategorie «{$kat->label}» + {$docs->count()} Dokument(e) gelöscht");
    }

    /**
     * Semantik-Vektoren der gelöschten Docs aus dem Core-Store nehmen (kein FK am Doc). Rein
     * best-effort: ein fehlender/kaputter Provider oder Store darf den eigentlichen Löschvorgang
     * nicht kippen — deshalb schluckt der Helfer alles. Ein verwaister Vektor löst ohne Doc
     * ohnehin nie auf und wird beim nächsten knowledge-embed-Lauf nicht neu erzeugt.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $docs  Zeilen mit id + team_id
     */
    private function purgeEmbeddings($docs): void
    {
        try {
            $emb = app(\Platform\Core\Services\EmbeddingService::class);
            $globalTeam = (int) config('foodalchemist.semantic_search.global_team_id', 0);
            foreach ($docs as $d) {
                try {
                    $emb->delete(
                        $d->team_id === null ? $globalTeam : (int) $d->team_id,
                        KnowledgeEmbeddingService::ENTITY_TYPE,
                        $d->id,
                    );
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }
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
