<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Pairing\AnkerWissenAuslese;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P4: flächige Dossier-Auslese. Zu beweisen ist nicht das Modell, sondern die Absicherung im Code:
 * erfundene Belege fliegen raus, `muss` braucht ein ausdrückliches Wort, Cave/Verwertung werden nicht gelesen,
 * und ein gelesener Anker fällt beim nächsten Lauf aus dem Scope (resumefähig).
 */
const KUERBIS_DOSSIER = "---\nanker_id: 1\n---\n# Kürbis\n"
    ."| Komponente | braucht daneben |\n|---|---|\n| Püree | Säure muss von außen kommen, sonst süß und flach |\n"
    .'Fett ist Pflicht, nicht Kür. Röstung bringt Tiefe.';

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    $this->anker = function (string $slug, string $name): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->doc = function (int $ankerId, string $slug, string $inhalt, bool $aktiv = true): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug, 'category' => 'zutat',
            'anchor_id' => $ankerId, 'content_md' => $inhalt, 'version' => 1, 'content_hash' => hash('sha256', $inhalt),
            'char_count' => strlen($inhalt), 'active' => $aktiv ? 1 : 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('behält nur Bedarfe, deren Beleg im Dossier steht, und streicht erfundene', function () {
    [$profil, $verworfen] = AnkerWissenAuslese::bereinige([
        'braucht' => [
            ['achse' => 'saeure', 'staerke' => 'soll', 'beleg' => 'Säure muss von außen kommen'],
            ['achse' => 'schaerfe', 'staerke' => 'soll', 'beleg' => 'braucht unbedingt Chili und Ingwer'],   // nicht im Text
            ['achse' => 'erfunden', 'staerke' => 'soll', 'beleg' => 'Säure muss von außen kommen'],            // keine Achse
            ['achse' => 'fett', 'staerke' => 'soll', 'beleg' => ''],                                           // leerer Beleg
        ],
    ], KUERBIS_DOSSIER);

    expect(array_column($profil['braucht'], 'achse'))->toBe(['saeure'])
        ->and($verworfen)->toBe(3);
});

it('vergibt muss nur bei ausdrücklicher Formulierung im Beleg', function () {
    [$profil] = AnkerWissenAuslese::bereinige([
        'braucht' => [
            ['achse' => 'saeure', 'staerke' => 'muss', 'beleg' => 'Säure muss von außen kommen'],
            ['achse' => 'roestaroma', 'staerke' => 'muss', 'beleg' => 'Röstung bringt Tiefe'],   // Ermessen des Modells → soll
            ['achse' => 'fett', 'staerke' => 'muss', 'beleg' => 'Fett ist Pflicht'],
        ],
    ], KUERBIS_DOSSIER);

    expect(collect($profil['braucht'])->pluck('staerke', 'achse')->all())
        ->toBe(['saeure' => 'muss', 'roestaroma' => 'soll', 'fett' => 'muss']);
});

it('nimmt je Achse nur einen Bedarf (Alternativen des Modells werden nicht verdoppelt)', function () {
    [$profil] = AnkerWissenAuslese::bereinige([
        'braucht' => [
            ['achse' => 'saeure', 'staerke' => 'soll', 'beleg' => 'Säure muss von außen kommen'],
            ['achse' => 'saeure', 'staerke' => 'soll', 'beleg' => 'sonst süß und flach'],
        ],
    ], KUERBIS_DOSSIER);

    expect($profil['braucht'])->toHaveCount(1);
});

it('liest nur Aspekte mit Kombinationslogik (kein Cave, keine Verwertung) und nur aktive Dossiers', function () {
    $id = ($this->anker)('pumpkin', 'Kürbis');
    ($this->doc)($id, 'zutat.pumpkin--verhalten', KUERBIS_DOSSIER);
    ($this->doc)($id, 'zutat.pumpkin--cave', 'Cave-Text mit braucht Warnung');
    ($this->doc)($id, 'zutat.pumpkin--verwertung', 'Verwertung braucht Reste');
    ($this->doc)($id, 'zutat.pumpkin--verwendung-kombination', 'Inaktiver braucht Text', false);

    $q = app(AnkerWissenAuslese::class)->quellen($id);

    expect($q['slugs'])->toBe(['zutat.pumpkin--verhalten'])
        ->and($q['text'])->toContain('Säure muss von außen')->not->toContain('Cave-Text');
});

it('nimmt nur Anker in den Scope, die noch nichts aus dem Dossier haben und im Dossier „braucht" sagen', function () {
    $mit = ($this->anker)('pumpkin', 'Kürbis');
    $ohneText = ($this->anker)('salt', 'Salz');
    $schonGelesen = ($this->anker)('leek', 'Lauch');
    ($this->doc)($mit, 'zutat.pumpkin--verhalten', KUERBIS_DOSSIER);
    ($this->doc)($ohneText, 'zutat.salt--steckbrief', 'Nur Handelsformen, keine Kombinationslogik.');
    ($this->doc)($schonGelesen, 'zutat.leek--verhalten', KUERBIS_DOSSIER);
    DB::table('foodalchemist_anchor_bedarfe')->insert([
        'anchor_id' => $schonGelesen, 'achse' => 'fett', 'staerke' => 'soll', 'status' => 'entwurf',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ids = app(AnkerWissenAuslese::class)->kandidaten()->pluck('id')->all();

    expect($ids)->toBe([$mit]);
});

it('liest einen Anker durch den KI-Gateway, sichert ab und übernimmt als Entwurf — danach ist er nicht mehr im Scope', function () {
    $id = ($this->anker)('pumpkin', 'Kürbis');
    ($this->doc)($id, 'zutat.pumpkin--verhalten', KUERBIS_DOSSIER);

    app()->singleton(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => [
                'braucht' => [
                    ['achse' => 'saeure', 'staerke' => 'muss', 'beleg' => 'Säure muss von außen kommen'],
                    ['achse' => 'schaerfe', 'staerke' => 'soll', 'beleg' => 'braucht Chili aus der Karibik'],   // erfunden
                ],
                'liefert' => ['geschmack' => ['suesse' => 2], 'textur' => [], 'beleg' => 'süß'],
                'vertraegt' => [], 'zerstoert' => [], 'komponenten' => [],
            ], 'confidence' => 0.8]), 'model' => 'fake-auslese', 'usage' => []];
        }
    });

    $r = app(AnkerWissenAuslese::class)->lese($id, true);

    expect($r['importiert']['status'])->toBe('ok')
        ->and($r['importiert']['bedarfe'])->toBe(1)
        ->and($r['verworfen'])->toBe(1)
        ->and(DB::table('foodalchemist_anchor_bedarfe')->where('anchor_id', $id)->pluck('status', 'achse')->all())
        ->toBe(['saeure' => 'entwurf'])
        ->and(app(AnkerWissenAuslese::class)->kandidaten()->pluck('id')->all())->toBe([]);
});
