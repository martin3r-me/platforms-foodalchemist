<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unterkategorien tragen die Regelwerk-Nummer (Dominique 12.11.2026, wie Non-Food 16.x seit 27.10.).
 *
 * Anlass: Das Auswahlfeld „Unterkategorie“ im GP-Editor zeigte jede Unterkategorie doppelt — „Brot & Brötchen“ (verwaltete Liste,
 * am 16.10. ohne Nummer angelegt) und „09.1 Brot & Brötchen“ (Wert an den GPs). {@see VocabularyService::listSubCategories()} mischt beide.
 *
 * Der Lauf benennt verwaltete Einträge ohne Nummer in den nummerierten Wert um, den die GPs derselben Warengruppe tragen. Quelle der
 * Nummer sind die GP-Werte, nicht die Position (bei 15 fehlt 15.4: Wasser ist 15.5). Kein eindeutiger GP-Wert → Eintrag bleibt, wie er ist.
 * Gibt es den nummerierten Namen im selben Team schon, wird der Eintrag ohne Nummer gelöscht (soft), statt eine Dublette zu erzeugen.
 * Idempotent; GP-Daten werden nicht angefasst.
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_commodity_group_subcategories';

    public function up(): void
    {
        $gpWerte = DB::table('foodalchemist_gps')
            ->whereNull('deleted_at')->whereNotNull('sub_category')->whereNotNull('commodity_group_code')
            ->distinct()->get(['commodity_group_code', 'sub_category']);

        $zeilen = DB::table(self::TABELLE)->whereNull('deleted_at')->get(['id', 'team_id', 'commodity_group_code', 'name']);
        foreach ($zeilen as $z) {
            if (preg_match('/^\d{2}\.\d+ /u', (string) $z->name) === 1) {
                continue;
            }
            $muster = '/^' . preg_quote((string) $z->commodity_group_code, '/') . '\.\d+ ' . preg_quote(trim((string) $z->name), '/') . '$/u';
            $treffer = $gpWerte->where('commodity_group_code', $z->commodity_group_code)
                ->filter(fn ($w) => preg_match($muster, (string) $w->sub_category) === 1)
                ->pluck('sub_category')->unique()->values();
            if ($treffer->count() !== 1) {
                continue;
            }
            $neu = (string) $treffer->first();
            $gibtEs = DB::table(self::TABELLE)->whereNull('deleted_at')->where('id', '!=', $z->id)
                ->where('commodity_group_code', $z->commodity_group_code)->where('name', $neu)
                ->when($z->team_id === null, fn ($q) => $q->whereNull('team_id'), fn ($q) => $q->where('team_id', $z->team_id))
                ->exists();
            if ($gibtEs) {
                DB::table(self::TABELLE)->where('id', $z->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table(self::TABELLE)->where('id', $z->id)->update(['name' => $neu, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Bewusst leer: die Nummer ist der Regelwerk-Stand (§3); zurück auf den Namen ohne Nummer erzeugt wieder Dubletten im Auswahlfeld.
    }
};
