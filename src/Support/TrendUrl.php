<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Spec 93 · Normalisierte URL für den Dublettenschutz im Trend-Eingang: Schema und Host klein, Standard-Port, Fragment
 * und Tracking-Parameter (utm_*, fbclid, gclid, igsh …) weg, übrige Parameter sortiert, ohne abschließenden Slash.
 * Gleiche normalisierte URL = derselbe Artikel.
 */
final class TrendUrl
{
    /** Tracking-Parameter, die einen Link nicht zu einem anderen Artikel machen. Präfix-Einträge enden auf „*“. */
    private const TRACKING = ['utm_*', 'fbclid', 'gclid', 'dclid', 'msclkid', 'igsh', 'igshid', 'mc_cid', 'mc_eid', '_hsenc', '_hsmi', 'yclid', 'wt_mc'];

    public static function normalisieren(string $url): string
    {
        $url = trim($url);
        $t = parse_url($url);
        $schema = strtolower((string) ($t['scheme'] ?? ''));
        $host = strtolower((string) ($t['host'] ?? ''));
        if (! in_array($schema, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('Der Link muss mit http:// oder https:// beginnen und einen Host haben.');
        }
        $port = isset($t['port']) && ! ($schema === 'http' && (int) $t['port'] === 80) && ! ($schema === 'https' && (int) $t['port'] === 443)
            ? ':'.(int) $t['port'] : '';
        $pfad = rtrim((string) ($t['path'] ?? ''), '/');

        $query = '';
        if (isset($t['query']) && $t['query'] !== '') {
            $teile = [];
            foreach (explode('&', $t['query']) as $paar) {
                if ($paar === '') {
                    continue;
                }
                $name = strtolower(urldecode(explode('=', $paar, 2)[0]));
                if (! self::istTracking($name)) {
                    $teile[] = $paar;
                }
            }
            sort($teile);
            $query = $teile !== [] ? '?'.implode('&', $teile) : '';
        }

        return $schema.'://'.$host.$port.$pfad.$query;
    }

    public static function hash(string $url): string
    {
        return hash('sha256', self::normalisieren($url));
    }

    private static function istTracking(string $name): bool
    {
        foreach (self::TRACKING as $t) {
            if (str_ends_with($t, '*') ? str_starts_with($name, rtrim($t, '*')) : $name === $t) {
                return true;
            }
        }

        return false;
    }
}
