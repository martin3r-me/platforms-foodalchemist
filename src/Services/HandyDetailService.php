<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierContact;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * #1003 Handy-Detail im Handbuch-Stil (Rezept und Gericht): nur was die Küche am Handy braucht.
 * Schritte, Anrichten, Regeneration, Portion und Behälter kommen aus derselben Karte wie das öffentliche
 * Handbuch (HandbuchService::gerichtKarte, ohne den Report-Lauf für Zutaten); Zutaten liest die View direkt
 * am Rezept. Kein Pairing, keine KI, keine Verwaltung → schneller Aufbau am Handy.
 * Lieferanten (PR3): Kontakt-Karte und Artikel-Karte, nur über die team-sichtbaren Wege (visibleToTeam,
 * SupplierItemService mit Team-Preis) — ein Mandant sieht am Handy genau das, was er am Computer sieht.
 */
class HandyDetailService
{
    public function __construct(private HandbuchService $handbuch)
    {
    }

    /** @return array<string,mixed> Karte (foto_url, portion, behaelter, zubereitung, anrichten, regeneration) */
    public function karte(Team $team, FoodAlchemistRecipe $rezept): array
    {
        $karte = $this->handbuch->hydrateKarte($this->handbuch->gerichtKarte($team, $rezept, '', false));
        if ($karte['foto_url'] === null) {
            // Ohne Gericht-Foto: das Endprodukt-Bild („so soll es fertig aussehen") steht oben
            $endprodukt = FoodAlchemistRecipeStepPhoto::where('recipe_id', $rezept->id)->where('is_result', true)->first();
            $karte['foto_url'] = $endprodukt?->url();
        }

        return $karte;
    }

    /** Darreichungsformen eines Gerichts, Standard zuerst (VK aus der Darreichung, der Preis-Wahrheit). */
    public function formen(FoodAlchemistRecipe $rezept): \Illuminate\Support\Collection
    {
        return FoodAlchemistRecipeDarreichung::with('servingForm')
            ->where('recipe_id', $rezept->id)->orderByDesc('is_standard')->orderBy('id')->get();
    }

    public const WOCHENTAGE = ['1' => 'Mo', '2' => 'Di', '3' => 'Mi', '4' => 'Do', '5' => 'Fr', '6' => 'Sa', '7' => 'So'];

    /**
     * Kontakt-Karte am Handy: erreichen und bestellen (Ansprechpartner, Bestell-Mail, Bestellschluss, Vorlauf,
     * Liefertage, Mindestbestellwert). Keine Konditionen (Rabatt, Zahlungsziel, Rückvergütung). Ansprechpartner
     * nur aus der eigenen Team-Kette. Null, wenn der Lieferant für das Team nicht sichtbar ist.
     *
     * @return array<string,mixed>|null
     */
    public function lieferantKarte(Team $team, int $id): ?array
    {
        $s = FoodAlchemistSupplier::visibleToTeam($team)->find($id);
        if ($s === null) {
            return null;
        }
        $tage = array_filter(explode(',', (string) $s->delivery_days), 'strlen');

        return [
            'id' => (int) $s->id,
            'name' => $s->name,
            'ort' => trim(($s->postal_code ?? '') . ' ' . ($s->city ?? '')) ?: null,
            'inaktiv' => (bool) $s->is_inactive,
            'email_order' => $s->email_order ?: null,
            'homepage' => $s->homepage ?: null,
            'bestellschluss' => $s->order_cutoff_time ? substr((string) $s->order_cutoff_time, 0, 5) : null,
            'vorlauf_tage' => $s->order_lead_days !== null ? (int) $s->order_lead_days : null,
            'liefertage' => collect($tage)->map(fn ($t) => self::WOCHENTAGE[trim($t)] ?? null)->filter()->implode(', ') ?: null,
            'mindestbestellwert' => $s->min_order_value !== null ? (float) $s->min_order_value : null,
            'kontakte' => FoodAlchemistSupplierContact::visibleToTeam($team)->where('supplier_id', $s->id)
                ->orderBy('name')->get(['name', 'role', 'phone', 'email'])->all(),
        ];
    }

    /**
     * Artikel-Karte am Handy: derselbe Lese-Weg wie Liste und Suche (SupplierItemService, Team-Preis), dazu
     * Vergleichspreis und die 14 Allergene. Auch ausgelistete Artikel (Link aus einer älteren Liste). Null, wenn
     * der Artikel für das Team nicht sichtbar ist.
     */
    public function artikel(Team $team, int $id): ?FoodAlchemistSupplierItem
    {
        $items = app(SupplierItemService::class);
        $item = $items->byIds($team, [$id], ['onlyActive' => false])->first();
        if ($item === null) {
            return null;
        }
        $item->loadMissing('supplier:id,name');
        $item->setAttribute('vergleichspreis', app(PriceService::class)->vergleichspreis($item, $item->aktiver_preis !== null ? (float) $item->aktiver_preis : null));
        $item->setAttribute('allergen_werte', $items->getAllergens($item));

        return $item;
    }
}
