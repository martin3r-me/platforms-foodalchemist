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
    // Spec 21 Tranche B (S5b): das einzige Rezept-Signal mit KI-Urteil im Rücken. Die
    // Zähl-Query selbst ist so deterministisch wie Tranche A — sie liest abgelegte
    // Befunde (`foodalchemist_recipe_findings`, S5a), der Egress lag im Batch. Deshalb
    // trägt der Typ dasselbe `rezept_`-Präfix: für Cockpit, Panel und Policies ist er
    // ein Rezept-Qualitätssignal wie die anderen, nur mit anderer Herkunft.
    case RezeptPlausiKi = 'rezept_plausi_ki';
    // S5b-2 — dieselbe Ablage, anderer Erzeuger: nicht die Rezeptur steht in Frage,
    // sondern die Bauart („Gericht oder Komponente?", 269er-Regel „Wie gebaut?"). Ein
    // eigener Typ und nicht ein Unterfall von `rezept_plausi_ki`, weil die Auflösung
    // eine andere ist: dort korrigiert man Zeilen, hier stellt man ein Rezept um.
    case RezeptGerichtVsKomponente = 'rezept_gericht_vs_komponente';
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
    // Spec 21 Tranche D: Foodbook-Ebene — das Kundendokument selbst (bis dahin 0 Signale).
    // Drei verschiedene Arbeitsmengen, s. DataQualityService::foodbookChecks:
    // `foodbook_kapitel_leer` misst nur BENUTZTE Bücher (ein Entwurf ist bewusst unfertig),
    // `foodbook_skizze_ungeerdet` hängt am Kapitel-Go — der Knopf IST die Grenze.
    case FoodbookKapitelLeer = 'foodbook_kapitel_leer';
    case FoodbookSkizzeUngeerdet = 'foodbook_skizze_ungeerdet';
    // S4c-2 — der Rest von Tranche D, beide gegen ein FREMDES Soll gemessen und darum
    // je mit einer eigenen dritten Arbeitsmenge: `foodbook_ziel_verfehlt` gegen die
    // Kapitel-Ziele im Planungs-Gerüst (ohne Gerüst kein Befund, wie S4b-1),
    // `foodbook_stale` gegen den freigegebenen VK-Snapshot (R2.5) — und nur an Büchern,
    // die schon draußen sind: in der Kalkulation SOLLEN Preise sich bewegen.
    case FoodbookZielVerfehlt = 'foodbook_ziel_verfehlt';
    case FoodbookStale = 'foodbook_stale';
    // L2b (Spec 03) hat den Fixer nachgeliefert — das Kapitel-Textfeld gab es im Editor
    // vorher gar nicht, und ein Signal ohne Fixer ist Rauschen (Spec 21 §9). Bewusst
    // `info`: ein Kapitel ohne Hinführung ist druckbar, nur nicht ausformuliert.
    case FoodbookKapitelOhneText = 'foodbook_kapitel_ohne_text';
    // Spec 21 Tranche E · E3: Meta-Signal über die Zeitreihe — ein Zähler ist gegenüber
    // dem Vorlauf gestiegen. Alarmiert bei *Veränderung*, nicht bei Bestand; das ist der
    // eigentliche „System im Blick"-Mechanismus.
    case QualitaetDrift = 'qualitaet_drift';
    // Trendradar: die tägliche 08:00-Automatisierung hat aus Top-Trends Konzeptvorschläge
    // erzeugt. Kein Datenmangel, sondern eine proaktive Anregung — Klasse „Info", landet in
    // derselben Inbox, damit der Vorschlag den User erreicht, auch wenn er nicht im Modul ist.
    case TrendKonzeptVorschlag = 'trend_konzept_vorschlag';

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
            self::RezeptPlausiKi => 'Rezept mit offenem KI-Befund',
            self::RezeptGerichtVsKomponente => 'Gericht oder Komponente? (Bauart-Zweifel)',
            self::KonzeptSlotLuecke => 'Konzept mit unbesetztem Pflicht-Slot',
            self::KonzeptOhneWording => 'Konzept ohne Kunden-Wording',
            self::KonzeptPreisbandVerletzt => 'Konzept außerhalb des Preisbands',
            self::KonzeptRegelVerletzt => 'Konzept verletzt eine Gerüst-Regel',
            self::KonzeptDramaturgie => 'Konzept wiederholt eine Hauptzutat',
            self::FoodbookKapitelLeer => 'Foodbook-Kapitel ohne Inhalt',
            self::FoodbookSkizzeUngeerdet => 'Kreativ-Skizze nach dem Go nicht geerdet',
            self::FoodbookZielVerfehlt => 'Foodbook verfehlt ein Kapitel-Ziel',
            self::FoodbookStale => 'Foodbook zeigt einen überholten Preis',
            self::FoodbookKapitelOhneText => 'Foodbook-Kapitel ohne Hinführung',
            self::QualitaetDrift => 'Qualität verschlechtert sich',
            self::TrendKonzeptVorschlag => 'Trend-Konzeptvorschläge',
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
            self::RezeptPlausiKi => 'heroicon-o-chat-bubble-left-right',
            self::RezeptGerichtVsKomponente => 'heroicon-o-arrows-right-left',
            self::KonzeptSlotLuecke => 'heroicon-o-squares-2x2',
            self::KonzeptOhneWording => 'heroicon-o-chat-bubble-bottom-center-text',
            self::KonzeptPreisbandVerletzt => 'heroicon-o-banknotes',
            self::KonzeptRegelVerletzt => 'heroicon-o-no-symbol',
            self::KonzeptDramaturgie => 'heroicon-o-arrow-path',
            self::FoodbookKapitelLeer => 'heroicon-o-book-open',
            self::FoodbookSkizzeUngeerdet => 'heroicon-o-sparkles',
            self::FoodbookZielVerfehlt => 'heroicon-o-flag',
            self::FoodbookStale => 'heroicon-o-clock',
            self::FoodbookKapitelOhneText => 'heroicon-o-chat-bubble-left-ellipsis',
            self::QualitaetDrift => 'heroicon-o-arrow-trending-down',
            self::TrendKonzeptVorschlag => 'heroicon-o-sparkles',
        };
    }

    /**
     * Rezept-Inhalts-Qualität (Spec 21 Tranche A + B). Abgrenzung zu den älteren
     * Kaskaden-Typen (EK/Anker/Servierform), die Geld- bzw. Erdungs-Lücken messen
     * statt der Rezeptur selbst.
     *
     * Tranche A ist durchgehend deterministisch (0-Egress); seit S5b sind mit
     * {@see istKiUrteil} zwei Typen dabei, deren Befund aus einem KI-Pass stammt.
     * Für alles, was diese Methode steuert (Ebene, Panel, Policies), ist das
     * derselbe Sachverhalt — wer die Herkunft braucht, fragt `istKiUrteil()`.
     */
    public function istRezeptQualitaet(): bool
    {
        return str_starts_with($this->value, 'rezept_');
    }

    /**
     * Spec 21 Tranche B — der Befund hinter dem Signal ist ein KI-Urteil (abgelegt in
     * `foodalchemist_recipe_findings`), kein Prädikat über Stammdaten. Relevant überall
     * dort, wo „das kann ein Fixer erledigen" gilt: hier entscheidet der Mensch je
     * Befund, deshalb führt der Weg ins Rezept-Modal statt auf einen Knopf.
     */
    public function istKiUrteil(): bool
    {
        return in_array($this, [self::RezeptPlausiKi, self::RezeptGerichtVsKomponente], true);
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

    /**
     * Spec 21 Tranche D — Qualität des Kundendokuments (Foodbook-Ebene, deterministisch).
     * Wieder eine eigene Tranche und nicht Teil von C: das Prüf-Objekt ist das Buch, und
     * es hat einen eigenen Lebenszyklus (Status + Phase + Kapitel-Go), aus dem sich DREI
     * verschiedene Arbeitsmengen ergeben — anders als bei Konzepten, wo eine reicht.
     */
    public function istFoodbookQualitaet(): bool
    {
        return str_starts_with($this->value, 'foodbook_');
    }
}
