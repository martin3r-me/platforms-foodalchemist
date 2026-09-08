<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 52 · C0 + D6 — das aufgelöste Regelprofil eines Prompt-Keys, mit Fingerabdruck.
 *
 * **C0 (Fingerabdruck):** Kanon, Routing und Budget beantworten heute zusammen die Frage
 * „was gilt für diesen Schritt", stehen aber in drei Tabellen und zwei Config-Bäumen. Das
 * Profil fasst sie zu EINER Aussage zusammen und hasht sie. Ändert jemand irgendwo etwas,
 * ändert sich der Fingerabdruck — das ist der billigste Weg, Drift überhaupt bemerkbar zu
 * machen, solange es keine versionierte Veröffentlichung gibt.
 *
 * **D6 (drei Zustände):** ein Schritt ist entweder
 *   · `gesteuert`    — Profil vorhanden und auflösbar,
 *   · `bewusst_leer` — ausdrücklich `none` geroutet (eine Entscheidung, kein Versehen),
 *   · `ungesteuert`  — nichts hinterlegt (Befund, siehe {@see WissensVersorgungService}),
 *   · `fehlerhaft`   — hinterlegt, aber eine Quelle löst nicht auf. **Das ist der Zustand,
 *     den es vorher nicht gab** — er sah wie `gesteuert` aus und lieferte weniger.
 *
 * ★ Warum das VOR einem Korpus-Umbau stehen muss: der Kanon hängt an
 * `knowledge_document_id`. Umbenennen ist deshalb harmlos. **Löschen und Neuanlegen nicht** —
 * der FK ist `cascade`, die Kanon-Zeile geht mit, `hasCanon()` wird `false`, und der Gateway
 * schaltet damit **die alten Bindungen wieder scharf**. Ohne diese Prüfung merkt das niemand;
 * es gäbe nur schlechtere Rezepte.
 */
class WissensProfilService
{
    public function __construct(
        private readonly KnowledgeCanonService $canon,
        private readonly KnowledgeContextService $wissen,
        private readonly AiGatewayService $gateway,
    ) {}

    /**
     * Das aufgelöste Profil eines Prompt-Keys.
     *
     * @return array<string, mixed>
     */
    public function profil(string $promptKey, Team $team, string $role = 'root'): array
    {
        $routingKey = KnowledgeContextService::routingFeatureFuer($promptKey);
        $docs = $this->canon->documentsFor('prompt_key', $promptKey, $team, $role);
        $kaputt = $this->canon->unaufloesbareZeilen($team, $promptKey);
        $hatKanonZeilen = $this->canon->hasCanon('prompt_key', $promptKey, $team, $role);

        $routing = DB::table('foodalchemist_knowledge_routings')
            ->where('feature', $routingKey)->orderBy('category')
            ->get(['category', 'mode', 'max_docs', 'max_chars_per_doc'])
            ->map(fn ($r) => [
                'category' => (string) $r->category,
                'mode' => (string) $r->mode,
                'max_docs' => $r->max_docs !== null ? (int) $r->max_docs : null,
                'max_chars_per_doc' => $r->max_chars_per_doc !== null ? (int) $r->max_chars_per_doc : null,
            ])->all();

        $pflicht = $docs->where('mode', 'pflicht')->values();
        $wennPlatz = $docs->where('mode', 'wenn_platz')->values();
        $budgetBound = (int) $this->gateway->boundBudgetFuer($promptKey)['total'];
        $pflichtZeichen = (int) $pflicht->sum('char_count');
        $wirksamesRouting = array_values(array_filter($routing, fn ($r) => $r['mode'] !== 'none'));

        // Bindungen, die scharf wuerden, wenn der Kanon verschwaende (siehe Klassen-Docblock).
        $bereich = str_contains($promptKey, '.') ? explode('.', $promptKey, 2)[0] : $promptKey;
        $lauerndeBindungen = DB::table('foodalchemist_knowledge_bindings as b')
            ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
            ->whereNull('b.deleted_at')->where('b.active', 1)->where('b.binding_type', 'layer')
            ->whereIn('b.target_key', array_unique([$promptKey, $bereich]))
            ->whereNull('d.deleted_at')->where('d.active', 1)
            ->pluck('d.slug')->map(fn ($x) => (string) $x)->all();

        $befunde = $this->befunde($kaputt, $pflichtZeichen, $budgetBound, $pflicht);
        if ($docs->isNotEmpty() && $lauerndeBindungen !== []) {
            $befunde[] = [
                'code' => 'bindung_wuerde_scharf',
                'schwere' => 'hinweis',
                'slug' => null,
                'text' => 'Dieser Key hat Kanon UND '.count($lauerndeBindungen).' aktive Bindung(en) ('
                    .implode(', ', $lauerndeBindungen).'). Die Bindungen sind heute stumm — verliert der Key '
                    .'seinen Kanon (z. B. weil ein Dossier geloescht wird), schalten sie sich STILL wieder scharf.',
            ];
        }
        // NUR `blockiert` kippt den Zustand. Ein `hinweis` (z. B. lauernde Bindungen) ist eine
        // Warnung fuer spaeter, kein Defekt von heute — sonst staenden die gesunden Kanon-Keys
        // als fehlerhaft da und die Meldung waere nach einer Woche Rauschen.
        $blockiert = array_filter($befunde, fn ($b) => $b['schwere'] === 'blockiert') !== [];
        $zustand = match (true) {
            $blockiert => 'fehlerhaft',
            $docs->isNotEmpty() || $wirksamesRouting !== [] => 'gesteuert',
            $routing !== [] => 'bewusst_leer',
            default => 'ungesteuert',
        };

        return [
            'prompt_key' => $promptKey,
            'role' => $role,
            'routing_key' => $routingKey,
            'alt_schluessel' => $routingKey !== $promptKey,
            'zustand' => $zustand,
            'pflicht' => $pflicht->map(fn ($d) => ['slug' => $d->slug, 'version' => $d->version, 'zeichen' => $d->char_count])->all(),
            'wenn_platz' => $wennPlatz->map(fn ($d) => ['slug' => $d->slug, 'version' => $d->version, 'zeichen' => $d->char_count])->all(),
            'pflicht_zeichen' => $pflichtZeichen,
            'routing' => $routing,
            'budget_bound' => $budgetBound,
            'budget_retrieval' => $this->wissen->budgetFuer($routingKey),
            // hasCanon() ohne Doc-Status vs. tatsächlich aufgelöste Docs: klaffen sie
            // auseinander, ist genau der stille Fall eingetreten.
            'kanon_zeilen_vorhanden' => $hatKanonZeilen,
            'kanon_aufgeloest' => $docs->count(),
            'lauernde_bindungen' => $lauerndeBindungen,
            'befunde' => $befunde,
            'fingerabdruck' => $this->fingerabdruck($promptKey, $role, $routingKey, $pflicht, $wennPlatz, $routing, $budgetBound),
        ];
    }

    /**
     * Fingerabdruck über alles, was das Verhalten dieses Schritts bestimmt.
     *
     * Bewusst inklusive `version` je Dossier: eine inhaltliche Überarbeitung ändert das
     * Verhalten genauso wie eine geänderte Kanon-Zeile. Und bewusst inklusive Budget — ein
     * gesenkter Deckel kappt Pflichtwissen, ohne dass eine Steuertabelle sich ändert.
     *
     * @param \Illuminate\Support\Collection<int, object> $pflicht
     * @param \Illuminate\Support\Collection<int, object> $wennPlatz
     * @param list<array<string, mixed>> $routing
     */
    private function fingerabdruck(
        string $promptKey, string $role, string $routingKey,
        $pflicht, $wennPlatz, array $routing, int $budgetBound,
    ): string {
        $teile = [
            'k' => $promptKey, 'r' => $role, 'rk' => $routingKey, 'b' => $budgetBound,
            'p' => $pflicht->map(fn ($d) => $d->slug.'@'.$d->version)->all(),
            'w' => $wennPlatz->map(fn ($d) => $d->slug.'@'.$d->version)->all(),
            'ro' => array_map(
                fn ($r) => $r['category'].':'.$r['mode'].':'.($r['max_docs'] ?? '-').':'.($r['max_chars_per_doc'] ?? '-'),
                $routing,
            ),
        ];

        return substr(hash('sha256', (string) json_encode($teile)), 0, 16);
    }

    /**
     * @param list<array<string, mixed>> $kaputt
     * @param \Illuminate\Support\Collection<int, object> $pflicht
     * @return list<array<string, mixed>>
     */
    private function befunde(array $kaputt, int $pflichtZeichen, int $budgetBound, $pflicht): array
    {
        $befunde = [];

        foreach ($kaputt as $z) {
            $befunde[] = [
                'code' => $z['grund'],
                'schwere' => $z['schwere'],
                'slug' => $z['slug'] ?? ('#'.$z['document_id']),
                'text' => match ($z['grund']) {
                    'dossier_fehlt' => 'Kanon-Zeile zeigt auf ein Dossier, das es nicht mehr gibt.',
                    'dossier_geloescht' => 'Dossier ist gelöscht — die Kanon-Zeile ist über keinen Lesepfad mehr sichtbar.',
                    'art_nie_im_prompt' => 'Dieses Dossier ist eine Ablauf-Anleitung für Agenten und gehört in keinen '
                        .'Prompt — der Generator ruft keine Werkzeuge. Aus dem Kanon nehmen; Agenten erreichen es über `ablauf.GET`.',
                    default => 'Dossier ist deaktiviert — die Zeile wird beim Prompt-Bau still übersprungen.',
                },
            ];
        }

        // Pflicht wird NIE gekappt (WissenKanonBlockTest). Reisst sie den Deckel, geht das
        // Budget nicht auf Kosten der Pflicht, sondern auf Kosten von allem anderen — der
        // Retrieval-Block schrumpft still. Deshalb ein Befund, kein Automatismus.
        if ($pflichtZeichen > $budgetBound && $budgetBound > 0) {
            $befunde[] = [
                'code' => 'pflicht_ueber_budget',
                'schwere' => 'blockiert',
                'slug' => null,
                'text' => 'Pflichtwissen ('.number_format($pflichtZeichen, 0, ',', '.').' Z.) überschreitet das Budget ('
                    .number_format($budgetBound, 0, ',', '.').' Z.). Pflicht wird nicht gekappt — es verdrängt das übrige Wissen.',
            ];
        }

        $deckel = (int) config('foodalchemist.semantic_search.dossier_max_chars', 4000);
        foreach ($pflicht as $d) {
            if ($deckel > 0 && (int) $d->char_count > $deckel) {
                $befunde[] = [
                    'code' => 'dossier_ueber_deckel',
                    'schwere' => 'hinweis',
                    'slug' => (string) $d->slug,
                    'text' => 'Pflicht-Dossier ist '.number_format((int) $d->char_count, 0, ',', '.').' Z. gross (Deckel '
                        .number_format($deckel, 0, ',', '.').' Z.) — ein Thema pro Dossier ist verletzt.',
                ];
            }
        }

        return $befunde;
    }

    /**
     * Integritäts-Lauf über die ganze Registry.
     *
     * @return array{keys: int, gesteuert: int, bewusst_leer: int, ungesteuert: int, fehlerhaft: int,
     *   blockierend: int, profile: list<array<string, mixed>>}
     */
    public function integritaet(Team $team, ?string $praefix = null): array
    {
        $profile = [];
        foreach (array_keys((array) config('foodalchemist.prompts', [])) as $key) {
            $key = (string) $key;
            $bereich = str_contains($key, '.') ? explode('.', $key, 2)[0] : $key;
            if ($praefix !== null && $praefix !== '' && $bereich !== $praefix) {
                continue;
            }
            $profile[] = $this->profil($key, $team);
        }

        $zaehl = fn (string $z) => count(array_filter($profile, fn ($p) => $p['zustand'] === $z));
        $blockierend = count(array_filter(
            $profile,
            fn ($p) => array_filter($p['befunde'], fn ($b) => $b['schwere'] === 'blockiert') !== [],
        ));

        return [
            'keys' => count($profile),
            'datenwerk_ohne_achse' => $this->datenwerkOhneAchse($team),
            'gesteuert' => $zaehl('gesteuert'),
            'bewusst_leer' => $zaehl('bewusst_leer'),
            'ungesteuert' => $zaehl('ungesteuert'),
            'fehlerhaft' => $zaehl('fehlerhaft'),
            'blockierend' => $blockierend,
            'profile' => $profile,
        ];
    }

    /**
     * Spec 52/H2 — Dossiers, die als `datenwerk` deklariert sind, aber an keiner Achse hängen.
     *
     * Ein Nachschlagewerk soll **aufgelöst** werden, nicht gesucht (Grundsatz A). Hängt es an
     * keiner Achse, passiert genau das Gegenteil: es liegt als Prosa im Suchtopf und
     * konkurriert um Rangplätze — der Fall, den die Messung „Mengen-Standard auf Platz 7"
     * gezeigt hat. Hinweis, kein Fehler: solange die Achse fehlt, ist die Suche immerhin ein Weg.
     *
     * @return list<string>
     */
    private function datenwerkOhneAchse(Team $team): array
    {
        if (! Schema::hasColumn('foodalchemist_knowledge_documents', 'art')) {
            return [];
        }

        $gebunden = [];
        foreach ($this->canon->achsenBindungen($team) as $werte) {
            foreach ($werte as $slugs) {
                foreach ($slugs as $slug) {
                    $gebunden[$slug] = true;
                }
            }
        }
        // Der Config-Baum zaehlt genauso als „aufgeloest" — sonst meldete der Bericht die
        // Achsen als Luecke, die seit jeher ueber `knowledge_axis_map` laufen.
        $map = config('foodalchemist.ai.knowledge_axis_map', []);
        foreach (is_array($map) ? $map : [] as $werte) {
            foreach (is_array($werte) ? $werte : [] as $slugs) {
                foreach (is_array($slugs) ? $slugs : [] as $slug) {
                    $gebunden[(string) $slug] = true;
                }
            }
        }

        $q = DB::table('foodalchemist_knowledge_documents')
            ->where('art', Wissensart::DATENWERK)->where('active', 1)->whereNull('deleted_at');
        TeamScope::applyVisible($q, 'team_id', $team);

        return $q->orderBy('slug')->pluck('slug')
            ->map(fn ($s) => (string) $s)
            ->reject(fn ($s) => isset($gebunden[$s]))
            ->values()->all();
    }
}
