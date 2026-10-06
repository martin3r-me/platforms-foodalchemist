<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Pairing\AnkerVarianten;
use Platform\FoodAlchemist\Services\Pairing\AnkerWissenImport;
use Platform\FoodAlchemist\Services\Pairing\KontrastAbleitung;

/**
 * Spec 60 · P4: Anker-Wissen pflegen — drei Schritte, jeder für sich wiederholbar.
 *
 *   php artisan foodalchemist:anker-wissen varianten            Grundname/Verfahren/Grund-Anker aus den Inspire-Namen
 *   php artisan foodalchemist:anker-wissen import --pfad=DIR    Profile (*.json, Format der Pilot-Auslese) übernehmen
 *   php artisan foodalchemist:anker-wissen kontrast             Kontrast-Kanten aus Bedarf × Eigenschaft neu bauen
 *
 * Default ist Dry-Run für `varianten`; `import` und `kontrast` schreiben nur mit --apply.
 */
class AnkerWissenCommand extends Command
{
    protected $signature = 'foodalchemist:anker-wissen
        {schritt : varianten | import | kontrast}
        {--pfad= : Ordner mit Profil-JSON-Dateien (import)}
        {--apply : wirklich schreiben}';

    protected $description = 'Spec 60: Anker-Varianten ableiten, Dossier-Wissen importieren, Kontrast-Kanten bauen';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        return match ((string) $this->argument('schritt')) {
            'varianten' => $this->varianten($apply),
            'import' => $this->import($apply),
            'kontrast' => $this->kontrast($apply),
            default => $this->fehler('Schritt unbekannt. Erlaubt: varianten, import, kontrast.'),
        };
    }

    private function varianten(bool $apply): int
    {
        $s = app(AnkerVarianten::class)->ableiten($apply);
        $this->table(['Kennzahl', 'Wert'], collect($s)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->line($apply ? '✅ geschrieben' : '→ Dry-Run. Mit --apply schreiben.');

        return self::SUCCESS;
    }

    private function import(bool $apply): int
    {
        $pfad = rtrim((string) $this->option('pfad'), '/');
        if ($pfad === '' || ! is_dir($pfad)) {
            return $this->fehler('--pfad=/ordner/mit/profilen angeben.');
        }
        $dateien = glob($pfad.'/*.json') ?: [];
        if (! $apply) {
            $this->line(count($dateien).' Profile gefunden. → Dry-Run. Mit --apply übernehmen.');

            return self::SUCCESS;
        }
        $summe = ['ok' => 0, 'abgelehnt' => 0, 'bedarfe' => 0, 'eigenschaften' => 0, 'komponenten' => 0, 'kombinationen' => 0, 'konflikte' => 0, 'offen' => 0];
        $svc = app(AnkerWissenImport::class);
        foreach ($dateien as $datei) {
            $profil = json_decode((string) file_get_contents($datei), true);
            $r = is_array($profil) ? $svc->importiere($profil) : ['status' => 'ungueltig'];
            if ($r['status'] !== 'ok') {
                $summe['abgelehnt']++;
                $this->warn(basename($datei).': '.$r['status']);

                continue;
            }
            $summe['ok']++;
            foreach (['bedarfe', 'eigenschaften', 'komponenten', 'kombinationen', 'konflikte', 'offen'] as $k) {
                $summe[$k] += $r[$k];
            }
        }
        $this->table(['Kennzahl', 'Wert'], collect($summe)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->line('Danach: foodalchemist:anker-wissen kontrast --apply');

        return self::SUCCESS;
    }

    private function kontrast(bool $apply): int
    {
        if (! $apply) {
            $this->line('→ Dry-Run. Mit --apply werden alle abgeleiteten Kontrast-Kanten neu gebaut.');

            return self::SUCCESS;
        }
        $s = app(KontrastAbleitung::class)->baue();
        $this->info("Kontrast-Kanten: {$s['kanten']} aus {$s['bedarfe']} Bedarfen.");

        return self::SUCCESS;
    }

    private function fehler(string $text): int
    {
        $this->error($text);

        return self::FAILURE;
    }
}
