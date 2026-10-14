<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Spec 82 Abo Konzepte/Pakete/Formate · EIN Register, wo eine Konzept-/Paket-Fassung lebend referenziert wird. Gebraucht vom Folgen (BausteinFolgeService,
 * PR 5) und von Kündigung/Zurückziehen (AboKuendigungService, PR 6: „genutzt“, Umhängen) — dieselben Filter an allen Stellen, sonst zählt die Kündigung
 * etwas anderes als das Folgen.
 *
 * `kapitel` = Zwischentabelle bis zum Elternobjekt (Foodbook, Speisekarte, Angebot), sonst hängt das Elternobjekt direkt an der Zeile.
 * `eigene` = nur nicht-Fassungs-Eltern (Konzept, Format): eine Fassung ist nie „Ausgabe des Mandanten“.
 * `angebot` = Statusregel des Angebots (angenommen/abgelehnt behalten die Fassung); sonst gilt „nicht archiviert“.
 */
final class VerweisRegister
{
    public const REFERENZEN = [
        'konzept_paket' => ['tab' => 'foodalchemist_concept_slots', 'spalte' => 'package_id', 'art' => 'paket', 'eltern' => 'foodalchemist_concepts', 'fk' => 'concept_id', 'name' => 'name', 'wo' => 'Konzept', 'eigene' => true],
        'konzept_eingebettet' => ['tab' => 'foodalchemist_concept_slots', 'spalte' => 'embedded_concept_id', 'art' => 'concept', 'eltern' => 'foodalchemist_concepts', 'fk' => 'concept_id', 'name' => 'name', 'wo' => 'Konzept', 'eigene' => true],
        'format' => ['tab' => 'foodalchemist_format_slots', 'spalte' => 'concept_id', 'art' => 'concept', 'eltern' => 'foodalchemist_formats', 'fk' => 'format_id', 'name' => 'name', 'wo' => 'Format', 'eigene' => true],
        'foodbook' => ['tab' => 'foodalchemist_foodbook_blocks', 'spalte' => 'concept_id', 'art' => 'concept', 'eltern' => 'foodalchemist_foodbooks', 'fk' => 'foodbook_id', 'name' => 'label', 'wo' => 'Foodbook',
            'kapitel' => ['foodalchemist_foodbook_chapters', 'chapter_id']],
        'speisekarte' => ['tab' => 'foodalchemist_menu_card_items', 'spalte' => 'concept_id', 'art' => 'concept', 'eltern' => 'foodalchemist_menu_cards', 'fk' => 'menu_card_id', 'name' => 'name', 'wo' => 'Speisekarte',
            'kapitel' => ['foodalchemist_menu_card_sections', 'section_id']],
        'angebot' => ['tab' => 'foodalchemist_offer_blocks', 'spalte' => 'concept_id', 'art' => 'concept', 'eltern' => 'foodalchemist_offers', 'fk' => 'offer_id', 'name' => 'name', 'wo' => 'Angebot',
            'kapitel' => ['foodalchemist_offer_chapters', 'chapter_id'], 'angebot' => true],
        'speiseplan_konzept' => ['tab' => 'foodalchemist_menu_plan_entries', 'spalte' => 'concept_id', 'art' => 'concept', 'eltern' => 'foodalchemist_menu_plans', 'fk' => 'menu_plan_id', 'name' => 'name', 'wo' => 'Speiseplan'],
        // Format-Kapitel/-Rubriken (PR 7): die Zeile IST das Kapitel, das Elternobjekt hängt direkt daran (kein Zwischenkapitel)
        'foodbook_kapitel_format' => ['tab' => 'foodalchemist_foodbook_chapters', 'spalte' => 'format_id', 'art' => 'format', 'eltern' => 'foodalchemist_foodbooks', 'fk' => 'foodbook_id', 'name' => 'label', 'wo' => 'Foodbook'],
        'angebot_kapitel_format' => ['tab' => 'foodalchemist_offer_chapters', 'spalte' => 'format_id', 'art' => 'format', 'eltern' => 'foodalchemist_offers', 'fk' => 'offer_id', 'name' => 'name', 'wo' => 'Angebot', 'angebot' => true],
        'speisekarte_rubrik_format' => ['tab' => 'foodalchemist_menu_card_sections', 'spalte' => 'format_id', 'art' => 'format', 'eltern' => 'foodalchemist_menu_cards', 'fk' => 'menu_card_id', 'name' => 'name', 'wo' => 'Speisekarte'],
        'speiseplan_paket' => ['tab' => 'foodalchemist_menu_plan_entries', 'spalte' => 'package_id', 'art' => 'paket', 'eltern' => 'foodalchemist_menu_plans', 'fk' => 'menu_plan_id', 'name' => 'name', 'wo' => 'Speiseplan'],
    ];

    /**
     * Basis-Abfrage einer Quelle: Zeile `x` (ohne gelöscht), Elternobjekt `p` (ohne gelöscht, nur eigene bzw. nur aktive nach Statusregel). Aufrufer ergänzen
     * `whereIn('x.<spalte>', …)` und `whereIn('x.team_id', …)`.
     */
    public static function basis(string $quelle): Builder
    {
        $r = self::REFERENZEN[$quelle];
        $q = DB::table($r['tab'].' AS x');
        if (isset($r['kapitel'])) {
            [$kapTab, $kapFk] = $r['kapitel'];
            $q->join($kapTab.' AS k', 'k.id', '=', 'x.'.$kapFk)->join($r['eltern'].' AS p', 'p.id', '=', 'k.'.$r['fk']);
        } else {
            $q->join($r['eltern'].' AS p', 'p.id', '=', 'x.'.$r['fk']);
        }
        $q->whereNull('x.deleted_at')->whereNull('p.deleted_at')->whereNotNull('x.team_id');
        if (! empty($r['eigene'])) {
            $q->where('p.ist_fassung', false);   // Konzept/Format des Mandanten, nie eine Fassung
        }
        ! empty($r['angebot'])
            ? $q->whereNotIn('p.status', ['angenommen', 'abgelehnt'])   // historische Angebote behalten die Fassung
            : $q->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'));

        return $q;
    }

    /** @return list<string> Quellen, die eine Art (concept | paket) referenzieren */
    public static function quellenFuer(string $art): array
    {
        return array_keys(array_filter(self::REFERENZEN, fn ($r) => $r['art'] === $art));
    }
}
