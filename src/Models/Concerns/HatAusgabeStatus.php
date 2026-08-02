<?php

namespace Platform\FoodAlchemist\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Platform\FoodAlchemist\Casts\AusgabeStatusCast;
use Platform\FoodAlchemist\Enums\AusgabeStatus;

/**
 * Spec 33 · P0 — der Betriebs-Status einer Ausgabeform (Foodbook, Speisekarte, Speiseplan).
 *
 * Als Trait und nicht dreimal kopiert: die drei Ausgabe-Services sind bereits strukturgleich
 * dupliziert, und eine vierte Kopie derselben Statuslogik hätte die Lage nur verschlimmert.
 * Der Cast wird über `initialize…` gesetzt (Laravel ruft das je Instanz), damit die Models
 * ihre eigenen `$casts` behalten.
 *
 * Das Gültigkeitsfenster kommt in P1 dazu — dieser Trait beantwortet ausschließlich die Frage
 * „was hat der Mensch gesetzt", nicht „ist das Datum noch drin".
 */
trait HatAusgabeStatus
{
    public function initializeHatAusgabeStatus(): void
    {
        // Toleranter Cast statt `AusgabeStatus::class`: der eingebaute Enum-Cast wirft bei
        // jedem Altwert. Siehe AusgabeStatusCast.
        $this->casts['status'] = AusgabeStatusCast::class;
    }

    /** Nur laufende Ausgaben (Status `aktiv`) — ohne Fenster-Prüfung, s. Klassen-Kopf. */
    public function scopeLaeuft(Builder $q): Builder
    {
        return $q->where('status', AusgabeStatus::Aktiv->value);
    }

    /**
     * Der Status als Enum — bequemer Zugriff, der auch dann trägt, wenn ein Aufrufer das Model
     * ohne die `status`-Spalte selektiert hat (`get(['id','name'])`).
     */
    public function statusWert(): AusgabeStatus
    {
        $roh = $this->attributes['status'] ?? null;

        return $roh instanceof AusgabeStatus ? $roh : AusgabeStatus::normalisiere(is_string($roh) ? $roh : null);
    }

    /** Läuft diese Ausgabe laut Status? (Fenster separat, s. P1.) */
    public function laeuftLautStatus(): bool
    {
        return $this->statusWert()->laeuft();
    }
}
