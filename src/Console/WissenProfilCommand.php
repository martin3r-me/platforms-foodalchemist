<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Knowledge\WissensProfilService;

/**
 * Spec 52 · C0 + D6 — das aufgelöste Regelprofil je Prompt-Key, mit Fingerabdruck und Prüfung.
 *
 * **Warum es das gibt, obwohl `wissen-versorgung` (A2) schon zählt:** A2 beantwortet
 * „erreicht diesen Prompt überhaupt Wissen". Dieses Kommando beantwortet „ist das, was
 * hinterlegt ist, auch **auflösbar**" — und das ist ein anderer Zustand. Eine Kanon-Zeile auf
 * ein deaktiviertes Dossier zählt in A2 als gesteuert und liefert trotzdem nichts.
 *
 * **Der Anlass ist konkret:** Dominique baut den Korpus inhaltlich neu. Der Kanon hängt an
 * `knowledge_document_id` — Umbenennen ist harmlos, **Löschen und Neuanlegen nicht**. Ohne
 * diesen Wächter liefe der Umbau in dieselbe stille Falle wie der 155-Originale-Cutover.
 *
 * Rein lesend. Exit 1 bei blockierenden Befunden, damit ein Scheduler-Lauf sichtbar fehlschlägt.
 */
class WissenProfilCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-profil
        {--team=6 : Team-Kontext für die Kanon-Sichtbarkeit}
        {--key= : nur dieses eine Profil zeigen (voller Prompt-Key)}
        {--praefix= : nur Keys dieses Bereichs}
        {--pruefen : nur Befunde ausgeben (Wächter-Modus)}
        {--json : maschinenlesbar}';

    protected $description = 'Spec 52/C0+D6: aufgelöstes Regelprofil je Prompt-Key — Pflichtquellen, Fingerabdruck, Befunde';

    public function handle(WissensProfilService $dienst): int
    {
        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            $this->error('Kein Team #'.$this->option('team').' — ohne Team-Kontext ist die Kanon-Sicht nicht aussagekräftig.');

            return self::FAILURE;
        }

        $key = trim((string) ($this->option('key') ?? ''));
        if ($key !== '') {
            if (! array_key_exists($key, (array) config('foodalchemist.prompts', []))) {
                $this->error('Unbekannter Prompt-Key «'.$key.'».');

                return self::FAILURE;
            }
            $profil = $dienst->profil($key, $team);
            $this->einzeln($profil);

            return $this->hatBlockierendes([$profil]) ? self::FAILURE : self::SUCCESS;
        }

        $praefix = trim((string) ($this->option('praefix') ?? ''));
        $bericht = $dienst->integritaet($team, $praefix !== '' ? $praefix : null);

        if ($this->option('json')) {
            $this->line((string) json_encode($bericht, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $bericht['blockierend'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $mitBefund = array_values(array_filter($bericht['profile'], fn ($p) => $p['befunde'] !== []));

        if (! $this->option('pruefen')) {
            $this->table(
                ['Prompt-Key', 'Zustand', 'Pflicht', 'wenn_platz', 'Σ Pflicht / Budget', 'Fingerabdruck'],
                array_map(fn ($p) => [
                    $p['prompt_key'],
                    $p['zustand'],
                    count($p['pflicht']) ?: '–',
                    count($p['wenn_platz']) ?: '–',
                    number_format($p['pflicht_zeichen'], 0, ',', '.').' / '.number_format($p['budget_total'], 0, ',', '.'),
                    $p['fingerabdruck'],
                ], $bericht['profile'])
            );
        }

        $this->newLine();
        $this->info(sprintf(
            '%d Prompt-Keys%s · gesteuert %d · bewusst leer %d · ungesteuert %d · FEHLERHAFT %d',
            $bericht['keys'],
            $praefix !== '' ? " (Präfix «{$praefix}»)" : '',
            $bericht['gesteuert'], $bericht['bewusst_leer'], $bericht['ungesteuert'], $bericht['fehlerhaft'],
        ));

        if (($bericht['datenwerk_ohne_achse'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Als `datenwerk` deklariert, aber an KEINER Achse — liegt damit im Suchtopf statt aufgeloest zu werden:');
            foreach ($bericht['datenwerk_ohne_achse'] as $slug) {
                $this->line("  · {$slug}");
            }
            $this->line('  Verdrahten: knowledge_canon.PUT mit scope=achse, scope_key=<achse>:<wert>.');
        }

        foreach ($mitBefund as $p) {
            $this->newLine();
            $blockiert = array_filter($p['befunde'], fn ($b) => $b['schwere'] === 'blockiert') !== [];
            $blockiert ? $this->error($p['prompt_key'].':') : $this->warn($p['prompt_key'].':');
            foreach ($p['befunde'] as $b) {
                $marke = $b['schwere'] === 'blockiert' ? '✗' : '·';
                $this->line("  {$marke} [{$b['code']}] ".($b['slug'] !== null ? $b['slug'].' — ' : '').$b['text']);
            }
        }

        if ($bericht['blockierend'] > 0) {
            $this->newLine();
            $this->error($bericht['blockierend'].' Prompt-Key(s) mit blockierendem Befund — Steuerung und Prompt stimmen nicht ueberein.');

            return self::FAILURE;
        }

        if ($mitBefund === []) {
            $this->info('Keine Befunde.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $p */
    private function einzeln(array $p): void
    {
        $this->info($p['prompt_key'].'  ·  Zustand: '.$p['zustand'].'  ·  Fingerabdruck: '.$p['fingerabdruck']);
        if ($p['alt_schluessel']) {
            $this->line('  Routing läuft unter dem Alt-Schlüssel «'.$p['routing_key'].'» — ein Call, zwei Identitäten.');
        }
        $this->newLine();

        $this->line('<comment>Pflicht</comment> ('.number_format($p['pflicht_zeichen'], 0, ',', '.').' Z. von '
            .number_format($p['budget_total'], 0, ',', '.').' Budget):');
        foreach ($p['pflicht'] as $d) {
            $this->line('  · '.$d['slug'].' @v'.$d['version'].'  ('.number_format($d['zeichen'], 0, ',', '.').' Z.)');
        }
        if ($p['pflicht'] === []) {
            $this->line('  –');
        }

        if ($p['wenn_platz'] !== []) {
            $this->newLine();
            $this->line('<comment>wenn_platz</comment>:');
            foreach ($p['wenn_platz'] as $d) {
                $this->line('  · '.$d['slug'].' @v'.$d['version'].'  ('.number_format($d['zeichen'], 0, ',', '.').' Z.)');
            }
        }

        $this->newLine();
        $this->line('<comment>Suche</comment> (Routing auf «'.$p['routing_key'].'», Budget '
            .number_format($p['budget_total'], 0, ',', '.').' Z.):');
        foreach ($p['routing'] as $r) {
            $this->line('  · '.($r['art'] ?? $r['category']).' → '.$r['mode']
                .($r['max_docs'] ? ' (max. '.$r['max_docs'].' Quellen)' : ''));
        }
        if ($p['routing'] === []) {
            $this->line('  –');
        }

        foreach ($p['befunde'] as $b) {
            $this->newLine();
            $marke = $b['schwere'] === 'blockiert' ? '✗' : '·';
            $b['schwere'] === 'blockiert'
                ? $this->error("{$marke} [{$b['code']}] ".($b['slug'] !== null ? $b['slug'].' — ' : '').$b['text'])
                : $this->warn("{$marke} [{$b['code']}] ".($b['slug'] !== null ? $b['slug'].' — ' : '').$b['text']);
        }
    }

    /** @param list<array<string, mixed>> $profile */
    private function hatBlockierendes(array $profile): bool
    {
        foreach ($profile as $p) {
            if (array_filter($p['befunde'], fn ($b) => $b['schwere'] === 'blockiert') !== []) {
                return true;
            }
        }

        return false;
    }
}
