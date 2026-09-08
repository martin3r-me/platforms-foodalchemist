<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;

/**
 * Spec 50 · E-3 — das Vorgangs-Register: was ein Agent wissen muss, BEVOR er anfängt.
 *
 * Der Anlass (§1 der Spec): die acht Workflow-Dossiers sind fachlich gut und trotzdem
 * wirkungslos. Sie liegen in der Kategorie `workflow`, die **kein Routing** hat — kein
 * Generator lädt sie, und `knowledge.SEARCH` findet sie nur, wenn jemand zufällig den
 * richtigen Begriff tippt. Ein Agent, der „lege ein Gericht an" hört, bekommt sie nicht.
 *
 * **Die Konstruktionsregel dieser Klasse** (Spec §7): das Soll wird ABGELEITET, nicht
 * abgeschrieben. Eine handgepflegte Aspekt-Liste in Markdown ist genau das, was seit
 * 2026-07-14 nicht funktioniert — sie altert, und niemand merkt es. Deshalb enthält
 * {@see VORGAENGE} ausschliesslich **Verdrahtung** (welcher Reife-Typ, welches Dossier,
 * welcher Prompt-Key, welches Regelwerk-Feature). Alles Fachliche kommt zur Laufzeit:
 *
 *  · Soll-Aspekte  → {@see ReifeService::sollAspekte()} (und damit aus den Adaptern selbst)
 *  · Kette + Prosa → Frontmatter und Abschnitte des Workflow-Dossiers
 *  · Regelwerke    → {@see KnowledgeCanonService} (Fallback: ganzes Regelwerk-Dossier)
 *
 * Ändert sich ein Anreicherungs-Schritt im Code, ändert sich die Auskunft mit.
 *
 * **Ehrliche Degradation** wie in Etappe 7: fehlt einem Vorgang das Dossier, steht das in
 * `nicht_verfuegbar` statt in erfundener Prosa. Hat ein Vorgang kein Artefakt mit
 * Reife-Adapter (Preis-Monitoring), gibt es keine Soll-Aspekte — und das wird gesagt.
 */
class VorgangsRegisterService
{
    /**
     * Reine Verdrahtung. Kein Fachtext, keine Schrittfolgen, keine Aspekte — die kommen
     * aus dem Code bzw. aus dem Dossier.
     *
     * `kind`         Reife-Artefakt-Typ ({@see ReifeService::KINDS}) oder null
     * `doc_slugs`    Workflow-Dossiers in der Wissensbasis, in Lesereihenfolge (leer = keines).
     *                Das ERSTE ist das Leit-Dossier: aus seinem Kopf kommen `required_tools`
     *                und `trigger_phrases`. Mehrere, weil ein Dossier höchstens 4.000 Zeichen
     *                tragen darf (Spec 50 Strang III, Ein-Thema-Regel) — ein Vorgang mit zwei
     *                Wegen passt nicht in eines.
     * `feature`      Schlüssel für die Regelwerk-Auswahl ({@see KnowledgeContextService})
     * `prompt_keys`  Prompt-Registry-Zeilen, die dieser Vorgang benutzt
     * `einstieg`     das Tool, mit dem der Vorgang beginnt
     */
    public const VORGAENGE = [
        'basisrezept_anlegen' => [
            'titel' => 'Basisrezept anlegen',
            'kind' => 'recipe',
            // Vier Teile statt eines 8.691-Zeichen-Dossiers (Migration 2026_09_07_000001);
            // das alte `workflow.rezept_anlegen_mcp` ist dort stillgelegt.
            'doc_slugs' => [
                'workflow.basisrezept_regeln',
                'workflow.basisrezept_erzeugen',
                'workflow.basisrezept_komponenten',
                'workflow.basisrezept_abschluss',
            ],
            // Spec 52/Paket 2: ein Schluesselraum. Der Alt-Name lieferte ueber
            // REGELWERK_SLUG_LIKE fuer BEIDE Vorgaenge `%basisrezept%`.
            'feature' => 'recipe.generator',
            'prompt_keys' => ['recipe.generator'],
            'einstieg' => 'foodalchemist.recipes.POST',
        ],
        'gericht_anlegen' => [
            'titel' => 'Gericht / Verkaufsrezept anlegen',
            'kind' => 'gericht',
            // EIN Prozess, zwei Ausführende — Weg A die SaaS-KI über die Planungs-Leitstelle,
            // Weg B der Agent in ihrer Rolle, mit derselben Schrittfolge. Auf vier Dossiers
            // verteilt, weil keines über 4.000 Zeichen gehen darf: das Leit-Dossier trägt die
            // Regeln, die für BEIDE Wege gelten, zwei je einen Weg, das vierte den gemeinsamen
            // Abschluss (Speisen-Klasse, Kalkulation, Definition of Done).
            // Der ältere `workflow.gericht_anlegen_mcp` beschrieb nur Weg A und ist globales
            // Master-Wissen (per MCP weder editier- noch deaktivierbar).
            'doc_slugs' => [
                'workflow.verkaufsgericht_anlegen_mcp',
                'workflow.gericht_weg_a_leitstelle',
                'workflow.gericht_weg_b_eigenregie',
                'workflow.gericht_abschluss',
            ],
            // Spec 52/Paket 2: der Gericht-Vorgang faellt jetzt auf das VK-Regelwerk zurueck,
            // nicht mehr auf das Basisrezepte-Regelwerk.
            'feature' => 'vk.generator',
            'prompt_keys' => ['vk.generator'],
            'einstieg' => 'foodalchemist.verkaufsrezepte.POST',
        ],
        'konzept_anlegen' => [
            'titel' => 'Konzept anlegen (Concepter)',
            'kind' => 'concept',
            'doc_slugs' => ['workflow.konzept_anlegen_mcp'],
            'feature' => 'concept.brief_geruest',
            'prompt_keys' => ['concept.brief_geruest', 'concept.plan', 'concept.wording'],
            'einstieg' => 'foodalchemist.concepts.POST',
        ],
        'foodbook_anlegen' => [
            'titel' => 'Foodbook anlegen',
            'kind' => 'foodbook',
            'doc_slugs' => ['workflow.foodbook_anlegen_mcp'],
            'feature' => 'foodbook.grundgeruest',
            'prompt_keys' => ['foodbook.grundgeruest', 'foodbook.kundentext'],
            'einstieg' => 'foodalchemist.foodbooks.POST',
        ],
        'angebot_erstellen' => [
            'titel' => 'Angebot erstellen',
            'kind' => 'angebot',
            'doc_slugs' => ['workflow.angebot_erstellen_mcp'],
            'feature' => 'concept.brief_geruest',
            'prompt_keys' => ['foodbook.kundentext'],
            'einstieg' => 'foodalchemist.angebote.POST',
        ],
        'speiseplan_erstellen' => [
            'titel' => 'Speiseplan erstellen',
            'kind' => 'speiseplan',
            'doc_slugs' => ['workflow.speiseplan_erstellen_mcp'],
            // Fuer diesen Vorgang gibt es KEIN Regelwerk — lieber keines nennen als ein falsches.
            'feature' => null,
            'prompt_keys' => [],
            'einstieg' => 'foodalchemist.speiseplaene.POST',
        ],
        'speisekarte_anlegen' => [
            'titel' => 'Speisekarte anlegen',
            'kind' => 'speisekarte',
            'doc_slugs' => ['workflow.speisekarte_anlegen_mcp'],
            // Fuer diesen Vorgang gibt es KEIN Regelwerk — lieber keines nennen als ein falsches.
            'feature' => null,
            // Das Karten-Wording laeuft ueber `foodbook.kundentext` (SpeisekarteService::kiWordingVorschlag)
            // — es gibt keine eigene Registry-Zeile `speisekarte.wording`.
            'prompt_keys' => ['foodbook.kundentext'],
            'einstieg' => 'foodalchemist.speisekarten.POST',
        ],
        'format_anlegen' => [
            'titel' => 'Format anlegen',
            'kind' => 'format',
            'doc_slugs' => ['workflow.format_anlegen_mcp'],
            'feature' => 'format.grundgeruest',
            'prompt_keys' => ['format.grundgeruest'],
            'einstieg' => 'foodalchemist.formats.POST',
        ],
        'gp_aus_la_anlegen' => [
            'titel' => 'Grundprodukt aus Lieferantenartikel anlegen (LA-First)',
            // Grundprodukte haben keinen Reife-Adapter — es gibt kein Soll zu melden.
            'kind' => null,
            'doc_slugs' => ['workflow.gp_aus_la_anlegen_mcp'],
            // Spec 52/Paket 2: das GP-Regelwerk, nicht das Basisrezepte-Regelwerk.
            'feature' => 'gp.suggest',
            'prompt_keys' => [],
            'einstieg' => 'foodalchemist.gps.MATCH',
        ],
        'preis_margen_monitoring' => [
            'titel' => 'Preis- und Margen-Monitoring',
            'kind' => null,
            'doc_slugs' => ['workflow.preis_margen_monitoring_mcp'],
            // Fuer diesen Vorgang gibt es KEIN Regelwerk — lieber keines nennen als ein falsches.
            'feature' => null,
            'prompt_keys' => [],
            'einstieg' => 'foodalchemist.kalkulation.GET',
        ],
    ];

    public function __construct(
        private ReifeService $reife,
        private KnowledgeContextService $wissen,
        private KnowledgeCanonService $kanon,
    ) {}

    /** @return list<array{code: string, titel: string, kind: ?string, einstieg: string}> */
    public function liste(): array
    {
        $aus = [];
        foreach (self::VORGAENGE as $code => $v) {
            $aus[] = ['code' => $code, 'titel' => $v['titel'], 'kind' => $v['kind'], 'einstieg' => $v['einstieg']];
        }

        return $aus;
    }

    public function kennt(string $code): bool
    {
        return isset(self::VORGAENGE[$code]);
    }

    /**
     * Der volle Vorgang. `null`, wenn der Code unbekannt ist.
     *
     * @return array<string, mixed>|null
     */
    public function vorgang(string $code, ?Team $team): ?array
    {
        $v = self::VORGAENGE[$code] ?? null;
        if ($v === null) {
            return null;
        }

        $nichtVerfuegbar = [];
        $docs = [];
        $fehlend = [];
        foreach ($v['doc_slugs'] as $slug) {
            $d = $this->wissen->getDocument($team, $slug);
            if ($d !== null) {
                $docs[] = $d;
            } else {
                $fehlend[] = $slug;
            }
        }
        if ($v['doc_slugs'] === []) {
            $nichtVerfuegbar[] = ['was' => 'ablauf_prosa', 'warum' => 'Für diesen Vorgang gibt es kein Workflow-Dossier.'];
        } elseif ($docs === []) {
            $nichtVerfuegbar[] = ['was' => 'ablauf_prosa',
                'warum' => 'Kein Dossier des Vorgangs ist aktiv oder für dieses Team sichtbar (' . implode(', ', $fehlend) . ').'];
        } elseif ($fehlend !== []) {
            // Teil-Verfügbarkeit ehrlich melden: der Agent sieht sonst nicht, dass ihm ein Weg fehlt.
            $nichtVerfuegbar[] = ['was' => 'ablauf_prosa_teilweise',
                'warum' => 'Diese Dossiers fehlen oder sind inaktiv: ' . implode(', ', $fehlend) . '.'];
        }

        // Kopf-Felder kommen aus dem Leit-Dossier (dem ersten), nicht gemischt aus allen.
        $kopf = $docs !== [] ? $this->wissen->frontmatterOf((string) $docs[0]->content_md) : [];
        $kette = $this->kette($kopf);
        if ($kette === null && $docs !== []) {
            $nichtVerfuegbar[] = ['was' => 'kette',
                'warum' => 'Das Leit-Dossier «' . $docs[0]->slug . '» nennt im Kopf keine required_tools.'];
        }

        $aspekte = null;
        if ($v['kind'] !== null) {
            $aspekte = $this->reife->sollAspekte($v['kind']);
        } else {
            $nichtVerfuegbar[] = ['was' => 'soll_aspekte',
                'warum' => 'Dieser Vorgang erzeugt kein Artefakt mit Reife-Messung — es gibt kein Soll zu prüfen.'];
        }

        return array_filter([
            'code' => $code,
            'titel' => $v['titel'],
            'einstieg' => $v['einstieg'],
            'artefakt' => $v['kind'],
            'trigger_phrasen' => $this->liste_aus($kopf, 'trigger_phrases'),
            'kette' => $kette,
            'soll_aspekte' => $aspekte,
            'pruefen_mit' => $v['kind'] !== null
                ? ['tool' => 'foodalchemist.reife.GET', 'args' => ['kind' => $v['kind']]]
                : null,
            'regelwerke' => $this->regelwerke($v, $team),
            'prompt_keys' => $v['prompt_keys'],
            'dossiers' => $docs !== [] ? array_map(fn ($d) => [
                'slug' => $d->slug,
                'titel' => $d->title,
                'version' => (int) $d->version,
                'zeichen' => (int) $d->char_count,
                'abschnitte' => $this->abschnitte((string) $d->content_md),
                'lesen_mit' => ['tool' => 'foodalchemist.knowledge.GET', 'args' => ['slug' => $d->slug]],
            ], $docs) : null,
            'nicht_verfuegbar' => $nichtVerfuegbar,
        ], fn ($v) => $v !== null);
    }

    /**
     * Die Regelwerke, die für diesen Vorgang gelten.
     *
     * Kanon zuerst — sobald {@see KnowledgeCanonService} Zeilen für den Prompt-Key hat, ist
     * die Auswahl §-genau kuratiert. Ohne Kanon das ganze Regelwerk-Dossier: gleicher
     * Vertrag, gröbere Auflösung. Das ist die Zusage aus Spec §5.1 („leerer Kanon ⇒ ganze
     * Docs, befüllter Kanon ⇒ §-genau, gleicher Tool-Vertrag").
     *
     * @return array{quelle: string, dokumente: list<array<string, mixed>>}
     */
    public function regelwerke(array $v, ?Team $team): array
    {
        if ($team !== null) {
            foreach ($v['prompt_keys'] as $key) {
                $docs = $this->kanon->documentsFor('prompt_key', $key, $team);
                if ($docs->isNotEmpty()) {
                    return [
                        'quelle' => 'kanon',
                        'dokumente' => $docs->map(fn ($d) => [
                            'slug' => $d->slug, 'titel' => $d->title, 'kategorie' => $d->category,
                            'mode' => $d->mode, 'zeichen' => (int) $d->char_count, 'prompt_key' => $key,
                        ])->values()->all(),
                    ];
                }
            }
        }

        // Kein Kanon → nachschlagen, welcher Regelwerks-BEREICH fachlich zu diesem Vorgang
        // gehoert. `feature === null` heisst ausdruecklich „fuer diesen Vorgang gibt es kein
        // Regelwerk" (Spec 52/Paket 2) — vorher fielen genau diese Vorgaenge blind aufs
        // Basisrezepte-Regelwerk zurueck.
        //
        // ★ **Die Bedeutung dieser Antwort hat sich mit Spec 52 · F4 geaendert, und das muss
        // hier stehen.** Vorher galt: „dieselbe Auswahl, die der Generator trifft" — der zog
        // per `regelwerk:always` genau dieses eine Dossier in den Prompt. Diesen Pfad gibt es
        // nicht mehr; verbindliches Wissen kommt aus dem Kanon. Was hier steht, ist also
        // NICHT mehr „was der Prompt bekommt", sondern „was ein Mensch/Agent zu diesem
        // Vorgang lesen sollte". Deshalb `quelle: 'nachschlagen'` statt `'dossier'`, und
        // deshalb ALLE Treffer statt des alphabetisch ersten: `%basisrezept%` trifft rund
        // zwanzig §-Dossiers, und eines davon zu nennen waere schlechter als alle.
        $docs = $v['feature'] === null
            ? collect()
            : $this->wissen->regelwerkDossiersFuer($team, $v['feature']);

        return [
            'quelle' => $docs->isNotEmpty() ? 'nachschlagen' : 'keine',
            'dokumente' => $docs->map(fn ($d) => [
                'slug' => $d->slug, 'titel' => $d->title, 'kategorie' => 'regelwerk',
                'mode' => 'nachschlagen', 'zeichen' => (int) $d->char_count, 'prompt_key' => null,
            ])->values()->all(),
            'hinweis' => $docs->isNotEmpty()
                ? 'Kein Kanon fuer diesen Vorgang — diese Dossiers gehoeren fachlich dazu, werden aber '
                    .'NICHT automatisch in den Prompt gelegt. Wer sie verbindlich machen will: '
                    .'`knowledge_canon.PUT` auf den passenden Prompt-Key.'
                : null,
        ];
    }

    /** @return list<string>|null */
    private function kette(array $kopf): ?array
    {
        $tools = $this->liste_aus($kopf, 'required_tools');

        return $tools === [] ? null : $tools;
    }

    /** @return list<string> */
    private function liste_aus(array $kopf, string $key): array
    {
        $wert = $kopf[$key] ?? null;
        if (is_array($wert)) {
            return array_values(array_filter(array_map('trim', $wert), fn ($s) => $s !== ''));
        }

        return is_string($wert) && trim($wert) !== '' ? [trim($wert)] : [];
    }

    /**
     * Die Überschriften des Dossiers als Inhaltsverzeichnis — damit der Agent sieht, dass es
     * Anti-Patterns und eine Schritt-Gliederung gibt, ohne 8.000 Zeichen zu laden.
     *
     * @return list<string>
     */
    private function abschnitte(string $md): array
    {
        preg_match_all('/^#{2,3}\s+(.+)$/m', $md, $m);

        return array_values(array_map('trim', $m[1] ?? []));
    }
}
