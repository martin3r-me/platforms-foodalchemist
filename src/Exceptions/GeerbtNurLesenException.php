<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 84 §4.0 · Sichtbar ist nicht eigen: Ein Team darf eine geerbte Zeile (Master, globaler Seed, Abo-Fassung) lesen,
 * aber nicht ändern. Eigener Typ statt Satz-Vergleich: Aufrufer unterscheiden am Typ, nicht am Wortlaut.
 * Erbt von RuntimeException, damit bestehende `catch (\RuntimeException)` die Meldung weiter anzeigen.
 */
final class GeerbtNurLesenException extends \RuntimeException
{
    public function __construct(public readonly string $was = 'Eintrag', ?string $meldung = null)
    {
        parent::__construct($meldung ?? "Geerbt: {$was} kann nur das Besitzer-Team ändern.");
    }

    /** Wirft, wenn `$zeile` dem Team nicht gehört (isOwnedBy, inkl. Master-Pflege globaler Basis-Kataloge). */
    public static function wennNichtEigen(object $zeile, \Platform\Core\Models\Team $team, string $was): void
    {
        if (! $zeile->isOwnedBy($team)) {
            throw new self($was);
        }
    }
}
