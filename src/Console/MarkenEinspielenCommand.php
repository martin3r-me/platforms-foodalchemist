<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\SupplierRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel;

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3 — Lieferanten-Rolle/Kurzname und Marken-Kürzel aus den CSVs des Moduls einspielen. **Default ist Probelauf.**
 *
 * Quellen (resources/data/marken/, von Dominique bestätigt 26.10.2026): `lieferanten_rolle.csv` (lieferant;rolle;kurzname) und `kuerzel.csv`
 * (lieferant;kuerzel;marke;typ; lieferant leer = gilt für alle). Ein weiteres Kürzel ist eine CSV-Zeile plus erneuter Lauf.
 * Lieferanten nur per exaktem Namen (global, nicht gelöscht); kein oder mehrere Treffer werden gelistet, nie geraten. Idempotent (updateOrCreate),
 * löscht nie. Sperrwörter (MSC) werden abgewiesen.
 */
class MarkenEinspielenCommand extends Command
{
    protected $signature = 'foodalchemist:marken-einspielen
        {--pfad= : Ordner mit lieferanten_rolle.csv und kuerzel.csv (Standard: Modul resources/data/marken)}
        {--apply : wirklich schreiben (ohne dieses Flag nur Probelauf)}';

    protected $description = '§5 Ausnahme 3: Lieferanten-Rolle, Kurzname und Marken-Kürzel einspielen (Probelauf, --apply schreibt).';

    public function handle(MarkeAusArtikel $marken): int
    {
        $pfad = rtrim((string) ($this->option('pfad') ?: __DIR__ . '/../../resources/data/marken'), '/');
        $apply = (bool) $this->option('apply');
        foreach (['lieferanten_rolle.csv', 'kuerzel.csv'] as $datei) {
            if (! is_file($pfad . '/' . $datei)) {
                $this->error("Datei fehlt: {$pfad}/{$datei}");

                return self::FAILURE;
            }
        }

        $zaehler = ['lieferant_neu' => 0, 'lieferant_gleich' => 0, 'kuerzel_neu' => 0, 'kuerzel_geaendert' => 0, 'kuerzel_gleich' => 0];
        $fehler = [];
        $schreiben = [];

        foreach ($this->lies($pfad . '/lieferanten_rolle.csv') as $z) {
            $rolle = SupplierRolle::tryFrom(trim($z['rolle'] ?? ''));
            if ($rolle === null) {
                $fehler[] = "Rolle unbekannt: „{$z['rolle']}“ ({$z['lieferant']})";

                continue;
            }
            $id = $this->lieferantId($z['lieferant'], $fehler);
            if ($id === null) {
                continue;
            }
            $kurz = $rolle === SupplierRolle::Hersteller && trim($z['kurzname'] ?? '') !== '' ? trim($z['kurzname']) : null;
            $jetzt = DB::table('foodalchemist_suppliers')->where('id', $id)->first(['rolle', 'kurzname']);
            if ($jetzt->rolle === $rolle->value && $jetzt->kurzname === $kurz) {
                $zaehler['lieferant_gleich']++;

                continue;
            }
            $zaehler['lieferant_neu']++;
            $schreiben[] = fn () => DB::table('foodalchemist_suppliers')->where('id', $id)->update(['rolle' => $rolle->value, 'kurzname' => $kurz, 'updated_at' => now()]);
        }

        foreach ($this->lies($pfad . '/kuerzel.csv') as $z) {
            $kuerzel = mb_strtolower(rtrim(trim($z['kuerzel'] ?? ''), '.'));
            $marke = trim($z['marke'] ?? '');
            $typ = trim($z['typ'] ?? '');
            if ($kuerzel === '' || $marke === '' || ! in_array($typ, [FoodAlchemistSupplierMarke::TYP_EIGENMARKE, FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE], true)) {
                $fehler[] = 'Kürzel-Zeile unvollständig: ' . implode(';', $z);

                continue;
            }
            if (in_array($kuerzel, MarkeAusArtikel::SPERRWOERTER, true)) {
                $fehler[] = "„{$kuerzel}“ ist ein Sperrwort (Zertifizierung, keine Marke) — abgewiesen.";

                continue;
            }
            $lieferant = trim($z['lieferant'] ?? '');
            $id = $lieferant === '' ? null : $this->lieferantId($lieferant, $fehler);
            if ($lieferant !== '' && $id === null) {
                continue;
            }
            $vorhanden = FoodAlchemistSupplierMarke::query()->where('kuerzel', $kuerzel)
                ->when($id === null, fn ($q) => $q->whereNull('supplier_id'), fn ($q) => $q->where('supplier_id', $id))->first();
            if ($vorhanden !== null && $vorhanden->marke === $marke && $vorhanden->typ === $typ) {
                $zaehler['kuerzel_gleich']++;

                continue;
            }
            $zaehler[$vorhanden === null ? 'kuerzel_neu' : 'kuerzel_geaendert']++;
            $schreiben[] = fn () => $vorhanden !== null
                ? $vorhanden->update(['marke' => $marke, 'typ' => $typ])
                : FoodAlchemistSupplierMarke::create(['supplier_id' => $id, 'kuerzel' => $kuerzel, 'marke' => $marke, 'typ' => $typ]);
        }

        $this->table(['Lieferanten neu/geändert', 'unverändert', 'Kürzel neu', 'geändert', 'unverändert', 'nicht zugeordnet/abgewiesen'], [[
            $zaehler['lieferant_neu'], $zaehler['lieferant_gleich'], $zaehler['kuerzel_neu'], $zaehler['kuerzel_geaendert'], $zaehler['kuerzel_gleich'], count($fehler),
        ]]);
        foreach ($fehler as $f) {
            $this->warn($f);
        }
        if (! $apply) {
            $this->info('Probelauf — nichts geschrieben. Mit --apply schreiben.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($schreiben) {
            foreach ($schreiben as $s) {
                $s();
            }
        });
        $marken->vergessen();
        $this->info(count($schreiben) . ' Änderungen geschrieben.');

        return self::SUCCESS;
    }

    /** Globaler, nicht gelöschter Lieferant mit exakt diesem Namen; sonst Fehlerzeile. */
    private function lieferantId(string $name, array &$fehler): ?int
    {
        $ids = DB::table('foodalchemist_suppliers')->where('name', trim($name))->whereNull('team_id')->whereNull('deleted_at')->pluck('id');
        if ($ids->count() !== 1) {
            $fehler[] = "Lieferant „{$name}“: {$ids->count()} Treffer — nicht zugeordnet.";

            return null;
        }

        return (int) $ids->first();
    }

    /** @return list<array<string, string>> */
    private function lies(string $datei): array
    {
        $h = fopen($datei, 'r');
        $kopf = array_map(fn ($k) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $k)), fgetcsv($h, 0, ';', '"', '\\') ?: []);
        $zeilen = [];
        while (($z = fgetcsv($h, 0, ';', '"', '\\')) !== false) {
            if ($z === [null] || count($z) !== count($kopf)) {
                continue;
            }
            $zeilen[] = array_combine($kopf, array_map('strval', $z));
        }
        fclose($h);

        return $zeilen;
    }
}
