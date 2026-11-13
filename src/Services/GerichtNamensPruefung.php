<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;

/**
 * Agentenpfad (Dominique 13.11.2026): Gerichtsname beim Anlegen/Umbenennen per MCP gegen VK §1 prüfen — WARNEN, nicht blockieren.
 *
 * Anlass: Ein Agent legte für Broich.Catering 9 Gerichte an (#125–133) mit Gefäß, Deko, Herkunft und Diät-Hinweisen im Namen.
 * Die Regel gab es, aber POST und PUT nahmen jeden Namen ohne Wort an.
 *
 * Keine eigene Prüflogik: die Fälle kommen aus den beiden bestehenden Prüfungen —
 *  · {@see RecipeConformanceAdapter::vkNamensBefunde()} (Regeln als Daten: vk.1.1.hg, vk.1.1.bausteine, alle Regeln mit Ziel `vk.name`,
 *    also vk.1.2.marker / vk.1.2.diaet / vk.1.2.fuellwoerter — jeweils nur, wenn die Regel aktiv ist)
 *  · {@see DataQualityService::namingBefundeFuer()} (Datenqualität: [HG]-Präfix, Marker, Grammatur mit §1.2a-Zwillings-Ausnahme,
 *    Leerraum, Trenner am Rand — dieselbe Prüfung, die das Signal „Naming" zählt)
 * Doppelte Fälle (Marker, Präfix) werden über die Regel-ID zusammengelegt; die Regel-Fassung gewinnt.
 */
class GerichtNamensPruefung
{
    /** Datenqualitäts-Fall → Regel-ID. Leerraum/Trenner haben keine VK-Regel-Zeile, ihr Schlüssel nennt die Herkunft. */
    private const FALL_REGEL = [
        'vk_praefix' => 'vk.1.1.hg', 'vk_marker' => 'vk.1.2.marker', 'grammatur' => 'vk.1.2.grammatur',
        'leerraum' => 'datenqualitaet.leerraum', 'trenner_rand' => 'datenqualitaet.trenner_rand',
    ];

    public const BEISPIEL = '[HG] Rinderfilet | Portwein-Jus | Selleriepüree';

    public function __construct(private DataQualityService $dq) {}

    /**
     * @return list<array{regel: string, text: string, vorschlag: string}>
     */
    public function warnungen(Team $team, int $recipeId, string $name): array
    {
        $aus = [];
        foreach (RecipeConformanceAdapter::vkNamensBefunde($name) as $b) {
            $aus[$b['schluessel']] ??= ['regel' => $b['schluessel'], 'text' => $b['begruendung'],
                'vorschlag' => $this->vorschlag($b['schluessel'], $name, $b['treffer'] ?? null, $b['vorschlag'] ?? null)];
        }
        foreach ($this->dq->namingBefundeFuer($team, $recipeId) as $fall) {
            $regel = self::FALL_REGEL[$fall] ?? $fall;
            $aus[$regel] ??= ['regel' => $regel, 'text' => (string) (SignalCauseService::NAMING_PARAGRAPHEN[$fall]['regel'] ?? $fall),
                'vorschlag' => $this->vorschlag($regel, $name, null, null)];
        }

        return array_values($aus);
    }

    /** Konkreter Gegenvorschlag, wo er sich aus dem Treffer ableiten lässt; sonst die Anweisung aus §1. */
    private function vorschlag(string $regel, string $name, ?string $treffer, ?string $regelVorschlag): string
    {
        if ($regelVorschlag !== null && $regelVorschlag !== '') {
            return $regelVorschlag;
        }
        $ohne = fn (string $teil) => trim((string) preg_replace('/\s{2,}/u', ' ', str_ireplace($teil, ' ', $name)), " \t|,;");

        return match (true) {
            $regel === 'vk.1.1.hg' && preg_match('/^\[[^\]]*\]/u', $name) !== 1
                => '[<Kürzel>] ' . trim($name) . ' — Kürzel aus foodalchemist.dish_classes.GET (hauptgruppen[].code), Muster: „' . self::BEISPIEL . '“.',
            $regel === 'vk.1.1.hg' => 'Ein Kürzel aus foodalchemist.dish_classes.GET (hauptgruppen[].code) verwenden.',
            $regel === 'vk.1.1.bausteine' => 'Leitkomponente und Bausteine mit „ | “ trennen, z. B. „' . self::BEISPIEL . '“.',
            $regel === 'vk.1.2.fuellwoerter' => 'Füllwort' . ($treffer !== null ? ' »' . $treffer . '«' : '') . ' weglassen, Bausteine mit „ | “ trennen, z. B. „' . self::BEISPIEL . '“.',
            $regel === 'vk.1.2.grammatur' && ($m = RegelBuch::per('vk.1.2.grammatur')?->params['muster'][0] ?? null) !== null
                => trim((string) preg_replace('/\s{2,}/u', ' ', (string) @preg_replace((string) $m, ' ', $name))) . ' — Grammatur ins Datenfeld (Darreichung).',
            $treffer !== null && $treffer !== '' => $ohne($treffer),
            $regel === 'datenqualitaet.leerraum' || $regel === 'datenqualitaet.trenner_rand'
                => trim((string) preg_replace('/\s{2,}/u', ' ', $name), " \t|,;:-–"),
            default => 'Name nach VK §1 bilden: „' . self::BEISPIEL . '“.',
        };
    }
}
