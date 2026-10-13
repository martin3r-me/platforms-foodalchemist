<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;

/**
 * Spec 82 §9 · Wem gehört ein Profil? Entweder dem Team selbst (standortId 0, das Teamprofil aus §6) oder einem Kunden-Standort
 * eines Projekts im Master-Team (standortId > 0, das Team ist dann das Master-Team). Eine Stelle, damit Lesen, Schreiben,
 * Machbarkeit und Kontext dieselbe Schlüsselung benutzen und kein Aufrufer `standort_id` von Hand vergessen kann.
 */
final class Profiltraeger
{
    private function __construct(public readonly Team $team, public readonly int $standortId)
    {
    }

    public static function team(Team $team): self
    {
        return new self($team, 0);
    }

    public static function standort(Team $master, int $standortId): self
    {
        if ($standortId < 1) {
            throw new \InvalidArgumentException('Ein Kunden-Standort hat eine ID ab 1.');
        }

        return new self($master, $standortId);
    }

    public static function von(Team|self $x): self
    {
        return $x instanceof self ? $x : self::team($x);
    }

    public function istStandort(): bool
    {
        return $this->standortId > 0;
    }

    /** Spalten, die jede Profil-Abfrage setzt. @return array{team_id:int, standort_id:int} */
    public function schluessel(): array
    {
        return ['team_id' => (int) $this->team->id, 'standort_id' => $this->standortId];
    }
}
