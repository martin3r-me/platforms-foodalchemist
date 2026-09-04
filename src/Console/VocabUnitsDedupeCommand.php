<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;

/**
 * Plural-Dubletten im Einheiten-Vokabular zusammenlegen (Dominique, 2026-09-04).
 *
 * Anlass: der Formen-Katalog leitet sich seit B aus dem Vokabular ab — und das führt
 * `zweig` UND `zweige`, `scheibe` UND `scheiben` als getrennte Einträge. Damit wären sie
 * getrennte FORMEN: die KI müsste für Thymian „Zweig 2 g" und „Zweige 2 g" schätzen, in
 * 44 Rezeptzeilen steht `zweige`, in 17 `zweig`. Der Schätzlauf hätte die Dublette
 * zementiert, darum vorher aufräumen — Entscheid: „nur einmal schätzen".
 *
 * Bewusst eine EXPLIZITE Liste statt Plural-Erkennung: „Fäden" ist kein Plural von „Faden"
 * im Küchen-Sinn (Safranfäden zählt man in Fäden), „Hände" gehört zu „Handvoll" und nicht
 * zu einer Einzahl, und `blaetter` hat gar keine Einzahl im Vokabular. Raten wäre hier
 * schlimmer als tippen.
 *
 * Was passiert je Paar: Rezeptzeilen der Plural-Einheit auf die Einzahl umhängen, hinterlegte
 * Gewichte (gp_forms, gp_count_unit_defaults) mitnehmen — nur wo die Einzahl noch keins hat,
 * die Einzahl gewinnt sonst —, danach die Plural-Einheit INAKTIV setzen. Kein Delete: die
 * Einheit bleibt für Altbestand auflösbar und der Schritt ist umkehrbar.
 *
 * Die Gramm-Defaults der Paare sind heute identisch (zweig/zweige je 2 g, der Rest je NULL),
 * es verschiebt sich also KEINE Menge. Der Befehl prüft das und bricht ab, wenn nicht.
 */
class VocabUnitsDedupeCommand extends Command
{
    /** plural => einzahl. Nur nachgewiesene Dubletten desselben Begriffs. */
    public const PAARE = [
        'zweige' => 'zweig',
        'scheiben' => 'scheibe',
        'dosen' => 'dose',
        'schalen' => 'schale',
    ];

    protected $signature = 'foodalchemist:vocab-units-dedupe
        {--team= : Team-ID (Pflicht)}
        {--apply : umhängen + Plural inaktiv setzen; ohne = dry-run}';

    protected $description = 'Legt Plural-Dubletten im Einheiten-Vokabular auf die Einzahl zusammen (zweige→zweig …).';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        $team = $teamId > 0 ? Team::find($teamId) : null;
        if ($team === null) {
            $this->error('--team=<id> ist Pflicht.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $zeilen = [];

        foreach (self::PAARE as $plural => $einzahl) {
            $p = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', $plural)->first();
            $e = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', $einzahl)->first();
            if ($p === null || $e === null) {
                $zeilen[] = [$plural, $einzahl, '—', '—', 'ein Partner fehlt → übersprungen'];

                continue;
            }

            // Sicherung: unterschiedliche Gramm-Defaults würden beim Umhängen Mengen verschieben.
            $pg = $p->default_in_g !== null ? (float) $p->default_in_g : null;
            $eg = $e->default_in_g !== null ? (float) $e->default_in_g : null;
            if ($pg !== $eg) {
                $zeilen[] = [$plural, $einzahl, (string) $pg, (string) $eg,
                    'ABBRUCH: verschiedene Gramm-Defaults — Umhängen würde Mengen ändern'];
                $this->error("«{$plural}» ({$pg} g) und «{$einzahl}» ({$eg} g) tragen verschiedene Gewichte — "
                    .'das muss ein Mensch entscheiden, nicht dieser Befehl.');

                return self::FAILURE;
            }

            $nZeilen = DB::table('foodalchemist_recipe_ingredients')
                ->where('unit_vocab_id', $p->id)->whereNull('deleted_at')->count();
            $nFormen = DB::table('foodalchemist_gp_forms')
                ->where('form_slug', $plural)->whereNull('deleted_at')->count();

            if ($apply) {
                DB::transaction(function () use ($p, $e, $plural, $einzahl) {
                    DB::table('foodalchemist_recipe_ingredients')
                        ->where('unit_vocab_id', $p->id)->whereNull('deleted_at')
                        ->update(['unit_vocab_id' => $e->id]);

                    // Formen/Zähl-Defaults nur übernehmen, wo die Einzahl noch keine hat.
                    foreach (DB::table('foodalchemist_gp_forms')->where('form_slug', $plural)
                        ->whereNull('deleted_at')->get(['id', 'gp_id']) as $f) {
                        $schonDa = DB::table('foodalchemist_gp_forms')->where('gp_id', $f->gp_id)
                            ->where('form_slug', $einzahl)->whereNull('deleted_at')->exists();
                        $schonDa
                            ? DB::table('foodalchemist_gp_forms')->where('id', $f->id)->update(['deleted_at' => now()])
                            : DB::table('foodalchemist_gp_forms')->where('id', $f->id)->update(['form_slug' => $einzahl]);
                    }
                    DB::table('foodalchemist_gp_count_unit_defaults')
                        ->where('unit_vocab_id', $p->id)->whereNull('deleted_at')
                        ->update(['unit_vocab_id' => $e->id]);

                    $p->forceFill(['is_inactive' => true])->save();   // kein Delete: umkehrbar
                });
            }

            $zeilen[] = [$plural, $einzahl, (string) $nZeilen, (string) $nFormen,
                $apply ? 'umgehängt, Plural inaktiv' : 'würde umgehängt'];
        }

        $this->info($apply ? 'ANGEWANDT' : 'DRY-RUN');
        $this->table(['Plural', 'Einzahl', 'Rezeptzeilen', 'Formen', 'Ergebnis'], $zeilen);

        return self::SUCCESS;
    }
}
