<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importiert die menschlich reviewte DE/EN-Übersetzung der Foodpairing-Anker aus einer CSV
 * (Spalten: Original_Name_EN;Name_DE;Kategorie;Unterkategorie;…). DETERMINISTISCH, keine KI.
 *
 * Bevorzugter Weg vor {@see AnchorsTranslateCommand} (der KI-Fallback für Anker, die die CSV
 * nicht abdeckt). Match: `anchor.display_de` (aktuell Englisch aus dem Inspire-Import) ==
 * `CSV.Original_Name_EN` (case-insensitiv, getrimmt). Geschrieben wird:
 *   - display_de  = Name_DE (deutsch)
 *   - display_en  = bisheriger display_de (Original = Idempotenz-Marker)
 *   - category    = Kategorie (deutsch, falls Spalte vorhanden)
 *   - subcategory = Unterkategorie (deutsch, falls Spalte vorhanden)
 *
 * Idempotent + resume-fähig: nur Anker mit `display_en IS NULL`. Dry-Run by default; --apply schreibt.
 * Die CSV ist Foodpairing-Katalogdaten → NICHT im Repo, per --csv zur Laufzeit übergeben.
 */
class AnchorsTranslateCsvCommand extends Command
{
    protected $signature = 'foodalchemist:anchors-translate-csv
        {--csv= : Pfad zur reviewten CSV (Original_Name_EN;Name_DE;Kategorie;Unterkategorie;…)}
        {--apply : Schreiben (sonst Dry-Run: nur Abdeckung zählen)}
        {--delimiter=; : CSV-Trennzeichen}';

    protected $description = 'Importiert die reviewte DE/EN-Anker-Übersetzung aus CSV (display_de/en + deutsche Kategorie)';

    private const ANCHORS = 'foodalchemist_vocab_pairing_anchors';

    public function handle(): int
    {
        $path = (string) $this->option('csv');
        $apply = (bool) $this->option('apply');
        $delim = (string) ($this->option('delimiter') ?: ';');

        if ($path === '' || ! is_readable($path)) {
            $this->error('CSV nicht lesbar — --csv=/pfad/zur/datei.csv angeben.');

            return self::FAILURE;
        }

        // CSV → Map: normalisierter EN-Name => [de, kat, sub].
        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error('CSV konnte nicht geöffnet werden.');

            return self::FAILURE;
        }
        $header = fgetcsv($fh, 0, $delim);
        if (! is_array($header)) {
            $this->error('CSV-Header fehlt.');
            fclose($fh);

            return self::FAILURE;
        }
        // BOM am ersten Header-Feld entfernen.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $col = array_flip(array_map('trim', $header));
        foreach (['Original_Name_EN', 'Name_DE'] as $req) {
            if (! isset($col[$req])) {
                $this->error("CSV-Spalte fehlt: {$req}");
                fclose($fh);

                return self::FAILURE;
            }
        }

        $map = [];
        $rows = 0;
        while (($r = fgetcsv($fh, 0, $delim)) !== false) {
            $en = trim((string) ($r[$col['Original_Name_EN']] ?? ''));
            if ($en === '') {
                continue;
            }
            $de = trim((string) ($r[$col['Name_DE']] ?? ''));
            $map[$this->norm($en)] = [
                'de' => $de !== '' ? $de : $en,
                'kat' => isset($col['Kategorie']) ? trim((string) ($r[$col['Kategorie']] ?? '')) : '',
                'sub' => isset($col['Unterkategorie']) ? trim((string) ($r[$col['Unterkategorie']] ?? '')) : '',
            ];
            $rows++;
        }
        fclose($fh);
        $this->info(sprintf('CSV: %d Zeilen, %d eindeutige EN-Namen%s.',
            $rows, count($map), $apply ? '' : ' [DRY-RUN]'));

        // Offene Anker (noch nicht übersetzt).
        $anker = DB::table(self::ANCHORS)
            ->whereNull('deleted_at')->whereNull('display_en')->whereNotNull('display_de')
            ->orderBy('id')->get(['id', 'display_de']);

        $treffer = 0;
        $ohne = 0;
        $fehler = 0;
        $beispieleOhne = [];
        foreach ($anker as $a) {
            $hit = $map[$this->norm($a->display_de)] ?? null;
            if ($hit === null) {
                $ohne++;
                if (count($beispieleOhne) < 15) {
                    $beispieleOhne[] = $a->display_de;
                }

                continue;
            }
            $treffer++;
            if (! $apply) {
                continue;
            }
            // Defensiv auf die Spaltenbreiten kürzen (category 48, subcategory 150).
            $upd = ['display_en' => $a->display_de, 'display_de' => $hit['de'], 'updated_at' => now()];
            if ($hit['kat'] !== '') {
                $upd['category'] = mb_substr($hit['kat'], 0, 48);
            }
            if ($hit['sub'] !== '') {
                $upd['subcategory'] = mb_substr($hit['sub'], 0, 150);
            }
            try {
                DB::table(self::ANCHORS)->where('id', $a->id)->update($upd);
            } catch (\Throwable $e) {
                $fehler++;
                if ($fehler <= 5) {
                    $this->warn(sprintf('  Anker %d (%s) übersprungen: %s', $a->id, $a->display_de, $e->getMessage()));
                }
                // display_en bleibt NULL → beim nächsten Lauf erneut versucht.
            }
        }

        $this->info(sprintf('Anker offen: %d · CSV-Treffer: %d · ohne CSV-Eintrag: %d%s%s.',
            $anker->count(), $treffer, $ohne,
            $apply ? ' (geschrieben)' : '',
            $fehler > 0 ? ' · '.$fehler.' Fehler (offen geblieben)' : ''));
        if ($ohne > 0) {
            $this->line('Nicht abgedeckt (Beispiele): '.implode(', ', $beispieleOhne));
            $this->line('→ ggf. via foodalchemist:anchors-translate (KI-Fallback) nachziehen.');
        }

        return self::SUCCESS;
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim($s));
    }
}
