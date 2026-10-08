<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
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
    public string $sektion = 'einheiten';

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
        'kueche' => ['label' => 'Küchen-Profil', 'hint' => 'Küchentyp als Grundrichtung für Vorschläge, Farben je Positionstyp'],
        // Ebene 1 der DNA-Kette (Umzug 2026-07-21): Team-Food-DNA wohnt bei den Einstellungen, nicht als Top-Level-Nav
        'food-dna' => ['label' => 'Food DNA', 'hint' => 'Leitbild, Stil, Aromatik und No-Gos des eigenen Hauses'],
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

        // — Produktion & Kapazität —
        // Spec 30 E3: Arbeitsplätze mit optionaler Tageskapazität — bewusst getrennt vom
        // Koch-Equipment (das sagt „was braucht ein Rezept", der Posten „wo wird gearbeitet").
        'posten' => ['label' => 'Posten & Kapazität', 'hint' => 'Arbeitsplätze der Küche, verplanbare Minuten je Tag, Besetzung'],
        // Stufe 3 P3.1: Rollen als Kostenträger (Küchenchef/Koch/Hilfskoch) — Satz je Rolle.
        // Rolle ≠ Mensch: keine Namen/Schichten. Posten-Besetzung leitet Kapazität + Kosten ab.
        // Spec 61/75: was die Plattform-Rollen im FA dürfen + Häkchen „darf Rechnungen freigeben" — Rechte, nicht Kosten.
        'zugriffsrechte' => ['label' => 'Zugriffsrechte', 'hint' => 'Was Mitglieder und Betrachter dürfen, wer Rechnungen freigibt'],
        'rollen' => ['label' => 'Rollen & Stundensätze', 'hint' => 'Küchenrollen mit Stundensatz für Kapazität und Produktionskosten'],

        // — Ausgabe & Betrieb —
        // Spec 43: visueller Struktur-Builder für Präsentations-Designs (Block-Palette · Live-Vorschau · Tokens)
        'praesentations-designs' => ['label' => 'Präsentations-Designs', 'hint' => 'Aufbau, Farben und Schrift des digitalen Kundenbuchs'],
        // Spec 33 P2: Die Tabelle gab es seit Spec 19, die Pflege nie — deshalb war sie leer
        // und `outlet_id` an der Speisekarte hatte nicht einmal ein Eingabefeld.
        'betriebe' => ['label' => 'Betriebe & Standorte', 'hint' => 'Standorte und Ausgabestellen, Vorlage und Logo je Betrieb'],
        // Spec 77d: was jeder Standort (Unter-Team) vom Oberteam sieht — alles oder nur Freigegebenes
        'standort-inhalte' => ['label' => 'Inhalte für Standorte', 'hint' => 'Übernimmt alles vom Oberteam oder nur freigegebene Sammlungen und Ausgaben'],
        // Spec 59: zentraler Chip-Katalog für Speiseplan-Vorgaben („mind. 2× vegan je Woche“) —
        // die Vorgaben selbst stehen je Plan im Speiseplan-Editor (Reiter Stammdaten).
        'etiketten' => ['label' => 'Etiketten', 'hint' => 'Etikett-Vorlagen: Format, Felder, Datum vorbelegt oder zum Handschreiben, Logo und Design'],
        'speiseplan-chips' => ['label' => 'Speiseplan-Chips', 'hint' => 'Prüf-Chips für Speiseplan-Vorgaben: Ernährungsform oder Hauptgruppe, Standardwerte mindestens und höchstens'],
    ];

    /**
     * fa-pass 2026-10-05: Gruppen der Navigation (reine Darstellung). Jede Sektion steht in GENAU
     * einer Gruppe; was hier fehlt, rendert die View unter „Weitere" (nie verschluckt). Die erste
     * Gruppe beginnt mit `einheiten` — der mount()-Default steht weiter ganz oben.
     *
     * @var array<string, array{label: string, sektionen: list<string>}>
     */
    public const GRUPPEN = [
        'katalog' => ['label' => 'Katalog & Kategorien', 'sektionen' => ['einheiten', 'warengruppen', 'taxonomie', 'vk-taxonomie', 'behaelter', 'concepter-dimensionen']],
        'betrieb' => ['label' => 'Betrieb & Küche', 'sektionen' => ['betriebe', 'standort-inhalte', 'posten', 'rollen', 'zugriffsrechte', 'kueche']],
        'kalkulation' => ['label' => 'Einkauf & Kalkulation', 'sektionen' => ['einkauf', 'kalkulation', 'herstellkosten', 'aufschlagsklassen']],
        'ki' => ['label' => 'KI & Wissen', 'sektionen' => ['ki', 'food-dna', 'kunde-dna', 'brief-vorlagen', 'trendradar', 'wissenskategorien', 'wissenssteuerung']],
        'ausgabe' => ['label' => 'Ausgabe', 'sektionen' => ['schreibstile', 'praesentations-designs', 'speiseplan-chips', 'etiketten']],
    ];

    public function mount(string $sektion = 'einheiten'): void
    {
        abort_unless(array_key_exists($sektion, self::SEKTIONEN), 404);
        $this->sektion = $sektion;
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.settings.index', [
            'sektionen' => self::SEKTIONEN,
            'gruppen' => self::GRUPPEN,
            'istKindTeam' => $team !== null && $team->parent_team_id !== null,
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
