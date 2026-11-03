<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorLeistung;
use Platform\FoodAlchemist\Models\FoodAlchemistLeistungVorlage;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\LeistungVorlageService;

/**
 * Spec 94 · Konfigurator-Editor im Vollbild, aufgebaut wie der Angebots-Editor: Kennzahlen-Leiste, Reiter Übersicht ·
 * Konzepte · Leistungen · Pax-Pakete · Kalkulation · Branding und Präsentation, ein Speichern für Kopf und Regeln,
 * Bearbeitungssperre (Spec 65). Schreiben nur über KonfiguratorService, Zahlen nur aus dem KonfiguratorPricingService.
 */
class KonfiguratorEditor extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65

    public ?int $selectedId = null;

    /** Kopf und Regeln. */
    public array $form = [];

    public ?string $fehler = null;

    public ?string $hinweis = null;

    // ── Konzepte ──
    public string $conceptSuche = '';

    /** Ziel beim Hinzufügen: haupt_dinner | haupt_lunch | addon */
    public string $conceptZiel = 'haupt_dinner';

    public ?int $konzeptEditId = null;

    /** Spec 94c: Suche im Reiter Getränke (Concepts der Kategorie „Getränke“, sonst alle). */
    public string $getraenkSuche = '';

    /** Spec 94c: Zeitfenster des neuen Getränkepakets (je Zeitfenster ein eigenes Concept): dinner | lunch */
    public string $getraenkZiel = 'dinner';

    /** Schreibstil für „Speisen per KI betexten“ (Id als String, leer = keiner gewählt). */
    public string $wordingStil = '';

    public array $konzeptForm = [];

    // ── Leistungen ──
    /** null = zu, 0 = neue Leistung, sonst die bearbeitete. */
    public ?int $leistungEditId = null;

    public array $leistungForm = [];

    public string $leistungStufen = '';

    /** @var list<int|string> */
    public array $vorlageAuswahl = [];

    public string $vorlageZielKonzept = '';

    public string $vorlageGiltFuer = '';

    // ── Pakete ──
    public array $paketNeu = ['von_pax' => '', 'bis_pax' => '', 'bezeichnung' => ''];

    public array $vorschlagParameter = ['von' => '50', 'bis' => '1200', 'toleranz' => '', 'raster' => '25', 'mindestbreite' => '25'];

    /** @var list<array>|null */
    public ?array $vorschlag = null;

    /** Zuletzt an das Kind „Branding und Präsentation“ gemeldeter Sperr-Modus (es rendert sonst nicht neu). */
    public string $sperrModusGemeldet = '';

    protected function sperrZiel(): ?array
    {
        return $this->selectedId !== null ? ['konfigurator', $this->selectedId] : null;
    }

    protected function sperrModalName(): ?string
    {
        return 'konfigurator-editor';
    }

    protected function sperrFreiExtra(): array
    {
        return ['beiModalGeschlossen', 'vorschlagen', 'vorschlagVerwerfen', 'konzeptBearbeitenAbbrechen', 'leistungAbbrechen'];
    }

    #[On('modal.closed')]
    public function beiModalGeschlossen(?string $name = null): void
    {
        $this->sperreBeiSchliessen($name);
    }

    protected function nachAbbrechen(): void
    {
        $this->ladeForm();
        $this->reset('konzeptEditId', 'konzeptForm', 'leistungEditId', 'leistungForm', 'leistungStufen', 'vorschlag', 'fehler');
    }

    #[On('konfigurator-editor.oeffnen')]
    public function oeffnen(int $id): void
    {
        if ($this->selectedId !== null && $this->selectedId !== $id) {
            $this->bearbeitenBeenden();
        }
        $this->selectedId = $id;
        $this->reset('konzeptEditId', 'konzeptForm', 'leistungEditId', 'leistungForm', 'leistungStufen', 'vorschlag', 'fehler', 'hinweis', 'vorlageAuswahl', 'conceptSuche');
        $this->ladeForm();
        if ($this->selectedId !== null) {
            $this->dispatch('modal.open', name: 'konfigurator-editor');
        }
    }

    // ── Kopf ─────────────────────────────────────────────────────────────────────

    public function speichern(KonfiguratorService $svc): void
    {
        if ($this->tue(fn () => $svc->aendern($this->team(), $this->selectedId, $this->form))) {
            $this->hinweis = 'Gespeichert.';
            $this->dispatch('konfigurator-gespeichert');
            $this->bearbeitenBeenden();
        }
    }

    public function loeschen(KonfiguratorService $svc): void
    {
        $id = $this->selectedId;
        if ($this->tue(fn () => $svc->loeschen($this->team(), $id))) {
            $this->bearbeitenBeenden();
            $this->selectedId = null;
            $this->dispatch('modal.close', name: 'konfigurator-editor');
            $this->dispatch('konfigurator-geloescht', id: $id);
        }
    }

    // ── Konzepte ─────────────────────────────────────────────────────────────────

    public function conceptHinzu(int $conceptId, KonfiguratorService $svc): void
    {
        $in = match ($this->conceptZiel) {
            'addon' => ['rolle' => 'addon', 'concept_id' => $conceptId, 'addon_tageszeit' => 'beide'],
            'haupt_lunch' => ['rolle' => 'haupt', 'lunch_concept_id' => $conceptId],
            default => ['rolle' => 'haupt', 'dinner_concept_id' => $conceptId],
        };
        $titel = FoodAlchemistConcept::visibleToTeam($this->team())->whereKey($conceptId)->value('consumer_name')
            ?: FoodAlchemistConcept::visibleToTeam($this->team())->whereKey($conceptId)->value('name');
        if ($this->tue(fn () => $svc->konzeptHinzu($this->team(), $this->selectedId, $in + ['kunden_titel' => $titel]))) {
            $this->conceptSuche = '';
        }
    }

    /** Spec 94c: Concept als Getränkepaket für ein Zeitfenster hinzufügen (Pauschale je Event); das andere Zeitfenster per Bearbeiten. */
    public function getraenkHinzu(int $conceptId, KonfiguratorService $svc): void
    {
        $c = FoodAlchemistConcept::visibleToTeam($this->team())->whereKey($conceptId)->first(['id', 'name', 'consumer_name']);
        $zeit = $this->getraenkZiel === 'lunch' ? 'lunch_concept_id' : 'dinner_concept_id';
        if ($this->tue(fn () => $svc->konzeptHinzu($this->team(), $this->selectedId, ['rolle' => 'getraenk', $zeit => $conceptId,
            'getraenk_abrechnung' => 'pauschale', 'getraenk_dauer' => 'event', 'kunden_titel' => $c?->consumer_name ?: $c?->name]))) {
            $this->getraenkSuche = '';
        }
    }

    public function konzeptBearbeiten(int $id): void
    {
        $k = $this->konfigurator()?->konzepte->firstWhere('id', $id);
        if ($k === null) {
            return;
        }
        $this->konzeptEditId = $id;
        $this->konzeptForm = array_map(fn ($w) => $w === null ? '' : $w, $k->only(['lunch_concept_id', 'dinner_concept_id', 'kunden_titel', 'claim', 'abschnitt',
            'addon_tageszeit', 'gebunden_an_id', 'indikativ_lunch', 'indikativ_dinner', 'getraenk_abrechnung', 'getraenk_dauer', 'grund_stunden', 'folgestunde_satz']));
    }

    public function konzeptBearbeitenAbbrechen(): void
    {
        $this->reset('konzeptEditId', 'konzeptForm');
    }

    public function konzeptSpeichern(KonfiguratorService $svc): void
    {
        if ($this->tue(fn () => $svc->konzeptAendern($this->team(), (int) $this->konzeptEditId, $this->konzeptForm))) {
            $this->konzeptBearbeitenAbbrechen();
        }
    }

    public function konzeptEntfernen(int $id, KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->konzeptEntfernen($this->team(), $id));
    }

    public function konzeptVerschieben(int $id, int $richtung, KonfiguratorService $svc): void
    {
        $liste = $this->konfigurator()?->konzepte->values() ?? collect();
        $i = $liste->search(fn ($k) => $k->id === $id);
        $j = $i === false ? false : $i + ($richtung < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= $liste->count()) {
            return;
        }
        $this->tue(function () use ($svc, $liste, $i, $j) {
            $svc->konzeptAendern($this->team(), $liste[$i]->id, ['position' => $j]);
            $svc->konzeptAendern($this->team(), $liste[$j]->id, ['position' => $i]);
        });
    }

    /** Stufe 5b: Wording eines Gerichts nur für diesen Konfigurator (leer = zurück auf Concept-/Gericht-Wording). */
    public function wordingSetzen(int $konzeptId, int $slotId, mixed $text, KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->wordingSetzen($this->team(), $konzeptId, $slotId, (string) $text));
    }

    /** KI-Wording wie „Speisen neu betexten“ im Foodbook-Kapitel: Texte nur für diesen Konfigurator. */
    public function wordingGenerieren(int $konzeptId, KonfiguratorService $svc): void
    {
        if ($this->wordingStil === '') {
            $this->fehler = 'Bitte zuerst einen Schreibstil wählen.';

            return;
        }
        $n = 0;
        if ($this->tue(function () use ($svc, $konzeptId, &$n) {
            $n = $svc->wordingGenerieren($this->team(), $konzeptId, (int) $this->wordingStil);
        })) {
            $this->hinweis = $n > 0 ? "{$n} Gericht(e) im gewählten Stil betextet." : 'Keine Texte erhalten. Bitte später erneut versuchen.';
        }
    }

    public function wordingZuruecksetzen(int $konzeptId, int $slotId, KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->wordingSetzen($this->team(), $konzeptId, $slotId, null));
    }

    // ── Leistungen ───────────────────────────────────────────────────────────────

    public function leistungNeu(string $konzeptId = ''): void
    {
        $this->leistungEditId = 0;
        $this->leistungStufen = '';
        $this->leistungForm = LeistungVorlagen::leereRegel() + ['kategorie' => (string) array_key_first(app(\Platform\FoodAlchemist\Services\Konfigurator\LeistungsKategorieService::class)->auswahl($this->team())), 'konfigurator_konzept_id' => $konzeptId, 'gilt_fuer' => '', 'ersetzt_leistung_id' => ''];
    }

    public function leistungBearbeiten(int $id): void
    {
        $l = $this->konfigurator()?->leistungen->firstWhere('id', $id);
        if ($l === null) {
            return;
        }
        $this->leistungEditId = $id;
        $this->leistungForm = LeistungVorlagen::alsFormular($l) + array_map(fn ($w) => $w === null ? '' : (string) $w, $l->only(['konfigurator_konzept_id', 'gilt_fuer', 'ersetzt_leistung_id']));
        $this->leistungStufen = LeistungVorlagen::stufenAlsText($l->stufen_json);
    }

    public function leistungAbbrechen(): void
    {
        $this->reset('leistungEditId', 'leistungForm', 'leistungStufen');
    }

    public function leistungSpeichern(KonfiguratorService $svc): void
    {
        $in = $this->leistungForm + ['stufen_json' => LeistungVorlagen::stufenAusText($this->leistungStufen)];
        $ok = $this->tue(fn () => $this->leistungEditId
            ? $svc->leistungAendern($this->team(), (int) $this->leistungEditId, $in)
            : $svc->leistungHinzu($this->team(), $this->selectedId, $in));
        if ($ok) {
            $this->leistungAbbrechen();
        }
    }

    public function leistungEntfernen(int $id, KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->leistungEntfernen($this->team(), $id));
    }

    public function vorlagenUebernehmen(KonfiguratorService $svc): void
    {
        $ids = array_values(array_map('intval', array_filter($this->vorlageAuswahl)));
        if ($ids === []) {
            $this->fehler = 'Bitte mindestens eine Vorlage wählen.';

            return;
        }
        $ziel = $this->vorlageZielKonzept !== '' ? (int) $this->vorlageZielKonzept : null;
        $erg = null;
        if ($this->tue(function () use ($svc, $ids, $ziel, &$erg) {
            $erg = $svc->ausVorlagen($this->team(), $this->selectedId, $ids, $ziel, $this->vorlageGiltFuer ?: null);
        })) {
            $this->vorlageAuswahl = [];
            $this->hinweis = count($erg['leistungen']) . ' Leistung(en) übernommen.' . ($erg['warnungen'] !== [] ? ' ' . implode(' ', $erg['warnungen']) : '');
        }
    }

    public function vorlageAbgleich(int $leistungId, KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->vorlageAbgleich($this->team(), $leistungId));
    }

    public function alleVorlagenAbgleichen(KonfiguratorService $svc): void
    {
        $this->tue(function () use ($svc) {
            foreach ($svc->vorlageGeaendert($this->team(), $this->selectedId) as $id) {
                $svc->vorlageAbgleich($this->team(), $id);
            }
        });
    }

    // ── Pakete ───────────────────────────────────────────────────────────────────

    public function paketAnlegen(KonfiguratorService $svc): void
    {
        if ($this->tue(fn () => $svc->paketAnlegen($this->team(), $this->selectedId, $this->paketNeu))) {
            $this->paketNeu = ['von_pax' => '', 'bis_pax' => '', 'bezeichnung' => ''];
        }
    }

    public function paketAendern(int $paketId, string $feld, mixed $wert, KonfiguratorService $svc): void
    {
        if (! in_array($feld, ['von_pax', 'bis_pax', 'referenz_pax', 'bezeichnung'], true)) {
            return;
        }
        $this->tue(fn () => $svc->paketAendern($this->team(), $paketId, [$feld => $wert]));
    }

    public function paketEntfernen(int $paketId, KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->paketEntfernen($this->team(), $paketId));
    }

    public function schnueren(KonfiguratorPricingService $pricing): void
    {
        $bericht = null;
        if ($this->tue(function () use ($pricing, &$bericht) {
            $bericht = $pricing->schnueren($this->team(), $this->konfigurator());
        })) {
            $this->hinweis = "Pakete geschnürt: {$bericht['pakete']} Paket(e)" . ($bericht['abweichungen'] !== [] ? ', ' . count($bericht['abweichungen']) . ' Handwert(e) weichen von der Regel ab.' : '.');
        }
    }

    public function vorschlagen(KonfiguratorPricingService $pricing): void
    {
        $p = $this->vorschlagParameter;
        $this->tue(function () use ($pricing, $p) {
            if ((int) $p['von'] < 1 || (int) $p['bis'] < (int) $p['von']) {
                throw new \InvalidArgumentException('Gästebereich: „von“ ab 1, „bis“ nicht unter „von“.');
            }
            $this->vorschlag = $pricing->paketgrenzenVorschlagen($this->konfigurator(), (int) $p['von'], (int) $p['bis'],
                $p['toleranz'] !== '' ? (float) str_replace(',', '.', $p['toleranz']) : null, max(1, (int) $p['raster']), max(1, (int) $p['mindestbreite']));
        });
    }

    public function vorschlagVerwerfen(): void
    {
        $this->vorschlag = null;
    }

    public function vorschlagUebernehmen(KonfiguratorService $svc): void
    {
        if ($this->vorschlag === null) {
            return;
        }
        if ($this->tue(fn () => $svc->vorschlagUebernehmen($this->team(), $this->selectedId, $this->vorschlag))) {
            $this->vorschlag = null;
            $this->hinweis = 'Vorschlag übernommen, Pakete geschnürt.';
        }
    }

    /** Handwert in der Paket-Matrix; leerer Wert = zurück auf die Regel. */
    public function mengeSetzen(int $paketId, int $leistungId, mixed $wert, KonfiguratorService $svc): void
    {
        $wert = trim((string) $wert);
        $this->tue(fn () => $svc->mengeSetzen($this->team(), $paketId, $leistungId, null, $wert === '' ? null : (float) str_replace(',', '.', $wert)));
    }

    // ── Render ───────────────────────────────────────────────────────────────────

    public function render(KonfiguratorPricingService $pricing, KonfiguratorService $svc, LeistungVorlageService $vorlagen)
    {
        $team = $this->team();
        $k = $this->konfigurator();
        $daten = ['k' => $k, 'sperr' => $this->sperrZustand()];
        // Das Kind KonfiguratorPraesentation hält dieselbe Sperre, rendert aber nur bei eigenen Anfragen neu:
        // Wechsel des Modus (Bearbeiten, Speichern, Abbrechen, Ablauf) melden, sonst bleiben seine Felder gesperrt.
        if ($daten['sperr']['modus'] !== $this->sperrModusGemeldet) {
            $this->sperrModusGemeldet = $daten['sperr']['modus'];
            $this->dispatch('konfigurator-sperre')->to(KonfiguratorPraesentation::class);
        }
        if ($k !== null) {
            $k->load(['konzepte.lunchConcept:id,name,consumer_name,price_per_person_cache,price_per_person_manual,price_mode,ek_per_person_cache,price_override_expires_at',
                'konzepte.dinnerConcept:id,name,consumer_name,price_per_person_cache,price_per_person_manual,price_mode,ek_per_person_cache,price_override_expires_at',
                'leistungen', 'pakete.mengen']);
            $matrix = $pricing->matrix($k);
            $q = trim($this->conceptSuche);
            $daten += [
                'matrix' => $matrix,
                'kennzahlen' => self::kennzahlen($k, $matrix),
                'geaendert' => $svc->vorlageGeaendert($team, $k->id),
                'vorlagen' => $vorlagen->liste($team, null, null, true),
                'kategorien' => app(\Platform\FoodAlchemist\Services\Konfigurator\LeistungsKategorieService::class)->auswahl($team),
                'kategorieNamen' => app(\Platform\FoodAlchemist\Services\Konfigurator\LeistungsKategorieService::class)->namen($team),
                'einheiten' => \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->pluck('display_de', 'id')->all(),
                'klassen' => FoodAlchemistMarkupClass::visibleToTeam($team)->bereich('leistung')->where('is_inactive', false)->orderBy('code')->get(['id', 'code', 'label', 'class_factor_pct']),
                'conceptTreffer' => mb_strlen($q) >= 2
                    ? FoodAlchemistConcept::visibleToTeam($team)->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('consumer_name', 'like', "%{$q}%"))
                        ->orderBy('name')->limit(12)->get(['id', 'name', 'consumer_name', 'price_per_person_cache', 'ek_per_person_cache'])
                    : collect(),
                'getraenkTreffer' => $this->getraenkTreffer($team),
                'vkVorschau' => $this->leistungEditId !== null && ($this->leistungForm['vk_modus'] ?? 'auto') === 'auto' && is_numeric(str_replace(',', '.', (string) ($this->leistungForm['ek_satz'] ?? '')))
                    ? $pricing->vkVorschlag($team, (float) str_replace(',', '.', (string) $this->leistungForm['ek_satz']), ($this->leistungForm['markup_class_id'] ?? '') !== '' ? (int) $this->leistungForm['markup_class_id'] : null)
                    : null,
                'nutzer' => $team->users()->orderBy('name')->get(['users.id', 'users.name'])->pluck('name', 'id')->all(),
                'speisen' => fn ($c) => $c !== null ? $pricing->speisenPreis($c) : null,
                // Stufe 5b: Gerichte je Konzept (Haupt und Add-on) und Tageszeit mit Wording-Kette (Konfigurator → Concept → Gericht), wie im Foodbook
                'gerichtZeilen' => $k->konzepte->mapWithKeys(fn ($kz) => [$kz->id => collect(['lunch' => $kz->lunchConcept, 'dinner' => $kz->dinnerConcept])
                    ->filter()->map(fn ($c) => app(\Platform\FoodAlchemist\Services\WordingResolver::class)->gerichtZeilen(
                        $c->loadMissing(['slots.dish', 'slots.embeddedConcept.slots.dish', 'slots.package.dishes.dish']), $kz))->all()])->all(),
                'schreibstile' => \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::visibleToTeam($team)->where('is_inactive', false)->orderBy('name')->pluck('name', 'id')->all(),
                'anfragen' => \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::visibleToTeam($team)->where('konfigurator_id', $k->id)->latest()->limit(10)->get(),
            ];
        }

        return view('foodalchemist::livewire.angebote.konfigurator-editor', $daten);
    }

    /**
     * Spec 94c: Treffer für den Reiter Getränke — Concepts der Kategorie „Getränke“ (team-pflegbar, samt Unterkategorien) auch ohne Suche;
     * ohne diese Kategorie jedes Concept ab 2 Zeichen.
     */
    private function getraenkTreffer(\Platform\Core\Models\Team $team): \Illuminate\Support\Collection
    {
        $q = trim($this->getraenkSuche);
        $kat = \Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory::visibleToTeam($team)->where('name', 'like', 'Getränke%')->pluck('id');
        $kat = $kat->merge(\Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory::visibleToTeam($team)->whereIn('parent_id', $kat)->pluck('id'));
        if ($kat->isEmpty() && mb_strlen($q) < 2) {
            return collect();
        }

        return FoodAlchemistConcept::visibleToTeam($team)
            ->when($kat->isNotEmpty(), fn ($w) => $w->whereIn('category_id', $kat))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('consumer_name', 'like', "%{$q}%")))
            ->orderBy('name')->limit(12)->get(['id', 'name', 'consumer_name', 'price_per_person_cache', 'ek_per_person_cache']);
    }

    /** Kennzahlen-Leiste: All-in „ab“ (günstigster Fall im größten Paket), schwächster DB, Zahl der Warnungen. */
    public static function kennzahlen(FoodAlchemistKonfigurator $k, array $matrix): array
    {
        $faelle = collect($matrix['pakete'])->flatMap(fn ($p) => collect($p['faelle'])->map(fn ($f) => $f + ['paket' => $p['name']]));
        $einzel = $faelle->filter(fn ($f) => $f['fall']['typ'] === 'einzel');
        $groesstes = collect($matrix['pakete'])->last();
        $ab = $groesstes !== null ? collect($groesstes['faelle'])->filter(fn ($f) => $f['fall']['typ'] === 'einzel')->min('preis_pp') : null;
        $schwach = $faelle->flatMap(fn ($f) => collect($f['rand'])->map(fn ($r) => ['db' => $r['db'], 'wo' => $f['paket'] . ' · ' . $f['name'] . ' · ' . $r['pax'] . ' Gäste']))->sortBy('db')->first();

        return [
            'haupt' => $k->konzepte->where('rolle', 'haupt')->count(),
            'addons' => $k->konzepte->where('rolle', 'addon')->count(),
            'getraenke' => $k->konzepte->where('rolle', 'getraenk')->count(),
            'pakete' => $k->pakete->count(),
            'ab' => $ab,
            'schwach' => $schwach,
            'warnungen' => count($matrix['warnungen']),
            'faelle' => $einzel->count(),
        ];
    }

    private function konfigurator(): ?FoodAlchemistKonfigurator
    {
        return $this->selectedId !== null ? FoodAlchemistKonfigurator::visibleToTeam($this->team())->find($this->selectedId) : null;
    }

    private function ladeForm(): void
    {
        $k = $this->konfigurator();
        if ($k === null) {
            $this->selectedId = null;
            $this->form = [];

            return;
        }
        $this->form = [
            'name' => $k->name, 'kunden_titel' => (string) $k->kunden_titel, 'claim' => (string) $k->claim, 'intro' => (string) $k->intro, 'ort' => (string) $k->ort,
            'status' => $k->status, 'verantwortlich_user_id' => (string) ($k->verantwortlich_user_id ?? ''), 'pax_vorgabe' => (string) $k->pax_vorgabe,
            'gueltig_bis' => $k->gueltig_bis?->format('Y-m-d') ?? '',
            'stunden_lunch' => (string) (float) $k->stunden_lunch, 'stunden_dinner' => (string) (float) $k->stunden_dinner, 'stunden_day_night' => (string) (float) $k->stunden_day_night,
            'tageszeit_aktiv' => (bool) $k->tageszeit_aktiv, 'kombi_rabatt_pct' => (string) (float) $k->kombi_rabatt_pct,
            'paket_toleranz_pct' => (string) (float) $k->paket_toleranz_pct, 'rundung' => number_format((float) $k->rundung, 2, '.', ''),
            'reihenfolge' => $k->reihenfolge ?: 'tageszeit_zuerst',
            'getraenke_pflicht' => (bool) $k->getraenke_pflicht,
        ];
    }

    /** Aktion ausführen; Fehler als Meldung statt Ausnahme. */
    private function tue(callable $aktion): bool
    {
        $this->fehler = null;
        $this->hinweis = null;
        if ($this->selectedId === null) {
            return false;
        }
        try {
            $aktion();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Den Eintrag gibt es nicht mehr.';

            return false;
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return false;
        }

        return true;
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
