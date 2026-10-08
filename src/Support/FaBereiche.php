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
        'stammdaten' => 'Stammdaten (Grundprodukte, Lieferanten, Geschirr)',
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
    ];

    /** Routen: erstes Segment nach „foodalchemist." => Bereich. `null` = bereichsfrei. */
    public const ROUTEN = [
        'dashboard' => 'uebersicht', 'review' => 'uebersicht', 'signale' => 'uebersicht',
        'planung' => 'planung', 'blaetter' => 'planung',
        'controlling' => 'controlling', 'einkauf' => 'controlling',   // einkauf.* = alte Auswertungs-Routen → Controlling (Spec 32)
        'gps' => 'stammdaten', 'suppliers' => 'stammdaten', 'geschirr' => 'stammdaten', 'favorites' => 'stammdaten',
        'recipes' => 'rezepte', 'rezepte' => 'rezepte', 'verkauf' => 'rezepte', 'kalkulation' => 'rezepte', 'kalkulator' => 'rezepte',
        'concepter' => 'concepter', 'concepts' => 'concepter', 'formate' => 'concepter', 'pakete' => 'concepter',
        'foodbooks' => 'foodbook', 'speisekarte' => 'speisekarte', 'speiseplan' => 'speiseplan', 'angebote' => 'angebote',
        'produktion' => 'produktion', 'etiketten' => 'produktion',
        'orders' => 'einkauf', 'wareneingang' => 'einkauf', 'bestellvorlagen' => 'einkauf',
        'lager' => 'lager',
        'knowledge' => 'wissen', 'trendradar' => 'wissen',
        'einstellungen' => 'einstellungen', 'food-dna' => 'einstellungen',
        // bereichsfrei: Sprachbefehl, Designsystem (Bau-Werkzeug), „demnächst"-Platzhalter
        'voice' => null, 'ui-katalog' => null, 'demnaechst' => null,
        // öffentlich geteilte Präsentationen (ohne Anmeldung) und Plattform-Assets — nie an einer Freischaltung
        'presentation' => null, 'platform-asset' => null,
    ];

    /**
     * MCP-Tools: Ressource (Segment zwischen „foodalchemist." und „.VERB") — Präfix-Regeln, die ERSTE
     * passende gewinnt. Längere/speziellere Präfixe stehen deshalb oben.
     *
     * @var list<array{0:string,1:?string}>
     */
    public const TOOL_PRAEFIXE = [
        // bereichsfrei: Ansicht/Navigation, Läufe, Rechte-Pflege, Betriebs-Brille umschalten (nur Ansicht)
        ['ui', null], ['runs', null], ['team_roles', null], ['team_bereiche', null], ['standorte', null],
        // Controlling (Auswertungen; Spec 32 hat die Einkaufs-Auswertungen hierher verlegt)
        ['einkauf_', 'controlling'], ['sales_', 'controlling'], ['benchmark', 'controlling'], ['menu_engineering', 'controlling'],
        ['simulation', 'controlling'], ['coverage', 'controlling'],
        // Übersicht
        ['signal', 'uebersicht'], ['signale', 'uebersicht'],
        // Einkauf
        ['orders', 'einkauf'], ['order_', 'einkauf'], ['delivery_notes', 'einkauf'], ['supplier_invoices', 'einkauf'],
        ['triple_match', 'einkauf'], ['bestellvorschlag', 'einkauf'], ['einkaufsliste', 'einkauf'],
        // Lager
        ['inventory', 'lager'], ['storage_bins', 'lager'], ['eigenproduktion', 'lager'], ['lagerartikel', 'lager'],
        // Produktion
        ['production_', 'produktion'], ['produktionsblatt', 'produktion'], ['labels', 'produktion'], ['label_templates', 'produktion'],
        ['printers', 'produktion'], ['behaelter_bedarf', 'produktion'],
        // Planung (Leitstelle)
        ['planung', 'planung'], ['planning', 'planung'], ['leitstelle', 'planung'], ['brief_templates', 'planung'], ['phase', 'planung'],
        ['platzhalter', 'planung'], ['assemblierung', 'planung'], ['kombination', 'planung'], ['canvas', 'planung'], ['ablauf', 'planung'],
        // Ausgaben (einzeln)
        ['speisekarte', 'speisekarte'], ['speiseplan', 'speiseplan'], ['speiseplaene', 'speiseplan'],
        ['foodbook', 'foodbook'], ['kapitel_', 'foodbook'],
        ['angebot', 'angebote'], ['offer', 'angebote'],
        // Concepter & Formate
        ['concept', 'concepter'], ['composer', 'concepter'], ['format', 'concepter'], ['paket', 'concepter'],
        ['zielgruppen', 'concepter'], ['portfolio', 'concepter'], ['proportion', 'concepter'],
        // Rezepte (inkl. Gerichte, Kalkulation/VK)
        ['recipe', 'rezepte'], ['verkaufsrezepte', 'rezepte'], ['dish', 'rezepte'], ['kalkulation', 'rezepte'], ['vk_snapshots', 'rezepte'],
        ['lab_notes', 'rezepte'], ['reife', 'rezepte'], ['surplus', 'rezepte'], ['quality_run', 'rezepte'], ['anreicherung_vorschlag', 'rezepte'],
        ['datenwerk', 'rezepte'], ['substitution', 'rezepte'],
        // Wissen & Trendradar
        ['knowledge', 'wissen'], ['anker_wissen', 'wissen'], ['trendradar', 'wissen'], ['pairing', 'wissen'], ['reference', 'wissen'],
        ['regelwerk', 'wissen'], ['terminology', 'wissen'],
        // Stammdaten
        ['gp', 'stammdaten'], ['gps', 'stammdaten'], ['artikel', 'stammdaten'], ['supplier', 'stammdaten'], ['suppliers', 'stammdaten'],
        ['geschirr_', 'stammdaten'], ['favorites', 'stammdaten'], ['match', 'stammdaten'], ['lead_la', 'stammdaten'], ['ingest', 'stammdaten'],
        ['struktur_vokabular', 'stammdaten'], ['component_equivalents', 'stammdaten'],
        // Einstellungen
        ['settings', 'einstellungen'], ['team_settings', 'einstellungen'], ['vocab_', 'einstellungen'], ['outlet_settings', 'einstellungen'],
        ['outlets', 'einstellungen'], ['presentation_designs', 'einstellungen'], ['behaelter_katalog', 'einstellungen'],
    ];

    /** Einzelne Tools, die unabhängig von ihrer Ressource bereichsfrei sind (nur Ansicht des Benutzers). */
    public const TOOLS_FREI = ['foodalchemist.outlets.SET_ACTIVE', 'foodalchemist.outlets.GET'];

    /** Livewire: erstes Namespace-Segment unter Platform\FoodAlchemist\Livewire => Bereich. Fehlt = frei. */
    public const KOMPONENTEN = [
        'Dashboard' => 'uebersicht', 'ReviewQueue' => 'uebersicht', 'Signale' => 'uebersicht',
        'Planung' => 'planung',
        'Controlling' => 'controlling',
        'Gps' => 'stammdaten', 'Suppliers' => 'stammdaten', 'Geschirr' => 'stammdaten', 'Favorites' => 'stammdaten',
        'Recipes' => 'rezepte', 'Verkauf' => 'rezepte', 'Kalkulation' => 'rezepte', 'Kalkulator' => 'rezepte',
        'Concepter' => 'concepter', 'Concepts' => 'concepter', 'Formate' => 'concepter', 'Pakete' => 'concepter',
        'Foodbooks' => 'foodbook', 'Speisekarte' => 'speisekarte', 'Speiseplan' => 'speiseplan', 'Angebote' => 'angebote',
        'Produktion' => 'produktion', 'Etiketten' => 'produktion',
        'Orders' => 'einkauf', 'Wareneingang' => 'einkauf', 'Bestellvorlagen' => 'einkauf',
        'Lager' => 'lager',
        'Knowledge' => 'wissen', 'Trendradar' => 'wissen',
        'Settings' => 'einstellungen', 'FoodDna' => 'einstellungen',
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
