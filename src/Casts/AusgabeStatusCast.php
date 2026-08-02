<?php

namespace Platform\FoodAlchemist\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Platform\FoodAlchemist\Enums\AusgabeStatus;

/**
 * Spec 33 · P0 — toleranter Cast für den Betriebs-Status der Ausgabeformen.
 *
 * **Warum nicht einfach `AusgabeStatus::class` als Cast?** Weil der eingebaute Enum-Cast bei
 * jedem unbekannten Wert eine Exception wirft — und unbekannte Werte sind hier keine Theorie:
 * im Bestand lag ein `final`, das Foodbook-Dropdown schrieb `active`, der Speiseplan `draft`.
 * Ein strikter Cast hätte jede Liste zerlegt, in der ein einziger Altdatensatz auftaucht. Genau
 * die Brüchigkeit, gegen die diese Spec antritt, wäre damit nur verschoben worden.
 *
 * Dieser Cast bildet stattdessen in **beide** Richtungen ab:
 *
 * - **Lesen:** alles, was in der Spalte steht, wird über {@see AusgabeStatus::normalisiere}
 *   auf einen gültigen Fall gehoben. Unbekanntes wird `entwurf` — der einzige Zustand, der
 *   nichts behauptet.
 * - **Schreiben:** ebenso. Damit kann auch ein direkter `Model::create(['status' => 'draft'])`
 *   (Seeder, Test-Fixture, Alt-Code) keinen ungültigen Wert mehr in die Datenbank legen. Die
 *   Normalisierungs-Migration räumt den Bestand auf, dieser Cast hält ihn sauber.
 *
 * Der Preis: `$model->status` gibt nie exakt zurück, was in der Spalte stand. Das ist gewollt —
 * wer den Rohwert braucht (Migration, Protokoll), liest `getRawOriginal('status')`.
 */
class AusgabeStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): AusgabeStatus
    {
        return $value instanceof AusgabeStatus ? $value : AusgabeStatus::normalisiere(is_string($value) ? $value : null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof AusgabeStatus) {
            return $value->value;
        }

        return AusgabeStatus::normalisiere(is_string($value) ? $value : null)->value;
    }
}
