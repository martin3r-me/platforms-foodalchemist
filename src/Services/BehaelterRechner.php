<?php

namespace Platform\FoodAlchemist\Services;

/**
 * Spec 51 — Bedarfs-Primitive für Behälter: rechnet die PRODUZIERTE MENGE (kg) in ganze
 * Behälter um und bietet Alternativen an, statt eine auszuwählen.
 *
 * Zwilling von {@see GebindeRechner}: pure, kein DB-Zugriff, kein State, Behälter werden
 * duck-typed erwartet (laenge_mm, breite_mm, tiefe_mm, volumen_l, nutzfaktor,
 * max_fuellgewicht_kg, kapazitaet_kg, name, eignung). Damit mit Plain-Objekten testbar.
 *
 * Der Anlass aus der Küche: »bei 2 kg reicht ein kleiner Einsatz, bei 10 nehme ich zwei flache«.
 * Eine gerechnete ANZAHL zu einem fest gewählten Typ trifft das nicht — die Menge wählt auch die
 * GRÖSSE. Und weil »flach statt tief« von Standzeit, Gerät und Buffet abhängt, entscheidet das
 * der Mensch: dieser Rechner liefert eine Basis plus Alternativen und wertet nicht.
 *
 * Basis-Rangfolge (Muster: RecipeRecomputeService::grammFaktor(), Allergen-Konfidenz):
 *   1 referenz_menge_kg am Rezept   → »hoch«    — kommt aus der Küche, enthält die Dichte
 *   2 dichteklasse × Nutzvolumen    → »mittel«  — KI-vorschlagbar, grob aber sofort da
 *   3 (Warengruppen-Default)        → »niedrig« — vom Aufrufer als dichteklasse durchgereicht
 *   4 nichts                        → nicht berechenbar, MIT GRUND
 *
 * Was dieser Rechner NICHT tut: raten. Fehlt die Masse (yield_kg NULL), fehlen Referenz und
 * Dichteklasse, oder hat der Behälter weder Maße noch Nennvolumen, liefert er `berechenbar=false`
 * und einen Grund — wie GebindeRechner bei »Stück-Artikel ohne Stückgewicht«.
 */
class BehaelterRechner
{
    /** kg je Liter NUTZvolumen. Grobe Klassen, bewusst keine Nachkommastellen-Präzision. */
    public const DICHTE = [
        'fluessig' => 1.00,
        'dicht' => 0.90,
        'schuettfaehig' => 0.60,
        'locker' => 0.20,
    ];

    private const KONFIDENZ_ABSTUFUNG = ['hoch' => 'mittel', 'mittel' => 'niedrig', 'niedrig' => 'niedrig'];

    /**
     * Nutzbares Volumen in Litern — NUR aus einem veröffentlichten Nennvolumen.
     *
     * Die Kantenrechnung ist bewusst KEIN Fallback mehr. GN-Behälter sind konisch: 530 × 325 × 65 mm
     * ergeben geometrisch 11,2 l, im Handel stehen 8,8 l. Auf Echtdaten (demo, 2026-09-04) fiel auf,
     * dass genau dieser Fallback bei den 20-mm-Formaten zuschlug — für die veröffentlicht der Handel
     * gar kein Litermaß, weil es Einlege-Schalen sind. Ergebnis war ein um 38 % zu hohes
     * Nutzvolumen, still und als Zahl präsentiert.
     *
     * Ohne Nennvolumen ist ein Behälter deshalb NICHT bemessbar, und das wird gesagt. Die Maße
     * bleiben trotzdem nützlich: fürs VERHÄLTNIS zwischen zwei Formaten (siehe wirkflaeche()), wo
     * sich der Konizitäts-Fehler weitgehend herauskürzt.
     */
    public static function nutzvolumenL(?object $c): ?float
    {
        if ($c === null || ! isset($c->volumen_l) || $c->volumen_l === null || (float) $c->volumen_l <= 0.0) {
            return null;
        }

        $faktor = isset($c->nutzfaktor) && $c->nutzfaktor !== null ? (float) $c->nutzfaktor : 0.85;

        return round((float) $c->volumen_l * $faktor, 3);
    }

    /** Grundfläche in cm² aus den Randmaßen — für die Anzeige in den Einstellungen. */
    public static function grundflaecheCm2(?object $c): ?float
    {
        if ($c === null || ! isset($c->laenge_mm, $c->breite_mm) || $c->laenge_mm === null || $c->breite_mm === null) {
            return null;
        }

        return round(((float) $c->laenge_mm * (float) $c->breite_mm) / 100, 2);
    }

    /**
     * Wirksame Fläche fürs Skalieren, in l je mm Höhe.
     *
     * `volumen_l / tiefe_mm` schlägt die Randfläche, weil es die Konizität schon enthält: ein
     * GN 1/2-65 hat 50 % der Randfläche eines GN 1/1-65, aber nur 45 % des Volumens — die kleinere
     * Form verliert anteilig mehr an Wand und Radius. Über die Randfläche zu skalieren hiesse,
     * diesen Verlust zu ignorieren.
     */
    private static function wirkflaeche(?object $c): ?float
    {
        if ($c === null) {
            return null;
        }

        $tiefe = isset($c->tiefe_mm) && $c->tiefe_mm !== null ? (float) $c->tiefe_mm : null;

        if (isset($c->volumen_l) && $c->volumen_l !== null && $tiefe !== null && $tiefe > 0.0) {
            return (float) $c->volumen_l / $tiefe;
        }

        $rand = self::grundflaecheCm2($c);

        return $rand !== null ? $rand / 100 : null;      // cm² → l je mm
    }

    /**
     * `eignung === null` heisst »noch nicht gepflegt«, nicht »verboten« — sonst wäre nach der
     * Migration jeder Bestandsbehälter gesperrt.
     */
    private static function taugtFuer(object $c, string $zweck): bool
    {
        $eignung = $c->eignung ?? null;
        if ($eignung === null) {
            return true;
        }
        if (is_string($eignung)) {
            $eignung = json_decode($eignung, true) ?: [];
        }

        return in_array($zweck, (array) $eignung, true);
    }

    /**
     * @param  float   $mengeKg     produzierte Menge (production_order_lines.produzierte_menge_kg)
     * @param  array   $basis       ['container' => object|null, 'referenz_menge_kg' => ?float,
     *                               'dichteklasse' => ?string, 'skalierung' => ?string,
     *                               'max_schichthoehe_mm' => ?float, 'konfidenz_rang3' => bool]
     * @param  array   $kandidaten  weitere Behälter-Objekte für Alternativen
     * @param  string  $zweck       abfuellen | regenerieren | ausgabe | transport
     * @return array{berechenbar:bool, varianten:array, grund:?string}
     *         varianten[0] ist die Basis; die weiteren sind Alternativen, flach → tief sortiert.
     */
    public function varianten(float $mengeKg, array $basis, array $kandidaten, string $zweck): array
    {
        $leer = ['berechenbar' => false, 'varianten' => [], 'grund' => null];

        $ref = $basis['container'] ?? null;
        $refKg = isset($basis['referenz_menge_kg']) && $basis['referenz_menge_kg'] !== null
            ? (float) $basis['referenz_menge_kg'] : null;
        $dichte = $basis['dichteklasse'] ?? null;
        $skalierung = $basis['skalierung'] ?? null;
        $schichtMm = isset($basis['max_schichthoehe_mm']) && $basis['max_schichthoehe_mm'] !== null
            ? (float) $basis['max_schichthoehe_mm'] : null;

        if ($mengeKg <= 0.0) {
            return [...$leer, 'grund' => 'Keine produzierte Menge — Ausbeute (yield_kg) am Rezept fehlt oder ist 0.'];
        }
        if ($ref === null) {
            return [...$leer, 'grund' => 'Kein Behälter für diesen Zweck hinterlegt.'];
        }
        if ($skalierung === 'lagenware') {
            return $this->lagenware($basis, $ref, $zweck, $leer);
        }
        if (! self::taugtFuer($ref, $zweck)) {
            $name = $ref->name ?? 'Behälter';

            return [...$leer, 'grund' => "«{$name}» ist nicht für „{$zweck}“ freigegeben."];
        }

        [$refKgProBehaelter, $konfidenz] = $this->basisMenge($ref, $refKg, $dichte, $basis['konfidenz_rang3'] ?? false);

        if ($refKgProBehaelter === null) {
            return [...$leer, 'grund' => $refKg === null && $dichte === null
                ? 'Weder Referenzmenge noch Dichteklasse hinterlegt — Behälter nicht bemessbar.'
                : 'Behälter ohne Maße und ohne Nennvolumen — Bedarf nicht umrechenbar.'];
        }

        $varianten = [$this->variante($ref, $mengeKg, $refKgProBehaelter, $konfidenz, true)];

        foreach ($kandidaten as $kandidat) {
            if (($kandidat->id ?? null) === ($ref->id ?? null) || ! self::taugtFuer($kandidat, $zweck)) {
                continue;
            }
            $skaliert = $this->skaliere($ref, $kandidat, $refKgProBehaelter, $skalierung, $schichtMm, $konfidenz);
            if ($skaliert === null) {
                continue;
            }
            [$kg, $konf] = $skaliert;
            $varianten[] = $this->variante($kandidat, $mengeKg, $kg, $konf, false);
        }

        // Basis bleibt vorn; Alternativen flach → tief, damit die schonendere Variante zuerst steht.
        $kopf = array_shift($varianten);
        usort($varianten, fn (array $a, array $b) => ($a['tiefe_mm'] ?? PHP_INT_MAX) <=> ($b['tiefe_mm'] ?? PHP_INT_MAX));

        return ['berechenbar' => true, 'varianten' => [$kopf, ...$varianten], 'grund' => null];
    }

    /**
     * Lagenware wird GELEGT, nicht geschüttet: Papadam, Schnitzel, Tartelettes. Für sie ist die
     * Masse die falsche Grösse — 3 kg Papadam füllen kein GN zu 3 kg, sondern zu einer Lage.
     *
     * Gerechnet wird deshalb über Stück. Fehlt eine der beiden Zahlen (Stückzahl je Behälter am
     * Rezept, Gesamtstückzahl aus Ausbeute × Stückertrag), rechnet der Rechner NICHT — eine
     * Umrechnung über die Masse wäre genau die Erfindung, die er sonst überall vermeidet.
     *
     * Alternativen gibt es hier bewusst keine: die Stückzahl bezieht sich auf GENAU diesen
     * Behälter; auf ein anderes Format zu skalieren hiesse, die Legefläche zu raten.
     */
    private function lagenware(array $basis, object $ref, string $zweck, array $leer): array
    {
        $jeBehaelter = $basis['stueck_je_behaelter'] ?? null;
        $gesamt = $basis['stueck_gesamt'] ?? null;

        if ($jeBehaelter === null || (int) $jeBehaelter <= 0) {
            return [...$leer, 'grund' => 'Lagenware — Stückzahl je Behälter nicht hinterlegt.'];
        }
        if ($gesamt === null || (float) $gesamt <= 0) {
            return [...$leer, 'grund' => 'Lagenware — Stückertrag (yield_pieces) am Rezept fehlt.'];
        }

        $anzahl = max(1, (int) ceil((float) $gesamt / (int) $jeBehaelter - 1e-9));

        return ['berechenbar' => true, 'varianten' => [[
            'container_id' => $ref->id ?? null,
            'behaelter' => $ref->name ?? null,
            'tiefe_mm' => isset($ref->tiefe_mm) && $ref->tiefe_mm !== null ? (float) $ref->tiefe_mm : null,
            'anzahl' => $anzahl,
            'kg_je_behaelter' => null,
            'stueck_je_behaelter' => (int) $jeBehaelter,
            'stueck_gesamt' => (int) round((float) $gesamt),
            'rest_im_letzten_kg' => null,
            'auf_deckel_gekappt' => false,
            'konfidenz' => 'hoch',
            'ist_basis' => true,
        ]], 'grund' => null];
    }

    /** @return array{0: ?float, 1: string} kg je Referenzbehälter + Konfidenz */
    private function basisMenge(object $ref, ?float $refKg, ?string $dichte, bool $rang3): array
    {
        if ($refKg !== null && $refKg > 0.0) {
            return [$refKg, 'hoch'];                                  // Rang 1 — aus der Küche
        }

        if ($dichte !== null && isset(self::DICHTE[$dichte])) {
            $nutz = self::nutzvolumenL($ref);
            if ($nutz !== null && $nutz > 0.0) {
                return [round($nutz * self::DICHTE[$dichte], 3), $rang3 ? 'niedrig' : 'mittel'];
            }
        }

        // Letzter Fallback: die alte handgepflegte kg-Zahl. Sie kennt keine Füllhöhe, deshalb niedrig.
        if (isset($ref->kapazitaet_kg) && $ref->kapazitaet_kg !== null && (float) $ref->kapazitaet_kg > 0.0) {
            return [(float) $ref->kapazitaet_kg, 'niedrig'];
        }

        return [null, 'niedrig'];
    }

    /**
     * Referenz → Kandidat. Die Richtung ist entscheidend:
     *
     * »nur die Fläche skaliert, der Rest bleibt Luft« stimmt nur flach → TIEF. Tief → FLACH kappt:
     * eine Referenz GN 1/1-100 mit 12 kg steht vielleicht 80 mm hoch — das passt nicht proportional
     * in ein GN 1/1-65. Ohne gepflegte Schichthöhe kann der Rechner das nicht wissen, also rechnet
     * er konservativ mit dem Tiefenverhältnis und stuft die Konfidenz ab.
     *
     * @return array{0: float, 1: string}|null kg je Kandidat + Konfidenz, oder null wenn unskalierbar
     */
    private function skaliere(
        object $ref,
        object $kandidat,
        float $refKg,
        ?string $skalierung,
        ?float $schichtMm,
        string $konfidenz
    ): ?array {
        $flRef = self::wirkflaeche($ref);
        $flKand = self::wirkflaeche($kandidat);

        // Ohne jedes Maß bleibt nur das Volumenverhältnis (Eimer, Kanne, Kiste).
        if ($flRef === null || $flKand === null || $flRef <= 0.0) {
            $volRef = self::nutzvolumenL($ref);
            $volKand = self::nutzvolumenL($kandidat);
            if ($volRef === null || $volKand === null || $volRef <= 0.0) {
                return null;
            }

            return [round($refKg * ($volKand / $volRef), 3), self::KONFIDENZ_ABSTUFUNG[$konfidenz]];
        }

        $tRef = isset($ref->tiefe_mm) && $ref->tiefe_mm !== null ? (float) $ref->tiefe_mm : null;
        $tKand = isset($kandidat->tiefe_mm) && $kandidat->tiefe_mm !== null ? (float) $kandidat->tiefe_mm : null;
        $flaechenVerhaeltnis = $flKand / $flRef;

        if ($tRef === null || $tKand === null || $tRef <= 0.0) {
            return [round($refKg * $flaechenVerhaeltnis, 3), self::KONFIDENZ_ABSTUFUNG[$konfidenz]];
        }

        // Gepflegte Schichthöhe: die genaue Antwort — die Ware liegt nie höher als sie liegt.
        if ($schichtMm !== null && $schichtMm > 0.0) {
            $hRef = min($schichtMm, $tRef);
            $hKand = min($schichtMm, $tKand);

            return [round($refKg * $flaechenVerhaeltnis * ($hKand / $hRef), 3), $konfidenz];
        }

        if ($skalierung === 'tiefer_fuellbar') {
            return [round($refKg * $flaechenVerhaeltnis * ($tKand / $tRef), 3), $konfidenz];
        }

        // hoehe_gebunden (und unbekannt): tiefer bringt nichts, flacher kappt.
        $tiefenFaktor = min(1.0, $tKand / $tRef);
        $konf = $tKand < $tRef ? self::KONFIDENZ_ABSTUFUNG[$konfidenz] : $konfidenz;

        return [round($refKg * $flaechenVerhaeltnis * $tiefenFaktor, 3), $konf];
    }

    /** Eine Variante inkl. Handhabungs-Deckel und Rest im letzten Behälter. */
    private function variante(object $c, float $mengeKg, float $kgRoh, string $konfidenz, bool $istBasis): array
    {
        $deckel = isset($c->max_fuellgewicht_kg) && $c->max_fuellgewicht_kg !== null
            ? (float) $c->max_fuellgewicht_kg : null;

        // Ein GN 1/1-200 fasst rechnerisch ~25 kg Suppe. Korrekt, aber niemand trägt das.
        $gekappt = $deckel !== null && $deckel > 0.0 && $kgRoh > $deckel;
        $kg = $gekappt ? $deckel : $kgRoh;

        $anzahl = max(1, (int) ceil($mengeKg / $kg - 1e-9));

        return [
            'container_id' => $c->id ?? null,
            'behaelter' => $c->name ?? null,
            'tiefe_mm' => isset($c->tiefe_mm) && $c->tiefe_mm !== null ? (float) $c->tiefe_mm : null,
            'anzahl' => $anzahl,
            'kg_je_behaelter' => round($kg, 3),
            'rest_im_letzten_kg' => round($anzahl * $kg - $mengeKg, 3),
            'auf_deckel_gekappt' => $gekappt,
            'konfidenz' => $konfidenz,
            'ist_basis' => $istBasis,
        ];
    }

    /**
     * Abfüll- und Regenerationsbehälter sind oft DERSELBE (Ragout im GN mit Deckel, das direkt in
     * den Ofen geht). Dann wird einmal gezählt, nicht zweimal — sonst steht auf dem Zettel
     * doppeltes Geschirr und niemand glaubt der Liste mehr.
     *
     * @return array{durchgaengig:bool, anzahl:?int, hinweis:string}
     */
    public function zusammenlegen(?array $abfuellen, ?array $regenerieren): array
    {
        $a = $abfuellen['varianten'][0] ?? null;
        $r = $regenerieren['varianten'][0] ?? null;

        if ($a === null || $r === null) {
            return ['durchgaengig' => false, 'anzahl' => $a['anzahl'] ?? $r['anzahl'] ?? null, 'hinweis' => ''];
        }

        if ($a['container_id'] !== null && $a['container_id'] === $r['container_id']) {
            return [
                'durchgaengig' => true,
                'anzahl' => max((int) $a['anzahl'], (int) $r['anzahl']),
                'hinweis' => 'durchgängig, kein Umfüllen',
            ];
        }

        return ['durchgaengig' => false, 'anzahl' => null, 'hinweis' => 'Umfüllen am Einsatztag'];
    }
}
