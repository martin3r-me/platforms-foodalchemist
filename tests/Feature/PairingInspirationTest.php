<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\PairingInspirationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E9.2 — Pairing-Inspiration: abstrakt (voll_kreativ, nur Aroma-Nachbarn) vs.
 * geerdet (hybrid/datenbank, Nachbarn + tragende GPs). Rein lesend.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PairingInspirationService::class);

    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst(str_replace('_', ' ', $slug)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->zander = $mkAnker('zander');
    $this->roteBete = $mkAnker('rote_bete');
    $this->meerrettich = $mkAnker('meerrettich');
    $this->dill = $mkAnker('dill');

    $mkKante = function (int $a, int $b, string $typ) {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => $typ, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };
    $mkKante($this->zander, $this->roteBete, 'klassisch');
    $mkKante($this->zander, $this->meerrettich, 'aroma');
    $mkKante($this->zander, $this->dill, 'klassisch');

    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->mkGpMitAnker = function (string $name, int $ankerId, array $overrides = [], bool $mitLeadLa = false) use ($supplier) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(array_merge(['is_derivat' => false, 'is_platzhalter' => false], $overrides));
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gp->id, 'anchor_id' => $ankerId, 'role' => 'kern',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($mitLeadLa) {
            $la = FoodAlchemistSupplierItem::create([
                'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
                'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg',
            ]);
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
            FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 3.20, 'status' => '0']);
            $gp->update(['lead_la_supplier_item_id' => $la->id]);
        }

        return $gp->refresh();
    };
    // „Rote Bete"-GP = Favorit (bucket 'fuehren'); „Dill"-GP = Lead-LA + Preis, kein Favorit (bucket 'leicht');
    // meerrettich hat keinen tragenden GP → Nachbar-Lücke.
    $this->gpRoteBete = ($this->mkGpMitAnker)('Rote Bete', $this->roteBete, ['is_favorite' => true], true);
    $this->gpDill = ($this->mkGpMitAnker)('Dill', $this->dill, [], true);
});

it('E9.2: voll_kreativ = abstrakt — Aroma-Nachbarn ohne GP', function () {
    $out = $this->svc->inspiration($this->rootTeam, ['zander'], 'voll_kreativ');

    expect($out['modus'])->toBe('voll_kreativ')
        ->and($out['geerdet'])->toBeFalse()
        ->and($out['inspiration'])->toHaveCount(1);

    $nachbarn = collect($out['inspiration'][0]['nachbarn']);
    expect($nachbarn->pluck('slug')->all())->toContain('rote_bete', 'meerrettich')
        ->and($nachbarn->first())->not->toHaveKey('gps');   // abstrakt: KEINE GP-Erdung
});

it('E9.2: hybrid = geerdet — Nachbarn tragen die echten GPs', function () {
    $out = $this->svc->inspiration($this->rootTeam, ['zander'], 'hybrid');

    expect($out['geerdet'])->toBeTrue();
    $rb = collect($out['inspiration'][0]['nachbarn'])->firstWhere('slug', 'rote_bete');
    expect($rb)->toHaveKey('gps');
    $gp = collect($rb['gps'])->firstWhere('name', 'Rote Bete');
    expect($gp)->not->toBeNull()
        ->and($gp['has_lead_la'])->toBeTrue()
        ->and($gp['is_favorite'])->toBeTrue();

    // Nachbar ohne tragenden GP (meerrettich) → leere gps-Liste, nicht fehlend
    $mr = collect($out['inspiration'][0]['nachbarn'])->firstWhere('slug', 'meerrettich');
    expect($mr['gps'])->toBe([]);
});

it('E9.2: ungültiger Modus fällt auf hybrid (geerdet) zurück; sucheAnker findet per Begriff', function () {
    $out = $this->svc->inspiration($this->rootTeam, ['zander'], 'quatsch');
    expect($out['modus'])->toBe('hybrid')->and($out['geerdet'])->toBeTrue();

    $treffer = $this->svc->sucheAnker('Rote');
    expect($treffer->pluck('slug')->all())->toContain('rote_bete');
});

it('E9.3: Verfügbarkeits-Buckets führen/leicht/Lücke + Nachbar-Lücke-Flag', function () {
    $out = $this->svc->inspiration($this->rootTeam, ['zander'], 'hybrid');
    $nachbarn = collect($out['inspiration'][0]['nachbarn']);

    // führen = Favorit
    $rb = $nachbarn->firstWhere('slug', 'rote_bete');
    expect(collect($rb['gps'])->firstWhere('name', 'Rote Bete')['bucket'])->toBe('fuehren')
        ->and($rb['luecke'])->toBeFalse();

    // leicht = Lead-LA + Preis, kein Favorit
    $dill = $nachbarn->firstWhere('slug', 'dill');
    expect(collect($dill['gps'])->firstWhere('name', 'Dill')['bucket'])->toBe('leicht')
        ->and($dill['luecke'])->toBeFalse();

    // Lücke = kein tragender GP → Nachbar-Lücke-Flag true
    $mr = $nachbarn->firstWhere('slug', 'meerrettich');
    expect($mr['gps'])->toBe([])->and($mr['luecke'])->toBeTrue();
});

it('E9.3: GP mit Anker aber ohne beschaffbaren LA = Lücke-Bucket', function () {
    // „Wilder Meerrettich" trägt meerrettich, aber KEIN Lead-LA → bucket luecke, Nachbar bleibt Lücke.
    ($this->mkGpMitAnker)('Wilder Meerrettich', $this->meerrettich, [], false);

    $out = $this->svc->inspiration($this->rootTeam, ['zander'], 'hybrid');
    $mr = collect($out['inspiration'][0]['nachbarn'])->firstWhere('slug', 'meerrettich');
    expect(collect($mr['gps'])->firstWhere('name', 'Wilder Meerrettich')['bucket'])->toBe('luecke')
        ->and($mr['luecke'])->toBeTrue();   // einziger Carrier ist Lücke → Nachbar Lücke
});

it('E9.3: meldeLuecke legt idempotent EIN Sortiments-Lücke-Signal an', function () {
    $s1 = $this->svc->meldeLuecke($this->rootTeam, 'meerrettich', ['kapitel_id' => 7]);
    $s2 = $this->svc->meldeLuecke($this->rootTeam, 'meerrettich');

    expect($s1->type)->toBe(\Platform\FoodAlchemist\Enums\SignalTyp::SortimentsLuecke)
        ->and($s2->id)->toBe($s1->id)   // dedup: dasselbe offene Signal aktualisiert
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
            ->where('type', 'sortiments_luecke')->count())->toBe(1)
        ->and($s1->title)->toContain('Meerrettich');
});
