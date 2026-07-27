<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\FileArticleImportService;

/**
 * Spec 13 · S1a/S1b/S1c — Kanal B: Artikel-Datei eines Lieferanten einlesen.
 *
 * Bewusst **dry-run als Default**: ein Import berührt den Katalog, aus dem jede
 * Kalkulation ihren EK zieht. Geschrieben wird nur mit `--apply`; die Ausgabe ist
 * in beiden Modi dieselbe, damit der Trockenlauf eine echte Vorschau ist und nicht
 * eine andere Codebahn.
 *
 * Resumefähig ohne Zustand: der Upsert ist idempotent (leere Zelle ändert nichts,
 * unveränderte Zeile bleibt unangetastet) — ein abgebrochener Lauf wird durch
 * erneutes Ausführen derselben Datei fortgesetzt.
 *
 * Vorlage + Spalten-Liste: docs/IMPORT_Kanal_B_Artikel_Vorlage.md
 */
class ImportArticlesCommand extends Command
{
    protected $signature = 'foodalchemist:import-articles
        {--file= : Pfad zur CSV/TSV-Datei (eine Zeile je Artikel)}
        {--supplier= : Lieferant (ID) — eine Datei = ein Lieferant}
        {--team= : Besitzer-Team des Imports (ID)}
        {--dry-run : nur prüfen und berichten (Default)}
        {--apply : wirklich schreiben}
        {--zeilen=25 : höchstens so viele Zeilen-Befunde ausgeben}';

    protected $description = 'Kanal B (Spec 13): Lieferanten-Artikel + EK + Nährwerte/Allergene/Zusatzstoffe aus einer CSV-Datei upserten, inkl. Recompute-/Signal-Kette (Lieferbedingungen folgen in S2).';

    public function handle(FileArticleImportService $import): int
    {
        $pfad = (string) $this->option('file');
        if ($pfad === '') {
            $this->error('--file fehlt.');

            return self::FAILURE;
        }
        $team = Team::find((int) $this->option('team'));
        if (! $team) {
            $this->error('--team=ID fehlt oder existiert nicht.');

            return self::FAILURE;
        }
        $supplierId = (int) $this->option('supplier');
        if ($supplierId <= 0) {
            $this->error('--supplier=ID fehlt. Sichtbare Lieferanten: '
                . FoodAlchemistSupplier::visibleToTeam($team)->orderBy('name')->limit(20)
                    ->get(['id', 'name'])->map(fn ($s) => "#{$s->id} {$s->name}")->implode(', '));

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('TROCKENLAUF — es wird nichts geschrieben (mit --apply scharf stellen).');
        }

        try {
            $runId = $apply ? $import->starteRun($team->id, 0) : null;
            $bericht = $import->importiere($team, $supplierId, $pfad, $apply);
            if ($runId !== null) {
                $import->beendeRun($runId, $bericht);
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("── {$bericht['datei']} → {$bericht['lieferant']} (Team {$team->id}) · {$bericht['zeilen']} Zeile(n)");
        $this->line('   Spalten erkannt: ' . implode(', ', $bericht['spalten']['erkannt']));
        foreach ($bericht['hinweise'] as $h) {
            $this->warn('   ! ' . $h);
        }

        $max = max(1, (int) $this->option('zeilen'));
        $gezeigt = 0;
        foreach ($bericht['befunde'] as $b) {
            $preisEreignis = isset($b['preis']) && $b['preis']['status'] !== 'unveraendert';
            $detailEreignis = array_filter($b['details'] ?? []) !== [];
            if ($b['status'] === 'unveraendert' && ! $preisEreignis && ! $detailEreignis) {
                continue; // Rauschen: der Normalfall eines Wiederholungslaufs
            }
            if ($gezeigt++ >= $max) {
                break;
            }
            $zeile = "   Z{$b['zeile']} [{$b['status']}] {$b['artikel']} {$b['bezeichnung']}";
            if (isset($b['felder']) && $b['felder'] !== []) {
                $zeile .= ' · ' . implode(',', $b['felder']);
            }
            if (isset($b['grund'])) {
                $zeile .= ' · ' . $b['grund'];
            }
            $b['status'] === 'fehler' ? $this->warn($zeile) : $this->line($zeile);
            if (isset($b['preis'])) {
                $p = $b['preis'];
                $text = match ($p['status']) {
                    'neu' => 'Preis neu: ' . number_format((float) $p['neu'], 2, ',', '.') . ' €',
                    // ASCII-Pfeil mit Absicht: der Unicode-Pfeil kam in der Konsolen-
                    // Ausgabe nicht durch (die € daneben schon) — ein verschluckter
                    // Pfeil las sich wie „37,99 99,99" und damit wie zwei Preise.
                    'geaendert' => 'Preis ' . number_format((float) $p['alt'], 2, ',', '.') . ' EUR -> '
                        . number_format((float) $p['neu'], 2, ',', '.') . ' EUR',
                    'fehler' => 'Preis NICHT gesetzt: ' . $p['grund'],
                    default => null,
                };
                if ($text !== null) {
                    $p['status'] === 'fehler' ? $this->warn("      € {$text}") : $this->line("      € {$text}");
                }
            }
            foreach (['naehrwerte' => 'Nährwerte', 'allergene' => 'Allergene', 'zusatzstoffe' => 'Zusatzstoffe'] as $block => $label) {
                $felder = $b['details'][$block] ?? [];
                if ($felder !== []) {
                    $this->line("      ⊞ {$label}: " . implode(', ', $felder));
                }
            }
            foreach ($b['warnungen'] ?? [] as $w) {
                $this->line("      ⚠ {$w}");
            }
        }

        $this->info("   → neu {$bericht['neu']} · aktualisiert {$bericht['aktualisiert']}"
            . " · unverändert {$bericht['unveraendert']} · übersprungen {$bericht['uebersprungen']}"
            . " · Fehler {$bericht['fehler']}" . ($apply ? '' : ' (nichts geschrieben)'));

        $pr = $bericht['preise'];
        if (($pr['neu'] + $pr['geaendert'] + $pr['unveraendert'] + $pr['fehler']) > 0) {
            $this->info("   € Preise: neu {$pr['neu']} · geändert {$pr['geaendert']}"
                . " · unverändert {$pr['unveraendert']} · Fehler {$pr['fehler']}");
        }

        $d = $bericht['details'];
        if (($d['naehrwerte'] + $d['allergene'] + $d['zusatzstoffe']) > 0) {
            $this->info("   ⊞ Detail-Blöcke (Zeilen): Nährwerte {$d['naehrwerte']}"
                . " · Allergene {$d['allergene']} · Zusatzstoffe {$d['zusatzstoffe']}");
        }

        // E4: die Kette ist der DoD-Kern — sie wird IMMER berichtet, auch wenn sie
        // nichts getroffen hat. „0 Rezepte" ist eine Aussage, eine fehlende Zeile nicht.
        $k = $bericht['kette'];
        if ($k['gps'] > 0 || $k['rezepte'] > 0) {
            $this->info($apply
                ? "   ⛓ Kette: {$k['gps']} GP · {$k['rezepte']} Rezept(e) betroffen · {$k['neu_berechnet']} neu berechnet · {$k['signale']} Preis-Signal(e)"
                : "   ⛓ Kette (Vorschau): {$k['gps']} GP · {$k['rezepte']} Rezept(e) würden neu berechnet");
            if ($k['abgeschnitten']) {
                $this->warn('   ! Kette bei ' . FileArticleImportService::MAX_RECOMPUTE
                    . ' Rezepten abgeschnitten — Rest mit `php artisan foodalchemist:recompute` nachziehen.');
            }
        } elseif ($k['bewegt'] > 0) {
            $this->line("   ⛓ Kette: kein GP an den {$k['bewegt']} bewegten Artikel(n) — der EK steht, kostet aber noch kein Rezept.");
        } elseif ($pr['neu'] > 0) {
            // Trockenlauf auf lauter neuen Artikeln: es gibt noch keine IDs, gegen die
            // man eine Kette auflösen könnte. Das ist keine leere Kette, sondern eine
            // unbestimmbare — der Unterschied gehört gesagt.
            $this->line('   ⛓ Kette: noch nicht bestimmbar (neue Artikel haben vor dem scharfen Lauf keine ID).');
        }

        if (! $apply && ($bericht['neu'] + $bericht['aktualisiert']) > 0) {
            $this->line('   Vor dem scharfen Lauf: DB-Backup ziehen, dann denselben Aufruf mit --apply.');
        }

        return ($bericht['fehler'] + $pr['fehler']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
