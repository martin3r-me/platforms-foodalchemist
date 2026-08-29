<?php

namespace Platform\FoodAlchemist\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Spec 43 — Public-Präsentations-Layer für die Ausgabeformen (Foodbook/Speisekarte/
 * Speiseplan). Trägt Token, Freigabe-Datum, Ablauf (Pflicht-Datum), eingefrorenen
 * Snapshot und die Design-Zuordnung. Semantik gespiegelt aus CorePublicFormLink:
 * live = enabled && veröffentlicht && Ablauf gesetzt & in der Zukunft.
 *
 * Casts werden via initializeHasPresentation() automatisch gemerged, damit alle drei
 * Head-Models nur `use HasPresentation` brauchen.
 */
trait HasPresentation
{
    public function initializeHasPresentation(): void
    {
        $this->mergeCasts([
            'presentation_enabled' => 'boolean',
            'presentation_published_at' => 'datetime',
            'presentation_expires_at' => 'datetime',
            'presentation_snapshot_json' => 'array',
            'presentation_settings_json' => 'array',
        ]);
    }

    /** Öffentliche Auflösung per Token — bewusst OHNE Team-Scope (Public-Pfad). */
    public function scopeByPresentationToken(Builder $query, string $token): Builder
    {
        return $query->where('presentation_token', $token);
    }

    /** Öffentliche Auflösung per Token ODER eigenem Link-Namen (Slug) — ohne Team-Scope. */
    public function scopeByPresentationRef(Builder $query, string $ref): Builder
    {
        return $query->where(function (Builder $q) use ($ref) {
            $q->where('presentation_token', $ref)->orWhere('presentation_slug', $ref);
        });
    }

    /** Öffentliche URL-Referenz: eigener Link-Name wenn gesetzt, sonst der Zufalls-Token. */
    public function presentationPublicRef(): ?string
    {
        return $this->presentation_slug ?: ($this->presentation_token ?: null);
    }

    /**
     * Ist der öffentliche Link gerade gültig? enabled + veröffentlicht + Ablauf gesetzt
     * und in der Zukunft. Pflicht-Datum: ohne expires_at ist ein Link nie live.
     */
    public function isPresentationLive(): bool
    {
        if (! $this->presentation_enabled) {
            return false;
        }
        if ($this->presentation_published_at === null) {
            return false;
        }
        if ($this->presentation_expires_at === null || $this->presentation_expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function presentationSettings(): array
    {
        return is_array($this->presentation_settings_json) ? $this->presentation_settings_json : [];
    }
}
