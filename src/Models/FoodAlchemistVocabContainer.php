<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Behälter-Vokabular (GN, Eimer, Kanne, Euronorm-Kasten, Blech, Träger).
 * Bis Spec 51 gab es dafür kein Model — Editor und Einstellungen lasen die Tabelle per
 * `DB::table()`, und die einzige gepflegte Zahl (`kapazitaet_kg`) wurde NIRGENDS gerechnet.
 * Mit der Behälter-Bemessung braucht der Rechner Fläche und Tiefe getrennt, plus die
 * Freigaben je Zweck — das lässt sich nicht mehr sinnvoll als Array herumreichen.
 */
class FoodAlchemistVocabContainer extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    /** Die vier Zwecke — identisch mit `foodalchemist_recipe_containers.zweck`. */
    public const ZWECKE = ['abfuellen', 'regenerieren', 'ausgabe', 'transport'];

    protected $table = 'foodalchemist_vocab_containers';

    protected $guarded = ['id'];

    protected $casts = [
        'is_inactive' => 'boolean',
        'ist_traeger' => 'boolean',
        'eignung' => 'array',
    ];

    /**
     * Nutzbares Volumen in Litern bzw. Grundfläche in cm².
     *
     * Die Mathematik lebt in {@see BehaelterRechner} und NUR dort. Zwei Implementierungen
     * derselben Formel sind genau das Drift-Muster, das Spec 51 an den drei Regenerations-
     * Ablagen behebt — hier wird delegiert, nicht kopiert.
     */
    public function nutzvolumenL(): ?float
    {
        return \Platform\FoodAlchemist\Services\BehaelterRechner::nutzvolumenL($this);
    }

    public function grundflaecheCm2(): ?float
    {
        return \Platform\FoodAlchemist\Services\BehaelterRechner::grundflaecheCm2($this);
    }

    /**
     * Ist dieser Typ für den Zweck freigegeben?
     *
     * `eignung === null` heisst »noch nicht gepflegt«, nicht »verboten« — sonst wäre nach der
     * Migration jeder Bestandsbehälter gesperrt. Der Rechner behandelt das als »keine bekannte
     * Einschränkung« und sagt es dazu, statt eine Freigabe zu behaupten.
     */
    public function istFuerZweck(string $zweck): bool
    {
        return $this->eignung === null || in_array($zweck, (array) $this->eignung, true);
    }

    /** Explizit freigegeben (nicht bloss »nicht gepflegt«) — für Lücken-Meldungen. */
    public function istExplizitFreigegeben(string $zweck): bool
    {
        return is_array($this->eignung) && in_array($zweck, $this->eignung, true);
    }
}
