<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistMarktdaten as Daten;
use Platform\FoodAlchemist\Services\Trends\GenesisClient;
use Platform\FoodAlchemist\Services\Trends\GenesisCsvLeser;

class MarktdatenService
{
    /**
     * Feste Destatis-Reihen. Je Reihe: GENESIS-Tabelle, Klassifikationsmerkmal + Schlüssel, Wertvariable (Teil des Labels)
     * und optional weitere Pflicht-Schlüssel (z. B. „Originalwert“ statt „bereinigt“), damit je Monat genau EIN Wert bleibt.
     *
     * Reihen mit `merkmal = null` sind freigegeben, aber noch nicht abrufbar: ihre Codes werden erst über
     * `erkunden()` (marktdaten.ABRUF mit erkunden_tabelle) auf io nachgeschlagen und dann hier eingetragen — nie geraten.
     * `ersetzt_durch`: Reihe wird nur gezeigt, solange die Nachfolgerin noch keinen Wert hat (keine Lücke beim Umstieg).
     * `gruppe`: Unterzeile einer anderen Reihe (eigene Karte entfällt).
     */
    public const REIHEN = [
        // Codes aus dem Erkunden auf io (05.11.): 61281-0004 Merkmal WZ08P9, Wertvariable PRE011.
        'grosshandel_nahrungsmittel' => ['name' => 'Großhandel Nahrungs- und Genussmittel', 'lang' => 'Großhandelsverkaufspreise: Nahrungs- und Genussmittel, Getränke, Tabakwaren (WZ 46.3)',
            'tabelle' => '61281-0004', 'merkmal' => 'WZ08P9', 'schluessel' => 'WZ08-463', 'wert_code' => 'PRE011'],
        'grosshandel_fleisch' => ['name' => 'Fleisch und Fleischwaren', 'lang' => 'Großhandelsverkaufspreise: Fleisch und Fleischwaren (WZ 46.32)', 'gruppe' => 'grosshandel_nahrungsmittel',
            'tabelle' => '61281-0004', 'merkmal' => 'WZ08P9', 'schluessel' => 'WZ08-4632', 'wert_code' => 'PRE011'],
        'grosshandel_milch' => ['name' => 'Milch, Eier, Speisefette', 'lang' => 'Großhandelsverkaufspreise: Milcherzeugnisse, Eier, Speiseöle und -fette (WZ 46.33)', 'gruppe' => 'grosshandel_nahrungsmittel',
            'tabelle' => '61281-0004', 'merkmal' => 'WZ08P9', 'schluessel' => 'WZ08-4633', 'wert_code' => 'PRE011'],
        'grosshandel_obst_gemuese' => ['name' => 'Obst, Gemüse, Kartoffeln', 'lang' => 'Großhandelsverkaufspreise: Obst, Gemüse und Kartoffeln (WZ 46.31)', 'gruppe' => 'grosshandel_nahrungsmittel',
            'tabelle' => '61281-0004', 'merkmal' => 'WZ08P9', 'schluessel' => 'WZ08-4631', 'wert_code' => 'PRE011'],
        'grosshandel_fisch' => ['name' => 'Fisch, Fischerzeugnisse', 'lang' => 'Großhandelsverkaufspreise: Fisch und Fischerzeugnisse (WZ 46.381)', 'gruppe' => 'grosshandel_nahrungsmittel',
            'tabelle' => '61281-0004', 'merkmal' => 'WZ08P9', 'schluessel' => 'WZ08-46381', 'wert_code' => 'PRE011'],
        'grosshandel_getreide' => ['name' => 'Mehl, Getreideprodukte', 'lang' => 'Großhandelsverkaufspreise: Mehl und Getreideprodukte (WZ 46.382)', 'gruppe' => 'grosshandel_nahrungsmittel',
            'tabelle' => '61281-0004', 'merkmal' => 'WZ08P9', 'schluessel' => 'WZ08-46382', 'wert_code' => 'PRE011'],
        'grosshandel_zucker_backwaren' => ['name' => 'Zucker, Süß- und Backwaren', 'lang' => 'Großhandelsverkaufspreise: Zucker, Süßwaren und Backwaren (WZ 46.36)', 'gruppe' => 'grosshandel_nahrungsmittel',
            'tabelle' => '61281-0004', 'merkmal' => 'WZ08P9', 'schluessel' => 'WZ08-4636', 'wert_code' => 'PRE011'],
        'grosshandel_kaffee_gewuerze' => ['name' => 'Kaffee, Tee, Kakao, Gewürze', 'lang' => 'Großhandelsverkaufspreise: Kaffee, Tee, Kakao und Gewürze (WZ 46.37)', 'gruppe' => 'grosshandel_nahrungsmittel',
            'tabelle' => '61281-0004', 'merkmal' => 'WZ08P9', 'schluessel' => 'WZ08-4637', 'wert_code' => 'PRE011'],
        'grosshandel_getraenke' => ['name' => 'Getränke', 'lang' => 'Großhandelsverkaufspreise: Getränke (WZ 46.34)', 'gruppe' => 'grosshandel_nahrungsmittel',
            'tabelle' => '61281-0004', 'merkmal' => 'WZ08P9', 'schluessel' => 'WZ08-4634', 'wert_code' => 'PRE011'],
        'grosshandel_tk' => ['name' => 'TK-Nahrungsmittel', 'lang' => 'Großhandelsverkaufspreise: Tiefgefrorene Nahrungsmittel (WZ 46.391)', 'gruppe' => 'grosshandel_nahrungsmittel',
            'tabelle' => '61281-0004', 'merkmal' => 'WZ08P9', 'schluessel' => 'WZ08-46391', 'wert_code' => 'PRE011'],
        // Verbraucherpreise Fleisch (61111-0004, COICOP-5-Steller): klappbare Detailzeilen in der Großhandel-Karte, klar als Ladenpreise beschriftet.
        'vpi_rind' => ['name' => 'Rind- und Kalbfleisch', 'lang' => 'Verbraucherpreisindex: Rind- und Kalbfleisch', 'gruppe' => 'grosshandel_nahrungsmittel', 'abschnitt' => 'verbraucher',
            'tabelle' => '61111-0004', 'merkmal' => 'CC13A5', 'schluessel' => 'CC13-01121', 'wert_code' => 'PREIS1'],
        'vpi_schwein' => ['name' => 'Schweinefleisch', 'lang' => 'Verbraucherpreisindex: Schweinefleisch', 'gruppe' => 'grosshandel_nahrungsmittel', 'abschnitt' => 'verbraucher',
            'tabelle' => '61111-0004', 'merkmal' => 'CC13A5', 'schluessel' => 'CC13-01122', 'wert_code' => 'PREIS1'],
        'vpi_gefluegel' => ['name' => 'Geflügelfleisch', 'lang' => 'Verbraucherpreisindex: Geflügelfleisch', 'gruppe' => 'grosshandel_nahrungsmittel', 'abschnitt' => 'verbraucher',
            'tabelle' => '61111-0004', 'merkmal' => 'CC13A5', 'schluessel' => 'CC13-01124', 'wert_code' => 'PREIS1'],
        'vpi_fleischwaren' => ['name' => 'Fleisch- und Wurstwaren', 'lang' => 'Verbraucherpreisindex: Fleisch- und Wurstwaren', 'gruppe' => 'grosshandel_nahrungsmittel', 'abschnitt' => 'verbraucher',
            'tabelle' => '61111-0004', 'merkmal' => 'CC13A5', 'schluessel' => 'CC13-01127', 'wert_code' => 'PREIS1'],
        'lebensmittelpreise' => ['name' => 'Lebensmittel', 'lang' => 'Nahrungsmittel & alkoholfreie Getränke (Verbraucherpreisindex)', 'ersetzt_durch' => 'grosshandel_nahrungsmittel',
            'tabelle' => '61111-0004', 'merkmal' => 'CC13A5', 'schluessel' => 'CC13-01', 'wert' => 'verbraucherpreisindex'],
        'gaststaettenpreise' => ['name' => 'Restaurantpreise', 'lang' => 'Gaststättendienstleistungen (Verbraucherpreisindex)',
            'tabelle' => '61111-0004', 'merkmal' => 'CC13A5', 'schluessel' => 'CC13-111', 'wert' => 'verbraucherpreisindex'],
        'kantinenpreise' => ['name' => 'Kantinen und Mensen', 'lang' => 'Bewirtungsleistungen von Kantinen und Mensen (Verbraucherpreisindex)',
            'tabelle' => '61111-0004', 'merkmal' => 'CC13A5', 'schluessel' => 'CC13-11120', 'wert_code' => 'PREIS1'],
        // 45213-0005: Wertvariable UMS103; Preisbasis (WERTE4: REAL/NOMINAL) und Bereinigung (WERT01: WERTORG = Originalwert)
        // stehen als weitere Merkmale in der Zeile — Pflicht-Schlüssel, damit je Monat genau ein Wert bleibt.
        // Hauptreihe Gastronomie (WZ 56) statt Gastgewerbe (55–56); Unterzeilen Caterer (56.2) und Event-Caterer (56.21).
        'gastro_umsatz_nominal' => ['name' => 'Gastronomie-Umsatz nominal', 'lang' => 'Umsatz in der Gastronomie (WZ 56), in jeweiligen Preisen, Originalwert',
            'tabelle' => '45213-0005', 'merkmal' => 'WZ08W9', 'schluessel' => 'WZ08-56', 'wert_code' => 'UMS103', 'zusatz' => ['NOMINAL', 'WERTORG']],
        'caterer_umsatz_nominal' => ['name' => 'Caterer und Verpflegungsdienste', 'lang' => 'Umsatz Caterer und sonstige Verpflegungsdienstleistungen (WZ 56.2), nominal', 'gruppe' => 'gastro_umsatz_nominal',
            'tabelle' => '45213-0005', 'merkmal' => 'WZ08W9', 'schluessel' => 'WZ08-562', 'wert_code' => 'UMS103', 'zusatz' => ['NOMINAL', 'WERTORG']],
        'eventcaterer_umsatz_nominal' => ['name' => 'Event-Caterer', 'lang' => 'Umsatz Event-Caterer (WZ 56.21), nominal', 'gruppe' => 'gastro_umsatz_nominal',
            'tabelle' => '45213-0005', 'merkmal' => 'WZ08W9', 'schluessel' => 'WZ08-5621', 'wert_code' => 'UMS103', 'zusatz' => ['NOMINAL', 'WERTORG']],
        'gastro_umsatz_real' => ['name' => 'Gastronomie-Umsatz real', 'lang' => 'Umsatz in der Gastronomie (WZ 56), preisbereinigt, Originalwert',
            'tabelle' => '45213-0005', 'merkmal' => 'WZ08W9', 'schluessel' => 'WZ08-56', 'wert_code' => 'UMS103', 'zusatz' => ['REAL', 'WERTORG']],
        'caterer_umsatz_real' => ['name' => 'Caterer und Verpflegungsdienste', 'lang' => 'Umsatz Caterer und sonstige Verpflegungsdienstleistungen (WZ 56.2), real', 'gruppe' => 'gastro_umsatz_real',
            'tabelle' => '45213-0005', 'merkmal' => 'WZ08W9', 'schluessel' => 'WZ08-562', 'wert_code' => 'UMS103', 'zusatz' => ['REAL', 'WERTORG']],
        'eventcaterer_umsatz_real' => ['name' => 'Event-Caterer', 'lang' => 'Umsatz Event-Caterer (WZ 56.21), real', 'gruppe' => 'gastro_umsatz_real',
            'tabelle' => '45213-0005', 'merkmal' => 'WZ08W9', 'schluessel' => 'WZ08-5621', 'wert_code' => 'UMS103', 'zusatz' => ['REAL', 'WERTORG']],
        // 61241-0004: Merkmal GP19M2 (nur 2-Steller), Wertvariable PRE001. GP19-10 umfasst auch Futtermittel.
        'erzeugerpreise_nahrungsmittel' => ['name' => 'Erzeugerpreise Nahrungsmittel', 'lang' => 'Erzeugerpreise gewerblicher Produkte: Nahrungs- und Futtermittel (GP 10)',
            'tabelle' => '61241-0004', 'merkmal' => 'GP19M2', 'schluessel' => 'GP19-10', 'wert_code' => 'PRE001'],
        // Strom Gewerbe: in 61241-0004 gibt es nur GP19-35 „Energieversorgung“ (enthält Gas) — bewusst NICHT als Strom geführt.
    ];

    /** Tabellen, die `erkunden()` nachschlagen darf: nur die konfigurierten, nie eine freie Eingabe. */
    public const TABELLEN = ['61111-0004', '61281-0004', '45213-0005', '61241-0004'];

    /** Mindestabstand in Prozentpunkten, ab dem eine Vergleichsaussage eine Richtung nennt; darunter „etwa gleich“. */
    public const AUSSAGE_SCHWELLE_PP = 0.5;

    public function __construct(private readonly FaRechte $rechte, private readonly GenesisClient $client, private readonly GenesisCsvLeser $leser) {}

    public static function quelle(string $tabelle): string
    {
        return 'https://genesis.destatis.de/datenbank/online/statistic/'.strtok($tabelle, '-').'/table/'.$tabelle;
    }

    public static function abrufbar(array $reihe): bool
    {
        return ($reihe['merkmal'] ?? null) !== null && ($reihe['schluessel'] ?? null) !== null;
    }

    public function lesen(Team $team): array
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Lesen, 'Marktdaten lesen');
        $daten = Daten::visibleToTeam($team)->orderByDesc('abgerufen_at')->get();
        $alle = [];
        foreach (self::REIHEN as $serie => $meta) {
            // Eigener Snapshot hat Vorrang; geerbte Daten bleiben lesbar, niemals überschreibbar.
            $d = $daten->first(fn ($d) => $d->serie === $serie && (int) $d->team_id === (int) $team->id)
                ?? $daten->firstWhere('serie', $serie);
            $alle[$serie] = $this->reihe($serie, $meta, $d);
        }
        $sichtbar = [];
        foreach ($alle as $serie => $r) {
            $nachfolger = $r['ersetzt_durch'] ?? null;
            if (isset($r['gruppe']) || ($nachfolger !== null && ($alle[$nachfolger]['aktuell']['wert'] ?? null) !== null)) {
                continue;
            }
            // Reihen ohne Daten erscheinen nicht als leere Karte (Abruffehler stehen im ABRUF-Ergebnis).
            if ($r['aktuell'] === null) {
                continue;
            }
            $r['unterreihen'] = array_values(array_filter($alle, fn ($u) => ($u['gruppe'] ?? null) === $serie && $u['aktuell'] !== null));
            $mitVergleich = array_filter($r['unterreihen'], fn ($u) => $u['vorjahr_prozent'] !== null);
            usort($mitVergleich, fn ($x, $y) => abs($y['vorjahr_prozent']) <=> abs($x['vorjahr_prozent']));
            $r['staerkste_unterreihe'] = $mitVergleich[0] ?? null;
            $sichtbar[] = $r;
        }
        $aussagen = self::aussagen($alle);

        return ['zugang_eingerichtet' => $this->client->eingerichtet(), 'reihen' => $sichtbar, 'aussagen' => $aussagen,
            'aussage' => $aussagen[0] ?? null,
            'hinweis' => 'Amtliche Indizes für Deutschland: Sie zeigen die Entwicklung, keine Euro-Beträge und keine Einkaufs- oder Betriebskosten des eigenen Betriebs. Vorjahresvergleich nur bei gleicher Indexbasis; Original-Kennzeichnungen beachten.'];
    }

    private function reihe(string $serie, array $meta, ?Daten $d): array
    {
        $punkte = $d?->punkte ?? [];
        $bekannte = array_values(array_filter($punkte, fn ($p) => $p['wert'] !== null));
        $aktuell = $bekannte !== [] ? $bekannte[array_key_last($bekannte)] : ($punkte === [] ? null : $punkte[array_key_last($punkte)]);
        $offeneMonate = $aktuell === null ? 0 : count(array_filter($punkte, fn ($p) => $p['monat'] > $aktuell['monat'] && $p['wert'] === null));
        $vorjahr = $aktuell ? collect($punkte)->firstWhere('monat', ((int) substr($aktuell['monat'], 0, 4) - 1).substr($aktuell['monat'], 4)) : null;
        // Indexbasis-Wechsel innerhalb der Reihe = kein Vergleich (sonst wäre ein Basissprung „Wachstum“).
        $vergleich = $aktuell !== null && $vorjahr !== null && $aktuell['wert'] !== null && $vorjahr['wert'] > 0
            && $aktuell['einheit'] === $vorjahr['einheit']
            ? round(($aktuell['wert'] / $vorjahr['wert'] - 1) * 100, 1) : null;

        return $meta + ['serie' => $serie, 'abrufbar' => self::abrufbar($meta), 'aktuell' => $aktuell, 'neuere_monate_ohne_wert' => $offeneMonate,
            'vorjahr_prozent' => $vergleich, 'punkte' => $punkte,
            // Anzeige-Tabelle: nur Monate mit Wert, neueste zuerst. Zukunft filtert schon der CSV-Leser.
            'tabelle_werte' => array_reverse(array_values(array_map(fn ($p) => ['monat' => $p['monat'], 'wert' => $p['wert'], 'einheit' => $p['einheit']],
                array_filter($punkte, fn ($p) => $p['wert'] !== null)))),
            'abgerufen_am' => $d?->abgerufen_at?->toIso8601String(), 'versucht_am' => $d?->versucht_at?->toIso8601String(),
            'fehler' => $d?->letzter_fehler, 'quelle' => self::quelle($meta['tabelle']), 'team_id' => $d?->team_id,
            'veraltet' => $aktuell !== null && $aktuell['monat'] < now()->subMonthsNoOverflow(3)->format('Y-m')];
    }

    /**
     * Klartext-Aussagen NUR aus den abgerufenen Werten, in fester Reihenfolge. Jede braucht für beide Reihen einen
     * Vorjahresvergleich (der fällt bei Basiswechsel innerhalb einer Reihe schon weg) und denselben Bezugsmonat.
     * Zwei Reihen derselben Tabelle müssen zusätzlich dieselbe Indexbasis haben. Vorjahres-Prozente verschiedener
     * Indizes sind basisunabhängig vergleichbar; deshalb über Tabellen hinweg kein Basis-Abgleich.
     *
     * @return list<array{schluessel:string, text:string, monat:string, quellen:list<string>}>
     */
    public static function aussagen(array $reihen): array
    {
        $r = collect($reihen)->keyBy('serie');
        $out = [];
        if ($a = self::paar($r->get('grosshandel_nahrungsmittel'), $r->get('gaststaettenpreise'))) {
            [$e, $v] = $a;
            $out[] = self::satz('kalkulation', [$r['grosshandel_nahrungsmittel'], $r['gaststaettenpreise']],
                'Großhandel Nahrungsmittel '.self::pct($e).', Restaurantpreise '.self::pct($v).' zum Vorjahresmonat: '
                .self::richtung($e, $v, 'Die Großhandelspreise', 'die Restaurantpreise',
                    ' – der Einkauf verteuert sich schneller, die Kalkulation gerät unter Druck.', ' – mehr Spielraum in der Kalkulation.'));
        } elseif ($a = self::paar($r->get('gaststaettenpreise'), $r->get('lebensmittelpreise'))) {
            [$g, $l] = $a;
            $out[] = self::satz('kalkulation', [$r['gaststaettenpreise'], $r['lebensmittelpreise']],
                'Restaurantpreise '.self::pct($g).', Lebensmittel '.self::pct($l).' zum Vorjahresmonat: '
                .self::richtung($g, $l, 'Die Restaurantpreise', 'die Lebensmittelpreise im Handel', '.', '.'));
        }
        // Gemeinschaftsverpflegung: Kantinenpreise gegen den Großhandel (Einkauf), sonst gegen die Restaurantpreise.
        if ($a = self::paar($r->get('grosshandel_nahrungsmittel'), $r->get('kantinenpreise'))) {
            [$e, $k] = $a;
            $out[] = self::satz('kantine', [$r['grosshandel_nahrungsmittel'], $r['kantinenpreise']],
                'Großhandel Nahrungsmittel '.self::pct($e).', Kantinen und Mensen '.self::pct($k).' zum Vorjahresmonat: '
                .self::richtung($e, $k, 'Die Großhandelspreise', 'die Kantinenpreise',
                    ' – in der Gemeinschaftsverpflegung wird die Kalkulation enger.', ' – die Kantinenpreise ziehen dem Einkauf voraus.'));
        } elseif ($a = self::paar($r->get('kantinenpreise'), $r->get('gaststaettenpreise'))) {
            [$k, $g] = $a;
            $out[] = self::satz('kantine', [$r['kantinenpreise'], $r['gaststaettenpreise']],
                'Kantinen und Mensen '.self::pct($k).', Restaurantpreise '.self::pct($g).' zum Vorjahresmonat: '
                .self::richtung($k, $g, 'Die Kantinenpreise', 'die Restaurantpreise', '.', '.'));
        }
        // Umsatz: zuerst die Caterer-Zeile (BHG-Kerngeschäft), sonst Gastronomie gesamt.
        foreach (['caterer' => 'Umsatz Caterer', 'gastro' => 'Gastronomie-Umsatz'] as $pref => $etikett) {
            if (! ($a = self::paar($r->get($pref.'_umsatz_nominal'), $r->get($pref.'_umsatz_real')))) {
                continue;
            }
            [$n, $re] = $a;
            $folge = match (true) {
                $n > 0 && $re <= 0 => 'Das Plus kommt aus höheren Preisen, nicht aus mehr Gästen oder Absatz.',
                $n > 0 && $re > 0 => 'Auch preisbereinigt wird mehr umgesetzt.',
                $n <= 0 && $re <= 0 => 'Die Branche setzt auch in Euro weniger um.',
                default => 'Preisbereinigt wird mehr umgesetzt, in Euro nicht.',
            };
            $out[] = self::satz('umsatz', [$r[$pref.'_umsatz_nominal'], $r[$pref.'_umsatz_real']],
                $etikett.' nominal '.self::pct($n).', real '.self::pct($re).' zum Vorjahresmonat: '.$folge);
            break;
        }
        if ($a = self::paar($r->get('erzeugerpreise_nahrungsmittel'), $r->get('grosshandel_nahrungsmittel'))) {
            [$h, $gh] = $a;
            $out[] = self::satz('vorlauf', [$r['erzeugerpreise_nahrungsmittel'], $r['grosshandel_nahrungsmittel']],
                'Erzeugerpreise Nahrungsmittel '.self::pct($h).', Großhandel '.self::pct($gh).' zum Vorjahresmonat: '
                .self::richtung($h, $gh, 'Bei den Herstellern', 'im Großhandel', ' – Druck auf künftige Einkaufspreise möglich.', '.', true));
        }
        return $out;
    }

    /** Rückwärtskompatibel: erste Aussage (Kalkulation) oder null. */
    public static function aussage(array $reihen): ?array
    {
        return self::aussagen($reihen)[0] ?? null;
    }

    /** @return array{0: float, 1: float}|null */
    private static function paar(?array $a, ?array $b): ?array
    {
        if (! $a || ! $b || $a['vorjahr_prozent'] === null || $b['vorjahr_prozent'] === null
            || ($a['aktuell']['monat'] ?? null) !== ($b['aktuell']['monat'] ?? null)) {
            return null;
        }
        if ($a['tabelle'] === $b['tabelle'] && $a['aktuell']['einheit'] !== $b['aktuell']['einheit']) {
            return null;
        }

        return [(float) $a['vorjahr_prozent'], (float) $b['vorjahr_prozent']];
    }

    /** „X steigt stärker als Y“ bzw. „sinkt weniger stark“, „etwa gleich“ unter der Schwelle. */
    private static function richtung(float $a, float $b, string $nameA, string $nameB, string $folgeA, string $folgeB, bool $ortsangabe = false): string
    {
        $abstand = round(abs($a - $b), 1);
        $pp = ' (Abstand '.number_format($abstand, 1, ',', '.').' Prozentpunkte)';
        if ($abstand < self::AUSSAGE_SCHWELLE_PP) {
            return 'Beide entwickeln sich etwa gleich'.$pp.'.';
        }
        [$hoch, $tief, $folge] = $a > $b ? [$nameA, $nameB, $folgeA] : [ucfirst($nameB), lcfirst($nameA), $folgeB];
        // Ortsangaben („Bei den Herstellern …“) brauchen ein eigenes Subjekt: „… steigen die Preise stärker als im Großhandel“.
        $preise = $ortsangabe ? ' die Preise' : '';
        $verb = max($a, $b) > 0 ? 'steigen'.$preise.' stärker als' : 'sinken'.$preise.' weniger stark als';

        return $hoch.' '.$verb.' '.$tief.$pp.rtrim($folge, '.').'.';
    }

    private static function pct(float $v): string
    {
        return ($v > 0 ? '+' : '').number_format($v, 1, ',', '.').' %';
    }

    private static function satz(string $schluessel, array $reihen, string $text): array
    {
        return ['schluessel' => $schluessel, 'text' => $text, 'monat' => $reihen[0]['aktuell']['monat'],
            'quellen' => array_values(array_unique(array_map(fn ($r) => $r['tabelle'], $reihen)))];
    }

    /**
     * Nur feste, abrufbare Reihen, aktuelles Team; kein Hintergrundabruf aus einem Render-/GET-Pfad.
     * $reihen: nur diese Schlüssel (Teil-Abruf, damit ein MCP-Aufruf nicht in den Client-Timeout läuft); unbekannte → Fehler.
     *
     * @param  list<string>|null  $reihen
     */
    public function abrufen(Team $team, bool $apply = false, ?array $reihen = null): array
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Marktdaten abrufen');
        if ($reihen !== null && ($unbekannt = array_diff($reihen, array_keys(self::REIHEN))) !== []) {
            throw new \RuntimeException('Unbekannte Reihen: '.implode(', ', $unbekannt).'. Erlaubt: '.implode(', ', array_keys(self::REIHEN)).'.');
        }
        $out = [];
        $offen = [];
        foreach (self::REIHEN as $serie => $meta) {
            if ($reihen !== null && ! in_array($serie, $reihen, true)) {
                continue;
            }
            if (! self::abrufbar($meta)) {
                $offen[] = ['serie' => $serie, 'tabelle' => $meta['tabelle'], 'grund' => 'Merkmal und Schlüssel fehlen – erst per erkunden_tabelle nachschlagen.'];

                continue;
            }
            try {
                $punkte = $this->leser->lesen($this->client->laden($meta['tabelle'], $meta['merkmal'], $meta['schluessel']),
                    $meta['schluessel'], $meta['wert'] ?? '', $meta['zusatz'] ?? [], $meta['wert_code'] ?? null);
                if ($apply) {
                    Daten::updateOrCreate(['team_id' => $team->id, 'serie' => $serie], [
                        'punkte' => $punkte, 'abgerufen_at' => now(), 'versucht_at' => now(), 'letzter_fehler' => null,
                    ]);
                }
                $bekannte = array_values(array_filter($punkte, fn ($p) => $p['wert'] !== null));
                $out[] = ['serie' => $serie, 'tabelle' => $meta['tabelle'], 'monate' => count($punkte), 'monate_mit_wert' => count($bekannte),
                    'letzter_monat' => $punkte[array_key_last($punkte)]['monat'],
                    'letzter_wert_monat' => $bekannte === [] ? null : $bekannte[array_key_last($bekannte)]['monat'],
                    'einheit' => $punkte[array_key_last($punkte)]['einheit'], 'fehler' => null];
            } catch (\RuntimeException $e) {
                if ($apply) {
                    Daten::updateOrCreate(['team_id' => $team->id, 'serie' => $serie], [
                        'versucht_at' => now(), 'letzter_fehler' => mb_substr($e->getMessage(), 0, 500),
                    ]);
                }
                $out[] = ['serie' => $serie, 'tabelle' => $meta['tabelle'], 'monate' => 0, 'fehler' => $e->getMessage()];
            }
        }

        return ['gespeichert' => $apply, 'probelauf' => ! $apply, 'ergebnisse' => $out, 'noch_nicht_abrufbar' => $offen];
    }

    /**
     * Nachschlagen statt raten: Aufbau einer konfigurierten Tabelle (Merkmale) bzw. die Schlüssel eines Merkmals,
     * gefiltert nach Suchwort. Schreibt nichts. Nur Tabellen aus TABELLEN; Merkmal ist ein GENESIS-Code.
     */
    public function erkunden(Team $team, string $tabelle, ?string $merkmal = null, ?string $suche = null): array
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Marktdaten erkunden');
        if (! in_array($tabelle, self::TABELLEN, true)) {
            throw new \RuntimeException('Nur konfigurierte Tabellen: '.implode(', ', self::TABELLEN).'.');
        }
        if ($merkmal === null) {
            return ['tabelle' => $tabelle, 'aufbau' => $this->client->tabellenAufbau($tabelle)];
        }
        if (! preg_match('/^[A-Z0-9]{2,12}$/', $merkmal)) {
            throw new \RuntimeException('Merkmal muss ein GENESIS-Code sein (Großbuchstaben/Ziffern).');
        }
        $werte = $this->client->merkmalWerte($merkmal);
        $suche = mb_strtolower(trim((string) $suche));
        if ($suche !== '') {
            $werte = array_values(array_filter($werte, fn ($w) => str_contains(mb_strtolower($w['code'].' '.$w['inhalt']), $suche)));
        }

        return ['tabelle' => $tabelle, 'merkmal' => $merkmal, 'suche' => $suche ?: null, 'anzahl' => count($werte), 'werte' => array_slice($werte, 0, 60)];
    }
}
