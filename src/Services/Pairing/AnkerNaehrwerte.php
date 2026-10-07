<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\WissensStatus;
use Platform\FoodAlchemist\Services\SensorikService;

/**
 * Spec 60 · Ebene 2, Nährwert-Kanal: was ein Anker an Salz, Süße und Fett liefert — gemessen an den
 * Nährwerten der Lieferantenartikel seiner Kern-Grundprodukte, nicht aus dem Dossier.
 *
 * Messung und Umrechnung sind NICHT neu: {@see SensorikService::erdungBulk} misst je GP (Leer-Label-,
 * 0-ist-nicht-deklariert- und LMIV-Guard, Kurve an EU-VO 1924/2006 + FSA verankert), die Stufe ist
 * 3 × Intensität, gerundet — dieselbe Umrechnung, mit der das Rezept-Profil die Sensorik übernimmt
 * ({@see RezeptProfil}, SENSORIK_BELEGT). Stufe ≥ 2 entspricht damit Intensität ≥ 0,5, nahe der
 * FSA-Schwelle „hoch" (Fett 0,55 · Salz/Zucker 0,60).
 *
 * Gemessen wird nur an GPs, die die Zutat SIND — nicht an Produkten, die sie enthalten. Am Anker
 * „Honig" hängen als Kern auch Honig-Senf-Dressing, Baklava und Ziegenkäse-Ravioli; ein Median über
 * alle ergab „Honig: salzig 2, fett 2, süß 1" (gemessen 2026-10-06). Identität heißt:
 *   1. der Anker ist der EINZIGE Kern-Anker des GP, und
 *   2. das ERSTE Wort des Produktnamens (vor dem Doppelpunkt, Regelwerk GP §6) ist der Anker oder ein
 *      Kompositum mit ihm als Kopf (deutsche Wortbildung: das letzte Glied bestimmt — „Waldhonig" ist
 *      Honig, „Honigkuchen" ist Kuchen, „Honig-Senf-Dressing" ist Dressing). Das erste Wort, weil der
 *      Bestand Produkt vor Geschmack schreibt: „Eis Zitrone", „Radler Zitrone", „Hummus Tomate".
 *   3. kein zusammengesetztes Produkt („Avocado mit Steckrüben-Couscous", „Sesam mit Kimchi").
 * Je Anker gilt der Median über diese GPs. Ergebnis: `anchor_eigenschaften` mit quelle = naehrwert.
 * Neuaufbau ersetzt die eigenen Entwürfe; geprüfte und verworfene Zeilen bleiben (wie KategorieRegeln).
 *
 * Kräuter und Gewürze (außer Salz) bekommen keinen Nährwert-Kanal: sie werden in Gramm dosiert, ihre
 * Werte je 100 g sagen nichts darüber, was sie dem Teller geben. Gemessen 2026-10-06: ohne die Regel
 * erschienen „Rosmarin (Fett)", „Oregano/Majoran (Süße)", „Currypulver (Salz)" als Kontrast-Lieferanten.
 *
 * Säure, Umami, Bitterkeit und Schärfe sind in den Nährwerten nicht enthalten — sie kommen weiter
 * nur aus dem Dossier.
 */
final class AnkerNaehrwerte
{
    /** Würzmengen-Kategorien ohne Nährwert-Kanal; Präfix-Vergleich auf category/subcategory. */
    public const OHNE_KANAL = ['Kräuter', 'Gewuerze', 'Gemüse/Fruchtgemüse/Chili'];   // Chili: Gemüse-Kategorie, Gewürz-Dosis

    /** Ausnahme: Salz ist ein Gewürz und liefert Salz. */
    public const AUSNAHME = ['Gewuerze/Salz'];

    /** Sensorik-Dimension → Achse. */
    public const ACHSEN = ['salzig' => 'salz', 'suess' => 'suesse', 'fettig' => 'fett'];

    public function __construct(private readonly SensorikService $sensorik) {}

    /** @return array{anker: int, zeilen: int} */
    public function ableiten(): array
    {
        $mappings = DB::table('foodalchemist_gp_anchor_mappings')->whereNull('deleted_at')->where('role', 'kern')
            ->get(['anchor_id', 'gp_id']);
        $kernJeGp = $mappings->groupBy('gp_id')->map(fn ($z) => $z->pluck('anchor_id')->unique()->count());
        $gpName = DB::table('foodalchemist_gps')->whereIn('id', $kernJeGp->keys()->all())->pluck('name', 'id');
        $anker = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $mappings->pluck('anchor_id')->unique()->all())
            ->get(['id', 'display_de', 'grundname', 'category', 'subcategory']);
        $ankerName = $anker->mapWithKeys(fn ($a) => [(int) $a->id => (string) ($a->grundname ?: $a->display_de)]);
        $wuerzmenge = $anker->filter(fn ($a) => $this->wuerzmenge((string) ($a->subcategory ?: $a->category)))
            ->mapWithKeys(fn ($a) => [(int) $a->id => true]);
        $kern = $mappings->filter(fn ($m) => ! isset($wuerzmenge[(int) $m->anchor_id]) && $kernJeGp[$m->gp_id] === 1
                && $this->istZutat((string) ($gpName[$m->gp_id] ?? ''), (string) ($ankerName[(int) $m->anchor_id] ?? '')))
            ->groupBy('anchor_id')
            ->map(fn ($z) => $z->pluck('gp_id')->map(fn ($i) => (int) $i)->unique()->values()->all());
        $messung = [];
        foreach (array_chunk($kern->flatten()->unique()->values()->all(), 2000) as $chunk) {
            $messung += $this->sensorik->erdungBulk($chunk);
        }
        $bestand = DB::table('foodalchemist_anchor_eigenschaften')->where('quelle', 'naehrwert')
            ->where('status', '!=', WissensStatus::Entwurf->value)->get(['anchor_id', 'achse'])
            ->mapWithKeys(fn ($r) => [$r->anchor_id.'|'.$r->achse => true])->all();

        $zeilen = [];
        $ts = now();
        foreach ($kern as $ankerId => $gps) {
            foreach (self::ACHSEN as $dim => $achse) {
                $werte = [];
                foreach ($gps as $gp) {
                    if (isset($messung[$gp][$dim])) {
                        $werte[] = (float) $messung[$gp][$dim]['wert'];
                    }
                }
                if ($werte === [] || isset($bestand[$ankerId.'|'.$achse])) {
                    continue;
                }
                $median = $this->median($werte);
                $zeilen[] = ['anchor_id' => (int) $ankerId, 'achse' => $achse, 'stufe' => (int) round(3 * $median),
                    'quelle' => 'naehrwert', 'status' => WissensStatus::Entwurf->value,
                    'beleg' => mb_substr('Nährwerte: Intensität '.number_format($median, 2, ',', '.')
                        .(count($werte) > 1 ? ' (Median aus '.count($werte).' Grundprodukten)' : ' ('.$this->basis($messung, $gps, $dim).')'), 0, 400),
                    'created_at' => $ts, 'updated_at' => $ts];
            }
        }

        DB::transaction(function () use ($zeilen) {
            DB::table('foodalchemist_anchor_eigenschaften')->where('quelle', 'naehrwert')
                ->where('status', WissensStatus::Entwurf->value)->delete();
            foreach (array_chunk($zeilen, 1000) as $c) {
                DB::table('foodalchemist_anchor_eigenschaften')->insert($c);
            }
        });

        return ['anker' => count(array_unique(array_column($zeilen, 'anchor_id'))), 'zeilen' => count($zeilen)];
    }

    /**
     * Ist das GP die Zutat selbst? Produktname = Anker-Name oder Kompositum mit ihm als Kopf.
     * Umlaute wie im GP-Bestand ausgeschrieben (ö → oe), Vergleich ohne Groß-/Kleinschreibung.
     */
    public function istZutat(string $gpName, string $ankerName): bool
    {
        $norm = fn (string $x) => trim(strtr(mb_strtolower($x), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']));
        $produkt = $norm(explode(':', $gpName, 2)[0]);
        $anker = $norm(explode(',', $ankerName, 2)[0]);
        if ($produkt === '' || $anker === '') {
            return false;
        }
        if ($produkt === $anker) {
            return true;
        }
        if (preg_match('/\s(mit|und|&)\s/u', ' '.$produkt.' ') === 1) {
            return false;                                     // zusammengesetzt: Gericht, nicht Zutat
        }
        $erstesWort = (string) (preg_split('/\s+/', $produkt)[0] ?? '');
        $teile = explode('-', $erstesWort);
        $kopf = (string) end($teile);

        return $kopf !== '' && str_ends_with($kopf, $anker);
    }

    private function wuerzmenge(string $pfad): bool
    {
        foreach (self::AUSNAHME as $a) {
            if (str_starts_with($pfad, $a)) {
                return false;
            }
        }
        foreach (self::OHNE_KANAL as $k) {
            if (str_starts_with($pfad, $k)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<float>  $werte */
    private function median(array $werte): float
    {
        sort($werte);
        $n = count($werte);

        return $n % 2 === 1 ? $werte[intdiv($n, 2)] : ($werte[$n / 2 - 1] + $werte[$n / 2]) / 2;
    }

    /** Messgrundlage des einen GP, z. B. „Fett 99,9 g/100 g". */
    private function basis(array $messung, array $gps, string $dim): string
    {
        foreach ($gps as $gp) {
            if (isset($messung[$gp][$dim])) {
                return (string) $messung[$gp][$dim]['basis'];
            }
        }

        return '';
    }
}
