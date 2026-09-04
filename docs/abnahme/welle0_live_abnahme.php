<?php
echo "══════ 1. Warteschlangen: sind die Defaults wirklich LEER? ══════\n";
foreach (['gerichte','rezepte','kaskade','anreichern'] as $q) {
    $v = config('foodalchemist.queue.'.$q, '');
    printf("  %-12s %s\n", $q, $v === '' ? 'leer  ✓ (kein Job wird umgeleitet)' : "'{$v}'  ⚠ SCHARF");
}

echo "\n══════ 2. Deckel-Spalte da? ══════\n";
printf("  cascade_runs.deckel_hinweise: %s\n",
    \Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_cascade_runs','deckel_hinweise') ? 'da ✓' : 'FEHLT');

echo "\n══════ 3. Dossier-Routing: wohin haengen die zwei? ══════\n";
foreach (['geschmacksbalance','produktion-arbeitszeit-und-personenminuten'] as $slug) {
    $rows = DB::table('foodalchemist_knowledge_bindings as b')
        ->join('foodalchemist_knowledge_documents as d','d.id','=','b.knowledge_document_id')
        ->where('d.slug',$slug)->whereNull('b.deleted_at')
        ->get(['b.target_key','b.mode','b.active']);
    echo "  {$slug}:\n";
    foreach ($rows as $r) { printf("    %-22s %-10s active=%d\n", $r->target_key, $r->mode, $r->active); }
    if ($rows->isEmpty()) { echo "    (keine Bindung)\n"; }
}

echo "\n══════ 4. Pflicht-Summe je Generator gegen den Deckel ══════\n";
foreach (['recipe.generator','vk.generator','recipe.eigenschaften'] as $key) {
    $ziele = array_values(array_unique([$key, explode('.', $key)[0]]));
    $rows = DB::table('foodalchemist_knowledge_bindings as b')
        ->join('foodalchemist_knowledge_documents as d','d.id','=','b.knowledge_document_id')
        ->whereIn('b.target_key',$ziele)->where('b.active',1)->whereNull('b.deleted_at')
        ->where('d.active',1)->whereNull('d.deleted_at')
        ->get(['b.mode','d.slug','d.content_md']);
    $a=0; $disc=0;
    foreach ($rows as $r) {
        $len = mb_strlen(\Platform\FoodAlchemist\Support\DossierText::ohneVorspann((string)$r->content_md));
        $r->mode === 'always' ? $a += $len : $disc += $len;
    }
    // ACHTUNG: der Array-Key heisst woertlich 'recipe.generator' — mit Punkt IM Key.
    // config() loest Punkte als Verschachtelung auf und wuerde ihn NIE finden (still 4200).
    // Der Produktionscode macht es richtig (AiGatewayService:366 holt das ganze Array).
    $b = config('foodalchemist.ai.bound_knowledge_budget', [])[$key] ?? [];
    $deckel = is_array($b) && isset($b['total']) ? (int) $b['total'] : 4200;
    printf("  %-22s Pflicht %6d (bereinigt) · discovery %6d · Deckel %6d  %s\n",
        $key, $a, $disc, $deckel, $a <= $deckel ? '✓' : '✗ GEKAPPT');
}

echo "\n══════ 5. Prompt-Zusammensetzung, OHNE Token zu verbrennen ══════\n";
config(['foodalchemist.ai.provider' => 'fake']);
\Illuminate\Support\Facades\Auth::login(\Platform\Core\Models\Team::find(6)->users()->first());
$vorher = DB::table('foodalchemist_ai_call_log')->max('id');
foreach (['recipe.generator','vk.generator'] as $key) {
    try { app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)
        ->propose($key, ['description' => 'Rinderfilet mit Pfefferrahm', 'saison' => '2026-09'], []); }
    catch (\Throwable $e) { echo "  ({$key}: ".mb_substr($e->getMessage(),0,70).")\n"; }
}
printf("\n  %-18s %8s %8s %8s %8s %8s\n", 'feature','chars','bound','task','kontext','dropped');
foreach (DB::table('foodalchemist_ai_call_log')->where('id','>',(int)$vorher)->orderBy('id')->get() as $l) {
    $p = json_decode((string)$l->prompt_parts, true) ?: [];
    printf("  %-18s %8s %8s %8s %8s %8s\n", $l->feature, $l->prompt_chars,
        $p['bound']??'-', $p['task']??'-', $p['kontext']??'-', $p['dropped']??'-');
}
DB::table('foodalchemist_ai_call_log')->where('id','>',(int)$vorher)->delete();
echo "  (Fake-Calls aus dem Log entfernt — Statistik unverfaelscht)\n";

echo "\n══════ 6. Drift-Signal: offen oder geschlossen? ══════\n";
$sig = DB::table('foodalchemist_signals')->where('type','steuerdaten_drift')
    ->orderByDesc('id')->first(['status','title','created_at']);
echo $sig === null ? "  kein Signal (nie Drift gemeldet)\n"
    : "  {$sig->status}: {$sig->title}  ({$sig->created_at})\n";

echo "\n══════ 7. Der neue Signal-Typ hat live einen Weg (das war die Luecke) ══════\n";
$typ = \Platform\FoodAlchemist\Enums\SignalTyp::SteuerdatenDrift;
$s = new \Platform\FoodAlchemist\Models\FoodAlchemistSignal(['type' => $typ, 'payload' => []]);
$plan = \Platform\FoodAlchemist\Support\SignalCockpit::planFor($s);
$grund = \Platform\FoodAlchemist\Support\SignalCockpit::ohneWegGrund($s);
$ki    = \Platform\FoodAlchemist\Support\SignalCockpit::kiPlan($s);
printf("  Label      : %s\n", $typ->label());
printf("  Kategorie  : %s\n", $plan['kind'] ?? '— KEINE (Fehler)');
printf("  KI-Knopf   : %s\n", $ki === null ? 'nein ✓ (absichtlich knopflos)' : '⚠ da');
printf("  ohneWegGrund: %s\n", $grund === null ? 'null ✓ (es gibt einen Weg)' : 'gesetzt ⚠ (widerspruechlich)');
printf("  Weg-Satz   : %s\n", isset($plan['plan']) ? mb_substr($plan['plan'],0,90).'…' : '— FEHLT');
$ok = ($plan['kind'] ?? null) === 'navigate' && $ki === null && $grund === null;
echo $ok ? "  ⇒ genau eine Kategorie, ein Weg, kein Knopf ✓\n" : "  ⇒ ✗ INKONSISTENT\n";
