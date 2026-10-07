<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Ai\KnowledgeBudget;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\Knowledge\WissensProfilService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 52 · Grundsatz E — die Wissens-Steuerung bekommt eine Oberfläche.
 *
 * **Der Anlass ist eine Asymmetrie, die alles falsch anfühlen liess:** die ALTE Struktur
 * (Bindungen) hat seit #469 eine Kurations-UI im Wissens-Browser. Der Kanon — die neue,
 * gewinnende Ebene — hat **keine**, und die Routings haben seit `docs/wissen.md` einen
 * „Ausblick"-Eintrag und sonst nichts. Wer im UI kuratierte, pflegte also den Fallback,
 * während die Wahrheit nur per MCP erreichbar war.
 *
 * Diese Seite macht zweierlei:
 *   · **sichtbar** — je Prompt-Key das aufgelöste Profil, sein Zustand und seine Befunde
 *     ({@see WissensProfilService}). Das ist die Antwort auf „welches Wissen bekommt dieser
 *     Schritt", die es im UI vorher nirgends gab.
 *   · **einstellbar** — der Routing-Editor (feature × category → Modus + Deckel) UND seit
 *     Spec 52 · Paket 3 der **Kanon-Editor**: welches Dossier ist für diesen Schritt
 *     verbindlich, in welcher Reihenfolge, `pflicht` oder `wenn_platz`. Der stand hier als
 *     „eigener Schritt" angekündigt, weil er eine Dossier-Suche braucht — das ist er jetzt.
 *
 * ★ Warum der Kanon-Editor nicht nachrangig ist: seit F2 ist der Kanon die EINZIGE Quelle für
 * „muss in diesen Prompt". Ohne Oberfläche wäre die wichtigste Steuerung des Moduls nur per
 * MCP erreichbar — und die alte, abgeschaffte Ebene hätte weiterhin die einzige UI gehabt.
 *
 * Schreibrecht: `knowledge_routings` ist **globaler Master** (keine `team_id`-Spalte) — es gilt
 * für alle Teams. Deshalb darf nur das Master-Team schreiben, gleiche Regel wie beim globalen
 * Wissen. Lesen bleibt gemeinsam.
 */
class Wissenssteuerung extends Component
{
    public array $artForm = ['feature' => '', 'art' => 'fachwissen', 'mode' => 'discovery', 'max_docs' => '3', 'max_chars_per_doc' => ''];

    public string $bereich = '';

    public bool $nurBefunde = false;

    public ?string $offen = null;

    /** @var array{feature: string, category: string, mode: string, max_docs: string, max_chars_per_doc: string} */
    public array $form = [
        'feature' => '', 'category' => '', 'mode' => 'discovery', 'max_docs' => '', 'max_chars_per_doc' => '',
    ];

    public ?int $editId = null;

    /** Prompt-Key, dessen Kanon gerade bearbeitet wird (null = keiner). */
    public ?string $kanonKey = null;

    /** Freitext-Suche im Dossier-Wähler. */
    public string $kanonSuche = '';

    /** @var array{slug: string, mode: string, ord: string} */
    public array $kanonForm = ['slug' => '', 'mode' => 'pflicht', 'ord' => ''];

    /** Prompt-Key, dessen Budget gerade bearbeitet wird (null = keiner). */
    public ?string $budgetKey = null;

    public string $budgetWert = '';

    public ?string $fehler = null;

    public ?string $hinweis = null;


    /*
     * ── Darstellung ─────────────────────────────────────────────────────────────────────
     * Die Seite zeigte bisher nur technische Schlüssel (recipe.generator, discovery, …).
     * Diese Tabellen übersetzen sie in Küchensprache. Reine Anzeige: gespeichert und
     * verglichen wird weiter mit den Schlüsseln, sie bleiben klein daneben sichtbar.
     */

    /** Bereich = Teil vor dem ersten Punkt eines Prompt-Keys. */
    public const BEREICH_LABEL = [
        'chat' => 'Chat', 'component' => 'Komponente', 'concept' => 'Konzept', 'conformance' => 'Regelwerk-Prüfung',
        'demo' => 'Test', 'foodbook' => 'Foodbook', 'format' => 'Format', 'gp' => 'Grundprodukt',
        'planning' => 'Planung', 'planung' => 'Planung', 'praesentation' => 'Präsentation', 'price' => 'Preis',
        'recipe' => 'Basisrezept', 'signal' => 'Signal', 'trend' => 'Trendradar', 'vk' => 'Gericht', 'voice' => 'Sprachbefehl',
    ];

    /** Arbeitsschritt = Teil nach dem ersten Punkt. Unbekanntes wird lesbar gemacht statt versteckt. */
    public const SCHRITT_WORT = [
        'allergene' => 'Allergene', 'anker' => 'Aroma-Anker', 'bauart' => 'Bauart', 'brief_geruest' => 'Grundgerüst aus dem Auftrag',
        'category' => 'Kategorie', 'check' => 'Prüfung', 'cluster_label' => 'Trends einordnen', 'command' => 'Befehl',
        'condition' => 'Zustand', 'conformance_revise' => 'Regelwerk-Korrektur', 'description' => 'Beschreibung',
        'design_css' => 'Eigenes Design', 'dichteklasse' => 'Dichteklasse', 'dish_proposal_revise' => 'Gerichtvorschlag überarbeiten',
        'domain' => 'Warenkunde', 'echo' => 'Echo', 'eigenschaften' => 'Eigenschaften', 'equipment' => 'Geräte',
        'extract' => 'Rezept einlesen', 'garverlust' => 'Garverlust', 'generator' => 'Erzeugen', 'geschmack' => 'Geschmack',
        'grundgeruest' => 'Grundgerüst', 'kapitel_ideen' => 'Kapitel-Ideen', 'kohaerenz' => 'Stimmigkeit', 'kundentext' => 'Kundentext',
        'la_suggest' => 'Artikelvorschlag', 'leitplanken' => 'Leitplanken aus dem Auftrag', 'level' => 'Niveau',
        'margin_levers' => 'Margen-Hebel', 'marketing' => 'Verkaufstext', 'message' => 'Nachricht', 'naehrwerte' => 'Nährwerte',
        'name_putzen' => 'Namen bereinigen', 'pairing' => 'Pairing', 'piece_default_g' => 'Stückgewicht', 'plan' => 'Plan',
        'plating' => 'Anrichten', 'plausi' => 'Plausibilität', 'posten' => 'Posten', 'production_depth' => 'Fertigungstiefe',
        'recipe_category_suggest' => 'Rezept-Kategorie vorschlagen', 'recipe_naming_suggest' => 'Rezeptnamen vorschlagen',
        'regeneration' => 'Regenerieren', 'replacement_suggest' => 'Ersatz vorschlagen', 'review' => 'Prüfen', 'role' => 'Rolle',
        'rollen' => 'Rollen', 'sektor' => 'Sektor', 'sensorik' => 'Sensorik', 'servier_vehikel' => 'Servier-Vehikel',
        'serving_form_suggest' => 'Servierform vorschlagen', 'speisen_klasse' => 'Speisenklasse', 'steps' => 'Arbeitsschritte',
        'suggest' => 'Vorschlag', 'supplier_inquiry' => 'Lieferanten-Anfrage', 'tags' => 'Schlagworte', 'teller_heber' => 'Teller-Heber',
        'term_la_rank' => 'Artikel-Rangfolge', 'titel_vorschlag' => 'Titelvorschlag', 'ueberarbeiten' => 'Überarbeiten',
        'verpackungsmasse' => 'Verpackungsmaße', 'vk_release_advice' => 'Freigabe-Empfehlung', 'wording' => 'Formulierung',
        'zaehl_einheiten' => 'Zähleinheiten',
    ];

    /** Schlüssel ohne Punkt-Schema (Alt-Schlüssel der Routing-Ebene). */
    public const SCHRITT_SONDER = [
        'ai_generate_recipe' => 'Rezept- und Gericht-Erzeugung',
        'ai_suggest_pairings' => 'Pairing-Vorschläge',
        'ai_infer_ankers' => 'Aroma-Anker ableiten',
    ];

    /** Verwendung einer Kategorie bzw. Art (Routing-Modus). */
    public const MODUS_LABEL = [
        'always' => 'Immer vollständig', 'discovery' => 'Passendes suchen', 'grounding' => 'Je Hauptzutat',
        'none' => 'Bewusst nicht', 'resolve' => 'Werte auflösen',
    ];

    /** Zustand je Arbeitsschritt: [Text, Ton]. */
    public const ZUSTAND_LABEL = [
        'gesteuert' => ['Versorgt', 'ok'], 'bewusst_leer' => ['Bewusst ohne', 'neutral'],
        'ungesteuert' => ['Ohne Wissen', 'warn'], 'fehlerhaft' => ['Fehlerhaft', 'crit'],
    ];

    public const ART_LABEL = ['fachwissen' => 'Fachwissen', 'referenz' => 'Referenz', 'datenwerk' => 'Datenwerk'];

    public const ACHSE_LABEL = [
        'occasion' => 'Anlass', 'sektor' => 'Sektor', 'level' => 'Niveau', 'niveau' => 'Niveau', 'serviceform' => 'Serviceform',
        'convenience' => 'Convenience', 'bestand' => 'Bestand', 'bio_praeferenz' => 'Bio',
    ];

    /** Lesbarer Name eines Prompt- oder Routing-Schlüssels (z. B. recipe.generator → Basisrezept · Erzeugen). */
    public static function schrittLabel(?string $key): string
    {
        $key = (string) $key;
        if ($key === '') {
            return '–';
        }
        if (isset(self::SCHRITT_SONDER[$key])) {
            return self::SCHRITT_SONDER[$key];
        }
        [$bereich, $rest] = str_contains($key, '.') ? explode('.', $key, 2) : [$key, ''];
        $bereichText = self::BEREICH_LABEL[$bereich] ?? self::lesbar($bereich);

        return $rest === '' ? $bereichText : $bereichText.' · '.(self::SCHRITT_WORT[$rest] ?? self::lesbar($rest));
    }

    /** snake_case/Punkt-Werte als lesbarer Text, z. B. schule_kita → Schule kita. */
    public static function lesbar(?string $wert): string
    {
        return ucfirst(trim(str_replace(['_', '.'], ' ', (string) $wert)));
    }

    public static function modusLabel(?string $modus): string
    {
        return self::MODUS_LABEL[(string) $modus] ?? self::lesbar($modus);
    }

    /**
     * Routings sind global (die Tabelle hat keine `team_id`) — sie gelten für JEDES Team.
     * Eine Fehlkonfiguration legt damit die KI-Versorgung aller Mandanten still, nicht nur die
     * eigene. Deshalb dieselbe Master-Regel wie bei globalem Wissen.
     */
    private function darfSchreiben(): bool
    {
        return TeamScope::mayWrite(null, Auth::user()?->currentTeamRelation);
    }

    /**
     * Budget eines Arbeitsschritts bearbeiten.
     *
     * Die Seite ZEIGTE „Σ Pflicht / Budget" von Anfang an — und wer sah, dass ein Schritt an
     * seiner Grenze steht, konnte nichts tun ausser einen Deploy bestellen. Ein Grenzwert, den
     * man sieht aber nicht bewegt, ist eine Diagnose ohne Therapie.
     */
    public function editBudget(string $key): void
    {
        $this->fehler = $this->hinweis = null;
        $this->budgetKey = $key;
        $this->budgetWert = (string) KnowledgeBudget::forKey($key);
    }

    public function saveBudget(): void
    {
        $this->fehler = $this->hinweis = null;
        if ($this->budgetKey === null) return;
        if (! $this->darfSchreiben()) { $this->fehler = 'Der Wissensumfang gilt für alle Teams. Ändern darf ihn nur das Master-Team.'; return; }
        $wert = (int) $this->budgetWert;
        $team = Auth::user()?->currentTeamRelation;
        $pflicht = 0;
        if ($team !== null) {
            try {
                $pflicht = (int) (app(WissensProfilService::class)->profil($this->budgetKey, $team)['pflicht_zeichen'] ?? 0);
            } catch (\Throwable) {
                $pflicht = 0;   // kein Profil ⇒ kein Riegel, aber auch kein Absturz beim Speichern
            }
        }
        // Vorwarnen statt zur Laufzeit abbrechen: Pflichtwissen wird nie gekappt, ein zu kleines
        // Budget legt den Schritt beim naechsten Aufruf still — und niemand braechte das mit
        // dieser Zahl in Verbindung.
        if ($pflicht > 0 && $wert < $pflicht) {
            $this->fehler = sprintf('%s Zeichen sind weniger als das verbindliche Wissen dieses Schritts (%s Zeichen). Der Schritt würde abbrechen statt zu kürzen. Erst das verbindliche Wissen verkleinern.',
                number_format($wert, 0, ',', '.'), number_format($pflicht, 0, ',', '.'));

            return;
        }
        try {
            KnowledgeBudget::setze($this->budgetKey, $wert);
            $this->hinweis = 'Wissensumfang gespeichert. Gilt ab dem nächsten KI-Aufruf.';
            $this->budgetKey = null;
        } catch (\RuntimeException $e) { $this->fehler = $e->getMessage(); }
    }

    public function resetBudget(string $key): void
    {
        $this->fehler = $this->hinweis = null;
        if (! $this->darfSchreiben()) { $this->fehler = 'Der Wissensumfang gilt für alle Teams. Ändern darf ihn nur das Master-Team.'; return; }
        KnowledgeBudget::setze($key, null);
        $this->budgetKey = null;
        $this->hinweis = 'Wissensumfang steht wieder auf dem Standard.';
    }

    public function editArt(int $id): void
    {
        $row = DB::table('foodalchemist_knowledge_routings')->where('id', $id)->whereNotNull('art')->first();
        if ($row === null) return;
        $this->artForm = ['feature' => $row->feature, 'art' => $row->art, 'mode' => $row->mode, 'max_docs' => (string) ($row->max_docs ?? 3), 'max_chars_per_doc' => (string) ($row->max_chars_per_doc ?? '')];
    }

    public function saveArt(): void
    {
        $this->fehler = $this->hinweis = null;
        if (! $this->darfSchreiben()) { $this->fehler = 'Diese Einstellung gilt für alle Teams. Ändern darf sie nur das Master-Team.'; return; }
        try {
            app(\Platform\FoodAlchemist\Services\KnowledgeRoutingService::class)->setArt(
                $this->artForm['feature'], $this->artForm['art'], $this->artForm['mode'], (int) $this->artForm['max_docs'],
                $this->artForm['max_chars_per_doc'] === '' ? null : (int) $this->artForm['max_chars_per_doc']);
            $this->hinweis = 'Gespeichert. Gilt ab dem nächsten KI-Aufruf.';
        } catch (\InvalidArgumentException $e) { $this->fehler = $e->getMessage(); }
    }

    public function edit(int $id): void
    {
        $this->fehler = $this->hinweis = null;
        $r = DB::table('foodalchemist_knowledge_routings')->where('id', $id)->first();
        if ($r === null) {
            $this->fehler = 'Diese Einstellung gibt es nicht mehr. Bitte die Seite neu laden.';

            return;
        }
        $this->editId = $id;
        $this->form = [
            'feature' => (string) $r->feature,
            'category' => (string) $r->category,
            'mode' => (string) $r->mode,
            'max_docs' => (string) ($r->max_docs ?? ''),
            'max_chars_per_doc' => (string) ($r->max_chars_per_doc ?? ''),
        ];
    }

    public function cancel(): void
    {
        $this->editId = null;
        $this->fehler = $this->hinweis = null;
        $this->form = ['feature' => '', 'category' => '', 'mode' => 'discovery', 'max_docs' => '', 'max_chars_per_doc' => ''];
    }

    public function save(): void
    {
        $this->fehler = $this->hinweis = null;
        if (! $this->darfSchreiben()) {
            $this->fehler = 'Diese Einstellung gilt für alle Teams. Ändern darf sie nur das Master-Team.';

            return;
        }
        if (! in_array($this->form['mode'], ['always', 'discovery', 'grounding', 'none'], true)) {
            $this->fehler = 'Bitte eine Verwendung wählen: „Immer vollständig", „Passendes suchen", „Je Hauptzutat" oder „Bewusst nicht".';

            return;
        }

        $daten = [
            'mode' => $this->form['mode'],
            'max_docs' => $this->form['max_docs'] === '' ? null : max(0, (int) $this->form['max_docs']),
            'max_chars_per_doc' => $this->form['max_chars_per_doc'] === '' ? null : max(0, (int) $this->form['max_chars_per_doc']),
            'updated_at' => now(),
        ];

        if ($this->editId !== null) {
            if (DB::table('foodalchemist_knowledge_routings')->where('id', $this->editId)->whereNotNull('art')->exists()) {
                $this->fehler = 'Diese Einstellung gehört zu einer Wissensart. Bitte unter „Wissen nach Art" bearbeiten.';
                return;
            }
            DB::table('foodalchemist_knowledge_routings')->where('id', $this->editId)->update($daten);
            $this->hinweis = 'Gespeichert. Gilt ab dem nächsten KI-Aufruf.';
            $this->cancel();

            return;
        }

        $feature = trim($this->form['feature']);
        $category = trim($this->form['category']);
        if ($feature === '' || $category === '') {
            $this->fehler = 'Bitte Arbeitsschritt und Kategorie angeben.';

            return;
        }
        // (feature, category) ist unique — updateOrInsert, sonst stirbt das Anlegen am Bestand.
        DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
            ['feature' => $feature, 'category' => $category],
            $daten + ['created_at' => now()],
        );
        $this->hinweis = 'Gespeichert. Gilt ab dem nächsten KI-Aufruf.';
        $this->cancel();
    }

    public function delete(int $id): void
    {
        $this->fehler = $this->hinweis = null;
        if (! $this->darfSchreiben()) {
            $this->fehler = 'Diese Einstellung gilt für alle Teams. Ändern darf sie nur das Master-Team.';

            return;
        }
        DB::table('foodalchemist_knowledge_routings')->where('id', $id)->delete();
        // Ohne Zeile ist die Kategorie search-only — das ist etwas ANDERES als `none`
        // (bewusst leer). Deshalb sagen, was passiert ist, statt nur „gelöscht".
        $this->hinweis = 'Entfernt. Diese Kategorie wird für den Arbeitsschritt nicht mehr automatisch mitgegeben, nur noch bei Bedarf durchsucht.';
    }

    public function toggleOffen(string $key): void
    {
        $this->offen = $this->offen === $key ? null : $key;
    }

    /** Kanon-Editor für EINEN Prompt-Key auf/zu. */
    public function kanonEdit(?string $promptKey): void
    {
        $this->kanonKey = $this->kanonKey === $promptKey ? null : $promptKey;
        $this->kanonForm = ['slug' => '', 'mode' => 'pflicht', 'ord' => ''];
        $this->kanonSuche = '';
        $this->fehler = null;
        $this->hinweis = null;
    }

    /**
     * Dossier in den Kanon eines Prompt-Keys aufnehmen.
     *
     * Über {@see KnowledgeCanonService::set()} und NICHT per Insert: dort leben Tenancy,
     * Enum-Prüfung, der Changelog-Guard („kein Changelog im Prompt") und der Deckel-Hinweis.
     * Der Wissens-Browser hatte genau diesen Fehler in der Gegenrichtung — ein roher
     * `addBinding()`-Insert an den Garantien des Service vorbei (Befund `F`).
     */
    public function kanonAdd(): void
    {
        $this->fehler = $this->hinweis = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->kanonKey === null) {
            return;
        }
        $slug = trim($this->kanonForm['slug']);
        if ($slug === '') {
            $this->fehler = 'Bitte ein Dossier wählen.';

            return;
        }

        try {
            $ergebnis = app(KnowledgeCanonService::class)->set($team, [
                'scope' => 'prompt_key',
                'scope_key' => $this->kanonKey,
                'slug' => $slug,
                'mode' => $this->kanonForm['mode'] ?: 'pflicht',
                'ord' => $this->kanonForm['ord'] !== '' ? (int) $this->kanonForm['ord'] : null,
            ]);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        // Die Hinweise des Service NICHT schlucken: „Dossier über dem Deckel" und „Dossier ist
        // inaktiv" sind genau die Fälle, in denen die Zeile entsteht und trotzdem nicht liefert.
        $this->hinweis = $ergebnis['hinweise'] !== []
            ? 'Hinterlegt. Bitte beachten: '.implode(' · ', $ergebnis['hinweise'])
            : 'Dossier gilt jetzt verbindlich für „'.self::schrittLabel($this->kanonKey).'".';
        $this->kanonForm = ['slug' => '', 'mode' => 'pflicht', 'ord' => ''];
        $this->kanonSuche = '';
    }

    /** Dossier aus dem Kanon nehmen. Idempotent; meldet, wenn nichts zu entfernen war. */
    public function kanonRemove(string $promptKey, string $slug): void
    {
        $this->fehler = $this->hinweis = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        try {
            $n = app(KnowledgeCanonService::class)->remove($team, 'prompt_key', $promptKey, $slug);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->hinweis = $n > 0
            ? '„'.$slug.'" gilt für „'.self::schrittLabel($promptKey).'" nicht mehr verbindlich.'
            : 'Nichts entfernt. Dieser Eintrag gehört einem anderen Team oder gilt für alle Teams.';
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $bericht = $team !== null
            ? app(WissensProfilService::class)->integritaet($team, $this->bereich !== '' ? $this->bereich : null)
            : ['keys' => 0, 'gesteuert' => 0, 'bewusst_leer' => 0, 'ungesteuert' => 0, 'fehlerhaft' => 0, 'blockierend' => 0, 'profile' => []];

        $profile = $this->nurBefunde
            ? array_values(array_filter($bericht['profile'], fn ($p) => $p['befunde'] !== []))
            : $bericht['profile'];

        $bereiche = collect(array_keys((array) config('foodalchemist.prompts', [])))
            ->map(fn ($k) => str_contains((string) $k, '.') ? explode('.', (string) $k, 2)[0] : (string) $k)
            ->unique()->sort()->values()->all();

        return view('foodalchemist::livewire.settings.wissenssteuerung', [
            'bericht' => $bericht,
            'profile' => $profile,
            'eingestellteBudgets' => KnowledgeBudget::eingestellt(),
            'bereiche' => $bereiche,
            'routings' => DB::table('foodalchemist_knowledge_routings')
                ->orderBy('feature')->orderBy('category')->get(),
            'kategorien' => DB::table('foodalchemist_knowledge_categories')
                ->whereNull('deleted_at')->where('active', 1)->orderBy('slug')->pluck('slug')->all(),
            // Spec 52/H2: Achsen-Bindungen sind Kanon-Zeilen mit scope='achse'. Hier erst
            // SICHTBAR — bearbeiten laeuft bis zum Kanon-Editor ueber knowledge_canon.PUT,
            // weil es einen Dossier-Waehler braucht. Das steht so auch auf der Seite.
            'achsen' => $team !== null
                ? app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)->achsenBindungen($team)
                : [],
            'achsenConfig' => (array) config('foodalchemist.ai.knowledge_axis_map', []),
            'datenwerkOhneAchse' => $bericht['datenwerk_ohne_achse'] ?? [],
            'darfSchreiben' => $this->darfSchreiben(),
            // Dossier-Wähler: erst ab 2 Zeichen suchen — eine Liste über 1.100 Dossiers ist
            // kein Wähler, sondern eine Wand. Inaktive bewusst MIT: eine Kanon-Zeile darauf ist
            // ein legitimer Vorbereitungs-Schritt, und der Service warnt beim Setzen.
            'kanonTreffer' => mb_strlen(trim($this->kanonSuche)) >= 2
                ? DB::table('foodalchemist_knowledge_documents')
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('slug', 'like', '%'.trim($this->kanonSuche).'%')
                        ->orWhere('title', 'like', '%'.trim($this->kanonSuche).'%'))
                    ->tap(fn ($q) => TeamScope::applyVisible($q, 'team_id', $team))
                    ->orderBy('slug')->limit(15)
                    ->get(['slug', 'title', 'category', 'char_count', 'active'])
                : collect(),
            'alias' => KnowledgeContextService::ROUTING_ALIAS,
            // Anzeige: Kategorie-Kennung → Name (die Kennung bleibt Schlüssel der Steuerung).
            'kategorieLabels' => DB::table('foodalchemist_knowledge_categories')
                ->whereNull('deleted_at')->pluck('label', 'slug')->all(),
            // Vorschläge für das Feld „Arbeitsschritt" (Datalist) — Eingabe bleibt frei.
            'schrittVorschlaege' => collect(array_keys((array) config('foodalchemist.prompts', [])))
                ->merge(array_values(KnowledgeContextService::ROUTING_ALIAS))
                ->map(fn ($k) => (string) $k)->unique()->sort()->values()->all(),
        ]);
    }
}
