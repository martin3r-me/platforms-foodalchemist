<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAromaService;
use Platform\FoodAlchemist\Services\SensorikService;

/**
 * Live-Abnahme „Geschmacksprofil = Wahrnehmung" (Dominique 29.10.2026). NUR LESEND: schätzt die Referenzfälle mit der echten KI,
 * speichert nichts, und vergleicht mit den freigegebenen Spannen (Plan 00_INBOX/_Plan_Geschmacksprofil_Wahrnehmung_2026-10-29.md).
 *
 *   --gp=amerikaner:123 --gp=brezel:456 …   je Referenzfall die GP-ID auf diesem System
 *   --rezept=roastbeef:789 --laeufe=2        Rezept-Fall, mehrfach; zusätzlich: Umami ≥ Salzig und gleiche Dominanten in allen Läufen
 */
class GpSensorikReferenzCommand extends Command
{
    protected $signature = 'foodalchemist:gp-sensorik-referenz
        {--admin= : E-Mail des Nutzers, in dessen Namen die KI läuft (Call-Log, Budget, Kill-Switch)}
        {--gp=* : fall:gp_id (Fälle: amerikaner, brezel, parmesan, schokolade, chips)}
        {--rezept=* : fall:recipe_id (Fall: roastbeef)}
        {--laeufe=2 : Läufe je Rezept-Fall (Stabilität)}';

    protected $description = 'Live-Abnahme Geschmacksprofil = Wahrnehmung: Referenzfälle mit echter KI schätzen (nur lesend) und gegen die Spannen prüfen';

    /** Freigegebene Spannen [min, max] je Achse (Dominique 29.10.2026). Nicht genannte Achsen werden nicht geprüft. */
    public const SPANNEN = [
        'amerikaner' => ['suess' => [0.60, 0.85], 'salzig' => [0.00, 0.25], 'fettig' => [0.20, 0.45]],
        'brezel' => ['suess' => [0.00, 0.15], 'salzig' => [0.60, 0.90], 'fettig' => [0.00, 0.20], 'bitter' => [0.00, 0.30]],
        'parmesan' => ['suess' => [0.00, 0.15], 'salzig' => [0.50, 0.80], 'fettig' => [0.40, 0.70], 'umami' => [0.80, 1.00]],
        'schokolade' => ['suess' => [0.35, 0.65], 'salzig' => [0.00, 0.10], 'fettig' => [0.40, 0.70], 'bitter' => [0.50, 0.80]],
        'chips' => ['suess' => [0.00, 0.20], 'salzig' => [0.50, 0.80], 'fettig' => [0.60, 0.90], 'umami' => [0.10, 0.40]],
        'roastbeef' => ['umami' => [0.55, 0.85], 'salzig' => [0.20, 0.50], 'fettig' => [0.20, 0.50], 'scharf' => [0.10, 0.30]],
    ];

    public function handle(GpAromaService $aroma, SensorikService $sensorik): int
    {
        $mail = strtolower(trim((string) $this->option('admin')));
        $user = $mail !== '' ? User::whereRaw('LOWER(email) = ?', [$mail])->first() : null;
        if ($user === null) {
            $this->error('--admin=<E-Mail> fehlt oder ist unbekannt (die KI läuft im Namen eines Nutzers).');

            return self::FAILURE;
        }
        Auth::login($user);

        $zeilen = [];
        $alleOk = true;
        foreach ($this->paare('gp') as [$fall, $id]) {
            $gp = FoodAlchemistGp::with('commodity_group')->find($id);
            $v = $gp !== null ? $aroma->schaetze($gp) : null;
            if ($v === null) {
                $zeilen[] = [$fall, "GP {$id}", '—', 'kein Ergebnis'];
                $alleOk = false;

                continue;
            }
            [$text, $ok] = $this->pruefe($fall, $v['werte']);
            $alleOk = $alleOk && $ok;
            $zeilen[] = [$fall, $gp->name, $text, ($ok ? 'im Rahmen' : 'außerhalb') . ($v['eingriffe'] !== [] ? ' · ' . implode(' ', $v['eingriffe']) : '')];
        }
        foreach ($this->paare('rezept') as [$fall, $id]) {
            $dominanten = [];
            for ($i = 1; $i <= max(1, (int) $this->option('laeufe')); $i++) {
                $v = $sensorik->schaetzeRezept($id);
                if ($v === null) {
                    $zeilen[] = ["{$fall} #{$i}", "Rezept {$id}", '—', 'kein Ergebnis'];
                    $alleOk = false;

                    continue;
                }
                [$text, $ok] = $this->pruefe($fall, $v['geschmack']);
                $umamiVorn = (float) ($v['geschmack']['umami'] ?? 0) >= (float) ($v['geschmack']['salzig'] ?? 0);
                $dominanten[] = implode(',', array_keys(array_filter($v['geschmack'], fn ($w) => $w >= 0.6)));
                $alleOk = $alleOk && $ok && ($fall !== 'roastbeef' || $umamiVorn);
                $zeilen[] = ["{$fall} #{$i}", "Rezept {$id}", $text, ($ok ? 'im Rahmen' : 'außerhalb') . ($fall === 'roastbeef' ? ($umamiVorn ? ' · Umami ≥ Salzig' : ' · Umami < Salzig') : '')];
            }
            $stabil = count(array_unique($dominanten)) <= 1;
            $alleOk = $alleOk && $stabil;
            $zeilen[] = ["{$fall}", 'Stabilität', 'Dominanten: ' . implode(' | ', $dominanten), $stabil ? 'gleich' : 'abweichend'];
        }
        if ($zeilen === []) {
            $this->error('Keine Fälle: --gp=fall:id bzw. --rezept=fall:id angeben.');

            return self::FAILURE;
        }
        $this->table(['Fall', 'Gegenstand', 'Werte (Spanne)', 'Ergebnis'], $zeilen);
        $this->line($alleOk ? 'Alle Fälle im Rahmen.' : 'Mindestens ein Fall außerhalb, Details oben.');

        return $alleOk ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array{0: string, 1: int}> */
    private function paare(string $option): array
    {
        $out = [];
        foreach ((array) $this->option($option) as $roh) {
            [$fall, $id] = array_pad(explode(':', (string) $roh, 2), 2, null);
            if (! isset(self::SPANNEN[$fall]) || ! ctype_digit((string) $id)) {
                $this->warn("Übersprungen: {$roh} (fall:id, Fälle: " . implode(', ', array_keys(self::SPANNEN)) . ')');

                continue;
            }
            $out[] = [$fall, (int) $id];
        }

        return $out;
    }

    /** @return array{0: string, 1: bool} */
    private function pruefe(string $fall, array $werte): array
    {
        $teile = [];
        $ok = true;
        foreach (self::SPANNEN[$fall] as $dim => [$min, $max]) {
            $w = (float) ($werte[$dim] ?? 0);
            $drin = $w >= $min - 1e-9 && $w <= $max + 1e-9;
            $ok = $ok && $drin;
            $teile[] = SensorikService::DIM_LABEL[$dim] . ' ' . number_format($w, 2, ',', '') . " ({$min}–{$max})" . ($drin ? '' : ' ✗');
        }

        return [implode(' · ', $teile), $ok];
    }
}
