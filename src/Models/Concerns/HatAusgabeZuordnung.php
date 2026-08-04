<?php

namespace Platform\FoodAlchemist\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * Spec 33 · P2 — die zwei Zuordnungsachsen einer Ausgabeform.
 *
 * **Betrieb** (`outlet_id`) und **Kunde** (`crm_company_id` / `crm_contact_id`),
 * beide optional, an allen drei Formen. Das Leitbild ist die Mehrbetriebs-Sicht: welcher
 * Standort fährt gerade was — und im Betreibermodell zusätzlich: für welchen Kunden.
 *
 * Beide optional zu lassen ist Absicht. Eine freistehende Karte ohne Standort und ohne Kunde
 * muss anlegbar bleiben; sie taucht in der Portfolio-Übersicht dann im Block „ohne Zuordnung"
 * auf, statt still aus beiden Brillen zu fallen.
 *
 * Der CRM-Zeiger ist bewusst **kein Fremdschlüssel** (Muster `2026_06_16_000112`): CRM ist ein
 * eigenständiges Modul, Cross-Modul-Zugriff läuft über Resolver. Die Relation hier ist die
 * bequeme Lesehilfe, nicht die Architektur-Aussage.
 */
trait HatAusgabeZuordnung
{
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOutlet::class, 'outlet_id');
    }

    public function crmCompany(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CrmCompany::class, 'crm_company_id');
    }

    public function crmContact(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CrmContact::class, 'crm_contact_id');
    }

    /**
     * Ist diese Ausgabe überhaupt zugeordnet? Ohne Standort UND ohne Kunde erscheint sie in
     * keiner der beiden Brillen — die Übersicht muss sie darum getrennt ausweisen.
     */
    public function hatZuordnung(): bool
    {
        return ($this->outlet_id ?? null) !== null
            || ($this->crm_company_id ?? null) !== null;
    }

    /**
     * Kunden-Bezeichnung für die Anzeige: ausschließlich der verknüpfte CRM-Name.
     */
    public function kundeLabel(): ?string
    {
        if (($this->crm_company_id ?? null) !== null && $this->relationLoaded('crmCompany')) {
            $name = trim((string) ($this->crmCompany?->name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
