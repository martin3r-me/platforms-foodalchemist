<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Reife\RecipeReifeAdapter;
use Platform\FoodAlchemist\Services\Reife\ReifeAdapter;

/**
 * Spec 50 · Schicht 4 — VOLLSTÄNDIGKEIT. **Read-only, kein Provider-Call.**
 *
 * Die vierte Qualitätsschicht des Moduls:
 *  1 Grounding (Spec 41) — welches Wissen liest die KI beim Schreiben?
 *  2 Matching (Spec 36) — welche GP/LA werden verdrahtet?
 *  3 Konformität (Spec 43) — hält das Erzeugte die Regelwerke ein?
 *  4 **Vollständigkeit (hier)** — ist überhaupt alles da, und WEISS der Erzeuger das?
 *
 * Der Unterschied zu Schicht 3 ist der Kern: Konformität prüft, ob das Vorhandene richtig
 * ist; Vollständigkeit prüft, ob das Fehlende fehlt. Beides zusammen ergibt erst eine
 * Aussage über ein Artefakt.
 *
 * Anlass (MCP-Session 2026-09-03): ein Agent baute per MCP ein Concept mit 6 Gerichten und
 * 26 Basisrezepten — fachlich brauchbar, aber er reichte die Befunde am Ende SELBST nach
 * (VK 15,40 € gegen Zielpreis 48 €, Lohn 0 €, keine Aufschlagsklasse, keine Darreichung).
 * Der Server wusste das alles bereits; er sagte es nur nie. Diese Klasse sagt es.
 *
 * KOMPONIERT, rechnet nicht neu — jede Zahl kommt aus dem Dienst, der sie besitzt
 * (`BulkEnrichService`, `RecipeOneShotService::vkVorbedingungen`, `DataQualityService`).
 * Eine zweite Formel wäre eine zweite Wahrheit (ARCHITEKTUR.md §5).
 */
class ReifeService
{
    /** Schwere → Ampel-Rang. Eine blockierende Lücke macht rot, egal wie viel sonst steht. */
    private const RANG = ['hinweis' => 1, 'wichtig' => 2, 'blockiert' => 3];

    /**
     * @return array{kind: string, id: int, name: string, status: ?string, ampel: string,
     *               vorlaeufig: bool, luecken: list<array>, erfuellt: list<string>,
     *               nicht_messbar: list<array>, kennzahlen: array, naechste_schritte: list<array>}|null
     */
    public function reife(Team $team, string $kind, int $id): ?array
    {
        $adapter = $this->adapter($kind);
        $mess = $adapter->messe($team, $id);
        if ($mess === null) {
            return null;
        }

        return [
            'kind' => $kind,
            'id' => $id,
            'name' => $mess['name'],
            'status' => $mess['status'],
            'ampel' => $this->ampel($mess['luecken']),
            'vorlaeufig' => $mess['vorlaeufig'],
            'luecken' => $mess['luecken'],
            'erfuellt' => $mess['erfuellt'],
            'nicht_messbar' => $mess['nicht_messbar'],
            'kennzahlen' => $mess['kennzahlen'],
            'naechste_schritte' => $this->naechsteSchritte($mess['luecken']),
        ];
    }

    /**
     * Die Kurzform für Write-Antworten: dieselbe Messung, aber nur das, was der Aufrufer
     * unmittelbar braucht. Vollständig lesen kann er danach über das Reife-Tool.
     *
     * Gibt `null` zurück, wenn nichts zu melden ist — dann bleibt die Write-Antwort so
     * schlank wie bisher.
     */
    public function kurz(Team $team, string $kind, int $id): ?array
    {
        $r = $this->reife($team, $kind, $id);
        if ($r === null || ($r['luecken'] === [] && $r['nicht_messbar'] === [])) {
            return null;
        }

        return [
            'ampel' => $r['ampel'],
            'vorlaeufig' => $r['vorlaeufig'],
            'offen' => count($r['luecken']),
            'luecken' => array_map(fn (array $l) => $l['code'], $r['luecken']),
            'naechste_schritte' => $r['naechste_schritte'],
        ];
    }

    /**
     * Rot, sobald etwas blockiert; gelb bei Wichtigem; sonst grün.
     * Bewusst NICHT gemittelt: eine fehlende Darreichung wird nicht dadurch harmlos, dass
     * zwanzig andere Felder stehen.
     *
     * @param  list<array>  $luecken
     */
    private function ampel(array $luecken): string
    {
        $max = 0;
        foreach ($luecken as $l) {
            $max = max($max, self::RANG[$l['schwere']] ?? 0);
        }

        return match ($max) {
            3 => 'rot',
            2 => 'gelb',
            default => 'gruen',
        };
    }

    /**
     * Aus den Lücken die Handlungen ableiten — je Tool einmal, schwerste Lücke zuerst.
     * Das ist der Teil, den der Agent gestern selbst zusammenreimen musste.
     *
     * @param  list<array>  $luecken
     * @return list<array{tool: string, warum: string, pflicht: bool}>
     */
    private function naechsteSchritte(array $luecken): array
    {
        $sortiert = $luecken;
        usort($sortiert, fn ($a, $b) => (self::RANG[$b['schwere']] ?? 0) <=> (self::RANG[$a['schwere']] ?? 0));

        $schritte = [];
        foreach ($sortiert as $l) {
            $tool = $l['wie']['tool'] ?? null;
            if ($tool === null || isset($schritte[$tool])) {
                continue;                                          // kein Weg bekannt, oder schon genannt
            }
            $schritte[$tool] = [
                'tool' => $tool,
                'warum' => $l['was'],
                'pflicht' => $l['schwere'] === 'blockiert',
            ];
        }

        return array_values($schritte);
    }

    private function adapter(string $kind): ReifeAdapter
    {
        return match ($kind) {
            'recipe', 'basisrezept', 'sales_recipe', 'vk', 'gericht' => app(RecipeReifeAdapter::class),
            default => throw new \InvalidArgumentException("Kein Reife-Adapter für Artefakt-Typ «{$kind}»."),
        };
    }
}
