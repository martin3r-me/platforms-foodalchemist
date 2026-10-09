<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\ConformanceService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Support\GpKorrektur;
use Platform\FoodAlchemist\Support\RegelwerkLeser;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 80 Teil G — richtig anlegen statt nachprüfen. Regeln kommen aus Wissens-Dossiers (Fixtures = Auszüge
 * der echten Dossiers vom 05.10.), nicht aus dem Code.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dossier = function (string $slug, string $md): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug, 'category' => 'regelwerk',
            'content_md' => $md, 'version' => 1, 'content_hash' => hash('sha256', $md), 'char_count' => strlen($md),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        RegelwerkLeser::vergessen();
    };
    ($this->dossier)('regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form', "## §2\n"
        . "- Verarbeitungs-Suffixe: `brunoise`, `würfel/wuerfel`, `gehackt`, `geschnitten`, `gerieben`, `geröstet`, `gemahlen`, `in scheiben`.\n");
    ($this->dossier)('regelwerk-basisrezepte-10-12-naming-grundprinzip-typ-vokabular--1-2-typ-vokabular-kontrolliert',
        "| Hauptgruppe | Erlaubte Typen |\n|---|---|\n| Pürees & Marken | `Püree`, `Mark` |\n| Cremes & Cremaux | `Crème`, `Curd` |\n| Beilagen | `Beilage`, `Polenta` |\n");
    $this->gp = function (string $name, string $zustand, ?string $bio = null, string $status = 'approved'): FoodAlchemistGp {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['condition' => $zustand, 'bio' => $bio, 'status' => $status]);

        return $gp->fresh();
    };
});

it('liest Listen aus einem Dossier (Schrägstrich = Schreibvarianten)', function () {
    expect(GpKorrektur::verarbeitungsSuffixe())->toContain('würfel')->toContain('wuerfel')->toContain('in scheiben');
});

it('§2: frisches GP in Schnittform wird zur Rohform, die Verarbeitung wandert in die Notiz', function () {
    $wuerfel = ($this->gp)('Schalotten: frisch, Wuerfel 5 mm', 'frisch');
    $ganz = ($this->gp)('Schalotten: frisch, ganz', 'frisch');

    $k = GpKorrektur::korrigiere($this->rootTeam, $wuerfel->id, ['bio_pref' => 'conventional']);

    expect($k['gp_id'])->toBe($ganz->id)
        ->and($k['notiz'])->toBe('würfel')
        ->and($k['gruende'][0])->toContain('§2');
});

it('§2 nutzt die GP-Felder (Hauptzutat, Verarbeitung) vor dem Namen', function () {
    $gewuerfelt = ($this->gp)('Karotte: frisch', 'frisch');
    $gewuerfelt->update(['main_ingredient_slug' => 'karotte', 'processing' => 'gewürfelt']);
    $roh = ($this->gp)('Möhre: frisch, ganz', 'frisch');          // anderer Name, gleiche Hauptzutat
    $roh->update(['main_ingredient_slug' => 'karotte']);

    $k = GpKorrektur::korrigiere($this->rootTeam, $gewuerfelt->id, []);

    expect($k['gp_id'])->toBe($roh->id)->and($k['notiz'])->toBe('würfel');
});

it('§2 lässt Trockenware (Pfeffer gemahlen, §5 F2.3) und Voll-Convenience stehen', function () {
    $pfeffer = ($this->gp)('Pfeffer schwarz: trocken, gemahlen', 'trocken');
    ($this->gp)('Pfeffer schwarz: trocken, Koerner', 'trocken');
    $wuerfel = ($this->gp)('Zwiebeln: frisch, Wuerfel', 'frisch');
    ($this->gp)('Zwiebeln: frisch, ganz', 'frisch');

    expect(GpKorrektur::korrigiere($this->rootTeam, $pfeffer->id, []))->toBeNull()
        ->and(GpKorrektur::korrigiere($this->rootTeam, $wuerfel->id, ['convenience' => 'voll_convenience']))->toBeNull();
});

it('§10: Bio ohne Bio-Vorgabe wird konventionell — mit Bio-Vorgabe nicht; ohne Gegenstück bleibt es', function () {
    $bioWasser = ($this->gp)('Wasser: still, Bio', 'frisch', 'bio');
    $wasser = ($this->gp)('Wasser: still', 'frisch');
    $bioReis = ($this->gp)('Reis Parboiled: trocken, Bio', 'trocken', 'bio');

    expect(GpKorrektur::korrigiere($this->rootTeam, $bioWasser->id, ['bio_pref' => 'conventional'])['gp_id'])->toBe($wasser->id)
        ->and(GpKorrektur::korrigiere($this->rootTeam, $bioWasser->id, ['bio_pref' => 'bio']))->toBeNull()
        ->and(GpKorrektur::korrigiere($this->rootTeam, $bioReis->id, []))->toBeNull();
});

it('Entwurfs-GP ist kein Tauschziel', function () {
    $wuerfel = ($this->gp)('Karotten: frisch, Wuerfel', 'frisch');
    ($this->gp)('Karotten: frisch, ganz', 'frisch', null, 'tentative');

    expect(GpKorrektur::korrigiere($this->rootTeam, $wuerfel->id, []))->toBeNull();
});

it('§1.2: der Generator bringt das Typ-Präfix in die Schreibweise des Vokabulars, Unbekanntes bleibt', function () {
    $kanon = Closure::bind(fn (string $n, bool $vk) => $this->kanonischerTyp($n, $vk), app(RecipeGeneratorService::class), RecipeGeneratorService::class);

    expect($kanon('creme: Vanille', false))->toBe('Crème: Vanille')
        ->and($kanon('pueree: Petersilienwurzel', false))->toBe('Püree: Petersilienwurzel')
        ->and($kanon('Gemüsebeilage: Karotte', false))->toBe('Gemüsebeilage: Karotte')
        ->and($kanon('creme: Vanille', true))->toBe('creme: Vanille');
});

it('Code-Prüfung meldet §1.2, §2, §10 und §8.3 ohne KI', function () {
    $r = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'gb_karotte', 'name' => 'Gemüsebeilage: Karotte',
        'status' => 'draft', 'description' => 'Glasierte Karotten.']);
    $g = $this->unitG($this->rootTeam)->id;
    foreach ([($this->gp)('Schalotten: frisch, Wuerfel 5 mm', 'frisch'), ($this->gp)('Wasser: still, Bio', 'frisch', 'bio')] as $i => $gp) {
        FoodAlchemistRecipeIngredient::create(['team_id' => $r->team_id, 'recipe_id' => $r->id, 'gp_id' => $gp->id,
            'raw_text' => $gp->name, 'quantity' => '100', 'unit_vocab_id' => $g, 'position' => $i + 1]);
    }

    $paragraphen = array_column(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, $r->id), 'paragraph');

    expect($paragraphen)->toContain('§1.2')->toContain('§2')->toContain('§10')->toContain('§8.3');
});

it('Paragraph-Stamm vereinheitlicht die Labels der KI', function () {
    expect(ConformanceService::paragraphStamm('§11 F11.1'))->toBe('11')
        ->and(ConformanceService::paragraphStamm('§11a/F11.1'))->toBe('11')
        ->and(ConformanceService::paragraphStamm('Regelwerk Basisrezepte §1.2'))->toBe('1.2')
        ->and(ConformanceService::paragraphStamm('§2.2'))->toBe('2')
        ->and(ConformanceService::paragraphStamm('Dossier: Immer'))->toBe('');
});
