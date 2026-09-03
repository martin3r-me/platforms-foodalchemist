<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wissens-STEUERDATEN für eine frische Datenbank setzen — Routings je (Feature, Kategorie).
 *
 * WARUM ES DIESEN BEFEHL GIBT: bis 2026-09-03 saß dieselbe Liste im `knowledge-import`
 * ({@see KnowledgeImportCommand}) und wurde bei JEDEM Import mitgeschrieben. Das war eine Falle
 * mit sehr geduldigem Fehlermodus:
 *
 *  · Auf demo ein No-op (`insertOrIgnore`, die Zeilen existieren) — man sieht also nichts.
 *  · Auf einer FRISCHEN DB (Disaster Recovery, neuer Kunde, neue Dev-Umgebung) stellte der
 *    Import den Monolithen-Pfad wieder her, den Welle 0 abgebaut hatte: 15 der 31 Tupel mit
 *    `mode='always'`, darunter `ai_generate_recipe|regelwerk|always|1|9500`.
 *
 * Und diese Zeile ist besonders heikel, weil sie den TOTEN Pfad wiederbelebt: `regelwerkBlock()`
 * holt per `->first()` genau EIN Dossier. Der Generator hätte also ein Regelwerk statt der
 * gebundenen §-Dossiers bekommen — ohne Fehlermeldung, nur mit schlechteren Rezepten.
 *
 * Inhalt UND Auslöser trennen: ein Import liest Dokumente, er entscheidet keine Politik.
 *
 * Die `ai_generate_recipe`-Zeilen tragen hier den Stand NACH Welle 0. Ein Test hält sie gegen
 * {@see WissenSteuerdatenW0Command::ROUTINGS} — zwei Listen, die dasselbe behaupten, driften
 * sonst auseinander, und die Divergenz fiele erst beim nächsten Neuaufbau auf.
 *
 * `insertOrIgnore`: bestehende Zeilen bleiben unangetastet. Der Befehl richtet KEINEN Bestand
 * ein — dafür ist `wissen-steuerdaten-w0 --apply` da.
 */
class KnowledgePolicySeedCommand extends Command
{
    protected $signature = 'foodalchemist:knowledge-policy-seed
        {--apply : Schreiben (ohne dieses Flag nur Vorschau)}';

    protected $description = 'Wissens-Routings für eine frische DB setzen (Politik, getrennt vom Import)';

    /**
     * [feature, category, mode, max_docs, max_chars]
     *
     * Die `ai_generate_recipe`-Zeilen sind gegenüber der alten Import-Liste KORRIGIERT:
     *   · `regelwerk`      always 1×9500 → **none**  (kommt vollständig über die Bindings, W0-3)
     *   · `kueche`         3×3000        → 2×2500
     *   · `niveau`         1×3000        → 1×1800
     *   · `kreativ_input`  3×2000        → 1×2000
     *   · `cross_cutting`  bleibt `always`, weil die Zeile dort nur ein Boolean-Gate ist —
     *     Menge und Deckel steuern die Konstanten in KnowledgeContextService, nicht dieses Feld.
     *
     * Die übrigen Features bleiben unverändert. `always` ist dort weiterhin TRAGEND, solange der
     * Kanon (W2-2/W2-3) nicht steht — insbesondere `foodbook.grundgeruest`/`concept.brief_geruest`
     * hätten sonst keinen Wissenspfad mehr. „Nie `always`" gilt erst MIT dem Kanon, nicht vorher.
     *
     * @var list<array{0:string,1:string,2:string,3:?int,4:?int}>
     */
    public const ROUTINGS = [
        // ── Rezept-Generator: Stand nach Welle 0 ──
        ['ai_generate_recipe', 'cross_cutting', 'always', null, null],
        ['ai_generate_recipe', 'domain', 'discovery', null, null],
        ['ai_generate_recipe', 'pairing', 'discovery', null, null],
        ['ai_generate_recipe', 'regelwerk', 'none', null, null],
        ['ai_generate_recipe', 'referenzgericht', 'none', null, null],
        ['ai_generate_recipe', 'kueche', 'discovery', 2, 2500],
        ['ai_generate_recipe', 'weltkueche', 'discovery', 1, 2000],
        ['ai_generate_recipe', 'signatur_kuechen', 'discovery', 1, 2000],
        ['ai_generate_recipe', 'kreativ_input', 'discovery', 1, 2000],
        ['ai_generate_recipe', 'niveau', 'discovery', 1, 1800],
        ['ai_generate_recipe', 'ernaehrung', 'discovery', 1, 1500],
        ['ai_generate_recipe', 'prasentation_service', 'discovery', 1, 1500],

        // ── übrige Features: unverändert übernommen ──
        ['concept.brief_geruest', 'regelwerk', 'always', 1, 9000],
        ['recipe.steps', 'cross_cutting', 'always', null, null],
        ['recipe.steps', 'domain', 'discovery', null, null],
        ['recipe.steps', 'kueche', 'discovery', 3, 3000],
        ['recipe.steps', 'niveau', 'discovery', 1, 3000],
        ['recipe.ueberarbeiten', 'regelwerk', 'always', 1, 7000],
        ['vk.ueberarbeiten', 'regelwerk', 'always', 1, 7000],
        ['concept.wording', 'cross_cutting', 'always', null, null],
        ['foodbook.kundentext', 'cross_cutting', 'always', null, null],
        ['foodbook.plan', 'cross_cutting', 'always', null, null],
        ['foodbook.plan', 'domain', 'discovery', null, null],
        ['foodbook.plan', 'concept', 'always', 4, 4000],
        ['foodbook.plan', 'trend', 'discovery', 5, 1500],
        ['concept.plan', 'cross_cutting', 'always', null, null],
        ['concept.plan', 'domain', 'discovery', null, null],
        ['concept.plan', 'concept', 'always', 4, 4000],
        ['concept.brief_geruest', 'trend', 'discovery', 5, 1500],
        ['ai_extract_recipe', 'cross_cutting', 'none', null, null],
        ['ai_suggest_pairings', 'pairing', 'grounding', 5, 1200],
        ['ai_infer_ankers', 'pairing', 'grounding', 3, 1400],
        ['recipe.eigenschaften', 'produktion_kapazitat', 'always', 3, 7000],
        ['recipe.eigenschaften', 'regelwerk', 'always', 1, 6000],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'Wissens-Routings: SCHREIBEN' : 'Wissens-Routings: VORSCHAU (--apply zum Schreiben)');

        $neu = 0;
        $vorhanden = 0;
        $plan = [];
        foreach (self::ROUTINGS as [$feature, $category, $mode, $maxDocs, $maxChars]) {
            $ist = DB::table('foodalchemist_knowledge_routings')
                ->where('feature', $feature)->where('category', $category)->first();

            if ($ist !== null) {
                $vorhanden++;
                $gleich = $ist->mode === $mode
                    && (int) $ist->max_docs === (int) $maxDocs
                    && (int) $ist->max_chars_per_doc === (int) $maxChars;
                if (! $gleich) {
                    // NICHT überschreiben: ein Bestand kann bewusst abweichen (und `--apply` hier
                    // wäre dann eine stille Politik-Änderung). Nur melden — richten tut das
                    // `wissen-steuerdaten-w0 --apply`, das dafür einen Assert mitbringt.
                    $plan[] = [$feature, $category, "{$ist->mode}/" . ($ist->max_docs ?? '-') . '/' . ($ist->max_chars_per_doc ?? '-'), "{$mode}/" . ($maxDocs ?? '-') . '/' . ($maxChars ?? '-'), 'WEICHT AB (nicht angetastet)'];
                }

                continue;
            }

            $plan[] = [$feature, $category, '—', "{$mode}/" . ($maxDocs ?? '-') . '/' . ($maxChars ?? '-'), $apply ? 'angelegt' : 'ANLEGEN'];
            $neu++;
            if ($apply) {
                DB::table('foodalchemist_knowledge_routings')->insertOrIgnore([
                    'feature' => $feature,
                    'category' => $category,
                    'mode' => $mode,
                    'max_docs' => $maxDocs,
                    'max_chars_per_doc' => $maxChars,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($plan !== []) {
            $this->table(['feature', 'category', 'ist', 'soll', 'aktion'], $plan);
        }
        $this->line(sprintf('%d Routings im Soll · %d vorhanden · %d %s', count(self::ROUTINGS), $vorhanden, $neu, $apply ? 'angelegt' : 'anzulegen'));
        if (! $apply && $neu > 0) {
            $this->info('Nichts geschrieben. Mit --apply ausführen.');
        }

        return self::SUCCESS;
    }
}
