<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 51 — Behälter-Dubletten zusammenführen, die der Katalog-Seed erzeugt hat.
 *
 * ★ Der Fehler: `foodalchemist:behaelter-katalog` hat gegen den SLUG entdoppelt. Der Bestand aus
 * dem WaWi-Import schreibt aber `gn_1_1_65mm`, der Seed `gn_11_65mm` — verschiedene Slugs, gleicher
 * Behälter. Auf demo standen danach 16 GN-Größen doppelt im Katalog (Bestand 2026-06-12 + Seed
 * 2026-09-04). Die Spec hatte das Muster sogar benannt (»mindestens drei Slug-Schreibweisen im
 * Umlauf, der Matcher normalisiert über den NAMEN«) — der Backfill-Matcher tat das, der Seed nicht.
 *
 * Zusammengeführt wird auf die NIEDRIGSTE ID: das ist die Bestandszeile, auf die vorhandene Rezepte
 * und Darreichungen zeigen. Fehlende Attribute (Volumen, Maße, Freigaben) wandern von der Dublette
 * in den Behalt-Datensatz, gesetzte Werte werden NIE überschrieben. Referenzen werden umgehängt,
 * dann wird die Dublette soft-gelöscht.
 *
 * Sicherung gegen Falsch-Merge: tragen beide ein Nennvolumen und weichen sie um mehr als 5 % ab,
 * bleibt das Paar unangetastet — dann sind es zwei verschiedene Behälter mit ähnlichem Namen.
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_vocab_containers';

    /** Referenzen auf das Behälter-Vokabular — Spiegel von Settings\Behaelter::nutzungsorte('behaelter'). */
    private const REFERENZEN = [
        ['foodalchemist_recipes', ['container_warm_vocab_id', 'container_cold_vocab_id']],
        ['foodalchemist_recipe_presentations', ['container_warm_vocab_id', 'container_cold_vocab_id']],
        ['foodalchemist_recipe_containers', ['container_vocab_id']],
    ];

    public function up(): void
    {
        $paare = $this->dubletten();

        foreach ($paare as [$behalt, $dublette]) {
            $this->ergaenzeAttribute($behalt, $dublette);
            $this->haengeReferenzenUm((int) $dublette->id, (int) $behalt->id);

            DB::table(self::TABELLE)->where('id', $dublette->id)->update([
                'deleted_at' => now(),
                'is_inactive' => 1,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Nicht umkehrbar: welche Referenz vorher auf welche Dublette zeigte, ist nach dem
        // Umhängen nicht mehr rekonstruierbar. Rückweg ist das Backup.
    }

    /** @return list<array{0: object, 1: object}> [Behalt, Dublette] */
    private function dubletten(): array
    {
        $rows = DB::table(self::TABELLE)->whereNull('deleted_at')->orderBy('id')->get();

        $gruppen = [];
        foreach ($rows as $r) {
            $gruppen[($r->team_id ?? 'global').'|'.$this->schluessel((string) $r->name)][] = $r;
        }

        $paare = [];
        foreach ($gruppen as $gruppe) {
            if (count($gruppe) < 2) {
                continue;
            }
            $behalt = array_shift($gruppe);           // niedrigste ID = die referenzierte
            foreach ($gruppe as $dublette) {
                if ($this->widersprechenSich($behalt, $dublette)) {
                    continue;
                }
                $paare[] = [$behalt, $dublette];
            }
        }

        return $paare;
    }

    /** „GN 1/1 65mm", „GN 1/1-65" und „gn 1/1 65 mm" ergeben denselben Schlüssel. */
    private function schluessel(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = (string) preg_replace('/(\d)\s*mm\b/u', '$1', $s);      // 65mm / 65 mm → 65
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);    // 1/1, 1-1, 1_1 → 1 1
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private function widersprechenSich(object $a, object $b): bool
    {
        $va = $a->volumen_l ?? null;
        $vb = $b->volumen_l ?? null;
        if ($va === null || $vb === null || (float) $va <= 0.0) {
            return false;
        }

        return abs((float) $va - (float) $vb) / (float) $va > 0.05;
    }

    private function ergaenzeAttribute(object $behalt, object $dublette): void
    {
        $felder = ['familie', 'format_code', 'laenge_mm', 'breite_mm', 'tiefe_mm', 'volumen_l',
            'nutzfaktor', 'max_fuellgewicht_kg', 'eignung', 'kapazitaet_kg', 'ist_traeger',
            'traeger_plaetze', 'traeger_format'];

        $patch = [];
        foreach ($felder as $f) {
            if (! property_exists($behalt, $f)) {
                continue;
            }
            // Nur Lücken füllen. Ein gepflegter Wert im Behalt-Datensatz gewinnt immer.
            if (($behalt->$f ?? null) === null && ($dublette->$f ?? null) !== null) {
                $patch[$f] = $dublette->$f;
            }
        }

        if ($patch !== []) {
            DB::table(self::TABELLE)->where('id', $behalt->id)->update($patch + ['updated_at' => now()]);
        }
    }

    private function haengeReferenzenUm(int $von, int $auf): void
    {
        foreach (self::REFERENZEN as [$tabelle, $spalten]) {
            if (! DB::getSchemaBuilder()->hasTable($tabelle)) {
                continue;
            }
            foreach ($spalten as $spalte) {
                if (! DB::getSchemaBuilder()->hasColumn($tabelle, $spalte)) {
                    continue;
                }
                DB::table($tabelle)->where($spalte, $von)->update([$spalte => $auf]);
            }
        }
    }
};
