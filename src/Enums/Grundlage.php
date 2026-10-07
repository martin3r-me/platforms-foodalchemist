<?php

namespace Platform\FoodAlchemist\Enums;

/** Spec 60 · P6: worauf eine Aussage beruht. */
enum Grundlage: string
{
    case InspireGemessen = 'inspire_gemessen';
    case NaehrwertBelegt = 'naehrwert_belegt';
    case Regel = 'regel';                       // Kategorie/Verfahren (KategorieRegeln)
    case DossierEntwurf = 'dossier_entwurf';
    case DossierGeprueft = 'dossier_geprueft';
    case Keine = 'keine';

    public function label(): string
    {
        return match ($this) {
            self::InspireGemessen => 'gemessen (Foodpairing)',
            self::NaehrwertBelegt => 'belegt (Nährwerte)',
            self::Regel => 'aus Kategorie',
            self::DossierEntwurf => 'aus Dossier, ungeprüft',
            self::DossierGeprueft => 'aus Dossier, geprüft',
            self::Keine => 'keine Grundlage',
        };
    }

    /** Grundlage einer Eigenschaft bzw. eines Bedarfs aus Quelle und Status. */
    public static function aus(string $quelle, string $status = 'entwurf'): self
    {
        return match ($quelle) {
            'sensorik', 'naehrwert' => self::NaehrwertBelegt,
            'kategorie' => self::Regel,
            default => $status === WissensStatus::Geprueft->value ? self::DossierGeprueft : self::DossierEntwurf,
        };
    }
}
