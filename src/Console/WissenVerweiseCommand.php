<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;

/**
 * Spec 52/H7 — hängende §-Verweise im zusammengesetzten Prompt.
 *
 * Der Defekt entstand durch den Split: vorher war `Regelwerk_Basisrezepte` EIN Dokument,
 * §2 konnte inline auf §4 und §11 verweisen. Nach dem Schnitt in Ein-Thema-Dossiers ist
 * so ein Verweis ein Textstring ohne Ziel — das Modell liest „siehe §11", und §11 liegt
 * nicht im Prompt. Belegt am Kanon von `recipe.generator`: er trägt §1.0–1.5, §2, §3, §4
 * und §6, aber NICHT §11 (Derivate) und nicht §1.10/§1.11 (Anti-Patterns), auf die §2
 * verweist.
 *
 * ★ Bewusst ein WÄCHTER, kein Laufzeit-Check: die Prüfung gehört zur Kuration, nicht in
 * jeden Modellaufruf. Sie kostet dort nichts und meldet trotzdem jeden Fall.
 *
 * Der Befund ist eine MELDUNG, keine Blockade. Auflösung je Fall (Spec 52/H7):
 * Ziel mitliefern, Verweis auflösen oder Verweis entfernen — nie hängen lassen.
 */
class WissenVerweiseCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-verweise
        {--team= : PFLICHT — der Kanon ist team-aufgelöst}
        {--prompt-key=* : nur diese Prompt-Keys (Default: alle mit Kanon)}
        {--json : Ergebnis als JSON-Zeile}';

    protected $description = 'H7: meldet §-Verweise, deren Ziel nicht im selben Prompt steht';

    /** `§6`, `§1.10`, `§1.5a` — Bindestrich/Gedankenstrich trennt Bereiche, kein Teil des Zeichens. */
    private const MUSTER = '/§\s?([0-9]+(?:\.[0-9]+)*[a-z]?)/u';

    public function handle(KnowledgeCanonService $kanon): int
    {
        $teamId = (int) $this->option('team');
        if ($teamId <= 0) {
            $this->error('--team=<id> ist Pflicht — der Kanon wird team-aufgelöst.');

            return self::INVALID;
        }
        $team = Team::find($teamId);
        if ($team === null) {
            $this->error("Team {$teamId} existiert nicht.");

            return self::INVALID;
        }

        $keys = $this->option('prompt-key') ?: $this->promptKeysMitKanon($teamId);
        $berichte = [];
        foreach ($keys as $key) {
            $docs = $kanon->documentsFor('prompt_key', (string) $key, $team);
            if ($docs->isEmpty()) {
                continue;
            }
            $berichte[(string) $key] = $this->pruefe($docs);
        }

        $offen = array_sum(array_map(fn ($b) => count($b['haengend']), $berichte));

        if ($this->option('json')) {
            $this->line((string) json_encode(['team' => $teamId, 'haengend_gesamt' => $offen, 'keys' => $berichte],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $offen === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach ($berichte as $key => $b) {
            $this->line(sprintf('%-28s %2d Dossiers · liefert %2d §§ · %s',
                $key, $b['dossiers'], count($b['bereitgestellt']),
                $b['haengend'] === [] ? 'keine hängenden Verweise' : count($b['haengend']).' HÄNGEND'));
            foreach ($b['haengend'] as $ref => $quellen) {
                $this->warn(sprintf('    §%-8s verwiesen in: %s', $ref, implode(', ', $quellen)));
            }
        }
        $this->line('');
        $this->line($offen === 0 ? '✓ kein hängender Verweis' : "⚠ {$offen} hängende Verweise — Ziel mitliefern, auflösen oder entfernen");

        return $offen === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $docs
     * @return array{dossiers:int, bereitgestellt:list<string>, haengend:array<string, list<string>>}
     */
    private function pruefe($docs): array
    {
        // Was der Prompt LIEFERT: die §§ aus den Titeln. Ein Bereichstitel („§1.6–§1.9")
        // nennt beide Enden; die Zwischenstufen deckt die Präfix-Regel unten mit ab.
        $bereitgestellt = [];
        foreach ($docs as $d) {
            foreach ($this->paragraphen((string) ($d->title ?? '')) as $p) {
                $bereitgestellt[$p] = true;
            }
        }
        // ⚠ PHP wandelt numerische Array-Schluessel still in int: aus '4' wird 4, und der
        //   strikte Vergleich 4 === '4' ist false. '1.2' bleibt dagegen String — der Fehler
        //   traf also nur ganzzahlige §§ und waere in einer Stichprobe durchgerutscht.
        $bereitgestellt = array_map('strval', array_keys($bereitgestellt));

        // Worauf er VERWEIST: alle §§ im Fliesstext.
        $haengend = [];
        foreach ($docs as $d) {
            foreach ($this->paragraphen((string) ($d->content_md ?? '')) as $ref) {
                if ($this->gedeckt($ref, $bereitgestellt)) {
                    continue;
                }
                $haengend[$ref][] = (string) $d->slug;
            }
        }
        ksort($haengend, SORT_NATURAL);

        return ['dossiers' => $docs->count(), 'bereitgestellt' => $bereitgestellt,
            'haengend' => array_map(fn ($q) => array_values(array_unique($q)), $haengend)];
    }

    /** @return list<string> */
    private function paragraphen(string $text): array
    {
        preg_match_all(self::MUSTER, $text, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Gedeckt ist ein Verweis, wenn genau dieses § im Prompt liegt ODER ein Unter-§ davon.
     * „siehe §1" ist erfüllt, wenn §1.2 dabei ist — der Verweis meint die Familie.
     *
     * NICHT gedeckt ist die Gegenrichtung: §1.0 im Prompt deckt einen Verweis auf §1.10
     * nicht, das ist ein anderer Inhalt. Genau dieser Fall ist der Anlass für H7.
     *
     * @param  list<string>  $bereitgestellt
     */
    private function gedeckt(string $ref, array $bereitgestellt): bool
    {
        foreach ($bereitgestellt as $p) {
            if ($p === $ref || str_starts_with($p, $ref.'.')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function promptKeysMitKanon(int $teamId): array
    {
        return \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_canon')
            ->where('scope', 'prompt_key')->where('active', 1)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $teamId))
            ->distinct()->orderBy('scope_key')->pluck('scope_key')->map(fn ($v) => (string) $v)->all();
    }
}
