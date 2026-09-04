<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Support\TeamScope;
use RuntimeException;

/**
 * Planungs-/Kreativ-Session (Doppel-Diamant, Spec 08): CRUD + Trend-Einstieg + Lineage.
 *
 * Die Session ist der owner-lose Container der Divergenz-Phase (Analyse + Skizzen + Planung).
 * Sie erdet NICHTS — das „Go" (Livewire, P3) ruft die bestehenden Erzeugungs-Services und meldet
 * das Ergebnis hier über {@see verknuepfeArtefakt} zurück (Lineage: Trend → Entwurf). Team-lokal
 * (D1: visibleToTeam/isOwnedBy übers Model-Trait).
 */
class PlanningSessionService
{
    /**
     * Scope → Nomen für die ebenen-spezifische Fassung des Trend-Briefs (Etappe 4, Teil 2 Folge-Chunk).
     * Die Scope-Keys spiegeln {@see \Platform\FoodAlchemist\Livewire\Planung\Index::SCOPES}.
     */
    private const TREND_SCOPE_NOMEN = ['rezept' => 'Basisrezept', 'gericht' => 'Gericht', 'concept' => 'Konzept'];

    /** Der scope-agnostische Nomen-Block im Trend-Brief-Lead (Reihenfolge historisch, byte-gepinnt). */
    private const TREND_NOMEN_AGNOSTISCH = 'Konzept/Gericht/Basisrezept';

    public function create(Team $team, array $in): FoodAlchemistPlanningSession
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Planungs-Titel ist Pflicht.');
        }
        $mode = (string) ($in['creative_mode'] ?? 'voll_kreativ');

        return FoodAlchemistPlanningSession::create([
            'team_id' => $team->id,
            'title' => $title,
            'brief' => $this->clean($in['brief'] ?? null),
            'analysis' => $this->clean($in['analysis'] ?? null),
            'source_knowledge_document_id' => isset($in['source_knowledge_document_id']) ? (int) $in['source_knowledge_document_id'] : null,
            'creative_mode' => in_array($mode, FoodAlchemistPlanningSession::CREATIVE_MODES, true) ? $mode : 'voll_kreativ',
            'status' => 'divergenz',
            'created_via' => (string) ($in['created_via'] ?? 'ui'),
        ]);
    }

    /**
     * Session aus einem Trend eröffnen — der Trend-Kontext wandert mit: Titel, ein Start-Brief,
     * und der Trend-Inhalt als Analyse-Text (der User liest/plant daran). Setzt die Herkunft
     * (`source_knowledge_document_id`), die beim „Go" an die Artefakte durchgereicht wird.
     */
    public function ausTrend(Team $team, int $knowledgeDocumentId): FoodAlchemistPlanningSession
    {
        $doc = TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_documents')
                ->where('category', 'trend')->where('active', 1)->whereNull('deleted_at'),
            'team_id', $team
        )->where('id', $knowledgeDocumentId)->first(['id', 'title', 'content_md']);

        if ($doc === null) {
            throw new RuntimeException('Trend nicht gefunden oder nicht sichtbar.');
        }

        $fm = app(KnowledgeContextService::class)->frontmatterOf((string) $doc->content_md);
        $body = $this->bodyAuszug((string) $doc->content_md, 1200);
        $quellen = is_array($fm['quellen'] ?? null) ? implode("\n", array_map(fn ($q) => '- ' . $q, $fm['quellen'])) : '';

        $analyse = trim($body . ($quellen !== '' ? "\n\nQuellen:\n" . $quellen : ''));

        // Strukturiertes Trend-Signal (Kategorie/Klasse) — denormalisiert je Doc in trend_meta.
        // #71: NUR reviewte (`approved`) Cluster-Zuordnung fließt in den Brief. Eine `tentative`
        // (Auto-Cluster, noch nicht Review-freigegeben) wird ignoriert → `briefAusTrend` fällt auf
        // den neutralen Platzhalter zurück, statt auf einer unbestätigten Einordnung zu briefen.
        $meta = DB::table('foodalchemist_trend_meta')
            ->where('knowledge_document_id', (int) $doc->id)
            ->where('status', 'approved')
            ->first(['category', 'trend_class']);

        return $this->create($team, [
            'title' => (string) $doc->title,
            'brief' => $this->briefAusTrend($team, (string) $doc->title, (string) $doc->content_md, $meta),
            'analysis' => $analyse,
            'source_knowledge_document_id' => (int) $doc->id,
            'created_via' => 'trend',
        ]);
    }

    /**
     * Substantiver Start-Brief aus dem strukturierten Trend-Signal — deterministisch, KEINE Erfindung.
     * Statt des generischen Einzeilers fließen (a) die Einordnung (Kategorie › Klasse aus
     * {@see foodalchemist_trend_meta}) und (b) die Kernaussage (erster Prosa-Absatz des Trend-Bodys)
     * in den Brief, damit das Trendradar-Signal die Generierung wirklich erreicht. Fehlt beides
     * (kein geclustertes Meta + leerer Body), bleibt der Brief byte-identisch zum alten Platzhalter.
     */
    private function briefAusTrend(Team $team, string $title, string $md, ?object $meta): string
    {
        $zeilen = ['Aus diesem Food-Trend ein ' . self::TREND_NOMEN_AGNOSTISCH . " entwickeln: {$title}."];

        $einordnung = $this->trendEinordnung($team, $meta);
        if ($einordnung !== '') {
            $zeilen[] = 'Einordnung: ' . $einordnung . '.';
        }

        $kern = $this->bodyLead($md, 320);
        if ($kern !== '') {
            $zeilen[] = 'Kernaussage: ' . $kern;
        }

        return implode("\n", $zeilen);
    }

    /**
     * Ebenen-spezifische Fassung eines (scope-agnostisch gebauten) Trend-Briefs: ersetzt im Lead
     * »ein Konzept/Gericht/Basisrezept entwickeln« das Nomen durch das der Ziel-Ebene
     * (rezept→Basisrezept, gericht→Gericht, concept→Konzept). Nur der Lead wird berührt —
     * Einordnung/Kernaussage bleiben scope-neutral. Der Session-Brief selbst bleibt agnostisch
     * (die Session hat keine Ebene); die Ebene entsteht erst beim Übertragen ins Tab-Briefing.
     *
     * Rein & deterministisch (keine Erfindung): trägt der Brief den agnostischen Lead nicht
     * (edierter/fremder Text, unbekannter Scope), bleibt er unverändert — Fallback = Bestandsverhalten.
     * Ersetzt nur das erste Vorkommen, damit ein Titel mit derselben Phrase nicht mit-editiert wird.
     */
    public static function briefFuerScope(string $brief, string $scope): string
    {
        $nomen = self::TREND_SCOPE_NOMEN[$scope] ?? null;
        if ($nomen === null) {
            return $brief;
        }

        $agnostisch = 'ein ' . self::TREND_NOMEN_AGNOSTISCH . ' entwickeln';
        $pos = strpos($brief, $agnostisch);
        if ($pos === false) {
            return $brief;
        }

        return substr_replace($brief, 'ein ' . $nomen . ' entwickeln', $pos, strlen($agnostisch));
    }

    /**
     * Einordnung »Kategorie › Klasse« aus dem geclusterten Trend-Meta. Das Kategorie-Label kommt aus
     * der Taxonomie ({@see foodalchemist_trend_taxonomy}) — einzige Wahrheit, kein hartcodierter Katalog;
     * die Klasse trägt in `trend_class` bereits das lesbare Label (TrendClusterCommand). Leer, wenn nichts
     * zugeordnet ist.
     */
    private function trendEinordnung(Team $team, ?object $meta): string
    {
        if ($meta === null) {
            return '';
        }

        $kategorie = $this->kategorieLabel($team, trim((string) ($meta->category ?? '')));
        $klasse = trim((string) ($meta->trend_class ?? ''));

        if ($kategorie !== '' && $klasse !== '') {
            return $kategorie . ' › ' . $klasse;
        }

        return $kategorie !== '' ? $kategorie : $klasse;
    }

    /** Kategorie-Slug → lesbares Label aus der Taxonomie-Kategoriezeile (global). Fallback: der Slug selbst. */
    private function kategorieLabel(Team $team, string $slug): string
    {
        if ($slug === '') {
            return '';
        }
        $label = DB::table('foodalchemist_trend_taxonomy')
            ->whereNull('deleted_at')->where('active', 1)
            ->where('category', $slug)->whereNull('trend_class')
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $team->id))
            ->orderByRaw('team_id IS NULL')   // team-eigene Zeile vor der globalen
            ->value('description');

        return trim((string) ($label ?? '')) !== '' ? trim((string) $label) : $slug;
    }

    /** Erster Prosa-Absatz des Bodys (ohne Frontmatter/Überschriften/Listen-Marker), auf ~$max Zeichen. */
    private function bodyLead(string $md, int $max): string
    {
        $body = trim($this->strippeFrontmatter($md));
        foreach (preg_split('/\R{2,}/u', $body) ?: [] as $absatz) {
            $absatz = trim((string) $absatz);
            if ($absatz === '' || str_starts_with($absatz, '#')) {
                continue;                          // Leerblock / Überschrift überspringen
            }
            // führenden Listen-/Zitat-Marker glätten, interne Zeilenumbrüche zu einem Satz ziehen
            $absatz = trim((string) preg_replace('/^\s*[>*\-+]\s+/u', '', $absatz));
            $absatz = trim((string) preg_replace('/\s+/u', ' ', $absatz));
            if ($absatz === '') {
                continue;
            }

            return mb_strlen($absatz) > $max ? rtrim(mb_substr($absatz, 0, $max)) . '…' : $absatz;
        }

        return '';
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistPlanningSession
    {
        $session = $this->ownedSession($team, $id);
        $patch = [];
        foreach (['title', 'brief', 'analysis'] as $feld) {
            if (array_key_exists($feld, $in)) {
                $wert = $feld === 'title' ? trim((string) $in[$feld]) : $this->clean($in[$feld]);
                if ($feld === 'title' && $wert === '') {
                    throw new RuntimeException('Titel darf nicht leer sein.');
                }
                $patch[$feld] = $wert;
            }
        }
        if ($patch !== []) {
            $session->update($patch);
        }

        return $session->refresh();
    }

    public function setStatus(Team $team, int $id, string $status): FoodAlchemistPlanningSession
    {
        if (! in_array($status, FoodAlchemistPlanningSession::STATUSES, true)) {
            throw new RuntimeException("Ungültiger Status «{$status}».");
        }
        $session = $this->ownedSession($team, $id);
        $session->update(['status' => $status]);

        return $session->refresh();
    }

    public function setCreativeMode(Team $team, int $id, string $mode): FoodAlchemistPlanningSession
    {
        if (! in_array($mode, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            throw new RuntimeException("Ungültiger Kreativ-Modus «{$mode}».");
        }
        $session = $this->ownedSession($team, $id);
        $session->update(['creative_mode' => $mode]);

        return $session->refresh();
    }

    /**
     * Richtungs-Regler (Leitplanken) der Session setzen — gesetzt am Planung-Go,
     * vererbt in den Kaskaden-Fan-out (siehe PlanningCascadeService). Gefiltert gegen
     * ALLOWED_GENERATION_PARAMS (kein beliebiges JSON); leere/leerwertige Auswahl → null
     * (kein leeres {} persistieren, damit der Fan-out sauber auf „keine Regler" fällt).
     */
    public function setGenerationParams(Team $team, int $id, array $params): FoodAlchemistPlanningSession
    {
        $session = $this->ownedSession($team, $id);
        $session->update(['generation_params' => $this->filterGenerationParams($params)]);

        return $session->refresh();
    }

    /**
     * Whitelist + Leerwert-Filter + WERT-Prüfung für die Richtungs-Regler.
     *
     * Die Key-Whitelist allein reicht nicht: ein falscher WERT (»Gala« statt `dinner`)
     * lief stumm durch und lief damit ins Leere — das Achsen-Mapping löst `occasion`/`sektor`
     * deterministisch auf und findet für einen unbekannten Wert nichts. Weder Fehler noch
     * Playbook. Relevant wird das, sobald Leitplanken aus Freitext/Sprache extrahiert
     * werden statt aus Dropdowns.
     *
     * Geprüft werden nur Keys mit deklariertem Vokabular
     * ({@see FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES}); alle anderen
     * (Zahlen, Booleans, Freitext wie `aroma`) passieren unverändert.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>|null  $verworfen  füllt sich mit »key=wert«-Notizen zu allem, was
     *                                        verworfen wurde — für Aufrufer, die das dem
     *                                        Menschen zeigen wollen (Freitext-Extraktion).
     * @return array<string, mixed>|null
     */
    public function filterGenerationParams(array $params, ?array &$verworfen = null): ?array
    {
        $verworfen = [];

        $unbekannt = array_diff(array_keys($params), FoodAlchemistPlanningSession::ALLOWED_GENERATION_PARAMS);
        foreach ($unbekannt as $key) {
            $verworfen[] = $key . ' (kein Leitplanken-Regler)';
        }

        $gefiltert = array_intersect_key(
            $params,
            array_flip(FoodAlchemistPlanningSession::ALLOWED_GENERATION_PARAMS)
        );
        $gefiltert = array_filter($gefiltert, static fn ($v) => $v !== null && $v !== '' && $v !== []);

        foreach ($gefiltert as $key => $wert) {
            $erlaubt = FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES[$key] ?? null;
            if ($erlaubt === null) {
                continue;                                            // kein deklariertes Vokabular → durchlassen
            }
            if (is_array($wert)) {
                // Mehrfachauswahl (diaet_hart): jeden Eintrag einzeln prüfen, Rest behalten.
                $sauber = [];
                foreach ($wert as $einzel) {
                    if (in_array($einzel, $erlaubt, true)) {
                        $sauber[] = $einzel;
                    } else {
                        $verworfen[] = $key . '=' . (is_scalar($einzel) ? (string) $einzel : gettype($einzel));
                    }
                }
                if ($sauber === []) {
                    unset($gefiltert[$key]);
                } else {
                    $gefiltert[$key] = array_values($sauber);
                }

                continue;
            }
            if (! in_array($wert, $erlaubt, true)) {
                $verworfen[] = $key . '=' . (is_scalar($wert) ? (string) $wert : gettype($wert));
                unset($gefiltert[$key]);
            }
        }

        return $gefiltert === [] ? null : $gefiltert;
    }

    /**
     * Lineage nach dem „Go": das erzeugte Artefakt bekommt die Trend-Herkunft (first-class FK),
     * eine ggf. materialisierte Skizze wird verknüpft, die Session geht auf „konvergenz".
     * Setzt NICHTS anderes am Artefakt (Erzeugung selbst macht der jeweilige Service, draft).
     */
    public function verknuepfeArtefakt(FoodAlchemistPlanningSession $session, string $art, int $artefaktId, ?int $ideaId = null): void
    {
        $trendId = $session->source_knowledge_document_id;

        if ($art === 'recipe') {
            FoodAlchemistRecipe::whereKey($artefaktId)->update([
                'source_knowledge_document_id' => $trendId,
                'created_via' => 'plan_go',
            ]);
            if ($ideaId !== null) {
                FoodAlchemistDishIdea::whereKey($ideaId)->update([
                    'generated_recipe_id' => $artefaktId,
                    'generation_status' => 'erstellt',
                    'materialized_at' => now(),
                    'materialized_ref' => ['recipe_id' => $artefaktId],
                    'status' => 'freigegeben',
                ]);
            }
        } elseif ($art === 'concept') {
            // Concept trägt created_via schon aus generiereAusBrief (…_plan_go) — nur die Herkunft-FK.
            FoodAlchemistConcept::whereKey($artefaktId)->update([
                'source_knowledge_document_id' => $trendId,
            ]);
            if ($ideaId !== null) {
                FoodAlchemistDishIdea::whereKey($ideaId)->update([
                    'materialized_concept_id' => $artefaktId,
                    'materialized_at' => now(),
                    'materialized_ref' => ['concept_id' => $artefaktId],
                    'status' => 'freigegeben',
                ]);
            }
        } else {
            throw new RuntimeException("Unbekannter Artefakt-Typ «{$art}».");
        }

        if ($session->status === 'divergenz') {
            $session->update(['status' => 'konvergenz']);
        }
    }

    /** Team-sichtbare Sessions (neueste zuerst) — für MCP/Listen. */
    public function list(Team $team): \Illuminate\Support\Collection
    {
        return FoodAlchemistPlanningSession::visibleToTeam($team)->orderByDesc('updated_at')->get();
    }

    /** Eine team-sichtbare Session (oder null). */
    public function get(Team $team, int $id): ?FoodAlchemistPlanningSession
    {
        return FoodAlchemistPlanningSession::visibleToTeam($team)->find($id);
    }

    /**
     * Planung verwerfen = **Soft-Delete** (reversibel, kein Hard-Delete; die Zeile bleibt mit
     * `deleted_at`). Team-owned (D1): nur das Besitzer-Team darf löschen, nicht ein Kind-Team über
     * die geerbte Sichtbarkeit. Fehlt/geerbt → `ownedSession` wirft.
     */
    public function verwerfen(Team $team, int $id): void
    {
        $this->ownedSession($team, $id)->delete();
    }

    /**
     * Planung duplizieren: eine **team-eigene Kopie** (Titel „… (Kopie)"; Brief/Analyse/Kreativ-Modus/
     * `generation_params` übernommen). Bewusst ein FRISCHER Entwurf — KEIN Lauf, KEINE Skizzen, KEIN
     * `plan_concept_id`, KEIN Trend-Ursprung (die Kopie ist nicht „aus Trend"). Team-owned (D1).
     */
    public function duplizieren(Team $team, int $id): FoodAlchemistPlanningSession
    {
        $q = $this->ownedSession($team, $id);
        $kopie = $this->create($team, [
            'title' => mb_substr(trim((string) $q->title), 0, 240) . ' (Kopie)',
            'brief' => $q->brief,
            'analysis' => $q->analysis,
            'creative_mode' => $q->creative_mode,
            'created_via' => 'duplikat',
        ]);
        if (is_array($q->generation_params) && $q->generation_params !== []) {
            $kopie->update(['generation_params' => $q->generation_params]);
        }

        return $kopie->refresh();
    }

    private function ownedSession(Team $team, int $id): FoodAlchemistPlanningSession
    {
        $session = FoodAlchemistPlanningSession::visibleToTeam($team)->findOrFail($id);
        if (! $session->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Planungs-Session — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $session;
    }

    /** Body ohne YAML-Frontmatter, auf ~$max Zeichen gekürzt (für den Analyse-Prefill). */
    private function bodyAuszug(string $md, int $max): string
    {
        return mb_substr(trim($this->strippeFrontmatter($md)), 0, $max);
    }

    /** YAML-Frontmatter (inkl. BOM) vom Markdown-Kopf abtrennen. */
    private function strippeFrontmatter(string $md): string
    {
        return preg_replace('/\A\x{FEFF}?\s*---\R.*?\R---\R?/su', '', $md) ?? $md;
    }

    private function clean(mixed $wert): ?string
    {
        $s = trim((string) ($wert ?? ''));

        return $s === '' ? null : $s;
    }
}
