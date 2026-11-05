<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\TrendEingangErschliessenJob;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\Ai\KiExternModus;
use Platform\FoodAlchemist\Services\Trends\QuellenAbruf;
use Platform\FoodAlchemist\Support\GoogleNewsSuche;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt;
use Platform\FoodAlchemist\Support\StichwortFilter;
use Platform\FoodAlchemist\Support\TrendSammelthema;
use Platform\FoodAlchemist\Support\TrendUrl;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * Spec 93 A · Eingang des Trendradars — systematisch erfasste Artikel, getrennt von der Pinnwand. Der eine Schreibpfad
 * für Abruf, MCP und UI.
 *
 * Reihenfolge beim Einreichen: Stichwort-Filter (kein Treffer → keine Zeile, nur Zähler an der Quelle) → Dublettenschutz
 * über die normalisierte URL in der ganzen Teamfamilie (eigene Zeile: nur zuletzt_gesehen_at; Zeile eines anderen Teams:
 * nur melden, nie dort schreiben) → neue Zeile mit Status „neu“. Gespeichert werden Titel, Link, Datum und ein gekürzter
 * Teaser, nie der Volltext. Auf die Pinnwand kommt ein Artikel nur per ausdrücklicher Entscheidung.
 *
 * Spec 93 B: Jeder neue Eintrag wird danach erschlossen (TrendEingangErschliessenJob): Plattform-Abruf und Klick → Plattform-KI,
 * MCP-Aufruf → KI-Auftrag. Erschlossene Artikel kommen in ein automatisches Thema (TrendThemenService), nie auf die Pinnwand.
 */
class TrendEingangService
{
    public const AKTIONEN = ['verwerfen', 'wiederherstellen', 'thema', 'pinnwand'];

    public function __construct(
        private readonly FaRechte $rechte,
        private readonly TrendService $trends,
        private readonly TrendQuellenService $quellen,
        private readonly QuellenAbruf $abruf,
        private readonly TeamSettingsService $settings,
        private readonly TrendThemenService $themen,
    ) {}

    // ── Lesen ──────────────────────────────────────────────────────────────

    /**
     * @param  array{status?:string, quelle_id?:int, suche?:string}  $filter
     * @return Collection<int, FoodAlchemistTrendEingang>
     */
    public function liste(Team $team, array $filter = [], int $limit = 200): Collection
    {
        $q = FoodAlchemistTrendEingang::visibleToTeam($team)->with('quelle:id,name,art');
        if (! empty($filter['status'])) {
            $q->whereIn('status', (array) $filter['status']);
        }
        if (! empty($filter['quelle_id'])) {
            $q->where('quelle_id', (int) $filter['quelle_id']);
        }
        $suche = trim((string) ($filter['suche'] ?? ''));
        if ($suche !== '') {
            $q->where(fn ($w) => $w->where('titel', 'like', '%'.$suche.'%')->orWhere('teaser', 'like', '%'.$suche.'%')->orWhere('thema', 'like', '%'.$suche.'%'));
        }

        return $q->orderByDesc('created_at')->orderByDesc('id')->limit(max(1, min(500, $limit)))->get();
    }

    /** @return array<string,int> Status → Anzahl (sichtbare Zeilen) */
    public function zaehler(Team $team): array
    {
        return FoodAlchemistTrendEingang::visibleToTeam($team)->select('status', DB::raw('count(*) as n'))->groupBy('status')
            ->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }

    public function alsArray(FoodAlchemistTrendEingang $e, ?Team $team = null): array
    {
        return [
            'id' => (int) $e->id, 'titel' => $e->titel, 'url' => $e->link(),
            'quelle_id' => $e->quelle_id !== null ? (int) $e->quelle_id : null, 'quelle' => $e->quelle?->name,
            'veroeffentlicht_am' => $e->veroeffentlicht_am?->toIso8601String(), 'teaser' => $e->teaser,
            'zusammenfassung' => $e->zusammenfassung, 'thema' => $e->thema, 'kategorie' => $e->kategorie, 'sparten' => $e->sparten ?? [],
            'tiefe' => $e->tiefe, 'relevant' => $e->relevant, 'begruendung' => $e->begruendung,
            'thema_quelle' => $e->thema_quelle, 'thema_id' => $e->thema_inspiration_id !== null ? (int) $e->thema_inspiration_id : null,
            'passt_zu_trend_id' => $e->passt_zu_trend_id !== null ? (int) $e->passt_zu_trend_id : null,
            'erschlossen_am' => $e->erschlossen_at?->toIso8601String(),
            'status' => $e->status, 'status_label' => V::label(V::EINGANG_STATUS, $e->status),
            'eingereicht_von' => $e->eingereicht_von, 'inspiration_id' => $e->inspiration_id !== null ? (int) $e->inspiration_id : null,
            'zuletzt_gesehen_at' => $e->zuletzt_gesehen_at?->toIso8601String(), 'angelegt_am' => $e->created_at?->toIso8601String(),
            'eigenes_team' => $team === null || (int) $e->team_id === (int) $team->id,
            'observatorium' => $e->observatorium, 'herausgeber' => $e->herausgeber, 'herausgeber_url' => $e->herausgeber_url,
        ];
    }

    // ── Einreichen ─────────────────────────────────────────────────────────

    /**
     * Ein Artikel in den Eingang. `von` = abruf (System) | mcp | hand.
     *
     * Spec 93 E: `$signal` = Schlüssel eines Einzelsignals aus einem Beitrag (siehe {@see signaleEinreichen()}). Dann gilt
     * als Dublette erst dieselbe URL mit demselben Signal; gespeichert wird die URL mit Fragment `#signal=…`, damit jeder
     * Beleg im Thema seinen eigenen Link hat und das Zählen der Automatik den Beitrag trotzdem erkennt (Fragment fällt weg).
     *
     * @param  array{url:string, titel:string, veroeffentlicht_am?:?string, teaser?:?string, quelle_id?:?int}  $daten
     * @return array{ergebnis:string, eintrag:?FoodAlchemistTrendEingang, grund:?string}
     *                                                                                   ergebnis: neu | dublette | gefiltert
     */
    public function einreichen(Team $team, array $daten, string $von = 'mcp', ?int $userId = null, ?string $signal = null): array
    {
        if (! array_key_exists($von, V::EINGEREICHT_VON)) {
            throw new \RuntimeException('eingereicht_von „'.$von.'“ gibt es nicht.');
        }
        if ($von !== 'abruf') {
            $this->pruefe($userId, $team, 'In den Trend-Eingang einreichen');
        }
        $url = trim((string) ($daten['url'] ?? ''));
        $hash = TrendUrl::hash($url);   // wirft ohne http(s)/Host
        if ($signal !== null) {
            $hash = hash('sha256', TrendUrl::normalisieren($url)."\n#signal:".$signal);
            $url = TrendUrl::normalisieren($url).'#signal='.rawurlencode(str_replace(' ', '-', $signal));
        }
        $titel = trim(preg_replace('/\s+/u', ' ', (string) ($daten['titel'] ?? '')) ?? '');
        if ($titel === '') {
            throw new \RuntimeException('Ein Eintrag braucht einen Titel.');
        }
        $teaser = $this->teaser($daten['teaser'] ?? null);
        $quelle = ! empty($daten['quelle_id'])
            ? FoodAlchemistTrendQuelle::where('team_id', $team->id)->findOrFail((int) $daten['quelle_id'])
            : null;

        if ($quelle !== null && ($grund = GoogleNewsSuche::datumsGrund((string) ($quelle->feed_url ?: $quelle->url), $daten['veroeffentlicht_am'] ?? null)) !== null) {
            if ($von !== 'abruf') {
                $this->mcpBesuch($quelle, 1);
            }

            return ['ergebnis' => 'gefiltert', 'eintrag' => null, 'grund' => $grund];
        }

        // 1. Stichwort-Filter
        $woerter = $quelle !== null ? $this->quellen->stichworte($quelle) : $this->settings->trendStichworte($team);
        $filter = StichwortFilter::pruefen($titel.' '.($teaser ?? ''), $woerter['ein'], $woerter['aus']);
        if (! $filter['durch']) {
            if ($quelle !== null && $von !== 'abruf') {
                $this->mcpBesuch($quelle, 1);
            }

            return ['ergebnis' => 'gefiltert', 'eintrag' => null, 'grund' => 'gefiltert: '.$filter['grund']];
        }

        // 2. Dublette in der Teamfamilie
        // auch weich gelöschte Zeilen: der DB-Riegel unique(team_id, url_hash) kennt kein deleted_at
        $vorhanden = FoodAlchemistTrendEingang::withTrashed()->whereIn('team_id', $this->trends->fundstueckFamilie($team))
            ->where('url_hash', $hash)->orderByRaw('team_id = ? desc', [$team->id])->first();
        if ($vorhanden !== null) {
            if ((int) $vorhanden->team_id === (int) $team->id) {
                $mehr = ['zuletzt_gesehen_at' => now()];
                // Alte Google-News-Einträge erhalten beim Wiedersehen ihre RSS-Herkunft, ohne erneuten KI-Call.
                if (! $vorhanden->herausgeber && is_string($daten['herausgeber'] ?? null)) {
                    $mehr['herausgeber'] = $this->kurz($daten['herausgeber'], 255);
                    $mehr['herausgeber_url'] = ObservatoriumInhalt::herausgeberUrl($daten['herausgeber_url'] ?? null);
                    $mehr['inhalt_hash'] = ObservatoriumInhalt::inhaltHash($vorhanden->titel, $vorhanden->url, $mehr['herausgeber']);
                }
                $vorhanden->update($mehr);
                $grund = 'Dublette (eigener Eingang, ID '.$vorhanden->id.')';
            } else {
                $grund = 'Dublette (Team '.(Team::find($vorhanden->team_id)?->name ?? $vorhanden->team_id).')';
                $vorhanden = null;   // fremde Zeile: nur melden, nicht zurückgeben, nicht schreiben
            }
            if ($quelle !== null && $von !== 'abruf') {
                $this->mcpBesuch($quelle, 0);
            }

            return ['ergebnis' => 'dublette', 'eintrag' => $vorhanden, 'grund' => $grund];
        }

        // 3. Neue Zeile
        $eintrag = FoodAlchemistTrendEingang::create([
            'team_id' => $team->id,
            'quelle_id' => $quelle?->id,
            'url' => mb_substr($url, 0, 2048),
            'url_hash' => $hash,
            'titel' => mb_substr($titel, 0, 500),
            'veroeffentlicht_am' => $this->datum($daten['veroeffentlicht_am'] ?? null),
            'teaser' => $teaser,
            'status' => 'neu',
            'eingereicht_von' => $von,
            'zuletzt_gesehen_at' => now(),
            'created_by' => $userId,
            'observatorium' => ObservatoriumInhalt::einordnen(['titel' => $titel, 'teaser' => $teaser]),
            'herausgeber' => $this->kurz($daten['herausgeber'] ?? null, 255),
            'herausgeber_url' => ObservatoriumInhalt::herausgeberUrl($daten['herausgeber_url'] ?? null),
            'inhalt_hash' => ObservatoriumInhalt::inhaltHash($titel, $url, $this->kurz($daten['herausgeber'] ?? null, 255)),
        ]);
        if ($quelle !== null && $von !== 'abruf') {
            $this->mcpBesuch($quelle, 0);
        }
        app(WettbewerbService::class)->vorschlagen($eintrag);
        $this->erschliessenDispatchen($eintrag);

        return ['ergebnis' => 'neu', 'eintrag' => $eintrag->refresh(), 'grund' => $filter['grund']];
    }

    /**
     * Spec 93 E · Ein Beitrag mit mehreren Einzelsignalen (Studie, Trendliste): je Signal ein eigener Eingangs-Eintrag mit
     * eigenem Thema — sonst bündelt das Erschließen die ganze Studie unter ein Sammelthema und keins der Signale zählt.
     * Wer einreicht, kennt die Struktur; zerlegt wird hier deterministisch, ohne KI. Titel = „Signal — Beitrag“, damit das
     * Erschließen den Zusammenhang sieht. Höchstens `trends.quellen.max_signale_je_beitrag` Signale (jedes kostet einen Call).
     *
     * @param  array{url:string, titel:string, veroeffentlicht_am?:?string, quelle_id?:?int}  $beitrag
     * @param  list<array{titel?:string, teaser?:?string}>  $signale
     * @return list<array{signal:string, ergebnis:string, eintrag:?FoodAlchemistTrendEingang, grund:?string}>
     */
    public function signaleEinreichen(Team $team, array $beitrag, array $signale, string $von = 'mcp', ?int $userId = null): array
    {
        $max = (int) config('foodalchemist.trends.quellen.max_signale_je_beitrag', 12);
        if (count($signale) > $max) {
            throw new \RuntimeException('Höchstens '.$max.' Signale je Beitrag ('.count($signale).' übergeben).');
        }
        $beitragTitel = trim(preg_replace('/\s+/u', ' ', (string) ($beitrag['titel'] ?? '')) ?? '');
        // erst alle prüfen, dann anlegen — ein Signal ohne Titel soll nicht die Hälfte des Beitrags eingereicht zurücklassen
        $liste = [];
        foreach ($signale as $s) {
            $s = (array) $s;
            $signalTitel = trim(preg_replace('/\s+/u', ' ', (string) ($s['titel'] ?? '')) ?? '');
            $schluessel = TrendThemenService::schluessel($signalTitel);
            if ($schluessel === '') {
                throw new \RuntimeException('Jedes Signal braucht einen Titel.');
            }
            $liste[$schluessel] ??= ['titel' => $signalTitel, 'teaser' => $s['teaser'] ?? null];   // gleiches Signal zweimal: einmal reicht
        }
        $ergebnisse = [];
        foreach ($liste as $schluessel => $s) {
            $signalTitel = $s['titel'];
            $titel = $beitragTitel !== '' ? $signalTitel.' — '.$beitragTitel : $signalTitel;
            $r = $this->einreichen($team, [
                'url' => $beitrag['url'] ?? '', 'titel' => $titel, 'teaser' => $s['teaser'] ?? null,
                'veroeffentlicht_am' => $beitrag['veroeffentlicht_am'] ?? null, 'quelle_id' => $beitrag['quelle_id'] ?? null,
            ], $von, $userId, (string) $schluessel);   // Array-Schlüssel „2027“ wäre sonst int
            $ergebnisse[] = ['signal' => $signalTitel] + $r;
        }

        return $ergebnisse;
    }

    /**
     * Eine plattform-Quelle abrufen: höchstens N neue Einträge (Dubletten und Gefiltertes zählen nicht mit), Fehler an
     * der Quelle statt Abbruch. mcp-Quellen ruft die Plattform nie ab.
     *
     * @return array{neu:int, dubletten:int, gefiltert:int, fehler:?string}
     */
    public function quelleAbrufen(FoodAlchemistTrendQuelle $quelle): array
    {
        if ($quelle->abruf_weg !== 'plattform') {
            return ['neu' => 0, 'dubletten' => 0, 'gefiltert' => 0, 'fehler' => 'Quelle wird per MCP gesichtet, kein Plattform-Abruf.'];
        }
        $team = Team::findOrFail($quelle->team_id);
        try {
            $eintraege = $this->abruf->lesen($quelle);
        } catch (\RuntimeException $e) {
            $this->quellen->abrufErgebnis($quelle, $e->getMessage(), 0);

            return ['neu' => 0, 'dubletten' => 0, 'gefiltert' => 0, 'fehler' => $e->getMessage()];
        }
        $max = (int) config('foodalchemist.trends.quellen.max_neue_je_abruf', 20);
        $z = ['neu' => 0, 'dubletten' => 0, 'gefiltert' => 0, 'fehler' => null];
        foreach ($eintraege as $e) {
            if ($z['neu'] >= $max) {
                break;
            }
            try {
                $r = $this->einreichen($team, $e + ['quelle_id' => $quelle->id], 'abruf');
            } catch (\RuntimeException) {
                continue;   // einzelner kaputter Eintrag (Link ohne http) bricht den Lauf nicht
            }
            $z[$r['ergebnis'] === 'neu' ? 'neu' : ($r['ergebnis'] === 'dublette' ? 'dubletten' : 'gefiltert')]++;
        }
        $this->quellen->abrufErgebnis($quelle->refresh(), null, $z['gefiltert']);

        return $z;
    }

    // ── Erschließen (Spec 93 B) ────────────────────────────────────────────

    /**
     * Erschließen anstoßen: die genannten Einträge oder alle offenen („neu“/„fehler“) des eigenen Teams, älteste zuerst,
     * höchstens `trends.quellen.erschliessen_max_je_aufruf` (50) je Aufruf. Per MCP laufen die Jobs extern.
     *
     * @param  list<int>|null  $ids
     * @return list<int> angestoßene Eintrag-IDs
     */
    public function erschliessenAnstossen(Team $team, ?array $ids = null, ?int $userId = null): array
    {
        $this->pruefe($userId, $team, 'Trend-Eingang erschließen');
        $max = (int) config('foodalchemist.trends.quellen.erschliessen_max_je_aufruf', 50);
        $q = FoodAlchemistTrendEingang::where('team_id', $team->id)->whereIn('status', ['neu', 'fehler']);
        if ($ids !== null) {
            $q->whereKey(array_map('intval', $ids));
        }
        $zeilen = $q->orderBy('id')->limit($max)->get();
        foreach ($zeilen as $e) {
            $this->erschliessenDispatchen($e);
        }

        return $zeilen->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * KI-Merkmale übernehmen (vom Job). Ungültige Antwort → Status „fehler“ mit Begründung; nicht relevant → „verworfen“;
     * sonst „erschlossen“ und gebündelt. Ein von Hand gesetztes Thema bleibt.
     *
     * @param  array<string,mixed>  $w
     * @param  list<int>  $trendIds  erlaubte Werte für passt_zu_trend_id (die mitgegebene Liste)
     */
    public function erschliessungUebernehmen(FoodAlchemistTrendEingang $e, array $w, array $trendIds): FoodAlchemistTrendEingang
    {
        if (! is_bool($w['relevant'] ?? null)) {
            return $this->erschliessungFehlgeschlagen($e, 'KI-Antwort ohne gültiges Feld relevant');
        }
        $begruendung = $this->kurz($w['begruendung'] ?? null, 1000);
        $e->update(['observatorium' => ObservatoriumInhalt::einordnen(
            ['titel' => $e->titel, 'teaser' => $e->teaser, 'sparten' => $w['sparten'] ?? $e->sparten],
            is_array($w['observatorium'] ?? null) ? $w['observatorium'] : null,
        )]);
        if ($w['relevant'] === false) {
            $e->update(['relevant' => false, 'status' => 'verworfen', 'begruendung' => $begruendung ?? 'Nicht relevant (KI)',
                'zusammenfassung' => $this->kurz($w['zusammenfassung'] ?? null, 400), 'erschlossen_at' => now()]);

            return $e->refresh();
        }
        $thema = $e->thema_quelle === 'manuell' ? $e->thema : $this->kurz($w['thema'] ?? null, 190);
        if ($thema === null || TrendThemenService::schluessel($thema) === '') {
            return $this->erschliessungFehlgeschlagen($e, 'KI-Antwort ohne Thema');
        }
        // Spec 93 E: Sammelthemen („Trendprognose 2027“) bündeln ganze Studien und zählen kein Signal — ablehnen statt bündeln.
        // Ein von Hand gesetztes Thema gilt immer.
        if ($e->thema_quelle !== 'manuell' && TrendSammelthema::ist($thema)) {
            return $this->erschliessungFehlgeschlagen($e, 'Sammelthema „'.$thema.'“ abgelehnt: Beitrag mit Einzelsignalen (signale) neu einreichen oder das Thema von Hand setzen.');
        }
        $tiefe = (string) ($w['tiefe'] ?? 'unklar');
        $trend = isset($w['passt_zu_trend_id']) && in_array((int) $w['passt_zu_trend_id'], $trendIds, true) ? (int) $w['passt_zu_trend_id'] : null;
        $e->update([
            'relevant' => true,
            'zusammenfassung' => $this->kurz($w['zusammenfassung'] ?? null, 400),
            'thema' => $thema,
            'thema_quelle' => $e->thema_quelle === 'manuell' ? 'manuell' : 'ki',
            'kategorie' => array_key_exists((string) ($w['kategorie'] ?? ''), V::KATEGORIEN) ? (string) $w['kategorie'] : null,
            'sparten' => array_values(array_intersect(array_map('strval', (array) ($w['sparten'] ?? [])), array_keys(V::SPARTEN))) ?: null,
            'tiefe' => array_key_exists($tiefe, V::TIEFEN) ? $tiefe : 'unklar',
            'begruendung' => $this->kurz($w['tiefe_begruendung'] ?? null, 1000) ?? $begruendung,
            'passt_zu_trend_id' => $trend,
            'status' => 'erschlossen',
            'erschlossen_at' => now(),
        ]);
        if ($this->themen->buendeln($e->refresh()) !== null) {
            $e->update(['status' => 'gebuendelt']);
        }

        return $e->refresh();
    }

    /**
     * Spec 93 C/D: Trend-KI-Budget des Monats erreicht? Gezählt werden die Plattform-Kosten von Erschließen und Ableitung
     * (ein Topf) im Team (Call-Log, € zum eingefrorenen Kurs). null-Budget = kein eigener Deckel (es gilt nur der allgemeine Team-Deckel).
     */
    public function budgetErreicht(int $teamId): bool
    {
        $team = Team::find($teamId);
        $budget = $team ? $this->settings->trendKiBudget($team) : null;
        if ($budget === null) {
            return false;
        }

        return $this->trendKiVerbrauch($teamId) >= $budget - 1e-9;
    }

    /** Spec 93 D: ein Topf für das Trend-KI-Budget — Erschließen und Ableitung zählen zusammen. */
    public const TREND_KI_FEATURES = ['trend.eingang_erschliessen', 'trend.ableitung'];

    /** Plattform-KI-Kosten des Trendradars (Erschließen + Ableitung) im laufenden Monat, in € (ohne Kosten = MCP-Antworten zählen nicht). */
    public function trendKiVerbrauch(int $teamId): float
    {
        if (! Schema::hasColumn('foodalchemist_ai_call_log', 'usd_eur')) {
            return 0.0;
        }

        return round((float) DB::table('foodalchemist_ai_call_log')->where('team_id', $teamId)->whereIn('feature', self::TREND_KI_FEATURES)
            ->where('created_at', '>=', now()->startOfMonth())->whereNotNull('kosten_usd')->whereNotNull('usd_eur')
            ->sum(DB::raw('kosten_usd * usd_eur')), 4);
    }

    public function erschliessungFehlgeschlagen(FoodAlchemistTrendEingang $e, string $grund): FoodAlchemistTrendEingang
    {
        $e->update(['status' => 'fehler', 'begruendung' => mb_substr($grund, 0, 1000)]);

        return $e->refresh();
    }

    /**
     * Job anstoßen (nach dem Commit). Extern, wenn ein MCP-Aufruf dahintersteht oder das Team die Trend-KI auf „extern“ gestellt hat
     * (dann auch Scheduler-Abruf und Klick) — das Flag wird JETZT gelesen, nicht im Callback.
     */
    private function erschliessenDispatchen(FoodAlchemistTrendEingang $e): void
    {
        $extern = app(KiExternModus::class)->fuerDispatch()
            || $this->settings->trendKiWeg(Team::findOrFail($e->team_id)) === 'extern';
        $id = (int) $e->id;
        $team = (int) $e->team_id;
        DB::afterCommit(fn () => TrendEingangErschliessenJob::dispatch($id, $team, $extern));
    }

    private function kurz(mixed $wert, int $max): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) ($wert ?? '')) ?? '');

        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    // ── Entscheiden ────────────────────────────────────────────────────────

    /**
     * verwerfen (wert = Begründung, optional) | wiederherstellen | thema (wert = neues Thema) | pinnwand (als Inspiration
     * auf die Pinnwand — die einzige Brücke vom Eingang zur Pinnwand, immer per ausdrücklicher Entscheidung).
     */
    public function entscheiden(Team $team, int $id, string $aktion, ?string $wert = null, ?int $userId = null): FoodAlchemistTrendEingang
    {
        $this->pruefe($userId, $team, 'Trend-Eingang entscheiden');
        $eintrag = FoodAlchemistTrendEingang::where('team_id', $team->id)->findOrFail($id);
        $wert = trim((string) ($wert ?? ''));
        switch ($aktion) {
            case 'verwerfen':
                $eintrag->update(['status' => 'verworfen', 'begruendung' => $wert !== '' ? mb_substr($wert, 0, 2000) : $eintrag->begruendung]);
                $this->themen->loesen($eintrag);
                break;
            case 'wiederherstellen':
                if ($eintrag->thema !== null && $eintrag->erschlossen_at !== null) {
                    $eintrag->update(['status' => 'erschlossen', 'relevant' => true]);
                    if ($this->themen->buendeln($eintrag->refresh()) !== null) {
                        $eintrag->update(['status' => 'gebuendelt']);
                    }
                } else {
                    $eintrag->update(['status' => 'neu']);
                }
                break;
            case 'thema':
                if ($wert === '' || TrendThemenService::schluessel($wert) === '') {
                    throw new \RuntimeException('Bitte ein Thema angeben.');
                }
                // von Hand gesetzt: die KI fasst es nie wieder an; ein schon gebündelter Artikel wandert ins neue Thema
                $eintrag->update(['thema' => mb_substr($wert, 0, 190), 'thema_quelle' => 'manuell']);
                if (in_array($eintrag->status, ['erschlossen', 'gebuendelt'], true)) {
                    if ($this->themen->buendeln($eintrag->refresh()) !== null) {
                        $eintrag->update(['status' => 'gebuendelt']);
                    }
                }
                break;
            case 'pinnwand':
                if ($eintrag->inspiration_id !== null) {
                    throw new \RuntimeException('Dieser Artikel liegt schon auf der Pinnwand (Inspiration '.$eintrag->inspiration_id.').');
                }
                $beleg = $this->trends->fundstueckAblegen($team, [
                    'titel' => $eintrag->titel, 'url' => $eintrag->link(),
                    'quelle' => $eintrag->quelle?->art ?? 'presse',
                    'notiz' => $eintrag->zusammenfassung ?? $eintrag->teaser,
                    'fundort' => $eintrag->quelle?->name,
                    'beobachtet_am' => ($eintrag->veroeffentlicht_am ?? now())->toDateString(),
                ], null, $userId);
                $eintrag->update(['inspiration_id' => $beleg->inspiration_id]);
                break;
            default:
                throw new \RuntimeException('Aktion „'.$aktion.'“ gibt es nicht. Erlaubt: '.implode(', ', self::AKTIONEN).'.');
        }

        return $eintrag->refresh();
    }

    // ── intern ─────────────────────────────────────────────────────────────

    /** MCP hat die Quelle besucht: letzter_abruf_at setzen, Filter-Zähler je Kalendertag führen. */
    private function mcpBesuch(FoodAlchemistTrendQuelle $quelle, int $gefiltert): void
    {
        $neuerTag = $quelle->letzter_abruf_at === null || ! $quelle->letzter_abruf_at->isSameDay(now());
        $quelle->update([
            'letzter_abruf_at' => now(),
            'gefiltert_letzter_abruf' => ($neuerTag ? 0 : (int) $quelle->gefiltert_letzter_abruf) + $gefiltert,
            'gefiltert_gesamt' => (int) $quelle->gefiltert_gesamt + $gefiltert,
        ]);
    }

    private function teaser(mixed $wert): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($wert ?? ''))) ?? '');
        if ($t === '') {
            return null;
        }
        $max = (int) config('foodalchemist.trends.quellen.teaser_max', 600);

        return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)).'…' : $t;
    }

    private function datum(mixed $wert): ?Carbon
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $wert);
        } catch (\Throwable) {
            return null;
        }
    }

    private function pruefe(?int $userId, Team $team, string $wofuer): void
    {
        if ($userId !== null) {
            $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, $wofuer);
        } else {
            $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, $wofuer);
        }
    }
}
