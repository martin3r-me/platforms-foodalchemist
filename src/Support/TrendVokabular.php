<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Spec 79 · Vokabular des Trendradars nach Sarah Spork (BHG Trend Radar, 2026).
 * Einzige Quelle für Werte und Beschriftungen — Service, Tools, Livewire und Seeder lesen hier.
 */
final class TrendVokabular
{
    /** Trend = Tiefe, gesellschaftliches Bedürfnis, Dauer. Hype = Oberfläche, medial getrieben, kurzlebig (Kap. 2.1). */
    public const TYPEN = ['trend' => 'Trend', 'hype' => 'Hype'];

    /** Trendhierarchie nach Imbeck (Kap. 2.2), innen → außen. */
    public const EBENEN = ['mode' => 'Mode', 'konsum' => 'Konsum-/Branchentrend', 'mega' => 'Megatrend', 'meta' => 'Metatrend'];

    public const KATEGORIEN = ['food' => 'Food', 'getraenke' => 'Getränke', 'deko' => 'Deko', 'event' => 'Veranstaltungskonzepte'];

    /** Imbecks vier Food-Cluster (Kap. 4.1). */
    public const FOOD_CLUSTER = [
        'gesund_individuell' => 'Gesund und individuell',
        'genuss_gesundheit' => 'Genuss und Gesundheit',
        'funktionalitaet' => 'Funktionalität',
        'nachhaltigkeit' => 'Nachhaltigkeit / Neo-Ökologie',
    ];

    /** Veranstalter- und Teilnehmersicht getrennt (Kap. 5.3). */
    public const SICHTEN = ['veranstalter' => 'Veranstalter', 'teilnehmer' => 'Teilnehmer', 'beide' => 'Beide'];

    public const STATUS = [
        'gesichtet' => 'Gesichtet',
        'geprueft' => 'Geprüft',
        'auf_radar' => 'Auf dem Radar',
        'in_umsetzung' => 'In Umsetzung',
        'archiviert' => 'Archiviert',
        'verworfen' => 'Verworfen',
    ];

    /** Status, mit denen ein Trend im Radar erscheint. */
    public const RADAR_STATUS = ['auf_radar', 'in_umsetzung'];

    public const KONFIDENZ = ['hoch' => 'Hoch', 'mittel' => 'Mittel', 'niedrig' => 'Niedrig'];

    public const GARTNER_PHASEN = [
        'innovation_trigger' => 'Innovation Trigger',
        'peak' => 'Peak of Inflated Expectations',
        'trough' => 'Trough of Disillusionment',
        'slope' => 'Slope of Enlightenment',
        'plateau' => 'Plateau of Productivity',
    ];

    public const QUELLEN = [
        'marktforschung' => 'Marktforschung',
        'kaufverhalten' => 'Kaufverhalten',
        'befragung' => 'Mitarbeiterbefragung',
        'literatur' => 'Literatur',
        'branchenquelle' => 'Branchenquelle',
        'presse' => 'Fachpresse',
        'beobachtung' => 'Eigene Beobachtung',
        'instagram' => 'Instagram',
        'social_media' => 'Social Media (sonstige)',
        'google_trends' => 'Google Trends',
    ];

    /** Bestätigende Quellen (Kap. 3.3): Marktforschung, Kaufverhalten, interne Befragung. */
    public const HARTE_QUELLEN = ['marktforschung', 'kaufverhalten', 'befragung'];

    /** Reine Social-/Suchsignale: entdecken und messen, bestätigen nie allein (Kap. 3.3). */
    public const SOZIALE_QUELLEN = ['instagram', 'social_media', 'google_trends'];

    /** Erlaubte Dateien an einem Beleg (Screenshot, Foto, PDF). */
    public const DATEI_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/gif', 'application/pdf'];

    public const DATEI_MAX_KB = 15360;

    public static function label(array $liste, ?string $wert): ?string
    {
        return $wert === null ? null : ($liste[$wert] ?? $wert);
    }
}
