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
     * Neuordnung 23.10. (Dominique: Reiter statt Seitenleiste): je GRUPPE ein Reiter, im Reiter die Bereiche als Abschnitte mit
     * Sprungmarken. Reiter und Bereich stehen in der URL (?tab=…&bereich=…); alte Links /einstellungen/{sektion} öffnen den Reiter
     * der Sektion und springen zum Bereich. Nur der aktive Reiter wird gerendert, Bereiche unterhalb des ersten laden verzögert
     * (lazy), erweiterte Bereiche stecken in einem aufklappbaren Block.
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
            $this->tab = self::gruppeVon($sektion);
            $this->bereich = $sektion;
        }
        $this->normalisieren($sichtbar);
    }

    /** Reiter wechseln (Leiste im Server-Modus): nur sichtbare Reiter, Sprungziel fällt weg. */
    public function tabWaehlen(string $tab): void
    {
        $this->tab = $tab;
        $this->bereich = null;
        $this->normalisieren(self::sichtbareSektionen());
    }

    /** Ungültiger oder nicht sichtbarer Reiter/Bereich (auch aus einer geteilten URL) → erster sichtbarer Reiter, kein Sprung. */
    private function normalisieren(array $sichtbar): void
    {
        if ($this->bereich !== null && ! isset($sichtbar[$this->bereich])) {
            $this->bereich = null;
        }
        if ($this->bereich !== null) {
            $this->tab = self::gruppeVon($this->bereich);
        }
        $gruppen = self::sichtbareGruppen($sichtbar);
        if (! isset($gruppen[$this->tab])) {
            $this->tab = (string) (array_key_first($gruppen) ?? '');
        }
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
        $grund = array_values(array_filter($imReiter, fn ($k) => in_array($k, self::GRUND, true)));   // Sprungziel bleibt an seinem Platz (Klappblock öffnet)
        $erweitert = array_values(array_diff($imReiter, $grund));
        if ($grund === []) {   // Reiter ganz ohne Grundeinstellung (z. B. Kategorien): alles offen, kein Klappblock
            [$grund, $erweitert] = [$erweitert, []];
        }

        return view('foodalchemist::livewire.settings.index', [
            'sektionen' => $sektionen,
            'gruppen' => $gruppen,
            'grundBereiche' => $grund,
            'erweiterteBereiche' => $erweitert,
            'stichworte' => self::STICHWORTE,
            'istKindTeam' => $team !== null && $team->parent_team_id !== null,
            'nurLesen' => ! self::darfAendern(),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
