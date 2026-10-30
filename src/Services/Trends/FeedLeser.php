<?php

namespace Platform\FoodAlchemist\Services\Trends;

/**
 * Spec 93 A · Liest Quellen deterministisch, ohne KI und ohne Netz (den Abruf macht SichererAbruf):
 *
 * - RSS 2.0 / Atom → Titel, Link, Datum, Teaser (Tags entfernt, gekürzt)
 * - HTML → Feed-Links aus <link rel="alternate" type="application/rss+xml|atom+xml">
 * - HTML einer Rubrik-/Übersichtsseite → Artikel-Links: <a> an einer Überschrift (h1–h4) oder als erster Titel-Link
 *   in einem article-/teaser-Container; nur dieselbe Domain, nicht die Übersicht selbst; Navigation, Kopf, Fuß,
 *   Seitenleiste sowie Kategorie-, Tag-, Autor- und Blätter-Links fallen weg.
 *
 * Gespeichert wird später nur Titel, Link, Datum und ein gekürzter Teaser — nie der Volltext.
 */
class FeedLeser
{
    /** Pfadteile, die auf Rubrik-, Schlagwort-, Autor- oder Blätter-Seiten zeigen, nicht auf Artikel. */
    private const KEINE_ARTIKEL = ['tag', 'tags', 'schlagwort', 'schlagworte', 'kategorie', 'kategorien', 'category', 'categories',
        'rubrik', 'thema', 'themen', 'autor', 'autoren', 'author', 'authors', 'page', 'seite', 'suche', 'search', 'feed', 'rss',
        'impressum', 'datenschutz', 'kontakt', 'login', 'newsletter', 'abo', 'shop'];

    /** Container, deren Links nie Artikel sind. */
    private const RAUSWERFEN = ['nav', 'header', 'footer', 'aside', 'script', 'style', 'noscript', 'form', 'template', 'svg'];

    /** Titel kürzer als das sind „Mehr“, „Weiterlesen“, Pfeile — keine Artikeltitel. */
    private const TITEL_MIN = 12;

    /** Erkennt, ob ein Text ein RSS- oder Atom-Feed ist: 'rss' | 'atom' | null. */
    public function feedTyp(string $inhalt): ?string
    {
        $kopf = strtolower(substr(ltrim($inhalt), 0, 2000));
        if (str_contains($kopf, '<rss') || str_contains($kopf, '<rdf:rdf')) {
            return 'rss';
        }
        if (preg_match('/<feed[\s>]/', $kopf) === 1) {
            return 'atom';
        }

        return null;
    }

    /**
     * @return list<array{titel:string, url:string, veroeffentlicht_am:?string, teaser:?string}>
     */
    public function feed(string $xml, string $basisUrl): array
    {
        $vorher = libxml_use_internal_errors(true);
        // LIBXML_NONET: kein Nachladen aus dem Netz; ohne LIBXML_NOENT werden keine Entities ersetzt (kein XXE)
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        if ($doc === false) {
            throw new \RuntimeException('Der Feed ist kein gültiges XML.');
        }
        $eintraege = [];
        $name = strtolower($doc->getName());
        if ($name === 'rss' || $name === 'rdf') {
            $items = $name === 'rss' ? ($doc->channel->item ?? []) : ($doc->item ?? []);
            foreach ($items as $item) {
                $dc = $item->children('http://purl.org/dc/elements/1.1/');
                $eintraege[] = $this->eintrag((string) $item->title, trim((string) $item->link) ?: trim((string) $item->guid),
                    (string) ($item->pubDate ?: $dc->date), (string) $item->description, $basisUrl);
            }
        } elseif ($name === 'feed') {
            foreach ($doc->entry ?? [] as $entry) {
                $link = '';
                foreach ($entry->link as $l) {
                    $rel = (string) ($l['rel'] ?? 'alternate');
                    if ($rel === 'alternate' || $link === '') {
                        $link = (string) $l['href'];
                    }
                }
                $eintraege[] = $this->eintrag((string) $entry->title, $link, (string) ($entry->published ?: $entry->updated),
                    (string) ($entry->summary ?: $entry->content), $basisUrl);
            }
        } else {
            throw new \RuntimeException('Weder RSS noch Atom.');
        }

        return array_values(array_filter($eintraege));
    }

    /** @return list<array{url:string, typ:string}> Feed-Links aus <link rel="alternate">, absolut */
    public function feedLinks(string $html, string $basisUrl): array
    {
        $dom = $this->dom($html);
        $out = [];
        foreach ($dom->getElementsByTagName('link') as $l) {
            $rel = strtolower((string) $l->getAttribute('rel'));
            $typ = strtolower((string) $l->getAttribute('type'));
            $href = trim((string) $l->getAttribute('href'));
            if ($href === '' || ! str_contains(" $rel ", ' alternate ')) {
                continue;
            }
            if ($typ === 'application/rss+xml' || $typ === 'application/atom+xml') {
                $out[] = ['url' => $this->absolut($href, $basisUrl), 'typ' => str_contains($typ, 'atom') ? 'atom' : 'rss'];
            }
        }

        return $out;
    }

    /**
     * Artikel einer Übersichtsseite, in Seitenreihenfolge, ohne Dubletten.
     *
     * @return list<array{titel:string, url:string, veroeffentlicht_am:?string, teaser:?string}>
     */
    public function uebersicht(string $html, string $basisUrl): array
    {
        $dom = $this->dom($html);
        foreach (self::RAUSWERFEN as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $knoten) {
                $knoten->parentNode?->removeChild($knoten);
            }
        }
        $xp = new \DOMXPath($dom);
        $basis = parse_url($basisUrl);
        $basisHost = $this->hostOhneWww((string) ($basis['host'] ?? ''));
        $basisPfad = rtrim((string) ($basis['path'] ?? ''), '/');

        // Kandidaten: Link in/um einer Überschrift, sonst erster Link in einem Artikel-/Teaser-Container
        $kandidaten = [];
        foreach ($xp->query('//h1//a[@href] | //h2//a[@href] | //h3//a[@href] | //h4//a[@href] | //a[@href][.//h1 or .//h2 or .//h3 or .//h4]') as $a) {
            $kandidaten[$a->getNodePath()] = true;
        }
        $container = "//article | //*[contains(@class, 'teaser') or contains(@class, 'article') or contains(@class, 'post-')]";
        foreach ($xp->query($container) as $c) {
            foreach ($xp->query('.//a[@href]', $c) as $a) {
                if (mb_strlen($this->text($a)) >= self::TITEL_MIN) {
                    $kandidaten[$a->getNodePath()] = true;
                    break;
                }
            }
        }

        $gesehen = [];
        $treffer = [];
        // In Dokumentreihenfolge: die Seite bestimmt die Reihenfolge
        $inReihe = [];
        foreach ($xp->query('//a[@href]') as $a) {
            if (isset($kandidaten[$a->getNodePath()])) {
                $inReihe[] = $a;
            }
        }
        foreach ($inReihe as $a) {
            $url = $this->absolut(trim((string) $a->getAttribute('href')), $basisUrl);
            $t = parse_url($url);
            if (! in_array(strtolower((string) ($t['scheme'] ?? '')), ['http', 'https'], true)) {
                continue;
            }
            if ($this->hostOhneWww((string) ($t['host'] ?? '')) !== $basisHost) {
                continue;
            }
            $pfad = rtrim((string) ($t['path'] ?? ''), '/');
            if ($pfad === '' || $pfad === $basisPfad || ! $this->istArtikelPfad($pfad, $t['query'] ?? null)) {
                continue;
            }
            $schluessel = strtolower($url);
            if (isset($gesehen[$schluessel])) {
                continue;
            }
            $titel = $this->text($a);
            if (mb_strlen($titel) < self::TITEL_MIN) {
                continue;
            }
            $gesehen[$schluessel] = true;
            $huelle = $this->huelle($a);
            $treffer[] = [
                'titel' => mb_substr($titel, 0, 500),
                'url' => $url,
                'veroeffentlicht_am' => $huelle ? $this->datumIn($xp, $huelle) : null,
                'teaser' => $huelle ? $this->teaserIn($xp, $huelle, $titel) : null,
            ];
        }

        return $treffer;
    }

    // ── intern ─────────────────────────────────────────────────────────────

    /** @return array{titel:string, url:string, veroeffentlicht_am:?string, teaser:?string}|null */
    private function eintrag(string $titel, string $link, string $datum, string $teaser, string $basisUrl): ?array
    {
        $titel = $this->saeubern($titel);
        $link = trim($link);
        if ($titel === '' || $link === '') {
            return null;
        }

        return [
            'titel' => mb_substr($titel, 0, 500),
            'url' => $this->absolut($link, $basisUrl),
            'veroeffentlicht_am' => $this->datum($datum),
            'teaser' => $this->kuerzen($this->saeubern($teaser)),
        ];
    }

    private function istArtikelPfad(string $pfad, ?string $query): bool
    {
        if ($query !== null && preg_match('/(^|&)(page|p|seite|paged)=\d+/i', $query)) {
            return false;
        }
        foreach (explode('/', trim($pfad, '/')) as $teil) {
            if (in_array(strtolower($teil), self::KEINE_ARTIKEL, true)) {
                return false;
            }
        }

        return ! preg_match('~/(page|seite)/\d+$~i', $pfad);
    }

    /** Nächster Artikel-Container um den Link (article, li, Teaser-div), höchstens 5 Ebenen nach oben. */
    private function huelle(\DOMElement $a): ?\DOMElement
    {
        $k = $a->parentNode;
        for ($i = 0; $i < 5 && $k instanceof \DOMElement; $i++, $k = $k->parentNode) {
            $klasse = strtolower((string) $k->getAttribute('class'));
            if (in_array(strtolower($k->nodeName), ['article', 'li'], true) || str_contains($klasse, 'teaser') || str_contains($klasse, 'article') || str_contains($klasse, 'post')) {
                return $k;
            }
        }

        return null;
    }

    private function datumIn(\DOMXPath $xp, \DOMElement $huelle): ?string
    {
        foreach ($xp->query('.//time', $huelle) as $t) {
            $d = $this->datum((string) ($t->getAttribute('datetime') ?: $t->textContent));
            if ($d !== null) {
                return $d;
            }
        }

        return null;
    }

    private function teaserIn(\DOMXPath $xp, \DOMElement $huelle, string $titel): ?string
    {
        foreach ($xp->query('.//p', $huelle) as $p) {
            $t = $this->text($p);
            if ($t !== '' && $t !== $titel) {
                return $this->kuerzen($t);
            }
        }

        return null;
    }

    private function datum(string $roh): ?string
    {
        $roh = trim($roh);
        if ($roh === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($roh)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $vorher = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);

        return $dom;
    }

    private function text(\DOMNode $n): string
    {
        return trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '');
    }

    private function saeubern(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    private function kuerzen(string $s): ?string
    {
        if ($s === '') {
            return null;
        }
        $max = (int) config('foodalchemist.trends.quellen.teaser_max', 600);

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)).'…' : $s;
    }

    private function hostOhneWww(string $host): string
    {
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public function absolut(string $href, string $basis): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
            return $href;
        }
        $b = parse_url($basis);
        $schema = $b['scheme'] ?? 'https';
        $wurzel = $schema.'://'.($b['host'] ?? '').(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $schema.':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $wurzel.$href;
        }
        if (str_starts_with($href, '#') || $href === '') {
            return $basis;
        }
        $pfad = isset($b['path']) ? preg_replace('~/[^/]*$~', '/', $b['path']) : '/';

        return $wurzel.$pfad.$href;
    }
}
