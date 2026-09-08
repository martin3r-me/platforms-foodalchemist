<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\Knowledge\KanonSicherungService;
use Platform\FoodAlchemist\Services\SignalService;

/**
 * Spec 52 · Paket 3 — Kanon sichern, prüfen, zurückspielen.
 *
 * Warum es das braucht und warum kein Seeder: {@see KanonSicherungService}. Hier steht nur
 * die Bedienung; gerechnet wird im Dienst, damit das MCP-Tool dieselbe Antwort gibt.
 *
 *   export  → schreibt den Live-Kanon nach `database/kanon/kanon-team-<id>.json` (ins Repo)
 *   pruefen → hält Datei und Live-Stand gegeneinander, schreibt nichts, Exit ≠ 0 bei Drift
 *   import  → spielt die Datei ein (Vorschau; schreibt erst mit `--apply`)
 */
class WissenKanonSicherungCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-kanon-sicherung
        {richtung : export | pruefen | import}
        {--team=6 : Team-Kontext}
        {--datei= : Pfad (Default: database/kanon/kanon-team-<id>.json im Modul)}
        {--apply : beim Import wirklich schreiben (ohne: Vorschau)}
        {--kein-signal : bei `pruefen` kein Cockpit-Signal erzeugen (für lokale Läufe)}
        {--json : Maschinenlesbar}';

    protected $description = 'Spec 52/Paket 3: Kanon exportieren, gegen die Sicherung prüfen oder zurückspielen';

    public function handle(KanonSicherungService $dienst): int
    {
        $richtung = (string) $this->argument('richtung');
        if (! in_array($richtung, ['export', 'pruefen', 'import'], true)) {
            $this->error('richtung muss export, pruefen oder import sein.');

            return self::FAILURE;
        }

        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            $this->error('Kein Team #'.$this->option('team').'.');

            return self::FAILURE;
        }

        $datei = (string) ($this->option('datei') ?: $dienst->standardDatei((int) $team->id));

        try {
            return match ($richtung) {
                'export' => $this->exportieren($dienst, $team, $datei),
                'pruefen' => $this->pruefen($dienst, $team, $datei),
                default => $this->importieren($dienst, $team, $datei),
            };
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function exportieren(KanonSicherungService $dienst, Team $team, string $datei): int
    {
        $inhalt = $dienst->inhalt($team);
        if ($inhalt['zeilen'] === []) {
            // Kein stiller Erfolg: eine leere Sicherung würde beim nächsten Neuaufbau
            // „nichts wiederherzustellen" behaupten, statt den fehlenden Kanon zu melden.
            $this->error('Kein Kanon zu sichern — das ist selbst der Befund.');

            return self::FAILURE;
        }

        $ordner = dirname($datei);
        if (! is_dir($ordner) && ! @mkdir($ordner, 0o775, true) && ! is_dir($ordner)) {
            $this->error('Ordner nicht anlegbar: '.$ordner);

            return self::FAILURE;
        }
        file_put_contents($datei, json_encode($inhalt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        if ($this->option('json')) {
            $this->line((string) json_encode(['datei' => $datei, 'zeilen' => count($inhalt['zeilen'])], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(count($inhalt['zeilen']).' Kanon-Zeilen gesichert → '.$datei);
        $this->line('Die Datei gehört ins Repo — sie ist der Wiederherstellungs-Pfad.');

        return self::SUCCESS;
    }

    private function pruefen(KanonSicherungService $dienst, Team $team, string $datei): int
    {
        $abgleich = $dienst->abgleich($team, $dienst->lade($datei));
        if (! $this->option('kein-signal')) {
            $this->melde($team, $abgleich);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($abgleich, JSON_UNESCAPED_UNICODE));

            return $abgleich['deckungsgleich'] && $abgleich['ohne_dossier'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf('Sicherung: %d Zeilen · Live: %d Zeilen · Datei: %s',
            $abgleich['datei_zeilen'], $abgleich['live_zeilen'], $datei));

        if ($abgleich['deckungsgleich'] && $abgleich['ohne_dossier'] === []) {
            $this->info('Deckungsgleich — der Kanon ist gesichert.');

            return self::SUCCESS;
        }

        $this->liste('NUR LIVE (kuratiert, nicht gesichert → beim Neuaufbau weg)', $abgleich['nur_live']);
        $this->liste('NUR IN DER DATEI (live entfernt oder verloren)', $abgleich['nur_datei']);
        $this->liste('OHNE DOSSIER (Slug existiert hier nicht → käme nicht zurück)', $abgleich['ohne_dossier']);

        if ($abgleich['abweichend'] !== []) {
            $this->newLine();
            $this->warn(count($abgleich['abweichend']).' Zeile(n) mit abweichenden Werten:');
            foreach (array_slice($abgleich['abweichend'], 0, 20) as $a) {
                $felder = [];
                foreach ($a['felder'] as $f => $w) {
                    $felder[] = sprintf('%s: Datei %s ≠ Live %s', $f, var_export($w['datei'], true), var_export($w['live'], true));
                }
                $this->line('  · '.$a['zeile'].' — '.implode(' · ', $felder));
            }
        }

        $this->newLine();
        $this->line('Aufräumen: `export` schreibt den Live-Stand in die Datei, `import --apply` den umgekehrten Weg.');

        return self::FAILURE;
    }

    private function importieren(KanonSicherungService $dienst, Team $team, string $datei): int
    {
        $apply = (bool) $this->option('apply');
        $ergebnis = $dienst->spielEin($team, $dienst->lade($datei), $apply);

        if ($this->option('json')) {
            $this->line((string) json_encode($ergebnis + ['apply' => $apply], JSON_UNESCAPED_UNICODE));

            return $ergebnis['fehlend'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info(($apply ? 'Geschrieben' : 'Vorschau').': '.$ergebnis['geschrieben'].' Zeile(n)');

        foreach ($ergebnis['hinweise'] as $h) {
            $this->line('  ⚠ '.$h);
        }

        if ($ergebnis['fehlend'] !== []) {
            $this->newLine();
            $this->warn(count($ergebnis['fehlend']).' Slug(s) ohne Dossier — der Korpus ist nicht (mehr) deckungsgleich:');
            foreach (array_slice($ergebnis['fehlend'], 0, 20) as $s) {
                $this->line('  · '.$s);
            }
            if (count($ergebnis['fehlend']) > 20) {
                $this->line('  … und '.(count($ergebnis['fehlend']) - 20).' weitere');
            }
            $this->line('  Diese Verdrahtungen fehlen. Wurden die Dossiers neu geschnitten,');
            $this->line('  nennt `knowledge_links.SET` mit art=ersetzt den Nachfolger.');
        }

        if (! $apply) {
            $this->newLine();
            $this->line('Nichts geschrieben — mit --apply ausführen.');
        }

        return $ergebnis['fehlend'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Drift ins Signale-Cockpit, nicht ins Log.
     *
     * Gleiche Begründung wie beim Steuerdaten-Wächter: ein Kanon, der nur in einer DB lebt,
     * erzeugt beim Verlust KEINEN Fehler — der Generator läuft weiter und liefert schlechtere
     * Rezepte. Ein Scheduler-Lauf, der das nach `laravel.log` schreibt, wäre technisch
     * vorhanden und faktisch unsichtbar.
     *
     * Bewusst derselbe `SignalTyp::SteuerdatenDrift` und ein EIGENER `dedup_key`: fachlich ist
     * es dieselbe Aussage („die Steuerung weicht vom Soll ab"), aber die beiden Wächter dürfen
     * sich ihr Signal nicht gegenseitig überschreiben.
     *
     * @param  array<string, mixed>  $abgleich
     */
    private function melde(Team $team, array $abgleich): void
    {
        $signale = app(SignalService::class);

        // Schwere über `nur_datei_aufloesbar`, NICHT über `nur_datei`: `ohne_dossier` steckt
        // zwangsläufig immer auch in `nur_datei`, die Unterscheidung wäre sonst keine.
        $ohneDossier = $abgleich['ohne_dossier'];
        $verlierbar = count($abgleich['nur_live']) + count($abgleich['nur_datei_aufloesbar']) + count($abgleich['abweichend']);
        $unaufloesbar = count($ohneDossier);

        if ($verlierbar === 0 && $unaufloesbar === 0) {
            $signale->schliesseGemessen(
                $team, SignalTyp::SteuerdatenDrift, 'wissen-kanon-sicherung', 'wissen-kanon-sicherung',
                'Kanon und Sicherung sind wieder deckungsgleich — automatisch geschlossen',
            );

            return;
        }

        // Nur unauflösbare Slugs ist INFO, nicht Warnung: nach einem Korpus-Neuschnitt ist das
        // der erwartete Zustand, bis die Nachfolger stehen. Als Warnung geführt würde der
        // Riegel während des Umbaus wöchentlich feuern und dabei abstumpfen.
        $schwere = $verlierbar > 0 ? SignalSeverity::Warnung : SignalSeverity::Info;
        $titel = $verlierbar > 0
            ? $verlierbar.' Kanon-Zeile(n) weichen von der Sicherung ab'
            : $unaufloesbar.' gesicherte Kanon-Zeile(n) zeigen ins Leere';

        $zeilen = [];
        foreach ([[$abgleich['nur_live'], 'nur live, NICHT gesichert (bei einem Neuaufbau weg)'],
            [$abgleich['nur_datei_aufloesbar'], 'nur in der Sicherung, live entfernt — das Dossier gibt es noch'],
            [$ohneDossier, 'Slug existiert hier nicht (Neuschnitt?) — käme nicht zurück']] as [$werte, $text]) {
            if ($werte !== []) {
                $zeilen[] = count($werte).' × '.$text.': '.implode(', ', array_slice($werte, 0, 5))
                    .(count($werte) > 5 ? ' …' : '');
            }
        }
        if ($abgleich['abweichend'] !== []) {
            $zeilen[] = count($abgleich['abweichend']).' × abweichende Werte (mode/ord/active)';
        }

        $signale->erzeuge($team, SignalTyp::SteuerdatenDrift, $schwere, $titel, [
            'dedup_key' => 'wissen-kanon-sicherung',
            'source' => 'wissen-kanon-sicherung',
            'description' => "Der Kanon existiert nur als Zeilen in dieser Datenbank. Was hier nicht "
                . "gesichert ist, ist bei einem Neuaufbau weg — ohne Fehlermeldung, der Generator "
                . "läuft dann nur ohne Regelwerk.\n\n· ".implode("\n· ", $zeilen)
                . "\n\nBeheben: `php artisan foodalchemist:wissen-kanon-sicherung export --team={$team->id}` "
                . 'und die Datei committen. Zeigen Slugs ins Leere, erst die Nachfolger benennen '
                . '(`knowledge_links.SET` mit art=ersetzt), dann neu exportieren.',
            'payload' => $abgleich,
        ]);
    }

    /** @param  list<string>  $eintraege */
    private function liste(string $titel, array $eintraege): void
    {
        if ($eintraege === []) {
            return;
        }
        $this->newLine();
        $this->warn(count($eintraege).' × '.$titel.':');
        foreach (array_slice($eintraege, 0, 20) as $e) {
            $this->line('  · '.$e);
        }
        if (count($eintraege) > 20) {
            $this->line('  … und '.(count($eintraege) - 20).' weitere');
        }
    }
}
