<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spec 65 · Bearbeitungssperre. Ein Ziel (Typ + ID) gehört höchstens einer Person.
 *
 * - sperren(): frei oder abgelaufen → gehört danach mir; schon meins → verlängert; sonst null + Inhaber.
 * - Ablauf 15 Min ohne Aktivität; jede Schreibaktion des Inhabers verlängert (Guard im ServiceProvider).
 * - atomar über den Unique-Index (ziel_typ, ziel_id): zwei gleichzeitige Klicks → einer gewinnt.
 */
final class BearbeitungssperreService
{
    public const MINUTEN = 15;

    private const TABELLE = 'foodalchemist_bearbeitungssperren';

    /** @return array{ok: bool, inhaber: ?array{user_id:int, name:?string, seit:string, laeuft_ab:string}} */
    public function sperren(string $typ, string|int $id, int $userId, ?string $name, ?int $teamId = null): array
    {
        $id = (string) $id;
        $jetzt = Carbon::now();
        $ab = $jetzt->copy()->addMinutes(self::MINUTEN);

        // abgelaufene Sperre auf dieses Ziel räumen (frei = abgelaufen)
        DB::table(self::TABELLE)->where('ziel_typ', $typ)->where('ziel_id', $id)->where('laeuft_ab', '<', $jetzt)->delete();

        $vorhanden = $this->zeile($typ, $id);
        if ($vorhanden !== null && (int) $vorhanden->user_id !== $userId) {
            return ['ok' => false, 'inhaber' => $this->inhaber($vorhanden)];
        }
        if ($vorhanden !== null) {
            DB::table(self::TABELLE)->where('id', $vorhanden->id)->update(['laeuft_ab' => $ab, 'updated_at' => $jetzt]);

            return ['ok' => true, 'inhaber' => null];
        }

        try {
            DB::table(self::TABELLE)->insert([
                'team_id' => $teamId, 'ziel_typ' => $typ, 'ziel_id' => $id, 'user_id' => $userId, 'user_name' => $name,
                'seit' => $jetzt, 'laeuft_ab' => $ab, 'created_at' => $jetzt, 'updated_at' => $jetzt,
            ]);
        } catch (QueryException $e) {
            // Wettlauf: jemand war Millisekunden schneller
            $andere = $this->zeile($typ, $id);
            if ($andere !== null && (int) $andere->user_id !== $userId) {
                return ['ok' => false, 'inhaber' => $this->inhaber($andere)];
            }
            if ($andere === null) {
                throw $e;
            }
        }

        return ['ok' => true, 'inhaber' => null];
    }

    /** Gehört das Ziel gerade dieser Person (und ist nicht abgelaufen)? */
    public function haelt(string $typ, string|int $id, int $userId): bool
    {
        $z = $this->zeile($typ, (string) $id);

        return $z !== null && (int) $z->user_id === $userId && Carbon::parse($z->laeuft_ab)->isFuture();
    }

    /** Verlängert die eigene Sperre (Aktivität). false = nicht (mehr) meine. */
    public function verlaengern(string $typ, string|int $id, int $userId): bool
    {
        return DB::table(self::TABELLE)
            ->where('ziel_typ', $typ)->where('ziel_id', (string) $id)->where('user_id', $userId)
            ->where('laeuft_ab', '>=', Carbon::now())
            ->update(['laeuft_ab' => Carbon::now()->addMinutes(self::MINUTEN), 'updated_at' => Carbon::now()]) > 0;
    }

    public function freigeben(string $typ, string|int $id, int $userId): void
    {
        DB::table(self::TABELLE)->where('ziel_typ', $typ)->where('ziel_id', (string) $id)->where('user_id', $userId)->delete();
    }

    /** Admin: fremde Sperre lösen. Rechteprüfung beim Aufrufer. */
    public function loesen(string $typ, string|int $id): ?array
    {
        $z = $this->zeile($typ, (string) $id);
        if ($z === null) {
            return null;
        }
        DB::table(self::TABELLE)->where('id', $z->id)->delete();

        return $this->inhaber($z);
    }

    /** Aktive fremde Sperre oder null. */
    public function fremd(string $typ, string|int $id, int $userId): ?array
    {
        $z = $this->zeile($typ, (string) $id);
        if ($z === null || (int) $z->user_id === $userId || Carbon::parse($z->laeuft_ab)->isPast()) {
            return null;
        }

        return $this->inhaber($z);
    }

    private function zeile(string $typ, string $id): ?object
    {
        return DB::table(self::TABELLE)->where('ziel_typ', $typ)->where('ziel_id', $id)->first();
    }

    private function inhaber(object $z): array
    {
        return ['user_id' => (int) $z->user_id, 'name' => $z->user_name, 'seit' => (string) $z->seit, 'laeuft_ab' => (string) $z->laeuft_ab];
    }
}
