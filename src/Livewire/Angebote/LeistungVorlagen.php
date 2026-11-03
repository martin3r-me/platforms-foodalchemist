<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistLeistungVorlage;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;
use Platform\FoodAlchemist\Services\LeistungVorlageService;

/**
 * Spec 94 · Angebote → Leistungs-Vorlagen. Personal, Equipment, Logistik und Sonstiges einmal mit Mengenregel, EK und VK
 * pflegen; Konfiguratoren übernehmen sie als Kopie. Geerbte Vorlagen (Oberteam) sind lesbar, ändern kann nur das Besitzer-Team.
 * Schreiben nur über den LeistungVorlageService.
 */
class LeistungVorlagen extends Component
{
    public string $suche = '';

    public string $kategorieFilter = '';

    /** null = Formular zu; 0 = neue Vorlage; sonst die bearbeitete Vorlage. */
    public ?int $editId = null;

    public array $form = [];

    /** Stufen als Text: je Zeile „bis Gäste; Anzahl“, leeres „bis“ = offen. */
    public string $stufenText = '';

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function neu(string $kategorie = ''): void
    {
        $this->reset('fehler', 'hinweis', 'stufenText');
        $this->editId = 0;
        $auswahl = app(\Platform\FoodAlchemist\Services\Konfigurator\LeistungsKategorieService::class)->auswahl($this->team());
        $this->form = self::leereRegel() + ['kategorie' => isset($auswahl[$kategorie]) ? $kategorie : (string) array_key_first($auswahl)];
    }

    public function bearbeiten(int $id, LeistungVorlageService $svc): void
    {
        $this->reset('fehler', 'hinweis');
        $v = $svc->finde($this->team(), $id);
        if (! $v->isOwnedBy($this->team())) {
            $this->fehler = 'Diese Vorlage ist geerbt. Ändern kann sie nur das Team, dem sie gehört. Lege bei Bedarf eine eigene an.';

            return;
        }
        $this->editId = $v->id;
        $this->form = self::alsFormular($v) + ['aktiv' => (bool) $v->aktiv];
        $this->stufenText = self::stufenAlsText($v->stufen_json);
    }

    public function abbrechen(): void
    {
        $this->reset('editId', 'form', 'stufenText', 'fehler');
    }

    public function speichern(LeistungVorlageService $svc): void
    {
        $this->fehler = null;
        $eingabe = $this->form;
        $eingabe['stufen_json'] = self::stufenAusText($this->stufenText);
        try {
            $v = $this->editId ? $svc->aendern($this->team(), $this->editId, $eingabe) : $svc->anlegen($this->team(), $eingabe);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->hinweis = "„{$v->bezeichnung}“ gespeichert.";
        $this->reset('editId', 'form', 'stufenText');
    }

    public function aktivUmschalten(int $id, LeistungVorlageService $svc): void
    {
        $this->fehler = null;
        try {
            $v = $svc->finde($this->team(), $id);
            $svc->aendern($this->team(), $id, ['aktiv' => ! $v->aktiv]);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function loeschen(int $id, LeistungVorlageService $svc): void
    {
        $this->fehler = null;
        try {
            $svc->loeschen($this->team(), $id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        if ($this->editId === $id) {
            $this->abbrechen();
        }
        $this->hinweis = 'Vorlage gelöscht. Übernommene Kopien in Konfiguratoren bleiben unverändert.';
    }

    public function render(LeistungVorlageService $svc, KonfiguratorPricingService $pricing)
    {
        $team = $this->team();
        $liste = $svc->liste($team, $this->kategorieFilter ?: null, $this->suche ?: null);
        $vorschau = null;
        if ($this->editId !== null && ($this->form['vk_modus'] ?? 'auto') === 'auto' && is_numeric(str_replace(',', '.', (string) ($this->form['ek_satz'] ?? '')))) {
            $klasse = ($this->form['markup_class_id'] ?? '') !== '' ? (int) $this->form['markup_class_id'] : null;
            $vorschau = $pricing->vkVorschlag($team, (float) str_replace(',', '.', (string) $this->form['ek_satz']), $klasse);
        }

        return view('foodalchemist::livewire.angebote.leistung-vorlagen', [
            'team' => $team,
            'gruppen' => $liste->groupBy('kategorie'),
            'gesamt' => $liste->count(),
            'kategorien' => app(\Platform\FoodAlchemist\Services\Konfigurator\LeistungsKategorieService::class)->auswahl($team),
            'kategorieNamen' => app(\Platform\FoodAlchemist\Services\Konfigurator\LeistungsKategorieService::class)->namen($team),
            'klassen' => FoodAlchemistMarkupClass::visibleToTeam($team)->bereich('leistung')->where('is_inactive', false)
                ->orderBy('code')->get(['id', 'code', 'label', 'class_factor_pct']),
            'bezugOptionen' => $liste->reject(fn ($v) => $v->id === $this->editId)->mapWithKeys(fn ($v) => [$v->id => $v->bezeichnung])->all(),
            'vorschau' => $vorschau,
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    /** Kurzbeschreibung der Mengenregel für die Liste, z. B. „1 je 30 Gäste, mind. 2 · ab 200 Gäste“. */
    public static function regelText(object $v): string
    {
        $zahl = fn ($w) => rtrim(rtrim(number_format((float) $w, 2, ',', ''), '0'), ',');
        $text = match ($v->mengen_regel) {
            // n = 1 ohne Zahl im Singular: „1 je Gast“, „1 je „Servicekraft““; sonst „1 je 30 Gäste“, „1 je 8 × „Servicekraft““
            'schluessel' => '1 je ' . ((float) $v->schluessel_n === 1.0
                    ? ($v->bezug === 'leistung' ? '„' . ($v->bezugLeistung?->bezeichnung ?? '?') . '“' : 'Gast')
                    : $zahl($v->schluessel_n) . ' ' . ($v->bezug === 'leistung' ? '× „' . ($v->bezugLeistung?->bezeichnung ?? '?') . '“' : 'Gäste'))
                . ($v->reserve_pct ? ' + ' . $zahl($v->reserve_pct) . ' %' : '')
                . ($v->min !== null ? ', mind. ' . $zahl($v->min) : '') . ($v->max !== null ? ', max. ' . $zahl($v->max) : ''),
            'stufen' => 'Stufen (' . count($v->stufen_json ?? []) . ')',
            default => $zahl($v->anzahl) . ' fest',
        };
        if ($v->ab_pax !== null || $v->bis_pax !== null) {
            $text .= ' · ' . ($v->ab_pax !== null ? 'ab ' . $v->ab_pax : '') . ($v->ab_pax !== null && $v->bis_pax !== null ? ' ' : '') . ($v->bis_pax !== null ? 'bis ' . $v->bis_pax : '') . ' Gäste';
        }

        return $text;
    }

    /** Regel-Felder einer Vorlage/Leistung als Formularwerte (Strings, leer statt null). */
    public static function alsFormular(object $v): array
    {
        return array_map(fn ($w) => is_array($w) ? $w : ($w === null ? '' : (string) $w), $v->only(FoodAlchemistLeistungVorlage::REGEL_FELDER));
    }

    /** Stufen als Text: je Zeile „bis Gäste; Anzahl“. */
    public static function stufenAlsText(?array $stufen): string
    {
        return collect($stufen ?? [])->map(fn ($s) => ($s['bis_pax'] ?? '') . '; ' . rtrim(rtrim(number_format((float) $s['anzahl'], 2, '.', ''), '0'), '.'))->implode("\n");
    }

    /** @return list<array{bis_pax:?string, anzahl:string}> */
    public static function stufenAusText(string $text): array
    {
        $stufen = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $zeile) {
            $teile = array_map('trim', explode(';', $zeile));
            if (count($teile) === 2 && $teile[1] !== '') {
                $stufen[] = ['bis_pax' => $teile[0] !== '' ? $teile[0] : null, 'anzahl' => str_replace(',', '.', $teile[1])];
            }
        }

        return $stufen;
    }

    /** Leeres Regel-Formular (Standardwerte). */
    public static function leereRegel(): array
    {
        return ['bezeichnung' => '', 'notiz' => '', 'mengen_regel' => 'fest', 'anzahl' => '1', 'bezug' => 'gaeste', 'bezug_leistung_id' => '',
            'schluessel_n' => '', 'min' => '', 'max' => '', 'ab_pax' => '', 'bis_pax' => '', 'reserve_pct' => '', 'preis_je' => 'stueck',
            'stunden_quelle' => 'einsatzzeit', 'stunden' => '', 'stunden_zuschlag' => '', 'haeufigkeit' => 'je_event', 'ek_satz' => '',
            'markup_class_id' => '', 'vk_satz' => '', 'vk_modus' => 'auto', 'vk_begruendung' => '', 'aktiv' => true];
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
