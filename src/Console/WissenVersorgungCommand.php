<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Knowledge\WissensVersorgungService;

/**
 * Spec 52 · A2 — der Versorgungs-Bericht am Terminal.
 *
 * Die Rechnung steht in {@see WissensVersorgungService} — dieses Kommando ist nur die
 * Darstellung. Der zweite Abnehmer ist `foodalchemist.knowledge_versorgung.GET`, und der ist
 * auf demo der einzige Weg: dort gibt es keine Shell (Grundsatz E — alles in der UI
 * einstellbar, alles per MCP bedienbar; ein Kommando allein reicht nicht).
 *
 * **Abgrenzung zu `foodalchemist:wissen-deckung` (W2-4).** Das prüft die KORPUS-Richtung: nennt
 * ein Prompt-Task einen §, muss der Korpus ein Dossier dazu haben (der §12-Fall). Dieser Befehl
 * prüft die VERSORGUNGS-Richtung: erreicht diesen Prompt überhaupt Wissen, und welches. Zwei
 * Richtungen desselben Problems, deshalb zwei Befehle — nicht einer mit zwei Modi.
 *
 * Rein lesend. Exit-Code 1, sobald ungesteuerte Keys existieren, damit ein Scheduler-Lauf
 * sichtbar fehlschlägt statt still durchzulaufen.
 */
class WissenVersorgungCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-versorgung
        {--team=6 : Team-Kontext für die Kanon-Sichtbarkeit (global ∪ Ahnenkette)}
        {--praefix= : nur Keys mit diesem Bereichs-Präfix (z. B. recipe, vk, gp)}
        {--nur-befunde : nur ungesteuerte Keys ausgeben}
        {--json : Maschinenlesbar ausgeben (für Vorher/Nachher-Vergleiche)}';

    protected $description = 'Spec 52/A2: zeigt je Prompt-Key, welches Wissen ihn erreicht (Kanon, Routing, Bindung, Budget)';

    public function handle(WissensVersorgungService $dienst): int
    {
        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            // Ohne Nutzer greift nur die globale Partition — der Kanon sähe fast leer aus und
            // der Bericht behauptete eine Deckungslücke, die es nicht gibt. Lieber abbrechen.
            $this->error('Kein Team #'.$this->option('team').' — ohne Team-Kontext ist die Kanon-Sicht nicht aussagekräftig.');

            return self::FAILURE;
        }

        $praefix = (string) ($this->option('praefix') ?? '');
        $bericht = $dienst->bericht($team, $praefix !== '' ? $praefix : null);

        if ($bericht['keys'] === 0) {
            $this->error('Keine Prompt-Registry-Zeile gefunden'.($praefix !== '' ? " (Präfix «{$praefix}»)" : '').'.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($bericht, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $bericht['ungesteuert'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $ungesteuert = array_values(array_filter($bericht['zeilen'], fn ($z) => $z['verdikt'] === 'UNGESTEUERT'));
        // Alt-Bindungen sind seit Spec 52 · F2 nie mehr Versorgung, nur noch Ballast — als
        // eigene Zeile ausgewiesen, damit man sieht, was noch wegzuräumen ist.
        $altBindungen = array_values(array_filter($bericht['zeilen'], fn ($z) => (int) $z['bindungen'] > 0));
        $ausgabe = $this->option('nur-befunde') ? $ungesteuert : $bericht['zeilen'];

        if ($ausgabe !== []) {
            $this->table(
                ['Prompt-Key', 'Routing-Schlüssel', 'Kanon', 'Routing', 'Alt-Bindung', 'Wissensbudget gesamt', 'Verdikt'],
                array_map(fn ($z) => [
                    $z['prompt_key'],
                    $z['alt_schluessel'] ? $z['routing_key'].' (alt)' : '=',
                    $z['kanon_docs'] > 0 ? $z['kanon_docs'].' · '.number_format($z['kanon_chars'], 0, ',', '.').' Z.' : '–',
                    $z['routing'] === [] ? '–' : implode(', ', array_map(
                        fn ($r) => ($r['art'] ?? $r['category']).':'.$r['mode'].($r['max_docs'] ? ' max. '.$r['max_docs'].' Quellen' : ''),
                        $z['routing']
                    )),
                    $this->bindungsZelle($z),
                    number_format($z['budget_total'], 0, ',', '.'),
                    $z['verdikt'],
                ], $ausgabe)
            );
        }

        $this->newLine();
        $this->info(sprintf(
            'Registry: %d Prompt-Keys%s · gesteuert %d · bewusst none %d · UNGESTEUERT %d',
            $bericht['keys'],
            $praefix !== '' ? " (Präfix «{$praefix}»)" : '',
            $bericht['gesteuert'], $bericht['none'], $bericht['ungesteuert'],
        ));

        $rf = $bericht['routing_features'];
        if ($rf['ohne_aufrufer_gemessen'] !== []) {
            $this->newLine();
            $this->warn('Routing-Features OHNE AUFRUFER (grep-Befund 2026-09-07) — Politik, die nichts steuert:');
            foreach ($rf['ohne_aufrufer_gemessen'] as $f) {
                $this->line("  · {$f}");
            }
            $this->line('  Bei einem `none` ist damit die BEWUSSTE Entscheidung „kein Wissen" unter einem');
            $this->line('  Namen hinterlegt, den niemand ruft — sie existiert, wirkt aber nicht.');
        }
        if ($rf['ohne_prompt_key'] !== []) {
            $this->newLine();
            $this->warn('Routing-Features ohne Prompt-Key — ENTWEDER Ballast ODER ein Aufrufer-Eigenname:');
            foreach ($rf['ohne_prompt_key'] as $f) {
                $this->line("  · {$f}");
            }
            $this->line('  Welches von beidem, steht nur im Code: mehrere Stellen rufen contextFor() mit');
            $this->line('  einer Variablen, das ist statisch nicht entscheidbar. Bekannte Eigennamen stehen');
            $this->line('  in KnowledgeContextService::CONTEXT_ONLY_FEATURES.');
        }
        if ($rf['aufrufer_eigenname'] !== []) {
            $this->line('  (als Aufrufer-Eigenname dokumentiert: '.implode(', ', $rf['aufrufer_eigenname']).')');
        }

        if ($altBindungen !== []) {
            $this->newLine();
            $this->warn('ALT-BINDUNGEN, die niemand mehr liest (Spec 52 · F2) — Ballast, kein Verlust:');
            foreach ($altBindungen as $z) {
                $this->line("  · {$z['prompt_key']} — ".implode(', ', $z['bindungs_slugs']));
            }
            $this->line('  Lösen: `foodalchemist.knowledge.UNBIND` bzw. im Wissens-Browser am Dossier.');
        }

        if ($ungesteuert !== []) {
            $this->newLine();
            $this->error(count($ungesteuert).' Prompt-Key(s) erhalten weder Kanon noch Routing:');
            foreach ($ungesteuert as $z) {
                // Eine Bindung auf ein deaktiviertes Dossier sah früher nach Verdrahtung aus
                // und lieferte nichts — genau so ist beim 155-Originale-Cutover still Wissen
                // verschwunden. Seit F2 liefert KEINE Bindung mehr etwas; die Zeile bleibt,
                // weil so eine Leiche im Browser weiter nach Verdrahtung aussieht.
                $tot = $z['bindungen_tot'] > 0
                    ? " — ACHTUNG: {$z['bindungen_tot']} tot(e) Bindung(en) auf INAKTIVE Dossiers ("
                        .implode(', ', $z['bindungs_slugs']).')'
                    : '';
                $this->line("  · {$z['prompt_key']}{$tot}");
            }
            $this->newLine();
            $this->line('Jede Zeile braucht eine Entscheidung: Kanon-Zeile, Routing-Zeile oder ausdrücklich `none`.');
            $this->line('«ungesteuert» heisst: aus dem Wissens-Korpus erreicht diesen Prompt nichts. Regeln im');
            $this->line('Prompt-TEXT selbst sind davon unberührt — die prüft `foodalchemist:wissen-deckung`.');

            return self::FAILURE;
        }

        $this->info('Kein ungesteuerter Prompt-Key.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $z */
    private function bindungsZelle(array $z): string
    {
        if ((int) $z['bindungen'] === 0) {
            return '–';
        }
        $teile = [(string) $z['bindungen']];
        if ((int) $z['bindungen_tot'] > 0) {
            $teile[] = $z['bindungen_tot'].' tot';
        }
        if ((int) $z['bindungen_stumm'] > 0) {
            $teile[] = $z['bindungen_stumm'].' stumm';
        }
        return count($teile) === 1 ? $teile[0] : $teile[0].' ('.implode(', ', array_slice($teile, 1)).')';
    }
}
