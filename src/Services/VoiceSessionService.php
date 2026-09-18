<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Spec 53 / Paket F (4): Gesprächsgedächtnis für den Sprachbefehl. Der Sprach-Agent selbst
 * ist zustandslos (jeder `VoiceCommandService::verarbeite()`-Aufruf ein frischer Tool-Loop) —
 * OHNE dieses Gedächtnis vergisst er nach jedem Turn alles. Diese Sitzung liegt darum
 * serverseitig in Cache (TTL {@see TTL_MINUTEN}).
 *
 * Spec 55: Schlüssel Team+User+Planungs-Session (`VoiceModal::sitzungIds()`) — NICHT mehr die
 * Laravel-Browser-Session (der Agent lebt jetzt als Panel in der Planungs-Leitstelle, nicht
 * mehr seitenübergreifend; ein Gerätewechsel soll das Gespräch nicht abschneiden, solange
 * dieselbe Planungs-Session offen bleibt). Ohne offene Session: EIN geteilter Team+User-Eimer.
 *
 * Enthält NUR, was der Agent für Anschlussfragen braucht — kein Roh-Log: die letzten
 * {@see MAX_ZUEGE} Züge (gekürzt), die zuletzt NICHT bestätigten Vorschläge (für "ja"/"das
 * zweite") und das zuletzt geöffnete Objekt (für "dieses Rezept" ohne Namen).
 *
 * @phpstan-type VoiceSitzung array{
 *     zuege: list<array{rolle: string, text: string}>,
 *     offene_vorschlaege: array,
 *     geoeffnetes_objekt: ?array{type: string, id: int, name: ?string},
 * }
 */
class VoiceSessionService
{
    public const TTL_MINUTEN = 30;

    /** Letzte N Züge (User+Agent zusammen) — genug für eine Anschlussfrage, kein Transkript-Archiv. */
    public const MAX_ZUEGE = 6;

    /** Ein Zug wird auf diese Länge gekürzt, bevor er in Cache/Prompt landet. */
    private const ZUG_MAX_ZEICHEN = 400;

    public function schluessel(int $teamId, int $userId, string $sessionId): string
    {
        return "voice_session:{$teamId}:{$userId}:{$sessionId}";
    }

    /** @return VoiceSitzung */
    public function lade(int $teamId, int $userId, string $sessionId): array
    {
        $sitzung = Cache::get($this->schluessel($teamId, $userId, $sessionId));

        return is_array($sitzung) ? $sitzung : $this->leer();
    }

    /** @return VoiceSitzung */
    public function leer(): array
    {
        return ['zuege' => [], 'offene_vorschlaege' => [], 'geoeffnetes_objekt' => null];
    }

    /** @param VoiceSitzung $sitzung */
    public function speichere(int $teamId, int $userId, string $sessionId, array $sitzung): void
    {
        Cache::put($this->schluessel($teamId, $userId, $sessionId), $sitzung, now()->addMinutes(self::TTL_MINUTEN));
    }

    /** „Gespräch vergessen" — kompletter Reset, nichts bleibt hängen. */
    public function vergessen(int $teamId, int $userId, string $sessionId): void
    {
        Cache::forget($this->schluessel($teamId, $userId, $sessionId));
    }

    /**
     * @param  VoiceSitzung  $sitzung
     * @return VoiceSitzung
     */
    public function zugHinzufuegen(array $sitzung, string $rolle, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return $sitzung;
        }
        $sitzung['zuege'][] = ['rolle' => $rolle, 'text' => mb_strimwidth($text, 0, self::ZUG_MAX_ZEICHEN, '…')];
        $sitzung['zuege'] = array_slice($sitzung['zuege'], -self::MAX_ZUEGE);

        return $sitzung;
    }

    /**
     * Kurzer, promptfähiger Verlaufstext — GEKÜRZT (siehe {@see zugHinzufuegen()}), kein
     * Roh-Dump aller Tool-Läufe. `null` wenn nichts zu erzählen ist (spart Prompt-Zeichen
     * statt eines leeren "[Verlauf: ]"-Blocks).
     *
     * @param  VoiceSitzung  $sitzung
     */
    public function promptKontext(array $sitzung): ?string
    {
        if ($sitzung['zuege'] === [] && $sitzung['geoeffnetes_objekt'] === null) {
            return null;
        }
        $zeilen = array_map(
            static fn (array $z): string => ($z['rolle'] === 'user' ? 'Nutzer' : 'Agent') . ': ' . $z['text'],
            $sitzung['zuege'],
        );
        $objekt = $sitzung['geoeffnetes_objekt'];
        if ($objekt !== null) {
            $zeilen[] = 'Zuletzt geöffnet: ' . ($objekt['type'] ?? '?') . ' #' . ($objekt['id'] ?? '?')
                . ($objekt['name'] !== null ? ' „' . $objekt['name'] . '"' : '');
        }

        return implode("\n", $zeilen);
    }
}
