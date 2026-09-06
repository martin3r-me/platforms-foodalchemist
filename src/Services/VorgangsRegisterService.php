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
     * `doc_slug`     Workflow-Dossier in der Wissensbasis oder null
     * `feature`      Schlüssel für die Regelwerk-Auswahl ({@see KnowledgeContextService})
     * `prompt_keys`  Prompt-Registry-Zeilen, die dieser Vorgang benutzt
     * `einstieg`     das Tool, mit dem der Vorgang beginnt
     */
    public const VORGAENGE = [
        'basisrezept_anlegen' => [
            'titel' => 'Basisrezept anlegen',
            'kind' => 'recipe',
            'doc_slug' => 'workflow.rezept_anlegen_mcp',
            'feature' => 'ai_generate_recipe',
            'prompt_keys' => ['recipe.generator'],
            'einstieg' => 'foodalchemist.recipes.POST',
        ],
        'gericht_anlegen' => [
            'titel' => 'Gericht / Verkaufsrezept anlegen',
            'kind' => 'gericht',
            // Zusammengeführtes Dossier (2026-09-06): EIN Prozess, zwei Ausführende — Weg A die
            // SaaS-KI über die Planungs-Leitstelle, Weg B der Agent in ihrer Rolle, mit derselben
            // Schrittfolge. Der ältere Slug `workflow.gericht_anlegen_mcp` beschrieb nur Weg A und
            // ist globales Master-Wissen (per MCP weder editier- noch deaktivierbar) — deshalb trägt
            // das team-eigene Dossier die zusammengeführte Wahrheit und nennt den alten unter `ersetzt`.
            'doc_slug' => 'workflow.verkaufsgericht_anlegen_mcp',
            'feature' => 'ai_generate_recipe',
            'prompt_keys' => ['vk.generator'],
            'einstieg' => 'foodalchemist.verkaufsrezepte.POST',
        ],
        'konzept_anlegen' => [
            'titel' => 'Konzept anlegen (Concepter)',
            'kind' => 'concept',
            'doc_slug' => 'workflow.konzept_anlegen_mcp',
            'feature' => 'concept.brief_geruest',
            'prompt_keys' => ['concept.brief_geruest', 'concept.plan', 'concept.wording'],
            'einstieg' => 'foodalchemist.concepts.POST',
        ],
        'foodbook_anlegen' => [
            'titel' => 'Foodbook anlegen',
            'kind' => 'foodbook',
            'doc_slug' => 'workflow.foodbook_anlegen_mcp',
            'feature' => 'foodbook.grundgeruest',
            'prompt_keys' => ['foodbook.grundgeruest', 'foodbook.kundentext'],
            'einstieg' => 'foodalchemist.foodbooks.POST',
        ],
        'angebot_erstellen' => [
            'titel' => 'Angebot erstellen',
            'kind' => 'angebot',
            'doc_slug' => 'workflow.angebot_erstellen_mcp',
            'feature' => 'concept.brief_geruest',
            'prompt_keys' => ['foodbook.kundentext'],
            'einstieg' => 'foodalchemist.angebote.POST',
        ],
        'speiseplan_erstellen' => [
            'titel' => 'Speiseplan erstellen',
            'kind' => 'speiseplan',
            'doc_slug' => 'workflow.speiseplan_erstellen_mcp',
            'feature' => 'ai_generate_recipe',
            'prompt_keys' => [],
            'einstieg' => 'foodalchemist.speiseplaene.POST',
        ],
        'speisekarte_anlegen' => [
            'titel' => 'Speisekarte anlegen',
            'kind' => 'speisekarte',
            // Für die Speisekarte gibt es (noch) kein Workflow-Dossier — das meldet der
            // Vorgang ehrlich, statt die Prosa eines anderen Vorgangs zu borgen.
            'doc_slug' => null,
            'feature' => 'ai_generate_recipe',
            // Das Karten-Wording laeuft ueber `foodbook.kundentext` (SpeisekarteService::kiWordingVorschlag)
            // — es gibt keine eigene Registry-Zeile `speisekarte.wording`.
            'prompt_keys' => ['foodbook.kundentext'],
            'einstieg' => 'foodalchemist.speisekarten.POST',
        ],
        'format_anlegen' => [
            'titel' => 'Format anlegen',
            'kind' => 'format',
            'doc_slug' => null,
            'feature' => 'format.grundgeruest',
            'prompt_keys' => ['format.grundgeruest'],
            'einstieg' => 'foodalchemist.formats.POST',
        ],
        'gp_aus_la_anlegen' => [
            'titel' => 'Grundprodukt aus Lieferantenartikel anlegen (LA-First)',
            // Grundprodukte haben keinen Reife-Adapter — es gibt kein Soll zu melden.
            'kind' => null,
            'doc_slug' => 'workflow.gp_aus_la_anlegen_mcp',
            'feature' => 'ai_generate_recipe',
            'prompt_keys' => [],
            'einstieg' => 'foodalchemist.gps.MATCH',
        ],
        'preis_margen_monitoring' => [
            'titel' => 'Preis- und Margen-Monitoring',
            'kind' => null,
            'doc_slug' => 'workflow.preis_margen_monitoring_mcp',
            'feature' => 'ai_generate_recipe',
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
        $doc = $v['doc_slug'] !== null ? $this->wissen->getDocument($team, $v['doc_slug']) : null;
        if ($v['doc_slug'] === null) {
            $nichtVerfuegbar[] = ['was' => 'ablauf_prosa', 'warum' => 'Für diesen Vorgang gibt es kein Workflow-Dossier.'];
        } elseif ($doc === null) {
            $nichtVerfuegbar[] = ['was' => 'ablauf_prosa',
                'warum' => 'Das Dossier «' . $v['doc_slug'] . '» ist nicht aktiv oder für dieses Team nicht sichtbar.'];
        }

        $kopf = $doc !== null ? $this->wissen->frontmatterOf((string) $doc->content_md) : [];
        $kette = $this->kette($kopf);
        if ($kette === null && $doc !== null) {
            $nichtVerfuegbar[] = ['was' => 'kette',
                'warum' => 'Das Dossier «' . $v['doc_slug'] . '» nennt im Kopf keine required_tools.'];
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
            'dossier' => $doc !== null ? [
                'slug' => $doc->slug,
                'titel' => $doc->title,
                'version' => (int) $doc->version,
                'abschnitte' => $this->abschnitte((string) $doc->content_md),
                'lesen_mit' => ['tool' => 'foodalchemist.knowledge.GET', 'args' => ['slug' => $doc->slug]],
            ] : null,
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

        // Dieselbe Auswahl, die der Generator trifft — nicht eine zweite, die daneben liegen könnte.
        $doc = $this->wissen->regelwerkDokumentFuer($team, $v['feature']);

        return [
            'quelle' => $doc !== null ? 'dossier' : 'keine',
            'dokumente' => $doc !== null ? [[
                'slug' => $doc->slug, 'titel' => $doc->title, 'kategorie' => 'regelwerk',
                'mode' => 'pflicht', 'zeichen' => (int) $doc->char_count, 'prompt_key' => null,
            ]] : [],
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
