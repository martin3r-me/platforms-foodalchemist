<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\MachbarkeitService;
use Platform\FoodAlchemist\Services\TeamProfilService;
use Platform\FoodAlchemist\Support\MachbarkeitKontext;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\MachbarkeitCheckTool;
use Platform\FoodAlchemist\Tools\TeamProfilGetTool;
use Platform\FoodAlchemist\Tools\TeamProfilPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §6 / §12 E: Teamprofil (dreiwertig) und Machbarkeits-Check ohne KI.
 * Warnung nur bei AUSDRÜCKLICH nicht_vorhanden; Standardausstattung zählt ohne Gegenangabe als vorhanden.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(TeamProfilService::class);
    $this->check = app(MachbarkeitService::class);
    $this->admin = $this->makeUser($this->childA, 'Chef', 'owner');
    $this->member = $this->makeUser($this->childA, 'Koch', 'member');
    $this->viewer = $this->makeUser($this->childA, 'Gast', 'viewer');
    $this->rootUser = $this->makeUser($this->rootTeam, 'Oberhaupt', 'owner');

    $eq = fn (string $slug, string $name, bool $standard = false) => FoodAlchemistVocabKochequipment::create([
        'team_id' => null, 'slug' => $slug, 'name' => $name, 'sort_order' => 10, 'is_standard' => $standard,
    ]);
    $this->topf = $eq('topf', 'Topf', true);
    $this->kombi = $eq('kombidaempfer', 'Kombidämpfer');
    $this->sous = $eq('sous-vide', 'Sous-vide-Stick');

    $this->braucht = fn ($recipe, ...$equipment) => collect($equipment)->each(fn ($e) => DB::table('foodalchemist_recipe_equipment')->insert(
        ['recipe_id' => $recipe->id, 'equipment_id' => $e->id, 'created_at' => now(), 'updated_at' => now()]));
    $this->kontext = fn ($team = null) => new MachbarkeitKontext($team ?? $this->childA, $this->admin);
    $this->status = fn ($recipe, $team = null) => $this->check->pruefe(($this->kontext)($team), [$recipe->id])[0];
});

it('dreiwertig: keine Zeile = unbekannt, Standardausstattung wirkt als vorhanden, ausdrücklich nicht_vorhanden schlägt sie', function () {
    $a = fn () => collect($this->svc->ausstattung($this->childA))->keyBy('equipment_id');

    expect($a()[$this->kombi->id]['status'])->toBe('unbekannt')->and($a()[$this->kombi->id]['wirksam'])->toBe('unbekannt')
        ->and($a()[$this->topf->id]['status'])->toBe('unbekannt')->and($a()[$this->topf->id]['wirksam'])->toBe('vorhanden');

    $this->svc->setzeEquipment($this->childA, $this->admin, $this->topf->id, 'nicht_vorhanden');
    $this->svc->setzeEquipment($this->childA, $this->admin, $this->kombi->id, 'vorhanden', 2);
    expect($a()[$this->topf->id]['wirksam'])->toBe('nicht_vorhanden')
        ->and($a()[$this->kombi->id]['wirksam'])->toBe('vorhanden')->and($a()[$this->kombi->id]['anzahl'])->toBe(2);

    $this->svc->setzeEquipment($this->childA, $this->admin, $this->topf->id, null);   // zurück auf unbekannt
    expect($a()[$this->topf->id]['wirksam'])->toBe('vorhanden')
        ->and(DB::table('foodalchemist_team_profile_equipment')->where('equipment_id', $this->topf->id)->count())->toBe(0);
});

it('Machbarkeit: Warnung nur bei ausdrücklich nicht_vorhanden; unbekannt gibt einen Hinweis; Standardausstattung warnt nie ohne Gegenangabe', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Jus');
    ($this->braucht)($r, $this->topf, $this->kombi);

    $s = ($this->status)($r);
    expect($s['status'])->toBe('hinweis')                                    // Kombi unbekannt, Topf Standard
        ->and($s['warnungen'])->toBe([])->and($s['hinweise'][0]['equipment'])->toBe('Kombidämpfer')->and($s['hinweise'][0]['grund'])->toBe('Profil unvollständig');

    $this->svc->setzeEquipment($this->childA, $this->admin, $this->kombi->id, 'nicht_vorhanden');
    $s = ($this->status)($r);
    expect($s['status'])->toBe('warnung')->and($s['warnungen'][0]['equipment'])->toBe('Kombidämpfer');

    $this->svc->setzeEquipment($this->childA, $this->admin, $this->kombi->id, 'vorhanden');
    expect(($this->status)($r)['status'])->toBe('ok');
});

it('Rezept ohne Equipment-Angabe ist „ohne_angabe“, nie „ok"; unsichtbare Rezepte werden gemeldet', function () {
    $leer = $this->makeRecipe($this->rootTeam, 'Salat');
    expect(($this->status)($leer)['status'])->toBe('ohne_angabe');

    $fremd = $this->makeRecipe($this->childB, 'Geschwister-Rezept');   // Geschwister: für childA nicht sichtbar
    expect(($this->status)($fremd)['status'])->toBe('nicht_sichtbar');
});

it('Machbarkeit schließt Unter-Rezepte ein (bis 3 Ebenen), ist zyklensicher und nennt die Fundstelle', function () {
    $haupt = $this->makeRecipe($this->rootTeam, 'Gericht');
    $basis = $this->makeRecipe($this->rootTeam, 'Basis');
    $tief = $this->makeRecipe($this->rootTeam, 'Tief');
    $this->makeIngredient($haupt, 'Basis');
    $this->makeIngredient($basis, 'Tief');
    $this->makeIngredient($tief, 'Gericht');                                     // Zyklus zurück zum Hauptrezept
    DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $haupt->id)->update(['referenced_recipe_id' => $basis->id]);
    DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $basis->id)->update(['referenced_recipe_id' => $tief->id]);
    DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $tief->id)->update(['referenced_recipe_id' => $haupt->id]);
    ($this->braucht)($tief, $this->sous);
    $this->svc->setzeEquipment($this->childA, $this->admin, $this->sous->id, 'nicht_vorhanden');

    $s = ($this->status)($haupt);
    expect($s['status'])->toBe('warnung')->and($s['warnungen'][0]['benoetigt_in_rezept'])->toBe('Tief');
});

it('Kontext: Ziel-Team entscheidet — dasselbe Rezept, andere Küche, anderes Ergebnis', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Jus');
    ($this->braucht)($r, $this->kombi);
    $this->svc->setzeEquipment($this->childA, $this->admin, $this->kombi->id, 'nicht_vorhanden');
    $this->svc->setzeEquipment($this->childB, $this->makeUser($this->childB, 'B', 'owner'), $this->kombi->id, 'vorhanden');

    expect(($this->status)($r, $this->childA)['status'])->toBe('warnung')
        ->and(($this->status)($r, $this->childB)['status'])->toBe('ok');
});

it('Rechte: Lesen darf nicht schreiben, Kuratieren darf; Schreiben geht nie in ein fremdes Team', function () {
    expect(fn () => $this->svc->setzeProfil($this->childA, $this->viewer, ['max_pax_parallel' => 50]))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->svc->setzeEquipment($this->childA, $this->viewer, $this->kombi->id, 'vorhanden'))->toThrow(FaRechtFehltException::class);

    $this->svc->setzeProfil($this->childA, $this->member, ['max_pax_parallel' => 80, 'convenience_grad' => 'from_scratch']);
    expect($this->svc->lies($this->childA, $this->childA)['profil'])->toMatchArray(['max_pax_parallel' => 80, 'convenience_grad' => 'from_scratch']);

    // Oberteam-Nutzer hat im Unter-Team die Rechte der Kette, schreibt aber über das ÜBERGEBENE Team — ein Profil gehört dem Team, in dem er arbeitet.
    $this->svc->setzeProfil($this->rootTeam, $this->rootUser, ['max_pax_parallel' => 500]);
    expect($this->svc->lies($this->childA, $this->childA)['profil']['max_pax_parallel'])->toBe(80)
        ->and($this->svc->lies($this->rootTeam, $this->rootTeam)['profil']['max_pax_parallel'])->toBe(500);
});

it('Lesen: Oberteam liest Unter-Teams (Brille), Unter-Teams lesen weder Eltern noch Geschwister', function () {
    $this->svc->setzeProfil($this->childA, $this->admin, ['notes' => 'Rooftop']);

    expect($this->svc->lies($this->rootTeam, $this->childA)['profil']['notes'])->toBe('Rooftop');
    expect(fn () => $this->svc->lies($this->childB, $this->childA))->toThrow(FaRechtFehltException::class);   // Geschwister
    expect(fn () => $this->svc->lies($this->childA, $this->rootTeam))->toThrow(FaRechtFehltException::class);  // Eltern
});

it('Validierung: Convenience nur die DNA-Werte, Pax ab 1, Flächen mit Name und Monaten 1–12, Equipment nur aus dem sichtbaren Vokabular', function () {
    expect(fn () => $this->svc->setzeProfil($this->childA, $this->admin, ['convenience_grad' => 'wild']))->toThrow(RuntimeException::class, 'convenience_grad');
    expect(fn () => $this->svc->setzeProfil($this->childA, $this->admin, ['max_pax_parallel' => 0]))->toThrow(RuntimeException::class, 'ab 1');
    expect(fn () => $this->svc->setzeFlaeche($this->childA, $this->admin, null, ' ', 10))->toThrow(RuntimeException::class, 'Namen');
    expect(fn () => $this->svc->setzeFlaeche($this->childA, $this->admin, null, 'Terrasse', 10, 0, 13))->toThrow(RuntimeException::class, '1 und 12');
    expect(fn () => $this->svc->setzeEquipment($this->childA, $this->admin, 99999, 'vorhanden'))->toThrow(RuntimeException::class, 'nicht sichtbar');

    $f = $this->svc->setzeFlaeche($this->childA, $this->admin, null, 'Terrasse', 120, 5, 9);
    expect($this->svc->lies($this->childA, $this->childA)['flaechen'])->toHaveCount(1);
    $this->svc->loescheFlaeche($this->childA, $this->admin, $f->id);
    expect($this->svc->lies($this->childA, $this->childA)['flaechen'])->toBe([]);
});

it('Vokabular-Import (delete) löscht kein Profil: kein Fremdschlüssel auf equipment_id', function () {
    $this->svc->setzeEquipment($this->childA, $this->admin, $this->kombi->id, 'vorhanden');

    DB::table('foodalchemist_vocab_kitchen_equipment')->delete();   // wie ImportSliceCommand

    expect(DB::table('foodalchemist_team_profile_equipment')->count())->toBe(1);
});

it('Flag Standardausstattung ist per Default aus', function () {
    expect($this->kombi->fresh()->is_standard)->toBeFalse()->and($this->topf->fresh()->is_standard)->toBeTrue();
    $neu = FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => 'x', 'name' => 'X']);
    expect((bool) $neu->fresh()->is_standard)->toBeFalse();
});

it('MCP: team_profile.PUT/GET und machbarkeit.CHECK, Rechte werden durchgesetzt und das Oberteam liest per team_id', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Jus');
    ($this->braucht)($r, $this->kombi);

    $put = (new TeamProfilPutTool)->execute([
        'profil' => ['max_pax_parallel' => 60],
        'equipment' => [['equipment_id' => $this->kombi->id, 'status' => 'nicht_vorhanden']],
        'flaechen' => [['name' => 'Saal', 'max_pax' => 200]],
    ], new ToolContext($this->admin, $this->childA));
    expect($put->success)->toBeTrue();

    $verboten = (new TeamProfilPutTool)->execute(['profil' => ['max_pax_parallel' => 1]], new ToolContext($this->viewer, $this->childA));
    expect($verboten->success)->toBeFalse()->and($verboten->errorCode)->toBe('FORBIDDEN');

    $get = (new TeamProfilGetTool)->execute(['team_id' => $this->childA->id], new ToolContext($this->rootUser, $this->rootTeam));
    expect($get->success)->toBeTrue()->and($get->data['profil']['max_pax_parallel'])->toBe(60)->and($get->data['flaechen'])->toHaveCount(1);

    $fremd = (new TeamProfilGetTool)->execute(['team_id' => $this->rootTeam->id], new ToolContext($this->admin, $this->childA));
    expect($fremd->errorCode)->toBe('FORBIDDEN');

    $chk = (new MachbarkeitCheckTool)->execute(['recipe_ids' => [$r->id], 'team_id' => $this->childA->id], new ToolContext($this->rootUser, $this->rootTeam));
    expect($chk->success)->toBeTrue()->and($chk->data['ergebnisse'][0]['status'])->toBe('warnung');
});
