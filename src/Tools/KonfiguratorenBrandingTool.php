<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfiguratoren.BRANDING */
class KonfiguratorenBrandingTool extends KonfiguratorToolBasis
{
    private const AUSGABE = ['brand_color', 'band_color', 'footer_text', 'logo_path', 'cover_image_path', 'bild_lunch_path', 'bild_dinner_path', 'bild_day_night_path', 'presentation_design', 'outlet_id'];

    protected function ressource(): string
    {
        return 'konfiguratoren';
    }

    protected function verb(): string
    {
        return 'BRANDING';
    }

    protected function beschreibung(): string
    {
        return 'Branding setzen (presentation_design; outlet_id = Betrieb, dessen Logo gilt, solange kein eigenes Logo hochgeladen ist, 0 = keiner; brand_color, band_color, footer_text werden nur gespeichert, die Farben des Links kommen aus dem Design) oder mit uebernehmen_von=foodbook|angebot|betrieb und quelle_id einmalig kopieren. bild_entfernen=logo|cover|lunch|dinner|day_night entfernt ein Bild (lunch/dinner/day_night = Bilder der Zeitfenster-Karten Tag, Abend, Tag und Abend; hochgeladen werden Bilder im Editor). Antwort nennt alle Bild-Pfade.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer'], 'brand_color' => ['type' => 'string'], 'band_color' => ['type' => 'string'], 'footer_text' => ['type' => 'string'], 'presentation_design' => ['type' => 'string'], 'outlet_id' => ['type' => 'integer', 'description' => 'Betrieb für den Logo-Rückfall; 0 = keiner'], 'uebernehmen_von' => ['type' => 'string', 'enum' => ['foodbook', 'angebot', 'betrieb']], 'quelle_id' => ['type' => 'integer'], 'bild_entfernen' => ['type' => 'string', 'enum' => \Platform\FoodAlchemist\Services\KonfiguratorService::BILD_ARTEN, 'description' => 'Bild entfernen: logo, cover oder Zeitfenster-Karte lunch|dinner|day_night']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Übernimm bei Konfigurator 3 das Branding von Foodbook 7.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $svc = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class);
        if (! empty($a['bild_entfernen'])) {
            $k = $svc->bildEntfernen($team, self::id($a), (string) $a['bild_entfernen']);

            return ['branding' => $k->only(self::AUSGABE)];
        }
        $k = ! empty($a['uebernehmen_von'])
            ? $svc->brandingUebernehmen($team, self::id($a), (string) $a['uebernehmen_von'], self::id($a, 'quelle_id'))
            : $svc->branding($team, self::id($a), array_key_exists('outlet_id', $a) && (int) $a['outlet_id'] === 0 ? ['outlet_id' => null] + $a : $a);

        return ['branding' => $k->only(self::AUSGABE)];
    }
}
