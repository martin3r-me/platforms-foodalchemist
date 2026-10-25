<?php

namespace Platform\FoodAlchemist\Services\GpAbgleich;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAbgleichLog;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\MatchService;
use Platform\FoodAlchemist\Services\NonFoodService;

/**
 * EIN Abgleich-Dienst „Lieferantenartikel → GP“ (Entscheid Dominique 25.10.2026, Plan Matching_Plan_2026-10-25): dieselbe Entscheidung für Modal (PR B),
 * ClassifyLaJob/Mint, MCP `la.GP_ABGLEICH` und den Eval-Befehl. Die Tools spiegeln die Pipeline, indem sie NUR diesen Dienst rufen.
 *
 *   S0 exakte Brücke (EAN / Artikelnummer / bereits gemappter Zwilling) → verknüpfen, Sicherheit 1,0, 0 KI-Calls
 *   S1 Felder ({@see LaFelderService}: vorhandene Klassifikation, sonst gp.suggest)
 *   S2–S5 Kandidaten, harte Filter, Merkmals-Diff, Guard-Vorschau ({@see GpKandidatenSucher})
 *   S6 KI-Entscheider `gp.la_abgleich` (max. 5 Kandidaten; die KI darf nur aus der Liste wählen)
 *   S7 Aktion: verknüpfen (Guard hart) | neu (Felder → createGp + anlageGuard) | unsicher (Vorschlag)
 *
 * Automatisch (ohne Rückfrage) wird NUR verknüpft bei exakter Brücke ODER (Sicherheit ≥ 0,90 UND Rang 1 deterministisch = KI-Wahl UND Merkmals-Diff ohne
 * „abweichend“ UND Guard ok UND GP `approved`). Alles darunter ist ein Vorschlag. „neu“ wird nie automatisch angelegt.
 * Mandanten: zeigt der Abgleich auf ein globales Stamm-GP, das dem Team nicht gehört, verknüpft der Dienst NICHT (alternative = eigenes GP im Mandanten). Die
 * Overlay-Verknüpfung für Teams mit Stamm-Paket-Abo (nur in der Mandantensicht, Master unverändert) ist ein eigener Schritt.
 */
class GpAbgleichService
{
    public const SCHWELLE_AUTO = 0.90;

    public const MAX_KANDIDATEN = 5;

    /** @var list<int> ai_call_log-IDs des laufenden Abgleichs (siehe {@see AbgleichErgebnis::$kiLogIds}) */
    private array $kiLogIds = [];

    public function __construct(
        private readonly LeadLaService $lead,
        private readonly GpKandidatenSucher $sucher,
        private readonly LaFelderService $felder,
        private readonly MatchService $match,
        private readonly AiGatewayService $ki,
        private readonly GpNamingService $naming,
        private readonly NonFoodService $nonFood,
    ) {}

    /**
     * Entscheidung für EINEN Artikel. Schreibt nichts (Log und Ausführung: {@see self::entscheideProtokolliert()}, {@see self::fuehreAus()}).
     *
     * @param  array{ki?: bool, ignoriere_verknuepfung?: bool, max_kandidaten?: int, nur_exakt?: bool}  $opt
     */
    public function entscheide(Team $team, FoodAlchemistSupplierItem $la, array $opt = []): AbgleichErgebnis
    {
        $t0 = hrtime(true);
        $this->kiLogIds = [];
        $ergebnis = $this->entscheideIntern($team, $la, $opt);
        $ergebnis->dauerMs = (int) round((hrtime(true) - $t0) / 1e6);
        $ergebnis->kiLogIds = $this->kiLogIds;

        return $ergebnis;
    }

    private function entscheideIntern(Team $team, FoodAlchemistSupplierItem $la, array $opt): AbgleichErgebnis
    {
        $kiErlaubt = (bool) ($opt['ki'] ?? true);
        $max = max(1, min(self::MAX_KANDIDATEN, (int) ($opt['max_kandidaten'] ?? self::MAX_KANDIDATEN)));

        // Non-Food: nie an ein Food-GP
        if ($this->nonFood->istNonFood((int) $la->id)) {
            return new AbgleichErgebnis('blockiert', null, 'Non-Food-Artikel — kein Food-GP.', 0.0, [], [], [], 'nonfood');
        }
        // schon verknüpft
        $la->loadMissing('structure');
        if (empty($opt['ignoriere_verknuepfung']) && $la->structure?->gp_id !== null && $la->structure->deleted_at === null) {
            return new AbgleichErgebnis('bereits_verknuepft', (int) $la->structure->gp_id, 'Der Artikel hängt schon an diesem GP.', 1.0, [], [], [], 'verknuepft');
        }

        // S0 — exakte Brücke (0 KI-Calls)
        foreach ($this->match->exakteBruecken($la, $team) as $t) {
            /** @var FoodAlchemistGp $gp */
            $gp = $t['gp'];
            if (! $this->aktiv($gp)) {
                continue;
            }
            $guard = $this->guardOrLeer($team, $gp, (int) $la->id);
            if ($guard !== []) {
                continue;   // exakte Brücke, aber Deklaration widerspricht → normaler Weg entscheidet (Guard bleibt Veto)
            }
            [$darf, $alt] = $this->verknuepfbar($team, $gp);

            return new AbgleichErgebnis('verknuepfen', (int) $gp->id, 'Exakte Brücke (' . $t['methode'] . '): derselbe Artikel/EAN hängt schon an diesem GP.', 1.0, [], [$this->kandidatKurz([
                'gp' => $gp, 'score' => 1.0, 'rang' => 1.0, 'methoden' => [(string) $t['methode']], 'exakt' => true, 'diff' => ['abweichungen' => [], 'abweichend' => false], 'guard' => [],
            ])], [], 'S0', $darf, $darf, $alt);
        }

        if (! empty($opt['nur_exakt'])) {
            return new AbgleichErgebnis('unsicher', null, 'Keine exakte Brücke (nur S0 gefragt).', 0.0, [], [], [], 'S0');   // Rezept-Hotpath: nie Recall/KI
        }

        // S1 — Felder
        $felder = $this->felder->fuer($team, $la, $kiErlaubt);
        $kiCalls = $felder['ki'] instanceof AiProposal ? 1 : 0;
        [$tIn, $tOut] = $this->tokens($felder['ki'] ?? null);
        $felderOut = collect($felder)->except('ki')->all();

        // S2–S5 — Kandidaten
        $kandidaten = $this->sucher->kandidaten($team, $la, $felder, 'gleich', $max);

        if ($kandidaten === []) {
            if (trim((string) $felder['hauptzutat']) === '') {
                return new AbgleichErgebnis('unsicher', null, 'Keine Felder ableitbar (KI aus oder ohne Ergebnis) und kein Kandidat im Bestand.', 0.0, [], [], $felderOut, 'S2', false, true, null, $kiCalls, $tIn, $tOut);
            }

            return new AbgleichErgebnis('neu', null, 'Kein passendes GP im Bestand (Warengruppe, Zustand und Merkmale geprüft) — GP aus den Artikelfeldern anlegen.', 0.9, [], [], $felderOut, 'S2', false, true, null, $kiCalls, $tIn, $tOut);
        }

        $liste = array_map(fn ($c) => $this->kandidatKurz($c), $kandidaten);
        if (! $kiErlaubt) {
            $top = $kandidaten[0];

            return new AbgleichErgebnis('unsicher', (int) $top['gp']->id, 'Ohne KI nur Vorschlag: bester Kandidat nach Namen/Merkmalen.', min(0.69, (float) $top['rang']), $top['diff']['abweichungen'], $liste, $felderOut, 'ohne_ki', false, true, null, $kiCalls, $tIn, $tOut);
        }

        // S6 — KI-Entscheider
        $antwort = $this->entscheiderKi($la, $felderOut, $kandidaten);
        $kiCalls += 1;
        [$tI2, $tO2] = $this->tokens($antwort['ki']);
        $tIn += $tI2;
        $tOut += $tO2;

        return $this->auswerten($team, $antwort, $kandidaten, $liste, $felderOut, $kiCalls, $tIn, $tOut);
    }

    /**
     * Entscheidung + Protokoll; mit `ausfuehren` und erreichter Automatik-Schwelle auch S7 (nur verknüpfen). Für Job/Bulk/MCP.
     *
     * @param  array{modus?: string, ausfuehren?: bool, user_id?: int|null, ki?: bool, ignoriere_verknuepfung?: bool}  $opt
     * @return array{ergebnis: AbgleichErgebnis, log: FoodAlchemistAbgleichLog, ausgefuehrt: ?string}
     */
    public function entscheideProtokolliert(Team $team, FoodAlchemistSupplierItem $la, array $opt = []): array
    {
        $e = $this->entscheide($team, $la, $opt);
        $ausgefuehrt = null;
        $gpId = null;
        if (! empty($opt['ausfuehren']) && $e->automatisch && $e->aktion === 'verknuepfen') {
            try {
                $r = $this->fuehreAus($team, $la, $e);
                $ausgefuehrt = $r['ausgefuehrt'];
                $gpId = (int) $r['gp']->id;
            } catch (\RuntimeException $x) {
                $e->begruendung .= ' [Ausführung abgelehnt: ' . $x->getMessage() . ']';
                $e->automatisch = false;
            }
        }
        $log = $this->protokolliere($team, $la, $e, (string) ($opt['modus'] ?? ($ausgefuehrt !== null ? 'auto' : 'vorschlag')), $ausgefuehrt, $gpId, $opt['user_id'] ?? null);

        return ['ergebnis' => $e, 'log' => $log, 'ausgefuehrt' => $ausgefuehrt];
    }

    /**
     * S7: die Entscheidung ausführen. `verknuepfen` über den NORMALEN Weg (Guard hart, Transaktion, Lead-Neuwahl) — nur wenn der Aufrufer das Ziel-GP
     * verknüpfen darf; sonst Option `eigenes_gp` (team-eigenes GP aus den Feldern, der Stamm bleibt unberührt). `neu` legt das GP aus den Feldern an.
     *
     * @param  array{eigenes_gp?: bool}  $opt
     * @return array{ausgefuehrt: string, gp: FoodAlchemistGp}
     *
     * @throws \RuntimeException bei Rechte-/Namens-/Guard-Verstoß (Meldung für den Aufrufer)
     */
    public function fuehreAus(Team $team, FoodAlchemistSupplierItem $la, AbgleichErgebnis $e, array $opt = []): array
    {
        $eigenes = (bool) ($opt['eigenes_gp'] ?? false);
        if ($e->aktion === 'verknuepfen' && ! $eigenes) {
            if ($e->gpId === null) {
                throw new \RuntimeException('Keine Ziel-GP-ID in der Entscheidung.');
            }
            $gp = FoodAlchemistGp::visibleToTeam($team)->findOrFail($e->gpId);
            if (! $this->verknuepfbar($team, $gp)[0]) {
                throw new \RuntimeException('Das Ziel-GP gehört dem Team nicht (globales Stamm-GP) — verknüpfen darf nur der Besitzer. Alternative: team-eigenes GP anlegen (Option eigenes_gp).');
            }
            $this->lead->verknuepfen($team, $gp, (int) $la->id);   // Allergen-/Deklarations-Guard hart

            return ['ausgefuehrt' => 'verknuepft', 'gp' => $gp->refresh()];
        }
        if ($e->aktion === 'neu' || ($e->aktion === 'verknuepfen' && $eigenes)) {
            $in = array_intersect_key($e->felder, array_flip(['hauptzutat', 'flavor', 'zusatz', 'condition', 'processing', 'form', 'pflichtangabe', 'commodity_group_code', 'sub_category']));
            if (trim((string) ($in['hauptzutat'] ?? '')) === '') {
                throw new \RuntimeException('Keine Hauptzutat in den Artikelfeldern — GP nicht anlegbar.');
            }

            return DB::transaction(function () use ($team, $la, $in) {
                $gp = $this->naming->createGp($team, $in);   // wirft bei §6-Verstoß oder Dublette (anlageGuard)
                $this->lead->verknuepfen($team, $gp, (int) $la->id);

                return ['ausgefuehrt' => 'neu_angelegt', 'gp' => $gp->refresh()];
            });
        }

        throw new \RuntimeException("Aktion „{$e->aktion}“ ist nicht ausführbar.");
    }

    /** Log schreiben (Nachvollziehbarkeit + Rücknahme). */
    public function protokolliere(Team $team, FoodAlchemistSupplierItem $la, AbgleichErgebnis $e, string $modus, ?string $ausgefuehrtAktion = null, ?int $ausgefuehrtGpId = null, ?int $userId = null): FoodAlchemistAbgleichLog
    {
        return FoodAlchemistAbgleichLog::create([
            'team_id' => $team->id, 'user_id' => $userId, 'supplier_item_id' => $la->id, 'modus' => $modus, 'aktion' => $e->aktion, 'gp_id' => $e->gpId,
            'sicherheit' => round($e->sicherheit, 3), 'stufe' => $e->stufe, 'automatisch' => $e->automatisch, 'ausgefuehrt' => $ausgefuehrtAktion !== null,
            'ausgefuehrt_aktion' => $ausgefuehrtAktion, 'ausgefuehrt_gp_id' => $ausgefuehrtGpId, 'begruendung' => mb_substr($e->begruendung, 0, 4000),
            'abweichungen' => $e->abweichungen, 'kandidaten' => $e->kandidaten, 'felder' => $e->felder,
            'ki_calls' => $e->kiCalls, 'tokens_in' => $e->tokensIn, 'tokens_out' => $e->tokensOut, 'dauer_ms' => $e->dauerMs,
        ]);
    }

    /**
     * Rücknahme einer ausgeführten Entscheidung: Verknüpfung lösen (Lead-Neuwahl, Zähler); ein dabei neu angelegtes GP wird gelöscht, wenn nichts mehr daran hängt.
     *
     * @return array{zurueckgenommen: bool, hinweis: string}
     */
    public function zuruecknehmen(Team $team, int $logId): array
    {
        $log = FoodAlchemistAbgleichLog::query()->where('team_id', $team->id)->findOrFail($logId);
        if (! $log->ausgefuehrt || $log->rueckgenommen_at !== null || $log->ausgefuehrt_gp_id === null) {
            return ['zurueckgenommen' => false, 'hinweis' => 'Nichts zurückzunehmen (nicht ausgeführt oder schon zurückgenommen).'];
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($log->ausgefuehrt_gp_id);
        $hinweis = 'Verknüpfung gelöst.';
        if ($gp !== null) {
            try {
                $this->lead->entknuepfen($team, $gp, (int) $log->supplier_item_id);
            } catch (\RuntimeException $x) {
                return ['zurueckgenommen' => false, 'hinweis' => $x->getMessage()];
            }
            if ($log->ausgefuehrt_aktion === 'neu_angelegt') {
                try {
                    app(\Platform\FoodAlchemist\Services\GpService::class)->deleteGp($gp->refresh());
                    $hinweis .= ' Das angelegte GP wurde gelöscht (keine Verweise mehr).';
                } catch (\RuntimeException $x) {
                    $hinweis .= ' Das angelegte GP bleibt bestehen: ' . $x->getMessage();
                }
            }
        }
        $log->update(['rueckgenommen_at' => now()]);

        return ['zurueckgenommen' => true, 'hinweis' => $hinweis];
    }

    // ── S6 ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $felder
     * @param  list<array<string,mixed>>  $kandidaten
     * @return array{werte: array<string,mixed>, ki: AiProposal}
     */
    private function entscheiderKi(FoodAlchemistSupplierItem $la, array $felder, array $kandidaten): array
    {
        $ctx = [
            'artikel' => $this->felder->artikelKontext($la) + ['felder' => array_filter($felder, fn ($v, $k) => $k !== 'quelle' && $v !== '', ARRAY_FILTER_USE_BOTH)],
            'kandidaten' => array_map(function ($c) {
                /** @var FoodAlchemistGp $gp */
                $gp = $c['gp'];
                $lead = $gp->lead_la_supplier_item_id ? FoodAlchemistSupplierItem::with('allergens')->find($gp->lead_la_supplier_item_id) : null;
                $allergene = [];
                foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $key => $label) {
                    if (($lead?->allergens?->{'allergen_' . $key} ?? null) === 'enthalten') {
                        $allergene[] = $label;
                    }
                }

                return [
                    'gp_id' => (int) $gp->id,
                    'name' => (string) $gp->name,
                    'status' => $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status,
                    'warengruppe' => $gp->commodity_group_code,
                    'leitartikel' => $lead?->designation,
                    'leitartikel_zutaten' => $lead?->ingredients_supplier !== null ? mb_substr((string) $lead->ingredients_supplier, 0, 200) : null,
                    'allergene_leitartikel' => $allergene,
                    'deterministischer_rang' => $c['rang'],
                    'merkmals_diff' => array_values(array_map(fn ($e) => "{$e['merkmal']}: {$e['urteil']}", array_filter($c['diff']['eintraege'], fn ($e) => $e['urteil'] !== 'unbekannt'))),
                    'allergen_guard' => $c['guard'] === [] ? 'ok' : 'WIDERSPRUCH: ' . implode('; ', $c['guard']),
                ];
            }, $kandidaten),
        ];

        $vorschlag = $this->ki->propose('gp.la_abgleich', $ctx);

        return ['werte' => $vorschlag->werte, 'ki' => $vorschlag];
    }

    /**
     * Antwort der KI prüfen und in ein Ergebnis überführen: nur Kandidaten-IDs, Begründung Pflicht, Guard-Widerspruch verbietet „verknüpfen“.
     *
     * @param  array{werte: array<string,mixed>, ki: AiProposal}  $antwort
     * @param  list<array<string,mixed>>  $kandidaten
     * @param  list<array<string,mixed>>  $liste
     * @param  array<string,mixed>  $felder
     */
    private function auswerten(Team $team, array $antwort, array $kandidaten, array $liste, array $felder, int $kiCalls, int $tIn, int $tOut): AbgleichErgebnis
    {
        $w = $antwort['werte'];
        $aktion = (string) ($w['aktion'] ?? '');
        $gpId = isset($w['gp_id']) && is_numeric($w['gp_id']) ? (int) $w['gp_id'] : null;   // KI liefert IDs auch als String
        $begruendung = trim((string) ($w['begruendung'] ?? ''));
        $sicherheit = isset($w['sicherheit']) && is_numeric($w['sicherheit']) ? (float) $w['sicherheit'] : (float) $antwort['ki']->confidence;
        $sicherheit = max(0.0, min(1.0, $sicherheit));
        $abweichungen = array_values(array_filter(array_map('strval', (array) ($w['abweichungen'] ?? []))));

        $byId = [];
        foreach ($kandidaten as $c) {
            $byId[(int) $c['gp']->id] = $c;
        }
        $ziel = $gpId !== null ? ($byId[$gpId] ?? null) : null;

        $mk = fn (string $a, ?int $g, float $s, string $b, array $abw = [], bool $auto = false, bool $darf = true, ?string $alt = null) => new AbgleichErgebnis(
            $a, $g, $b, $s, $abw, $liste, $felder, 'S6', $auto, $darf, $alt, $kiCalls, $tIn, $tOut);

        if (! in_array($aktion, ['verknuepfen', 'neu'], true) || $begruendung === '') {
            return $mk('unsicher', null, 0.0, $begruendung !== '' ? $begruendung : 'Die KI lieferte keine verwertbare Entscheidung mit Begründung.');
        }
        if ($aktion === 'neu') {
            return $mk('neu', null, $sicherheit, $begruendung, $abweichungen);
        }
        if ($ziel === null) {
            return $mk('unsicher', null, 0.0, 'Die KI nannte ein GP, das nicht in der Kandidatenliste steht — verworfen. ' . $begruendung);
        }
        /** @var FoodAlchemistGp $gp */
        $gp = $ziel['gp'];
        if ($ziel['guard'] !== []) {
            return $mk('neu', null, min($sicherheit, 0.6), 'Allergen-/Deklarationsprofil widerspricht dem Ziel-GP — Verknüpfen ist nicht erlaubt; neue Variante anlegen. ' . $begruendung, array_merge($abweichungen, $ziel['guard']));
        }
        $diffAbw = $ziel['diff']['abweichungen'];
        if ($diffAbw !== []) {
            $abweichungen = array_values(array_unique(array_merge($abweichungen, $diffAbw)));
            $sicherheit = min($sicherheit, 0.6);   // bekannte Merkmals-Abweichung: höchstens Vorschlag
        }
        [$darf, $alt] = $this->verknuepfbar($team, $gp);
        $rang1 = (int) $kandidaten[0]['gp']->id === (int) $gp->id;
        $status = $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status;
        $auto = $darf && $sicherheit >= self::SCHWELLE_AUTO && $rang1 && $diffAbw === [] && $ziel['guard'] === [] && $status === 'approved';

        return $mk('verknuepfen', (int) $gp->id, $sicherheit, $begruendung, $abweichungen, $auto, $darf, $alt);
    }

    // ── Helfer ───────────────────────────────────────────────────────────────────────────────────────────

    private function aktiv(FoodAlchemistGp $gp): bool
    {
        $status = $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status;

        return in_array($status, ['approved', 'tentative'], true) && ! $gp->is_derivat && ! $gp->is_platzhalter && (string) $gp->commodity_group_code !== '16';
    }

    /** @return list<string> */
    private function guardOrLeer(Team $team, FoodAlchemistGp $gp, int $laId): array
    {
        try {
            return $this->lead->guardAbweichungen($team, $gp, $laId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Darf das Team den Artikel an dieses GP hängen? Heute nur der Besitzer ({@see FoodAlchemistGp::isOwnedBy}: eigenes GP, globale Stamm-GPs der Master).
     * Sonst Alternative „eigenes GP im Mandanten“ (team-eigen, der Stamm bleibt unberührt). Die Mandanten-Overlay-Verknüpfung mit Abo ist ein eigener Schritt (offen).
     *
     * @return array{0: bool, 1: ?string}
     */
    private function verknuepfbar(Team $team, FoodAlchemistGp $gp): array
    {
        return $gp->isOwnedBy($team) ? [true, null] : [false, 'eigenes_gp_im_mandanten'];
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function kandidatKurz(array $c): array
    {
        /** @var FoodAlchemistGp $gp */
        $gp = $c['gp'];

        return [
            'gp_id' => (int) $gp->id,
            'name' => (string) $gp->name,
            'status' => $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status,
            'score' => $c['score'],
            'rang' => $c['rang'],
            'methoden' => $c['methoden'],
            'exakt' => $c['exakt'],
            'diff_abweichungen' => $c['diff']['abweichungen'],
            'guard' => $c['guard'],
        ];
    }

    /** @return array{0: int, 1: int} Tokens ein/aus aus dem Aufruf-Log */
    private function tokens(?AiProposal $p): array
    {
        if ($p === null || $p->callLogId === null) {
            return [0, 0];
        }
        $this->kiLogIds[] = (int) $p->callLogId;
        $z = DB::table('foodalchemist_ai_call_log')->where('id', $p->callLogId)->first(['tokens_in', 'tokens_out']);

        return [(int) ($z->tokens_in ?? 0), (int) ($z->tokens_out ?? 0)];
    }
}
