<?php

namespace Platform\FoodAlchemist\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
 * Seit P1 trägt der Trait auch das **Gültigkeitsfenster**. Zwei Achsen, die zusammen „läuft am
 * Stichtag" ergeben: der Status sagt, was ein Mensch gesetzt hat, das Fenster sagt, ob das
 * Datum noch passt. Getrennt gehalten, weil sie unterschiedlich entstehen — und weil das
 * Fenster **nur die Anzeige** bremst, nie die Daten ändert.
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

    /** Läuft diese Ausgabe laut Status? (Ohne Fenster — s. {@see self::laeuftAm}.) */
    public function laeuftLautStatus(): bool
    {
        return $this->statusWert()->laeuft();
    }

    // ── Gültigkeitsfenster (Spec 33 · P1) ────────────────────────────────────

    /**
     * Beginn des Gültigkeitsfensters, `null` = unbefristet.
     *
     * Standard ist die Spalte `gueltig_von`. Der Speiseplan hat keine — er überschreibt und
     * leitet aus seinen Einträgen ab (s. {@see \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan}).
     */
    public function gueltigVon(): ?CarbonInterface
    {
        return $this->fensterWert('gueltig_von');
    }

    /** Ende des Gültigkeitsfensters, `null` = unbefristet. */
    public function gueltigBis(): ?CarbonInterface
    {
        return $this->fensterWert('gueltig_bis');
    }

    private function fensterWert(string $spalte): ?CarbonInterface
    {
        $wert = $this->attributes[$spalte] ?? null;
        if ($wert === null || $wert === '') {
            return null;
        }

        return $wert instanceof CarbonInterface ? $wert : Carbon::parse((string) $wert);
    }

    /**
     * Läuft diese Ausgabe am Stichtag? Status UND Fenster müssen stimmen.
     *
     * **Warum das Fenster mitzählt:** Versenden setzt auf `aktiv`, und nichts läuft von selbst
     * ab. Ohne diese Prüfung stünden nach zwei Saisons fünf „laufende" Karten je Standort und
     * die Konfliktliste wäre wertlos. Das Fenster bremst — **ohne die Daten anzufassen**. Der
     * Status bleibt `aktiv`, bis ein Mensch archiviert; nur die Anzeige weiß, dass es vorbei ist.
     */
    public function laeuftAm(mixed $stichtag = null): bool
    {
        if (! $this->laeuftLautStatus()) {
            return false;
        }

        $tag = Carbon::parse($stichtag ?? now())->startOfDay();
        $von = $this->gueltigVon()?->copy()->startOfDay();
        $bis = $this->gueltigBis()?->copy()->startOfDay();

        return ! ($von !== null && $tag->lt($von)) && ! ($bis !== null && $tag->gt($bis));
    }

    /**
     * Warum läuft es (nicht)? Ein Schlüssel für die Übersicht — ein grauer Punkt ohne Grund ist
     * in einer Steuerungsfläche wertlos.
     *
     * `laeuft` · `geplant` (Fenster liegt in der Zukunft) · `abgelaufen` (Fenster vorbei, Status
     * noch aktiv) · `entwurf` · `inaktiv` · `archiviert`
     */
    public function laufZustand(mixed $stichtag = null): string
    {
        $status = $this->statusWert();
        if (! $status->laeuft()) {
            return $status->value;
        }

        $tag = Carbon::parse($stichtag ?? now())->startOfDay();
        $von = $this->gueltigVon()?->copy()->startOfDay();
        $bis = $this->gueltigBis()?->copy()->startOfDay();

        return match (true) {
            $von !== null && $tag->lt($von) => 'geplant',
            $bis !== null && $tag->gt($bis) => 'abgelaufen',
            default => 'laeuft',
        };
    }

    /** Klartext zum {@see self::laufZustand} — `null`, wenn es läuft. */
    public function laufGrund(mixed $stichtag = null): ?string
    {
        return match ($this->laufZustand($stichtag)) {
            'laeuft' => null,
            'geplant' => 'Startet erst am ' . $this->gueltigVon()?->format('d.m.Y'),
            'abgelaufen' => 'Fenster endete am ' . $this->gueltigBis()?->format('d.m.Y')
                . ' — noch nicht archiviert',
            default => $this->statusWert()->grundNichtLaufend(),
        };
    }
}
