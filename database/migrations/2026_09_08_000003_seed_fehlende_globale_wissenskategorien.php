<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Spec 52 · Paket 2 — sechs Kategorien, die im Routing stehen, aber nie global geseedet wurden.
 *
 * `produktion_kapazitat`, `referenzgericht`, `weltkueche`, `signatur_kuechen`, `ernaehrung`
 * und `prasentation_service` tauchen in Routing-Zeilen auf — aber im Migrations-Seed des
 * Vokabulars fehlen sie. Auf demo existieren sie als **Team-Zeilen** (jemand hat sie per
 * `knowledge_categories.POST` nachgezogen), also fällt es dort nicht auf.
 *
 * Für einen **neuen Kunden** heisst das: die Routing-Zeilen sind Vorwärtsdeklarationen ohne
 * Wirkung, und `knowledge.POST` in einer dieser Kategorien scheitert an
 * `KnowledgeService::assertKategorie()` mit „Unbekannte Kategorie" — obwohl die Steuerung sie
 * kennt.
 *
 * **Nur anlegen, wenn der Slug NIRGENDS existiert** (kein Team, nicht global). Auf demo greift
 * die Migration damit gar nicht — es entstehen keine Dubletten neben den Team-Zeilen, und der
 * Bestand entscheidet weiter selbst. Ein späteres Zusammenführen Team-Zeile → global gehört
 * zur Mandanten-Kette und nicht hierher.
 */
return new class extends Migration
{
    /** slug => [label, beschreibung, sort_order] */
    private const KATEGORIEN = [
        'ernaehrung' => ['Ernährung & Diätformen', 'Diätformen, Allergien, DGE/Nährwert-Rahmen', 60],
        'weltkueche' => ['Weltküche', 'Länder- und Regionalküchen als Stil-Referenz', 70],
        'signatur_kuechen' => ['Signatur-Küchen', 'Chef-/Buch-Profile als Stil-Referenz', 80],
        'prasentation_service' => ['Präsentation & Service', 'Anrichten, Servierlogik, Gästeführung', 90],
        'produktion_kapazitat' => ['Produktion & Kapazität', 'Arbeitszeit, Personenminuten, Geräte-Durchsatz', 100],
        'referenzgericht' => ['Referenzgerichte', 'Vergleichsgerichte als Anhalt, kein Regelwerk', 110],
    ];

    public function up(): void
    {
        foreach (self::KATEGORIEN as $slug => [$label, $beschreibung, $sort]) {
            // Egal ob global oder Team-eigen: existiert der Slug, bleibt der Bestand.
            $existiert = DB::table('foodalchemist_knowledge_categories')
                ->where('slug', $slug)->whereNull('deleted_at')->exists();
            if ($existiert) {
                continue;
            }

            DB::table('foodalchemist_knowledge_categories')->insert([
                'uuid' => (string) UuidV7::generate(),
                'team_id' => null,
                'slug' => $slug,
                'label' => $label,
                'description' => $beschreibung,
                'sort_order' => $sort,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Nur die eigenen, global angelegten Zeilen — Team-Zeilen sind fremder Bestand.
        DB::table('foodalchemist_knowledge_categories')
            ->whereNull('team_id')
            ->whereIn('slug', array_keys(self::KATEGORIEN))
            ->delete();
    }
};
