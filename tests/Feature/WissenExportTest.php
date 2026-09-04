<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * W2-5: `knowledge-export` ist der fehlende Rückweg. CLAUDE.md erklärt das Modul zur
 * Source of Truth und den Vault zum Spiegel/Backup — es gab aber nur `knowledge-import`.
 * 3,2 Mio Zeichen kuratiertes Wissen ohne Weg hinaus; „Backup" war eine Behauptung.
 */
function expDoc(?int $teamId, string $slug, string $kategorie, string $inhalt, bool $aktiv = true): int
{
    return DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug,
        'title' => 'T ' . $slug, 'category' => $kategorie, 'content_md' => $inhalt,
        'version' => 1, 'content_hash' => hash('sha256', $inhalt), 'char_count' => mb_strlen($inhalt),
        'active' => $aktiv, 'source_path' => null, 'created_via' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->ziel = sys_get_temp_dir() . '/fa-export-' . bin2hex(random_bytes(6));
});

afterEach(function () {
    if (is_dir($this->ziel)) {
        exec('rm -rf ' . escapeshellarg($this->ziel));
    }
});

it('spiegelt die Ordner-/Präfix-Logik des Imports — sonst ist der Rückweg keiner', function () {
    expDoc(null, 'regelwerk.grundprodukte', 'regelwerk', '# GP-Regelwerk');
    expDoc(null, 'pairing.zander', 'pairing', '# Zander');
    expDoc(null, 'substitutionen', 'cross_cutting', '# Substitutionen');

    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel])->assertSuccessful();

    // Das Präfix MUSS aus dem Dateinamen raus: der Import setzt es beim Lesen wieder davor,
    // sonst entsteht beim Rückweg `regelwerk.regelwerk.grundprodukte`.
    expect(file_exists($this->ziel . '/07.01_Lebensmittel_und_Gastronomie/Regelwerke/grundprodukte.md'))->toBeTrue()
        ->and(file_exists($this->ziel . '/07.02_Flavor_Pairing/pairings/zander.md'))->toBeTrue()
        ->and(file_exists($this->ziel . '/07.01_Lebensmittel_und_Gastronomie/Cross_Cutting/substitutionen.md'))->toBeTrue();
});

it('schreibt content_md WORTWÖRTLICH — kein Frontmatter, sonst wächst bei jedem Zyklus ein Kopf', function () {
    $inhalt = "# Dossier\n\nZeile eins.\nZeile zwei.\n";
    expDoc(null, 'substitutionen', 'cross_cutting', $inhalt);

    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel])->assertSuccessful();

    $datei = $this->ziel . '/07.01_Lebensmittel_und_Gastronomie/Cross_Cutting/substitutionen.md';
    // Byte-identisch: der Import liest die Datei KOMPLETT in content_md (dokumentierte
    // Altlast) — mit Frontmatter im Export würde er ihn ins Dokument einbacken.
    expect(file_get_contents($datei))->toBe($inhalt);
});

it('Metadaten liegen im Manifest, nicht im Dokument', function () {
    expDoc($this->childA->id, 'eigen_doc', 'domain', '# Eigen');

    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel])->assertSuccessful();

    $m = json_decode((string) file_get_contents($this->ziel . '/_manifest.json'), true);
    $eintrag = collect($m['docs'])->firstWhere('slug', 'eigen_doc');

    expect($eintrag)->not->toBeNull()
        ->and($eintrag['kategorie'])->toBe('domain')
        ->and($eintrag['team_id'])->toBe($this->childA->id)
        ->and($eintrag['content_hash'])->toBe(hash('sha256', '# Eigen'))
        ->and($m['dokumente'])->toBeGreaterThanOrEqual(1);
});

it('vault-fremde Kategorien landen in _modul/ und verunreinigen den Spiegel nicht', function () {
    expDoc(null, 'event_playbook_gala', 'event_playbook', '# Gala');

    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel])->assertSuccessful();

    expect(file_exists($this->ziel . '/_modul/event_playbook/event_playbook_gala.md'))->toBeTrue();
});

it('idempotent: der zweite Lauf schreibt nichts neu', function () {
    expDoc(null, 'substitutionen', 'cross_cutting', '# S');

    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel])->assertSuccessful();
    $datei = $this->ziel . '/07.01_Lebensmittel_und_Gastronomie/Cross_Cutting/substitutionen.md';
    $vorher = filemtime($datei);

    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel])
        ->expectsOutputToContain('unverändert übersprungen')
        ->assertSuccessful();

    expect(filemtime($datei))->toBe($vorher);
});

it('löscht NIE — eine verwaiste Datei bleibt liegen', function () {
    expDoc(null, 'substitutionen', 'cross_cutting', '# S');
    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel])->assertSuccessful();

    $waise = $this->ziel . '/07.01_Lebensmittel_und_Gastronomie/Cross_Cutting/verwaist.md';
    file_put_contents($waise, '# Nicht mehr im Modul');

    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel])->assertSuccessful();

    // Ein Backup-Werkzeug, das aufräumt, übersetzt einen Modul-Fehler in Datenverlust.
    expect(file_exists($waise))->toBeTrue();
});

it('inaktive Dokumente bleiben draussen, mit --include-inactive kommen sie mit', function () {
    expDoc(null, 'quarantaene', 'domain', '# Q', aktiv: false);

    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel])->assertSuccessful();
    expect(file_exists($this->ziel . '/07.01_Lebensmittel_und_Gastronomie/Domains/quarantaene.md'))->toBeFalse();

    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel, '--include-inactive' => true])->assertSuccessful();
    expect(file_exists($this->ziel . '/07.01_Lebensmittel_und_Gastronomie/Domains/quarantaene.md'))->toBeTrue();
});

it('dry-run schreibt nichts', function () {
    expDoc(null, 'substitutionen', 'cross_cutting', '# S');

    $this->artisan('foodalchemist:knowledge-export', ['--dir' => $this->ziel, '--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();

    expect(is_dir($this->ziel) && file_exists($this->ziel . '/_manifest.json'))->toBeFalse();
});
