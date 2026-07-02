# LLM Guide für Food Alchemist

Diese Datei ist speziell für **Large Language Models (LLMs)** geschrieben, um das Verständnis und die Arbeit mit diesem Template zu erleichtern.

## 🎯 Zweck dieses Templates

Dieses Template ist eine **minimale, vollständige Vorlage** für neue Platform-Module. Es zeigt:
- ✅ Wie ein Modul strukturiert wird
- ✅ Welche Dateien benötigt werden
- ✅ Wie die Integration funktioniert
- ✅ Welche Patterns verwendet werden

## 📐 Architektur-Übersicht

### Service Provider Pattern

```
FoodAlchemistServiceProvider
├── register()          # Config laden (Laravel Best Practice)
└── boot()              # Modul-Registrierung & Setup
    ├── PlatformCore::registerModule()  # Modul registrieren
    ├── ModuleRouter::group()            # Routes laden
    ├── loadMigrationsFrom()             # Migrationen
    ├── loadViewsFrom()                  # Views
    └── registerLivewireComponents()     # Livewire auto-registrieren
```

### Route Pattern

```
Route-Definition: Route::get('/', Dashboard::class)
    ↓
ModuleRouter::group() fügt automatisch hinzu:
    - Prefix: /foodalchemist
    - Middleware: web, auth, etc.
    ↓
Finale Route: /foodalchemist/
```

### Livewire Component Pattern

```
Datei: src/Livewire/Dashboard.php
    ↓
Auto-Registrierung via registerLivewireComponents()
    ↓
Alias: foodalchemist.dashboard
    ↓
Verwendung: <livewire:foodalchemist.dashboard />
```

## 🔄 Workflow für neues Modul

### Schritt 1: Kopieren
```bash
cp -r foodalchemist dein-modul-name
```

### Schritt 2: Suchen & Ersetzen

**In ALLEN Dateien ersetzen:**
- `FoodAlchemist` → `DeinModulName` (PascalCase, Namespace)
- `foodalchemist` → `dein-modul-name` (kebab-case, Routes, Config)
- `module_template` → `dein_modul_name` (snake_case, Config-Keys)

**Wichtige Dateien:**
- `composer.json` - Name, Namespace, Provider
- `config/foodalchemist.php` → umbenennen & anpassen
- `src/FoodAlchemistServiceProvider.php` → umbenennen & anpassen
- Alle PHP-Dateien: Namespace ändern
- Alle Blade-Dateien: `foodalchemist::` → `dein-modul-name::`

### Schritt 3: Composer registrieren

In Hauptanwendung `composer.json`:
```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../platform/modules/dein-modul-name"
    }
  ],
  "require": {
    "martin3r/platform-dein-modul-name": "dev-main"
  }
}
```

Dann: `composer update`

## 📝 Code-Patterns

### 1. Service Provider

**Pattern:**
```php
public function register(): void {
    // Config laden (MUSS in register() sein!)
    $this->mergeConfigFrom(...);
}

public function boot(): void {
    // 1. Prüfen ob Config & DB vorhanden
    if (config()->has(...) && Schema::hasTable('modules')) {
        // 2. Modul registrieren
        PlatformCore::registerModule([...]);
    }
    
    // 3. Routes laden (nur wenn registriert)
    if (PlatformCore::getModule('...')) {
        ModuleRouter::group('...', function () {
            $this->loadRoutesFrom(...);
        });
    }
    
    // 4. Rest registrieren
    $this->loadMigrationsFrom(...);
    $this->loadViewsFrom(...);
    $this->registerLivewireComponents();
}
```

### 2. Livewire Component

**Pattern:**
```php
class Dashboard extends Component {
    public function render() {
        $user = Auth::user();
        $team = $user->currentTeam;
        
        // Daten laden
        $data = YourModel::where('team_id', $team->id)->get();
        
        return view('modul-name::livewire.dashboard', [
            'data' => $data,
        ])->layout('platform::layouts.app');
    }
}
```

### 3. View mit Sidebars

**Pattern:**
```blade
<x-ui-page>
    <x-slot name="navbar">...</x-slot>
    <x-ui-page-container>...</x-ui-page-container>
    <x-slot name="sidebar">...</x-slot>
    <x-slot name="activity">...</x-slot>
    
    {{-- Modals IMMER innerhalb von x-ui-page! --}}
    <livewire:modul-name.modal />
</x-ui-page>
```

### 4. Route-Definition

**Pattern:**
```php
Route::get('/', Dashboard::class)->name('modul-name.dashboard');
Route::get('/entities', Entity\Index::class)->name('modul-name.entities.index');
```

## 🎨 UI-Komponenten

### Standard-Komponenten

- `x-ui-page` - Haupt-Container
- `x-ui-page-navbar` - Navbar
- `x-ui-page-container` - Hauptinhalt
- `x-ui-page-sidebar` - Sidebar (links/rechts)
- `x-ui-panel` - Panel-Container
- `x-ui-button` - Button
- `x-ui-input-text` - Text-Input
- `x-ui-input-select` - Select
- `x-ui-dashboard-tile` - Statistik-Tile

### Verwendung

```blade
<x-ui-button variant="primary" size="sm" :href="route('...')">
    Button Text
</x-ui-button>

<x-ui-input-text 
    name="field_name"
    label="Label"
    wire:model="fieldName"
    placeholder="..."
/>
```

## 🔍 Häufige Probleme & Lösungen

### Problem: Routes funktionieren nicht

**Lösung:**
1. Config publiziert? → `php artisan vendor:publish --tag=config`
2. Config-Cache geleert? → `php artisan config:clear`
3. Route-Cache geleert? → `php artisan route:clear`

### Problem: Livewire Component nicht gefunden

**Lösung:**
1. Service Provider registriert? → Prüfe `composer.json`
2. `composer dump-autoload` ausgeführt?
3. Klasse existiert? → Prüfe Namespace

### Problem: Multiple Root Elements Error

**Lösung:**
- Modals müssen **innerhalb** von `<x-ui-page>` sein!
- Nicht außerhalb!

### Problem: Config nicht gefunden

**Lösung:**
- `mergeConfigFrom` muss in `register()` sein, nicht `boot()`!
- Config-Datei muss existieren

## 📚 Referenzen

### Ähnliche Module zum Lernen

- **HCM** (`platform/modules/hcm`) - Komplexeres Beispiel
- **Planner** (`platform/modules/planner`) - Modals, erweiterte Features
- **Location** (`platform/modules/location`) - Aktuelles Beispiel

### Core-Klassen

- `Platform\Core\PlatformCore` - Modul-Registrierung
- `Platform\Core\Routing\ModuleRouter` - Route-Handling
- `Platform\ActivityLog\Traits\LogsActivity` - Activity Logging

## ✅ Checkliste für LLMs

Wenn du ein neues Modul erstellst:

1. [ ] Template kopiert
2. [ ] Alle Namespaces angepasst
3. [ ] Config angepasst
4. [ ] Service Provider angepasst
5. [ ] Routes angepasst
6. [ ] Views angepasst
7. [ ] Composer registriert
8. [ ] `composer dump-autoload` ausgeführt
9. [ ] Config-Cache geleert
10. [ ] Route-Cache geleert
11. [ ] Getestet

## 🎓 Wichtige Konzepte

### Team-basierte Daten

**IMMER** Team-Filterung verwenden:
```php
$user = Auth::user();
$team = $user->currentTeam;
$data = Model::where('team_id', $team->id)->get();
```

### UUIDs

**IMMER** UUIDs für Models verwenden:
```php
use Symfony\Component\Uid\UuidV7;

protected static function booted(): void {
    static::creating(function ($model) {
        if (empty($model->uuid)) {
            do {
                $uuid = UuidV7::generate();
            } while (self::where('uuid', $uuid)->exists());
            $model->uuid = $uuid;
        }
    });
}
```

### Activity Logging

**IMMER** `LogsActivity` Trait verwenden:
```php
use Platform\ActivityLog\Traits\LogsActivity;

class Model extends Model {
    use LogsActivity;
    // ...
}
```

## 🚀 Nächste Schritte

Nach dem Erstellen des Basis-Moduls:

1. **Models hinzufügen** - In `src/Models/`
2. **Migrationen erstellen** - In `database/migrations/`
3. **Livewire Components erweitern** - Index, Show, Create, Edit
4. **Routes erweitern** - Für neue Views
5. **Policies erstellen** - Für Authorization
6. **Tests schreiben** - Für wichtige Funktionen

## 💡 Tipps für LLMs

1. **Folge den Patterns** - Dieses Template zeigt bewährte Patterns
2. **Konsistenz** - Halte dich an die Namenskonventionen
3. **Dokumentation** - Kommentiere wichtige Stellen
4. **Beispiele** - Sieh dir HCM/Planner für komplexere Beispiele an
5. **Testen** - Teste nach jeder Änderung

---

## 🔎 Semantische Pairing-/Domain-Suche (Embeddings)

Hybrid-Recall **über** der deterministischen Lexik in `KnowledgeContextService`
— **kein** Ersatz. Nutzt Cores Embedding-Infrastruktur (Commit `32b66074`,
`EmbeddingProviderContract` + `EmbeddingStoreContract` getrennt). Discussions
`#4`/`#8` im Package `platforms-food-alchemist`.

### Warum

`discoverDomains()` und `pairingBlock()` matchen heute rein lexikalisch
(258er-Alias-Map + Jaccard/Substring gegen **Slug/Titel**). Das verfehlt
Synonyme, die nicht in der Alias-Map stehen ("Topinambur", "Erdapfel" …). Die
Semantik findet das passende Domain-/Pairing-Doc, **wenn die Lexik dünn bleibt**.
Der präzise Anker-Edge-Graph (`foodalchemist_pairing_anker_edges`) bleibt
unangetastet: Semantik löst Freitext → Doc-/Stem-Slug auf, der Graph paart.

### Bausteine (alles im Modul)

| Datei | Rolle |
|---|---|
| `Services/Ai/KnowledgeEmbeddingService` | Fassade: `embedCorpus()`, `searchSlugs()`, `searchEnabled()` |
| `Console/KnowledgeEmbedCommand` | `php artisan foodalchemist:knowledge-embed` — indiziert den Korpus |
| `KnowledgeContextService::semanticSlugs()` | Hybrid-Einsprung in `discoverDomains()` + `pairingBlock()` |
| `config/foodalchemist.php` → `semantic_search` | Flag + Provider + Sentinel + Score |

### Core-API (nur **genutzt**, nie verändert)

```php
app(\Platform\Core\Services\EmbeddingService::class)
    ->embedAndStoreBatch(teamId, entityType, entries, providerName);   // Index
    ->search(teamId, queryText, entityTypes, limit, minScore, providerName); // Suche
```

- **entity_type:** `foodalchemist_knowledge_document` (polymorph, kein Vektor-Feld in unseren Tabellen).
- **Provider:** Default `null` ⇒ Core-Default = OpenAI `text-embedding-3-large` (3072d). `EMBEDDING_GEMINI_ENABLED=true` für Cooking-Jarvis-Kontinuität (768d, L2-norm.).
- **Skip-if-unchanged:** Cores `source_hash` (sha256) — unveränderter Text ⇒ kein API-Call.

### Was wird embeddet (die Qualitäts-Stellschraube)

- **domain:** Titel + Lead (erste ~2000 Zeichen) → Doc-Level-Relevanz reicht.
- **pairing:** Stem + **verifizierte Partner-Namen** (`extractPairingNames()`), **nicht** die molekulare Prosa — die Zutaten-Oberfläche soll zur Gericht-Beschreibung matchen.
- **cross_cutting:** wird **nicht** indiziert (always-load, kein Discovery).

### Globaler Korpus — `team_id`-Sentinel ⚠

`knowledge_documents.team_id` ist **NULL** (BHG-kuratiert, D1). Cores
`EmbeddingService` verlangt aber `team_id:int`. Wir mappen NULL →
`semantic_search.global_team_id` (Default `0`). Gefahrlos, weil
`core_embeddings.team_id` nur ein indizierter `bigint` ist (kein FK).
**Offener Core-Wunsch an Martin:** nativer Global-/Shared-Scope + global∪team-OR
in `search()` — bis dahin sucht das Modul ausschließlich in der Sentinel-Partition.

### Aktivierung (zweistufig, default AUS)

```bash
# 1. Korpus indizieren (idempotent; nach foodalchemist:knowledge-import laufen lassen)
php artisan foodalchemist:knowledge-embed

# 2. Hybrid-Fallback scharf schalten
FOODALCHEMIST_SEMANTIC_SEARCH=true
```

`enabled=false` (Default) = **exakt** das bisherige Lexik-Verhalten: kein
API-Call, keine Latenz im Generator-Hot-Path, keine Verhaltensänderung. Fehlender
Provider (Sandbox ohne Key) ⇒ alle Methoden degradieren still auf leer ⇒ Lexik
bleibt führend (GL-13 Invariante 6).

> **Noch offen:** Retrieval-Qualität gegen echte Pairing-Fälle auf `demo` mit
> Live-OpenAI prüfen, bevor `#4`/`#8` mit Verweis auf `32b66074` geschlossen werden.
> Der `FakeEmbeddingProvider` in den Tests prüft nur die Verdrahtung, nicht die
> echte semantische Qualität.

### Semantische Anker-Auflösung (B)

Zweiter Embedding-Hebel, getrennt von der Doc-Discovery: löst Freitext-Zutaten,
die `PairingService::resolveByName()` lexikalisch NICHT trifft (z. B. „Portwein
weiss"), semantisch auf einen Anker auf → hebt die Pairing-Coverage + die
Completion-Mathematik.

- `KnowledgeEmbeddingService::embedAnkers()` indiziert `vocab_pairing_ankers`
  (entity_type `foodalchemist_pairing_anker`, ohne `neutral`). Läuft im
  `foodalchemist:knowledge-embed`-Command mit.
- `KnowledgeEmbeddingService::resolveAnkerId($name)` → Anker-ID des besten Treffers
  über `semantic_search.anker_min_score` (Default **0.55**, höher als die Doc-Suche).
- Einsprung: `PairingService::resolveRecipeAnchors()` ruft den Fallback **nur** für
  sonst `unresolved`-Zeilen, gegated, markiert `via='embedding'`, überschreibt **nie**
  explizite gp/recipe-Mappings. Falsch-Auflösung > Risiko → hohe Schwelle.

### Pairing-Panel: Teller-Logik vs. Komponenten-Graph

`PairingService::panelRecipe()` verzweigt auf `ist_verkaufsrezept`:

- **Gericht (Teller):** „Komplettiert den Teller" (klassiker, breite Abdeckung) +
  „Macht den Teller eigen" (signature = `cover×w/√degree`, Allrounder rausgerechnet).
- **Basisrezept (Komponente):** keine Teller-Blöcke — stattdessen „Passt klassisch
  zu" (Aroma-Graph-Nachbarn) + „Verwandte Basisrezepte" (`recipesSharingPairings`).

Immer (beide): Aroma-Kohäsion, Kern-Anker, Kontrast. Ein Basisrezept „komplettiert
keinen Teller" — daher die Graph-Sicht statt der Teller-Vorschläge.
