<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Platform\FoodAlchemist\Services\Ai\KnowledgeBudget;
use Platform\FoodAlchemist\Services\KnowledgeRoutingService;

/**
 * Sicherung der STEUERUNG — die dritte und letzte Lücke neben Kanon und Einordnung.
 *
 * Drei Dinge entscheiden, welches Wissen ein Schritt bekommt: der **Kanon** („was muss"), die
 * **Routings** („was darf gesucht werden, wie tief") und das **Budget** („wie viel passt").
 * Für den Kanon gab es eine Sicherung, für die Einordnung seit heute — für die anderen beiden
 * nichts. Am 2026-09-12 lagen 41 neu angelegte Arten-Routings und zwei Budget-Werte
 * ausschliesslich in der Datenbank; ein Verlust hätte die ganze Verdrahtung mitgenommen,
 * während Kanon und Einordnung gerettet worden wären.
 *
 * GLOBAL, ohne Team: beide Tabellen haben keine `team_id` — die Steuerung gilt für alle
 * Mandanten, und genau deshalb darf nur das Master-Team sie ändern.
 *
 * Der Unterschied zu den beiden Schwester-Sicherungen: hier gibt es keine Slug-Auflösung.
 * Routings und Budgets verweisen auf Prompt-Keys und Kategorien, nicht auf Dossiers — die
 * Datei bleibt also auch dann einspielbar, wenn Dossiers umbenannt wurden.
 */
class SteuerungSicherungService
{
    public function standardDatei(): string
    {
        return dirname(__DIR__, 3).'/database/steuerung/steuerung-global.json';
    }

    /** @return array{erzeugt_am: string, routings: list<array<string, mixed>>, budgets: list<array<string, mixed>>} */
    public function inhalt(): array
    {
        return ['erzeugt_am' => now()->toDateString(), 'routings' => $this->routings(), 'budgets' => $this->budgets()];
    }

    /** @return list<array<string, mixed>> */
    public function routings(): array
    {
        return DB::table('foodalchemist_knowledge_routings')
            // Stabil sortiert, damit zwei Exporte desselben Standes zeichengleiche Dateien
            // ergeben — sonst rauscht jeder Export als Diff durch den Review.
            ->orderBy('feature')->orderByRaw('COALESCE(art, "")')->orderByRaw('COALESCE(category, "")')
            ->get(['feature', 'art', 'category', 'mode', 'max_docs', 'max_chars_per_doc'])
            ->map(fn ($r) => [
                'feature' => (string) $r->feature,
                'art' => $r->art,
                'category' => $r->category,
                'mode' => (string) $r->mode,
                'max_docs' => $r->max_docs !== null ? (int) $r->max_docs : null,
                'max_chars_per_doc' => $r->max_chars_per_doc !== null ? (int) $r->max_chars_per_doc : null,
            ])->all();
    }

    /**
     * Nur die ABWEICHENDEN Budgets. Was auf dem ausgelieferten Standard läuft, gehört nicht in
     * die Datei — sonst friert die Sicherung eine Config ein, die sich mit dem Code ändern darf.
     *
     * @return list<array<string, mixed>>
     */
    public function budgets(): array
    {
        if (! Schema::hasTable('foodalchemist_knowledge_budgets')) {
            return [];
        }

        return DB::table('foodalchemist_knowledge_budgets')->orderBy('prompt_key')
            ->get(['prompt_key', 'max_chars'])
            ->map(fn ($b) => ['prompt_key' => (string) $b->prompt_key, 'max_chars' => (int) $b->max_chars])->all();
    }

    /**
     * Datei lesen und den Vertrag prüfen — lieber hier hart fehlschlagen als beim Einspielen
     * die halbe Steuerung gesetzt haben.
     *
     * @return array{routings: list<array<string, mixed>>, budgets: list<array<string, mixed>>}
     */
    public function lade(string $datei): array
    {
        if (! is_file($datei)) {
            throw new InvalidArgumentException('Datei nicht gefunden: '.$datei);
        }
        $inhalt = json_decode((string) file_get_contents($datei), true);
        if (! is_array($inhalt) || ! is_array($inhalt['routings'] ?? null)) {
            throw new InvalidArgumentException('Datei ist keine Steuerungs-Sicherung (Schlüssel `routings` fehlt).');
        }

        $routings = [];
        foreach ($inhalt['routings'] as $i => $r) {
            if (! is_array($r) || trim((string) ($r['feature'] ?? '')) === '') {
                throw new InvalidArgumentException("Routing {$i}: `feature` fehlt.");
            }
            $hatArt = trim((string) ($r['art'] ?? '')) !== '';
            $hatKat = trim((string) ($r['category'] ?? '')) !== '';
            if ($hatArt === $hatKat) {
                throw new InvalidArgumentException("Routing {$i}: genau eines von `art` oder `category` ist Pflicht.");
            }
            // Modi gegen den Service prüfen, nicht gegen eine eigene Liste — eine zweite Liste
            // würde eine gültige Sicherung still ablehnen, sobald ein Modus dazukommt.
            $erlaubt = [...KnowledgeRoutingService::MODES, 'resolve'];
            if (! in_array($r['mode'] ?? '', $erlaubt, true)) {
                throw new InvalidArgumentException("Routing {$i}: `mode` = «{$r['mode']}» — erlaubt: ".implode('|', $erlaubt));
            }
            $routings[] = [
                'feature' => (string) $r['feature'], 'art' => $hatArt ? (string) $r['art'] : null,
                'category' => $hatKat ? (string) $r['category'] : null, 'mode' => (string) $r['mode'],
                'max_docs' => isset($r['max_docs']) ? (int) $r['max_docs'] : null,
                'max_chars_per_doc' => isset($r['max_chars_per_doc']) ? (int) $r['max_chars_per_doc'] : null,
            ];
        }

        $budgets = [];
        foreach ($inhalt['budgets'] ?? [] as $i => $b) {
            if (! is_array($b) || trim((string) ($b['prompt_key'] ?? '')) === '' || (int) ($b['max_chars'] ?? 0) < 1) {
                throw new InvalidArgumentException("Budget {$i}: `prompt_key` und ein positives `max_chars` sind Pflicht.");
            }
            $budgets[] = ['prompt_key' => (string) $b['prompt_key'], 'max_chars' => (int) $b['max_chars']];
        }

        return ['routings' => $routings, 'budgets' => $budgets];
    }

    /**
     * Datei gegen den Live-Stand halten.
     *
     * @param  array{routings: list<array<string, mixed>>, budgets: list<array<string, mixed>>}  $datei
     * @return array{nur_live: list<string>, nur_datei: list<string>, abweichend: list<array<string, mixed>>, deckungsgleich: int, budgets_abweichend: list<array<string, mixed>>}
     */
    public function abgleich(array $datei): array
    {
        $schluessel = fn (array $r) => $r['feature'].'|'.($r['art'] ?? '').'|'.($r['category'] ?? '');
        $live = collect($this->routings())->keyBy($schluessel);
        $ausDatei = collect($datei['routings'])->keyBy($schluessel);

        $abweichend = [];
        $gleich = 0;
        foreach ($ausDatei as $k => $z) {
            $l = $live->get($k);
            if ($l === null) {
                continue;
            }
            $diff = [];
            foreach (['mode', 'max_docs', 'max_chars_per_doc'] as $feld) {
                if ($l[$feld] !== $z[$feld]) {
                    $diff[$feld] = ['live' => $l[$feld], 'datei' => $z[$feld]];
                }
            }
            $diff === [] ? $gleich++ : $abweichend[] = ['routing' => $k] + $diff;
        }

        $liveB = collect($this->budgets())->pluck('max_chars', 'prompt_key');
        $dateiB = collect($datei['budgets'])->pluck('max_chars', 'prompt_key');
        $budgetsAbweichend = [];
        foreach ($dateiB as $key => $wert) {
            if ($liveB->get($key) !== $wert) {
                $budgetsAbweichend[] = ['prompt_key' => $key, 'live' => $liveB->get($key), 'datei' => $wert];
            }
        }

        return [
            'nur_live' => $live->keys()->diff($ausDatei->keys())->values()->all(),
            'nur_datei' => $ausDatei->keys()->diff($live->keys())->values()->all(),
            'abweichend' => $abweichend, 'deckungsgleich' => $gleich,
            'budgets_abweichend' => $budgetsAbweichend,
        ];
    }

    /**
     * Einspielen. Ohne `$apply` nur Vorschau.
     *
     * Geschrieben wird über {@see KnowledgeRoutingService} und {@see KnowledgeBudget::setze},
     * nie direkt in die Tabelle — sonst entstünde ein zweiter Schreibpfad an der
     * Modus-Prüfung vorbei. Zeilen, die live existieren und in der Datei fehlen, bleiben
     * stehen: eine alte Sicherung darf keine Entscheidung zurücknehmen, von der sie nichts weiss.
     *
     * @param  array{routings: list<array<string, mixed>>, budgets: list<array<string, mixed>>}  $datei
     * @return array{routings: int, budgets: int, fehler: list<array<string, string>>}
     */
    public function spielEin(array $datei, bool $apply): array
    {
        $service = app(KnowledgeRoutingService::class);
        $routings = 0;
        $budgets = 0;
        $fehler = [];

        foreach ($datei['routings'] as $r) {
            try {
                if ($apply) {
                    $r['art'] !== null
                        ? $service->setArt($r['feature'], $r['art'], $r['mode'], (int) ($r['max_docs'] ?? 3), $r['max_chars_per_doc'])
                        : $service->set($r['feature'], (string) $r['category'], $r['mode'], $r['max_docs'], $r['max_chars_per_doc']);
                }
                $routings++;
            } catch (\Throwable $e) {
                $fehler[] = ['routing' => $r['feature'].'/'.($r['art'] ?? $r['category']), 'grund' => $e->getMessage()];
            }
        }

        foreach ($datei['budgets'] as $b) {
            try {
                if ($apply) {
                    KnowledgeBudget::setze($b['prompt_key'], $b['max_chars']);
                }
                $budgets++;
            } catch (\Throwable $e) {
                $fehler[] = ['budget' => $b['prompt_key'], 'grund' => $e->getMessage()];
            }
        }

        return ['routings' => $routings, 'budgets' => $budgets, 'fehler' => $fehler];
    }
}
