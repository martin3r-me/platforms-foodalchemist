<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\WissensProfilService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 52 · Grundsatz E — die Wissens-Steuerung bekommt eine Oberfläche.
 *
 * **Der Anlass ist eine Asymmetrie, die alles falsch anfühlen liess:** die ALTE Struktur
 * (Bindungen) hat seit #469 eine Kurations-UI im Wissens-Browser. Der Kanon — die neue,
 * gewinnende Ebene — hat **keine**, und die Routings haben seit `docs/wissen.md` einen
 * „Ausblick"-Eintrag und sonst nichts. Wer im UI kuratierte, pflegte also den Fallback,
 * während die Wahrheit nur per MCP erreichbar war.
 *
 * Diese Seite macht zweierlei:
 *   · **sichtbar** — je Prompt-Key das aufgelöste Profil, sein Zustand und seine Befunde
 *     ({@see WissensProfilService}). Das ist die Antwort auf „welches Wissen bekommt dieser
 *     Schritt", die es im UI vorher nirgends gab.
 *   · **einstellbar** — der Routing-Editor (feature × category → Modus + Deckel). Bewusst
 *     zuerst das Routing und nicht der Kanon: Routing ist einfache Zeilen-Pflege, der Kanon
 *     braucht eine Dossier-Suche und kommt als eigener Schritt.
 *
 * Schreibrecht: `knowledge_routings` ist **globaler Master** (keine `team_id`-Spalte) — es gilt
 * für alle Teams. Deshalb darf nur das Master-Team schreiben, gleiche Regel wie beim globalen
 * Wissen. Lesen bleibt gemeinsam.
 */
class Wissenssteuerung extends Component
{
    public string $bereich = '';

    public bool $nurBefunde = false;

    public ?string $offen = null;

    /** @var array{feature: string, category: string, mode: string, max_docs: string, max_chars_per_doc: string} */
    public array $form = [
        'feature' => '', 'category' => '', 'mode' => 'discovery', 'max_docs' => '', 'max_chars_per_doc' => '',
    ];

    public ?int $editId = null;

    public ?string $fehler = null;

    public ?string $hinweis = null;

    /**
     * Routings sind global (die Tabelle hat keine `team_id`) — sie gelten für JEDES Team.
     * Eine Fehlkonfiguration legt damit die KI-Versorgung aller Mandanten still, nicht nur die
     * eigene. Deshalb dieselbe Master-Regel wie bei globalem Wissen.
     */
    private function darfSchreiben(): bool
    {
        return TeamScope::mayWrite(null, Auth::user()?->currentTeamRelation);
    }

    public function edit(int $id): void
    {
        $this->fehler = $this->hinweis = null;
        $r = DB::table('foodalchemist_knowledge_routings')->where('id', $id)->first();
        if ($r === null) {
            $this->fehler = 'Routing-Zeile nicht gefunden.';

            return;
        }
        $this->editId = $id;
        $this->form = [
            'feature' => (string) $r->feature,
            'category' => (string) $r->category,
            'mode' => (string) $r->mode,
            'max_docs' => (string) ($r->max_docs ?? ''),
            'max_chars_per_doc' => (string) ($r->max_chars_per_doc ?? ''),
        ];
    }

    public function cancel(): void
    {
        $this->editId = null;
        $this->fehler = $this->hinweis = null;
        $this->form = ['feature' => '', 'category' => '', 'mode' => 'discovery', 'max_docs' => '', 'max_chars_per_doc' => ''];
    }

    public function save(): void
    {
        $this->fehler = $this->hinweis = null;
        if (! $this->darfSchreiben()) {
            $this->fehler = 'Wissens-Routings sind global — nur das Master-Team darf sie ändern.';

            return;
        }
        if (! in_array($this->form['mode'], ['always', 'discovery', 'grounding', 'none'], true)) {
            $this->fehler = 'Modus muss always, discovery, grounding oder none sein.';

            return;
        }

        $daten = [
            'mode' => $this->form['mode'],
            'max_docs' => $this->form['max_docs'] === '' ? null : max(0, (int) $this->form['max_docs']),
            'max_chars_per_doc' => $this->form['max_chars_per_doc'] === '' ? null : max(0, (int) $this->form['max_chars_per_doc']),
            'updated_at' => now(),
        ];

        if ($this->editId !== null) {
            DB::table('foodalchemist_knowledge_routings')->where('id', $this->editId)->update($daten);
            $this->hinweis = 'Routing gespeichert — wirkt ab dem nächsten KI-Aufruf.';
            $this->cancel();

            return;
        }

        $feature = trim($this->form['feature']);
        $category = trim($this->form['category']);
        if ($feature === '' || $category === '') {
            $this->fehler = 'Feature und Kategorie sind Pflicht.';

            return;
        }
        // (feature, category) ist unique — updateOrInsert, sonst stirbt das Anlegen am Bestand.
        DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
            ['feature' => $feature, 'category' => $category],
            $daten + ['created_at' => now()],
        );
        $this->hinweis = 'Routing gesetzt.';
        $this->cancel();
    }

    public function delete(int $id): void
    {
        $this->fehler = $this->hinweis = null;
        if (! $this->darfSchreiben()) {
            $this->fehler = 'Wissens-Routings sind global — nur das Master-Team darf sie ändern.';

            return;
        }
        DB::table('foodalchemist_knowledge_routings')->where('id', $id)->delete();
        // Ohne Zeile ist die Kategorie search-only — das ist etwas ANDERES als `none`
        // (bewusst leer). Deshalb sagen, was passiert ist, statt nur „gelöscht".
        $this->hinweis = 'Routing entfernt — die Kategorie ist für dieses Feature jetzt search-only (kein Auto-Grounding).';
    }

    public function toggleOffen(string $key): void
    {
        $this->offen = $this->offen === $key ? null : $key;
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $bericht = $team !== null
            ? app(WissensProfilService::class)->integritaet($team, $this->bereich !== '' ? $this->bereich : null)
            : ['keys' => 0, 'gesteuert' => 0, 'bewusst_leer' => 0, 'ungesteuert' => 0, 'fehlerhaft' => 0, 'blockierend' => 0, 'profile' => []];

        $profile = $this->nurBefunde
            ? array_values(array_filter($bericht['profile'], fn ($p) => $p['befunde'] !== []))
            : $bericht['profile'];

        $bereiche = collect(array_keys((array) config('foodalchemist.prompts', [])))
            ->map(fn ($k) => str_contains((string) $k, '.') ? explode('.', (string) $k, 2)[0] : (string) $k)
            ->unique()->sort()->values()->all();

        return view('foodalchemist::livewire.settings.wissenssteuerung', [
            'bericht' => $bericht,
            'profile' => $profile,
            'bereiche' => $bereiche,
            'routings' => DB::table('foodalchemist_knowledge_routings')
                ->orderBy('feature')->orderBy('category')->get(),
            'kategorien' => DB::table('foodalchemist_knowledge_categories')
                ->whereNull('deleted_at')->where('active', 1)->orderBy('slug')->pluck('slug')->all(),
            'darfSchreiben' => $this->darfSchreiben(),
            'alias' => KnowledgeContextService::ROUTING_ALIAS,
        ]);
    }
}
