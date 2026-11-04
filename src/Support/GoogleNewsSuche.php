<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Carbon;

/** Reversible Suchaufträge in normalen Quellen-URLs; keine zusätzliche Datenhaltung. */
class GoogleNewsSuche
{
    /** Google liefert trotz when: alte Artikel. Vor Preview und KI lokal dieselbe Grenze durchsetzen. */
    public static function datumsGrund(string $url, mixed $datum): ?string
    {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'news.google.com'
            || parse_url($url, PHP_URL_PATH) !== '/rss/search') {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $p);
        if (! is_string($p['q'] ?? null) || ! preg_match('/(?:^|\s)when:(1|7|30|90)d(?:\s|$)/', $p['q'], $m)) {
            return null;
        }
        try {
            if ($datum === null || $datum === '') {
                return 'Google-News-Artikel ohne belastbares Veröffentlichungsdatum';
            }
            $d = Carbon::parse($datum);
        } catch (\Throwable) {
            return 'Google-News-Artikel mit ungültigem Veröffentlichungsdatum';
        }
        if ($d->greaterThan(now()->addDay())) {
            return 'Veröffentlichungsdatum liegt in der Zukunft';
        }
        if ($d->lessThan(now()->subDays((int) $m[1])->startOfDay())) {
            return 'Älter als der gewählte Suchzeitraum ('.$m[1].' Tage)';
        }

        return null;
    }

    public const MAERKTE = ['DE' => 'Deutschland · Deutsch', 'AT' => 'Österreich · Deutsch', 'CH' => 'Schweiz · Deutsch'];

    public const ZEITRAEUME = ['1d' => 'Letzter Tag', '7d' => 'Letzte 7 Tage', '30d' => 'Letzte 30 Tage', '90d' => 'Letzte 90 Tage'];

    public static function begriffe(string $text): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\r\n]+/u', $text) ?: []))));
    }

    public static function url(array $form): string
    {
        $terms = self::begriffe($form['begriffe']);
        $quote = fn ($t) => '"'.str_replace('"', '', $t).'"';
        $query = trim($form['ausdruck'] ?? '');
        if ($query === '') {
            $query = '('.implode(' OR ', array_map($quote, $terms)).')';
        }
        foreach (self::begriffe($form['ausschliessen'] ?? '') as $term) {
            $query .= ' -'.$quote($term);
        }
        $query .= ' when:'.$form['zeitraum'];

        return 'https://news.google.com/rss/search?'.http_build_query([
            'q' => $query, 'hl' => 'de', 'gl' => $form['markt'], 'ceid' => $form['markt'].':de',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Nur Darstellung: Suchlogik und gespeicherte URLs bleiben unverändert. */
    public static function anzeige(array $suche): array
    {
        if ($suche['begriffe'] !== '') {
            return ['begriffe' => self::begriffe($suche['begriffe']), 'kontext' => []];
        }
        $atom = '(?:"[^"()]+"|[\\p{L}\\p{N}_-]+)';
        $gruppe = $atom.'(?: OR '.$atom.')*';
        if (preg_match('/^\\(('.$gruppe.')\\)(?:\\s+\\(('.$gruppe.')\\))?$/u', $suche['ausdruck'], $m)) {
            $split = fn ($v) => array_map(fn ($t) => trim($t, '" '), explode(' OR ', $v));

            return ['begriffe' => $split($m[1]), 'kontext' => isset($m[2]) ? $split($m[2]) : []];
        }

        return ['begriffe' => [], 'kontext' => []];
    }

    public static function lesen(string $url): ?array
    {
        $parts = parse_url($url);
        if (($parts['host'] ?? '') !== 'news.google.com' || ($parts['path'] ?? '') !== '/rss/search') {
            return null;
        }
        parse_str($parts['query'] ?? '', $p);
        if (! is_string($p['q'] ?? null) || ! is_string($p['gl'] ?? 'DE') || ! isset(self::MAERKTE[$p['gl'] ?? 'DE']) || ($p['hl'] ?? 'de') !== 'de') {
            return null;
        }
        $q = $p['q'];
        preg_match('/\s+when:(1d|7d|30d|90d)$/', $q, $period);
        $q = preg_replace('/\s+when:(1d|7d|30d|90d)$/', '', $q);
        $out = ['begriffe' => '', 'ausdruck' => $q, 'ausschliessen' => '', 'markt' => $p['gl'] ?? 'DE', 'zeitraum' => $period[1] ?? '30d'];
        // Nur unser eigenes Format zerlegen. Bestehende komplexe Suchen bleiben verlustfrei als Ausdruck editierbar.
        if (preg_match('/^\(("[^"\r\n]+"(?: OR "[^"\r\n]+")*)\)((?: -"[^"\r\n]+")*)$/u', $q, $m)) {
            preg_match_all('/"([^"\r\n]+)"/u', $m[1], $terms);
            preg_match_all('/-"([^"\r\n]+)"/u', $m[2], $excluded);
            $out['begriffe'] = implode("\n", $terms[1]);
            $out['ausschliessen'] = implode("\n", $excluded[1]);
            $out['ausdruck'] = '';
        }

        return $out;
    }
}
