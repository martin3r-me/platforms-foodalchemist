<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistLeistungVorlage;
use Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorMengenRechner;
use Platform\FoodAlchemist\Services\Konfigurator\LeistungsRegelPruefung;

/**
 * Spec 94 · Leistungs-Vorlagen pflegen. Sicht über die Team-Kette (Oberteam pflegt, Unterteams lesen und legen eigene an),
 * ändern nur das Besitzer-Team. Der VK-Satz wird beim Speichern festgehalten: fest mit Begründung oder Vorschlag aus der
 * Leistungs-Aufschlagsklasse (KonfiguratorPricingService::vkSatz). Phase 1: nicht im Abo, nicht im Stamm-Paket.
 */
class LeistungVorlageService
{
    public function __construct(
        private LeistungsRegelPruefung $pruefung,
        private KonfiguratorPricingService $pricing,
        private KonfiguratorMengenRechner $rechner,
    ) {
    }

    /** @return Collection<int, FoodAlchemistLeistungVorlage> */
    public function liste(Team $team, ?string $kategorie = null, ?string $suche = null, bool $nurAktiv = false): Collection
    {
        return FoodAlchemistLeistungVorlage::visibleToTeam($team)
            ->with(['markupClass:id,code,label,class_factor_pct', 'bezugLeistung:id,bezeichnung'])
            ->when($kategorie !== null && $kategorie !== '', fn ($q) => $q->where('kategorie', $kategorie))
            ->when($suche !== null && trim($suche) !== '', fn ($q) => $q->where('bezeichnung', 'like', '%' . trim($suche) . '%'))
            ->when($nurAktiv, fn ($q) => $q->where('aktiv', true))
            ->orderBy('kategorie')->orderBy('position')->orderBy('bezeichnung')
            ->get();
    }

    public function finde(Team $team, int $id): FoodAlchemistLeistungVorlage
    {
        return FoodAlchemistLeistungVorlage::visibleToTeam($team)->findOrFail($id);
    }

    public function anlegen(Team $team, array $in): FoodAlchemistLeistungVorlage
    {
        $werte = $this->pruefung->pruefe($team, $in);
        $this->pruefeBezug($team, null, $werte);
        $vorlage = new FoodAlchemistLeistungVorlage($werte + [
            'team_id' => $team->id,
            'aktiv' => (bool) ($in['aktiv'] ?? true),
            'position' => (int) ($in['position'] ?? 0),
            'created_by' => Auth::id(),
        ]);
        $vorlage->vk_satz = $this->pricing->vkSatz($team, $vorlage);
        $vorlage->save();

        return $vorlage;
    }

    public function aendern(Team $team, int $id, array $in): FoodAlchemistLeistungVorlage
    {
        $vorlage = $this->finde($team, $id);
        GeerbtNurLesenException::wennNichtEigen($vorlage, $team, 'Leistungs-Vorlage');
        $werte = $this->pruefung->pruefe($team, $in, $vorlage->only(FoodAlchemistLeistungVorlage::REGEL_FELDER));
        $this->pruefeBezug($team, $vorlage->id, $werte);
        $vorlage->fill($werte);
        if (array_key_exists('aktiv', $in)) {
            $vorlage->aktiv = (bool) $in['aktiv'];
        }
        if (array_key_exists('position', $in)) {
            $vorlage->position = (int) $in['position'];
        }
        $vorlage->vk_satz = $this->pricing->vkSatz($team, $vorlage);
        $vorlage->save();

        return $vorlage;
    }

    public function loeschen(Team $team, int $id): void
    {
        $vorlage = $this->finde($team, $id);
        GeerbtNurLesenException::wennNichtEigen($vorlage, $team, 'Leistungs-Vorlage');
        $vorlage->delete();   // Kopien in Konfiguratoren bleiben unverändert; ihr Abgleich findet die Vorlage nicht mehr
    }

    /** Alle eigenen Vorlagen mit automatischem VK neu rechnen (nach Änderung von Basissatz oder Leistungsklasse). */
    public function vkNeuRechnen(Team $team): int
    {
        $n = 0;
        foreach (FoodAlchemistLeistungVorlage::where('team_id', $team->id)->where('vk_modus', 'auto')->get() as $vorlage) {
            $neu = $this->pricing->vkSatz($team, $vorlage);
            if ($neu !== ($vorlage->vk_satz !== null ? (float) $vorlage->vk_satz : null)) {
                $vorlage->update(['vk_satz' => $neu]);
                $n++;
            }
        }

        return $n;
    }

    /** Bezugs-Vorlage muss sichtbar sein und darf keinen Kreis schließen. */
    private function pruefeBezug(Team $team, ?int $eigeneId, array $werte): void
    {
        $bezugId = $werte['bezug_leistung_id'] ?? null;
        if ($bezugId === null) {
            return;
        }
        if ($bezugId === $eigeneId || ! FoodAlchemistLeistungVorlage::visibleToTeam($team)->whereKey($bezugId)->exists()) {
            throw new \InvalidArgumentException('Die Bezugs-Leistung gibt es nicht oder sie ist die Vorlage selbst.');
        }
        $bezuege = FoodAlchemistLeistungVorlage::visibleToTeam($team)->whereNotNull('bezug_leistung_id')
            ->pluck('bezug_leistung_id', 'id')->map(fn ($v) => (int) $v)->all();
        if ($eigeneId !== null) {
            $bezuege[$eigeneId] = (int) $bezugId;
        } else {
            $bezuege[0] = (int) $bezugId;
        }
        if ($this->rechner->findeKreis($bezuege) !== null) {
            throw new \InvalidArgumentException('Kreis in den Bezügen: zwei Leistungen hängen gegenseitig voneinander ab.');
        }
    }
}
