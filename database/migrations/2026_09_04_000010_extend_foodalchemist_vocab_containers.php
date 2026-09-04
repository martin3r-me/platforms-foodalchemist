<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 51 A — der Behälter-Katalog lernt seine Maße (und wofür er freigegeben ist).
 *
 * Bisher trug `foodalchemist_vocab_containers` genau eine Zahl: `kapazitaet_kg`. Sie wird seit
 * Juni gepflegt und NIRGENDS gerechnet (17 Fundstellen im Repo, 0 in src/Services, src/Tools,
 * config) — reines Anzeigedatum im Settings-Tooltip. Für die Bemessung reicht sie auch nicht:
 * eine einzelne kg-Zahl kann »flach statt tief« nicht ausdrücken und würde bei 10 kg genau die
 * eine tiefe GN vorschlagen, die in der Küche niemand nimmt.
 *
 * Deshalb Fläche und Tiefe GETRENNT. Damit kann der Rechner von einer Referenz-Füllung
 * (»in ein GN 1/1-65 passen 8 kg davon«) auf jedes andere Format skalieren.
 *
 * Zwei Behälter-Sorten, unterschieden über `ist_traeger`:
 *   - Füllbehälter: die Ware kommt hinein (GN, Eimer, Kanne, Euronorm-Kasten, Blech, Schale)
 *   - Träger:       nimmt Füllbehälter auf (Thermobox, Isolierbehälter, Wagen, Ofengestell)
 * Damit ist jede Familie mit EINEM Modell abgedeckt, statt je Familie eine Sonderlogik.
 *
 * `eignung` sagt, für welche Zwecke ein Typ freigegeben ist (abfuellen | regenerieren | ausgabe |
 * transport). Sie wird GEPFLEGT, nicht aus einem Material abgeleitet: eine Regel »Kunststoff →
 * nicht in den Ofen« liegt bei hitzefestem PP oder Silikonformen daneben und würde als Wahrheit
 * gelesen. NULL heisst »noch nicht gepflegt« und wird vom Rechner als »keine bekannte
 * Einschränkung« behandelt — nicht als Sperre.
 *
 * Backfill: Nur die GN-Zeilen, und nur was der Name deterministisch hergibt
 * (»GN 1/1 65mm« → format_code 1/1, tiefe_mm 65 → Grundfläche und Volumen aus der Normtabelle
 * DIN EN 631). Der Name bleibt unangetastet — Nutzer kennen »GN 1/1 65mm«, die Norm wandert
 * strukturiert daneben. Alles andere bleibt NULL und kommt über die Einstellungen.
 *
 * ⚠ Die eignung-Vorbelegung der GN-Zeilen (abfuellen/regenerieren/ausgabe) beschreibt, wofür
 * Gastronorm GEBAUT ist. Wer Polycarbonat-GN im Bestand hat, nimmt »regenerieren« in den
 * Einstellungen von Hand heraus — dort steht die Wahrheit über das eigene Inventar.
 *
 * `kuehlfaehig` wurde BEWUSST WEGGELASSEN: in v1 liest es niemand, und eine zweite ungenutzte
 * Spalte wäre genau der Fehler, den dieser Spec behebt. Kommt, wenn es einen Leser hat.
 */
return new class extends Migration
{
    /** DIN EN 631: format_code => [laenge_mm, breite_mm]. */
    private const FORMATE = [
        '2/1' => [650, 530],
        '1/1' => [530, 325],
        '2/3' => [354, 325],
        '1/2' => [325, 265],
        '1/3' => [325, 176],
        '1/4' => [265, 162],
        '2/4' => [530, 162],
        '1/6' => [176, 162],
        '1/9' => [176, 108],
    ];

    /** Handelsübliches Bruttovolumen in Litern: format_code => [tiefe_mm => liter]. */
    private const VOLUMEN = [
        '2/1' => [40 => 10.0, 65 => 18.5, 100 => 28.5, 150 => 42.0],
        '1/1' => [40 => 5.5, 65 => 8.8, 100 => 13.7, 150 => 20.0, 200 => 27.8],
        '2/3' => [40 => 3.3, 65 => 5.4, 100 => 8.0, 150 => 11.9, 200 => 15.5],
        '1/2' => [40 => 2.3, 65 => 4.0, 100 => 6.5, 150 => 9.5, 200 => 12.5],
        '1/3' => [40 => 1.4, 65 => 2.5, 100 => 4.0, 150 => 5.7, 200 => 7.5],
        '1/4' => [40 => 1.6, 65 => 1.8, 100 => 2.8, 150 => 4.0, 200 => 5.5],
        '2/4' => [40 => 2.0, 65 => 3.7, 100 => 5.8, 150 => 8.7],
        '1/6' => [65 => 1.0, 100 => 1.6, 150 => 2.4],
        '1/9' => [65 => 0.6, 100 => 1.0, 150 => 1.5],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_vocab_containers')) {
            return;
        }

        Schema::table('foodalchemist_vocab_containers', function (Blueprint $table) {
            $neu = [
                'familie' => fn () => $table->string('familie', 24)->nullable()
                    ->comment('GN | EN600x400 | Eimer | Kanne | Schale | Blech | Kiste | Traeger | frei'),
                'format_code' => fn () => $table->string('format_code', 16)->nullable()
                    ->comment('GN-Bruchteil (1/1, 1/2, …) oder Kantenmass (600x400)'),
                'laenge_mm' => fn () => $table->decimal('laenge_mm', 8, 1)->nullable(),
                'breite_mm' => fn () => $table->decimal('breite_mm', 8, 1)->nullable(),
                'tiefe_mm' => fn () => $table->decimal('tiefe_mm', 8, 1)->nullable(),
                'volumen_l' => fn () => $table->decimal('volumen_l', 8, 2)->nullable()
                    ->comment('Bruttovolumen laut Norm/Hersteller — Nutzmenge erst mit nutzfaktor'),
                'nutzfaktor' => fn () => $table->decimal('nutzfaktor', 3, 2)->default(0.85)
                    ->comment('Anteil des Bruttovolumens, der real befüllt wird (Rand/Radien/Transport)'),
                'max_fuellgewicht_kg' => fn () => $table->decimal('max_fuellgewicht_kg', 8, 3)->nullable()
                    ->comment('Handhabungs-Deckel: was ein Mensch noch tragen soll'),
                'eignung' => fn () => $table->json('eignung')->nullable()
                    ->comment('Freigegebene Zwecke; NULL = nicht gepflegt, keine bekannte Einschränkung'),
                'ist_traeger' => fn () => $table->boolean('ist_traeger')->default(false)
                    ->comment('true = nimmt Füllbehälter auf, wird selbst nicht befüllt'),
                'traeger_plaetze' => fn () => $table->unsignedSmallInteger('traeger_plaetze')->nullable(),
                'traeger_format' => fn () => $table->string('traeger_format', 16)->nullable()
                    ->comment('Welches Format hineinpasst (1/1, 600x400)'),
            ];

            foreach ($neu as $spalte => $anlegen) {
                if (! Schema::hasColumn('foodalchemist_vocab_containers', $spalte)) {
                    $anlegen();
                }
            }
        });

        $this->backfillGn();
    }

    /**
     * Nur was der Name deterministisch hergibt. Kein Raten, keine Umbenennung.
     * Trifft »GN 1/1 65mm«, »GN 1/1-65«, »GN 1/1 65 mm« — sonst bleibt die Zeile unberührt.
     */
    private function backfillGn(): void
    {
        $zeilen = DB::table('foodalchemist_vocab_containers')
            ->whereNull('deleted_at')
            ->whereNull('format_code')
            ->get(['id', 'name']);

        foreach ($zeilen as $zeile) {
            if (! preg_match('/^\s*GN\s*(\d+\s*\/\s*\d+)\s*[-\s]\s*(\d+)\s*(?:mm)?\s*$/i', (string) $zeile->name, $treffer)) {
                continue;
            }

            $format = str_replace(' ', '', $treffer[1]);
            $tiefe = (int) $treffer[2];

            if (! isset(self::FORMATE[$format])) {
                continue;
            }

            [$laenge, $breite] = self::FORMATE[$format];

            DB::table('foodalchemist_vocab_containers')->where('id', $zeile->id)->update([
                'familie' => 'GN',
                'format_code' => $format,
                'laenge_mm' => $laenge,
                'breite_mm' => $breite,
                'tiefe_mm' => $tiefe,
                'volumen_l' => $liter = self::VOLUMEN[$format][$tiefe] ?? null,
                // Der Deckel wird nur gesetzt, wo er bindet. Ein GN 1/9 (0,6 l) fasst nie 15 kg;
                // die Zahl dort einzutragen ist Rauschen, das die echten Deckel entwertet.
                'max_fuellgewicht_kg' => $liter !== null && $liter * 0.85 > 15 ? 15 : null,
                'eignung' => json_encode(['abfuellen', 'regenerieren', 'ausgabe']),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_vocab_containers')) {
            return;
        }

        Schema::table('foodalchemist_vocab_containers', function (Blueprint $table) {
            foreach ([
                'familie', 'format_code', 'laenge_mm', 'breite_mm', 'tiefe_mm', 'volumen_l',
                'nutzfaktor', 'max_fuellgewicht_kg', 'eignung', 'ist_traeger',
                'traeger_plaetze', 'traeger_format',
            ] as $spalte) {
                if (Schema::hasColumn('foodalchemist_vocab_containers', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
