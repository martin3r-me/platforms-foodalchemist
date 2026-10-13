<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;

/**
 * Spec 82 §6 — Standardausstattung im Equipment-Vokabular setzen (Dominique 13.10.: 15 Einträge). Eine Logik für Befehl und MCP.
 *
 * Idempotent: setzt genau diese Liste auf `is_standard = 1` und alle anderen Einträge auf 0. Gematcht wird nach Name
 * (Groß-/Kleinschreibung, Umlaute und Sonderzeichen egal), über alle Teams des Vokabulars. Der echte Lauf geht über die Modelle (damit das
 * activity-log die Änderungen sieht) und schreibt zusätzlich einen Klartext-Eintrag. Bewusst kein Migrationsschritt: ein Deploy ändert keine Daten.
 */
class EquipmentStandardService
{
    use PlattformAdminPruefung;

    /** Standardausstattung: gilt in jeder Küche als vorhanden, solange ein Team sie nicht ausdrücklich als fehlend markiert. */
    public const STANDARD = [
        'Topf', 'Kasserolle', 'Pfanne', 'Chinois / Passiersieb', 'Backblech', 'Bräter', 'Spritzbeutel', 'Kuchenform',
        'Stabmixer', 'Pürierstab', 'Mixer', 'Küchenmaschine / Planetenrührwerk', 'Induktionsplatte', 'Mörser', 'Mandoline / Hobel',
    ];

    public function __construct(private readonly PlattformProtokoll $protokoll) {}

    /** Für lesende Aufrufer (MCP): wirft FaRechtFehltException, wenn nicht Plattform-Admin. */
    public function nurPlattformAdminOeffentlich(?User $user, string $wofuer): User
    {
        return $this->nurPlattformAdmin($user, $wofuer);
    }

    /**
     * Abgleich (Trockenlauf) oder Schreiben.
     *
     * @return array{trockenlauf:bool, namen:int, neu:list<array{id:int,name:string}>, schon:list<array{id:int,name:string}>, zurueck:list<array{id:int,name:string}>, nicht_gefunden:list<string>}
     */
    public function setzen(bool $trockenlauf): array
    {
        $zeilen = DB::table('foodalchemist_vocab_kitchen_equipment')->whereNull('deleted_at')->get(['id', 'name', 'is_standard']);
        $soll = collect(self::STANDARD)->mapWithKeys(fn ($n) => [$this->norm($n) => $n]);

        $neu = $schon = $zurueck = [];
        $gefunden = [];
        foreach ($zeilen as $z) {
            $key = $this->norm((string) $z->name);
            $eintrag = ['id' => (int) $z->id, 'name' => (string) $z->name];
            if ($soll->has($key)) {
                $gefunden[$key] = true;
                (bool) $z->is_standard ? $schon[] = $eintrag : $neu[] = $eintrag;
            } elseif ((bool) $z->is_standard) {
                $zurueck[] = $eintrag;
            }
        }

        return ['trockenlauf' => $trockenlauf, 'namen' => count(self::STANDARD), 'neu' => $neu, 'schon' => $schon, 'zurueck' => $zurueck,
            'nicht_gefunden' => $soll->reject(fn ($n, $k) => isset($gefunden[$k]))->values()->all()];
    }

    /** Wie {@see setzen}, schreibt aber wirklich. Nur Plattform-Admin (ohne Benutzer oder mit anderer Rolle: FaRechtFehltException). */
    public function schreiben(?User $user): array
    {
        return $this->schreibe($this->nurPlattformAdmin($user, 'Standardausstattung setzen'));
    }

    /** Für den Konsolenbefehl (wer die Konsole hat, hat den Server): keine Benutzerprüfung. */
    public function schreibenAlsKonsole(): array
    {
        return $this->schreibe(null);
    }

    private function schreibe(?User $admin): array
    {
        $ergebnis = $this->setzen(false);

        $erstes = null;
        DB::transaction(function () use ($ergebnis, &$erstes) {
            foreach ([[$ergebnis['neu'], true], [$ergebnis['zurueck'], false]] as [$liste, $wert]) {
                foreach ($liste as $e) {
                    $modell = FoodAlchemistVocabKochequipment::find($e['id']);
                    $modell?->update(['is_standard' => $wert]);
                    $erstes ??= $modell;
                }
            }
        });
        if ($erstes !== null) {
            $this->protokoll->schreibe('equipment_standard_gesetzt', ['neu' => array_column($ergebnis['neu'], 'name'), 'zurueck' => array_column($ergebnis['zurueck'], 'name')], $admin, $erstes);
        }

        return $ergebnis;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    }
}
