<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
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
            'source_trend_refs' => $in['source_trend_refs'] ?? null,
            'creative_mode' => in_array($mode, FoodAlchemistPlanningSession::CREATIVE_MODES, true) ? $mode : 'voll_kreativ',
            'status' => 'divergenz',
            'created_via' => (string) ($in['created_via'] ?? 'ui'),
        ]);
    }

    /** Höchstzahl kombinierter Impulse (Trends, Hypes, Fundstücke) je Planung. */
    public const TRENDRADAR_MAX = 8;

    /**
     * Spec 79 · Planung aus dem Trendradar: Session aus einer KOMBINATION von Trends, Hypes und Fundstücken
     * (Inspiration). Der Brief entsteht deterministisch (keine KI) im festen Zeilenmuster,
     * damit {@see briefFuerScope} den Lead je Ebene schärft; die Belege wandern in die Analyse.
     *
     * @param  list<int>  $trendIds
     * @param  list<int>  $fundstueckIds
     */
    public function ausTrendradar(Team $team, array $trendIds, array $inspirationIds = [], string $createdVia = 'trendradar'): FoodAlchemistPlanningSession
    {
        $k = $this->trendKombination($team, $trendIds, $inspirationIds);

        return $this->create($team, [
            'title' => $k['title'],
            'brief' => $k['brief'],
            'analysis' => $k['analysis'],
            'source_trend_refs' => $k['refs'],
            'created_via' => $createdVia,
        ]);
    }

    /** Kombination in eine bestehende Session übernehmen (Planungs-Reiter „Trendradar"). */
    public function trendradarUebernehmen(Team $team, int $sessionId, array $trendIds, array $inspirationIds = []): FoodAlchemistPlanningSession
    {
        $session = $this->ownedSession($team, $sessionId);   // nur das Besitzer-Team schreibt (D1)
        $k = $this->trendKombination($team, $trendIds, $inspirationIds);
        $session->update([
            'title' => $k['title'],
            'brief' => $k['brief'],
            'analysis' => $k['analysis'],
            'source_trend_refs' => $k['refs'],
        ]);

        return $session->refresh();
    }

    /**
     * Titel, Brief, Analyse und Herkunft aus gewählten Trends/Hypes und Inspirationen (Pinnwand-Themen mit ihren
     * Quellen). `fundstueck_ids` (einzelne Quellen, Altbestand) nehmen ihre Inspiration mit. Nur Sichtbares;
     * Unbekanntes wird abgewiesen statt still übergangen.
     *
     * @return array{title:string, brief:string, analysis:string, refs:array{trend_ids:list<int>, inspiration_ids:list<int>}}
     */
    public function trendKombination(Team $team, array $trendIds, array $inspirationIds = [], array $fundstueckIds = []): array
    {
        $trendSvc = app(TrendService::class);
        $trendIds = array_values(array_unique(array_map('intval', $trendIds)));
        $inspirationIds = $trendSvc->inspirationIdsAus($team, ['inspiration_ids' => $inspirationIds, 'fundstueck_ids' => $fundstueckIds]);
        $anzahl = count($trendIds) + count($inspirationIds);
        if ($anzahl === 0) {
            throw new RuntimeException('Bitte mindestens einen Trend, Hype oder eine Inspiration wählen.');
        }
        if ($anzahl > self::TRENDRADAR_MAX) {
            throw new RuntimeException('Höchstens '.self::TRENDRADAR_MAX.' Impulse auf einmal kombinieren.');
        }
        $trends = \Platform\FoodAlchemist\Models\FoodAlchemistTrend::visibleToTeam($team)->whereIn('id', $trendIds)->with('belege')->get()->keyBy('id');
        // Inspirationen: Lesesicht der ganzen Teamfamilie (Standorte sehen sich gegenseitig), wie die Pinnwand
        $insps = \Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration::whereIn('team_id', $trendSvc->fundstueckFamilie($team))
            ->whereIn('id', $inspirationIds)->with('quellen')->get()->keyBy('id');
        if ($trends->count() !== count($trendIds) || $insps->count() !== count($inspirationIds)) {
            throw new RuntimeException('Mindestens ein gewählter Trend oder eine Inspiration ist nicht (mehr) sichtbar.');
        }
        $V = \Platform\FoodAlchemist\Support\TrendVokabular::class;
        $kurz = fn (?string $t, int $n) => $t === null || trim($t) === '' ? '' : (mb_strlen(trim($t)) > $n ? rtrim(mb_substr(trim($t), 0, $n - 1)).'…' : trim($t));

        $namen = [];
        $zeilen = ['Aus diesen Impulsen aus dem Trendradar ein '.self::TREND_NOMEN_AGNOSTISCH.' entwickeln.'];
        $analyse = [];
        foreach ($trendIds as $id) {
            $t = $trends[$id];
            $namen[] = $t->name;
            $art = $t->typ === 'hype' ? 'Hype' : 'Trend';
            $einordnung = array_filter([$t->ebene ? $V::EBENEN[$t->ebene] : null, $t->kategorie ? $V::KATEGORIEN[$t->kategorie] : null]);
            $zeilen[] = $art.($einordnung ? ' ('.implode(', ', $einordnung).')' : '').': '.$t->name
                .(($d = $kurz($t->definition, 260)) !== '' ? ' — '.$d : '');
            $belege = $t->belege->take(6)->map(fn ($b) => '  - '.($b->titel ?: ($V::QUELLEN[$b->quelle] ?? $b->quelle)).($b->url ? ' ('.$b->url.')' : ''))->implode("\n");
            $analyse[] = $art.': '.$t->name.' · Konfidenz '.$V::KONFIDENZ[$t->wirksameKonfidenz()].($belege !== '' ? "\n".$belege : '');
        }
        foreach ($inspirationIds as $id) {
            $i = $insps[$id];
            $namen[] = $i->titel;
            $notiz = $i->quellen->pluck('notiz')->filter()->first();
            $orte = $i->quellen->pluck('fundort')->filter()->unique()->take(3)->implode(', ');
            $zeilen[] = 'Inspiration: '.$i->titel
                .(($n = $kurz($notiz, 200)) !== '' ? ' — '.$n : '')
                .($orte !== '' ? ' (gesehen: '.$orte.')' : '');
            $quellen = $i->quellen->take(6)->map(fn ($b) => '  - '.($b->titel ?: ($V::QUELLEN[$b->quelle] ?? $b->quelle)).($b->url ? ' ('.$b->url.')' : '').($b->datei_name ? ' · Datei: '.$b->datei_name : ''))->implode("\n");
            $analyse[] = 'Inspiration: '.$i->titel.' · '.$i->quellen->count().' Quelle(n)'.($quellen !== '' ? "\n".$quellen : '');
        }
        if ($anzahl > 1) {
            $zeilen[] = 'Die Impulse verbinden, nicht nebeneinanderstellen.';
        }
        $title = $kurz(implode(' + ', $namen), 150);

        return [
            'title' => $title !== '' ? $title : 'Aus dem Trendradar',
            'brief' => implode("\n", $zeilen),
            // Erste Zeile = Anzeigename der Planung (Leitstelle zeigt den Anfang der Analyse), danach die Belege
            'analysis' => ($title !== '' ? $title : 'Aus dem Trendradar')."\n\nAus dem Trendradar:\n".implode("\n", $analyse),
            'refs' => ['trend_ids' => $trendIds, 'inspiration_ids' => $inspirationIds],
        ];
    }

    /**
     * Inspirations-IDs einer Session-Herkunft (`source_trend_refs`), Altbestand `fundstueck_ids` eingerechnet.
     *
     * @return list<int>
     */
    public function refsInspirationen(Team $team, ?array $refs): array
    {
        return app(TrendService::class)->inspirationIdsAus($team, [
            'inspiration_ids' => $refs['inspiration_ids'] ?? [], 'fundstueck_ids' => $refs['fundstueck_ids'] ?? [],
        ]);
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
        // Spec 77a: ab Kuratieren (Mitglied); ohne angemeldeten Benutzer (System) keine Prüfung
        app(\Platform\FoodAlchemist\Services\FaRechte::class)->pruefeAngemeldet($team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren, 'Planungs-Status setzen');

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
        if ($art === 'recipe') {
            FoodAlchemistRecipe::whereKey($artefaktId)->update(['created_via' => 'plan_go']);
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
            // Concept trägt created_via schon aus generiereAusBrief (…_plan_go); die Herkunft steht an der Session.
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

    private function clean(mixed $wert): ?string
    {
        $s = trim((string) ($wert ?? ''));

        return $s === '' ? null : $s;
    }
}
