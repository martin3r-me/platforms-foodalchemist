<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\FoodAlchemist\Services\GpNamingService;
use Symfony\Component\Uid\UuidV7;

/**
 * Synthetische GP-Pakete für den Importer (E2) — Format wie Skript 300 (JSON-Lines + manifest.json, gp_anker.jsonl + gp_anker_manifest.json).
 * Kein Zugriff auf echte Daten.
 */
final class GpPaketBauer
{
    /** Eine GP-Zeile mit allen Whitelist-Spalten; `$ueber` überschreibt. */
    public static function gp(string $name, array $ueber = []): array
    {
        $slug = app(GpNamingService::class)->slugify($name);

        return array_merge([
            'uuid' => (string) UuidV7::generate(), 'gp_key' => $slug, 'name' => $name, 'main_ingredient_slug' => $slug,
            'main_ingredient_display' => trim((string) strtok($name, ':')), 'processing' => null, 'form' => null, 'commodity_group_code' => 4,
            'sub_category' => null, 'condition' => 'frisch', 'condition_source' => null, 'bio' => null, 'requires_la' => 1, 'is_platzhalter' => 0, 'is_derivat' => 0,
            'status' => 'approved', 'cooking_loss_default_pct' => null, 'trimming_loss_default_pct' => null, 'piece_default_g' => null, 'piece_default_g_source' => null,
            'diameter_mm' => null, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null, 'volume_ml' => null,
            'tag_is_vegan' => null, 'tag_is_vegetarian' => null, 'tag_is_halal' => null, 'tag_contains_pork' => null, 'tag_contains_beef' => null, 'tag_is_organic' => null,
            'tag_is_regional' => null, 'tag_is_staple_food' => null, 'tag_is_convenience' => null, 'tag_is_lactose_free' => null, 'tag_is_gluten_free' => null,
            'tag_source' => null, 'is_favorite' => 0, 'favorite_rank' => null,
            'derivat_von_gp_key' => null, 'preferred_count_unit_slug' => null, 'serving_vehicle_slug' => null,
        ], $ueber);
    }

    /** Eine Verknüpfungs-Zeile (Artikel über Necta-legacy_id). */
    public static function artikel(string $gpKey, int $legacy, bool $lead = false, array $struktur = []): array
    {
        return ['gp_key' => $gpKey, 'necta_legacy_id' => $legacy, 'lead' => $lead, 'struktur' => array_merge(['is_food' => 1, 'classifier' => 'test', 'needs_review' => 0], $struktur)];
    }

    /**
     * @param  list<array>  $gps
     * @param  list<array>  $artikel
     * @param  array<string,list<array>>  $kinder  Dateiname → Zeilen (gp_forms.jsonl, gp_textures.jsonl, gp_einheiten.jsonl, gp_aequivalente.jsonl)
     * @param  ?list<array>  $anker
     */
    public static function schreibe(string $dir, array $gps, array $artikel, array $kinder = [], ?array $anker = null): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        foreach (glob($dir . '/*') ?: [] as $alt) {
            @unlink($alt);
        }
        $dateien = ['gps.jsonl' => $gps, 'gp_artikel.jsonl' => $artikel] + $kinder;
        $sha = [];
        $zeilen = [];
        foreach ($dateien as $name => $rows) {
            self::jsonl($dir . '/' . $name, $rows);
            $sha[$name] = hash_file('sha256', $dir . '/' . $name);
            $zeilen[$name] = count($rows);
        }
        file_put_contents($dir . '/manifest.json', json_encode(['erzeugt_am' => date('c'), 'quelle' => 'test', 'regelwerk' => 'test', 'zeilen' => $zeilen, 'sha256' => $sha], JSON_UNESCAPED_UNICODE));
        if ($anker !== null) {
            self::jsonl($dir . '/gp_anker.jsonl', $anker);
            file_put_contents($dir . '/gp_anker_manifest.json', json_encode(['mappings' => count($anker), 'gps' => count(array_unique(array_column($anker, 'gp_key'))), 'sha256' => hash_file('sha256', $dir . '/gp_anker.jsonl')]));
        }
    }

    /** Eine Paketdatei nachträglich verändern, OHNE das Manifest nachzuziehen (Hash-Test). */
    public static function verfaelsche(string $dir, string $datei): void
    {
        file_put_contents($dir . '/' . $datei, "\n", FILE_APPEND);
        file_put_contents($dir . '/' . $datei, json_encode(['x' => 1]) . "\n", FILE_APPEND);
    }

    public static function jsonl(string $pfad, array $rows): void
    {
        file_put_contents($pfad, implode('', array_map(fn ($r) => json_encode($r, JSON_UNESCAPED_UNICODE) . "\n", $rows)));
    }
}
