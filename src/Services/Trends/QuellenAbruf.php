<?php

namespace Platform\FoodAlchemist\Services\Trends;

use Illuminate\Http\Client\Response;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Support\SichererAbrufFehler;

/**
 * Spec 93 A · Abruf einer Trend-Quelle über SichererAbruf (SSRF-Schutz), ohne KI.
 *
 * - `pruefen()` wählt beim Anlegen den Weg: Feed gefunden → rss/atom über die Plattform; sonst Probelauf der
 *   Übersicht mit ≥ Schwelle Artikeln → uebersicht über die Plattform; sonst (zu wenig Treffer, Bot-Sperre, Fehler) →
 *   mcp/manuell, die Plattform ruft nie selbst ab.
 * - `lesen()` holt die Einträge einer plattform-Quelle. Eine Bot-Sperre (401/403/429) wird nicht umgangen, sondern als
 *   Fehler mit dem Hinweis „auf MCP umstellen“ gemeldet; eine Übersicht ohne Treffer ist ein Fehler, kein stiller Leerlauf.
 */
class QuellenAbruf
{
    public const GESPERRT = 'Abruf gesperrt';

    public const KEINE_ARTIKEL = 'keine Artikel erkannt – auf MCP umstellen';

    public const NICHT_LESBAR = 'kein Feed, Seite nicht lesbar – wird per MCP gesichtet';

    public function __construct(private readonly SichererAbruf $abruf, private readonly FeedLeser $leser) {}

    /**
     * @return array{feed_typ:string, abruf_weg:string, feed_url:?string, erkannt:string, grund:string, treffer:int}
     */
    public function pruefen(string $url): array
    {
        $schwelle = (int) config('foodalchemist.trends.quellen.uebersicht_mindest_treffer', 3);
        try {
            $antwort = $this->laden($url);
        } catch (\RuntimeException $e) {
            return $this->mcp('Seite nicht abrufbar', $e->getMessage().' · '.self::NICHT_LESBAR);
        }
        $inhalt = $antwort->body();

        // 1. Die URL ist selbst ein Feed
        if (($typ = $this->leser->feedTyp($inhalt)) !== null && ($n = $this->feedZahl($inhalt, $url)) > 0) {
            return $this->plattform($typ, $url, 'Feed ('.strtoupper($typ).')', $n.' Einträge im Feed', $n);
        }
        // 2. Feed-Link im Kopf der Seite, 3. übliche Feed-Pfade
        $kandidaten = $this->leser->feedLinks($inhalt, $url);
        foreach ($this->ueblichePfade($url) as $pfad) {
            $kandidaten[] = ['url' => $pfad, 'typ' => null];
        }
        $versucht = [];
        foreach ($kandidaten as $k) {
            if (isset($versucht[$k['url']]) || $k['url'] === $url) {
                continue;
            }
            $versucht[$k['url']] = true;
            try {
                $feed = $this->laden($k['url'])->body();
            } catch (\RuntimeException) {
                continue;
            }
            if (($typ = $this->leser->feedTyp($feed)) !== null && ($n = $this->feedZahl($feed, $k['url'])) > 0) {
                $wie = $k['typ'] !== null ? 'Feed-Link auf der Seite' : 'üblicher Feed-Pfad';

                return $this->plattform($typ, $k['url'], 'Feed ('.strtoupper($typ).') über '.$wie, $n.' Einträge im Feed '.$k['url'], $n);
            }
        }
        // 4. Probelauf der Übersicht
        $treffer = count($this->leser->uebersicht($inhalt, $url));
        if ($treffer >= $schwelle) {
            return $this->plattform('uebersicht', null, 'Übersichtsseite', $treffer.' Artikel erkannt (Schwelle '.$schwelle.')', $treffer);
        }

        return $this->mcp('kein Feed, '.$treffer.' Artikel auf der Seite', 'Unter der Schwelle von '.$schwelle.' Artikeln · '.self::NICHT_LESBAR, $treffer);
    }

    /**
     * Einträge einer plattform-Quelle. Wirft QuellenAbrufFehler mit einer Meldung für letzter_fehler.
     *
     * @return list<array{titel:string, url:string, veroeffentlicht_am:?string, teaser:?string}>
     */
    public function lesen(FoodAlchemistTrendQuelle $quelle): array
    {
        $url = (string) ($quelle->feed_url ?: $quelle->url);
        $inhalt = $this->laden($url)->body();
        if ($quelle->feed_typ === 'uebersicht') {
            $eintraege = $this->leser->uebersicht($inhalt, $url);
            if ($eintraege === []) {
                throw new QuellenAbrufFehler(self::KEINE_ARTIKEL);
            }

            return $eintraege;
        }
        try {
            return $this->leser->feed($inhalt, $url);
        } catch (\RuntimeException $e) {
            throw new QuellenAbrufFehler('Feed nicht lesbar ('.$e->getMessage().') – Quelle neu prüfen oder auf MCP umstellen', 0, $e);
        }
    }

    /** Holt eine URL; 401/403/429 = Bot-Sperre (nicht umgehen), sonstige Fehlerstatus als Fehler. */
    public function laden(string $url): Response
    {
        try {
            $antwort = $this->abruf->laden($url, (int) config('foodalchemist.trends.quellen.max_bytes', 2 * 1024 * 1024),
                (int) config('foodalchemist.trends.quellen.timeout', 20));
        } catch (SichererAbrufFehler $e) {
            throw new QuellenAbrufFehler($e->getMessage(), 0, $e);
        }
        if (in_array($antwort->status(), [401, 403, 429], true)) {
            throw new QuellenAbrufFehler(self::GESPERRT.' (HTTP '.$antwort->status().') – auf MCP umstellen');
        }
        if (! $antwort->successful()) {
            throw new QuellenAbrufFehler('HTTP '.$antwort->status().' beim Abruf');
        }

        return $antwort;
    }

    /** @return list<string> */
    private function ueblichePfade(string $url): array
    {
        $t = parse_url($url);
        $wurzel = ($t['scheme'] ?? 'https').'://'.($t['host'] ?? '').(isset($t['port']) ? ':'.$t['port'] : '');
        $pfad = rtrim((string) ($t['path'] ?? ''), '/');

        return array_values(array_unique(array_filter([
            $pfad !== '' ? $wurzel.$pfad.'/feed/' : null,
            $wurzel.'/feed/', $wurzel.'/rss', $wurzel.'/rss.xml',
        ])));
    }

    private function feedZahl(string $xml, string $url): int
    {
        try {
            return count($this->leser->feed($xml, $url));
        } catch (\RuntimeException) {
            return 0;
        }
    }

    private function plattform(string $typ, ?string $feedUrl, string $erkannt, string $grund, int $treffer): array
    {
        return ['feed_typ' => $typ, 'abruf_weg' => 'plattform', 'feed_url' => $feedUrl, 'erkannt' => $erkannt, 'grund' => $grund, 'treffer' => $treffer];
    }

    private function mcp(string $erkannt, string $grund, int $treffer = 0): array
    {
        return ['feed_typ' => 'manuell', 'abruf_weg' => 'mcp', 'feed_url' => null, 'erkannt' => $erkannt, 'grund' => $grund, 'treffer' => $treffer];
    }
}
