<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * M1-01 / D-1 §4: Settings-Gerüst — vertikale Sektions-Navigation, jede Sektion
 * eine eigene URL (V-17: kein Tab-State-Verlust). Die Sektionen selbst sind
 * eigenständige Livewire-Komponenten (Isolation, lazy pro Route).
 *
 * Edit-Gating verantwortet JEDE Sektion selbst — es gibt kein zentrales Gate im Gerüst.
 * Der Vertrag pro mutierender Sektion (MVP-039/041): serverseitig `TeamScope::owns()` bzw.
 * `isOwnedBy()` vor jedem Write; globale (team_id NULL) und geerbte Zeilen sind read-only.
 * `Curate::canCurate` blendet im UI die Buttons aus — es ersetzt den Server-Guard NICHT
 * (UI versteckt, Server verweigert; nie nur eins von beidem). Aufschlagsklassen war die
 * Ausnahme, die den früheren pauschalen „row-gated"-Kommentar widerlegte; jetzt geführt.
 */
class Index extends Component
{
    /**
     * Neuordnung 23.10. (Dominique: Reiter statt Seitenleiste): je GRUPPE ein Reiter. E-4 (Dominique 23.10.: „alle Tab-Inhalte
     * untereinander … eher schlimmer“): im Reiter je Unter-Bereich ein Unter-Reiter, es ist immer genau EINER gerendert. Reiter und
     * Unter-Reiter stehen in der URL (?tab=…&bereich=…, ohne bereich gilt der erste); alte Links /einstellungen/{sektion} und
     * ?bereich=<sektion> öffnen den passenden Unter-Reiter. Erweiterte Bereiche stehen als Unter-Reiter hinter einem Trenner.
     */
    #[Url(as: 'tab')]
    public string $tab = '';

    #[Url(as: 'bereich')]
    public ?string $bereich = null;

    /**
     * @var array<string, array{label: string, hint: string}>
     *
     * Reihenfolge = FA-Arbeits-Kaskade (2026-08-28, Dominique): erst die Stammdaten/Vokabulare,
     * auf die alles zugreift, dann Einkauf→Kalkulation→Preise, dann KI & Kreativ-Steuerung, dann
     * Wissen, dann Produktion, zuletzt Ausgabe/Betrieb. Bis 2026-10-05 bewusst ohne UI-Gruppen
     * (mehrere Sektionen gehören in zwei Töpfe zugleich). fa-pass 2026-10-05: die Navigation
     * gruppiert jetzt über GRUPPEN (unten) — jede Sektion steht in GENAU einer Gruppe, der Filter
     * über der Liste sucht weiter über Label UND Hint, damit „falsch einsortiert" nie „nicht
     * gefunden" heißt. `einheiten` bleibt zuerst → mount()-Default unverändert.
     */
    public const SEKTIONEN = [
        // Neuordnung 23.10. (E-2): Übersicht mit Kacheln und Status ist der erste Reiter (vorher Einstieg „Einheiten“, das ein Mandant nicht ändern darf).
        'start' => ['label' => 'Übersicht', 'hint' => 'Was eingerichtet ist und was noch fehlt, je Reiter'],
        // — Stammdaten & Vokabulare —
        // fa-pass 2026-10-05: Beschriftungen in Küchensprache (ohne Spec-/Ticket-Kürzel). Der
        // fachliche Hintergrund je Sektion steht in den Kommentaren, nicht mehr im sichtbaren Text.
        'einheiten' => ['label' => 'Einheiten', 'hint' => 'Gramm und Milliliter je Einheit, Stückgewichte für die Umrechnung'],
        'warengruppen' => ['label' => 'Warengruppen', 'hint' => 'Gruppen der Grundprodukte ordnen, Unterkategorien pflegen'],
        'taxonomie' => ['label' => 'Rezept-Kategorien', 'hint' => 'Hauptgruppen und Kategorien der Basisrezepte'],
        'vk-taxonomie' => ['label' => 'Gerichte-Kategorien', 'hint' => 'Speisen-Hauptgruppen und Diätformen der Gerichte'],
        'behaelter' => ['label' => 'Behälter & Geräte', 'hint' => 'Behälter mit Maßen, Regenerationsgeräte, Servier-Vehikel, Koch-Equipment'],
        // Konzept-Taxonomie (Kategorie/Klasse) ausgemustert 2026-07-25 (Dominique): Concept-Picker filtern
        // jetzt auf die Concepter-Dimensionen. Komponente/Route/DB bleiben (nicht-destruktiv), nur aus dem Nav raus.
        'concepter-dimensionen' => ['label' => 'Konzept-Merkmale', 'hint' => 'Einsatzmoment, Eventtyp, Saison, Servierform und Zielgruppe für Konzepte'],

        // — Einkauf, Kalkulation & Preise —
        'einkauf' => ['label' => 'Einkauf & Lieferanten', 'hint' => 'Welcher Artikel führt, Stammlieferanten je Warengruppe, Lagerorte'],
        'kalkulation' => ['label' => 'Kalkulation', 'hint' => 'Gar- und Putzverlust, Mehrwertsteuer, Rundung'],
        // #502 (2026-07-13): Regel-Cockpit zurück unter Einstellungen (Werkstatt aufgelöst) —
        //   Zuschläge, Fixkosten, Stundensatz, Marge. MwSt-Defaults liegen unter 'kalkulation'.
        'herstellkosten' => ['label' => 'Herstellkosten & Zuschläge', 'hint' => 'Zuschlagsschema, Fixkosten, Stundensatz und Marge bis zum Verkaufspreis'],
        // R5 (Dominique): eigene Seiten statt Sammel-Sektion — mit Anlegen/Bearbeiten
        'aufschlagsklassen' => ['label' => 'Preisklassen', 'hint' => 'Faktoren auf den Basissatz für die Preisstufen'],

        // — KI & Kreativ-Steuerung (speist die KI-Generierung) —
        // Test-Anker: der Hint nennt den Sprachbefehl wörtlich (EinstellungenSchirmTest).
        'ki' => ['label' => 'KI', 'hint' => 'Anbieter, Modellstufen, Verbrauch, Notschalter, Sprachbefehl (Agenten-Modus, dauerhaft aktiv, Vorlesen)'],
        'teamprofil' => ['label' => 'Teamprofil', 'hint' => 'Was die Küche kann: Geräte (vorhanden, fehlt, unbekannt), Flächen, Gäste parallel, Convenience-Grad'],
        'kueche' => ['label' => 'Küchen-Profil', 'hint' => 'Küchentyp als Grundrichtung für Vorschläge, Farben je Positionstyp'],
        // Ebene 1 der DNA-Kette (Umzug 2026-07-21): Team-Food-DNA wohnt bei den Einstellungen, nicht als Top-Level-Nav
        'food-dna' => ['label' => 'Food DNA', 'hint' => 'Leitbild, Stil, Aromatik und No-Gos des eigenen Hauses'],
        // Spec 82 §7 (Schritt 6): pflegbare Zusatzfelder der DNA-Canvases, Oberteam-eigen.
        'dna-felder' => ['label' => 'DNA-Zusatzfelder', 'hint' => 'Eigene Felder an Team-DNA, Kunden-DNA, Angebot, Foodbook und Concept, Feldgruppen als Reiter'],
        // Spec 42 F3: Kunde-DNA (Ebene 2) zog aus dem Foodbook hierher — Marken-DNA gehört zum Kunden, nicht pro Foodbook.
        'kunde-dna' => ['label' => 'Kunden-DNA', 'hint' => 'Marke, Ton und No-Gos je Kunde'],
        'schreibstile' => ['label' => 'Schreibstile', 'hint' => 'Tonfall für Karten- und Foodbook-Texte anlegen und pflegen'],
        'brief-vorlagen' => ['label' => 'Schnellstart-Vorlagen', 'hint' => 'Vorlagen für neue Planungen verwalten'],
        'trendradar' => ['label' => 'Trendradar', 'hint' => 'Tägliche Konzeptideen aus Trends an- und abschalten, Trends einlesen'],

        // — Wissen (#469: Vokabular, das die KI mit Wissen füttert) —
        'wissenskategorien' => ['label' => 'Wissens-Kategorien', 'hint' => 'Ordnung der Wissensbasis'],
        // `einsatzorte` (#469) ist mit Spec 52 · F3 aus der Navigation GENOMMEN: die Seite
        // pflegt das Ziel-Vokabular der Bindungen, und Bindungen wirken nicht mehr. Sie
        // bleibt als Komponente bestehen, solange der Wissens-Browser die Labels der
        // Alt-Bindungen daraus auflöst; ihr Eintrag hier wäre eine Einladung, ein totes
        // Vokabular zu pflegen. Verschwindet mit dem Tabellen-Drop.
        // Steuern statt binden: Sektion `wissenssteuerung`.
        // Spec 52 (Grundsatz E): der Kanon — die GEWINNENDE Ebene — hatte keine Oberflaeche,
        // die Routings seit `docs/wissen.md` nur einen „Ausblick"-Eintrag. Wer hier kuratierte,
        // pflegte damit ausschliesslich den Fallback.
        'wissenssteuerung' => ['label' => 'Wissens-Steuerung', 'hint' => 'Welches Wissen bei welchem KI-Schritt ankommt'],
        // Spec 81: Regeln als Daten — mechanische Regelwerk-Regeln, die der Code durchsetzt (statt der KI-Prüfung).
        'regeln' => ['label' => 'Regeln', 'hint' => 'Regelwerk-Regeln, die der Code beim Anlegen, Prüfen und Matching durchsetzt: Vokabular, Verbote, Zuordnungen, Schwellen'],

        // — Produktion & Kapazität —
        // Spec 30 E3: Arbeitsplätze mit optionaler Tageskapazität — bewusst getrennt vom
        // Koch-Equipment (das sagt „was braucht ein Rezept", der Posten „wo wird gearbeitet").
        'posten' => ['label' => 'Posten & Kapazität', 'hint' => 'Arbeitsplätze der Küche, verplanbare Minuten je Tag, Besetzung'],
        // Stufe 3 P3.1: Rollen als Kostenträger (Küchenchef/Koch/Hilfskoch) — Satz je Rolle.
        // Rolle ≠ Mensch: keine Namen/Schichten. Posten-Besetzung leitet Kapazität + Kosten ab.
        // Spec 61/75: was die Plattform-Rollen im FA dürfen + Häkchen „darf Rechnungen freigeben" — Rechte, nicht Kosten.
        'zugriffsrechte' => ['label' => 'Zugriffsrechte', 'hint' => 'Was Mitglieder und Betrachter dürfen, wer Rechnungen freigibt'],
        'rollen' => ['label' => 'Küchenrollen & Stundensätze', 'hint' => 'Küchenrollen mit Stundensatz für Kapazität und Produktionskosten'],

        // — Ausgabe & Betrieb —
        // Spec 43: visueller Struktur-Builder für Präsentations-Designs (Block-Palette · Live-Vorschau · Tokens)
        'praesentations-designs' => ['label' => 'Präsentations-Designs', 'hint' => 'Aufbau, Farben und Schrift des digitalen Kundenbuchs'],
        // Spec 33 P2: Die Tabelle gab es seit Spec 19, die Pflege nie — deshalb war sie leer
        // und `outlet_id` an der Speisekarte hatte nicht einmal ein Eingabefeld.
        // Spec 82 §1: „Betrieb" entfällt als Begriff, „Outlet" heißt in der Beschriftung Kalkulationsprofil.
        // Schlüssel `betriebe` bleibt (URLs, Sperr-Ziel, Code) — nur die Anzeige ändert sich.
        'betriebe' => ['label' => 'Kalkulationsprofile', 'hint' => 'Rechenbrille je Ausgabestelle (Marge, Zuschläge), Vorlage und Logo; Zuordnung zu den Unter-Teams'],
        // Spec 82 §5: der frühere Controlling-Reiter „Standorte & Freigaben" — Freigaben, Sammlungen, Schnellstart-Haken je Unter-Team.
        'unter-teams' => ['label' => 'Unter-Teams & Freigaben', 'hint' => 'Was die Unter-Teams vom Oberteam sehen: Haken „übernimmt alles", Freigaben, Sammlungen'],
        // Spec 82 §13 D/C1: was der Mandant im Monat zahlt (Inhaber/Admin des Oberteams; lesend, nur Gebühren und Mengen).
        'plan-nutzung' => ['label' => 'Plan und Nutzung', 'hint' => 'Was im Monat berechnet wird: Basis, weitere User, Unter-Teams, Stamm-Paket, Abo'],
        // Spec 82 §9/§13 C1: Mandanten-Stammblatt für Plattform-Admin und Master-Admin (lesend, nur Verwaltungsspalten, nie Inhalte).
        'mandanten' => ['label' => 'Mandanten', 'hint' => 'Stammblatt je Mandant: Status, Modus, CRM-Firma, Bereiche, Stamm-Paket, Abos, Kontingente, Gebühren'],
        // Spec 59: zentraler Chip-Katalog für Speiseplan-Vorgaben („mind. 2× vegan je Woche“) —
        // die Vorgaben selbst stehen je Plan im Speiseplan-Editor (Reiter Stammdaten).
        'etiketten' => ['label' => 'Etiketten', 'hint' => 'Etikett-Vorlagen: Format, Felder, Datum vorbelegt oder zum Handschreiben, Logo und Design; Hinweis „mit Jodsalz hergestellt“'],
        // Spec 85 D-7 PR 2: Team-Abweichungen von übersteuerbaren Regeln (Default-GP, Kleinstmengen, HACCP-Temperaturen) — FA-Admin des Teams.
        'hausstandards' => ['label' => 'Hausstandards', 'hint' => 'Wo euer Haus vom Plattform-Standard abweicht: Standard-Grundprodukte, Kleinstmengen, Hygiene-Temperaturen (lockerer nur bis zur belegten Untergrenze)'],
        'speiseplan-chips' => ['label' => 'Speiseplan-Chips', 'hint' => 'Prüf-Chips für Speiseplan-Vorgaben: Ernährungsform oder Hauptgruppe, Standardwerte mindestens und höchstens'],
    ];

    /**
     * Gruppen der Navigation nach AUFGABE (Neuordnung 23.10., Dominique: 00_INBOX/_Einstellungen_Neuordnung_Vorschlag.md). Jede Sektion
     * steht in GENAU einer Gruppe; was fehlt, rendert die View unter „Weitere" (nie verschluckt). „Plattform" ist nur für Master-Team
     * und Plattform-Admin sichtbar (die Sektionen darin tragen ihre eigene Sperre: NUR_MASTER, NUR_VERWALTUNG, NUR_PLATTFORM_ADMIN).
     *
     * @var array<string, array{label: string, sektionen: list<string>}>
     */
    public const GRUPPEN = [
        'uebersicht' => ['label' => 'Übersicht', 'sektionen' => ['start']],
        'team' => ['label' => 'Team & Zugriff', 'sektionen' => ['zugriffsrechte', 'unter-teams', 'plan-nutzung']],
        'kueche' => ['label' => 'Küche & Produktion', 'sektionen' => ['teamprofil', 'kueche', 'posten', 'rollen', 'behaelter', 'hausstandards']],
        'einkauf' => ['label' => 'Einkauf & Lager', 'sektionen' => ['einkauf']],
        'kalkulation' => ['label' => 'Kalkulation & Preise', 'sektionen' => ['kalkulation', 'herstellkosten', 'aufschlagsklassen', 'betriebe']],
        'stil' => ['label' => 'Küchenstil & Ausgabe', 'sektionen' => ['food-dna', 'dna-felder', 'kunde-dna', 'schreibstile', 'brief-vorlagen', 'praesentations-designs', 'etiketten', 'speiseplan-chips', 'trendradar']],
        'kategorien' => ['label' => 'Kategorien', 'sektionen' => ['taxonomie', 'vk-taxonomie', 'concepter-dimensionen', 'wissenskategorien', 'einheiten', 'warengruppen']],
        'ki' => ['label' => 'KI', 'sektionen' => ['ki']],
        'plattform' => ['label' => 'Plattform', 'sektionen' => ['mandanten', 'wissenssteuerung', 'regeln']],
    ];

    /**
     * Neuordnung 23.10.: Grundeinstellungen stehen im Reiter offen; alle übrigen im aufklappbaren Block „Erweitert" des Reiters
     * (Suche und Direktlink öffnen sie immer). Liste von Dominique bestätigt.
     *
     * @var list<string>
     */
    public const GRUND = ['start', 'zugriffsrechte', 'unter-teams', 'teamprofil', 'posten', 'etiketten', 'einkauf', 'kalkulation', 'herstellkosten', 'betriebe', 'food-dna', 'ki'];

    /**
     * E-4: Sektionen, deren große Blöcke je einen eigenen Unter-Reiter bekommen (Dominique 23.10., Gliederung
     * 00_INBOX/_Einstellungen_E4_Gliederung.md). Schlüssel = Unter-Reiter in der URL, an die Komponente geht er als `abschnitt`.
     * Die Schlüssel dürfen keine Sektion benennen (sonst wäre ?bereich= mehrdeutig).
     *
     * @var array<string, array<string, array{label: string, hint: string, stichworte: string}>>
     */
    public const ABSCHNITTE = [
        'teamprofil' => [
            'kennzahlen' => ['label' => 'Kennzahlen', 'hint' => 'Gäste parallel zum Tagesgeschäft, Convenience-Grad, Besonderheiten', 'stichworte' => 'Teamprofil Gäste Convenience Kennzahlen Wege Engpass'],
            'ausstattung' => ['label' => 'Ausstattung', 'hint' => 'Je Gerät: vorhanden, fehlt oder unbekannt', 'stichworte' => 'Teamprofil Geräte Equipment Kombidämpfer Pacojet'],
            'flaechen' => ['label' => 'Flächen', 'hint' => 'Räume und Flächen mit Gästegrenze und Saison', 'stichworte' => 'Teamprofil Fläche Terrasse Saal Rooftop'],
        ],
        'einkauf' => [
            'leitartikel' => ['label' => 'Leitartikel & Lieferanten', 'hint' => 'Welcher Artikel kalkuliert, Lieferanten-Reihenfolge, Stamm-Lieferanten je Warengruppe', 'stichworte' => 'Leitartikel Lead Bezugsquelle Favoriten Stamm-Lieferant Lieferant Strategie'],
            'bestellversand' => ['label' => 'Bestellversand', 'hint' => 'Wie eine Bestellung beim Absenden zum Lieferanten kommt', 'stichworte' => 'Bestellversand Mail Absender Signatur Bestellung'],
            'lagerorte' => ['label' => 'Lagerorte', 'hint' => 'Standardlager und weitere Lagerorte für Umlagerung, Inventur und Produktion', 'stichworte' => 'Lagerort Lager Inventur Umlagerung'],
        ],
        'kalkulation' => [
            'verluste' => ['label' => 'Verluste', 'hint' => 'Gar- und Putzverlust je Warengruppe', 'stichworte' => 'Garverlust Putzverlust Verlust'],
            'mwst-rundung' => ['label' => 'MwSt & Rundung', 'hint' => 'Steuersätze und Rundung der Verkaufspreise', 'stichworte' => 'MwSt Mehrwertsteuer Rundung Nachkommastellen'],
        ],
        'herstellkosten' => [
            'zuschlaege' => ['label' => 'Zuschläge', 'hint' => 'Zuschlagsschema, Marge, Lohnnebenkosten und Lohnquelle', 'stichworte' => 'Zuschlag Stundensatz Marge Gemeinkosten Lohn Wareneinsatz'],
            'fixkosten' => ['label' => 'Fixkosten', 'hint' => 'Fixkosten je Monat und Bezugsbasis für die automatischen Zuschläge', 'stichworte' => 'Fixkosten Bezugsbasis Miete Leasing'],
        ],
        'ki' => [
            'anbieter' => ['label' => 'Anbieter & Notschalter', 'hint' => 'KI für dieses Team an- und abschalten', 'stichworte' => 'KI Notschalter Kill-Switch'],
            'modellstufen' => ['label' => 'Modellstufen', 'hint' => 'Welche Modellstufe welcher Arbeitsschritt nutzt', 'stichworte' => 'KI Modell Modellstufen'],
            'sprachbefehl' => ['label' => 'Sprachbefehl', 'hint' => 'Agenten-Modus, dauerhaft aktiv, Vorlesen und Stimme', 'stichworte' => 'KI Sprachbefehl Agenten-Modus Vorlesen Stimme'],
            'nutzung' => ['label' => 'Nutzung & Kosten', 'hint' => 'KI-Aufrufe und Kosten im Zeitraum, Abrechnung je Mandant', 'stichworte' => 'KI Kosten Verbrauch Abrechnung'],
        ],
    ];

    /**
     * Zusätzliche Suchwörter je Sektion (Unterabschnitte, Fachwörter), damit die Suche „MwSt", „Drucker" oder „Lagerort" findet.
     *
     * @var array<string, string>
     */
    public const STICHWORTE = [
        'einheiten' => 'Stückgewicht Umrechnung Gramm Milliliter',
        'warengruppen' => 'Unterkategorie WG',
        'taxonomie' => 'Basisrezept Hauptgruppe',
        'vk-taxonomie' => 'Diätform vegan vegetarisch Speisen',
        'behaelter' => 'GN Gastronorm Regeneration Kombidämpfer Vehikel Koch-Equipment Geräte',
        'concepter-dimensionen' => 'Eventtyp Saison Zielgruppe Servierform Einsatzmoment',
        'einkauf' => 'Leitartikel Lead Bezugsquelle Favoriten Bestellversand Lagerort Lager Stamm-Lieferant Lieferant',
        'kalkulation' => 'MwSt Mehrwertsteuer Rundung Garverlust Putzverlust',
        'herstellkosten' => 'Zuschlag Fixkosten Stundensatz Marge Gemeinkosten',
        'aufschlagsklassen' => 'Preisstufe Basissatz Faktor Aufschlag',
        'ki' => 'Notschalter Kill-Switch Kosten Verbrauch Modell Modellstufen Sprachbefehl Vorlesen Stimme',
        'teamprofil' => 'Ausstattung Geräte Flächen Gäste Convenience Kennzahlen',
        'kueche' => 'Küchentyp Farben Positionstyp',
        'food-dna' => 'Leitbild Stil Aromatik No-Go DNA',
        'dna-felder' => 'Zusatzfeld Canvas Feldgruppe',
        'kunde-dna' => 'Kunde Marke Ton',
        'schreibstile' => 'Tonfall Text Karte Foodbook',
        'brief-vorlagen' => 'Vorlage Planung Brief Schnellstart',
        'trendradar' => 'Google Trends DataForSEO Trend',
        'wissenskategorien' => 'Wissen Dossier Kategorie',
        'wissenssteuerung' => 'Wissen Kanon Routing Budget Leitplanken',
        'regeln' => 'Regelwerk Verbote Schwellen Vokabular',
        'posten' => 'Arbeitsplatz Kapazität Minuten Besetzung Topf Deckel',
        'zugriffsrechte' => 'Rechte Rolle Mitglieder Betrachter Rechnungsfreigabe Support Bereiche Kontingente',
        'rollen' => 'Küchenchef Koch Hilfskoch Stundensatz Kostenträger',
        'praesentations-designs' => 'Design Farben Schrift Kundenbuch CSS Branding',
        'betriebe' => 'Kalkulationsprofil Outlet Ausgabestelle Marge Logo Vorlage Standort',
        'unter-teams' => 'Standort Freigabe Sammlung Unter-Team',
        'plan-nutzung' => 'Gebühren Abrechnung Monat Abo Stamm-Paket',
        'mandanten' => 'Mandant Stammblatt CRM Kontingent Gebühren',
        'etiketten' => 'Etikett Drucker Label Jodsalz Format Felder',
        'hausstandards' => 'HACCP Temperatur Kleinstmenge Standard-Grundprodukt Abweichung',
        'speiseplan-chips' => 'Chip Vorgabe Speiseplan Ernährungsform',
    ];

    /** Neuordnung 23.10.: Plattform-Wissen — nur Master-Team und Plattform-Admin (Mandanten sahen bisher nur den „vorbehalten"-Hinweis). */
    public const NUR_MASTER = ['wissenssteuerung'];

    /** Sektionen nur für Plattform-Admins (Spec 81: Regeln wie das globale Wissen — Kunden sehen sie nicht). */
    public const NUR_PLATTFORM_ADMIN = ['regeln'];

    /** Spec 82 §9/§13 C1: Sektionen nur für Plattform-Admin und Admin im Master-Team (Verwaltungsdaten aller Mandanten). */
    public const NUR_VERWALTUNG = ['mandanten'];

    /** Spec 82 §5: nur im Oberteam (ohne Eltern-Team) — ein Unter-Team hat keine Unter-Teams. */
    public const NUR_OBERTEAM = ['unter-teams', 'dna-felder', 'plan-nutzung', 'mandanten'];

    /**
     * Spec 82 §5/§13 B2: Sektionen, die an einem Bereich der Freischaltung (FaBereiche) hängen. Aus = Sektion
     * unsichtbar und der Direktlink gesperrt. Plattform-Admins sehen sie wie bei jedem Bereich immer (darfBereich).
     */
    public const BEREICH_SEKTIONEN = ['unter-teams' => 'unterteams'];

    /**
     * Spec 82 §13 C1/D: Sektionen mit eigener Rechteprüfung. `plan-nutzung`: Inhaber/Admin des Mandanten, Plattform-Admin, Admin im
     * Master-Team (nie im Master-Team selbst). `mandanten`: nur Plattform-Admin und Admin im Master-Team.
     */
    public static function rechteErlaubt(string $sektion): bool
    {
        $user = Auth::user();
        $team = $user?->currentTeamRelation;

        return match ($sektion) {
            'plan-nutzung' => $team !== null && app(\Platform\FoodAlchemist\Services\PlanNutzungService::class)->darfLesen($user, $team),
            'mandanten' => app(\Platform\FoodAlchemist\Services\MandantenService::class)->darfVerwaltung($user),
            default => true,
        };
    }

    public function mount(?string $sektion = null): void
    {
        $sichtbar = self::sichtbareSektionen();
        if ($sektion !== null) {
            // alter Link /einstellungen/{sektion}: unbekannt oder nicht erlaubt = 404 wie bisher, sonst Reiter + Sprung zum Bereich
            abort_unless(array_key_exists($sektion, self::SEKTIONEN) && isset($sichtbar[$sektion]), 404);
            $this->bereich = $sektion;   // normalisieren() setzt Reiter und Unter-Reiter (geteilte Sektion → erster Abschnitt)
        }
        $this->normalisieren($sichtbar);
    }

    /** E-4: Unter-Reiter wechseln. Die Sperre gilt je Reiter und bleibt stehen (kein Freigeben wie bei tabWaehlen). */
    public function bereichWaehlen(string $key): void
    {
        $unter = self::unterReiter($this->tab, self::sichtbareSektionen());
        if (isset($unter['grund'][$key]) || isset($unter['erweitert'][$key])) {
            $this->bereich = $key;
        }
    }

    /** Reiter wechseln (Leiste im Server-Modus): nur sichtbare Reiter, Sprungziel fällt weg. Eigene Sperren des alten Reiters gehen frei. */
    public function tabWaehlen(string $tab): void
    {
        $this->reiterFreigeben();
        $this->tab = $tab;
        $this->bereich = null;
        $this->normalisieren(self::sichtbareSektionen());
    }

    // ── E-3: EIN Bearbeiten je Reiter (Dominique 23.10.) ─────────────────────────────────────────────────────
    // Der Reiter holt, verlängert und gibt die Sperren ALLER seiner Bereiche frei (dieselben Ziele settings.<bereich> wie die
    // Bereiche selbst, BearbeitungssperreService). Die Bereiche zeichnen sich danach neu (Ereignis settings-reiter-geaendert) und
    // lesen den Zustand aus dem Dienst; eine fremde Sperre zeigt der Bereich weiter selbst an.

    public function reiterBearbeiten(): void
    {
        $user = Auth::user();
        $teamId = $user?->currentTeamRelation?->id;
        if ($teamId === null || ! self::darfAendern() || ! (bool) config('foodalchemist.bearbeitungssperre', true)) {
            return;
        }
        $svc = app(\Platform\FoodAlchemist\Services\BearbeitungssperreService::class);
        $belegt = [];
        foreach ($this->reiterSperrziele() as $key => $bereich) {
            $r = $svc->sperren('settings.' . $bereich, (int) $teamId, (int) $user->id, $user->name ?? null, (int) $teamId);
            if (! $r['ok']) {
                $belegt[] = (self::SEKTIONEN[$key]['label'] ?? $key) . ' (' . ($r['inhaber']['name'] ?? 'jemand anderes') . ')';
            }
        }
        if ($belegt !== []) {
            $this->dispatch('fa-saved', message: 'Gerade in Bearbeitung: ' . implode(', ', $belegt) . '. Die übrigen Bereiche kannst du ändern.', type: 'error');
        }
        $this->dispatch('settings-reiter-geaendert');
    }

    public function reiterFertig(): void
    {
        $this->reiterFreigeben();
        $this->dispatch('settings-reiter-geaendert');
    }

    /** Tippen/Klicken im Reiter verlängert alle eigenen Sperren (höchstens einmal je Minute, siehe View). */
    public function reiterHerzschlag(): void
    {
        $uid = Auth::id();
        $teamId = Auth::user()?->currentTeamRelation?->id;
        if ($uid === null || $teamId === null) {
            return;
        }
        $svc = app(\Platform\FoodAlchemist\Services\BearbeitungssperreService::class);
        foreach ($this->reiterSperrziele() as $bereich) {
            $svc->verlaengern('settings.' . $bereich, (int) $teamId, (int) $uid);
        }
    }

    private function reiterFreigeben(): void
    {
        $uid = Auth::id();
        $teamId = Auth::user()?->currentTeamRelation?->id;
        if ($uid === null || $teamId === null || $this->tab === '') {
            return;
        }
        $svc = app(\Platform\FoodAlchemist\Services\BearbeitungssperreService::class);
        foreach ($this->reiterSperrziele() as $bereich) {
            if ($svc->haelt('settings.' . $bereich, (int) $teamId, (int) $uid)) {
                $svc->freigeben('settings.' . $bereich, (int) $teamId, (int) $uid);
            }
        }
    }

    /**
     * Sperrbare Bereiche des aktiven Reiters: Sektion ⇒ Sperr-Bereich der Komponente (sperrBereich(), z. B. brief-vorlagen ⇒ brief_vorlagen).
     *
     * @return array<string, string>
     */
    private function reiterSperrziele(): array
    {
        $sichtbar = self::sichtbareSektionen();
        $out = [];
        foreach (self::sichtbareGruppen($sichtbar)[$this->tab]['sektionen'] ?? [] as $key) {
            $klasse = self::komponente($key);
            if ($klasse !== null && self::mitSperre($klasse)) {
                $m = new \ReflectionMethod($klasse, 'sperrBereich');
                $m->setAccessible(true);
                $out[$key] = (string) $m->invoke(new $klasse);
            }
        }

        return $out;
    }

    /** @return class-string|null Livewire-Klasse einer Sektion (Schlüssel kebab ⇒ Klasse Studly). */
    public static function komponente(string $sektion): ?string
    {
        $klasse = __NAMESPACE__ . '\\' . \Illuminate\Support\Str::studly($sektion);

        return class_exists($klasse) ? $klasse : null;
    }

    public static function mitSperre(string $klasse): bool
    {
        return in_array(\Platform\FoodAlchemist\Livewire\Settings\Concerns\MitEinstellungsSperre::class, class_uses_recursive($klasse), true);
    }

    /** @return array{modus: string, fremd: list<string>} modus: aus (nichts zu sperren/keine Rechte) · lesen · bearbeiten (mind. ein Bereich meins) */
    private function reiterZustand(): array
    {
        $ziele = $this->reiterSperrziele();
        $uid = Auth::id();
        $teamId = Auth::user()?->currentTeamRelation?->id;
        if ($ziele === [] || $uid === null || $teamId === null || ! self::darfAendern()
            || ! (bool) config('foodalchemist.bearbeitungssperre', true)) {
            return ['modus' => 'aus', 'fremd' => []];
        }
        $svc = app(\Platform\FoodAlchemist\Services\BearbeitungssperreService::class);
        $meins = false;
        $fremd = [];
        foreach ($ziele as $key => $bereich) {
            if ($svc->haelt('settings.' . $bereich, (int) $teamId, (int) $uid)) {
                $meins = true;
            } elseif (($f = $svc->fremd('settings.' . $bereich, (int) $teamId, (int) $uid)) !== null) {
                $fremd[] = (self::SEKTIONEN[$key]['label'] ?? $key) . ': ' . ($f['name'] ?? 'jemand anderes');
            }
        }

        return ['modus' => $meins ? 'bearbeiten' : 'lesen', 'fremd' => $fremd];
    }

    /** Ungültiger oder nicht sichtbarer Reiter/Unter-Reiter (auch aus einer geteilten URL) → erster sichtbarer Reiter, erster Unter-Reiter. */
    private function normalisieren(array $sichtbar): void
    {
        if ($this->bereich !== null) {
            $ziel = self::unterReiterZiel($this->bereich, $sichtbar);
            [$this->tab, $this->bereich] = $ziel ?? [$this->tab, null];
        }
        $gruppen = self::sichtbareGruppen($sichtbar);
        if (! isset($gruppen[$this->tab])) {
            $this->tab = (string) (array_key_first($gruppen) ?? '');
            $this->bereich = null;
        }
    }

    /**
     * E-4: Reiter und Unter-Reiter zu einem Schlüssel aus der URL: Sektion (geteilt → ihr erster Abschnitt) oder Abschnitt.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function unterReiterZiel(string $key, ?array $sichtbar = null): ?array
    {
        $sichtbar ??= self::sichtbareSektionen();
        if (isset($sichtbar[$key])) {
            return [self::gruppeVon($key), isset(self::ABSCHNITTE[$key]) ? (string) array_key_first(self::ABSCHNITTE[$key]) : $key];
        }
        foreach (self::ABSCHNITTE as $sektion => $abschnitte) {
            if (isset($abschnitte[$key]) && isset($sichtbar[$sektion])) {
                return [self::gruppeVon($sektion), $key];
            }
        }

        return null;
    }

    /**
     * E-4: Unter-Reiter eines Reiters, Grundeinstellungen zuerst, erweiterte hinter dem Trenner (Reiter ohne Grundeinstellung: alle vorn).
     * Eintrag: Sektion, Abschnitt (null = ganze Sektion), Beschriftung, Zweck, Suchwörter.
     *
     * @return array{grund: array<string, array{sektion: string, abschnitt: ?string, label: string, hint: string, stichworte: string}>, erweitert: array<string, array{sektion: string, abschnitt: ?string, label: string, hint: string, stichworte: string}>}
     */
    public static function unterReiter(string $tab, ?array $sichtbar = null): array
    {
        $sichtbar ??= self::sichtbareSektionen();
        $out = ['grund' => [], 'erweitert' => []];
        foreach (self::sichtbareGruppen($sichtbar)[$tab]['sektionen'] ?? [] as $sektion) {
            $teil = in_array($sektion, self::GRUND, true) ? 'grund' : 'erweitert';
            foreach (self::ABSCHNITTE[$sektion] ?? [$sektion => null] as $key => $abschnitt) {
                $out[$teil][$key] = $abschnitt === null
                    ? ['sektion' => $sektion, 'abschnitt' => null, 'label' => $sichtbar[$sektion]['label'], 'hint' => $sichtbar[$sektion]['hint'], 'stichworte' => self::STICHWORTE[$sektion] ?? '']
                    : ['sektion' => $sektion, 'abschnitt' => $key] + $abschnitt;
            }
        }
        if ($out['grund'] === []) {   // Reiter ganz ohne Grundeinstellung (z. B. Kategorien): alle gleichrangig, kein Trenner
            $out = ['grund' => $out['erweitert'], 'erweitert' => []];
        }

        return $out;
    }

    public static function gruppeVon(string $sektion): string
    {
        foreach (self::GRUPPEN as $key => $g) {
            if (in_array($sektion, $g['sektionen'], true)) {
                return $key;
            }
        }

        return '';
    }

    /**
     * Die Sektionen, die der angemeldete Benutzer in seinem Team sieht (Reiter, Bereiche und Suche aus EINER Quelle).
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function sichtbareSektionen(): array
    {
        $sektionen = self::istPlattformAdmin() ? self::SEKTIONEN : array_diff_key(self::SEKTIONEN, array_flip(self::NUR_PLATTFORM_ADMIN));
        if (self::istUnterTeam()) {
            $sektionen = array_diff_key($sektionen, array_flip(self::NUR_OBERTEAM));
        }
        if (! self::istMasterOderPlattformAdmin()) {
            $sektionen = array_diff_key($sektionen, array_flip(self::NUR_MASTER));
        }

        return array_filter($sektionen, static fn (string $key) => self::bereichErlaubt($key) && self::rechteErlaubt($key), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, array{label: string, sektionen: list<string>}> nur Gruppen mit mindestens einer sichtbaren Sektion */
    public static function sichtbareGruppen(?array $sichtbar = null): array
    {
        $sichtbar ??= self::sichtbareSektionen();
        $out = [];
        foreach (self::GRUPPEN as $key => $g) {
            $drin = array_values(array_filter($g['sektionen'], fn ($k) => isset($sichtbar[$k])));
            if ($drin !== []) {
                $out[$key] = ['label' => $g['label'], 'sektionen' => $drin];
            }
        }

        return $out;
    }

    /** Hängt die Sektion an einem Bereich, muss er für Team und Benutzer freigeschaltet sein. */
    public static function bereichErlaubt(string $sektion): bool
    {
        $bereich = self::BEREICH_SEKTIONEN[$sektion] ?? null;
        if ($bereich === null) {
            return true;
        }
        $user = Auth::user();
        $team = $user?->currentTeamRelation;

        return $team === null || app(\Platform\FoodAlchemist\Services\FaRechte::class)->darfBereich($user, $team, $bereich);
    }

    private static function istUnterTeam(): bool
    {
        return Auth::user()?->currentTeamRelation?->parent_team_id !== null;
    }

    private static function istMasterOderPlattformAdmin(): bool
    {
        $team = Auth::user()?->currentTeamRelation;

        return self::istPlattformAdmin() || ($team !== null && \Platform\FoodAlchemist\Support\MasterTeam::istMaster($team));
    }

    /** Darf der angemeldete Benutzer in diesem Team Einstellungen ändern (FA-Admin)? Sonst zeigt die Seite einen Hinweis. */
    private static function darfAendern(): bool
    {
        $user = Auth::user();
        $team = $user?->currentTeamRelation;

        return $user !== null && $team !== null
            && app(\Platform\FoodAlchemist\Services\FaRechte::class)->darf($user, $team, \Platform\FoodAlchemist\Enums\FaRolle::Admin);
    }

    private static function istPlattformAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null && ! $user->isAiUser() && app(\Platform\FoodAlchemist\Services\FaRechte::class)->istPlattformAdmin($user);
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $sektionen = self::sichtbareSektionen();
        $gruppen = self::sichtbareGruppen($sektionen);
        $imReiter = $gruppen[$this->tab]['sektionen'] ?? [];
        $unter = self::unterReiter($this->tab, $sektionen);
        $alleUnter = $unter['grund'] + $unter['erweitert'];
        $aktiv = isset($alleUnter[$this->bereich ?? '']) ? $this->bereich : array_key_first($alleUnter);
        // Suche über alle Unter-Reiter aller sichtbaren Reiter: ein Treffer öffnet Reiter + Unter-Reiter
        $treffer = [];
        foreach (array_keys($gruppen) as $g) {
            foreach (array_merge(...array_values(self::unterReiter($g, $sektionen))) as $key => $u) {
                $treffer[$key] = $u + ['tab' => $g];
            }
        }

        return view('foodalchemist::livewire.settings.index', [
            'sektionen' => $sektionen,
            'gruppen' => $gruppen,
            'grundReiter' => $unter['grund'],
            'erweiterteReiter' => $unter['erweitert'],
            'aktiv' => $aktiv,
            'aktivEintrag' => $aktiv !== null ? $alleUnter[$aktiv] : null,
            'treffer' => $treffer,
            'istKindTeam' => $team !== null && $team->parent_team_id !== null,
            'nurLesen' => ! self::darfAendern(),
            'reiterSperre' => $this->reiterZustand(),
            'mitSperre' => collect($imReiter)->mapWithKeys(fn ($k) => [$k => ($c = self::komponente($k)) !== null && self::mitSperre($c)])->all(),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
