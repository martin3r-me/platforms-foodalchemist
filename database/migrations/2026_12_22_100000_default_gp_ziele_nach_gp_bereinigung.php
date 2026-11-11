<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Probelauf Excel-Rezepte 10.11.2026 (9.205 Zeilen): 10 Ziele der §5-Default-Regel nannten GP-Namen, die es seit der GP-Bereinigung
 * (Ende Oktober) nicht mehr gibt — „Wasser“, „Eigelb“, „Öl“ … blieben in jedem Import offen. Entscheid Dominique 10./11.11.2026:
 * neue Ziele wie unten, Sojasauce = Kikkoman glutenfrei, Karotte = „Möhre: frisch, mittel“, Olivenöl vorerst „Olivenöl“ (das GP
 * „Olivenöl: hochwertig“ wird getrennt überarbeitet), neu „Pfefferkörner“ = schwarz, ganz (wie „Pfeffer“ = schwarz).
 *
 * Ein Eintrag wird nur umgestellt, wenn er noch das ALTE Ziel trägt — Pflege-Änderungen auf io bleiben unberührt. Idempotent.
 */
return new class extends Migration
{
    /** begriff => [altes Ziel, neues Ziel]; umgestellt wird nur das alte Ziel (die prefer_raw-Einträge Eigelb/Eiweiß → Eier stimmen und bleiben) */
    private const UMSTELLUNG = [
        'wasser' => ['Leitungswasser: frisch', 'Leitungswasser'],
        'eigelb' => ['Eigelb: fluessig, pasteurisiert', 'Eigelb: pasteurisiert, frisch, flüssig'],
        'eiweiss' => ['Huehnereiweiss: fluessig, pasteurisiert', 'Eiklar / Eiweiß: pasteurisiert, frisch, flüssig'],
        'weisswein' => ['Weisswein: konserviert, zum Kochen', 'Weißwein: zum Kochen'],
        'olivenöl' => ['Olivenoel: trocken, hochwertig', 'Olivenöl'],
        'sojasauce' => ['Sojasauce: konserviert, glutenfrei', 'Sojasauce: konserviert / (Kikkoman) / (Glutenfrei)'],
        'tomate' => ['Tomaten: frisch, ganz', 'Tomate: ganz, frisch'],
        'zwiebel' => ['Zwiebeln: frisch, ganz', 'Zwiebel: ganz, frisch'],
        'karotte' => ['Karotten: frisch, ganz', 'Möhre: frisch, mittel'],
        'öl' => ['Rapsoel / Pflanzenoel: trocken, raffiniert', 'Rapsöl / Pflanzenöl: raffiniert'],
    ];

    private const PFEFFERKOERNER = ['begriff' => 'pfefferkörner', 'aliase' => ['pfefferkorn'], 'ziel_typ' => 'gp', 'ziel_name' => 'Pfeffer schwarz: ganz, trocken'];

    public function up(): void
    {
        $regel = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'basisrezept.5.default_gp')->first();
        if ($regel === null) {
            return;
        }
        $p = (array) $regel->params;
        $geaendert = false;
        $eintraege = array_map(function ($e) use (&$geaendert) {
            $begriff = mb_strtolower((string) ($e['begriff'] ?? ''));
            $u = self::UMSTELLUNG[$begriff] ?? null;
            if ($u !== null && ($e['ziel_name'] ?? null) === $u[0]) {
                $e['ziel_name'] = $u[1];
                $geaendert = true;
            }

            return $e;
        }, (array) ($p['eintraege'] ?? []));
        if (! in_array('pfefferkörner', array_map(fn ($e) => mb_strtolower((string) ($e['begriff'] ?? '')), $eintraege), true)) {
            $eintraege[] = self::PFEFFERKOERNER;
            $geaendert = true;
        }
        if (! $geaendert) {
            return;
        }
        $felder = ['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'beispiele', 'dossier_slug'];
        app(RegelService::class)->speichere([...$regel->only($felder), 'params' => [...$p, 'eintraege' => $eintraege],
            'notiz' => trim(((string) $regel->notiz) . ' Ziele nach GP-Bereinigung, Pfefferkörner neu: Entscheid Dominique 2026-11-11.')],
            null, null, 'migration');
    }

    public function down(): void
    {
    }
};
