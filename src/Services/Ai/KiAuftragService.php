<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\KiAuftragAusstehendException;
use Platform\FoodAlchemist\Support\FaTeam;
use RuntimeException;

/**
 * #1002: Auftrags-Lebenszyklus des externen KI-Wegs. Ablauf (Variante „Wiederholung"):
 *  1. Gateway ruft {@see antwortOderAuftrag()} am Provider-Punkt: gibt es eine beantwortete Antwort zu
 *     (Team, Feature, messages_hash), wird sie einmalig verbraucht — sonst Auftrag anlegen + KiAuftragAusstehendException.
 *  2. MCP-Client holt offene Aufträge ({@see offene()}) und beantwortet sie ({@see beantworte()}).
 *  3. Der Aufrufer wird wiederholt; Prüfung, Call-Log und Nachverarbeitung laufen unverändert im Gateway/Aufrufer.
 * Ohne gültige Auftrags-ID wird nichts geschrieben.
 */
class KiAuftragService
{
    public const TTL_STUNDEN = 24;

    /** @param array<int, array<string, mixed>> $messages @param array<string, mixed> $optionen */
    public function antwortOderAuftrag(string $feature, array $messages, array $optionen, array $audit = []): array
    {
        $teamId = FaTeam::aktuellId();
        if ($teamId === null) {
            throw new RuntimeException('Externer KI-Weg braucht ein Team im Kontext.');
        }
        $hash = self::hash($messages);

        $fertig = DB::table('foodalchemist_ki_auftraege')
            ->where('team_id', $teamId)->where('feature', $feature)->where('messages_hash', $hash)
            ->where('status', 'beantwortet')->orderBy('id')->first();
        if ($fertig !== null) {
            // einmalig verbrauchen: UPDATE mit Statusbedingung, damit zwei parallele Läufe nicht dieselbe Antwort ziehen
            $n = DB::table('foodalchemist_ki_auftraege')->where('id', $fertig->id)->where('status', 'beantwortet')
                ->update(['status' => 'verbraucht', 'verbraucht_at' => now(), 'updated_at' => now()]);
            if ($n === 1) {
                $modell = trim(($fertig->anbieter ? $fertig->anbieter . '/' : '') . (string) $fertig->modell, '/');

                return ['content' => (string) $fertig->antwort, 'model' => $modell !== '' ? $modell : 'extern', 'usage' => [], 'extern' => true];
            }
        }

        $offen = DB::table('foodalchemist_ki_auftraege')
            ->where('team_id', $teamId)->where('feature', $feature)->where('messages_hash', $hash)
            ->where('status', 'offen')->where('laeuft_ab_at', '>', now())->first();
        if ($offen !== null) {
            throw new KiAuftragAusstehendException($offen->uuid, $feature);
        }

        $uuid = (string) \Symfony\Component\Uid\UuidV7::generate();
        DB::table('foodalchemist_ki_auftraege')->insert([
            'uuid' => $uuid, 'team_id' => $teamId, 'user_id' => \Illuminate\Support\Facades\Auth::id(),
            'feature' => $feature, 'messages_hash' => $hash,
            'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE),
            'optionen' => json_encode(array_filter($optionen, fn ($v) => is_scalar($v) || is_array($v)), JSON_UNESCAPED_UNICODE),
            'target_table' => $audit['target_table'] ?? null, 'target_id' => $audit['target_id'] ?? null,
            'knowledge_run_id' => $audit['knowledge_run_id'] ?? null,
            'status' => 'offen', 'laeuft_ab_at' => now()->addHours(self::TTL_STUNDEN),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        throw new KiAuftragAusstehendException($uuid, $feature);
    }

    /** @return list<object> offene, nicht abgelaufene Aufträge des Teams (inkl. vollem Prompt) */
    public function offene(int $teamId, int $limit = 50, ?string $feature = null): array
    {
        $this->laufAb($teamId);

        return DB::table('foodalchemist_ki_auftraege')->where('team_id', $teamId)->where('status', 'offen')
            ->when($feature, fn ($q) => $q->where('feature', $feature))
            ->orderBy('id')->limit(max(1, min(50, $limit)))->get()->all();
    }

    /**
     * Speichert eine externe Antwort. Abgelehnt (RuntimeException): unbekannte/fremde ID, nicht offen, abgelaufen,
     * Antwort kein JSON-Objekt. Die fachliche Prüfung (structural_retry, Guards) macht danach der Wiederholungslauf.
     *
     * @param array<string, mixed>|string $werte
     */
    public function beantworte(int $teamId, string $auftragUuid, array|string $werte, ?string $anbieter, ?string $modell): void
    {
        $zeile = DB::table('foodalchemist_ki_auftraege')->where('uuid', $auftragUuid)->where('team_id', $teamId)->first();
        if ($zeile === null) {
            throw new RuntimeException("Unbekannter KI-Auftrag {$auftragUuid}.");
        }
        if ($zeile->status !== 'offen') {
            throw new RuntimeException("KI-Auftrag {$auftragUuid} ist nicht offen (Status {$zeile->status}).");
        }
        if ($zeile->laeuft_ab_at !== null && now()->gt($zeile->laeuft_ab_at)) {
            DB::table('foodalchemist_ki_auftraege')->where('id', $zeile->id)->update(['status' => 'abgelaufen', 'updated_at' => now()]);
            throw new RuntimeException("KI-Auftrag {$auftragUuid} ist abgelaufen.");
        }
        $roh = is_array($werte) ? json_encode($werte, JSON_UNESCAPED_UNICODE) : (string) $werte;
        $json = json_decode(app(AiGatewayService::class)->stripJsonFence($roh), true);
        if (! is_array($json)) {
            throw new RuntimeException('Antwort ist kein JSON-Objekt/Array — Schema-Verstoß, nichts gespeichert.');
        }
        DB::table('foodalchemist_ki_auftraege')->where('id', $zeile->id)->where('status', 'offen')->update([
            'status' => 'beantwortet', 'antwort' => json_encode($json, JSON_UNESCAPED_UNICODE),
            'anbieter' => $anbieter, 'modell' => $modell, 'beantwortet_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function hash(array $messages): string
    {
        return hash('sha256', json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function laufAb(int $teamId): void
    {
        DB::table('foodalchemist_ki_auftraege')->where('team_id', $teamId)->where('status', 'offen')
            ->where('laeuft_ab_at', '<', now())->update(['status' => 'abgelaufen', 'updated_at' => now()]);
    }
}
