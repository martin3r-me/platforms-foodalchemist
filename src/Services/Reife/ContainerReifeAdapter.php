<?php

namespace Platform\FoodAlchemist\Services\Reife;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\CoverageService;
use Platform\FoodAlchemist\Services\DataQualityService;

/**
 * Spec 50 · Etappe 7 — gemeinsamer Unterbau der Container-Adapter (Konzept, Format,
 * Foodbook, Speisekarte, Speiseplan, Angebot).
 *
 * Ein Container hat drei Reife-Schichten, die alle Adapter gleich behandeln:
 *  · Kopf — die Felder, die ein Kundendokument trägt (Name, Kundenname, Claim, Text)
 *  · Struktur — Positionen, Kapitel, Header: was der Composer „bauen" würde
 *  · Gerüst — die Coverage gegen den Planungs-Rahmen, sofern eines dranhängt
 *
 * Die Adapter messen nur, sie rechnen nichts neu und rufen keinen Provider. Wo ein
 * MCP-Werkzeug den Weg kennt, steht es in `wie`; wo keines existiert (Speiseplan-Kopf),
 * steht `wie: null` — der Agent sieht die Lücke, ohne einen Weg zu erfinden.
 *
 * Coverage-Übersetzung (Etappe-7-Design): `verletzt` → wichtig, `teilerfuellt` → hinweis,
 * `erfuellt`/`info` → erfüllt; kein Gerüst → `nicht_messbar` (das Soll fehlt, nicht das Ist).
 * {@see CoverageService::coverage()} kennt nur concept|foodbook|speisekarte — alle anderen
 * Owner-Typen fallen dort still in den Concept-Zweig, darum rufen nur diese drei Adapter sie.
 */
abstract class ContainerReifeAdapter implements ReifeAdapter
{
    public function __construct(
        protected CoverageService $coverage,
        protected DataQualityService $dq,
    ) {}

    /** @return array{code: string, ebene: string, schwere: string, was: string, wie: ?array} */
    protected function luecke(string $code, string $schwere, string $was, ?string $tool, array $args = []): array
    {
        return [
            'code' => $code, 'ebene' => $this->artifactType(), 'schwere' => $schwere, 'was' => $was,
            'wie' => $tool !== null ? ['tool' => $tool] + ($args !== [] ? ['args' => $args] : []) : null,
        ];
    }

    /** Ein leeres Kopf-Feld als Lücke, ein gefülltes als erfüllt. */
    protected function kopfFeld(array &$luecken, array &$erfuellt, mixed $wert, string $code, string $schwere, string $was, ?string $tool, array $args = []): void
    {
        $leer = $wert === null || (is_string($wert) && trim($wert) === '');
        if ($leer) {
            $luecken[] = $this->luecke($code, $schwere, $was, $tool, $args);
        } else {
            $erfuellt[] = $code;
        }
    }

    /**
     * Coverage gegen das Planungs-Gerüst in Lücken übersetzen.
     *
     * @return array{luecken: list<array>, erfuellt: list<string>, nicht_messbar: list<array>, kennzahlen: array}
     */
    protected function geruest(Team $team, string $ownerType, int $ownerId): array
    {
        $cov = $this->coverage->coverage($team, $ownerType, $ownerId);
        if (! ($cov['hat_geruest'] ?? false)) {
            return [
                'luecken' => [], 'erfuellt' => [], 'kennzahlen' => [],
                'nicht_messbar' => [['code' => 'geruest', 'warum' => 'Kein Planungs-Gerüst — ohne Soll keine Coverage-Aussage.']],
            ];
        }
        $luecken = [];
        $erfuellt = [];
        foreach ($cov['befunde'] ?? [] as $b) {
            $code = 'geruest_' . (string) ($b['dimension'] ?? 'unbekannt');
            $ampel = (string) ($b['ampel'] ?? 'info');
            if ($ampel === 'verletzt' || $ampel === 'teilerfuellt') {
                $was = trim((string) ($b['label'] ?? '') . ': Soll ' . (string) ($b['soll'] ?? '—') . ', Ist ' . (string) ($b['ist'] ?? '—'));
                $l = $this->luecke($code, $ampel === 'verletzt' ? 'wichtig' : 'hinweis', $was, null);
                // Coverage hängt am Slot/Kapitel — der Agent braucht den Ort, um den Befund zu greifen.
                if (($b['slot_id'] ?? null) !== null) {
                    $l['slot_id'] = (int) $b['slot_id'];
                }
                if (($b['chapter_id'] ?? null) !== null) {
                    $l['chapter_id'] = (int) $b['chapter_id'];
                }
                $luecken[] = $l;
            } elseif (! in_array($code, $erfuellt, true)) {
                $erfuellt[] = $code;
            }
        }

        return [
            'luecken' => $luecken, 'erfuellt' => $erfuellt, 'nicht_messbar' => [],
            'kennzahlen' => ['geruest_ampel' => $cov['ampel_gesamt'] ?? null, 'geruest_befunde' => $cov['zusammenfassung'] ?? []],
        ];
    }

    /**
     * Datenqualitäts-Ampel je Objekt. `$dubletten` nennt Codes, die die Struktur-Messung
     * bereits gemeldet hat — dieselbe Aussage unter zwei Namen ist für den Agent zwei Aufgaben.
     *
     * @param  array<string, string>  $metriken  Metrik → Schwere
     * @param  array<string, string>  $dubletten  Metrik → Struktur-Code, der sie überdeckt
     */
    protected function ampel(Team $team, string $kind, int $id, array $metriken, array $luecken, array $dubletten = []): array
    {
        $gemeldet = array_column($luecken, 'code');
        $out = [];
        foreach ($metriken as $metrik => $schwere) {
            $dublette = $dubletten[$metrik] ?? null;
            if ($dublette !== null && in_array($dublette, $gemeldet, true)) {
                continue;
            }
            if ($this->dq->trifftObjekt($team, $metrik, $kind, $id)) {
                $out[] = $this->luecke($metrik, $schwere, 'Datenqualitäts-Ampel meldet diesen Befund.', null);
            }
        }

        return $out;
    }

    /** Status als String — Enums haben ->value, Strings bleiben, NULL bleibt NULL. */
    protected function status(mixed $status): ?string
    {
        if ($status instanceof \BackedEnum) {
            return (string) $status->value;
        }

        return $status !== null ? (string) $status : null;
    }

    protected function ergebnis(string $name, mixed $status, array $luecken, array $erfuellt, array $nichtMessbar, array $kennzahlen, bool $vorlaeufig = false): array
    {
        return [
            'name' => $name,
            'status' => $this->status($status),
            'luecken' => $luecken,
            'erfuellt' => array_values(array_unique($erfuellt)),
            'nicht_messbar' => $nichtMessbar,
            'vorlaeufig' => $vorlaeufig,
            'kennzahlen' => $kennzahlen,
        ];
    }
}
