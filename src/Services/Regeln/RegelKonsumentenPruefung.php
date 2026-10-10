<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;

/**
 * Spec 81 — Schutz vor Paket 5 (Regeln aus den Dossiers nehmen). Jede aktive Regel mit `dossier_slug` steht heute
 * auch als Text im Dossier. Liest ein Prompt dieses Dossier über den Kanon, muss er die Regel nach der Bereinigung
 * über den Regel-Block bekommen — sonst verliert er sie still (Prüfbericht Berater 09.10.: fünf Keys betroffen).
 *
 * Meldet je Kanon-Leser, der weder Konsument von {@see RegelPromptBlock} noch bewusst ausgenommen ist, einen Befund.
 * Rein lesend.
 */
final class RegelKonsumentenPruefung
{
    /** @return list<array{prompt_key: string, schluessel: string, dossier: string}> */
    public function befunde(): array
    {
        if (! Schema::hasTable('foodalchemist_knowledge_canon')) {
            return [];
        }
        $erlaubt = array_flip([...RegelPromptBlock::konsumenten(), ...array_keys(RegelPromptBlock::bewusstOhne())]);
        $regeln = FoodAlchemistRule::query()->whereNull('team_id')->where('aktiv', true)->whereNotNull('dossier_slug')
            ->get(['schluessel', 'dossier_slug']);
        $out = [];
        foreach ($regeln as $r) {
            $keys = DB::table('foodalchemist_knowledge_canon as c')
                ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'c.knowledge_document_id')
                ->where('c.scope', 'prompt_key')->where('c.active', 1)->whereNull('c.deleted_at')
                ->where('d.slug', $r->dossier_slug)->whereNull('d.deleted_at')
                ->distinct()->pluck('c.scope_key');
            foreach ($keys as $key) {
                if (! isset($erlaubt[$key])) {
                    $out[] = ['prompt_key' => (string) $key, 'schluessel' => (string) $r->schluessel, 'dossier' => (string) $r->dossier_slug];
                }
            }
        }
        usort($out, static fn ($a, $b) => [$a['prompt_key'], $a['schluessel']] <=> [$b['prompt_key'], $b['schluessel']]);

        return $out;
    }
}
