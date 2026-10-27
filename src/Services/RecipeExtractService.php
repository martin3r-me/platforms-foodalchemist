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
 * Foto und Scan (2026-10-12, Dominique): {@see ausBild} schickt ein Foto (Rezeptkarte, Kochbuchseite,
 * Step-by-Step-Seite) als Bild-Teil derselben KI-Nachricht, {@see ausPdf} versucht zuerst den Text
 * (günstig) und gibt bei einem Scan das PDF selbst mit. Beides läuft über den OpenAI-Weg der
 * Plattform (AiGatewayService::bildTeile); vorher brach der Core bei Bild-Nachrichten ab (Fix #925).
 * Der Extraktions-Auftrag bleibt derselbe: wörtlich übernehmen, nichts erfinden — die Prüf-Vorschau
 * bleibt Pflicht, aus Bildern gelesene Mengen sind zu prüfen.
 */
class RecipeExtractService
{
    /** §4: Komponenten-Ebenen unter dem Rezept (K1 → K1.2 → K1.2.1); darunter nur noch Stub-Zeilen. */
    public const MAX_TIEFE = 3;

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
    public function extrahiere(Team $team, string $rohText, array $bilder = []): array
    {
        $rohText = trim($rohText);
        if ($rohText === '' && $bilder === []) {
            throw new \RuntimeException('Kein Text zum Extrahieren übergeben.');
        }
        $kontext = ['roh_text' => $rohText !== '' ? $rohText : '(siehe Anhang)'];
        if ($bilder !== []) {
            $kontext['quelle'] = 'bild';
            $kontext['hinweis'] = 'Der Rezepttext steht im angehängten Foto/Scan. Lies ihn wörtlich ab (auch Step-by-Step-Schritte '
                .'und Bildunterschriften). Schlecht lesbare Mengen oder Wörter weglassen, nie raten.';
        }
        $vorschlag = $this->ai->propose('recipe.extract', $kontext, $bilder !== [] ? ['bilder' => $bilder] : []);
        $werte = is_array($vorschlag->werte ?? null) ? $vorschlag->werte : [];
        if (empty($werte['name']) && empty($werte['zutaten']) && empty($werte['komponenten'])) {
            throw new \RuntimeException($bilder !== []
                ? 'Aus dem Bild ließ sich kein Rezept ablesen (kein Name/keine Zutaten erkannt) — schärfer fotografieren oder Text einfügen.'
                : 'Aus dem Text ließ sich kein Rezept ableiten (kein Name/keine Zutaten erkannt).');
        }

        return $werte;
    }

    /** Erlaubte Foto-Typen für den Import (Handyfoto einer Rezeptkarte / Kochbuchseite). */
    public const BILD_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Foto → Rezept: das Bild geht als data-URL in derselben Nachricht mit. */
    public function ausBild(Team $team, string $pfad, string $mime, ?string $zusatzText = null): array
    {
        if (! in_array($mime, self::BILD_MIMES, true)) {
            throw new \RuntimeException('Nur Fotos als JPG, PNG, WebP oder GIF — erhalten: '.$mime.'.');
        }

        return $this->extrahiere($team, (string) $zusatzText, [[
            'typ' => 'image', 'url' => 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($pfad)), 'detail' => 'high',
        ]]);
    }

    /**
     * PDF → Rezept: Text-PDF günstig über den Parser; liefert der kaum Text (Scan), geht das PDF
     * selbst als Datei-Teil an die KI (Seitenbilder). Gibt den gewählten Weg mit zurück.
     *
     * @return array{extrakt: array, quelle: 'pdf'|'scan'}
     */
    public function ausPdf(Team $team, string $pfad, string $name = 'import.pdf'): array
    {
        $text = '';
        try {
            $text = trim((new \Smalot\PdfParser\Parser())->parseFile($pfad)->getText());
        } catch (\Throwable) {
            $text = '';                                               // kaputter/verschlüsselter Parser-Lauf = wie Scan behandeln
        }
        if (mb_strlen($text) >= 20) {
            return ['extrakt' => $this->extrahiere($team, $text), 'quelle' => 'pdf'];
        }

        return ['extrakt' => $this->extrahiere($team, '', [[
            'typ' => 'pdf', 'name' => $name, 'data' => 'data:application/pdf;base64,'.base64_encode((string) file_get_contents($pfad)),
        ]]), 'quelle' => 'scan'];
    }

    /**
     * Schritt 2 — GEERDETE Anlage (rekursiv). Legt zuerst die Komponenten als Sub-Basisrezepte an
     * (geerdet), verknüpft sie im Parent per sub_rezept_id, und legt dann den Parent geerdet an.
     *
     * @param  array{typ?: string, name?: string, zutaten?: array, preparation?: string, komponenten?: array}  $extrakt
     * @param  array  $parameter  Rezept-Leitplanken (bio_pref, frische, convenience …) wie in der Kaskade — 27.10.: vorher immer leer
     * @return array{recipe: FoodAlchemistRecipe, statistik: array, offene: array, sub_recipes: list<array{id:int,name:string}>}
     */
    public function legeAn(Team $team, array $extrakt, ?bool $vkModusOverride = null, int $tiefe = 0, array $parameter = []): array
    {
        $vkModus = $vkModusOverride ?? (($extrakt['typ'] ?? 'basisrezept') === 'gericht');
        $zutaten = array_map(function (array $z) {
            // 27.10.: deutsche Zahl auch hier (MCP-Weg ohne Vorschau) — der Generator liest sonst „2.000“ per (float) als 2
            if (array_key_exists('quantity', $z) && ($q = \Platform\FoodAlchemist\Support\DeutscheZahl::alsFloat($z['quantity'])) !== null) {
                $z['quantity'] = $q;
            }

            return $z;
        }, array_values(array_filter((array) ($extrakt['zutaten'] ?? []), 'is_array')));
        // Ab der Tiefen-Grenze keine weitere Verschachtelung mehr auflösen (§4): Komponenten auf Ebene 1–3 unter dem Rezept.
        $komponenten = $tiefe >= self::MAX_TIEFE
            ? []
            : array_values(array_filter((array) ($extrakt['komponenten'] ?? []), 'is_array'));

        // 1. Komponenten zuerst: jede als eigenes Basisrezept anlegen + erden.
        $subInfo = [];
        foreach ($komponenten as $k) {
            $kName = trim((string) ($k['name'] ?? ''));
            $kZutaten = array_values(array_filter((array) ($k['zutaten'] ?? []), 'is_array'));
            if ($kName === '' || ($kZutaten === [] && array_filter((array) ($k['komponenten'] ?? []), 'is_array') === [])) {
                continue;   // reine Namensnennung ohne Zutaten und ohne Unter-Komponenten → unten als sub_rezept-Stub-Zeile
            }
            $subRes = $this->legeAn($team, [
                'typ' => 'basisrezept',
                'name' => $kName,
                'zutaten' => $kZutaten,
                'preparation' => (string) ($k['preparation'] ?? ''),
                'komponenten' => (array) ($k['komponenten'] ?? []),   // 27.10.: Unter-Komponenten weiterreichen (vorher [] → nur eine Ebene)
            ], false, $tiefe + 1, $parameter);
            $subId = (int) $subRes['recipe']->id;
            $subInfo[] = ['id' => $subId, 'name' => $kName];

            // 2. Im Parent verknüpfen: bestehende gleichnamige Zeile bekommt sub_rezept_id,
            //    sonst eine neue Komponenten-Zeile anhängen (damit die Verknüpfung sicher entsteht).
            $idx = $this->findeZeileNachName($zutaten, $kName);
            if ($idx !== null) {
                $zutaten[$idx]['sub_rezept_id'] = $subId;
                $zutaten[$idx]['sub_rezept'] = true;
                $zutaten[$idx]['sub_rezept_eigen'] = true;
            } else {
                $zutaten[] = [
                    'text' => $kName, 'sub_rezept_id' => $subId, 'sub_rezept' => true, 'sub_rezept_eigen' => true,
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
            $team, $name, $parameter, kiRezeptOverride: $override, vkModus: $vkModus, createdVia: 'import',
        );
        $result['sub_recipes'] = $subInfo;

        return $result;
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
