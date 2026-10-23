<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Panels;

use Livewire\Component;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec 85 F5 (Dominique 19.10.) — „Preisänderungen seit Veröffentlichung“. Kundenschutz ist das Einfrieren der Preise beim
 * Veröffentlichen eines Links; eine eigene VK-Freigabe gibt es nicht mehr (ersetzt das frühere Panel „Verkaufspreise freigeben“).
 *
 * Je veröffentlichtem Link (Foodbook, Speisekarte, Speiseplan, Angebot; Team-Link oder Betrieb) die Preise, die über die
 * Team-Leitplanke vom aktuell gerechneten VK abweichen. Quelle sind die offenen Signale `vk_anpassung_empfohlen` samt Payload
 * ({@see \Platform\FoodAlchemist\Services\AusgabeDriftService}) — billig beim Seitenaufruf; die teure Neuberechnung (ein
 * Snapshot-Bau je Link) läuft nur auf „Jetzt prüfen“. Abhilfe: im Editor neu veröffentlichen mit „Preise aktualisieren“.
 */
class LinkPreise extends Component
{
    use \Platform\FoodAlchemist\Livewire\Controlling\Concerns\ControllingTeam;

    public ?string $hinweis = null;

    private const EDITOR = [
        PresentationService::TYPE_FOODBOOK => ['foodalchemist.foodbooks.index', 'fb', 'Foodbook'],
        PresentationService::TYPE_SPEISEKARTE => ['foodalchemist.speisekarte.index', 'sk', 'Speisekarte'],
        PresentationService::TYPE_SPEISEPLAN => ['foodalchemist.speiseplan.index', 'sp', 'Speiseplan'],
        PresentationService::TYPE_ANGEBOT => ['foodalchemist.angebote.index', 'sel', 'Angebot'],
    ];


    public function jetztPruefen(SignalDetektorService $detektor): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        if (($recht = $this->rolleFehlt($team, FaRolle::Kuratieren, 'Ausgabe-Links prüfen')) !== null) {
            $this->hinweis = $recht;

            return;
        }
        $n = $detektor->ausgabeDriftPruefen($team);
        $this->hinweis = $n === 0 ? 'Geprüft: alle veröffentlichten Links tragen aktuelle Preise.'
            : 'Geprüft: ' . $n . ($n === 1 ? ' Link zeigt' : ' Links zeigen') . ' Preise, die von der Kalkulation abweichen.';
    }

    public function render(TeamSettingsService $settings)
    {
        $team = $this->team();
        $links = [];
        if ($team !== null) {
            $signale = FoodAlchemistSignal::query()->where('team_id', $team->id)->offen()
                ->typ(SignalTyp::VkAnpassungEmpfohlen->value)->with('outlet:id,name')->get();
            foreach ($signale as $s) {
                $p = (array) $s->payload;
                [$route, $param, $art] = self::EDITOR[$p['doc_type'] ?? ''] ?? [null, null, (string) ($p['doc_type'] ?? 'Ausgabe')];
                $links[] = [
                    'id' => (int) $s->id,
                    'titel' => (string) $s->title,
                    'art' => $art,
                    'betrieb' => $s->outlet?->name,
                    'max_delta_pct' => (float) ($p['max_delta_pct'] ?? 0),
                    'anzahl' => (int) ($p['anzahl'] ?? 0),
                    'zeilen' => array_values((array) ($p['zeilen'] ?? [])),
                    'url' => $route !== null && isset($p['doc_id']) ? route($route, [$param => (int) $p['doc_id']]) : null,
                ];
            }
            usort($links, fn ($a, $b) => $b['max_delta_pct'] <=> $a['max_delta_pct']);
        }

        return view('foodalchemist::livewire.controlling.panels.link-preise', [
            'links' => $links,
            'schwelle' => $team !== null ? $settings->maxVkDeltaPct($team) : null,
        ]);
    }
}
