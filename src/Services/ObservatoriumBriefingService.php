<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistObservatoriumBriefingProfil as Profil;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt as Inhalt;

/**
 * „Mein Briefing“ (Dominique 07.11.2026): eine gespeicherte Einstellung je Person statt der gerade offenen Filter.
 * Branchen (mehrere, ODER), Region, Zeitraum und bis zu 10 Themen (Suchbegriffe, ODER). Lesen reicht als Recht — es ist
 * die eigene Einstellung, keine Team-Daten. Ohne Profil: null, das Briefing fällt auf die aktuellen Filter zurück.
 */
class ObservatoriumBriefingService
{
    public const TAGE = ['7' => 'Letzte 7 Tage', '30' => 'Letzte 30 Tage', '90' => 'Letzte 90 Tage'];

    public const REGIONEN = ['' => 'Alle Regionen', 'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz'];

    public const MAX_THEMEN = 10;

    public function __construct(private readonly FaRechte $rechte) {}

    public function profil(Team $team, int $userId): ?Profil
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Lesen, 'Briefing lesen');

        return Profil::where('team_id', $team->id)->where('user_id', $userId)->first();
    }

    /**
     * @param  array{branchen?: mixed, region?: ?string, tage?: ?string, themen?: mixed}  $daten
     */
    public function speichern(Team $team, int $userId, array $daten): Profil
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Lesen, 'Briefing einstellen');
        $branchen = array_values(array_unique(array_filter(array_map('strval', (array) ($daten['branchen'] ?? [])))));
        if (($fremd = array_diff($branchen, array_keys(Inhalt::BRANCHEN))) !== []) {
            throw new \RuntimeException('Unbekannte Branche: '.implode(', ', $fremd).'.');
        }
        $region = (string) ($daten['region'] ?? '');
        if (! array_key_exists($region, self::REGIONEN)) {
            throw new \RuntimeException('Region bitte als DE, AT, CH oder leer (alle) angeben.');
        }
        $tage = (string) ($daten['tage'] ?? '7');
        if (! array_key_exists($tage, self::TAGE)) {
            throw new \RuntimeException('Zeitraum bitte 7, 30 oder 90 Tage.');
        }
        $themen = self::themen($daten['themen'] ?? []);
        if (count($themen) > self::MAX_THEMEN) {
            throw new \RuntimeException('Höchstens '.self::MAX_THEMEN.' Themen.');
        }

        return Profil::updateOrCreate(['team_id' => $team->id, 'user_id' => $userId],
            ['branchen' => $branchen ?: null, 'region' => $region ?: null, 'tage' => $tage, 'themen' => $themen ?: null]);
    }

    public function loeschen(Team $team, int $userId): void
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Lesen, 'Briefing einstellen');
        Profil::where('team_id', $team->id)->where('user_id', $userId)->delete();
    }

    /** Filter für ObservatoriumService::abfrage aus dem Profil (Ansicht „entdecken“ = alles Eingeordnete). */
    public static function filter(Profil $p): array
    {
        return ['ansicht' => 'entdecken', 'branchen' => $p->branchen ?? [], 'region' => (string) ($p->region ?? ''),
            'tage' => (string) $p->tage, 'themen' => $p->themen ?? []];
    }

    /** Lesbare Kurzform fürs Briefing: „Catering, Events · Deutschland · 7 Tage · Themen: Protein, Bowl“. */
    public static function zusammenfassung(Profil $p): string
    {
        $teile = [($p->branchen ? implode(', ', array_map(fn ($b) => Inhalt::BRANCHEN[$b] ?? $b, $p->branchen)) : 'Alle Branchen'),
            self::REGIONEN[(string) ($p->region ?? '')] ?? 'Alle Regionen', (self::TAGE[(string) $p->tage] ?? '')];
        if ($p->themen) {
            $teile[] = 'Themen: '.implode(', ', $p->themen);
        }

        return implode(' · ', array_filter($teile));
    }

    /** @return list<string> Text mit Komma/Zeilenumbruch oder Liste → bereinigt, ohne Dubletten, je max. 60 Zeichen */
    public static function themen(mixed $wert): array
    {
        $teile = is_array($wert) ? $wert : (preg_split('/[,;\n]+/u', (string) $wert) ?: []);
        $out = [];
        foreach ($teile as $t) {
            $t = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $t) ?? ''), 0, 60);
            if ($t !== '' && ! in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) {
                $out[] = $t;
            }
        }

        return $out;
    }
}
