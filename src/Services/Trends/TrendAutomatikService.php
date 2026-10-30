<?php

namespace Platform\FoodAlchemist\Services\Trends;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendSignal;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Services\TrendThemenService;
use Platform\FoodAlchemist\Support\TrendAutomatikRegeln;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * Spec 93 C · Einordnung nach Regeln und Automatik. Die KI hat in B Merkmale geliefert; hier entscheidet eine
 * deterministische Regel über Zählwerte — nachvollziehbar, testbar, reproduzierbar (zweimal gerechnet = dieselbe Liste).
 *
 * - kennzahlen(): je automatischem Thema Sichtungen (Artikel + passende Pinnwand-Fundstücke, Regel 2), Quellen,
 *   Quellenarten, Monate, Anteile, Sparten, Kategorie, Google-Trends-Befund.
 * - einordnen(): Kandidat / Hype / Trend / Ebene / Abklingen nach den Schwellen des Teams.
 * - lauf(): plant je Thema eine Aktion; schreibt nur bei Stufe ≠ aus und ausgeschaltetem Schatten-Modus.
 *   Bestandstrends (herkunft = manuell) bekommen nur Belege und neuBewerten; mega/meta setzt die Automatik nie;
 *   von Hand gesetzte Felder (manuell_felder) fasst sie nie an; höchstens max_neue_trends_je_lauf neue Trends.
 */
class TrendAutomatikService
{
    /** Belege, die einen Trend in Fachkreisen verankern (Trend-Regel: mindestens einer davon). */
    public const HARTE_ARTEN = ['presse', 'branchenquelle', 'literatur'];

    public function __construct(
        private readonly TrendService $trends,
        private readonly TeamSettingsService $settings,
        private readonly FaRechte $rechte,
    ) {}

    /** @return array{stufe:string, schatten:bool, regeln:array<string,int|float>, startwerte:array<string,int|float>, ki_budget_eur:?float, letzter_lauf:?array} */
    public function einstellungen(Team $team): array
    {
        return [
            'stufe' => $this->settings->trendAutomatik($team),
            'schatten' => $this->settings->trendAutomatikSchatten($team),
            'regeln' => TrendAutomatikRegeln::wirksam($this->settings->trendAutomatikRegeln($team)),
            'startwerte' => TrendAutomatikRegeln::startwerte(),
            'ki_budget_eur' => $this->settings->trendKiBudget($team),
            'letzter_lauf' => is_array($l = $this->settings->for($team)->trend_automatik_letzter_lauf) ? $l : null,
        ];
    }

    /** @return Collection<int, FoodAlchemistTrendInspiration> automatische Themen des Teams, stabil nach ID */
    public function themen(Team $team): Collection
    {
        return FoodAlchemistTrendInspiration::where('team_id', $team->id)->where('herkunft', 'automatisch')->orderBy('id')->get();
    }

    // ── Kennzahlen ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function kennzahlen(Team $team, FoodAlchemistTrendInspiration $thema, array $regeln, ?Carbon $jetzt = null): array
    {
        $jetzt ??= now();
        $schluessel = (string) ($thema->thema_schluessel ?: TrendThemenService::schluessel((string) $thema->titel));
        $sichtungen = [];
        foreach (FoodAlchemistTrendBeleg::where('inspiration_id', $thema->id)->orderBy('id')->get() as $b) {
            $sichtungen[] = $this->sichtung($b, false);
        }
        // Regel 2: Pinnwand-Fundstücke mit gleichem Schlüssel (Titel oder Schlagwort) zählen als Sichtung, die Karte bleibt unverändert
        foreach ($this->passendePinnwand($team, $schluessel) as $insp) {
            foreach ($insp->quellen as $b) {
                $sichtungen[] = $this->sichtung($b, true);
            }
        }
        $artikel = FoodAlchemistTrendEingang::where('thema_inspiration_id', $thema->id)->where('status', '!=', 'verworfen')->orderBy('id')->get();

        $fenster = $jetzt->copy()->subDays((int) $regeln['kandidat_tage']);
        $tage30 = $jetzt->copy()->subDays(30);
        $jahr = $jetzt->copy()->subMonths(12);
        $imFenster = array_filter($sichtungen, fn ($s) => $s['datum']->greaterThanOrEqualTo($fenster));
        $imJahr = array_filter($sichtungen, fn ($s) => $s['datum']->greaterThanOrEqualTo($jahr));
        $arten = array_values(array_unique(array_column($imJahr, 'art')));
        sort($arten);
        $monate = array_values(array_unique(array_map(fn ($s) => $s['datum']->format('Y-m'), $imJahr)));
        sort($monate);
        $mitTiefe = $artikel->filter(fn ($e) => $e->tiefe !== null);
        $sparten = array_values(array_unique($artikel->flatMap(fn ($e) => $e->sparten ?? [])->all()));
        sort($sparten);
        $letzte = $sichtungen === [] ? null : max(array_map(fn ($s) => $s['datum']->getTimestamp(), $sichtungen));

        return [
            'thema_id' => (int) $thema->id, 'thema' => $thema->titel, 'trend_id' => $thema->trend_id !== null ? (int) $thema->trend_id : null,
            'sichtungen' => count($sichtungen),
            'davon_pinnwand' => count(array_filter($sichtungen, fn ($s) => $s['pinnwand'])),
            'sichtungen_fenster' => count($imFenster),
            'sichtungen_30t' => count(array_filter($sichtungen, fn ($s) => $s['datum']->greaterThanOrEqualTo($tage30))),
            'quellen_fenster' => count(array_unique(array_column($imFenster, 'quelle'))),
            'quellenarten' => $arten,
            'harte_quelle' => array_intersect($arten, self::HARTE_ARTEN) !== [],
            'monate' => count($monate),
            'anteil_sozial' => $imJahr === [] ? 0.0 : round(count(array_filter($imJahr, fn ($s) => in_array($s['art'], V::SOZIALE_QUELLEN, true))) / count($imJahr), 3),
            'anteil_beduerfnis' => $mitTiefe->isEmpty() ? 0.0 : round($mitTiefe->where('tiefe', 'beduerfnis')->count() / $mitTiefe->count(), 3),
            'sparten' => $sparten,
            'kategorie' => $this->mehrheit($artikel->pluck('kategorie')->filter()->all()),
            'passt_zu_trend_id' => ($p = $this->mehrheit($artikel->pluck('passt_zu_trend_id')->filter()->map(fn ($v) => (string) $v)->all())) !== null ? (int) $p : null,
            'spitze_ohne_sockel' => $thema->trend_id !== null && (bool) FoodAlchemistTrendSignal::where('trend_id', $thema->trend_id)->orderByDesc('id')->value('spitze_ohne_sockel'),
            'letzte_sichtung' => $letzte !== null ? Carbon::createFromTimestamp($letzte)->toDateString() : null,
        ];
    }

    // ── Einordnung (deterministisch) ───────────────────────────────────────

    /**
     * @param  array<string,mixed>  $k  Kennzahlen
     * @param  array<string,int|float>  $r  wirksame Regeln
     * @return array{kandidat:bool, typ:?string, ebene:?string, abgeklungen:bool, begruendung:list<string>}
     */
    public function einordnen(array $k, array $r, ?Carbon $jetzt = null): array
    {
        $jetzt ??= now();
        $g = [];
        $abgeklungen = $k['letzte_sichtung'] === null
            || Carbon::parse($k['letzte_sichtung'])->lessThan($jetzt->copy()->subMonths((int) $r['abklingen_monate']));
        $kandidat = $k['sichtungen_fenster'] >= $r['kandidat_sichtungen'] && $k['quellen_fenster'] >= $r['kandidat_quellen'];
        $g[] = sprintf('Kandidat %s: %d Sichtungen aus %d Quellen in %d Tagen (Regel ≥ %d aus ≥ %d).', $kandidat ? 'ja' : 'nein',
            $k['sichtungen_fenster'], $k['quellen_fenster'], $r['kandidat_tage'], $r['kandidat_sichtungen'], $r['kandidat_quellen']);
        $tief = $k['anteil_beduerfnis'] >= $r['tiefe_schwelle'];
        $typ = null;
        if ($kandidat) {
            $hypeSignal = $k['sichtungen_30t'] >= $r['hype_sichtungen_30t'] || $k['spitze_ohne_sockel'];
            if ($hypeSignal && ! $tief) {
                $typ = 'hype';
                $g[] = sprintf('Hype: %d Sichtungen in 30 Tagen (≥ %d)%s, Anteil Bedürfnis %s < %s.', $k['sichtungen_30t'], $r['hype_sichtungen_30t'],
                    $k['spitze_ohne_sockel'] ? ' bzw. Google Trends „Spitze ohne Sockel“' : '', $this->zahl($k['anteil_beduerfnis']), $this->zahl($r['tiefe_schwelle']));
            } elseif ($tief && $k['monate'] >= $r['trend_monate'] && count($k['quellenarten']) >= $r['trend_quellenarten'] && $k['harte_quelle']) {
                $typ = 'trend';
                $g[] = sprintf('Trend: Sichtungen in %d Monaten (≥ %d), %d Quellenarten (≥ %d) mit Fachpresse/Branche/Literatur, Anteil Bedürfnis %s ≥ %s.',
                    $k['monate'], $r['trend_monate'], count($k['quellenarten']), $r['trend_quellenarten'], $this->zahl($k['anteil_beduerfnis']), $this->zahl($r['tiefe_schwelle']));
            } else {
                $g[] = 'Typ offen: weder Hype- noch Trend-Regel erfüllt (Monate '.$k['monate'].', Quellenarten '.count($k['quellenarten'])
                    .', harte Quelle '.($k['harte_quelle'] ? 'ja' : 'nein').', Anteil Bedürfnis '.$this->zahl($k['anteil_beduerfnis']).').';
            }
        }
        // Ebene: mode Standard, konsum ab Sparten-Breite oder Dauer; mega/meta setzt die Automatik nie
        $ebene = $typ === null ? null
            : (count($k['sparten']) >= $r['konsum_sparten'] || $k['monate'] >= $r['konsum_monate'] ? 'konsum' : 'mode');
        if ($ebene !== null) {
            $g[] = 'Ebene '.V::label(V::EBENEN, $ebene).' ('.count($k['sparten']).' Sparten, '.$k['monate'].' Monate; Konsum ab '.$r['konsum_sparten'].' bzw. '.$r['konsum_monate'].').';
        }
        if ($abgeklungen) {
            $g[] = 'Abgeklungen: keine Sichtung seit '.$r['abklingen_monate'].' Monaten (letzte '.($k['letzte_sichtung'] ?? '–').').';
        }

        return ['kandidat' => $kandidat, 'typ' => $typ, 'ebene' => $ebene, 'abgeklungen' => $abgeklungen, 'begruendung' => $g];
    }

    // ── Lauf ───────────────────────────────────────────────────────────────

    /**
     * Plant je Thema eine Aktion und führt sie aus, wenn die Stufe nicht „aus“ ist und der Schatten-Modus aus ist.
     * Die Liste ist reproduzierbar: gleicher Datenstand ⇒ gleiche Liste (Reihenfolge nach Thema-ID).
     *
     * @return array{stufe:string, schatten:bool, geschrieben:bool, neue_trends:int, gestaut:int, liste:list<array<string,mixed>>}
     */
    public function lauf(Team $team, ?bool $schreibenErzwingen = null, ?int $userId = null): array
    {
        $e = $this->einstellungen($team);
        $schreiben = $schreibenErzwingen ?? ($e['stufe'] !== 'aus' && ! $e['schatten']);
        if ($schreiben) {
            $userId !== null ? $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Trend-Automatik ausführen')
                : $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Trend-Automatik ausführen');
        }
        $r = $e['regeln'];
        $liste = [];
        $neu = 0;
        $gestaut = 0;
        foreach ($this->themen($team) as $thema) {
            $k = $this->kennzahlen($team, $thema, $r);
            $ein = $this->einordnen($k, $r);
            $plan = $this->planen($team, $thema, $k, $ein);
            if ($plan['aktion'] === 'anlegen') {
                if ($neu >= (int) $r['max_neue_trends_je_lauf']) {
                    $plan['aktion'] = 'gestaut';
                    $plan['hinweis'] = 'Grenze von '.$r['max_neue_trends_je_lauf'].' neuen Trends je Lauf erreicht — bleibt Kandidat.';
                    $gestaut++;
                } else {
                    $neu++;
                }
            }
            if ($schreiben && $e['stufe'] !== 'aus') {
                $plan['ergebnis'] = $this->ausfuehren($team, $thema, $k, $ein, $plan, $e['stufe'], $userId);
            }
            $liste[] = ['kennzahlen' => $k, 'einordnung' => $ein] + $plan;
        }

        if ($schreiben && $e['stufe'] !== 'aus') {
            // Buchführung des Laufs (kein Einstellungs-Schreiben durch den Benutzer, daher ohne Admin-Prüfung): Quelle des Signals „Kandidaten warten“
            $this->settings->for($team)->forceFill(['trend_automatik_letzter_lauf' => ['am' => now()->toIso8601String(), 'neue_trends' => $neu, 'gestaut' => $gestaut]])->save();
        }

        return ['stufe' => $e['stufe'], 'schatten' => $e['schatten'], 'geschrieben' => $schreiben && $e['stufe'] !== 'aus',
            'neue_trends' => $neu, 'gestaut' => $gestaut, 'liste' => $liste];
    }

    /** @return array{aktion:string, trend_id:?int, hinweis:string} */
    private function planen(Team $team, FoodAlchemistTrendInspiration $thema, array $k, array $ein): array
    {
        if ($thema->trend_id !== null) {
            $trend = FoodAlchemistTrend::where('team_id', $team->id)->find($thema->trend_id);
            if ($trend === null) {
                return ['aktion' => 'nichts', 'trend_id' => (int) $thema->trend_id, 'hinweis' => 'Hängt an einem Trend eines anderen Teams.'];
            }

            return $trend->herkunft === 'automatisch'
                ? ['aktion' => 'aktualisieren', 'trend_id' => (int) $trend->id, 'hinweis' => 'Eigener Trend der Automatik: Einordnung und Status nachziehen (ohne von Hand gesetzte Felder).']
                : ['aktion' => 'bewerten', 'trend_id' => (int) $trend->id, 'hinweis' => 'Bestandstrend: nur Konfidenz neu bewerten, Felder bleiben.'];
        }
        if (! $ein['kandidat']) {
            return ['aktion' => 'nichts', 'trend_id' => null, 'hinweis' => 'Noch kein Kandidat.'];
        }
        if ($k['passt_zu_trend_id'] !== null && ($ziel = FoodAlchemistTrend::where('team_id', $team->id)->find($k['passt_zu_trend_id'])) !== null) {
            return ['aktion' => 'anhaengen', 'trend_id' => (int) $ziel->id, 'hinweis' => 'Passt laut Erschließung zu „'.$ziel->name.'“: Artikel werden Belege.'];
        }
        if ($ein['typ'] === null) {
            return ['aktion' => 'nichts', 'trend_id' => null, 'hinweis' => 'Kandidat, Typ offen.'];
        }
        $vorhanden = FoodAlchemistTrend::visibleToTeam($team)->where('slug', $this->trends->slugFuer((string) $thema->titel))->first();
        if ($vorhanden !== null) {
            return (int) $vorhanden->team_id === (int) $team->id
                ? ['aktion' => 'anhaengen', 'trend_id' => (int) $vorhanden->id, 'hinweis' => 'Trend gleichen Namens gibt es schon: Artikel werden Belege.']
                : ['aktion' => 'nichts', 'trend_id' => (int) $vorhanden->id, 'hinweis' => 'Trend gleichen Namens gehört einem anderen Team.'];
        }

        return ['aktion' => 'anlegen', 'trend_id' => null, 'hinweis' => 'Neuer '.V::label(V::TYPEN, $ein['typ']).' wird angelegt.'];
    }

    private function ausfuehren(Team $team, FoodAlchemistTrendInspiration $thema, array $k, array $ein, array $plan, string $stufe, ?int $userId): ?string
    {
        if (! in_array($plan['aktion'], ['anlegen', 'anhaengen', 'aktualisieren', 'bewerten'], true)) {
            return null;
        }

        return DB::transaction(fn () => $this->trends->alsAutomatik(function () use ($team, $thema, $k, $ein, $plan, $stufe, $userId) {
            if ($plan['aktion'] === 'anlegen') {
                $trend = $this->trends->anlegen($team, [
                    'name' => $thema->titel, 'typ' => $ein['typ'], 'ebene' => $ein['ebene'], 'kategorie' => $k['kategorie'],
                    'sparten' => $k['sparten'], 'einordnung_quelle' => 'ki',
                    'einordnung_begruendung' => 'Automatik (Spec 93): '.implode(' ', $ein['begruendung']),
                    'inspiration_ids' => [$thema->id],
                ], $userId);
                $trend->forceFill(['herkunft' => 'automatisch'])->save();
                $this->ereignis($trend, $ein['typ'] === 'hype' ? 'neu_hype' : 'neu_trend');
                $this->statusNachStufe($team, $trend->refresh(), $stufe, $userId);

                return 'angelegt';
            }
            $trend = FoodAlchemistTrend::where('team_id', $team->id)->findOrFail($plan['trend_id']);
            if ($plan['aktion'] === 'anhaengen') {
                $this->trends->inspirationZuordnen($team, (int) $thema->id, (int) $trend->id, $userId);
            }
            if ($trend->herkunft !== 'automatisch') {
                $this->trends->neuBewerten($trend->refresh());   // Bestand: nur Belege + Konfidenz, Felder bleiben

                return $plan['aktion'] === 'anhaengen' ? 'angehaengt' : 'bewertet';
            }
            $this->einordnungNachziehen($trend->refresh(), $k, $ein);
            $this->statusNachStufe($team, $trend->refresh(), $stufe, $userId);

            return $plan['aktion'] === 'anhaengen' ? 'angehaengt' : 'aktualisiert';
        }));
    }

    /** Eigener Trend der Automatik: Typ/Ebene/Kategorie/Sparten nachziehen — nur Felder ohne Manuell-Merker, nie mega/meta. */
    private function einordnungNachziehen(FoodAlchemistTrend $trend, array $k, array $ein): void
    {
        $manuell = $trend->manuell_felder ?? [];
        $update = [];
        if ($ein['typ'] !== null && ! in_array('typ', $manuell, true) && $trend->typ !== $ein['typ']) {
            if ($trend->typ === 'hype' && $ein['typ'] === 'trend') {
                $this->ereignis($trend, 'hype_wird_trend');
            }
            $update['typ'] = $ein['typ'];
        }
        if ($ein['ebene'] !== null && ! in_array('ebene', $manuell, true) && ! in_array($trend->ebene, ['mega', 'meta'], true) && $trend->ebene !== $ein['ebene']) {
            $update['ebene'] = $ein['ebene'];
        }
        if ($k['kategorie'] !== null && ! in_array('kategorie', $manuell, true) && $trend->kategorie === null) {
            $update['kategorie'] = $k['kategorie'];
        }
        if (! in_array('sparten', $manuell, true) && $k['sparten'] !== ($trend->sparten ?? [])) {
            $update['sparten'] = $k['sparten'] ?: null;
        }
        if ($update !== []) {
            $update['einordnung_begruendung'] = 'Automatik (Spec 93): '.implode(' ', $ein['begruendung']);
            $trend->forceFill($update)->save();
        }
        $this->trends->neuBewerten($trend->refresh());
    }

    /** Status je Stufe: nur anheben, nie senken, nie wenn der Status von Hand gesetzt ist. */
    private function statusNachStufe(Team $team, FoodAlchemistTrend $trend, string $stufe, ?int $userId): void
    {
        if (in_array('status', $trend->manuell_felder ?? [], true) || ! in_array($trend->status, ['gesichtet', 'geprueft'], true)) {
            return;
        }
        $hindernis = $this->trends->radarHindernis($trend);
        if ($stufe === 'radar' && $hindernis === null) {
            $this->trends->statusSetzen($team, (int) $trend->id, 'auf_radar', $userId);
            $this->ereignis($trend->refresh(), 'auf_radar');

            return;
        }
        if ($trend->status === 'gesichtet') {
            $this->trends->statusSetzen($team, (int) $trend->id, 'geprueft', $userId);
        }
        if ($stufe === 'radar' && $hindernis !== null) {
            $trend->refresh()->forceFill(['einordnung_begruendung' => trim((string) $trend->einordnung_begruendung).' Nicht aufs Radar: '.$hindernis])->save();
        }
    }

    private function ereignis(FoodAlchemistTrend $trend, string $art): void
    {
        $liste = $trend->automatik_ereignisse ?? [];
        $liste[] = ['art' => $art, 'am' => now()->toIso8601String()];
        $trend->forceFill(['automatik_ereignisse' => array_slice($liste, -10)])->save();
    }

    // ── intern ─────────────────────────────────────────────────────────────

    /** @return Collection<int, FoodAlchemistTrendInspiration> manuelle Inspirationen der Teamfamilie mit gleichem Schlüssel (Titel oder Schlagwort) */
    public function passendePinnwand(Team $team, string $schluessel): Collection
    {
        if ($schluessel === '') {
            return collect();
        }

        return FoodAlchemistTrendInspiration::whereIn('team_id', $this->trends->fundstueckFamilie($team))->where('herkunft', 'manuell')
            ->with('quellen')->orderBy('id')->get()
            ->filter(function ($i) use ($schluessel) {
                if (TrendThemenService::schluessel((string) $i->titel) === $schluessel) {
                    return true;
                }
                foreach ($i->schlagworte ?? [] as $w) {
                    if (TrendThemenService::schluessel(ltrim((string) $w, '#')) === $schluessel) {
                        return true;
                    }
                }

                return false;
            })->values();
    }

    /** @return array{datum: Carbon, art: string, quelle: string, pinnwand: bool} */
    private function sichtung(FoodAlchemistTrendBeleg $b, bool $pinnwand): array
    {
        $host = $b->url ? strtolower((string) parse_url((string) $b->url, PHP_URL_HOST)) : '';
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return [
            'datum' => $b->beobachtet_am ? Carbon::parse($b->beobachtet_am)->startOfDay() : Carbon::parse($b->created_at)->startOfDay(),
            'art' => (string) $b->quelle,
            'quelle' => $host !== '' ? $host : ($b->fundort ? 'fundort:'.mb_strtolower((string) $b->fundort) : 'beleg:'.$b->id),
            'pinnwand' => $pinnwand,
        ];
    }

    /** Häufigster Wert; bei Gleichstand der alphabetisch erste (stabil). */
    private function mehrheit(array $werte): ?string
    {
        if ($werte === []) {
            return null;
        }
        $n = array_count_values(array_map('strval', $werte));
        ksort($n);
        arsort($n);

        return (string) array_key_first($n);
    }

    private function zahl(float|int $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
    }
}
