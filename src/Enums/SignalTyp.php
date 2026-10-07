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
    // Spec 32 C4: der Ist-Wareneinsatz (Einkaufsjournal ÷ Umsatz) weicht vom theoretischen
    // (Rezeptur × verkaufte Menge) ab — anders als WareneinsatzUeberZiel eine ISTMESSUNG
    // über einen Zeitraum, keine Kalkulation je Gericht.
    case WareneinsatzIstAbweichung = 'wareneinsatz_ist_abweichung';
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
    // ABGELÖST (Spec 60 · P8): die Pairing-Dokumente gibt es nicht mehr, der Detektor ist entfernt und der
    // offene Altbestand per Migration geschlossen. Der Case bleibt, damit die geschlossenen Zeilen lesbar sind.
    case WiderspruchWissenGraph = 'widerspruch_wissen_graph';
    // Spec 60 · P8: die vier Pairing-Signale ({@see \Platform\FoodAlchemist\Services\Pairing\PairingSignale}).
    // Präfix `pairing_` statt `rezept_`: sonst zählten sie zur Rezept-Qualitäts-Ebene der Ampel.
    case PairingWissenPruefen = 'pairing_wissen_pruefen';
    case PairingWiderspruchMessung = 'pairing_widerspruch_messung';
    case PairingKonfliktImGericht = 'pairing_konflikt_im_gericht';
    case PairingWissensluecke = 'pairing_wissensluecke';
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
    // Das einzige Rezept-Signal, dessen Befund von MENSCHEN kommt, die es gekocht haben: die
    // Kueche bewertet am Wandmonitor, das Aggregat kippt, hier steht es. Kein Praedikat ueber
    // Stammdaten und kein KI-Urteil — deshalb kein Fixer-Knopf: die Aufloesung ist, das Rezept
    // zu ueberarbeiten (oder aus dem Feedback eine Iteration zu ziehen, R2.6 „weiterentwickeln").
    case RezeptFeedbackKritisch = 'rezept_feedback_kritisch';
    // Das Gegenstueck — und der einzige Typ im ganzen Katalog, der KEIN Mangel ist.
    // Begruendung (Dominique, 2026-09-05): »wir gucken uns aktuell ja nur negative an … positive
    // ja auch«. Ein System, das ausschliesslich Probleme meldet, erzieht die Kueche dazu, es als
    // Nörgler zu lesen — und ein Rezept, das am Posten wiederholt Bestnoten bekommt, ist eine
    // Handlungsempfehlung: ins Standardrepertoire, in die naechste Karte, als Vorlage.
    // Deshalb Severity Info und eine HOEHERE Huerde als beim Mangel: Lob muss verdient sein,
    // sonst flutet es die Arbeitsliste, die es zu lesen gilt.
    case RezeptFeedbackStark = 'rezept_feedback_stark';
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
    // Schicht 3 · Slice 4c-2: Konformitäts-Critic (§-genau gegen die Regelwerke). GP/LA-Konformität
    // als System-Signal (aus foodalchemist_conformance_findings, status=offen). Rezept/VK-Konformität
    // hat KEINEN eigenen Typ — sie zeigt in der Leitstelle und überlappt mit den rezept_*-Signalen.
    case KonformitaetGp = 'konformitaet_gp';
    case KonformitaetLa = 'konformitaet_la';
    // Wissens-Steuerdaten-Drift (2026-09-03). KEIN Datenmangel und kein KI-Urteil, sondern ein
    // KONFIGURATIONS-Signal: die live wirksamen Routings/Bindings weichen von dem ab, was der
    // Code als Soll führt.
    //
    // Anlass: die Steuerdaten sind per Hand editierbar (MCP, SQL), und genau das ist schon
    // passiert — die Live-Tabelle trug `regelwerk|discovery|4x8000`, wo die Migration
    // `always|1|7000` gesetzt hatte. Ein Regelwerk, das leise aus dem Prompt fällt, macht keinen
    // Fehler sichtbar: der Generator läuft weiter und liefert schlechtere Rezepte. Ohne diesen
    // Wächter ist jede Token-/Qualitäts-Arbeit an den Prompts in Wochen wieder aufgebraucht,
    // ohne dass jemand den Grund benennen kann.
    //
    // Absichtlich in KEINEM der vier `ist*()`-Bereiche: es ist keine Rezept-, Konzept- oder
    // Foodbook-Qualität, sondern Systemzustand — es gehört in die allgemeine Inbox.
    case SteuerdatenDrift = 'steuerdaten_drift';

    public function label(): string
    {
        return match ($this) {
            self::SteuerdatenDrift => 'Wissenseinstellungen der KI weichen ab',
            self::PreisAnomalie => 'Auffälliger Lieferantenpreis',
            self::PreisSprungMargeImpact => 'Preissprung mit Folgen für die Marge',
            self::VeraltetePreise => 'Veraltete Preise',
            self::MargeUnterZiel => 'Marge unter Ziel',
            self::WareneinsatzUeberZiel => 'Wareneinsatz über Ziel',
            self::WareneinsatzIstAbweichung => 'Einkauf weicht von der Rezeptur ab',
            self::DatenqualitaetGpLa => 'Lücken bei Grundprodukten',
            self::NaehrwertPlausi => 'Unplausible Nährwerte',
            self::AnkerFehlt => 'Aromaprofil fehlt',
            self::ServierformUnbestimmt => 'Servierform nicht festgelegt',
            self::EkKetteUnvollstaendig => 'Einkaufspreis unvollständig',
            self::VkAnpassungEmpfohlen => 'Veröffentlichte Preise veraltet',
            self::VertragsfristFaellig => 'Vertragsfrist fällig',
            self::WiderspruchWissenGraph => 'Widerspruch Wissen ↔ Graph (abgelöst)',
            self::PairingWissenPruefen => 'Anker-Wissen zur Prüfung',
            self::PairingWiderspruchMessung => 'Widerspruch Anker-Wissen ↔ Messung',
            self::PairingKonfliktImGericht => 'Aroma-Konflikt im Gericht',
            self::PairingWissensluecke => 'Anker ohne Wissen (viel genutzt)',
            self::SortimentsLuecke => 'Lücke im Sortiment',
            self::RezeptOhneZubereitung => 'Rezept ohne Zubereitung',
            self::RezeptMengenLuecke => 'Rezept mit Zutat ohne Menge',
            self::RezeptYieldImplausibel => 'Ausbeute fehlt oder ist unmöglich',
            self::RezeptEinZutat => 'Rezept mit nur einer Zutat',
            self::RezeptNamingRegelwerk => 'Rezeptname verstößt gegen Benennungsregeln',
            self::RezeptDublette => 'Rezept doppelt vorhanden',
            self::RezeptKategorieProblem => 'Rezept ohne gültige Kategorie',
            self::RezeptAllergenUnbelastbar => 'Allergenangabe nicht gesichert',
            self::RezeptZutatenUngemappt => 'Rezept mit Zutaten ohne Grundprodukt',
            self::RezeptSubStubOffen => 'Unterrezept noch leer',
            self::RezeptVerwaist => 'Rezept lange ungenutzt',
            self::RezeptPlausiKi => 'Rezept mit offenem KI-Hinweis',
            self::RezeptGerichtVsKomponente => 'Gericht oder Komponente unklar',
            self::RezeptFeedbackKritisch => 'Küchen-Feedback kritisch',
            self::RezeptFeedbackStark => 'Küchen-Favorit (wiederholt Bestnoten)',
            self::KonzeptSlotLuecke => 'Konzept mit unbesetzter Pflichtposition',
            self::KonzeptOhneWording => 'Konzept mit Gericht ohne Kundentext',
            self::KonzeptPreisbandVerletzt => 'Konzept außerhalb der Preisspanne',
            self::KonzeptRegelVerletzt => 'Konzept verletzt eine Kundenvorgabe',
            self::KonzeptDramaturgie => 'Konzept wiederholt eine Hauptzutat',
            self::FoodbookKapitelLeer => 'Foodbook-Kapitel ohne Inhalt',
            self::FoodbookSkizzeUngeerdet => 'Gerichtsidee im Foodbook nicht umgesetzt',
            self::FoodbookZielVerfehlt => 'Foodbook verfehlt ein Kapitel-Ziel',
            self::FoodbookStale => 'Foodbook zeigt einen überholten Preis',
            self::FoodbookKapitelOhneText => 'Foodbook-Kapitel ohne Hinführung',
            self::QualitaetDrift => 'Qualität verschlechtert sich',
            self::TrendKonzeptVorschlag => 'Konzeptvorschläge aus Trends',
            self::KonformitaetGp => 'Grundprodukt verstößt gegen Anlageregeln',
            self::KonformitaetLa => 'Lieferantenartikel verstößt gegen Anlageregeln',
        };
    }

    /** Heroicon (ohne Präfix) für die Inbox-Darstellung. */
    public function icon(): string
    {
        return match ($this) {
            self::SteuerdatenDrift => 'heroicon-o-adjustments-horizontal',
            self::PreisAnomalie => 'heroicon-o-arrow-trending-up',
            self::PreisSprungMargeImpact => 'heroicon-o-bolt',
            self::VeraltetePreise => 'heroicon-o-clock',
            self::MargeUnterZiel => 'heroicon-o-scale',
            self::WareneinsatzUeberZiel => 'heroicon-o-shopping-cart',
            self::WareneinsatzIstAbweichung => 'heroicon-o-arrows-right-left',
            self::DatenqualitaetGpLa => 'heroicon-o-exclamation-triangle',
            self::NaehrwertPlausi => 'heroicon-o-beaker',
            self::AnkerFehlt => 'heroicon-o-link-slash',
            self::ServierformUnbestimmt => 'heroicon-o-question-mark-circle',
            self::EkKetteUnvollstaendig => 'heroicon-o-currency-euro',
            self::VkAnpassungEmpfohlen => 'heroicon-o-tag',
            self::VertragsfristFaellig => 'heroicon-o-calendar-days',
            self::WiderspruchWissenGraph => 'heroicon-o-light-bulb',
            self::PairingWissenPruefen => 'heroicon-o-clipboard-document-check',
            self::PairingWiderspruchMessung => 'heroicon-o-scale',
            self::PairingKonfliktImGericht => 'heroicon-o-fire',
            self::PairingWissensluecke => 'heroicon-o-book-open',
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
            self::RezeptFeedbackKritisch => 'heroicon-o-hand-thumb-down',
            self::RezeptFeedbackStark => 'heroicon-o-hand-thumb-up',
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
            self::KonformitaetGp => 'heroicon-o-clipboard-document-check',
            self::KonformitaetLa => 'heroicon-o-clipboard-document-list',
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
