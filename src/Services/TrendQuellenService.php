<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\Trends\QuellenAbruf;
use Platform\FoodAlchemist\Support\StichwortFilter;
use Platform\FoodAlchemist\Support\TrendUrl;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * Spec 93 A · Quellen des Trendradars — der eine Schreibpfad für UI und MCP.
 *
 * Lesen wie Spec 79 (Kind-Teams sehen die Quellen der Eltern), Schreiben nur im eigenen Team mit Kuratieren.
 * Beim Anlegen ohne ausdrücklichen feed_typ/abruf_weg wählt die Prüfung den Weg (Feed → Übersicht → MCP). Die
 * Fälligkeit folgt dem Rhythmus der Quelle; bei mcp-Quellen steuert sie nur die Reihenfolge in trend_quellen.GET.
 */
class TrendQuellenService
{
    private const FELDER = ['name', 'url', 'art', 'feed_typ', 'abruf_weg', 'rhythmus', 'schwerpunkt_kategorien', 'schwerpunkt_sparten',
        'stichworte_ein', 'stichworte_aus', 'aktiv'];

    public function __construct(private readonly FaRechte $rechte, private readonly QuellenAbruf $abruf, private readonly TeamSettingsService $settings) {}

    // ── Lesen ──────────────────────────────────────────────────────────────

    /**
     * @param  array{abruf_weg?:string, nur_faellige?:bool, suche?:string}  $filter
     * @return Collection<int, FoodAlchemistTrendQuelle>
     */
    public function liste(Team $team, array $filter = []): Collection
    {
        $q = FoodAlchemistTrendQuelle::visibleToTeam($team);
        if (! empty($filter['abruf_weg'])) {
            $q->where('abruf_weg', (string) $filter['abruf_weg']);
        }
        $suche = trim((string) ($filter['suche'] ?? ''));
        if ($suche !== '') {
            $q->where(fn ($w) => $w->where('name', 'like', '%'.$suche.'%')->orWhere('url', 'like', '%'.$suche.'%'));
        }
        $liste = $q->orderBy('name')->get();
        if (! empty($filter['nur_faellige'])) {
            $liste = $liste->filter(fn ($q) => $this->istFaellig($q))->values();
        }

        // fällige zuerst (am längsten überfällig oben), dann nicht fällige, dann ausgeschaltete
        return $liste->sortBy(function (FoodAlchemistTrendQuelle $q) {
            $f = $this->faelligSeit($q);

            return $f === null ? [2, 0, $q->name] : [$f->isFuture() ? 1 : 0, $f->getTimestamp(), $q->name];
        })->values();
    }

    public function detail(Team $team, int $id): FoodAlchemistTrendQuelle
    {
        return FoodAlchemistTrendQuelle::visibleToTeam($team)->findOrFail($id);
    }

    /** Ab wann die Quelle fällig ist (null = nie: aus oder Rhythmus „aus“). Noch nie abgerufen = fällig seit Anlage. */
    public function faelligSeit(FoodAlchemistTrendQuelle $q): ?Carbon
    {
        $tage = V::RHYTHMUS_TAGE[$q->rhythmus] ?? null;
        if (! $q->aktiv || $tage === null) {
            return null;
        }

        return $q->letzter_abruf_at !== null ? $q->letzter_abruf_at->copy()->addDays($tage) : ($q->created_at?->copy() ?? now());
    }

    public function istFaellig(FoodAlchemistTrendQuelle $q, ?Carbon $jetzt = null): bool
    {
        $f = $this->faelligSeit($q);

        return $f !== null && $f->lessThanOrEqualTo($jetzt ?? now());
    }

    /** Wirksame Stichwort-Listen: je Liste die der Quelle, sonst der Team-Standard des besitzenden Teams. */
    public function stichworte(FoodAlchemistTrendQuelle $q): array
    {
        $team = Team::find($q->team_id);
        $standard = $team ? $this->settings->trendStichworte($team) : ['ein' => [], 'aus' => []];
        $ein = StichwortFilter::liste($q->stichworte_ein ?? []);
        $aus = StichwortFilter::liste($q->stichworte_aus ?? []);

        return ['ein' => $ein !== [] ? $ein : $standard['ein'], 'aus' => $aus !== [] ? $aus : $standard['aus'],
            'ein_quelle' => $ein !== [] ? 'quelle' : 'team', 'aus_quelle' => $aus !== [] ? 'quelle' : 'team'];
    }

    public function alsArray(FoodAlchemistTrendQuelle $q, ?Team $team = null): array
    {
        $f = $this->faelligSeit($q);
        $w = $this->stichworte($q);

        return [
            'id' => (int) $q->id, 'name' => $q->name, 'url' => $q->url, 'feed_url' => $q->feed_url,
            'art' => $q->art, 'art_label' => V::label(V::QUELLEN, $q->art),
            'feed_typ' => $q->feed_typ, 'abruf_weg' => $q->abruf_weg, 'rhythmus' => $q->rhythmus, 'aktiv' => (bool) $q->aktiv,
            'schwerpunkt_kategorien' => $q->schwerpunkt_kategorien ?? [], 'schwerpunkt_sparten' => $q->schwerpunkt_sparten ?? [],
            'stichworte_ein' => $q->stichworte_ein ?? [], 'stichworte_aus' => $q->stichworte_aus ?? [],
            'stichworte_wirksam' => ['ein' => $w['ein'], 'aus' => $w['aus'], 'ein_von' => $w['ein_quelle'], 'aus_von' => $w['aus_quelle']],
            'letzter_abruf_at' => $q->letzter_abruf_at?->toIso8601String(),
            'faellig_seit' => $f?->toIso8601String(), 'faellig' => $this->istFaellig($q),
            'letzter_fehler' => $q->letzter_fehler, 'fehler_in_folge' => (int) $q->fehler_in_folge,
            'gefiltert_letzter_abruf' => (int) $q->gefiltert_letzter_abruf, 'gefiltert_gesamt' => (int) $q->gefiltert_gesamt,
            'pruef_hinweis' => $q->pruef_hinweis,
            'eigenes_team' => $team === null || (int) $q->team_id === (int) $team->id,
        ];
    }

    // ── Schreiben ──────────────────────────────────────────────────────────

    /**
     * Quelle anlegen. Ohne feed_typ und abruf_weg prüft das System die URL und wählt den Weg selbst.
     *
     * @return array{quelle: FoodAlchemistTrendQuelle, pruefung: ?array}
     */
    public function anlegen(Team $team, array $daten, ?int $userId = null): array
    {
        $this->pruefe($userId, $team, 'Trend-Quelle anlegen');
        $url = $this->url($daten['url'] ?? null);
        $hash = TrendUrl::hash($url);
        foreach (FoodAlchemistTrendQuelle::where('team_id', $team->id)->get(['id', 'url']) as $vorhanden) {
            if (TrendUrl::hash((string) $vorhanden->url) === $hash) {
                throw new \RuntimeException('Diese Quelle gibt es im Team schon (ID '.$vorhanden->id.').');
            }
        }
        $felder = $this->felder($daten);
        $felder['name'] = $felder['name'] ?? (string) parse_url($url, PHP_URL_HOST);
        $felder['url'] = $url;
        $felder['rhythmus'] = $felder['rhythmus'] ?? $this->settings->trendQuellenRhythmus($team);

        $pruefung = null;
        if (! isset($felder['feed_typ']) && ! isset($felder['abruf_weg'])) {
            $pruefung = $this->abruf->pruefen($url);
            $felder = array_merge($felder, $this->ausPruefung($pruefung));
        } else {
            $felder['abruf_weg'] = $felder['abruf_weg'] ?? (($felder['feed_typ'] ?? 'manuell') === 'manuell' ? 'mcp' : 'plattform');
            $felder['feed_typ'] = $felder['feed_typ'] ?? ($felder['abruf_weg'] === 'mcp' ? 'manuell' : null);
            if ($felder['feed_typ'] === null) {
                throw new \RuntimeException('abruf_weg=plattform braucht einen feed_typ (rss, atom oder uebersicht) — oder beides weglassen, dann prüft das System selbst.');
            }
            $this->wegPasst($felder['feed_typ'], $felder['abruf_weg']);
        }

        $quelle = FoodAlchemistTrendQuelle::create(array_merge($felder, ['team_id' => $team->id, 'created_by' => $userId]));

        return ['quelle' => $quelle->refresh(), 'pruefung' => $pruefung];
    }

    /**
     * Quelle ändern; mit `pruefen` prüft das System die URL neu und setzt Weg und Typ (z. B. wenn eine Übersicht kaputtgeht).
     *
     * @return array{quelle: FoodAlchemistTrendQuelle, pruefung: ?array}
     */
    public function aendern(Team $team, int $id, array $daten, bool $pruefen = false, ?int $userId = null): array
    {
        $this->pruefe($userId, $team, 'Trend-Quelle ändern');
        $quelle = $this->eigene($team, $id);
        $felder = $this->felder($daten);
        if (isset($felder['url'])) {
            $felder['url'] = $this->url($felder['url']);
        }
        $pruefung = null;
        if ($pruefen) {
            $pruefung = $this->abruf->pruefen((string) ($felder['url'] ?? $quelle->url));
            $felder = array_merge($felder, $this->ausPruefung($pruefung), ['letzter_fehler' => null, 'fehler_in_folge' => 0]);
        } elseif (isset($felder['feed_typ']) || isset($felder['abruf_weg'])) {
            $this->wegPasst($felder['feed_typ'] ?? $quelle->feed_typ, $felder['abruf_weg'] ?? $quelle->abruf_weg);
        }
        $quelle->update($felder);

        return ['quelle' => $quelle->refresh(), 'pruefung' => $pruefung];
    }

    public function loeschen(Team $team, int $id, ?int $userId = null): void
    {
        $this->pruefe($userId, $team, 'Trend-Quelle löschen');
        $this->eigene($team, $id)->delete();
    }

    /**
     * „Was käme durch?“ — holt den Feed bzw. die Übersicht und zeigt durchgelassen vs. gefiltert. Speichert nichts.
     *
     * @return array{durch: list<array>, gefiltert: list<array>, fehler: ?string}
     */
    public function probelauf(Team $team, int $id): array
    {
        $quelle = $this->detail($team, $id);
        if ($quelle->abruf_weg !== 'plattform') {
            return ['durch' => [], 'gefiltert' => [], 'fehler' => 'Diese Quelle wird per MCP gesichtet — die Plattform ruft sie nicht ab.'];
        }
        try {
            $eintraege = $this->abruf->lesen($quelle);
        } catch (\RuntimeException $e) {
            return ['durch' => [], 'gefiltert' => [], 'fehler' => $e->getMessage()];
        }
        $w = $this->stichworte($quelle);
        $durch = $gefiltert = [];
        foreach ($eintraege as $e) {
            $f = StichwortFilter::pruefen($e['titel'].' '.($e['teaser'] ?? ''), $w['ein'], $w['aus']);
            $zeile = ['titel' => $e['titel'], 'url' => $e['url'], 'grund' => $f['grund']];
            if ($f['durch']) {
                $durch[] = $zeile;
            } else {
                $gefiltert[] = $zeile;
            }
        }

        return ['durch' => $durch, 'gefiltert' => $gefiltert, 'fehler' => null];
    }

    /** Der Abruf meldet Erfolg oder Fehler an die Quelle (kein Abbruch des Laufs). */
    public function abrufErgebnis(FoodAlchemistTrendQuelle $quelle, ?string $fehler, int $gefiltert): void
    {
        $quelle->update([
            'letzter_abruf_at' => now(),
            'letzter_fehler' => $fehler !== null ? mb_substr($fehler, 0, 2000) : null,
            'fehler_in_folge' => $fehler !== null ? (int) $quelle->fehler_in_folge + 1 : 0,
            'gefiltert_letzter_abruf' => $gefiltert,
            'gefiltert_gesamt' => (int) $quelle->gefiltert_gesamt + $gefiltert,
        ]);
    }

    // ── intern ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function ausPruefung(array $p): array
    {
        return ['feed_typ' => $p['feed_typ'], 'abruf_weg' => $p['abruf_weg'], 'feed_url' => $p['feed_url'],
            'pruef_hinweis' => mb_substr('Erkannt: '.$p['erkannt'].' · gewählt: '.V::label(V::ABRUF_WEGE, $p['abruf_weg']).' ('.V::label(V::FEED_TYPEN, $p['feed_typ']).') · Grund: '.$p['grund'], 0, 500)];
    }

    private function wegPasst(string $feedTyp, string $weg): void
    {
        if ($weg === 'plattform' && $feedTyp === 'manuell') {
            throw new \RuntimeException('Eine manuelle Quelle kann die Plattform nicht abrufen — abruf_weg mcp wählen oder feed_typ setzen.');
        }
    }

    /** @return array<string,mixed> nur die übergebenen, geprüften Felder */
    private function felder(array $d): array
    {
        $f = [];
        foreach (self::FELDER as $k) {
            if (! array_key_exists($k, $d)) {
                continue;
            }
            $v = $d[$k];
            $f[$k] = match ($k) {
                'name' => $this->text($v, 190) ?? throw new \RuntimeException('Der Name darf nicht leer sein.'),
                'url' => (string) $v,
                'art' => $this->enum($v, V::QUELLEN, 'Quellenart'),
                'feed_typ' => $this->enum($v, V::FEED_TYPEN, 'feed_typ'),
                'abruf_weg' => $this->enum($v, V::ABRUF_WEGE, 'abruf_weg'),
                'rhythmus' => $this->enum($v, V::RHYTHMEN, 'Rhythmus'),
                'schwerpunkt_kategorien' => $this->mehrfach($v, V::KATEGORIEN, 'Kategorie'),
                'schwerpunkt_sparten' => $this->mehrfach($v, V::SPARTEN, 'Sparte'),
                'stichworte_ein', 'stichworte_aus' => StichwortFilter::liste($v) ?: null,
                'aktiv' => (bool) $v,
            };
        }

        return $f;
    }

    private function url(mixed $wert): string
    {
        $url = trim((string) ($wert ?? ''));
        if ($url === '') {
            throw new \RuntimeException('Die Quelle braucht eine URL.');
        }
        TrendUrl::normalisieren($url);   // wirft bei fehlendem http(s) oder Host

        return mb_substr($url, 0, 2048);
    }

    private function enum(mixed $wert, array $liste, string $label): string
    {
        $w = (string) $wert;
        if (! array_key_exists($w, $liste)) {
            throw new \RuntimeException($label.' „'.$w.'“ gibt es nicht. Erlaubt: '.implode(', ', array_keys($liste)).'.');
        }

        return $w;
    }

    /** @return list<string>|null */
    private function mehrfach(mixed $wert, array $liste, string $label): ?array
    {
        $werte = array_values(array_unique(array_filter((array) $wert, fn ($w) => $w !== '' && $w !== null)));
        foreach ($werte as $w) {
            $this->enum($w, $liste, $label);
        }

        return $werte !== [] ? $werte : null;
    }

    private function text(mixed $wert, int $max): ?string
    {
        $t = trim((string) ($wert ?? ''));

        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    private function eigene(Team $team, int $id): FoodAlchemistTrendQuelle
    {
        return FoodAlchemistTrendQuelle::where('team_id', $team->id)->findOrFail($id);
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
