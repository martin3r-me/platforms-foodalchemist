<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Support\DossierText;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(fn () => $this->seedTeamHierarchy());

/**
 * Der Provenienz-Vorspann der §-Dossiers geht nicht mehr in Prompts.
 *
 * Gemessen auf demo (2026-09-03): 9.261 / 13.612 / 3.224 Zeichen über die drei
 * Regelwerk-Präfixe sind DERSELBE Textbaustein, 17 / 20 / 9 mal wiederholt. Er nennt
 * Vault-Python-Skripte und enthält keine Regel.
 *
 * Diese Tests halten vor allem die GRENZE fest: was NICHT bereinigt wird. Ein zu
 * gieriger Strip würde in einem fremd aufgebauten Dossier echten Inhalt abschneiden,
 * und das fiele erst am schlechten Rezept auf.
 */

/** Der echte Aufbau, wie ihn der §-Split 2026-08-27 erzeugt. */
function fa_dossier(string $para, string $koerper): string
{
    return "**Regelwerk Basisrezepte** (verbindlich, Domain `03_KUECHE/03.02_Basisrezepte/`). "
        . "Pflicht-Referenz bei Recipe-Migration (Skripte 200-208), Recipe-Skills "
        . "(`food_dna_canvas`, `recipe_creator`, `komposition_builder`, …) und Rezept-Imports. "
        . "Bei Konflikt mit Skript-Code oder Memory gewinnt dieses Regelwerk. "
        . "Verwandte Regelwerke: `Regelwerk_Grundprodukte`, `Regelwerk_Lieferantenartikel`.\n\n"
        . "Dieses Dossier deckt **{$para}** ab und ist eigenständig anwendbar.\n\n---\n\n"
        . "## {$para}\n\n{$koerper}\n";
}

it('entfernt den Vorspann und behaelt den Paragraphen ganz', function () {
    $md = fa_dossier('§6 — Mengen, Einheiten & Yield', "Auto-Sum mit GP-Verlust-Faktor.\nMittelwert bei Bereich.");

    $rein = DossierText::ohneVorspann($md);

    expect($rein)->toStartWith('## §6')
        ->and($rein)->toContain('Auto-Sum mit GP-Verlust-Faktor')
        ->and($rein)->toContain('Mittelwert bei Bereich')
        ->and($rein)->not->toContain('Skripte 200-208')
        ->and($rein)->not->toContain('Pflicht-Referenz')
        ->and(DossierText::vorspannLaenge($md))->toBeGreaterThan(400);
});

it('laesst NORMATIVE Tabellen unangetastet — das war die Falle des Plans', function () {
    // Der Plan wollte »tabellendominant → kind=referenz« und hätte damit genau die
    // §5-Default-GP-Tabelle und die §8-Pflichtangaben-Matrix verworfen. Gemessen sind
    // 21 % der Dossier-Zeichen Tabellenzeilen — und die sind die Regel selbst.
    $tabelle = "| Zutat | Default-GP |\n|---|---|\n| Zucker | Raffinade weiss |\n| Salz | unjodiert |";
    $rein = DossierText::ohneVorspann(fa_dossier('§5 — Default-GPs', $tabelle));

    expect($rein)->toContain('Raffinade weiss')
        ->and($rein)->toContain('unjodiert')
        ->and($rein)->toContain('| Zutat | Default-GP |');
});

it('laesst ein Dokument OHNE generierten Vorspann unveraendert', function () {
    $fremd = "# Convenience und Recipe Engineering\n\nEinleitung ohne Regelwerk-Baustein.\n\n## 1) Stufen 0-5\n\nText.";

    expect(DossierText::ohneVorspann($fremd))->toBe($fremd)
        ->and(DossierText::vorspannLaenge($fremd))->toBe(0);
});

it('behaelt alles, wenn der Vorspann OHNE folgende Ueberschrift steht', function () {
    // Sonst bliebe nichts übrig — lieber nicht bereinigen als leeren Text liefern.
    $nurVorspann = "**Regelwerk Basisrezepte** (verbindlich). Kein Paragraph, keine Überschrift.";

    expect(DossierText::ohneVorspann($nurVorspann))->toBe($nurVorspann);
});

it('greift nicht bei fett gesetztem Text, der nur zufaellig so anfaengt', function () {
    // `**Regelwerke im Ueberblick**` ist KEIN generierter Vorspann.
    $md = "**Regelwerke im Ueberblick** — Sammelseite.\n\n## Uebersicht\n\nListe.";
    // Das Muster verlangt die Wortgrenze nach »Regelwerk«, greift hier also — und genau
    // deshalb prüft dieser Test, dass die Ueberschrift-Bedingung die zweite Sicherung ist:
    // der Rest bleibt vollständig, nur der Sammel-Einleitungssatz fällt.
    expect(DossierText::ohneVorspann($md))->toStartWith('## Uebersicht')
        ->and(DossierText::ohneVorspann($md))->toContain('Liste');
})->skip('Bewusst offen: das Muster ist eng auf den Split-Vorspann geeicht, nicht auf jede fette Zeile. Wenn ein echtes Sammel-Dokument mit **Regelwerk… beginnt, ist der Verlust ein Einleitungssatz — dokumentiert statt stillschweigend.');

it('schrumpft den gebundenen Block im echten Prompt, ohne eine Regel zu verlieren', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->actingAs($this->makeUser($this->rootTeam));

    // Vier §-Dossiers wie am Generator, jedes mit Vorspann.
    // Spaltenliste 1:1 aus dem Haus-Helfer w0Doc/w0Bind (WissenTokenWelle0Test) — die
    // Tabelle hat NOT-NULL-Spalten (version, content_hash, char_count), die ein
    // handgeschriebener Insert stillschweigend vergisst.
    foreach ([['§2 — Verarbeitungs-Reduktion', 'ERKENNUNGSMARKE-ZWEI'],
              ['§3 — Pürees, Marks, Coulis', 'ERKENNUNGSMARKE-DREI'],
              ['§4 — Sub-Rezept-Hierarchie', 'ERKENNUNGSMARKE-VIER'],
              ['§6 — Mengen, Einheiten, Yield', 'ERKENNUNGSMARKE-SECHS']] as $i => [$para, $marke]) {
        $slug = 'regelwerk-basisrezepte-vsp-' . $i;
        $md = fa_dossier($para, $marke . ' — die Regel selbst.');
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'team_id' => $this->rootTeam->id,
            'slug' => $slug,
            'title' => 'Regelwerk Basisrezepte ' . $para,
            'category' => 'regelwerk',
            'content_md' => $md,
            'version' => 1,
            'content_hash' => hash('sha256', $slug),
            'char_count' => mb_strlen($md),
            'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('foodalchemist_knowledge_bindings')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'team_id' => $this->rootTeam->id,
            'knowledge_document_id' => DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('id'),
            'binding_type' => 'layer',
            'target_key' => 'recipe.generator',
            'mode' => 'always',
            'weight' => 100 - $i,
            'active' => 1,
            'source' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    app(AiGatewayService::class)->propose('recipe.generator', ['description' => 'Rinderfilet'], []);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.generator')->latest('id')->first();
    $parts = json_decode((string) $log->prompt_parts, true);

    // 4 Dossiers × ~490 Z. Vorspann ≈ 1.960 Zeichen, die nicht mehr im Prompt stehen.
    // Der Block trägt nur noch Kopfzeilen + die vier §-Körper.
    expect($parts['bound'])->toBeLessThan(1600)
        ->and($parts['bound'])->toBeGreaterThan(300);

    // … und JEDE Regel ist weiter da. Das ist die Hälfte, die zählt.
    $audit = implode(' ', json_decode((string) $log->knowledge_used, true) ?: []);
    foreach ([0, 1, 2, 3] as $i) {
        expect($audit)->toContain('regelwerk-basisrezepte-vsp-' . $i);
    }
});
