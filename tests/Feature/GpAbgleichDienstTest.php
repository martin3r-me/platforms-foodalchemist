<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistAbgleichLog;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemNonfood;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpAbgleich\GpAbgleichService;
use Platform\FoodAlchemist\Services\GpAbgleich\MerkmalsDiff;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Abgleich-Dienst (Entscheid Dominique 25.10.2026): EIN Dienst „Lieferantenartikel → GP“ (S0 exakt, S1 Felder, S2–S5 Kandidaten/Filter/Diff/Guard-Vorschau,
 * S6 KI-Entscheider, S7 Aktion) für Modal, Job, MCP und Eval. KI wird gemockt; Fixtures sind synthetisch.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.master_team_id' => $this->rootTeam->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
    $this->nr = 0;

    $this->gp = fn (string $name, array $extra = []) => FoodAlchemistGp::create($extra + ['team_id' => $this->rootTeam->id, 'name' => $name,
        'gp_key' => 'k_' . bin2hex(random_bytes(4)), 'status' => 'approved', 'requires_la' => true, 'commodity_group_code' => '01', 'condition' => 'frisch',
        'main_ingredient_slug' => mb_strtolower(strtok($name, ':'))]);
    $this->la = function (string $bez, array $extra = [], ?FoodAlchemistGp $gp = null, ?string $milch = null) {
        $i = FoodAlchemistSupplierItem::create($extra + ['team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => $bez,
            'article_number' => 'A' . (++$this->nr), 'qty' => 1, 'unit_code' => 'kg']);
        if ($milch !== null) {
            FoodAlchemistItemAllergen::create(['team_id' => null, 'supplier_item_id' => $i->id, 'allergen_milk' => $milch]);
        }
        if ($gp !== null) {
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $i->id, 'gp_id' => $gp->id]);
            $gp->update(['n_las_total' => (int) $gp->n_las_total + 1]);
        }

        return $i;
    };
    /** KI mocken: gp.suggest → $suggest, gp.la_abgleich → $abgleich (Array oder Closure($kontext)); zählt die Calls je Key. */
    $this->kiCalls = new ArrayObject(['gp.suggest' => 0, 'gp.la_abgleich' => 0]);
    $this->ki = function (array $suggest, $abgleich = null) {
        $this->mock(AiGatewayService::class, function ($mock) use ($suggest, $abgleich) {
            $mock->shouldReceive('propose')->andReturnUsing(function ($key, $kontext = []) use ($suggest, $abgleich) {
                $this->kiCalls[$key] = ($this->kiCalls[$key] ?? 0) + 1;
                $werte = $key === 'gp.suggest' ? $suggest : (is_callable($abgleich) ? $abgleich($kontext) : ($abgleich ?? ['aktion' => 'neu', 'begruendung' => 'x', 'sicherheit' => 0.5]));

                return new AiProposal($werte, 0.8, 'Mock', [], $key);
            });
        });
    };
    $this->dienst = fn () => app(GpAbgleichService::class);
    $this->zwiebelFelder = ['hauptzutat' => 'Zwiebel', 'condition' => 'frisch', 'commodity_group_code' => '01', 'processing' => 'ganz'];
});

it('S0 exakte Brücke: gleicher Artikel/EAN an einem GP → verknüpfen, Sicherheit 1,0, automatisch, 0 KI-Calls', function () {
    $gp = ($this->gp)('Zwiebel: frisch, ganz');
    ($this->la)('Zwiebel 1kg', ['ean_packaging' => '4000000000017'], $gp);
    $neu = ($this->la)('Zwiebeln gelb 1 kg Netz', ['ean_packaging' => '4000000000017']);
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldNotReceive('propose'));

    $e = ($this->dienst)()->entscheide($this->rootTeam, $neu);

    expect($e->aktion)->toBe('verknuepfen')->and($e->gpId)->toBe($gp->id)->and($e->stufe)->toBe('S0')->and($e->sicherheit)->toBe(1.0)
        ->and($e->automatisch)->toBeTrue()->and($e->kiCalls)->toBe(0);
});

it('Non-Food-Artikel werden vorab abgewiesen (blockiert), schon verknüpfte als bereits_verknuepft', function () {
    $nf = ($this->la)('Duni Salatschale Bagasse 900 ml');
    FoodAlchemistSupplierItemNonfood::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $nf->id, 'ist_nonfood' => true, 'quelle' => 'manuell']);
    $gp = ($this->gp)('Zwiebel: frisch, ganz');
    $schon = ($this->la)('Zwiebel 5kg', [], $gp);

    expect(($this->dienst)()->entscheide($this->rootTeam, $nf)->aktion)->toBe('blockiert')
        ->and(($this->dienst)()->entscheide($this->rootTeam, $schon)->aktion)->toBe('bereits_verknuepft')
        ->and(($this->dienst)()->entscheide($this->rootTeam, $schon)->gpId)->toBe($gp->id);
});

it('ohne passenden Kandidaten: aktion neu aus den Feldern, KEIN Entscheider-Call', function () {
    ($this->ki)($this->zwiebelFelder);
    $la = ($this->la)('Zwiebel frisch ganz');

    $e = ($this->dienst)()->entscheide($this->rootTeam, $la);

    expect($e->aktion)->toBe('neu')->and($e->stufe)->toBe('S2')->and($e->felder['hauptzutat'])->toBe('Zwiebel')->and($e->automatisch)->toBeFalse()
        ->and($this->kiCalls['gp.suggest'])->toBe(1)->and($this->kiCalls['gp.la_abgleich'])->toBe(0);
});

it('KI-Entscheider: verknüpfen mit Kandidat (gp_id auch als String); alles ok + approved + Sicherheit ≥ 0,90 → automatisch', function () {
    $gp = ($this->gp)('Zwiebel: frisch, ganz');
    ($this->ki)($this->zwiebelFelder, fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => (string) $k['kandidaten'][0]['gp_id'], 'begruendung' => 'Zwiebel frisch ganz = Zwiebel: frisch, ganz.', 'sicherheit' => 0.95]);
    $la = ($this->la)('Zwiebel frisch ganz');

    $e = ($this->dienst)()->entscheide($this->rootTeam, $la);

    expect($e->aktion)->toBe('verknuepfen')->and($e->gpId)->toBe($gp->id)->and($e->stufe)->toBe('S6')->and($e->sicherheit)->toBe(0.95)->and($e->automatisch)->toBeTrue()
        ->and($e->zeigtBanner())->toBeTrue();
});

it('Schwelle: Sicherheit < 0,90 oder GP nur tentative → Vorschlag, nicht automatisch', function () {
    $gp = ($this->gp)('Zwiebel: frisch, ganz', ['status' => 'tentative']);
    ($this->ki)($this->zwiebelFelder, fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => $k['kandidaten'][0]['gp_id'], 'begruendung' => 'passt', 'sicherheit' => 0.95]);
    $la = ($this->la)('Zwiebel frisch ganz');
    expect(($this->dienst)()->entscheide($this->rootTeam, $la)->automatisch)->toBeFalse();

    $gp->update(['status' => 'approved']);
    ($this->ki)($this->zwiebelFelder, fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => $k['kandidaten'][0]['gp_id'], 'begruendung' => 'passt wohl', 'sicherheit' => 0.8]);
    $e = ($this->dienst)()->entscheide($this->rootTeam, $la);
    expect($e->aktion)->toBe('verknuepfen')->and($e->automatisch)->toBeFalse()->and($e->zeigtBanner())->toBeTrue();   // Banner ab 0,7
});

it('die KI darf nur aus der Kandidatenliste wählen: fremde gp_id wird verworfen, fehlende Begründung ebenso', function () {
    ($this->gp)('Zwiebel: frisch, ganz');
    ($this->ki)($this->zwiebelFelder, ['aktion' => 'verknuepfen', 'gp_id' => 999999, 'begruendung' => 'erfunden', 'sicherheit' => 0.99]);
    $la = ($this->la)('Zwiebel frisch ganz');
    $e = ($this->dienst)()->entscheide($this->rootTeam, $la);
    expect($e->aktion)->toBe('unsicher')->and($e->gpId)->toBeNull()->and($e->automatisch)->toBeFalse();

    ($this->ki)($this->zwiebelFelder, fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => $k['kandidaten'][0]['gp_id'], 'begruendung' => '', 'sicherheit' => 0.99]);
    expect(($this->dienst)()->entscheide($this->rootTeam, $la)->aktion)->toBe('unsicher');
});

it('Allergen-Guard vor der KI: widerspricht das Profil, wird „verknüpfen“ zu „neu“ (nie automatisch)', function () {
    $gp = ($this->gp)('Zwiebel: frisch, ganz');
    ($this->la)('Zwiebel pur 1 kg', [], $gp, 'nicht_enthalten');
    ($this->ki)($this->zwiebelFelder, fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => $k['kandidaten'][0]['gp_id'], 'begruendung' => 'gleiche Ware', 'sicherheit' => 0.97]);
    $la = ($this->la)('Zwiebel frisch ganz in Sahne', [], null, 'enthalten');

    $e = ($this->dienst)()->entscheide($this->rootTeam, $la);

    expect($e->aktion)->toBe('neu')->and($e->automatisch)->toBeFalse()->and($e->sicherheit)->toBeLessThanOrEqual(0.6)
        ->and(implode(' ', $e->abweichungen))->not->toBe('');
});

it('Merkmals-Diff: Geschmack nur auf einer Seite (Käse: Chili) senkt auf Vorschlag, nie automatisch', function () {
    ($this->gp)('Käse: Chili, frisch, Scheiben', ['flavor' => 'Chili', 'main_ingredient_slug' => 'kaese']);
    ($this->ki)(['hauptzutat' => 'Käse', 'condition' => 'frisch', 'commodity_group_code' => '01', 'processing' => 'Scheiben'],
        fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => $k['kandidaten'][0]['gp_id'], 'begruendung' => 'Käse frisch Scheiben', 'sicherheit' => 0.95]);
    $la = ($this->la)('Käse frisch Scheiben 45 % Fett');

    $e = ($this->dienst)()->entscheide($this->rootTeam, $la);

    expect($e->aktion)->toBe('verknuepfen')->and($e->sicherheit)->toBeLessThanOrEqual(0.6)->and($e->automatisch)->toBeFalse()
        ->and(implode(' ', $e->abweichungen))->toContain('Geschmack');
});

it('harter Filter: anderer Zustand (TK ≠ frisch) und andere Warengruppe fliegen als Kandidat raus', function () {
    ($this->gp)('Zwiebel: TK, ganz', ['condition' => 'TK']);
    ($this->gp)('Zwiebel: frisch, ganz', ['commodity_group_code' => '09']);
    ($this->ki)($this->zwiebelFelder);
    $la = ($this->la)('Zwiebel frisch ganz');

    $e = ($this->dienst)()->entscheide($this->rootTeam, $la);

    expect($e->kandidaten)->toBe([])->and($e->aktion)->toBe('neu');
});

it('ohne KI (--ohne-ki): höchstens exakt oder „unsicher“ mit Kandidaten, keine KI-Calls', function () {
    ($this->gp)('Zwiebel: frisch, ganz');
    $la = ($this->la)('Zwiebel frisch ganz');
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldNotReceive('propose'));

    $e = ($this->dienst)()->entscheide($this->rootTeam, $la, ['ki' => false]);

    expect($e->kiCalls)->toBe(0)->and(in_array($e->aktion, ['unsicher', 'neu'], true))->toBeTrue()->and($e->automatisch)->toBeFalse();
});

it('S7: verknüpfen führt über den normalen Weg aus (Struktur + Zähler), neu legt ein GP aus den Feldern an; Log und Rücknahme', function () {
    $gp = ($this->gp)('Zwiebel: frisch, ganz');
    ($this->ki)($this->zwiebelFelder, fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => $k['kandidaten'][0]['gp_id'], 'begruendung' => 'gleiche Ware', 'sicherheit' => 0.95]);
    $la = ($this->la)('Zwiebel frisch ganz');

    $r = ($this->dienst)()->entscheideProtokolliert($this->rootTeam, $la, ['ausfuehren' => true, 'modus' => 'auto']);

    expect($r['ausgefuehrt'])->toBe('verknuepft')->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->value('gp_id'))->toBe($gp->id)
        ->and($r['log']->ausgefuehrt)->toBeTrue()->and($r['log']->ausgefuehrt_gp_id)->toBe($gp->id)->and($r['log']->modus)->toBe('auto');

    $z = ($this->dienst)()->zuruecknehmen($this->rootTeam, $r['log']->id);
    expect($z['zurueckgenommen'])->toBeTrue()->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->value('gp_id'))->toBeNull()
        ->and($r['log']->fresh()->rueckgenommen_at)->not->toBeNull();

    // „neu“: nie automatisch, aber ausführbar auf Anforderung
    $andere = ($this->la)('Schalotte frisch ganz');
    ($this->ki)(['hauptzutat' => 'Schalotte', 'condition' => 'frisch', 'commodity_group_code' => '01']);
    $e = ($this->dienst)()->entscheide($this->rootTeam, $andere);
    $a = ($this->dienst)()->fuehreAus($this->rootTeam, $andere, $e);
    expect($a['ausgefuehrt'])->toBe('neu_angelegt')->and($a['gp']->name)->toBe('Schalotte: frisch')
        ->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $andere->id)->value('gp_id'))->toBe($a['gp']->id);
});

it('Merkmals-Diff (Einheit): Zustand, Geschmack, Fantasiename, Prozent, Zucker, Bio', function () {
    $gp = ($this->gp)('Schokolade: trocken, 70 % Kakao, ungezuckert', ['condition' => 'trocken', 'main_ingredient_slug' => 'schokolade']);
    $diff = app(MerkmalsDiff::class);

    $gleich = $diff->vergleiche(['condition' => 'trocken'], ['Zartbitter 70 % Kakao ungezuckert'], false, $gp);
    $andere = $diff->vergleiche(['condition' => 'trocken'], ['Schokolade 85 % Kakao gezuckert'], true, $gp);

    expect($gleich['abweichend'])->toBeFalse()
        ->and($andere['abweichend'])->toBeTrue()
        ->and(implode(' ', $andere['abweichungen']))->toContain('Kakao')->toContain('Zucker')->toContain('Bio');
});

it('Prompt gp.la_abgleich: Klasse mittel, Marke ist kein Unterscheidungsmerkmal, KI wählt nur aus der Liste', function () {
    $p = config('foodalchemist.prompts')['gp.la_abgleich'];

    expect($p['klasse'])->toBe('mittel')->and($p['task'])->toContain('verknuepfen')->toContain('kandidaten')->toContain('KEIN Unterscheidungsmerkmal')->toContain('allergen_guard');
});

it('MCP la.GP_ABGLEICH: ruft den Dienst, ohne apply wird nichts verknüpft, apply verlangt confirm, mit confirm wird ausgeführt', function () {
    $gp = ($this->gp)('Zwiebel: frisch, ganz');
    ($this->ki)($this->zwiebelFelder, fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => $k['kandidaten'][0]['gp_id'], 'begruendung' => 'gleiche Ware', 'sicherheit' => 0.95]);
    $la = ($this->la)('Zwiebel frisch ganz');
    $tool = app(ToolRegistry::class)->get('foodalchemist.la.GP_ABGLEICH');
    $ctx = new ToolContext($this->user, $this->rootTeam);

    expect($tool->getSchema()['required'])->toBe(['artikel_id']);
    $lesen = $tool->execute(['artikel_id' => $la->id], $ctx);
    expect($lesen->success)->toBeTrue()->and($lesen->data['aktion'])->toBe('verknuepfen')->and($lesen->data['gp_id'])->toBe($gp->id)->and($lesen->data['ausgefuehrt'])->toBeNull()
        ->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->value('gp_id'))->toBeNull();

    expect($tool->execute(['artikel_id' => $la->id, 'apply' => true], $ctx)->errorCode)->toBe('CONFIRM_REQUIRED');
    $ok = $tool->execute(['artikel_id' => $la->id, 'apply' => true, 'confirm' => true], $ctx);
    expect($ok->success)->toBeTrue()->and($ok->data['ausgefuehrt']['aktion'])->toBe('verknuepft')
        ->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->value('gp_id'))->toBe($gp->id)
        ->and(FoodAlchemistAbgleichLog::where('supplier_item_id', $la->id)->where('modus', 'mcp')->exists())->toBeTrue();
});

it('MCP und Dienst teilen die Pipeline: dieselbe Entscheidung für denselben Artikel', function () {
    ($this->gp)('Zwiebel: frisch, ganz');
    ($this->ki)($this->zwiebelFelder, fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => $k['kandidaten'][0]['gp_id'], 'begruendung' => 'gleiche Ware', 'sicherheit' => 0.75]);
    $la = ($this->la)('Zwiebel frisch ganz');

    $direkt = ($this->dienst)()->entscheide($this->rootTeam, $la);
    $tool = app(ToolRegistry::class)->get('foodalchemist.la.GP_ABGLEICH')->execute(['artikel_id' => $la->id], new ToolContext($this->user, $this->rootTeam));

    expect($tool->data['aktion'])->toBe($direkt->aktion)->and($tool->data['gp_id'])->toBe($direkt->gpId)->and($tool->data['sicherheit'])->toBe(round($direkt->sicherheit, 3));
});

it('Eval-Befehl: schreibt nie (keine Verknüpfung, kein Log), ignoriert die bestehende Verknüpfung, schreibt CSV + Markdown', function () {
    $gp = ($this->gp)('Zwiebel: frisch, ganz');
    ($this->ki)($this->zwiebelFelder, fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => $k['kandidaten'][0]['gp_id'], 'begruendung' => 'gleiche Ware', 'sicherheit' => 0.95]);
    $schon = ($this->la)('Zwiebel frisch ganz', [], $gp);
    $csv = sys_get_temp_dir() . '/eval_' . uniqid() . '.csv';
    file_put_contents($csv, "gruppe;artikel_id;heute_gp_id;heute_gp_name\nmit GP;{$schon->id};{$gp->id};Zwiebel: frisch, ganz\n");
    $out = sys_get_temp_dir() . '/evalout_' . uniqid();
    $vorher = [DB::table('foodalchemist_abgleich_log')->count(), DB::table('foodalchemist_supplier_item_structures')->count(), DB::table('foodalchemist_gps')->count()];

    $this->artisan('foodalchemist:abgleich-eval', ['--csv' => $csv, '--team' => $this->rootTeam->id, '--out' => $out])->assertSuccessful();

    expect([DB::table('foodalchemist_abgleich_log')->count(), DB::table('foodalchemist_supplier_item_structures')->count(), DB::table('foodalchemist_gps')->count()])->toBe($vorher);
    $z = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), file($out . '/abgleich_eval.csv', FILE_IGNORE_NEW_LINES));
    expect($z[0])->toContain('vergleich_automatisch')->toContain('urteil_dominique')
        ->and($z[1][array_search('vergleich_automatisch', $z[0])])->toBe('gleich')   // trotz bestehender Verknüpfung neu entschieden
        ->and(file_get_contents($out . '/abgleich_eval.md'))->toContain('Abgleich-Eval');
});

it('Mandant ohne Besitz am globalen Stamm-GP: Abgleich zeigt darauf, verknüpft aber NICHT — Alternative „eigenes GP im Mandanten“; der Stamm bleibt unberührt', function () {
    $global = ($this->gp)('Zwiebel: frisch, ganz', ['team_id' => null]);
    DB::table('foodalchemist_gps')->where('id', $global->id)->update(['stamm_class' => 'stamm_paket', 'n_las_total' => 3]);
    $mandant = $this->childA;
    $la = FoodAlchemistSupplierItem::create(['team_id' => $mandant->id, 'supplier_id' => $this->sup->id, 'designation' => 'Zwiebel frisch ganz', 'article_number' => 'M1', 'qty' => 1, 'unit_code' => 'kg']);
    ($this->ki)($this->zwiebelFelder, fn ($k) => ['aktion' => 'verknuepfen', 'gp_id' => $k['kandidaten'][0]['gp_id'], 'begruendung' => 'gleiche Ware', 'sicherheit' => 0.97]);

    $e = ($this->dienst)()->entscheide($mandant, $la);

    expect($e->aktion)->toBe('verknuepfen')->and($e->gpId)->toBe($global->id)->and($e->darfVerknuepfen)->toBeFalse()->and($e->alternative)->toBe('eigenes_gp_im_mandanten')
        ->and($e->automatisch)->toBeFalse();
    expect(fn () => ($this->dienst)()->fuehreAus($mandant, $la, $e))->toThrow(\RuntimeException::class, 'gehört dem Team nicht');

    $eigen = ($this->dienst)()->fuehreAus($mandant, $la, $e, ['eigenes_gp' => true]);
    expect($eigen['ausgefuehrt'])->toBe('neu_angelegt')->and((int) $eigen['gp']->team_id)->toBe((int) $mandant->id)
        ->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->value('gp_id'))->toBe($eigen['gp']->id)
        ->and((int) $global->fresh()->n_las_total)->toBe(3);   // Stamm unverändert
});

it('Eval: jeder KI-Aufruf steht danach im ai_call_log (genau die erwartete Zahl), sonst ist nichts geschrieben', function () {
    ($this->gp)('Zwiebel: frisch, ganz');
    $la = ($this->la)('Zwiebel frisch ganz');
    // KI-Fake schreibt wie das echte Gateway je Aufruf EINE ai_call_log-Zeile und meldet deren ID
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->andReturnUsing(function ($key, $kontext = []) {
            $id = DB::table('foodalchemist_ai_call_log')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->rootTeam->id, 'feature' => $key,
                'tokens_in' => 100, 'tokens_out' => 20, 'created_at' => now(), 'updated_at' => now()]);
            $werte = $key === 'gp.suggest' ? $this->zwiebelFelder
                : ['aktion' => 'verknuepfen', 'gp_id' => $kontext['kandidaten'][0]['gp_id'], 'begruendung' => 'gleiche Ware', 'sicherheit' => 0.95];

            return new AiProposal($werte, 0.8, 'Mock', [], $key, 0, $id);
        });
    });
    $vorher = ['log' => DB::table('foodalchemist_ai_call_log')->count(), 'abgleich' => DB::table('foodalchemist_abgleich_log')->count(),
        'struktur' => DB::table('foodalchemist_supplier_item_structures')->count(), 'gps' => DB::table('foodalchemist_gps')->count()];

    $this->artisan('foodalchemist:abgleich-eval', ['--ids' => (string) $la->id, '--team' => $this->rootTeam->id, '--out' => sys_get_temp_dir() . '/evalkosten_' . uniqid()])->assertSuccessful();

    expect(DB::table('foodalchemist_ai_call_log')->count())->toBe($vorher['log'] + 2)   // gp.suggest + gp.la_abgleich
        ->and(DB::table('foodalchemist_ai_call_log')->whereIn('feature', ['gp.suggest', 'gp.la_abgleich'])->count())->toBe($vorher['log'] + 2)
        ->and(DB::table('foodalchemist_abgleich_log')->count())->toBe($vorher['abgleich'])
        ->and(DB::table('foodalchemist_supplier_item_structures')->count())->toBe($vorher['struktur'])
        ->and(DB::table('foodalchemist_gps')->count())->toBe($vorher['gps']);
});
