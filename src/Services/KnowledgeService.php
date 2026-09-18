<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\WissenGesperrtException;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Support\TeamScope;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;

/**
 * #469 v3 — Schreib-Layer fürs Wissens-Modul (MCP-Wachstum, „Wissen von außen").
 * LLM-First: Tools rufen diesen Service, nie Models direkt.
 *
 * Leitplanken (analog Phase-A-Rezept-Kaskade):
 *  - Herkunft `created_via='mcp'`; Bindungen `source='mcp'` (Provenienz/Audit).
 *  - Vault-verwaltete Docs des EIGENEN Teams sind via MCP editierbar (Browser-Parität) —
 *    der Import-Guard (App-wins) schützt den Edit: content_hash weicht dann von
 *    imported_hash ab, der Re-Import überschreibt NICHT (außer --force). source_path bleibt
 *    (Provenienz).
 *  - GLOBALES Wissen (team_id NULL) gehört dem MASTER-Team und wird von ihm gepflegt; für
 *    jedes andere Team ist es read-only. Bis 2026-09-11 war es für JEDEN unveränderlich —
 *    das passte, solange global „geerbter Seed" hiess, und friert den kuratierten Bestand
 *    ein, sobald er selbst global ist. Der eine Riegel dafür ist {@see findAenderbar()}.
 */
class KnowledgeService
{

    /** Max. Slug-Länge einer Wissens-Kategorie = Breite von foodalchemist_knowledge_documents.category (VARCHAR 24). */
    private const MAX_CATEGORY_SLUG_LEN = 24;

    /**
     * Legt ein neues Wissens-Dokument an (inaktiv). Optional: Aliase + Einsatzort-Bindungen.
     *
     * @param  array{title?:string,category?:string,content_md?:string,source?:string,aliases?:array,bind_layers?:array,active?:bool}  $data
     */
    public function create(Team $team, array $data): object
    {
        $title = trim((string) ($data['title'] ?? ''));
        $category = trim((string) ($data['category'] ?? ''));
        if ($title === '' || $category === '') {
            throw new RuntimeException('title und category sind Pflicht.');
        }
        $this->assertKategorie($team, $category);
        $art = $this->pruefeArt($data['art'] ?? null);
        $einordnung = \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::payload($art, $data['geltung'] ?? [], $data['datenwerte'] ?? []);

        $content = (string) ($data['content_md'] ?? '');
        $source = ((string) ($data['source'] ?? 'mcp')) ?: 'mcp';
        // #505: expliziter Slug (z. B. Vault-konsistent skill.foo_bar) hat Vorrang, damit MCP-Anlage
        // und späterer Vault-Import denselben Slug treffen (reconcilebar, keine Dubletten).
        $slug = $this->uniqueSlug($title, isset($data['slug']) ? (string) $data['slug'] : null);
        $now = now();

        $id = DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) UuidV7::generate(),
            // Entscheid Dominique 2026-09-11: „alles was ich jetzt reingebe ist master".
            // Der Kurator legt GLOBAL an — sein Bestand IST der globale Bestand. Jedes
            // andere Team legt weiter team-eigen an.
            'team_id' => TeamScope::isMaster($team) ? null : $team->id,
            'slug' => $slug,
            'title' => $title,
            'category' => $category,
            ...$einordnung,
            'content_md' => $content,
            'version' => 1,
            'content_hash' => hash('sha256', $content),
            'imported_hash' => null,       // nicht Vault-verwaltet → Import-Guard N/A
            'char_count' => mb_strlen($content),
            // Entscheid Dominique 2026-09-07: AKTIV als Default. Die frühere Quarantäne
            // (immer inaktiv, Freigabe nur im Browser) war als Schutz gedacht, wirkte in der
            // Praxis aber als stille Falle: `POST` + vergessenes `SET_ACTIVE` hinterlässt ein
            // fertiges Dossier, das nirgends wirkt und dem man das nicht ansieht. Wer bewusst
            // einen Entwurf will, setzt `active: false` — dann ist die Quarantäne eine
            // Entscheidung statt eines Standards.
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'source_path' => null,
            'created_via' => $source,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ($this->cleanAliases($data['aliases'] ?? []) as $alias) {
            DB::table('foodalchemist_knowledge_aliases')->insertOrIgnore([
                'alias_slug' => $alias, 'knowledge_document_id' => $id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->verweigereBindLayers($data['bind_layers'] ?? null);

        return $this->find($slug);
    }

    /**
     * Der EINE Schreib-Riegel für Wissens-Dokumente: findet das Dossier und prüft in
     * demselben Schritt, ob dieses Team es anfassen darf.
     *
     * Warum öffentlich und warum überhaupt eine eigene Methode: `knowledge.EINORDNEN
     * --pruefen` versprach am 2026-09-11 sechzehn Einordnungen und schrieb zwölf. Der
     * Trockenlauf prüfte das FORMAT (`WissensGeltung::payload`) und nannte das im Kommentar
     * „dieselbe Pruefung wie beim Schreiben" — er prüfte weder Existenz noch Recht. Eine
     * Zusage, die von der Tat abweicht, ist schlimmer als keine Zusage. Seitdem rufen beide
     * Seiten dieselbe Methode.
     *
     * Die Meldungen trennen bewusst zwei Fälle: globales Wissen heisst beim Namen (der Master
     * pflegt es), ein FREMD-Team-Dossier heisst „nicht gefunden" — sonst verrät die
     * Fehlermeldung die Existenz fremder Slugs.
     *
     * @param  string  $verb  passt die Meldung an die Handlung an („editierbar", „löschbar", …)
     *
     * @throws RuntimeException             wenn es das Dossier nicht (sichtbar) gibt
     * @throws WissenGesperrtException      wenn es global ist und dieses Team nicht Master
     */
    public function findAenderbar(Team $team, string $slug, string $verb = 'editierbar'): object
    {
        $doc = DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->whereNull('deleted_at')->first();
        if ($doc === null) {
            throw new RuntimeException("Wissens-Dokument \"{$slug}\" nicht gefunden.");
        }
        if (! TeamScope::mayWrite($doc->team_id, $team)) {
            if ($doc->team_id === null) {
                throw new WissenGesperrtException(
                    "\"{$slug}\" ist globales Master-Wissen — nur das Master-Team hat es {$verb}."
                );
            }
            throw new RuntimeException("Wissens-Dokument \"{$slug}\" nicht gefunden.");
        }

        return $doc;
    }

    /**
     * Aktualisiert ein Wissens-Dokument (per slug) — auch Vault-verwaltete des EIGENEN
     * Teams (Browser-Parität; der Import-Guard schützt den Edit vor Re-Import-Überschreiben,
     * s. Klassen-Doc). Globales Wissen pflegt das Master-Team, sonst niemand.
     * Inhalts-Änderung ⇒ version+1 + neuer content_hash. Optional: Aliase/Bindungen ergänzen.
     *
     * @param  array{title?:string,category?:string,content_md?:string,active?:bool,aliases?:array,bind_layers?:array}  $data
     */
    public function update(Team $team, string $slug, array $data): object
    {
        // Vault-Lock aufgehoben (Browser-Parität): auch Vault-verwaltete Docs des EIGENEN
        // Teams sind editierbar. Der Inhalts-Edit bumpt content_hash, lässt imported_hash
        // unberührt ⇒ der knowledge-import erkennt „in-App editiert" und überschreibt NICHT
        // (App-wins, außer --force). source_path bleibt (Provenienz + reversibel via --force).
        $doc = $this->findAenderbar($team, $slug, 'editierbar');

        $payload = ['updated_at' => now()];
        if (array_key_exists('title', $data) && trim((string) $data['title']) !== '') {
            $payload['title'] = trim((string) $data['title']);
        }
        if (array_key_exists('art', $data)) {
            // Leerstring = ausdruecklich zuruecksetzen auf „noch nicht eingeordnet".
            $payload['art'] = trim((string) $data['art']) === '' ? null : $this->pruefeArt($data['art']);
        }
        if (array_key_exists('category', $data) && trim((string) $data['category']) !== '') {
            $cat = trim((string) $data['category']);
            $this->assertKategorie($team, $cat);
            $payload['category'] = $cat;
        }
        if (array_key_exists('active', $data)) {
            $payload['active'] = (bool) $data['active'];
        }
        if (array_key_exists('content_md', $data)) {
            $content = (string) $data['content_md'];
            $payload['content_md'] = $content;
            $payload['content_hash'] = hash('sha256', $content);
            $payload['char_count'] = mb_strlen($content);
            $payload['version'] = (int) $doc->version + 1;
        }
        if (array_intersect(['art', 'geltung', 'datenwerte'], array_keys($data)) !== []) {
            $payload = array_merge($payload, \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::payload(
                array_key_exists('art', $payload) ? $payload['art'] : ($doc->art ?? null),
                $data['geltung'] ?? \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::lesen($doc->geltung ?? null),
                $data['datenwerte'] ?? \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::lesen($doc->datenwerte ?? null),
            ));
            $payload['version'] = (int) $doc->version + 1;
        }
        DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->update($payload);

        $now = now();
        foreach ($this->cleanAliases($data['aliases'] ?? []) as $alias) {
            DB::table('foodalchemist_knowledge_aliases')->insertOrIgnore([
                'alias_slug' => $alias, 'knowledge_document_id' => $doc->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->verweigereBindLayers($data['bind_layers'] ?? null);

        $fresh = $this->find($slug);
        // Recall-Index nachziehen (A1): Inhalts-/Status-Edit ⇒ neu embedden bzw. bei
        // Deaktivierung purgen (queueDocument gated intern auf active). Async, no-op ohne Provider.
        app(KnowledgeEmbeddingService::class)->queueDocument($fresh);

        return $fresh;
    }

    /** Max. Einträge je `knowledge.IMPORT`-Aufruf (Briefing Zutaten-Bulk-Import: „100 bis 500"). */
    public const IMPORT_MAX = 500;

    /**
     * Massenanlage/-aktualisierung über `foodalchemist.knowledge.IMPORT` (Briefing
     * Zutaten-Bulk-Import, 2026-09-18: 11.966 Zutaten-Dossiers, 11.966 Einzel-`POST`-Aufrufe sind
     * über MCP nicht vertretbar).
     *
     * Idempotent über den Slug via Content-Hash: neuer Slug → `angelegt`; bestehender Slug mit
     * gleichem `content_md`-Hash → `unveraendert` (kein Schreiben, keine Versionsbumps); anderer
     * Hash → `aktualisiert` (version+1). Jeder Eintrag bekommt einen Status
     * `angelegt|aktualisiert|unveraendert|abgelehnt` + bei `abgelehnt` einen `grund`
     * (Kategorie unbekannt, Wissensart ungültig, Slug-Muster, > 4.000 Zeichen, Pflichtfeld fehlt,
     * fremdes Team).
     *
     * NICHT über {@see create()}/{@see update()} gebaut: {@see uniqueSlug()} hängt bei einer
     * Slug-Kollision STILLSCHWEIGEND `-2` an — auch an einen EXPLIZITEN Slug — und prüft dabei
     * NICHT `deleted_at` (anders als der Idempotenz-Check hier). Ein soft-gelöschtes Dossier mit
     * demselben Slug würde `create()` also unbemerkt auf einen anderen Slug ausweichen lassen und
     * die Idempotenz-Zusage dieses Tools brechen — hier deshalb direkte, für den Bulk-Fall
     * zugeschnittene Schreiblogik, die dieselben Validatoren (`assertKategorie`, `pruefeArt`,
     * `WissensGeltung::payload`) wie `create()`/`update()` wiederverwendet statt sie zu duplizieren.
     *
     * Transaktion JE EINTRAG (bewusste Abweichung von `knowledge.EINORDNEN`, das GAR KEINE
     * Transaktion nutzt — dort ist ein Eintrag ein einzelnes `UPDATE`, hier sind es mehrere
     * zusammengehörige Schreibvorgänge, die nicht halb angewendet stehen bleiben sollen). Ein
     * abgebrochener LAUF bleibt trotzdem beliebig oft wiederholbar: jeder Eintrag ist für sich
     * idempotent, ein bereits geschriebener Eintrag meldet beim nächsten Versuch `unveraendert`.
     *
     * Embedding: async über {@see KnowledgeEmbeddingService::queueDocument()} für jeden `angelegt`/
     * `aktualisiert`-Eintrag — derselbe Pfad, den `update()`/`setActive()` schon nutzen. Intern auf
     * `active=1` gegated: bei `active=false` (empfohlener Entwurfs-Weg, s. Regelwerk Zutaten-Dossier
     * §8) ist der Aufruf ein günstiger No-op, das eigentliche Embedding entsteht erst bei der
     * (blockweisen) Aktivierung.
     *
     * @param  list<array{slug?:string,title?:string,category?:string,art?:string,content_md?:string,frontmatter?:array,active?:bool}>  $eintraege
     * @return list<array{index:int,slug:?string,status:string,grund?:string,version?:int}>
     */
    public function import(Team $team, array $eintraege): array
    {
        $ergebnisse = [];
        foreach (array_values($eintraege) as $index => $eintrag) {
            $ergebnisse[] = DB::transaction(fn () => $this->importEintrag($team, $index, $eintrag));
        }

        return $ergebnisse;
    }

    /**
     * `frontmatter` wird entgegengenommen, aber NICHT gespeichert — es gibt keine eigene Spalte
     * dafür, `content_md` trägt die YAML-Frontmatter bereits als Text (Regelwerk Zutaten-Dossier
     * §5: das ganze Dossier inkl. Frontmatter ist EIN Textblock). Der Parameter existiert nur, damit
     * ein Aufrufer strukturierte Metadaten (z. B. `anker_slug`) mitschicken kann, ohne dass das Tool
     * sie ablehnt — für künftige Auswertungen, heute wirkungslos. Das gehört so in die
     * Tool-Beschreibung, nicht stillschweigend verschluckt.
     */
    private function importEintrag(Team $team, int $index, mixed $eintrag): array
    {
        if (! is_array($eintrag)) {
            return ['index' => $index, 'slug' => null, 'status' => 'abgelehnt', 'grund' => 'Eintrag ist kein Objekt.'];
        }
        $slug = trim((string) ($eintrag['slug'] ?? ''));
        $title = trim((string) ($eintrag['title'] ?? ''));
        $category = trim((string) ($eintrag['category'] ?? ''));
        $content = (string) ($eintrag['content_md'] ?? '');
        if ($slug === '' || $title === '' || $category === '' || $content === '') {
            return ['index' => $index, 'slug' => $slug ?: null, 'status' => 'abgelehnt', 'grund' => 'slug, title, category und content_md sind Pflicht.'];
        }
        // Slug-Muster: lowercase + a-z0-9._- (deckt reale Vault-Slugs wie
        // "zutat.acai_berry--verhalten-aroma-2" ab, ohne raten zu müssen was sonst noch vorkommt).
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $slug) !== 1) {
            return ['index' => $index, 'slug' => $slug, 'status' => 'abgelehnt', 'grund' => 'Slug-Muster ungültig — nur a-z, 0-9, ".", "_", "-", muss mit a-z/0-9 beginnen.'];
        }
        $zeichen = mb_strlen($content);
        if ($zeichen > 4000) {
            return ['index' => $index, 'slug' => $slug, 'status' => 'abgelehnt', 'grund' => "content_md > 4.000 Zeichen ({$zeichen})."];
        }
        try {
            $this->assertKategorie($team, $category);
            $art = $this->pruefeArt($eintrag['art'] ?? null);
        } catch (RuntimeException $e) {
            return ['index' => $index, 'slug' => $slug, 'status' => 'abgelehnt', 'grund' => $e->getMessage()];
        }

        $bestehend = DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->whereNull('deleted_at')->first();
        if ($bestehend !== null && ! TeamScope::mayWrite($bestehend->team_id, $team)) {
            return ['index' => $index, 'slug' => $slug, 'status' => 'abgelehnt', 'grund' => 'Slug gehört einem anderen Team bzw. ist globales Master-Wissen.'];
        }

        $hash = hash('sha256', $content);
        if ($bestehend !== null && $bestehend->content_hash === $hash) {
            return ['index' => $index, 'slug' => $slug, 'status' => 'unveraendert', 'version' => (int) $bestehend->version];
        }

        $active = (bool) ($eintrag['active'] ?? false);
        $now = now();
        try {
            if ($bestehend !== null) {
                $einordnung = \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::payload(
                    $art, \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::lesen($bestehend->geltung ?? null),
                    \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::lesen($bestehend->datenwerte ?? null),
                );
                $version = (int) $bestehend->version + 1;
                DB::table('foodalchemist_knowledge_documents')->where('id', $bestehend->id)->update([
                    'title' => $title, 'category' => $category, ...$einordnung,
                    'content_md' => $content, 'content_hash' => $hash, 'char_count' => $zeichen,
                    'version' => $version, 'active' => $active, 'updated_at' => $now,
                ]);
                $status = 'aktualisiert';
            } else {
                $einordnung = \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::payload($art, [], []);
                DB::table('foodalchemist_knowledge_documents')->insert([
                    'uuid' => (string) UuidV7::generate(),
                    'team_id' => TeamScope::isMaster($team) ? null : $team->id,
                    'slug' => $slug, 'title' => $title, 'category' => $category, ...$einordnung,
                    'content_md' => $content, 'version' => 1, 'content_hash' => $hash,
                    'imported_hash' => null, 'char_count' => $zeichen, 'active' => $active,
                    'source_path' => null, 'created_via' => 'mcp_import',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $version = 1;
                $status = 'angelegt';
            }
        } catch (RuntimeException $e) {
            return ['index' => $index, 'slug' => $slug, 'status' => 'abgelehnt', 'grund' => $e->getMessage()];
        }

        app(KnowledgeEmbeddingService::class)->queueDocument($this->find($slug));

        return ['index' => $index, 'slug' => $slug, 'status' => $status, 'version' => $version];
    }

    /**
     * Team-eigenes Wissensdokument löschen (D12). Globales Master-/Seed-Wissen bleibt read-only.
     * Räumt den Recall-Index best-effort mit. Geteilte Web-/MCP-Wahrheit (Browser + knowledge.DELETE).
     */
    public function delete(Team $team, string $slug): void
    {
        $doc = $this->findAenderbar($team, $slug, 'löschbar');
        // ⚠ Partition über partitionTeamId, NICHT `(int) $doc->team_id`: bei globalem Wissen
        // wäre das eine 0 — gelöscht würde in einer Partition, in der nie etwas lag, und der
        // echte Vektor bliebe als Waise zurück. Fiel bis 2026-09-11 nicht auf, weil die
        // Sperre vorher griff und globale Dossiers nie hier ankamen.
        app(KnowledgeEmbeddingService::class)->deleteDocument((int) $doc->id, $doc->team_id);
        DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->delete();
    }

    /**
     * Alias eines team-eigenen Wissensdokuments anlegen (D12). Idempotent. Gibt den normalisierten
     * Alias-Slug zurück. Geteilte Web-/MCP-Wahrheit (Browser::addAlias + knowledge.ALIAS add).
     */
    public function addAlias(Team $team, string $slug, string $alias): string
    {
        $doc = $this->findAenderbar($team, $slug, 'mit Aliassen pflegbar');
        $aliasSlug = Str::slug(trim($alias), '_');
        if ($aliasSlug === '') {
            throw new \RuntimeException('Alias darf nicht leer sein.');
        }
        $exists = DB::table('foodalchemist_knowledge_aliases')
            ->where('alias_slug', $aliasSlug)->where('knowledge_document_id', $doc->id)->exists();
        if (! $exists) {
            DB::table('foodalchemist_knowledge_aliases')->insert([
                'alias_slug' => $aliasSlug, 'knowledge_document_id' => $doc->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $aliasSlug;
    }

    /**
     * Alias eines team-eigenen Wissensdokuments entfernen (D12). Löst das Eltern-Doc über die Alias-ID
     * auf und prüft Eigentum. Geteilte Web-/MCP-Wahrheit (Browser::removeAlias + knowledge.ALIAS remove).
     */
    public function removeAlias(Team $team, int $aliasId): void
    {
        $docId = DB::table('foodalchemist_knowledge_aliases')->where('id', $aliasId)->value('knowledge_document_id');
        if ($docId === null) {
            throw new \RuntimeException('Alias nicht gefunden.');
        }
        $doc = DB::table('foodalchemist_knowledge_documents')->where('id', (int) $docId)->first();
        if ($doc === null || ! TeamScope::mayWrite($doc->team_id, $team)) {
            throw new \RuntimeException('Alias nicht team-eigen.');
        }
        DB::table('foodalchemist_knowledge_aliases')->where('id', $aliasId)->delete();
    }

    /** Max. Alias-Kandidaten je `knowledge.ALIAS`-Prüfaufruf. */
    public const ALIAS_CHECK_MAX = 500;

    /**
     * Massen-Lesezugriff: welche Alias-Kandidaten sind schon belegt, und auf welchem Dossier
     * (Briefing Zutaten-Bulk-Import, 2026-09-18). {@see addAlias()} schluckt eine Kollision bisher
     * STILLSCHWEIGEND — `alias_slug` ist system­weit eindeutig (kein Team-Bezug in der Tabelle), der
     * neue `add`-Aufruf legt das Dossier trotzdem an, nur ohne den Alias, und niemand merkt es
     * (Anlass: „acerola" lag schon als Alias auf einem völlig fremden Dossier). Normalisiert jeden
     * Kandidaten GENAU wie `addAlias()` (`Str::slug($a, '_')`), damit `belegt: false` hier wirklich
     * heißt, dass `add` mit demselben Text nicht kollidiert.
     *
     * @param  list<string>  $aliases
     * @return list<array{alias:string, alias_slug:string, belegt:bool, slug:?string, title:?string}>
     */
    public function checkAliases(array $aliases): array
    {
        $ergebnisse = [];
        foreach ($aliases as $roh) {
            $alias = trim((string) $roh);
            if ($alias === '') {
                continue;
            }
            $aliasSlug = Str::slug($alias, '_');
            if ($aliasSlug === '') {
                $ergebnisse[] = ['alias' => $alias, 'alias_slug' => '', 'belegt' => false, 'slug' => null, 'title' => null];

                continue;
            }
            $treffer = DB::table('foodalchemist_knowledge_aliases as a')
                ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'a.knowledge_document_id')
                ->where('a.alias_slug', $aliasSlug)->whereNull('d.deleted_at')
                ->first(['d.slug', 'd.title']);
            $ergebnisse[] = [
                'alias' => $alias, 'alias_slug' => $aliasSlug, 'belegt' => $treffer !== null,
                'slug' => $treffer->slug ?? null, 'title' => $treffer->title ?? null,
            ];
        }

        return $ergebnisse;
    }

    /** Der Satz, den jeder Bindungs-Schreibpfad ausgibt — einmal formuliert, nicht dreimal. */
    public const BINDUNG_ABGESCHAFFT = 'Bindungen (`knowledge_bindings`) sind abgeschafft (Spec 52 · F2). '
        . 'Sie wurden zur Laufzeit nicht mehr gelesen — eine neue Bindung wäre eine Zeile, die nichts tut. '
        . 'Was ein Prompt verbindlich bekommt, sagt der Kanon: `foodalchemist.knowledge_canon.PUT` '
        . '(scope=prompt_key, scope_key=<Prompt-Key>, slug=<Dossier>, mode=pflicht|wenn_platz). '
        . 'Welche Kategorie ein Schritt SUCHEN darf, sagt `foodalchemist.knowledge_routings.PUT`. '
        . 'Bestehende Alt-Bindungen lösen: `foodalchemist.knowledge.UNBIND`.';

    /**
     * ABGESCHAFFT (Spec 52 · F2, 2026-09-08). Wirft, statt eine wirkungslose Zeile anzulegen.
     *
     * Der Grund für „wirft" statt „ignoriert": eine Bindung, die entsteht und nichts tut,
     * ist exakt die Fehlerklasse, gegen die diese Spec antritt — der Kurator wird angewiesen,
     * etwas zu tun, das nachweislich nichts bewirkt (Befund `J`). Lieber ein lauter Fehler
     * mit dem richtigen Weg im Text.
     *
     * Der Rückweg bleibt offen: {@see unbindExisting()} löst Alt-Bindungen weiterhin.
     */
    public function bindLayer(Team $team, int $docId, string $targetKey, string $mode, string $source = 'mcp'): void
    {
        throw new RuntimeException(self::BINDUNG_ABGESCHAFFT);
    }

    /**
     * Bindet ein BESTEHENDES, sichtbares Doc an einen Einsatzort — auch globalen
     * Seed / Vault-Kanon. Anders als update() (Inhalts-Edit, für Vault-Docs
     * gesperrt) ist Binden ein rein kuratorischer Akt: der Doc-Inhalt wird nicht
     * angefasst, darum KEIN source_path-/owns-Guard auf dem DOC. Sichtbarkeit =
     * globaler Seed (team_id NULL) ODER eigene Ancestry; sonst NOT_FOUND (kein
     * Existenz-Leak über die Teamgrenze). Die Bindung selbst trägt team_id des
     * Callers (siehe bindLayer) → tenancy-scoped. Idempotent.
     */
    public function bindExisting(Team $team, string $slug, string $targetKey, string $mode = 'discovery'): object
    {
        $doc = $this->findSichtbar($team, $slug);
        $this->bindLayer($team, (int) $doc->id, $targetKey, $mode, 'mcp');

        return $this->find($slug);
    }

    /**
     * Löst eine Layer-Bindung — aber NUR eine team-eigene (owns): globale/Fremd-
     * Bindungen bleiben unberührt. Soft-Delete, damit Reads (whereNull deleted_at)
     * sie ignorieren und ein späteres Re-Bind sauber neu anlegt. Gibt zurück, ob
     * etwas gelöst wurde (idempotent).
     */
    public function unbindExisting(Team $team, string $slug, string $targetKey): bool
    {
        $doc = $this->findSichtbar($team, $slug);
        $n = DB::table('foodalchemist_knowledge_bindings')->whereNull('deleted_at')
            ->where('knowledge_document_id', $doc->id)
            ->where('binding_type', 'layer')->where('target_key', trim($targetKey))
            ->where('team_id', $team->id)
            ->update(['active' => false, 'deleted_at' => now(), 'updated_at' => now()]);

        return $n > 0;
    }

    /** Doc per Slug, sichtbarkeits-gescoped (globaler Seed + eigene Ancestry); sonst NOT_FOUND. */
    private function findSichtbar(Team $team, string $slug): object
    {
        $doc = TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at')->where('slug', $slug),
            'team_id', $team
        )->first();
        if ($doc === null) {
            throw new RuntimeException("Wissens-Dokument \"{$slug}\" nicht gefunden.");
        }

        return $doc;
    }

    /**
     * Wirft, wenn die Kategorie nicht im (aktiven) Vokabular steht.
     *
     * Öffentlich seit 22·H1 (V-044): die beiden LESE-Tools (`knowledge.LIST`/`SEARCH`) trugen
     * die Kategorien als JSON-Schema-Enum im Code — eine Handkopie einer zur Laufzeit
     * pflegbaren Tabelle. Ein `getSchema()` darf keine DB anfassen (seiteneffektfrei, wird
     * bei jeder LLM-Anfrage gerufen), also fällt die Prüfung in `execute()` — und zwar über
     * DIESE Methode, damit Lese- und Schreibweg dieselbe Menge und dieselbe
     * „Verfügbar: …"-Meldung benutzen statt zwei Wahrheiten zu pflegen.
     */
    /**
     * Spec 52/H1 — die Wissensart pruefen.
     *
     * Anders als die Kategorie ist die Art KEIN pflegbares Vokabular, sondern eine
     * Code-Konstante: der Prompt-Bau entscheidet anhand dieser Werte (`ablauf` kommt nie in
     * einen Prompt). Waere die Liste zur Laufzeit erweiterbar, koennte er sich nicht darauf
     * verlassen — ein frei erfundener Wert waere still wirkungslos.
     */
    private function pruefeArt(mixed $art): ?string
    {
        $wert = trim((string) ($art ?? ''));
        if ($wert === '') {
            return null;
        }
        if (! \Platform\FoodAlchemist\Services\Knowledge\Wissensart::gueltig($wert)) {
            throw new RuntimeException(
                'Unbekannte Wissensart «'.$wert.'». Erlaubt: '
                .implode(', ', \Platform\FoodAlchemist\Services\Knowledge\Wissensart::ALLE).'.'
            );
        }

        return $wert;
    }

    public function assertKategorie(Team $team, string $slug): void
    {
        $ok = DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')
            ->where('active', true)->where('slug', $slug)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $team->id))
            ->exists();
        if (! $ok) {
            $verfuegbar = DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')
                ->where('active', true)
                ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $team->id))
                ->orderBy('slug')->pluck('slug')->implode(', ');
            throw new RuntimeException("Unbekannte Kategorie \"{$slug}\". Verfügbar: {$verfuegbar}");
        }
    }

    /**
     * MCP-Kategorie-Anlage: neue Wissens-Kategorie team-scoped anlegen. Slug aus dem
     * Label, Dedup gegen globales Master-Vokabular + eigenes Team. SOFORT aktiv, damit
     * sie unmittelbar in knowledge.POST/PUT ({@see assertKategorie}) nutzbar ist — anders
     * als Docs, die in Quarantäne bleiben (eine leere Kategorie fließt in keinen Prompt,
     * daher unkritisch). Spiegelt Settings\Wissenskategorien::create().
     *
     * @return array{slug:string,label:string,description:?string,scope:string,active:bool}
     */
    public function createCategory(Team $team, string $label, ?string $description = null, ?string $slug = null): array
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('label ist Pflicht.');
        }
        // Slug: expliziter Override (für deutsche Formen wie ernaehrung/geschaeftsmodell),
        // sonst aus dem Label abgeleitet — beides normalisiert (wie die Settings-UI).
        $slug = ($slug !== null && trim($slug) !== '') ? Str::slug($slug, '_') : Str::slug($label, '_');
        if ($slug === '') {
            throw new RuntimeException("Aus «{$label}» lässt sich kein gültiger Kategorie-Slug bilden.");
        }
        // Der Slug muss in foodalchemist_knowledge_documents.category (VARCHAR 24) passen —
        // sonst liesse sich die Kategorie zwar anlegen, aber kein Doc darin speichern (knowledge.POST
        // kippt beim Insert). Lieber hier klar abweisen als eine unbrauchbare Kategorie erzeugen.
        if (mb_strlen($slug) > self::MAX_CATEGORY_SLUG_LEN) {
            throw new RuntimeException(
                "Slug «{$slug}» ist zu lang (".mb_strlen($slug).' Zeichen, max. '.self::MAX_CATEGORY_SLUG_LEN
                .'). Kürzeres label oder einen kürzeren slug wählen.'
            );
        }
        $exists = DB::table('foodalchemist_knowledge_categories')
            ->where('slug', $slug)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $team->id))
            ->whereNull('deleted_at')->exists();
        if ($exists) {
            throw new RuntimeException("Kategorie \"{$slug}\" existiert schon (global oder in diesem Team).");
        }
        $description = ($description !== null && trim($description) !== '') ? trim($description) : null;
        $maxSort = (int) DB::table('foodalchemist_knowledge_categories')->max('sort_order');
        DB::table('foodalchemist_knowledge_categories')->insert([
            'uuid' => (string) Str::uuid7(),
            'team_id' => $team->id,
            'slug' => $slug,
            'label' => $label,
            'description' => $description,
            'sort_order' => $maxSort + 10,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['slug' => $slug, 'label' => $label, 'description' => $description, 'scope' => 'team', 'active' => true];
    }

    /**
     * MCP: sichtbare Wissens-Kategorien (globales Master-Vokabular + eigenes Team),
     * sortiert. Standard nur aktive; $includeInactive=true zeigt auch deaktivierte
     * (Selbst-Kontrolle des Agenten über seine eigenen/inaktiven Kategorien).
     *
     * @return list<array{slug:string,label:string,description:?string,scope:string,active:bool}>
     */
    public function listCategories(Team $team, bool $includeInactive = false): array
    {
        $q = DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')
            ->where(fn ($qq) => $qq->whereNull('team_id')->orWhere('team_id', $team->id));
        if (! $includeInactive) {
            $q->where('active', true);
        }

        return $q->orderBy('sort_order')->orderBy('slug')
            ->get(['slug', 'label', 'description', 'team_id', 'active'])
            ->map(fn ($r) => [
                'slug' => $r->slug,
                'label' => $r->label,
                'description' => $r->description,
                'scope' => $r->team_id === null ? 'global' : 'team',
                'active' => (bool) $r->active,
            ])->all();
    }

    /**
     * MCP: ein Wissens-Dokument aktiv/inaktiv schalten (Kuration — „Einstampfen" alter
     * Docs oder Freigeben eigener Entwürfe). Anders als {@see update()} NICHT durch den
     * Vault-Content-Guard gesperrt: der active-Flag ist reine Kuration, kein Inhalt, und
     * der knowledge-import fasst ihn NICHT an (Re-Import ändert nur Inhalt) — ein
     * Deaktivieren bleibt also bestehen. Spiegelt Browser\Knowledge\Browser::toggleActive:
     * nur das Besitzer-Team darf (de)aktivieren; geerbtes/globales Master-Wissen ist gesperrt.
     *
     * @return array{slug:string,title:string,active:bool,vault_managed:bool,changed:bool}
     */
    public function setActive(Team $team, string $slug, bool $active): array
    {
        $doc = $this->findSichtbar($team, $slug);
        if (! TeamScope::mayWrite($doc->team_id, $team)) {
            throw new WissenGesperrtException(
                "\"{$slug}\" ist geerbtes/globales Master-Wissen — nur Besitzer bzw. Master-Team kann es (de)aktivieren."
            );
        }
        $changed = (bool) $doc->active !== $active;
        if ($changed) {
            DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)
                ->update(['active' => $active, 'updated_at' => now()]);
            // Recall-Index nachziehen (A1): Aktivieren → embedden, Deaktivieren → purgen
            // (queueDocument gated intern auf active). Nur bei echtem Statuswechsel — kein Churn.
            app(KnowledgeEmbeddingService::class)->queueDocument($this->find($doc->slug));
        }

        return [
            'slug' => $doc->slug,
            'title' => $doc->title,
            'active' => $active,
            'vault_managed' => $doc->source_path !== null,
            'changed' => $changed,
        ];
    }

    /** Max. Slugs je `knowledge.SET_ACTIVE`-Batch-Aufruf (Briefing Zutaten-Bulk-Import: „bis 500"). */
    public const SET_ACTIVE_BATCH_MAX = 500;

    /** Chunk-Größe + Staffel-Abstand fürs gestaffelte Embedding (s. Docblock unten). */
    private const SET_ACTIVE_BATCH_CHUNK = 50;

    private const SET_ACTIVE_BATCH_CHUNK_DELAY_SECONDS = 20;

    /**
     * Blockweises (De-)Aktivieren (Briefing Zutaten-Bulk-Import, 2026-09-18): dieselbe Prüfung wie
     * {@see setActive()} (Besitzrecht, `findSichtbar`), aber für bis zu {@see SET_ACTIVE_BATCH_MAX}
     * Slugs in einem Aufruf.
     *
     * ★ Embedding gestaffelt, nicht als Burst: `GenerateEmbeddingJob` (Core-Modul, NICHT hier
     * anfassbar) hat keine Rate-Limiting-Middleware — auf demo laufen nur 2 Worker auf der
     * `default`-Queue. Eine Aktivierung von 500 Slugs würde sonst 500 gleichzeitige Embedding-
     * Provider-Aufrufe anstoßen (Memory: schwere Jobs bringen demo off-peak schon in die Knie).
     * Statt einer Core-Änderung oder einer Queue-Config-Anpassung dispatcht diese Methode den
     * modul-eigenen {@see \Platform\FoodAlchemist\Jobs\QueueKnowledgeEmbedJob} in Chunks von
     * {@see SET_ACTIVE_BATCH_CHUNK} Slugs, je Chunk {@see SET_ACTIVE_BATCH_CHUNK_DELAY_SECONDS}
     * Sekunden später — 500 Slugs verteilen sich damit über ~3 Minuten statt als ein Stoß.
     * Deaktivieren purgt weiterhin sofort (kein Grund zu staffeln, das ist nur ein DB-Delete).
     *
     * @param  list<string>  $slugs
     * @return array{eintraege: list<array{slug:string,status:string,grund?:string}>, embedding_fertig_ca: ?string}
     */
    public function setActiveBatch(Team $team, array $slugs, bool $active): array
    {
        $ergebnisse = [];
        $spaetesteVerzoegerung = 0;
        $irgendwasAktiviert = false;
        foreach (array_chunk(array_values($slugs), self::SET_ACTIVE_BATCH_CHUNK) as $chunkIndex => $chunk) {
            $verzoegerung = $chunkIndex * self::SET_ACTIVE_BATCH_CHUNK_DELAY_SECONDS;
            foreach ($chunk as $roh) {
                $slug = trim((string) $roh);
                if ($slug === '') {
                    continue;
                }
                try {
                    $doc = $this->findSichtbar($team, $slug);
                } catch (RuntimeException $e) {
                    $ergebnisse[] = ['slug' => $slug, 'status' => 'nicht_gefunden', 'grund' => $e->getMessage()];

                    continue;
                }
                if (! TeamScope::mayWrite($doc->team_id, $team)) {
                    $ergebnisse[] = ['slug' => $slug, 'status' => 'gesperrt', 'grund' => 'geerbtes/globales Master-Wissen — nur Besitzer bzw. Master-Team kann es (de)aktivieren.'];

                    continue;
                }
                $changed = (bool) $doc->active !== $active;
                if ($changed) {
                    DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)
                        ->update(['active' => $active, 'updated_at' => now()]);
                    if ($active) {
                        // Erster Chunk (Verzögerung 0) ohne delay() dispatchen — "verzögert um 0
                        // Sekunden" ist technisch dasselbe, aber die Job-Delay-Property bliebe dann ein
                        // Carbon-"jetzt" statt null, was Aufrufer/Tests unnötig verwirrt.
                        $dispatch = \Platform\FoodAlchemist\Jobs\QueueKnowledgeEmbedJob::dispatch($doc->slug);
                        if ($verzoegerung > 0) {
                            $dispatch->delay(now()->addSeconds($verzoegerung));
                        }
                        $irgendwasAktiviert = true;
                        $spaetesteVerzoegerung = max($spaetesteVerzoegerung, $verzoegerung);
                    } else {
                        // Deaktivieren purgt nur den Vektor (DB-Delete) — kein Provider-Aufruf, kein Grund zu staffeln.
                        app(KnowledgeEmbeddingService::class)->deleteDocument((int) $doc->id, $doc->team_id ?? null);
                    }
                }
                $ergebnisse[] = ['slug' => $doc->slug, 'status' => $changed ? 'geaendert' : 'unveraendert'];
            }
        }

        return [
            'eintraege' => $ergebnisse,
            // +30s Sicherheitsabstand: der letzte Chunk-Job muss nach seinem eigenen delay() noch
            // ausgeführt UND der echte GenerateEmbeddingJob dahinter noch verarbeitet werden.
            'embedding_fertig_ca' => $irgendwasAktiviert
                ? now()->addSeconds($spaetesteVerzoegerung + 30)->toIso8601String()
                : null,
        ];
    }

    private function uniqueSlug(string $title, ?string $explicit = null): string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            // Expliziter Slug: leicht normalisieren, aber Punkte/Unterstriche erhalten (Vault-Format skill.foo_bar).
            $base = mb_strtolower(trim((string) preg_replace('/[^a-zA-Z0-9._-]+/', '-', trim($explicit)), '-.')) ?: 'wissen';
        } else {
            $base = Str::slug($title, '-') ?: 'wissen';
        }
        $slug = $base;
        for ($i = 2; DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->exists(); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    /** @return list<string> */
    private function cleanAliases(mixed $aliases): array
    {
        if (! is_array($aliases)) {
            return [];
        }
        $out = [];
        foreach ($aliases as $a) {
            $slug = Str::slug((string) $a, '_');
            if ($slug !== '') {
                $out[$slug] = true;
            }
        }

        return array_keys($out);
    }

    /** @return list<array{target_key:string,mode:string}> */
    /**
     * `bind_layers` in POST/PUT: abweisen, nicht ignorieren.
     *
     * Hier stand `cleanBindings()` — es normalisierte die Eingabe und legte Bindungen an.
     * Seit F2 liest die Laufzeit sie nicht mehr. Sie stillschweigend zu verwerfen wäre die
     * schlimmere Variante: der Aufrufer bekäme `success` und hätte nichts erreicht. Genau
     * diese Sorte „technisch vorhanden, faktisch unsichtbar" ist der Kern von Spec 52.
     *
     * Ein leeres Array oder `null` ist kein Fehler — nur ein Versuch, wirklich zu binden.
     */
    private function verweigereBindLayers(mixed $bindings): void
    {
        if (! is_array($bindings)) {
            return;
        }
        foreach ($bindings as $b) {
            if (is_array($b) && trim((string) ($b['target_key'] ?? '')) !== '') {
                throw new RuntimeException(self::BINDUNG_ABGESCHAFFT);
            }
        }
    }

    /** Volle Doc-Zeile per Slug (auch inaktiv — anders als KnowledgeContextService::getDocument). */
    private function find(string $slug): object
    {
        return DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->firstOrFail();
    }
}
