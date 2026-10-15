<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Pairing\AnkerNaehrwerte;
use Platform\FoodAlchemist\Services\Pairing\AnkerVarianten;
use Platform\FoodAlchemist\Services\Pairing\AnkerWissenAuslese;
use Platform\FoodAlchemist\Services\Pairing\AnkerWissenImport;
use Platform\FoodAlchemist\Services\Pairing\KategorieRegeln;
use Platform\FoodAlchemist\Services\Pairing\KontrastAbleitung;

/**
 * Spec 60 · P4: Anker-Wissen pflegen — drei Schritte, jeder für sich wiederholbar.
 *
 *   php artisan foodalchemist:anker-wissen varianten            Grundname/Verfahren/Grund-Anker aus den Inspire-Namen
 *   php artisan foodalchemist:anker-wissen import --pfad=DIR    Profile (*.json, Format der Pilot-Auslese) übernehmen
 *   php artisan foodalchemist:anker-wissen kategorie            Lieferseite Träger/Frische/Aromatik/Röstaroma aus Kategorie + Verfahren
 *   php artisan foodalchemist:anker-wissen naehrwert            Lieferseite Salz/Süße/Fett aus den Nährwerten der Kern-GPs
 *   php artisan foodalchemist:anker-wissen intensitaet          Startwerte der Aroma-Intensität je Kategorie (nur leere)
 *   php artisan foodalchemist:anker-wissen auslesen --team=ID   Dossiers per KI lesen → Profile als Entwurf (Prompt anker.wissen_auslese)
 *   php artisan foodalchemist:anker-wissen kontrast             Kontrast-Kanten aus Bedarf × Eigenschaft neu bauen
 *
 * Reihenfolge bei einem Neuaufbau: varianten → import (oder auslesen) → kategorie → naehrwert → intensitaet → kontrast
 * (danach `foodalchemist:rezept-profile --alle`, damit die Profile die neuen Eigenschaften tragen).
 *
 * Default ist Dry-Run für `varianten`; `import` und `kontrast` schreiben nur mit --apply.
 */
class AnkerWissenCommand extends Command
{
    protected $signature = 'foodalchemist:anker-wissen
        {schritt : varianten | import | auslesen | kategorie | naehrwert | intensitaet | kontrast}
        {--pfad= : Ordner mit Profil-JSON-Dateien (import)}
        {--team= : Team-ID für den KI-Zugang (auslesen)}
        {--anker= : nur diesen Anker (Anker-ID, auslesen)}
        {--limit=0 : höchstens so viele Anker (0 = alle, auslesen)}
        {--sleep=0 : Sekunden Pause zwischen KI-Aufrufen (auslesen)}
        {--apply : wirklich schreiben}';

    protected $description = 'Spec 60: Anker-Varianten ableiten, Dossier-Wissen importieren, Kontrast-Kanten bauen';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        return match ((string) $this->argument('schritt')) {
            'varianten' => $this->varianten($apply),
            'import' => $this->import($apply),
            'auslesen' => $this->auslesen($apply),
            'kategorie' => $this->regel($apply, fn () => app(KategorieRegeln::class)->eigenschaften(), 'Eigenschaften aus Kategorie/Verfahren'),
            'naehrwert' => $this->regel($apply, function () {
                $r = app(AnkerNaehrwerte::class)->ableiten();

                return $r['zeilen'].' Eigenschaften an '.$r['anker'].' Ankern';
            }, 'Salz/Süße/Fett aus Nährwerten'),
            'intensitaet' => $this->regel($apply, fn () => app(KategorieRegeln::class)->intensitaet(), 'Anker mit Startwert Aroma-Intensität'),
            'kontrast' => $this->kontrast($apply),
            default => $this->fehler('Schritt unbekannt. Erlaubt: varianten, import, auslesen, kategorie, naehrwert, intensitaet, kontrast.'),
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
        $summe = ['ok' => 0, 'abgelehnt' => 0, 'bedarfe' => 0, 'eigenschaften' => 0, 'komponenten' => 0, 'kombinationen' => 0, 'gruppen_klassiker' => 0, 'konflikte' => 0, 'hinweise' => 0, 'offen' => 0];
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
            foreach (['bedarfe', 'eigenschaften', 'komponenten', 'kombinationen', 'gruppen_klassiker', 'konflikte', 'hinweise', 'offen'] as $k) {
                $summe[$k] += $r[$k];
            }
        }
        $this->table(['Kennzahl', 'Wert'], collect($summe)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->line('Danach: foodalchemist:anker-wissen kontrast --apply');

        return self::SUCCESS;
    }

    /**
     * Dossiers per KI lesen. Ohne --apply: nur Scope zählen (kein KI-Aufruf, keine Kosten).
     * Mit --apply: je Anker lesen, absichern und als Entwurf übernehmen. Ein Ausfall bricht den Lauf nicht ab;
     * der Anker bleibt im Scope und kommt beim nächsten Lauf wieder.
     */
    private function auslesen(bool $apply): int
    {
        $svc = app(AnkerWissenAuslese::class);
        $kandidaten = $svc->kandidaten($this->option('anker') !== null ? (int) $this->option('anker') : null, (int) $this->option('limit'));
        $this->info(($apply ? 'LESE' : 'DRY-RUN').' — '.$kandidaten->count().' Anker ohne Dossier-Wissen');
        if (! $apply) {
            $this->line('Beispiele: '.$kandidaten->take(8)->pluck('name')->implode(', '));
            $this->line('→ Mit --team=ID --apply lesen (KI-Aufrufe, Kosten über das Team-Budget).');

            return self::SUCCESS;
        }
        $team = (int) $this->option('team') > 0 ? \Platform\Core\Models\Team::find((int) $this->option('team')) : null;
        $nutzer = $team?->users()->first();
        if ($team === null || $nutzer === null) {
            return $this->fehler('--team=<id> mit mindestens einem Nutzer ist Pflicht (KI-Gateway braucht Team-Kontext und Kill-Switch).');
        }
        \Illuminate\Support\Facades\Auth::login($nutzer);

        $summe = ['gelesen' => 0, 'leer' => 0, 'fehler' => 0, 'bedarfe' => 0, 'verworfen' => 0, 'eigenschaften' => 0, 'komponenten' => 0, 'kombinationen' => 0, 'konflikte' => 0, 'offen' => 0];
        $bar = $this->output->createProgressBar($kandidaten->count());
        foreach ($kandidaten as $k) {
            try {
                $r = $svc->lese((int) $k->id, true);
            } catch (\Throwable $e) {
                $summe['fehler']++;
                $this->newLine();
                $this->warn("{$k->slug}: ".mb_substr($e->getMessage(), 0, 140));
                if ($e instanceof \Platform\FoodAlchemist\Exceptions\KiBudgetErschoepftException
                    || $e instanceof \Platform\FoodAlchemist\Exceptions\KiDeaktiviertException) {
                    break;   // jeder weitere Aufruf scheitert gleich — Lauf sauber beenden
                }
                $bar->advance();

                continue;
            }
            if ($r['importiert'] === null || ($r['importiert']['status'] ?? '') !== 'ok') {
                $summe['leer']++;
            } else {
                $summe['gelesen']++;
                foreach (['bedarfe', 'eigenschaften', 'komponenten', 'kombinationen', 'konflikte', 'offen'] as $f) {
                    $summe[$f] += $r['importiert'][$f];
                }
            }
            $summe['verworfen'] += $r['verworfen'];
            $bar->advance();
            if ((int) $this->option('sleep') > 0) {
                sleep((int) $this->option('sleep'));
            }
        }
        $bar->finish();
        $this->newLine(2);
        $this->table(['Kennzahl', 'Wert'], collect($summe)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->line('verworfen = Bedarfe ohne Beleg im Dossier. Danach: foodalchemist:anker-wissen kontrast --apply');

        return self::SUCCESS;
    }

    private function regel(bool $apply, \Closure $lauf, string $was): int
    {
        if (! $apply) {
            $this->line('→ Dry-Run. Mit --apply schreiben.');

            return self::SUCCESS;
        }
        $this->info($was.': '.$lauf());

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
