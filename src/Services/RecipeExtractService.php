<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * Rezept-IMPORT (2026-08-22): bestehende Rezepturen (eingefügter Text / Web-Copy / Text-PDF)
 * TREU extrahieren und GEERDET als Draft anlegen. Zwei getrennte Schritte:
 *
 *  1. {@see extrahiere}  — recipe.extract (Wissenskontext bewusst leer, GL-13 Inv. 7): der Rohtext
 *     wird 1:1 in eine strukturierte, evtl. VERSCHACHTELTE Rezept-Form gebracht (typ + zutaten +
 *     preparation + komponenten[]). NICHTS anreichern/erfinden — das ist der Unterschied zum
 *     Generator (recipe.generator), der bewusst veredelt.
 *  2. {@see legeAn}     — die EIGENTLICHE Erdung: über den fertigen kiRezeptOverride-Pfad von
 *     {@see RecipeGeneratorService::generiere} wird jede Zutatenzeile am Resolver an echte GPs
 *     (bzw. Sub-Rezepte) gebunden, syncIngredients + Recompute (Yield/Allergene/EK) laufen.
 *     Verschachtelte Komponenten werden REKURSIV zuerst als Sub-Basisrezepte angelegt (geerdet)
 *     und im Parent per sub_rezept_id verknüpft (§4 Sub-Rezept-Hierarchie, max. 3 Ebenen).
 *
 * Bild/Vision (Foto → Rezept) ist auf den Core-Transport angewiesen und hier bewusst NICHT
 * enthalten (Text-only). Der Foto-Weg läuft über den MCP-Assistenten (eigene Vision) →
 * recipes.EXTRACT mit dem abgelesenen Text.
 */
class RecipeExtractService
{
    /** §4: maximale Verschachtelungstiefe (Parent + Komponenten + deren Stubs). */
    private const MAX_TIEFE = 3;

    public function __construct(
        private AiGatewayService $ai,
        private RecipeGeneratorService $generator,
    ) {
    }

    /**
     * Schritt 1 — TREUE Extraktion des Rohtextes in strukturierte (evtl. verschachtelte) Form.
     *
     * @return array{typ?: string, name?: string, zutaten?: array, preparation?: string, komponenten?: array}
     */
    public function extrahiere(Team $team, string $rohText): array
    {
        $rohText = trim($rohText);
        if ($rohText === '') {
            throw new \RuntimeException('Kein Text zum Extrahieren übergeben.');
        }
        $vorschlag = $this->ai->propose('recipe.extract', ['roh_text' => $rohText]);
        $werte = is_array($vorschlag->werte ?? null) ? $vorschlag->werte : [];
        if (empty($werte['name']) && empty($werte['zutaten']) && empty($werte['komponenten'])) {
            throw new \RuntimeException('Aus dem Text ließ sich kein Rezept ableiten (kein Name/keine Zutaten erkannt).');
        }

        return $werte;
    }

    /**
     * Schritt 2 — GEERDETE Anlage (rekursiv). Legt zuerst die Komponenten als Sub-Basisrezepte an
     * (geerdet), verknüpft sie im Parent per sub_rezept_id, und legt dann den Parent geerdet an.
     *
     * @param  array{typ?: string, name?: string, zutaten?: array, preparation?: string, komponenten?: array}  $extrakt
     * @return array{recipe: FoodAlchemistRecipe, statistik: array, offene: array, sub_recipes: list<array{id:int,name:string}>}
     */
    public function legeAn(Team $team, array $extrakt, ?bool $vkModusOverride = null, int $tiefe = 0): array
    {
        $vkModus = $vkModusOverride ?? (($extrakt['typ'] ?? 'basisrezept') === 'gericht');
        $zutaten = array_values(array_filter((array) ($extrakt['zutaten'] ?? []), 'is_array'));
        // Ab der Tiefen-Grenze keine weitere Verschachtelung mehr auflösen (§4).
        $komponenten = $tiefe >= self::MAX_TIEFE - 1
            ? []
            : array_values(array_filter((array) ($extrakt['komponenten'] ?? []), 'is_array'));

        // 1. Komponenten zuerst: jede als eigenes Basisrezept anlegen + erden.
        $subInfo = [];
        foreach ($komponenten as $k) {
            $kName = trim((string) ($k['name'] ?? ''));
            $kZutaten = array_values(array_filter((array) ($k['zutaten'] ?? []), 'is_array'));
            if ($kName === '' || $kZutaten === []) {
                continue;   // reine Namensnennung ohne Zutaten → unten als sub_rezept-Stub-Zeile
            }
            $subRes = $this->legeAn($team, [
                'typ' => 'basisrezept',
                'name' => $kName,
                'zutaten' => $kZutaten,
                'preparation' => (string) ($k['preparation'] ?? ''),
                'komponenten' => [],
            ], false, $tiefe + 1);
            $subId = (int) $subRes['recipe']->id;
            $subInfo[] = ['id' => $subId, 'name' => $kName];

            // 2. Im Parent verknüpfen: bestehende gleichnamige Zeile bekommt sub_rezept_id,
            //    sonst eine neue Komponenten-Zeile anhängen (damit die Verknüpfung sicher entsteht).
            $idx = $this->findeZeileNachName($zutaten, $kName);
            if ($idx !== null) {
                $zutaten[$idx]['sub_rezept_id'] = $subId;
                $zutaten[$idx]['sub_rezept'] = true;
            } else {
                $zutaten[] = [
                    'text' => $kName, 'sub_rezept_id' => $subId, 'sub_rezept' => true,
                    'quantity' => 1, 'unit' => 'stk',
                ];
            }
        }

        // 3. Parent geerdet anlegen (kiRezeptOverride = kein LLM, aber voller Resolver/Recompute).
        $name = trim((string) ($extrakt['name'] ?? '')) ?: 'Importiertes Rezept';
        $override = [
            'name' => $name,
            'zutaten' => $zutaten,
            'preparation' => (string) ($extrakt['preparation'] ?? ''),
        ];
        if ($override['zutaten'] === []) {
            throw new \RuntimeException("Rezept «{$name}» hat keine Zutaten — nichts zu erden.");
        }
        $result = $this->generator->generiere(
            $team, $name, [], kiRezeptOverride: $override, vkModus: $vkModus, createdVia: 'import',
        );
        $result['sub_recipes'] = $subInfo;

        return $result;
    }

    /**
     * Extrahiert reinen Text aus einem (text-basierten) PDF via smalot/pdfparser. Scan-/Bild-PDFs
     * liefern (fast) keinen Text → sauberer Hinweis statt Fehlversuch (Vision ist hier nicht enthalten).
     */
    public function pdfText(string $pfad): string
    {
        $text = trim((new \Smalot\PdfParser\Parser())->parseFile($pfad)->getText());
        if (mb_strlen($text) < 20) {
            throw new \RuntimeException('Aus diesem PDF ließ sich kaum Text lesen — vermutlich ein Scan/Bild-PDF. '
                . 'Bild-PDFs werden aktuell nicht gelesen; kopiere den Text hinein oder gib mir das Bild im Chat.');
        }

        return $text;
    }

    /** Findet in $zutaten die Zeile, deren Text zum Komponenten-Namen passt (normalisiert). */
    private function findeZeileNachName(array $zutaten, string $name): ?int
    {
        $ziel = $this->normalisiere($name);
        if ($ziel === '') {
            return null;
        }
        foreach ($zutaten as $i => $z) {
            $t = $this->normalisiere((string) ($z['text'] ?? $z['name'] ?? ''));
            if ($t === '') {
                continue;
            }
            if ($t === $ziel || (mb_strlen($ziel) >= 4 && (str_contains($t, $ziel) || str_contains($ziel, $t)))) {
                return $i;
            }
        }

        return null;
    }

    private function normalisiere(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // führende "Für die/das/den " + Doppelpunkt-Präfixe (»Sauce:«) glätten
        $s = preg_replace('/^f(ü|u)r (die|das|den|den) /u', '', $s) ?? $s;
        $s = preg_replace('/[:,].*$/u', '', $s) ?? $s;

        return trim($s);
    }
}
