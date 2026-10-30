<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\Trends\QuellenAbruf;
use Platform\FoodAlchemist\Support\StichwortFilter;
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
            'id' => (int) $e->id, 'titel' => $e->titel, 'url' => $e->url,
            'quelle_id' => $e->quelle_id !== null ? (int) $e->quelle_id : null, 'quelle' => $e->quelle?->name,
            'veroeffentlicht_am' => $e->veroeffentlicht_am?->toIso8601String(), 'teaser' => $e->teaser,
            'zusammenfassung' => $e->zusammenfassung, 'thema' => $e->thema, 'kategorie' => $e->kategorie, 'sparten' => $e->sparten ?? [],
            'tiefe' => $e->tiefe, 'relevant' => $e->relevant, 'begruendung' => $e->begruendung,
            'status' => $e->status, 'status_label' => V::label(V::EINGANG_STATUS, $e->status),
            'eingereicht_von' => $e->eingereicht_von, 'inspiration_id' => $e->inspiration_id !== null ? (int) $e->inspiration_id : null,
            'zuletzt_gesehen_at' => $e->zuletzt_gesehen_at?->toIso8601String(), 'angelegt_am' => $e->created_at?->toIso8601String(),
            'eigenes_team' => $team === null || (int) $e->team_id === (int) $team->id,
        ];
    }

    // ── Einreichen ─────────────────────────────────────────────────────────

    /**
     * Ein Artikel in den Eingang. `von` = abruf (System) | mcp | hand.
     *
     * @param  array{url:string, titel:string, veroeffentlicht_am?:?string, teaser?:?string, quelle_id?:?int}  $daten
     * @return array{ergebnis:string, eintrag:?FoodAlchemistTrendEingang, grund:?string}
     *                                                                                ergebnis: neu | dublette | gefiltert
     */
    public function einreichen(Team $team, array $daten, string $von = 'mcp', ?int $userId = null): array
    {
        if (! array_key_exists($von, V::EINGEREICHT_VON)) {
            throw new \RuntimeException('eingereicht_von „'.$von.'“ gibt es nicht.');
        }
        if ($von !== 'abruf') {
            $this->pruefe($userId, $team, 'In den Trend-Eingang einreichen');
        }
        $url = trim((string) ($daten['url'] ?? ''));
        $hash = TrendUrl::hash($url);   // wirft ohne http(s)/Host
        $titel = trim(preg_replace('/\s+/u', ' ', (string) ($daten['titel'] ?? '')) ?? '');
        if ($titel === '') {
            throw new \RuntimeException('Ein Eintrag braucht einen Titel.');
        }
        $teaser = $this->teaser($daten['teaser'] ?? null);
        $quelle = ! empty($daten['quelle_id'])
            ? FoodAlchemistTrendQuelle::where('team_id', $team->id)->findOrFail((int) $daten['quelle_id'])
            : null;

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
                $vorhanden->update(['zuletzt_gesehen_at' => now()]);
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
        ]);
        if ($quelle !== null && $von !== 'abruf') {
            $this->mcpBesuch($quelle, 0);
        }

        return ['ergebnis' => 'neu', 'eintrag' => $eintrag->refresh(), 'grund' => $filter['grund']];
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
                break;
            case 'wiederherstellen':
                $eintrag->update(['status' => $eintrag->zusammenfassung !== null ? 'erschlossen' : 'neu']);
                break;
            case 'thema':
                if ($wert === '') {
                    throw new \RuntimeException('Bitte ein Thema angeben.');
                }
                $eintrag->update(['thema' => mb_substr($wert, 0, 190)]);
                break;
            case 'pinnwand':
                if ($eintrag->inspiration_id !== null) {
                    throw new \RuntimeException('Dieser Artikel liegt schon auf der Pinnwand (Inspiration '.$eintrag->inspiration_id.').');
                }
                $beleg = $this->trends->fundstueckAblegen($team, [
                    'titel' => $eintrag->titel, 'url' => $eintrag->url,
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
