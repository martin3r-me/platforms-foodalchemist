<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Lädt eine von außen vorgegebene URL serverseitig, ohne dass sie ins interne Netz zeigen kann (SSRF-Schutz).
 *
 * - nur http/https, keine Zugangsdaten in der URL
 * - der Host wird aufgelöst; zeigt EINE seiner Adressen auf Loopback, privates Netz, Link-Local (169.254.x, auch
 *   Cloud-Metadaten), CGNAT, Multicast, reservierte Bereiche oder eine IPv6-Hülle darum, wird abgelehnt
 * - die Verbindung geht an die geprüfte Adresse (CURLOPT_RESOLVE), DNS-Rebinding zwischen Prüfung und Abruf greift nicht
 * - Weiterleitungen folgen wir selbst, jede Stufe wird neu geprüft (höchstens 3)
 * - Timeout, Größengrenze (Content-Length vorab, Fortschritt beim Laden, Länge danach), eigener User-Agent
 *
 * Fehler kommen als SichererAbrufFehler (RuntimeException) mit einer Meldung für den Menschen.
 */
class SichererAbruf
{
    public const USER_AGENT = 'FoodAlchemist-Abruf/1.0 (+https://foodalchemist.io)';

    public const MAX_WEITERLEITUNGEN = 3;

    /** Gesperrte IPv4-Bereiche (CIDR). Bewusst ausdrücklich statt nur filter_var, dessen Bereiche je PHP-Version schwanken. */
    private const GESPERRT_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** Gesperrte IPv6-Bereiche (CIDR). Hüllen um IPv4 (::ffff:, 64:ff9b::, 2002::) prüfen wir über die innere Adresse. */
    private const GESPERRT_V6 = ['::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001:db8::/32', '100::/64'];

    /** @var \Closure(string): list<string> */
    private \Closure $aufloeser;

    /** @param  (\Closure(string): list<string>)|null  $aufloeser  Host → IP-Adressen; Standard DNS (A + AAAA). */
    public function __construct(?\Closure $aufloeser = null)
    {
        $this->aufloeser = $aufloeser ?? fn (string $host): array => self::dnsAufloesen($host);
    }

    /**
     * Holt die URL. Gibt die Antwort der letzten Stufe zurück (auch 4xx/5xx — der Aufrufer entscheidet), wirft bei
     * gesperrtem Ziel, zu vielen Weiterleitungen, Netzfehler oder zu großem Inhalt.
     */
    public function laden(string $url, int $maxBytes, int $timeout = 20): Response
    {
        $aktuell = $url;
        for ($stufe = 0; $stufe <= self::MAX_WEITERLEITUNGEN; $stufe++) {
            [$host, $port, $ip] = $this->pruefeZiel($aktuell);
            $antwort = $this->einzelAbruf($aktuell, $host, $port, $ip, $maxBytes, $timeout);
            if ($antwort->status() >= 300 && $antwort->status() < 400 && $antwort->header('Location') !== '') {
                $aktuell = self::absolut($aktuell, $antwort->header('Location'));
                continue;
            }
            if (strlen($antwort->body()) > $maxBytes) {
                throw new SichererAbrufFehler('Der Inhalt ist größer als '.self::groesse($maxBytes).'.');
            }

            return $antwort;
        }

        throw new SichererAbrufFehler('Zu viele Weiterleitungen (mehr als '.self::MAX_WEITERLEITUNGEN.').');
    }

    /**
     * Prüft Schema, Host und alle aufgelösten Adressen. Gibt Host, Port und die erste erlaubte Adresse zurück.
     *
     * @return array{0:string, 1:int, 2:string}
     */
    public function pruefeZiel(string $url): array
    {
        $teile = parse_url($url);
        $schema = strtolower((string) ($teile['scheme'] ?? ''));
        if (! in_array($schema, ['http', 'https'], true)) {
            throw new SichererAbrufFehler('Die URL muss mit http:// oder https:// beginnen.');
        }
        if (isset($teile['user']) || isset($teile['pass'])) {
            throw new SichererAbrufFehler('Die URL darf keine Zugangsdaten enthalten.');
        }
        $host = strtolower(trim((string) ($teile['host'] ?? ''), '[]'));
        if ($host === '') {
            throw new SichererAbrufFehler('Die URL hat keinen Host.');
        }
        $port = (int) ($teile['port'] ?? ($schema === 'https' ? 443 : 80));

        $adressen = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->aufloeser)($host);
        if ($adressen === []) {
            throw new SichererAbrufFehler('Der Host „'.$host.'“ ist nicht auflösbar.');
        }
        foreach ($adressen as $ip) {
            if (! self::adresseErlaubt($ip)) {
                throw new SichererAbrufFehler('Die Adresse „'.$host.'“ zeigt ins interne Netz und wird nicht geladen.');
            }
        }

        return [$host, $port, $adressen[0]];
    }

    /** true nur für öffentliche Unicast-Adressen. */
    public static function adresseErlaubt(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::GESPERRT_V4 as $netz) {
                if (self::imNetz($ip, $netz)) {
                    return false;
                }
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }
        $bin = inet_pton($ip);
        // IPv4 in IPv6-Hüllen: ::ffff:a.b.c.d (mapped), ::a.b.c.d (kompatibel), 64:ff9b::/96 (NAT64), 2002::/16 (6to4)
        $innen = null;
        if (str_starts_with($bin, str_repeat("\0", 10)."\xff\xff") || (str_starts_with($bin, str_repeat("\0", 12)) && substr($bin, 12) !== "\0\0\0\0" && substr($bin, 12) !== "\0\0\0\1")) {
            $innen = substr($bin, 12, 4);
        } elseif (str_starts_with($bin, "\x00\x64\xff\x9b".str_repeat("\0", 8))) {
            $innen = substr($bin, 12, 4);
        } elseif (str_starts_with($bin, "\x20\x02")) {
            $innen = substr($bin, 2, 4);
        }
        if ($innen !== null) {
            return self::adresseErlaubt((string) inet_ntop($innen));
        }
        foreach (self::GESPERRT_V6 as $netz) {
            if (self::imNetz($ip, $netz)) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function einzelAbruf(string $url, string $host, int $port, string $ip, int $maxBytes, int $timeout): Response
    {
        $ziel = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $optionen = [
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ziel]],
            'on_headers' => function ($antwort) use ($maxBytes) {
                $laenge = (int) ($antwort->getHeaderLine('Content-Length') ?: 0);
                if ($laenge > $maxBytes) {
                    throw new SichererAbrufFehler('Der Inhalt ist größer als '.self::groesse($maxBytes).'.');
                }
            },
            'progress' => function ($gesamt, $geladen) use ($maxBytes) {
                if ($geladen > $maxBytes) {
                    throw new SichererAbrufFehler('Der Inhalt ist größer als '.self::groesse($maxBytes).'.');
                }
            },
        ];
        try {
            return Http::withOptions($optionen)->withUserAgent(self::USER_AGENT)
                ->connectTimeout(min(10, $timeout))->timeout($timeout)->get($url);
        } catch (SichererAbrufFehler $e) {
            throw $e;
        } catch (\Throwable $e) {
            $innen = $e->getPrevious();
            while ($innen !== null) {
                if ($innen instanceof SichererAbrufFehler) {
                    throw $innen;
                }
                $innen = $innen->getPrevious();
            }
            throw new SichererAbrufFehler('Die Adresse konnte nicht geladen werden ('.class_basename($e).').', 0, $e);
        }
    }

    /** @return list<string> */
    private static function dnsAufloesen(string $host): array
    {
        $adressen = gethostbynamel($host) ?: [];
        $v6 = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($v6 as $eintrag) {
            if (! empty($eintrag['ipv6'])) {
                $adressen[] = (string) $eintrag['ipv6'];
            }
        }

        return array_values(array_unique($adressen));
    }

    private static function imNetz(string $ip, string $cidr): bool
    {
        [$netz, $bits] = explode('/', $cidr);
        $a = inet_pton($ip);
        $b = inet_pton($netz);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int) $bits;
        $voll = intdiv($bits, 8);
        if (substr($a, 0, $voll) !== substr($b, 0, $voll)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $maske = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$voll]) & $maske) === (ord($b[$voll]) & $maske);
    }

    /** Location relativ zur aktuellen URL auflösen (absolut, //host, /pfad, pfad). */
    private static function absolut(string $basis, string $location): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location)) {
            return $location;
        }
        $b = parse_url($basis);
        $wurzel = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($location, '//')) {
            return $b['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $wurzel.$location;
        }
        $pfad = isset($b['path']) ? preg_replace('~/[^/]*$~', '/', $b['path']) : '/';

        return $wurzel.$pfad.$location;
    }

    private static function groesse(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576).' MB' : round($bytes / 1024).' KB';
    }
}
