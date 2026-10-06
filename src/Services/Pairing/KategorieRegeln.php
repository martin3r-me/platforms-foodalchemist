<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Enums\Verfahren;
use Platform\FoodAlchemist\Enums\WissensStatus;

/**
 * Spec 60 · P4b (Entscheidung Dominique 2026-10-06): regelbasierte Werte aus Inspire-Kategorie
 * und Verfahren — als solche gekennzeichnet (quelle = kategorie), nie mit Dossier-Wissen vermischt.
 *
 * 1. Lieferseite der vier Achsen, die die Dossiers nicht als „liefert" erfassen. Hier fallen
 *    Kategorie und Funktion zusammen:
 *      Träger ← Reis, Pasta, Körner, Brot, Knollen, Hülsenfrüchte
 *      Frische ← Küchenkräuter, Zitrusfrüchte, Sprossen, Salate, Zitrusgewürze (nur roh/Grundform)
 *      kräftige Aromatik ← Gewürze (ohne Salz), Würzung, Tischsaucen, Kräuter
 *      Röstaroma ← Verfahren geröstet/gegrillt/gebraten/gebacken/geräuchert/karamellisiert, Kaffee
 * 2. Startwert der Aroma-Intensität (Aromakraft je Gramm) je Kategorie/Unterkategorie, nur wo
 *    noch kein Wert gesetzt ist. Wird an echten Rezepten kalibriert (P5).
 *
 * Regeln greifen über Präfix der Unterkategorie („Gemüse/Fruchtgemüse/Chili" vor „Gemüse").
 */
final class KategorieRegeln
{
    /** Achse → [Kategorie-Präfix => Stufe]. Längstes Präfix gewinnt. */
    public const LIEFERT = [
        'traeger' => [
            'Getreide/Reis' => 3, 'Getreide/Pasta' => 3, 'Getreide/Koerner' => 3, 'Backwaren/Brot' => 3,
            'Gemüse/Knollengemüse' => 2, 'Gemüse/Samengemüse' => 2,
        ],
        'frische' => [
            'Kräuter/Küchenkräuter' => 3, 'Obst/Zitrusfrüchte' => 3, 'Gemüse/Sprossen' => 2,
            'Gemüse/Blattgemüse/Salate' => 2, 'Gewuerze/Zitrusgewuerze' => 2,
        ],
        'aromatik' => [
            'Gewuerze' => 3, 'Gewuerze/Salz' => 0, 'Würzmittel/Würzung' => 2, 'Würzmittel/Tischsaucen' => 2,
            'Kräuter' => 2,
        ],
        'roestaroma' => [
            'Getränke/Alkoholfreie Getränke/Kaffee' => 3,
        ],
    ];

    /** Verfahren → Stufe Röstaroma. */
    public const ROESTAROMA_VERFAHREN = [
        'geroestet' => 3, 'gegrillt' => 3, 'gebraten' => 2, 'gebacken' => 2, 'geraeuchert' => 2, 'karamellisiert' => 2,
    ];

    /** Kategorie-Präfix → Aroma-Intensität (Faktor je Gramm). Längstes Präfix gewinnt. */
    public const INTENSITAET = [
        'Gewuerze' => 5.0, 'Gewuerze/Salz' => 0.0,
        'Kräuter' => 3.0, 'Würzmittel' => 3.0, 'Würzmittel/Essig' => 2.0,
        'Schokolade' => 3.0, 'Blüten' => 2.0, 'Aufstriche und Dips' => 2.0, 'Brühen und Fonds' => 1.5,
        'Gemüse' => 1.0, 'Gemüse/Fruchtgemüse/Chili' => 4.0, 'Gemüse/Zwiebelgemüse' => 2.0, 'Gemüse/Pilze' => 1.5,
        'Obst' => 1.5, 'Obst/Zitrusfrüchte' => 2.0,
        'Protein' => 1.0, 'Protein/Charcuterie' => 2.0,
        'Milchprodukte' => 1.0, 'Milchprodukte/Käse/Blauschimmelkaese' => 3.0, 'Milchprodukte/Käse/Hartkaese' => 2.0,
        'Milchprodukte/Käse/Rotschmierkaese' => 3.0, 'Milchprodukte/Milch' => 0.5, 'Milchprodukte/Ei' => 0.5,
        'Nüsse und Samen' => 1.5, 'Fette und Öle' => 1.0,
        'Süßungsmittel' => 1.0, 'Süßungsmittel/Zucker' => 0.2,
        'Getränke' => 1.5, 'Getränke/Alkoholfreie Getränke/Kaffee' => 3.0, 'Getränke/Alkoholfreie Getränke/Tee' => 2.0,
        'Getränke/Alkoholische Getränke/Spirituosen' => 3.0,
        'Getreide' => 0.5, 'Backwaren' => 0.5, 'Dessert' => 1.0, 'Süßwaren' => 1.5, 'Snacks' => 1.0,
    ];

    /** Lieferseite neu ableiten (ersetzt die eigenen Entwürfe, geprüfte/verworfene bleiben). */
    public function eigenschaften(): int
    {
        $anker = DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
            ->get(['id', 'category', 'subcategory', 'verfahren']);
        $bestand = DB::table('foodalchemist_anchor_eigenschaften')->where('quelle', 'kategorie')
            ->where('status', '!=', WissensStatus::Entwurf->value)->get(['anchor_id', 'achse'])
            ->mapWithKeys(fn ($r) => [$r->anchor_id.'|'.$r->achse => true])->all();

        $zeilen = [];
        $ts = now();
        foreach ($anker as $a) {
            $pfad = (string) ($a->subcategory ?? $a->category ?? '');
            $werte = [];
            foreach (self::LIEFERT as $achse => $regeln) {
                // Frische liefert nur die rohe Grundform — getrocknete/gegarte Kräuter sind nicht frisch.
                if ($achse === 'frische' && ! in_array($a->verfahren, [null, Verfahren::Roh->value], true)) {
                    continue;
                }
                $stufe = $this->praefix($pfad, $regeln);
                if ($stufe !== null && $stufe >= 2) {
                    $werte[$achse] = (int) $stufe;
                }
            }
            $roest = self::ROESTAROMA_VERFAHREN[(string) $a->verfahren] ?? null;
            if ($roest !== null) {
                $werte['roestaroma'] = max($werte['roestaroma'] ?? 0, $roest);
            }
            foreach ($werte as $achse => $stufe) {
                if (Achse::tryFrom($achse) === null || isset($bestand[$a->id.'|'.$achse])) {
                    continue;
                }
                $zeilen[] = ['anchor_id' => $a->id, 'achse' => $achse, 'stufe' => $stufe, 'quelle' => 'kategorie',
                    'beleg' => 'Regel: '.($roest !== null && $achse === 'roestaroma' ? 'Verfahren '.Verfahren::from((string) $a->verfahren)->label() : $pfad),
                    'status' => WissensStatus::Entwurf->value, 'created_at' => $ts, 'updated_at' => $ts];
            }
        }

        DB::transaction(function () use ($zeilen) {
            DB::table('foodalchemist_anchor_eigenschaften')->where('quelle', 'kategorie')
                ->where('status', WissensStatus::Entwurf->value)->delete();
            foreach (array_chunk($zeilen, 1000) as $c) {
                DB::table('foodalchemist_anchor_eigenschaften')->insert($c);
            }
        });

        return count($zeilen);
    }

    /** Startwerte der Aroma-Intensität — nur für Anker ohne Wert. */
    public function intensitaet(): int
    {
        $n = 0;
        foreach (DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')->whereNull('aroma_intensitaet')
            ->get(['id', 'category', 'subcategory']) as $a) {
            $wert = $this->praefix((string) ($a->subcategory ?? $a->category ?? ''), self::INTENSITAET);
            if ($wert === null) {
                continue;
            }
            DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $a->id)->update(['aroma_intensitaet' => $wert]);
            $n++;
        }

        return $n;
    }

    /**
     * Wert der längsten passenden Präfix-Regel.
     *
     * @param  array<string, int|float>  $regeln
     */
    private function praefix(string $pfad, array $regeln): int|float|null
    {
        $best = null;
        $laenge = -1;
        foreach ($regeln as $praefix => $wert) {
            if (($pfad === $praefix || str_starts_with($pfad, $praefix.'/')) && strlen($praefix) > $laenge) {
                [$best, $laenge] = [$wert, strlen($praefix)];
            }
        }

        return $best;
    }
}
