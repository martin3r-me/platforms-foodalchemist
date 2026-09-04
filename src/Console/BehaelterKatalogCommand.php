<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec 51 — legt den Behälter-Grundstock an, den die Bemessung braucht.
 *
 * Befund beim Bau: der Katalog kennt (je nach Mandant) ein paar GN-Zeilen aus dem WaWi-Import,
 * ohne Maße — und EIMER GIBT ES ÜBERHAUPT NICHT. Ohne Behälter kein Bedarf: der Rechner liefert
 * dann korrekt einen Grund statt einer Zahl, aber eben auch nie eine Zahl.
 *
 * Bewusst ein KOMMANDO, keine Migration. Ein `migrate` soll Schema ändern, nicht Stammdaten in
 * fremde Mandanten schreiben — und wer welche Behälter im Haus hat, weiss nur das Haus selbst.
 * Dry-Run ist der Default; --apply schreibt. Idempotent über den Slug im Ziel-Scope.
 *
 * WAS HIER STEHT UND WAS NICHT:
 *  - GN-Formate und -Volumina sind DIN EN 631 bzw. handelsüblich (Bruttovolumen). Belegt.
 *  - Eimer 5 l / 10 l: die Größen, die Dominique genannt hat. Weitere legt man in den
 *    Einstellungen an — dafür ist die Maske da.
 *  - Thermoboxen in Bäckernorm 600×400 (200/300 mm) sind Handelsware. Ihre STECKPLATZ-ZAHL ist
 *    es nicht: sie hängt an Innenhöhe und Behältertiefe. Bleibt NULL statt geraten.
 *  - `nutzfaktor` 0,85 und `max_fuellgewicht_kg` sind VORSCHLÄGE (Faustzahl »Nutzfüllmenge
 *    ≈ 85 %«; keine öffentliche Quelle nennt maximale Füllhöhen). Das Kommando sagt das dazu.
 */
class BehaelterKatalogCommand extends Command
{
    protected $signature = 'foodalchemist:behaelter-katalog
        {--team= : Ziel-Team-ID (ohne Angabe: globale Zeilen, team_id NULL — nur der Master pflegt die)}
        {--apply : Schreiben (sonst Dry-Run: nur zeigen, was fehlt)}';

    protected $description = 'Legt den Behälter-Grundstock an (GN nach EN 631, Eimer, Thermoboxen) — idempotent';

    private const TABELLE = 'foodalchemist_vocab_containers';

    /** DIN EN 631: format => [laenge_mm, breite_mm, [tiefe_mm => brutto_liter]]. */
    private const GN = [
        '2/1' => [650, 530, [40 => 10.0, 65 => 18.5, 100 => 28.5, 150 => 42.0]],
        '1/1' => [530, 325, [20 => 2.5, 40 => 5.5, 65 => 8.8, 100 => 13.7, 150 => 20.0, 200 => 27.8]],
        '2/3' => [354, 325, [40 => 3.3, 65 => 5.4, 100 => 8.0, 150 => 11.9, 200 => 15.5]],
        '1/2' => [325, 265, [40 => 2.3, 65 => 4.0, 100 => 6.5, 150 => 9.5, 200 => 12.5]],
        '1/3' => [325, 176, [40 => 1.4, 65 => 2.5, 100 => 4.0, 150 => 5.7, 200 => 7.5]],
        '1/4' => [265, 162, [65 => 1.8, 100 => 2.8, 150 => 4.0, 200 => 5.5]],
        '1/6' => [176, 162, [65 => 1.0, 100 => 1.6, 150 => 2.4]],
        '1/9' => [176, 108, [65 => 0.6, 100 => 1.0, 150 => 1.5]],
    ];

    public function handle(): int
    {
        $teamId = $this->option('team') !== null ? (int) $this->option('team') : null;
        $apply = (bool) $this->option('apply');

        $vorhanden = DB::table(self::TABELLE)
            ->when($teamId === null, fn ($q) => $q->whereNull('team_id'), fn ($q) => $q->where('team_id', $teamId))
            ->whereNull('deleted_at')
            ->pluck('slug')->flip();

        $neu = [];
        foreach ($this->katalog() as $zeile) {
            $slug = Str::slug($zeile['name'], '_');
            if (isset($vorhanden[$slug])) {
                continue;
            }
            $neu[] = ['slug' => $slug] + $zeile;
        }

        if ($neu === []) {
            $this->info('Katalog vollständig — nichts anzulegen.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Familie', 'L×B×T mm', 'Liter', 'max kg', 'Freigaben'],
            array_map(fn (array $z) => [
                $z['name'], $z['familie'],
                $z['laenge_mm'] !== null ? "{$z['laenge_mm']}×{$z['breite_mm']}×{$z['tiefe_mm']}" : '—',
                $z['volumen_l'] ?? '—',
                $z['max_fuellgewicht_kg'] ?? '—',
                implode(', ', json_decode((string) $z['eignung'], true) ?: []),
            ], $neu)
        );

        $ziel = $teamId === null ? 'GLOBAL (team_id NULL)' : "Team {$teamId}";

        if (! $apply) {
            $this->warn(count($neu)." Zeilen fehlen in {$ziel}. Dry-Run — mit --apply schreiben.");
            $this->line('Hinweis: nutzfaktor 0,85 und die kg-Deckel sind Vorschläge, keine Herstellerangaben.');
            $this->line('Nach dem Anlegen in den Einstellungen gegen das eigene Inventar prüfen —');
            $this->line('vor allem die Freigabe »regenerieren« (Polycarbonat-GN gehört nicht in den Ofen).');

            return self::SUCCESS;
        }

        $jetzt = now();
        DB::table(self::TABELLE)->insert(array_map(fn (array $z) => $z + [
            'uuid' => (string) Str::uuid7(),
            'team_id' => $teamId,
            'sort_order' => 100,
            'created_at' => $jetzt, 'updated_at' => $jetzt,
        ], $neu));

        $this->info(count($neu)." Behälter in {$ziel} angelegt.");
        $this->line('Freigaben und kg-Deckel bitte in den Einstellungen gegen das eigene Inventar prüfen.');

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function katalog(): array
    {
        $zeilen = [];

        foreach (self::GN as $format => [$laenge, $breite, $tiefen]) {
            foreach ($tiefen as $tiefe => $liter) {
                $zeilen[] = [
                    'name' => "GN {$format} {$tiefe}mm",
                    'group_name' => 'GN',
                    'familie' => 'GN',
                    'format_code' => $format,
                    'laenge_mm' => $laenge, 'breite_mm' => $breite, 'tiefe_mm' => $tiefe,
                    'volumen_l' => $liter,
                    'nutzfaktor' => 0.85,
                    // Nur setzen, wo er wirklich bindet: ein GN 1/9 (0,6 l) fasst nie 15 kg —
                    // die Zahl dort einzutragen ist Rauschen, das echte Deckel unglaubwuerdig macht.
                    'max_fuellgewicht_kg' => $liter * 0.85 > 15 ? 15 : null,
                    'eignung' => json_encode(['abfuellen', 'regenerieren', 'ausgabe']),
                    'ist_traeger' => false, 'traeger_plaetze' => null, 'traeger_format' => null,
                    'kapazitaet_kg' => null,
                ];
            }
        }

        // Abfüllen und Lagern — die Suppe kommt aus dem Kipper hier hinein, nicht ins GN.
        foreach ([5, 10] as $liter) {
            $zeilen[] = [
                'name' => "Eimer {$liter} l",
                'group_name' => 'Eimer',
                'familie' => 'Eimer',
                'format_code' => null,
                'laenge_mm' => null, 'breite_mm' => null, 'tiefe_mm' => null,
                'volumen_l' => $liter,
                'nutzfaktor' => 0.90,
                'max_fuellgewicht_kg' => $liter,
                'eignung' => json_encode(['abfuellen', 'transport']),
                'ist_traeger' => false, 'traeger_plaetze' => null, 'traeger_format' => null,
                'kapazitaet_kg' => null,
            ];
        }

        // Träger: nehmen Füllbehälter auf, werden selbst nie befüllt. Plätze bleiben ungepflegt,
        // weil sie von Innenhöhe und Behältertiefe abhängen — 4× GN-65 oder 2× GN-150 in derselben Box.
        foreach ([200, 300] as $hoehe) {
            $zeilen[] = [
                'name' => "Thermobox 600x400 ({$hoehe} mm)",
                'group_name' => 'Transport',
                'familie' => 'Traeger',
                'format_code' => '600x400',
                'laenge_mm' => 600, 'breite_mm' => 400, 'tiefe_mm' => $hoehe,
                'volumen_l' => null,
                'nutzfaktor' => 0.85,
                'max_fuellgewicht_kg' => null,
                'eignung' => json_encode(['transport']),
                'ist_traeger' => true, 'traeger_plaetze' => null, 'traeger_format' => '600x400',
                'kapazitaet_kg' => null,
            ];
        }

        return $zeilen;
    }
}
