<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * W2-4: §-DECKUNG prüfen — nennt ein Prompt einen Paragraphen, muss der Korpus ihn haben.
 *
 * Anlass ist ein echter, teuer gefundener Fehler: der Generator-Task kündigte „§12
 * Zutaten-/Komponenten-Reihenfolge" an, und im Modul existierte KEIN Dossier, das §12
 * enthielt — der §-Dossier-Split (2026-08-27) hatte ihn verloren. Das Modell wurde also auf
 * eine Regel verwiesen, die es nie zu lesen bekam. Kein Test schlug an, weil Prompt-Texte
 * und Korpus-Inhalte nirgends gegeneinander gehalten wurden.
 *
 * Der Befehl prüft EXISTENZ, nicht Bedeutung — mehr kann eine Maschine hier nicht leisten.
 * Aber Existenz ist genau das, was gefehlt hat.
 *
 * Zweite Richtung mit dazu: Dossiers, die KEIN Prompt je erwähnt. Das ist kein Fehler
 * (Retrieval findet sie semantisch), aber für das Token-Programm die interessante Frage,
 * welches Wissen nur Gewicht ist.
 */
class WissenDeckungCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-deckung
        {--team= : Sichtbarkeit aus Sicht dieses Teams (ohne Angabe: der ganze Korpus)}
        {--fail-on-gap : Exit-Code 1 bei Lücken (für CI/Runbooks)}';

    protected $description = 'Prüft, ob jeder in Prompts genannte § im aktiven Regelwerk-Korpus vorkommt';

    public function handle(): int
    {
        $prompts = (array) config('foodalchemist.prompts', []);
        if ($prompts === []) {
            $this->error('Keine Prompt-Registry gefunden.');

            return self::FAILURE;
        }

        // 1. §-Nennungen je Prompt-Key aus task + system einsammeln.
        $genannt = [];
        foreach ($prompts as $key => $p) {
            $text = trim((string) ($p['task'] ?? '') . ' ' . (string) ($p['system'] ?? ''));
            if ($text === '') {
                continue;
            }
            // §12, §8.3, § 6.1 — die Schreibweisen im Bestand sind uneinheitlich.
            preg_match_all('/§\s?(\d+(?:\.\d+)*)/u', $text, $m);
            $nummern = array_values(array_unique($m[1] ?? []));
            if ($nummern !== []) {
                sort($nummern, SORT_NATURAL);
                $genannt[$key] = $nummern;
            }
        }

        if ($genannt === []) {
            $this->info('Kein Prompt nennt einen § — nichts zu prüfen.');

            return self::SUCCESS;
        }

        // 2. Aktiver Regelwerk-Korpus.
        $q = DB::table('foodalchemist_knowledge_documents')
            ->where('category', 'regelwerk')->where('active', 1)->whereNull('deleted_at');
        if (($team = $this->option('team')) !== null && $team !== '') {
            $q->where(fn ($w) => $w->whereNull('team_id')->orWhere('team_id', (int) $team));
        }
        $docs = $q->get(['slug', 'title', 'content_md']);
        if ($docs->isEmpty()) {
            $this->error('Kein aktives Regelwerk-Dossier — jede §-Nennung wäre eine Lücke.');

            return self::FAILURE;
        }

        // 3. Welche §-Nummern KOMMEN im Korpus vor? Zwei Signale, beide nötig:
        //    (a) `§n` im Text — der Paragraph wird wirklich behandelt;
        //    (b) Nummer im Slug/Titel — die gesplitteten Dossiers tragen sie dort,
        //        nicht mehr als `## §n` im Körper.
        $imKorpus = [];
        foreach ($docs as $d) {
            preg_match_all('/§\s?(\d+(?:\.\d+)*)/u', (string) $d->content_md, $m);
            foreach ($m[1] ?? [] as $n) {
                $imKorpus[$n][] = $d->slug;
            }
            preg_match_all('/(?<![\d.])(\d+(?:[-.]\d+)*)(?![\d.])/', $d->slug . ' ' . $d->title, $sm);
            foreach ($sm[1] ?? [] as $n) {
                $imKorpus[str_replace('-', '.', $n)][] = $d->slug;
            }
        }

        $luecken = [];
        $this->line('');
        $this->line('§-NENNUNGEN JE PROMPT');
        foreach ($genannt as $key => $nummern) {
            $fehlt = array_values(array_filter($nummern, fn ($n) => ! isset($imKorpus[$n])));
            $status = $fehlt === [] ? 'ok' : 'LÜCKE: §' . implode(', §', $fehlt);
            $this->line(sprintf('  %-34s §%-28s %s', $key, implode(' §', $nummern), $status));
            foreach ($fehlt as $n) {
                $luecken[$n][] = $key;
            }
        }

        $this->line('');
        if ($luecken === []) {
            $this->info('✓ Jeder genannte § kommt im aktiven Regelwerk-Korpus vor.');
        } else {
            $this->warn('LÜCKEN — diese § werden Prompts versprochen, stehen aber in keinem aktiven Dossier:');
            foreach ($luecken as $n => $keys) {
                $this->line(sprintf('  §%-10s versprochen von: %s', $n, implode(', ', array_unique($keys))));
            }
            $this->line('  Das Modell wird auf eine Regel verwiesen, die es nicht zu lesen bekommt (der §12-Fall).');
        }

        // 4. Gegenrichtung — Dossiers, die kein Prompt erwähnt.
        $alleGenannt = array_unique(array_merge(...array_values($genannt)));
        $unerwaehnt = $docs->filter(function ($d) use ($alleGenannt, $imKorpus) {
            foreach ($alleGenannt as $n) {
                if (in_array($d->slug, $imKorpus[$n] ?? [], true)) {
                    return false;
                }
            }

            return true;
        });
        $this->line('');
        $this->line(sprintf(
            'Von %d aktiven Regelwerk-Dossiers wird %d von keinem Prompt namentlich über einen § erreicht.',
            $docs->count(), $unerwaehnt->count(),
        ));
        $this->line('  (Kein Fehler — Retrieval findet sie semantisch. Für das Token-Programm aber die');
        $this->line('   Frage, welches Wissen nur Gewicht ist.)');

        return ($luecken !== [] && $this->option('fail-on-gap')) ? self::FAILURE : self::SUCCESS;
    }
}
