<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Spec 77b · Katalog der FA-Bereiche, die je Team freigeschaltet (Plattform-Admin) und je User
 * eingeschränkt (Team-Admin) werden. Jede FA-Route, jedes FA-MCP-Tool und jede FA-Livewire-Komponente
 * gehört genau EINEM Bereich — oder steht ausdrücklich in einer FREI-Liste (Hülle, Ansicht, Kopf).
 *
 * Zuordnung über Präfix-Tabellen (Routen-Gruppe, Tool-Ressource, Livewire-Namespace). Damit nichts
 * still durchrutscht, hält ein Wächter-Test JEDE registrierte Route und JEDES registrierte Tool gegen
 * diese Tabellen (tests/Feature/Spec77bBereicheTest.php) — ein neues Tool ohne Zuordnung lässt ihn rot werden.
 */
final class FaBereiche
{
    /** Schlüssel => Anzeigename (Reihenfolge = Anzeige). F4 (Dominique 08.10.): Ausgaben einzeln. */
    public const KATALOG = [
        'uebersicht' => 'Übersicht (Dashboard, Signale)',
        'planung' => 'Planung (Leitstelle)',
        'controlling' => 'Controlling',
        'stammdaten' => 'Stammdaten (Grundprodukte, Lieferanten)',
        'rezepte' => 'Rezepte (Basisrezepte, Gerichte, Kalkulation)',
        'concepter' => 'Concepter & Formate',
        'foodbook' => 'Foodbook',
        'speisekarte' => 'Speisekarte',
        'speiseplan' => 'Speiseplan',
        'angebote' => 'Angebote',
        'produktion' => 'Produktion (Tagesplan, Wandmonitor, Etiketten)',
        'einkauf' => 'Einkauf (Bestellungen, Wareneingang, Vorlagen)',
        'lager' => 'Lager & Inventur',
        'wissen' => 'Wissen & Trendradar',
        'einstellungen' => 'Einstellungen',
        'unterteams' => 'Unter-Teams & Freigaben',   // Spec 82 §13 B2: schaltbar in /verwaltung → Freigaben
        'projekte' => 'Projekte (Agentur-Akte, nur im Master-Team)',   // Spec 82 §9: Projekt = Agentur-Akte im Master
        'abos' => 'Abos (Master vergibt, Mandant liest)',     // Spec 82 §8 / §13 B6: Rechte-/Master-Prüfung zusätzlich im AboService
    ];

    /**
     * Bereiche, die NUR im Master-Team freigeschaltet sind (Spec 82 §9): Ohne Master-Team ist der Bereich aus, auch für
     * Plattform-Admins (kein Admin-Durchgriff auf Agentur-Daten). Eine ausdrückliche Zeile `aktiv=false` schaltet ihn im Master ebenfalls aus.
     */
    public const NUR_MASTER = ['projekte'];

    /** Routen: erstes Segment nach „foodalchemist." => Bereich. `null` = bereichsfrei. */
    public const ROUTEN = [
        'dashboard' => 'uebersicht', 'review' => 'uebersicht', 'signale' => 'uebersicht',
        'planung' => 'planung', 'blaetter' => 'planung',
        'controlling' => 'controlling', 'einkauf' => 'controlling',   // einkauf.* = alte Auswertungs-Routen → Controlling (Spec 32)
        'gps' => 'stammdaten', 'suppliers' => 'stammdaten', 'favorites' => 'stammdaten',
        'recipes' => 'rezepte', 'rezepte' => 'rezepte', 'verkauf' => 'rezepte', 'kalkulation' => 'rezepte', 'kalkulator' => 'rezepte',
        'concepter' => 'concepter', 'concepts' => 'concepter', 'formate' => 'concepter', 'pakete' => 'concepter',
        'foodbooks' => 'foodbook', 'speisekarte' => 'speisekarte', 'speiseplan' => 'speiseplan', 'angebote' => 'angebote',
        'produktion' => 'produktion', 'etiketten' => 'produktion',
        'orders' => 'einkauf', 'wareneingang' => 'einkauf', 'bestellvorlagen' => 'einkauf', 'preislisten' => 'einkauf',
        'lager' => 'lager',
        'knowledge' => 'wissen', 'trendradar' => 'wissen',
        'einstellungen' => 'einstellungen', 'food-dna' => 'einstellungen',
        'standortprofil' => 'einstellungen',   // Spec 82 §9: Eigene Standorte + Kunden-Standorte (nur Master)
        'projekte' => 'projekte',   // Spec 82 §9: Projekt-Liste und -Detail (nur im Master-Team)
        'abos' => 'abos',           // Spec 82 §13 B6: Abo-Seite (Master verwaltet, Mandant liest „Meine Abos“)
        // bereichsfrei: Sprachbefehl, Designsystem (Bau-Werkzeug), „demnächst"-Platzhalter
        'voice' => null, 'ui-katalog' => null, 'demnaechst' => null,
        'suche' => null, 'handy_app' => null,   // #1003 Handy: Suche filtert je Abschnitt selbst nach Bereich; App-Manifest ist öffentlich
        // öffentlich geteilte Präsentationen (ohne Anmeldung) und Plattform-Assets — nie an einer Freischaltung
        'presentation' => null, 'platform-asset' => null,
        // Spec 82 §13 C3: Export-Download an keinem Bereich, auch im gesperrten Team erreichbar
        'mandant-export' => null,
    ];

    /**
     * MCP-Tools: Ressource (Segment zwischen „foodalchemist." und „.VERB") — Präfix-Regeln, die ERSTE
     * passende gewinnt. Längere/speziellere Präfixe stehen deshalb oben.
     *
     * @var list<array{0:string,1:?string}>
     */
    public const TOOL_PRAEFIXE = [
        // Spec 82 §9: Projekte (Agentur-Akte, nur im Master-Team) — Ressourcen `projekte`, `projekt_standorte`
        ['projekt', 'projekte'],
        // bereichsfrei: Ansicht/Navigation, Läufe, Rechte-Pflege, Betriebs-Brille umschalten (nur Ansicht)
        ['ui', null], ['runs', null], ['team_roles', null], ['team_bereiche', null], ['standorte', null],
        // #1002: KI-Aufträge (externer Weg) sind featureübergreifend wie Läufe — ein Auftrag entsteht nur aus einem Aufruf, der seinen Bereich
        // schon passiert hat, und gehört dem Team (KiAuftragService filtert auf team_id). Je Bereich zuordnen hieße: Auftrag von gp.sensorik
        // unter Stammdaten, von recipe.* unter Rezepte — das Tool selbst kennt nur die Liste.
        ['ki_auftraege', null],
        // Spec 82 §13 B1/B4: Plattform-Steuerung (Stamm-Paket, Master-Team, Mandanten-Verwaltung, Gebühren) — Rechteprüfung im Dienst (nur Plattform-Admin), nicht je Bereich
        ['stamm_paket', null], ['paket_grundeinstellungen', null], ['master_team', null], ['mandant_export', null], ['equipment_standard', null], ['mandanten', null], ['plan_preise', null], ['plan_nutzung', null], ['support_zugang', null], ['ki_klassen', null], ['ki_verbrauch', null],   // Spec 84 §4.4 (E3): Rechte-Pflege des Mandanten, bereichsfrei wie die Zugriffsrechte
        // Spec 82 §8 / §13 B6: Abos (Bereich „Abos" im Master-Team)
        ['abos', 'abos'],
        // Spec 77d: Inhalte für Standorte (Editor im Controlling), Sammlungen + eigene Kopie gehören zu den Rezepten
        ['standort_inhalte', 'controlling'], ['sammlungen', 'rezepte'], ['inhalte', 'rezepte'],
        // Controlling (Auswertungen; Spec 32 hat die Einkaufs-Auswertungen hierher verlegt)
        ['einkauf_', 'controlling'], ['sales_', 'controlling'], ['benchmark', 'controlling'], ['menu_engineering', 'controlling'],
        ['simulation', 'controlling'], ['coverage', 'controlling'], ['ausgabe_drift', 'controlling'],
        // Übersicht
        ['signal', 'uebersicht'], ['signale', 'uebersicht'],
        // Einkauf
        ['orders', 'einkauf'], ['order_', 'einkauf'], ['delivery_notes', 'einkauf'], ['supplier_invoices', 'einkauf'],
        ['triple_match', 'einkauf'], ['preisliste', 'einkauf'], ['bestellvorschlag', 'einkauf'], ['einkaufsliste', 'einkauf'],
        // Lager
        ['inventory', 'lager'], ['storage_bins', 'lager'], ['eigenproduktion', 'lager'], ['lagerartikel', 'lager'],
        // Produktion
        ['production_', 'produktion'], ['produktionsblatt', 'produktion'], ['labels', 'produktion'], ['label_templates', 'produktion'],
        ['printers', 'produktion'], ['behaelter_bedarf', 'produktion'],
        // Planung (Leitstelle)
        ['planung', 'planung'], ['planning', 'planung'], ['leitstelle', 'planung'], ['brief_templates', 'planung'], ['phase', 'planung'],
        ['platzhalter', 'planung'], ['assemblierung', 'planung'], ['kombination', 'planung'], ['canvas', 'planung'], ['kontext', 'planung'], ['ablauf', 'planung'],
        // Ausgaben (einzeln)
        ['speisekarte', 'speisekarte'], ['speiseplan', 'speiseplan'], ['speiseplaene', 'speiseplan'],
        ['foodbook', 'foodbook'], ['kapitel_', 'foodbook'],
        ['angebot', 'angebote'], ['offer', 'angebote'],
        // Concepter & Formate
        ['concept', 'concepter'], ['composer', 'concepter'], ['format', 'concepter'], ['paket', 'concepter'], ['baustein', 'concepter'],
        ['zielgruppen', 'concepter'], ['portfolio', 'concepter'], ['proportion', 'concepter'],
        // Rezepte (inkl. Gerichte, Kalkulation/VK)
        ['recipe', 'rezepte'], ['verkaufsrezepte', 'rezepte'], ['dish', 'rezepte'], ['kalkulation', 'rezepte'],
        ['lab_notes', 'rezepte'], ['reife', 'rezepte'], ['surplus', 'rezepte'], ['quality_run', 'rezepte'], ['anreicherung_vorschlag', 'rezepte'],
        ['datenwerk', 'rezepte'], ['substitution', 'rezepte'], ['machbarkeit', 'rezepte'],
        // Wissen & Trendradar
        ['knowledge', 'wissen'], ['rules', 'wissen'], ['anker_wissen', 'wissen'], ['trendradar', 'wissen'], ['trends', 'wissen'], ['trend_belege', 'wissen'], ['fundstuecke', 'wissen'], ['pairing', 'wissen'], ['reference', 'wissen'],
        ['regelwerk', 'wissen'], ['terminology', 'wissen'],
        // Stammdaten
        ['gp', 'stammdaten'], ['gps', 'stammdaten'], ['preise_verlauf', 'stammdaten'], ['preise_historie', 'stammdaten'], ['nonfood', 'stammdaten'], ['artikel', 'stammdaten'], ['produktpass', 'stammdaten'], ['necta_pruefaelle', 'stammdaten'], ['marke_offen', 'stammdaten'], ['supplier', 'stammdaten'], ['suppliers', 'stammdaten'],
        ['favorites', 'stammdaten'], ['match', 'stammdaten'], ['la', 'stammdaten'], ['lead_la', 'stammdaten'], ['ingest', 'stammdaten'],
        ['struktur_vokabular', 'stammdaten'], ['component_equivalents', 'stammdaten'], ['naehrwerte', 'stammdaten'], ['naehrwert_pruefung', 'stammdaten'],
        // Einstellungen
        ['settings', 'einstellungen'], ['team_settings', 'einstellungen'], ['team_profile', 'einstellungen'], ['vocab_', 'einstellungen'], ['outlet_settings', 'einstellungen'],
        ['outlets', 'einstellungen'], ['presentation_designs', 'einstellungen'], ['behaelter_katalog', 'einstellungen'],
    ];

    /** Einzelne Tools, die unabhängig von ihrer Ressource bereichsfrei sind (nur Ansicht des Benutzers). */
    public const TOOLS_FREI = ['foodalchemist.outlets.SET_ACTIVE', 'foodalchemist.outlets.GET'];

    /** Livewire: erstes Namespace-Segment unter Platform\FoodAlchemist\Livewire => Bereich. Fehlt = frei. */
    public const KOMPONENTEN = [
        'Dashboard' => 'uebersicht', 'ReviewQueue' => 'uebersicht', 'Signale' => 'uebersicht',
        'Planung' => 'planung',
        'Controlling' => 'controlling',
        'Gps' => 'stammdaten', 'Suppliers' => 'stammdaten', 'Favorites' => 'stammdaten',
        'Recipes' => 'rezepte', 'Verkauf' => 'rezepte', 'Kalkulation' => 'rezepte', 'Kalkulator' => 'rezepte',
        'Concepter' => 'concepter', 'Concepts' => 'concepter', 'Formate' => 'concepter', 'Pakete' => 'concepter',
        'Foodbooks' => 'foodbook', 'Speisekarte' => 'speisekarte', 'Speiseplan' => 'speiseplan', 'Angebote' => 'angebote',
        'Produktion' => 'produktion', 'Etiketten' => 'produktion',
        'Orders' => 'einkauf', 'Wareneingang' => 'einkauf', 'Bestellvorlagen' => 'einkauf', 'Preislisten' => 'einkauf',
        'Lager' => 'lager',
        'Knowledge' => 'wissen', 'Trendradar' => 'wissen',
        'Settings' => 'einstellungen', 'FoodDna' => 'einstellungen',
        'Projekte' => 'projekte', 'Standortprofil' => 'einstellungen', 'Abos' => 'abos',
    ];

    public static function istBereich(string $bereich): bool
    {
        return array_key_exists($bereich, self::KATALOG);
    }

    /** Bereich einer FA-Route (`foodalchemist.orders.index`); null = bereichsfrei oder keine FA-Route. */
    public static function fuerRoute(?string $routenName): ?string
    {
        if ($routenName === null || ! str_starts_with($routenName, 'foodalchemist.')) {
            return null;
        }
        $gruppe = explode('.', substr($routenName, strlen('foodalchemist.')))[0] ?? '';

        return self::ROUTEN[$gruppe] ?? null;
    }

    /** true, wenn die Route ausdrücklich zugeordnet ist (auch als bereichsfrei) — für den Wächter-Test. */
    public static function routeZugeordnet(string $routenName): bool
    {
        $gruppe = explode('.', substr($routenName, strlen('foodalchemist.')))[0] ?? '';

        return array_key_exists($gruppe, self::ROUTEN);
    }

    /** Bereich eines FA-Tools (`foodalchemist.orders.SET_STATUS`); null = bereichsfrei. */
    public static function fuerTool(string $toolName): ?string
    {
        return self::toolZuordnung($toolName)[1];
    }

    /** @return array{0:bool,1:?string} [zugeordnet, bereich] */
    public static function toolZuordnung(string $toolName): array
    {
        if (in_array($toolName, self::TOOLS_FREI, true)) {
            return [true, null];
        }
        if (! str_starts_with($toolName, 'foodalchemist.')) {
            return [true, null];
        }
        $teile = explode('.', $toolName);
        $ressource = $teile[1] ?? '';
        foreach (self::TOOL_PRAEFIXE as [$praefix, $bereich]) {
            if ($ressource === $praefix || str_starts_with($ressource, $praefix)) {
                return [true, $bereich];
            }
        }

        return [false, null];
    }

    /**
     * Sidebar-Gruppen ohne Einträge, deren Bereich der User im aktuellen Team nicht nutzen darf.
     *
     * @param  list<array{group?:string, items?:list<array{route?:string}>}>  $gruppen
     */
    public static function sichtbareNavigation(array $gruppen, mixed $user): array
    {
        $team = $user?->currentTeamRelation ?? null;
        if (! $user instanceof \Platform\Core\Models\User || $team === null) {
            return $gruppen;
        }
        // Spec 82 §9: Master-only-Bereiche stehen außerhalb des Master-Teams in keiner Navigation, auch nicht für Plattform-Admins
        if (! \Platform\FoodAlchemist\Support\MasterTeam::istMaster($team)) {
            $gruppen = array_values(array_filter(array_map(function (array $g) {
                $g['items'] = array_values(array_filter($g['items'] ?? [], fn (array $item) => ! in_array(self::fuerRoute($item['route'] ?? null), self::NUR_MASTER, true)));

                return $g;
            }, $gruppen), fn (array $g) => ($g['items'] ?? []) !== []));
        }
        $rechte = app(\Platform\FoodAlchemist\Services\FaRechte::class);
        if (! $user->isAiUser() && $rechte->istPlattformAdmin($user)) {
            return $gruppen;
        }
        // einmal je Aufbau laden statt je Eintrag
        $teamAn = $rechte->teamBereiche($team);
        $userAus = \Platform\FoodAlchemist\Models\FoodAlchemistUserBereichSperre::whereIn('team_id', $rechte->teamKette($team))
            ->where('user_id', $user->id)->pluck('bereich')->all();
        $darf = fn (?string $b) => $b === null || (($teamAn[$b] ?? true) && ! in_array($b, $userAus, true));

        return array_values(array_filter(array_map(function (array $g) use ($darf) {
            $g['items'] = array_values(array_filter($g['items'] ?? [], fn (array $item) => $darf(self::fuerRoute($item['route'] ?? null))));

            return $g;
        }, $gruppen), fn (array $g) => ($g['items'] ?? []) !== []));
    }

    /** Bereich einer Livewire-Komponente (Klassenname); null = bereichsfrei. */
    public static function fuerKomponente(object|string $komponente): ?string
    {
        $klasse = is_object($komponente) ? $komponente::class : $komponente;
        $praefix = 'Platform\\FoodAlchemist\\Livewire\\';
        if (! str_starts_with($klasse, $praefix)) {
            return null;
        }
        $segment = explode('\\', substr($klasse, strlen($praefix)))[0] ?? '';

        return self::KOMPONENTEN[$segment] ?? null;
    }
}
