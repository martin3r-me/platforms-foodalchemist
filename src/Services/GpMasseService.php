<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\NonFoodMasse;
use RuntimeException;

/**
 * Maße eines Non-Food-Grundprodukts (Warengruppe 16) pflegen. Eingabe in cm bzw. ml wie im Betrieb gesagt, gespeichert in mm. Nur Warengruppe 16:
 * ein Lebensmittel hat keine Maße (Größe ist dort Teil der Pflichtangaben §8). Der LA erbt die Maße nur zur Anzeige.
 */
class GpMasseService
{
    /**
     * @param  array<string, mixed>  $eingabe  Schlüssel diameter_cm, length_cm, width_cm, height_cm, volume_ml (leer = Maß löschen; fehlender Schlüssel = unverändert)
     */
    public function setze(FoodAlchemistGp $gp, array $eingabe): FoodAlchemistGp
    {
        $felder = array_intersect_key($eingabe, NonFoodMasse::EINGABE);
        if ($felder === []) {
            return $gp;
        }
        if (! $gp->istNonFood()) {
            throw new RuntimeException('Maße gibt es nur bei Non-Food-Grundprodukten (Warengruppe 16).');
        }
        $update = [];
        foreach ($felder as $schluessel => $wert) {
            $spalte = NonFoodMasse::EINGABE[$schluessel];
            if (trim((string) ($wert ?? '')) === '') {
                $update[$spalte] = null;

                continue;
            }
            $zahl = NonFoodMasse::lese($wert);
            if ($zahl === null) {
                throw new RuntimeException("Maß {$schluessel} muss eine Zahl größer 0 sein.");
            }
            $update[$spalte] = $spalte === 'volume_ml' ? round($zahl, 1) : round($zahl * 10, 1);
        }
        $gp->update($update);

        return $gp->refresh();
    }

    /**
     * Servier-Typ des Gefäßes (Vokabular Servier-Vehikel) setzen — EINMAL am GP (nur WG 16). Eingabe: Id oder Name; leer löscht.
     * Alle Darreichungen mit diesem Gefäß lesen den Typ von hier ({@see DarreichungService::vehikelId}).
     */
    public function setzeServierTyp(FoodAlchemistGp $gp, int|string|null $idOderName): FoodAlchemistGp
    {
        if (! $gp->istNonFood()) {
            throw new RuntimeException('Einen Servier-Typ tragen nur Non-Food-Grundprodukte (Warengruppe 16).');
        }
        if ($idOderName === null || trim((string) $idOderName) === '') {
            $gp->update(['serving_vehicle_vocab_id' => null]);

            return $gp->refresh();
        }
        $q = \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_serving_vehicles')->whereNull('deleted_at')->where('is_inactive', false);
        $id = is_numeric($idOderName)
            ? $q->where('id', (int) $idOderName)->value('id')
            : $q->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $idOderName))])->value('id');
        if ($id === null) {
            throw new RuntimeException('Servier-Typ „'.$idOderName.'“ gibt es im Vokabular nicht.');
        }
        $gp->update(['serving_vehicle_vocab_id' => (int) $id]);

        return $gp->refresh();
    }

    /** Die Größe für den Namen aus den gespeicherten Maßen (Ø → L×B×H → Volumen); null ohne Maß. */
    public function groesse(FoodAlchemistGp $gp): ?string
    {
        return NonFoodMasse::groesse($gp->only(NonFoodMasse::FELDER));
    }

    /** Größe aus der Eingabe in cm/ml (z. B. aus dem Formular, bevor gespeichert ist). */
    public function groesseAusEingabe(array $eingabe): ?string
    {
        $mm = [];
        foreach (NonFoodMasse::EINGABE as $schluessel => $spalte) {
            $zahl = NonFoodMasse::lese($eingabe[$schluessel] ?? null);
            $mm[$spalte] = $zahl === null ? null : ($spalte === 'volume_ml' ? $zahl : $zahl * 10);
        }

        return NonFoodMasse::groesse($mm);
    }
}
