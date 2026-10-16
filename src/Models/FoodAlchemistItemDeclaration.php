<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description LA-Deklarationen (M2-15, GL-09-Quelle): 18 LMIV-Stoffe (+ freiwillig Jod) als rohe
 * Necta-Integer {0,1,3,NULL} (3=ja, 1=nein, 0/NULL=keine Angabe). Labels GL-09 §4.2.
 */
class FoodAlchemistItemDeclaration extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    /** GL-09 §4.2 — Spalte → LMIV-Kennzeichnung (DE) */
    public const STOFFE = [
        'with_dye' => 'mit Farbstoff',
        'with_preservative' => 'mit Konservierungsstoff',
        'with_antioxidant' => 'mit Antioxidationsmittel',
        'with_flavour_enhancer' => 'mit Geschmacksverstärker',
        'sulphurated' => 'geschwefelt',
        'blackened' => 'geschwärzt',
        'waxed' => 'gewachst',
        'with_phosphate' => 'mit Phosphat',
        'with_sweetener' => 'mit Süßungsmittel(n)',
        'contains_phenylalanine' => 'enthält eine Phenylalaninquelle',
        'excessive_consumption_laxative' => 'kann bei übermäßigem Verzehr abführend wirken',
        'packaged_modified_atmosphere' => 'unter Schutzatmosphäre verpackt',
        'caffeinated' => 'koffeinhaltig',
        'contains_milk_protein' => 'enthält Milcheiweiß',
        'contains_quinine' => 'chininhaltig',
        'taurine_containing' => 'taurinhaltig',
        'can_impair_attention_children' => 'kann Aktivität/Aufmerksamkeit bei Kindern beeinträchtigen',
        'with_type_sugar_sweetener' => 'mit Zuckerart(en) und Süßungsmittel(n)',
    ];

    /**
     * Freiwillige Angaben: gleiche Roh-Domäne {0,1,3,NULL} wie STOFFE, aber NIE Teil der Pflicht-Kennzeichnung, der
     * Zusatzstoff-Legende oder -Nummerierung (Entscheid Dominique 15.10.2026). Jod aus Necta `declarationIodhTlig`.
     *
     * Rechtliche Einordnung: freiwillige Angabe, keine ZZulV-Pflicht (bestätigt Dominique 16.10.2026).
     */
    public const FREIWILLIG = [
        'iodized' => 'jodiert',
    ];

    /**
     * MCP-Form der freiwilligen Angaben, getrennt von den Pflicht-Stoffen: [label => ja|nein|unbekannt] aus Rohwerten {3,1,0,NULL}.
     *
     * @param  callable(string):mixed  $wert  Feld → Rohwert (z. B. fn ($f) => $rezept->{"additive_{$f}"})
     * @return array<string,string>
     */
    public static function freiwilligFuerMcp(callable $wert): array
    {
        $out = [];
        foreach (self::FREIWILLIG as $feld => $label) {
            $out[$label] = match ((int) ($wert($feld) ?? 0)) {
                3 => 'ja',
                1 => 'nein',
                default => 'unbekannt',
            };
        }

        return $out;
    }

    /**
     * Freiwillige Angabe → abgeleitete Spalte am Lieferantenartikel (Jod aus der Zutatenliste, {@see \Platform\FoodAlchemist\Support\JodAusZutaten}).
     * Die GP-Aggregation nimmt je Artikel max(Necta-Flag, abgeleitet); der Necta-Rohwert bleibt unberührt.
     */
    public const FREIWILLIG_ABGELEITET = [
        'iodized' => 'iodized_abgeleitet',
    ];

    /** Wortlaut des freiwilligen Hinweises je Feld (Speisekarte, Etikett), nur bei eingeschaltetem Mandanten-Schalter. */
    public const FREIWILLIG_HINWEIS = [
        'iodized' => 'mit Jodsalz hergestellt',
    ];

    protected $table = 'foodalchemist_item_declarations';

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }
}
