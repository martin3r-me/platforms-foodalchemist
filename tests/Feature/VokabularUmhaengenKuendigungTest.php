<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan B (c), Kurator 18.10.2026: Kündigt ein Mandant ein Abo, werden die genutzten Fassungen zu seinen Arbeitszeilen. Deren Vokabular-Verweise
 * zeigen nie auf Master-Zeilen: sichtbare Zeile gleichen Codes, sonst NULL. Die Servierform (Pflichtfeld) wird, wenn sie fehlt, genau einmal
 * beim Mandanten nachgelegt und als art „kuendigung“ gemerkt — auch beim Mandanten ohne Grundeinstellungen-Paket.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    \Platform\FoodAlchemist\Support\StammPaket::freischalten($this->broich);   // Abo setzt das Stamm-Paket voraus (nicht das Grundeinstellungen-Paket)
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->chef = $this->makeUser($this->broich, 'Broich Chef', 'owner');

    $zeile = fn (string $tabelle, array $w) => DB::table($tabelle)->insertGetId(['uuid' => (string) Str::uuid7(), 'created_at' => now(), 'updated_at' => now()] + $w);
    $hg = $zeile('foodalchemist_recipe_main_groups', ['team_id' => $this->master->id, 'code' => 'SAU', 'label' => 'Saucen']);
    $this->mKategorie = $zeile('foodalchemist_recipe_categories', ['team_id' => $this->master->id, 'main_group_id' => $hg, 'code' => 'SAU-JUS', 'label' => 'Jus']);
    $this->mKlasse = $zeile('foodalchemist_markup_classes', ['team_id' => $this->master->id, 'code' => 'A', 'label' => 'Klasse A', 'class_factor_pct' => 140]);
    $this->bKlasse = $zeile('foodalchemist_markup_classes', ['team_id' => $this->broich->id, 'code' => 'A', 'label' => 'Unsere A', 'class_factor_pct' => 100]);
    $this->mForm = $zeile('foodalchemist_serving_forms', ['team_id' => $this->master->id, 'code' => 'sauciere', 'label' => 'Sauciere', 'legacy_id' => 4711]);

    $rezept = function (string $name) {
        $r = $this->makeRecipe($this->master, $name);
        $r->update(['category_id' => $this->mKategorie, 'markup_class_id' => $this->mKlasse]);
        FoodAlchemistRecipeDarreichung::create(['team_id' => $this->master->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->mForm,
            'markup_class_id' => $this->mKlasse, 'is_standard' => true]);

        return $r;
    };
    $inhalte = app(InhaltsFreigabeService::class);
    $abos = app(AboService::class);
    $fs = app(FassungService::class);
    $this->abo = function (string $name) use ($rezept, $inhalte, $abos, $fs) {
        $r = $rezept($name);
        $sammlung = $inhalte->sammlungAnlegen($this->master, "Sammlung {$name}", null, $this->kurator);
        $inhalte->sammlungHinzu($this->master, $sammlung, 'recipe', [$r->id], $this->kurator);
        $abo = $abos->abonnieren($this->master, $this->broich, $sammlung, $this->kurator);
        $f = FoodAlchemistRecipe::mitFassungen()->findOrFail($fs->veroeffentlichen($this->master, $r->id, 'Erst', $this->kurator)['fassung_id']);
        $eigen = $this->makeRecipe($this->broich, "Broich mit {$name}");   // Broich nutzt die Fassung als Zutat → wird kopiert
        $this->makeIngredient($eigen, $name, null, '100')->update(['referenced_recipe_id' => $f->id]);

        return [$abo, $f];
    };
    $this->svc = app(AboKuendigungService::class);
    $this->actingAs($this->chef);
});

it('★ Kündigung: Kategorie → Broichs Kategorie gleichen Codes, Servierform nachgelegt — nie eine Master-ID', function () {
    [$abo, $fassung] = ($this->abo)('Kalbsjus');
    $hg = DB::table('foodalchemist_recipe_main_groups')->insertGetId(['uuid' => (string) Str::uuid7(), 'team_id' => $this->broich->id, 'code' => 'SAU', 'label' => 'Saucen',
        'created_at' => now(), 'updated_at' => now()]);
    $bKategorie = DB::table('foodalchemist_recipe_categories')->insertGetId(['uuid' => (string) Str::uuid7(), 'team_id' => $this->broich->id, 'main_group_id' => $hg,
        'code' => 'SAU-JUS', 'label' => 'Unser Jus', 'created_at' => now(), 'updated_at' => now()]);

    $r = $this->svc->kuendigen($this->broich, $abo->id, $this->chef, bestaetigt: true);

    $kopie = FoodAlchemistRecipe::findOrFail($r['kopien'][$fassung->id]);
    $dar = FoodAlchemistRecipeDarreichung::where('recipe_id', $kopie->id)->sole();
    $form = DB::table('foodalchemist_serving_forms')->find($dar->serving_form_id);
    expect($fassung->fresh()->category_id)->toBe($this->mKategorie)                          // Fassung zeigt auf die Master-Kategorie …
        ->and((int) $kopie->category_id)->toBe($bKategorie)                                     // … die Kopie auf Broichs gleichen Codes
        ->and($dar->markup_class_id)->toBeNull()                                               // Fassungen tragen keine Master-Preisklasse
        ->and((int) $form->team_id)->toBe($this->broich->id)->and($form->code)->toBe('sauciere')
        ->and($form->legacy_id)->toBeNull()->and((int) $dar->serving_form_id)->not->toBe($this->mForm)
        ->and($r['servierformen_nachgelegt'])->toBe(1)
        ->and(DB::table('foodalchemist_paket_kopien')->where('team_id', $this->broich->id)->where('tabelle', 'serving_forms')
            ->where('quell_id', $this->mForm)->value('art'))->toBe('kuendigung');
});

it('Kündigung ohne passende Mandanten-Kategorie: Kategorie der Kopie ist NULL, nie die Master-ID', function () {
    [$abo, $fassung] = ($this->abo)('Kalbsjus');

    $r = $this->svc->kuendigen($this->broich, $abo->id, $this->chef, bestaetigt: true);

    $kopie = FoodAlchemistRecipe::findOrFail($r['kopien'][$fassung->id]);
    expect($fassung->fresh()->category_id)->toBe($this->mKategorie)->and($kopie->category_id)->toBeNull();
});

it('zweite Kündigung legt dieselbe Servierform nicht noch einmal an, sondern nutzt die Kopie', function () {
    [$abo1] = ($this->abo)('Kalbsjus');
    [$abo2, $f2] = ($this->abo)('Geflügeljus');

    $this->svc->kuendigen($this->broich, $abo1->id, $this->chef, bestaetigt: true);
    $r2 = $this->svc->kuendigen($this->broich, $abo2->id, $this->chef, bestaetigt: true);

    $formen = DB::table('foodalchemist_serving_forms')->where('team_id', $this->broich->id)->where('code', 'sauciere')->pluck('id');
    $dar = FoodAlchemistRecipeDarreichung::where('recipe_id', $r2['kopien'][$f2->id])->sole();
    expect($formen)->toHaveCount(1)->and((int) $dar->serving_form_id)->toBe((int) $formen[0])->and($r2['servierformen_nachgelegt'])->toBe(0);
});

it('Umhänger: schon sichtbare Zeile (global) bleibt, Master-Zeile ohne Gegenstück → NULL, eigene vor globaler', function () {
    $u = app(\Platform\FoodAlchemist\Support\VokabularUmhaenger::class);
    $global = DB::table('foodalchemist_markup_classes')->insertGetId(['uuid' => (string) Str::uuid7(), 'team_id' => null, 'code' => 'G', 'label' => 'Global',
        'created_at' => now(), 'updated_at' => now()]);
    $mNurMaster = DB::table('foodalchemist_markup_classes')->insertGetId(['uuid' => (string) Str::uuid7(), 'team_id' => $this->master->id, 'code' => 'Z',
        'label' => 'Nur Master', 'created_at' => now(), 'updated_at' => now()]);

    expect($u->fuer($this->broich, 'foodalchemist_markup_classes', $global))->toBe($global)
        ->and($u->fuer($this->broich, 'foodalchemist_markup_classes', $mNurMaster))->toBeNull()
        ->and($u->fuer($this->broich, 'foodalchemist_markup_classes', $this->mKlasse))->toBe($this->bKlasse)
        ->and($u->fuer($this->broich, 'foodalchemist_markup_classes', null))->toBeNull();
});

