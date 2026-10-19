<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistKiKlasseOverride;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Support\KiKlassen;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 85 (Dominique 19.10.): die EINE Lesestelle für die KI-Klasse eines Arbeitsschritts — eingestellt (Master-Admin) > Code.
 *
 * Scoped gebunden (je Request bzw. je Queue-Job frisch): die Einstellungen werden einmal gelesen und gelten für den ganzen Request;
 * {@see setze()} leert den Speicher. Global, nicht je Mandant: nur das Master-Team stellt ein, und dort nur die Rolle Admin.
 */
final class KiKlassenEinstellung
{
    /** @var array<string, string>|null feature_key ⇒ klasse; null = noch nicht gelesen */
    private ?array $eingestellt = null;

    /** Wirksame Klasse eines Prompt-Keys. */
    public function klasseFuer(string $key): string
    {
        return $this->eingestellt()[$key] ?? $this->standard($key);
    }

    /** Klasse aus dem Code (Registry), ohne Einstellung. */
    public function standard(string $key): string
    {
        return KiKlassen::von(config('foodalchemist.prompts', [])[$key] ?? null);
    }

    /** @return array<string, string> nur eingestellte Schritte */
    public function eingestellt(): array
    {
        if ($this->eingestellt === null) {
            $this->eingestellt = Schema::hasTable('foodalchemist_ki_klassen_override')
                ? FoodAlchemistKiKlasseOverride::query()->whereNotNull('klasse')->pluck('klasse', 'feature_key')
                    ->filter(fn ($k) => KiKlassen::gueltig($k))->all()
                : [];
        }

        return $this->eingestellt;
    }

    /** @return list<string> die einstellbaren Arbeitsschritte (Prompt-Registry) */
    public function schritte(): array
    {
        return array_values(array_diff(array_keys((array) config('foodalchemist.prompts', [])), ['demo.echo']));
    }

    /** Darf der angemeldete Benutzer in diesem Team einstellen? Nur Admin im Master-Team. */
    public function darfEinstellen(Team $team): bool
    {
        return MasterTeam::istMaster($team) && app(FaRechte::class)->darf(Auth::user(), $team, FaRolle::Admin);
    }

    /**
     * Klasse eines Schritts einstellen; `null` = zurück auf die Klasse aus dem Code.
     *
     * @throws FaRechtFehltException  nicht Admin im Master-Team
     * @throws \InvalidArgumentException  unbekannter Schritt oder ungültige Klasse
     */
    public function setze(Team $team, string $key, ?string $klasse, ?string $grund = null): void
    {
        if (! MasterTeam::istMaster($team)) {
            throw new FaRechtFehltException(FaRolle::Admin, null, 'KI-Klassen einstellen (nur im Master-Team)');
        }
        app(FaRechte::class)->pruefe(Auth::user(), $team, FaRolle::Admin, 'KI-Klassen einstellen');
        if (! in_array($key, $this->schritte(), true)) {
            throw new \InvalidArgumentException("Unbekannter Arbeitsschritt „{$key}“.");
        }
        if ($klasse !== null && ! KiKlassen::gueltig($klasse)) {
            throw new \InvalidArgumentException("Ungültige Klasse „{$klasse}“ — erlaubt: " . implode(', ', KiKlassen::ALLE) . '.');
        }
        $zeile = FoodAlchemistKiKlasseOverride::firstOrNew(['feature_key' => $key]);
        $zeile->fill(['klasse' => $klasse, 'set_by' => Auth::id(), 'grund' => $grund !== null && trim($grund) !== '' ? trim($grund) : null]);
        if ($zeile->exists || $klasse !== null) {
            $zeile->save();   // eine Zeile bleibt auch beim Zurücksetzen (klasse NULL) — der Verlauf hängt an ihr
        }
        $this->eingestellt = null;
    }
}
