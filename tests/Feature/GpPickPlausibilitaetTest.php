<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Pick — der Fall „Tomaten: TK, getrocknet" mit 1.600 g (Lauf 65, 2026-09-07).
 *
 * 57,9 % der Einsatzmasse einer Cremesuppe als Trockentomate haben JEDE Stufe passiert, den
 * Konformitäts-Critic eingeschlossen (er meldete drei andere Befunde). Warum:
 *
 *  - Der Kandidaten-Payload an das Modell trug nur `{id, name, score}` — die
 *    produktbestimmende Achse musste es sich aus dem Namen zusammenreimen (D2).
 *  - Der Vorschlag des Modells hat Vorrang (`match_method = 'gemini_proposed'`,
 *    Konfidenz 1.0), und `validiereProposedGp` prüft nur Existenz und Sichtbarkeit.
 *  - Eine Mengen-Plausibilität gab es NIRGENDS: die Spalte `Anteil %` im Zutaten-Editor ist
 *    ausdrücklich „reine Diagnose, read-only", das Sous-Chef-Gate wirkt nur auf
 *    `fremdkoerper`, und der §-Pass ist ein LLM-Call mit Default `weich`.
 *
 * Ein LLM-Pass ist für diese Frage das falsche Werkzeug: die Rechnung ist exakt, und was
 * exakt entscheidbar ist, darf nicht von einem Sampling abhängen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->einheit = fn (string $slug, string $dim) => FoodAlchemistVocabEinheit::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'slug' => $slug],
        ['display_de' => $slug, 'dimension' => $dim, 'default_in_g' => $dim === 'mass' ? 1 : null],
    );
    $this->zutat = function ($recipe, ?int $gpId, float $menge, $einheit, string $text, int $pos = 1): void {
        DB::table('foodalchemist_recipe_ingredients')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'gp_id' => $gpId,
            'raw_text' => $text, 'display_name' => $text, 'quantity' => $menge,
            'unit_vocab_id' => $einheit?->id, 'position' => $pos,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    /** GP mit Verarbeitungs-Feld — der Marker steckt in `processing`, nicht in `condition`. */
    $this->gpMitVerarbeitung = function (string $name, ?string $processing = null) {
        $gp = $this->makeGp($this->rootTeam, $name);
        if ($processing !== null) {
            DB::table('foodalchemist_gps')->where('id', $gp->id)->update(['processing' => $processing]);
        }

        return $gp;
    };
});

it('D: Trockentomate als Hauptmasse ergibt einen HARTEN §6-Befund, deterministisch', function () {
    $suppe = $this->makeRecipe($this->rootTeam, 'Crème-Suppe: Tomate-Speck', ['status' => 'draft']);
    $g = ($this->einheit)('g', 'mass');

    // Der Ist-Zustand von GP 13757: „TK" im Namen, `condition` NULL, getrocknet in der
    // Verarbeitung. Ein zustand-basierter Check wäre hier blind — darum prüft die Regel
    // Name + processing + form.
    ($this->zutat)($suppe, ($this->gpMitVerarbeitung)('Tomaten: TK, getrocknet', 'getrocknet')->id, 1600, $g, 'Tomaten', 1);
    ($this->zutat)($suppe, $this->makeGp($this->rootTeam, 'Bacon: frisch, geschnitten')->id, 120, $g, 'Bacon', 2);
    ($this->zutat)($suppe, $this->makeGp($this->rootTeam, 'Sahne: konserviert, 30 % Fett')->id, 200, $g, 'Sahne', 3);

    $befunde = app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $suppe->id);

    expect($befunde)->toHaveCount(1)
        ->and($befunde[0]['schweregrad'])->toBe('hart')
        ->and($befunde[0]['paragraph'])->toBe('§6')
        ->and($befunde[0]['konfidenz'])->toBe(1.0)
        ->and($befunde[0]['begruendung'])->toContain('getrocknet')
        ->and($befunde[0]['begruendung'])->toContain('83,3 %');
});

it('D: Tomatenmark als Röstbasis ist KEIN Befund — die Regel darf Saucen nicht flaggen', function () {
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Tomate', ['status' => 'draft']);
    $g = ($this->einheit)('g', 'mass');

    ($this->zutat)($sauce, ($this->gpMitVerarbeitung)('Tomatenmark: konserviert, konzentriert', 'konzentrat')->id, 60, $g, 'Tomatenmark', 1);
    ($this->zutat)($sauce, $this->makeGp($this->rootTeam, 'Tomaten / Pelati: konserviert, ganz')->id, 1920, $g, 'Pelati', 2);

    expect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $sauce->id))->toBe([]);
});

it('D: bei nicht bestimmbarer Masse schweigt die Regel (Stück-Zeile darf den Anteil nicht hochrechnen)', function () {
    $suppe = $this->makeRecipe($this->rootTeam, 'Suppe: gemischt', ['status' => 'draft']);

    ($this->zutat)($suppe, ($this->gpMitVerarbeitung)('Tomaten: TK, getrocknet', 'getrocknet')->id, 1600, ($this->einheit)('g', 'mass'), 'Tomaten', 1);
    // Eine Stück-Zeile trägt 0 g in die Summe und würde den Anteil künstlich auf 100 % treiben —
    // dieselbe Falle, die im Yield-Check des DataQualityService dokumentiert ist.
    ($this->zutat)($suppe, $this->makeGp($this->rootTeam, 'Lorbeerblatt')->id, 2, ($this->einheit)('stk', 'count'), 'Lorbeerblatt', 2);

    expect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $suppe->id))->toBe([]);
});

it('D: der Schwellwert ist konfigurierbar — auf 0 schaltet die Regel aus', function () {
    $suppe = $this->makeRecipe($this->rootTeam, 'Crème-Suppe: Tomate-Speck', ['status' => 'draft']);
    $g = ($this->einheit)('g', 'mass');
    ($this->zutat)($suppe, ($this->gpMitVerarbeitung)('Tomaten: TK, getrocknet', 'getrocknet')->id, 1600, $g, 'Tomaten', 1);
    ($this->zutat)($suppe, $this->makeGp($this->rootTeam, 'Sahne')->id, 200, $g, 'Sahne', 2);

    config()->set('foodalchemist.conformance.konzentrat_anteil_max', 0.0);

    expect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $suppe->id))->toBe([]);
});

it('D2: die Kandidatenliste an das Modell traegt zustand/verarbeitung/form — auch leer als null', function () {
    // GP 13757 heisst „TK" und hat `condition` NULL. Genau diese Blindheit hat den Fehler
    // ausgeloest, darum wird das leere Feld ausdruecklich als `null` MITGESCHICKT (statt
    // weggelassen): „Zustand ungeprueft" ist eine Information.
    $ohneFeld = ($this->gpMitVerarbeitung)('Tomaten: TK, getrocknet', 'getrocknet');
    $mitFeld = $this->makeGp($this->rootTeam, 'Tomaten / Pelati: konserviert, ganz');
    DB::table('foodalchemist_gps')->where('id', $mitFeld->id)->update(['condition' => 'konserviert']);

    $kontext = app(\Platform\FoodAlchemist\Services\GenerationContextService::class)
        ->forGeneration($this->rootTeam, 'Cremesuppe mit Tomaten und Sahne');

    $treffer = collect($kontext['gp_kandidaten']['treffer'] ?? [])->keyBy('id');

    // STRUKTURELL pruefen, nicht am Wortlaut: der Prompt-Text ist keine Schnittstelle, ein
    // Fake-Matcher darauf bricht still (feedback_prompt_wortlaut_ist_keine_schnittstelle).
    expect($kontext['gp_kandidaten'])->toHaveKey('lesehilfe')
        ->and(trim((string) $kontext['gp_kandidaten']['lesehilfe']))->not->toBe('')
        ->and($treffer->has($ohneFeld->id) || $treffer->has($mitFeld->id))->toBeTrue();

    if ($treffer->has($mitFeld->id)) {
        expect($treffer[$mitFeld->id])->toHaveKey('zustand')
            ->and($treffer[$mitFeld->id]['zustand'])->toBe('konserviert');
    }
    if ($treffer->has($ohneFeld->id)) {
        expect($treffer[$ohneFeld->id])->toHaveKey('zustand')
            ->and($treffer[$ohneFeld->id]['zustand'])->toBeNull()
            ->and($treffer[$ohneFeld->id]['verarbeitung'])->toBe('getrocknet');
    }
});

it('D1: der condition-Backfill traegt eindeutige Faelle nach und laesst mehrdeutige NULL', function () {
    // Eindeutig: genau EIN §9-Token im Namen.
    $tk = $this->makeGp($this->rootTeam, 'Tomaten: TK, getrocknet');
    // Mehrdeutig: zwei Zustaende im Namen — raten waere schlimmer als Luecke lassen
    // (dieselbe Vorsicht wie Vault-Skript 208).
    $doppel = $this->makeGp($this->rootTeam, 'Suesskartoffeln: frisch / TK, Wuerfel');
    // Kein §9-Token: „getrocknet" allein ist Verarbeitung, kein Zustand.
    $keiner = $this->makeGp($this->rootTeam, 'Tomaten: getrocknet in Oel');

    $this->artisan('foodalchemist:gp-zustand-backfill', ['--apply' => true])->assertExitCode(0);

    expect(DB::table('foodalchemist_gps')->where('id', $tk->id)->value('condition'))->toBe('TK')
        ->and(DB::table('foodalchemist_gps')->where('id', $doppel->id)->value('condition'))->toBeNull()
        ->and(DB::table('foodalchemist_gps')->where('id', $keiner->id)->value('condition'))->toBeNull();

    // Danach ist kein eindeutiger Fall mehr offen.
    $this->artisan('foodalchemist:gp-zustand-backfill', ['--verify' => true])->assertExitCode(0);
});
