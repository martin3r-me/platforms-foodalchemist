<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Signal-Typen (#378) — detektierte Auffälligkeiten (Klasse B) im „Signale"-Modul.
 * Die Entscheidungs-Queues (LA→GP-Match, KI-Bulk, VK ohne Klasse …) sind Klasse A
 * und bleiben in der ReviewQueue — sie sind KEINE SignalTyp-Werte.
 */
enum SignalTyp: string
{
    case PreisAnomalie = 'preis_anomalie';
    case PreisSprungMargeImpact = 'preis_sprung_marge_impact';
    case VeraltetePreise = 'veraltete_preise';
    case MargeUnterZiel = 'marge_unter_ziel';
    case WareneinsatzUeberZiel = 'wareneinsatz_ueber_ziel';
    case DatenqualitaetGpLa = 'datenqualitaet_gp_la';
    case NaehrwertPlausi = 'naehrwert_plausi';
    // Datenqualitäts-Kaskade (Ampel, DataQualityService) — Ebenen-übergreifende Lücken.
    case AnkerFehlt = 'anker_fehlt';
    case ServierformUnbestimmt = 'servierform_unbestimmt';
    case EkKetteUnvollstaendig = 'ek_kette_unvollstaendig';
    // R2.5: Live-VK weicht vom freigegebenen Snapshot über die Leitplanke ab.
    case VkAnpassungEmpfohlen = 'vk_anpassung_empfohlen';
    // R9.1: Vertrags-Kündigungsfrist eines Lieferanten läuft ab.
    case VertragsfristFaellig = 'vertragsfrist_faellig';
    // R6.11 · S2: Pairing-Wissensdokument behauptet eine Paarung, die der Anker-Graph nicht kennt (R&D-Frage).
    case WiderspruchWissenGraph = 'widerspruch_wissen_graph';
    // Spec 19 E9.3: Kreativ-Phase wünscht ein Aroma, das kein beschaffbarer GP trägt (Sortiments-/Buy-Signal).
    case SortimentsLuecke = 'sortiments_luecke';
    // Spec 21 Tranche A: Inhalts-Qualität auf Rezept-Ebene (deterministisch, 0-Egress).
    // Bis dahin prüfte das System am Rezept nur EK-Kette, Flavor-Anker und Servierform.
    case RezeptOhneZubereitung = 'rezept_ohne_zubereitung';
    case RezeptMengenLuecke = 'rezept_mengen_luecke';
    case RezeptYieldImplausibel = 'rezept_yield_implausibel';
    case RezeptEinZutat = 'rezept_ein_zutat';
    case RezeptNamingRegelwerk = 'rezept_naming_regelwerk';
    case RezeptDublette = 'rezept_dublette';
    case RezeptKategorieProblem = 'rezept_kategorie_problem';
    case RezeptAllergenUnbelastbar = 'rezept_allergen_unbelastbar';
    case RezeptZutatenUngemappt = 'rezept_zutaten_ungemappt';
    case RezeptSubStubOffen = 'rezept_sub_stub_offen';
    case RezeptVerwaist = 'rezept_verwaist';
    // Spec 21 Tranche C: Konzept-Ebene (bis dahin 0 Signale — die Kaskade endete am Gericht).
    // Gemessen wird nur an Konzepten, die IN GEBRAUCH sind (s. DataQualityService::konzepteInGebrauch):
    // ein unfertiger Entwurf ist kein Mangel, ein unfertiges verkauftes Konzept schon.
    case KonzeptSlotLuecke = 'konzept_slot_luecke';
    case KonzeptOhneWording = 'konzept_ohne_wording';
    // S4b — die frame-gestützte Hälfte: gemessen wird gegen das Planungs-Gerüst
    // (CoverageService), also gegen ein SOLL, das jemand für dieses Konzept gesetzt hat.
    // Ohne Gerüst gibt es kein Soll und damit auch keinen Befund.
    case KonzeptPreisbandVerletzt = 'konzept_preisband_verletzt';
    case KonzeptRegelVerletzt = 'konzept_regel_verletzt';
    // S4b-2 — die Anker-Graph-Hälfte: greift ohne Gerüst und ohne Soll, weil sie das
    // Konzept gegen sich selbst liest (welche Gänge tragen dieselbe Hauptzutat).
    // Bewusst `info`: eine Wiederholung kann gewollt sein (Themen-Menü).
    case KonzeptDramaturgie = 'konzept_dramaturgie';
    // Spec 21 Tranche E · E3: Meta-Signal über die Zeitreihe — ein Zähler ist gegenüber
    // dem Vorlauf gestiegen. Alarmiert bei *Veränderung*, nicht bei Bestand; das ist der
    // eigentliche „System im Blick"-Mechanismus.
    case QualitaetDrift = 'qualitaet_drift';

    public function label(): string
    {
        return match ($this) {
            self::PreisAnomalie => 'Preis-Anomalie',
            self::PreisSprungMargeImpact => 'Preis-Sprung (Marge-Impact)',
            self::VeraltetePreise => 'Veraltete Preise',
            self::MargeUnterZiel => 'Marge unter Ziel',
            self::WareneinsatzUeberZiel => 'Wareneinsatz über Ziel',
            self::DatenqualitaetGpLa => 'Datenqualität GP/LA',
            self::NaehrwertPlausi => 'Nährwert-Plausibilität',
            self::AnkerFehlt => 'Flavor-Anker fehlt',
            self::ServierformUnbestimmt => 'Servierform unbestimmt',
            self::EkKetteUnvollstaendig => 'EK-Kette unvollständig',
            self::VkAnpassungEmpfohlen => 'VK-Anpassung empfohlen',
            self::VertragsfristFaellig => 'Vertragsfrist fällig',
            self::WiderspruchWissenGraph => 'Widerspruch Wissen ↔ Graph',
            self::SortimentsLuecke => 'Sortiments-Lücke',
            self::RezeptOhneZubereitung => 'Rezept ohne Zubereitung',
            self::RezeptMengenLuecke => 'Rezept mit Mengen-Lücke',
            self::RezeptYieldImplausibel => 'Rezept-Ausbeute implausibel',
            self::RezeptEinZutat => 'Rezept mit nur einer Zutat',
            self::RezeptNamingRegelwerk => 'Rezept-Name gegen Regelwerk',
            self::RezeptDublette => 'Rezept-Dublette',
            self::RezeptKategorieProblem => 'Rezept-Kategorie fehlt/stillgelegt',
            self::RezeptAllergenUnbelastbar => 'Rezept-Allergene unbelastbar',
            self::RezeptZutatenUngemappt => 'Rezept mit ungemappten Zutaten',
            self::RezeptSubStubOffen => 'Sub-Rezept-Stub offen',
            self::RezeptVerwaist => 'Rezept verwaist',
            self::KonzeptSlotLuecke => 'Konzept mit unbesetztem Pflicht-Slot',
            self::KonzeptOhneWording => 'Konzept ohne Kunden-Wording',
            self::KonzeptPreisbandVerletzt => 'Konzept außerhalb des Preisbands',
            self::KonzeptRegelVerletzt => 'Konzept verletzt eine Gerüst-Regel',
            self::KonzeptDramaturgie => 'Konzept wiederholt eine Hauptzutat',
            self::QualitaetDrift => 'Qualität verschlechtert sich',
        };
    }

    /** Heroicon (ohne Präfix) für die Inbox-Darstellung. */
    public function icon(): string
    {
        return match ($this) {
            self::PreisAnomalie => 'heroicon-o-arrow-trending-up',
            self::PreisSprungMargeImpact => 'heroicon-o-bolt',
            self::VeraltetePreise => 'heroicon-o-clock',
            self::MargeUnterZiel => 'heroicon-o-scale',
            self::WareneinsatzUeberZiel => 'heroicon-o-shopping-cart',
            self::DatenqualitaetGpLa => 'heroicon-o-exclamation-triangle',
            self::NaehrwertPlausi => 'heroicon-o-beaker',
            self::AnkerFehlt => 'heroicon-o-link-slash',
            self::ServierformUnbestimmt => 'heroicon-o-question-mark-circle',
            self::EkKetteUnvollstaendig => 'heroicon-o-currency-euro',
            self::VkAnpassungEmpfohlen => 'heroicon-o-tag',
            self::VertragsfristFaellig => 'heroicon-o-calendar-days',
            self::WiderspruchWissenGraph => 'heroicon-o-light-bulb',
            self::SortimentsLuecke => 'heroicon-o-shopping-bag',
            self::RezeptOhneZubereitung => 'heroicon-o-document-minus',
            self::RezeptMengenLuecke => 'heroicon-o-scale',
            self::RezeptYieldImplausibel => 'heroicon-o-arrows-up-down',
            self::RezeptEinZutat => 'heroicon-o-cube',
            self::RezeptNamingRegelwerk => 'heroicon-o-pencil-square',
            self::RezeptDublette => 'heroicon-o-document-duplicate',
            self::RezeptKategorieProblem => 'heroicon-o-folder-minus',
            self::RezeptAllergenUnbelastbar => 'heroicon-o-shield-exclamation',
            self::RezeptZutatenUngemappt => 'heroicon-o-link-slash',
            self::RezeptSubStubOffen => 'heroicon-o-puzzle-piece',
            self::RezeptVerwaist => 'heroicon-o-archive-box',
            self::KonzeptSlotLuecke => 'heroicon-o-squares-2x2',
            self::KonzeptOhneWording => 'heroicon-o-chat-bubble-bottom-center-text',
            self::KonzeptPreisbandVerletzt => 'heroicon-o-banknotes',
            self::KonzeptRegelVerletzt => 'heroicon-o-no-symbol',
            self::KonzeptDramaturgie => 'heroicon-o-arrow-path',
            self::QualitaetDrift => 'heroicon-o-arrow-trending-down',
        };
    }

    /**
     * Spec 21 Tranche A — Rezept-Inhalts-Qualität (deterministisch, 0-Egress).
     * Abgrenzung zu den älteren Kaskaden-Typen (EK/Anker/Servierform), die Geld-
     * bzw. Erdungs-Lücken messen statt der Rezeptur selbst.
     */
    public function istRezeptQualitaet(): bool
    {
        return str_starts_with($this->value, 'rezept_');
    }

    /**
     * Spec 21 Tranche C — Qualität der Komposition (Konzept-Ebene, deterministisch).
     * Eigene Tranche statt Erweiterung von A: das Prüf-Objekt ist ein anderes
     * (Konzept statt Rezept), und die Arbeitsmenge ist enger — geprüft wird nur, was
     * in Gebrauch ist.
     */
    public function istKonzeptQualitaet(): bool
    {
        return str_starts_with($this->value, 'konzept_');
    }
}
