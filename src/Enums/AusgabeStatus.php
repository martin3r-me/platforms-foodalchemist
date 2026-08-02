<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 33 · P0 — der Betriebs-Lebenszyklus der drei Ausgabeformen (Foodbook, Speisekarte,
 * Speiseplan). **Ein** Statusfeld, vier Werte, für alle drei gleich.
 *
 *   entwurf → aktiv ⇄ inaktiv → archiviert
 *
 * **Warum es das braucht.** Bis hierher hatte jede Form ihr eigenes Vokabular, keines davon
 * verbindlich: `draft`｜`aktiv`｜`versendet`｜`archiviert` laut Migrations-Kommentar am Foodbook,
 * `entwurf`｜`aktiv`｜`veroeffentlicht`｜`archiviert` als Array-Konstante an der Speisekarte,
 * `draft`｜`active`｜`archiviert` freihändig im Speiseplan-Blade. Kein Enum, kein Scope, keine
 * Validierung — `status` steht in den `FELDER`-Listen und war mit jedem beliebigen String
 * beschreibbar. Im Dev-Bestand lag deshalb ein Foodbook auf `final`, einem Wert, den keine
 * einzige Quelle kennt. Eine Portfolio-Übersicht auf diesem Fundament zählt falsch.
 *
 * **Kein zweiter Zustand neben `aktiv`** (Entscheid Dominique, 2026-08-02): Versenden bzw.
 * Veröffentlichen *setzt* auf `aktiv` — `versendet`/`veroeffentlicht` verschwinden. Das
 * Versand-Ereignis geht dabei nicht verloren, denn es hing nie am Kopf: eingefroren wird je
 * Kapitel/Rubrik über `snapshot_at`/`snapshot_json` (harte Grenze in
 * {@see \Platform\FoodAlchemist\Services\FoodbookService::anlageZuruckziehen}).
 *
 * **Abgrenzung zur Phase.** {@see \Platform\FoodAlchemist\Services\PhaseService} (kontext →
 * struktur → befuellung → kalkulation → freigabe) beschreibt den BAU-Fortschritt und endet mit
 * der Freigabe. Dieser Enum beschreibt, was danach kommt: den Betrieb. Zwei Achsen, die sich
 * nicht ersetzen.
 */
enum AusgabeStatus: string
{
    /** In Arbeit, war nie draußen. */
    case Entwurf = 'entwurf';

    /** Läuft — die einzige Ausprägung, die als „im Portfolio" zählt. */
    case Aktiv = 'aktiv';

    /** War draußen, ist bewusst vom Netz. Pausiert, nicht abgeschlossen; jederzeit reaktivierbar. */
    case Inaktiv = 'inaktiv';

    /** Abgeschlossen, aus dem Portfolio raus. */
    case Archiviert = 'archiviert';

    public function label(): string
    {
        return match ($this) {
            self::Entwurf => 'Entwurf',
            self::Aktiv => 'Aktiv',
            self::Inaktiv => 'Inaktiv',
            self::Archiviert => 'Archiviert',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Entwurf => 'secondary',
            self::Aktiv => 'success',
            self::Inaktiv => 'warning',
            self::Archiviert => 'secondary',
        };
    }

    /**
     * Zählt diese Ausgabe als laufend?
     *
     * **Nur der Status** — das Gültigkeitsfenster kommt separat dazu (P1). Eine Ausgabe läuft
     * am Stichtag, wenn `laeuft()` UND das Datum im Fenster liegt. Die Trennung ist Absicht:
     * hier steht, was der Mensch gesetzt hat; das Fenster ist die automatische Bremse daneben.
     */
    public function laeuft(): bool
    {
        return $this === self::Aktiv;
    }

    /**
     * Warum läuft es nicht? Ein Grund, den die Übersicht anzeigen kann — ein grauer Punkt ohne
     * Begründung ist in einer Steuerungs-Fläche wertlos.
     */
    public function grundNichtLaufend(): ?string
    {
        return match ($this) {
            self::Aktiv => null,
            self::Entwurf => 'Entwurf — war nie draußen',
            self::Inaktiv => 'Bewusst vom Netz genommen',
            self::Archiviert => 'Abgeschlossen',
        };
    }

    /**
     * Historische und verschriebene Werte auf das gültige Vokabular abbilden.
     *
     * Wird von der Normalisierungs-Migration UND als Sicherheitsnetz beim Lesen benutzt (der
     * Bestand auf demo/prod kann Werte tragen, die hier noch nicht bekannt sind).
     *
     * Unbekanntes wird bewusst zu {@see self::Entwurf} und NICHT zu `aktiv`: `entwurf` ist der
     * einzige Zustand, der nichts behauptet. Ein falsch auf „aktiv" gehobener Datensatz landet
     * im Portfolio und in der Umsatz-Auswertung — der umgekehrte Fehler macht ihn nur unsichtbar
     * und ist damit der harmlosere. Genau so ein Fall war das `final` im Dev-Bestand.
     */
    public static function normalisiere(?string $roh): self
    {
        return match (mb_strtolower(trim((string) $roh))) {
            'aktiv', 'active' => self::Aktiv,
            // Entscheid: „draußen" IST aktiv — kein eigener Zustand daneben.
            'versendet', 'sent', 'veroeffentlicht', 'veröffentlicht', 'published' => self::Aktiv,
            'inaktiv', 'inactive', 'pausiert' => self::Inaktiv,
            'archiviert', 'archived' => self::Archiviert,
            default => self::Entwurf,
        };
    }

    /** Werte, die `normalisiere()` verlustfrei kennt — alles andere fällt auf Entwurf. */
    public static function bekannteRohwerte(): array
    {
        return ['entwurf', 'draft', 'aktiv', 'active', 'versendet', 'sent', 'veroeffentlicht',
            'veröffentlicht', 'published', 'inaktiv', 'inactive', 'pausiert', 'archiviert', 'archived'];
    }

    /** @return array<string,string> value => label, für Auswahlfelder */
    public static function optionen(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->value] = $c->label();
        }

        return $out;
    }
}
