<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * #1002 · Wächter der KI-Pflichtliste (docs/PLANUNG/90_KI_Dualisieren_Pflichtliste.md).
 *
 * Jeder KI-Aufruf im Code braucht einen Eintrag in der Pflichtliste (angeschlossen oder begründet ausgenommen).
 * Rot, wenn
 *
 *   1. eine Methode `->propose(` enthält, die nicht in Tabelle A steht, oder die Anzahl je Methode abweicht,
 *   2. ein Eintrag in Tabelle A keine Entsprechung im Code mehr hat (veraltet),
 *   3. ein Eintrag ohne gültige Einstufung/Phase/Status ist, oder `Ausnahme` ohne Begründung,
 *   4. ein anderer KI-Weg (callWithTools, Bildgenerierung, Embeddings, Websuche, direkter Provider-Chat)
 *      außerhalb der in Tabelle B geführten Stellen auftaucht,
 *   5. ein Prompt-Key aus `config/foodalchemist.php` (`ai.prompts`) weder in A, B noch C steht.
 *
 * Dynamische Keys (`$key`) sieht nur Regel 5. Der Test liest nur Dateien, keine DB.
 */
const SPEC90_DOK = 'docs/PLANUNG/90_KI_Dualisieren_Pflichtliste.md';

/** `GpProposalService::propose` ist ein GP-Vorschlag ohne KI, kein Gateway-Aufruf. */
const SPEC90_KEIN_KI = [
    'src/Tools/GpProposalsPostTool.php::execute',
    'src/Services/HardstopResolveService.php::beschaffungAnstossen',
];

/** Datei (relativ zum Modul) je Zusatzweg, die ihn nutzen darf. Muss in Tabelle B stehen. */
const SPEC90_ZUSATZWEGE = [
    'callWithTools(' => ['src/Services/VoiceCommandService.php', 'src/Services/Ai/AiGatewayService.php'],
    'ImageGenerationService' => ['src/Services/RecipeImageService.php'],
    '->embed(' => ['src/Services/Ai/SemanticRetrievalService.php', 'src/Services/Ai/PoolEmbeddingService.php', 'src/Services/Ai/KnowledgeEmbeddingService.php', 'src/Services/Ai/FakeAiProvider.php'],
    'include_web_search' => ['src/Services/Ai/AiGatewayService.php', 'src/Services/Websuche/CoreWebSucheTransport.php', 'src/Services/Ai/KiAuftragService.php', 'src/Tools/KiAuftraegeGetTool.php'],
    '->chat(' => ['src/Services/Ai/AiGatewayService.php', 'src/Services/Ai/FakeAiProvider.php'],
];

function spec90_modul(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<int, string> Zeilen ohne reine Kommentarzeilen, mit Originalzeilennummer (1-basiert) */
function spec90_codezeilen(string $pfad): array
{
    $out = [];
    foreach (explode("\n", (string) file_get_contents($pfad)) as $i => $zeile) {
        $t = ltrim($zeile);
        if ($t === '' || str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '/*') || str_starts_with($t, '#')) {
            continue;
        }
        $out[$i + 1] = $zeile;
    }

    return $out;
}

/** @return array<string, int> `src/..php::methode` → Anzahl `->propose(` */
function spec90_propose_ist(): array
{
    $modul = spec90_modul();
    $ist = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modul . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $datei) {
        if (! $datei->isFile() || $datei->getExtension() !== 'php') {
            continue;
        }
        $roh = explode("\n", (string) file_get_contents($datei->getPathname()));
        foreach (spec90_codezeilen($datei->getPathname()) as $nr => $zeile) {
            if (! str_contains($zeile, '->propose(')) {
                continue;
            }
            $methode = '?';
            for ($j = $nr - 1; $j >= 0; $j--) {
                if (preg_match('/function\s+(\w+)\s*\(/', $roh[$j], $m)) {
                    $methode = $m[1];
                    break;
                }
            }
            $rel = substr($datei->getPathname(), strlen($modul) + 1);
            $schluessel = $rel . '::' . $methode;
            $ist[$schluessel] = ($ist[$schluessel] ?? 0) + substr_count($zeile, '->propose(');
        }
    }
    foreach (SPEC90_KEIN_KI as $k) {
        unset($ist[$k]);
    }
    ksort($ist);

    return $ist;
}

/** @return array{a: array<string, array<string, string>>, b: string, doc: string} */
function spec90_liste(): array
{
    $doc = (string) file_get_contents(spec90_modul() . '/' . SPEC90_DOK);
    $a = [];
    foreach (explode("\n", $doc) as $zeile) {
        // | Nr | `src/..php::methode` | Keys | Anz | Einst | Phase | MCP | Status | Hinweis |
        if (! preg_match('/^\|\s*(\d+)\s*\|\s*`(src\/[^`]+::\w+)`\s*\|(.*)\|\s*$/', $zeile, $m)) {
            continue;
        }
        $spalten = array_map('trim', explode('|', $m[3]));
        if (count($spalten) < 7) {
            continue;
        }
        $a[$m[2]] = ['keys' => $spalten[0], 'anz' => $spalten[1], 'einst' => $spalten[2], 'phase' => $spalten[3],
            'mcp' => $spalten[4], 'status' => $spalten[5], 'hinweis' => $spalten[6], 'nr' => $m[1]];
    }
    $b = '';
    if (preg_match('/## B ·.*?(?=\n## )/s', $doc, $mb)) {
        $b = $mb[0];
    }

    return ['a' => $a, 'b' => $b, 'doc' => $doc];
}

/** @return list<string> */
function spec90_config_keys(): array
{
    $zeilen = explode("\n", (string) file_get_contents(spec90_modul() . '/config/foodalchemist.php'));
    $start = null;
    foreach ($zeilen as $i => $z) {
        if (str_contains($z, "'prompts' => [")) {
            $start = $i;
            break;
        }
    }
    if ($start === null) {
        return [];
    }
    $einzug = strlen($zeilen[$start]) - strlen(ltrim($zeilen[$start]));
    $keys = [];
    for ($i = $start + 1; $i < count($zeilen); $i++) {
        $z = $zeilen[$i];
        $t = ltrim($z);
        $e = strlen($z) - strlen($t);
        if ($t !== '' && $e <= $einzug && ! str_starts_with($t, '//') && ! str_starts_with($t, '*') && ! str_starts_with($t, '/*')) {
            break;
        }
        if ($e === $einzug + 4 && preg_match("/^'([\w.]+)'\s*=>/", $t, $m)) {
            $keys[] = $m[1];
        }
    }

    return $keys;
}

it('#1002 Wächter · die Liste ist lesbar und nicht leer', function () {
    $l = spec90_liste();
    expect(count($l['a']))->toBeGreaterThan(70, 'Tabelle A nicht gefunden — Spaltenformat der Pflichtliste geändert?')
        ->and($l['b'])->not->toBe('', 'Tabelle B fehlt')
        ->and(count(spec90_config_keys()))->toBeGreaterThan(60, 'Prompt-Keys nicht aus der Config lesbar');
});

it('#1002 Wächter · jede Methode mit propose() steht in der Pflichtliste, Anzahl stimmt', function () {
    $ist = spec90_propose_ist();
    $liste = spec90_liste()['a'];
    $fehlt = array_values(array_diff(array_keys($ist), array_keys($liste)));
    $veraltet = array_values(array_diff(array_keys($liste), array_keys($ist)));
    $abweichend = [];
    foreach ($ist as $k => $n) {
        if (isset($liste[$k]) && (int) $liste[$k]['anz'] !== $n) {
            $abweichend[] = "{$k}: Code {$n}, Liste {$liste[$k]['anz']}";
        }
    }

    expect($fehlt)->toBe([], 'KI-Aufruf ohne Eintrag in ' . SPEC90_DOK . ' (Datei::Methode eintragen: Einstufung, extern angeschlossen oder begründet ausgenommen)')
        ->and($veraltet)->toBe([], 'Eintrag in ' . SPEC90_DOK . ' ohne propose() im Code — Zeile entfernen')
        ->and($abweichend)->toBe([], 'Anzahl der propose() je Methode weicht von der Liste ab');
});

it('#1002 Wächter · jeder Eintrag hat Einstufung, Phase, Status und bei Ausnahme eine Begründung', function () {
    $schlecht = [];
    foreach (spec90_liste()['a'] as $k => $z) {
        if (! in_array($z['einst'], ['a', 'b', 'c'], true)) {
            $schlecht[] = "{$k}: Einstufung [{$z['einst']}]";
        }
        if (! in_array($z['phase'], ['P1', 'P2'], true)) {
            $schlecht[] = "{$k}: Phase [{$z['phase']}]";
        }
        if (! in_array($z['status'], ['angeschlossen', 'offen', 'Ausnahme (Vorschlag)', 'Ausnahme'], true)) {
            $schlecht[] = "{$k}: Status [{$z['status']}]";
        }
        if (str_starts_with($z['status'], 'Ausnahme') && mb_strlen($z['hinweis']) < 10) {
            $schlecht[] = "{$k}: Ausnahme ohne Begründung";
        }
        if (! ctype_digit($z['anz']) || (int) $z['anz'] < 1) {
            $schlecht[] = "{$k}: Anzahl [{$z['anz']}]";
        }
    }

    expect($schlecht)->toBe([]);
});

it('#1002 Wächter · andere KI-Wege (Tool-Loop, Bild, Embedding, Websuche, direkter Chat) nur an den geführten Stellen', function () {
    $modul = spec90_modul();
    $b = spec90_liste()['b'];
    $unerlaubt = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modul . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $datei) {
        if (! $datei->isFile() || $datei->getExtension() !== 'php') {
            continue;
        }
        $rel = substr($datei->getPathname(), strlen($modul) + 1);
        foreach (spec90_codezeilen($datei->getPathname()) as $zeile) {
            foreach (SPEC90_ZUSATZWEGE as $muster => $erlaubt) {
                if (str_contains($zeile, $muster) && ! in_array($rel, $erlaubt, true)) {
                    $unerlaubt[$rel . ' → ' . $muster] = true;
                }
            }
        }
    }
    // Jede erlaubte Datei muss in Tabelle B stehen (außer Gateway/Fake: das sind die Wege selbst).
    $nichtInB = [];
    foreach (SPEC90_ZUSATZWEGE as $erlaubt) {
        foreach ($erlaubt as $f) {
            if (! str_contains($f, 'Ai/AiGatewayService') && ! str_contains($f, 'Ai/FakeAiProvider')
                && ! str_contains($b, basename($f, '.php'))) {
                $nichtInB[$f] = true;
            }
        }
    }

    expect(array_keys($unerlaubt))->toBe([], 'Neuer KI-Weg: in ' . SPEC90_DOK . ' Tabelle B eintragen und SPEC90_ZUSATZWEGE ergänzen')
        ->and(array_keys($nichtInB))->toBe([], 'Erlaubte Stelle fehlt in Tabelle B der Pflichtliste');
});

it('#1002 Wächter · jeder Prompt-Key aus der Config steht in der Pflichtliste', function () {
    $doc = spec90_liste()['doc'];
    $fehlt = array_values(array_filter(spec90_config_keys(), fn (string $k) => ! str_contains($doc, $k)));

    expect($fehlt)->toBe([], 'Prompt-Key nicht in ' . SPEC90_DOK . ' (Tabelle A Spalte „Prompt-Key(s)“ oder Tabelle C)');
});
