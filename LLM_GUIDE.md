# LLM Guide — Food Alchemist sicher verändern

Dieser Leitfaden richtet sich an Menschen und Coding Agents, die das Modul noch
nicht kennen. Er ergänzt die [Architektur](docs/ARCHITEKTUR.md), ersetzt sie aber
nicht.

## 1. Vor jeder Änderung lesen

In dieser Reihenfolge:

1. [README.md](README.md) für Einstieg, Struktur und Befehle
2. [docs/README.md](docs/README.md) für die Dokumentationshierarchie
3. [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md) für Systemgrenzen und Invarianten
4. die betroffene Benutzer- oder Fachbereichsdokumentation
5. die Funktions-ID in Matrix 25 und gegebenenfalls Prompt-/Tool-ID in Matrix 26
6. bei Produkt- oder Prioritätsentscheidungen das
   [Zielbild 2029](docs/Zielbild_2029_und_Huerden_Food_Alchemist.md)

Historische Dokumente und Specs in `docs/_archiv/` sind nur Kontext. Sie dürfen aktuelle
Architektur oder Zielbild nicht überschreiben.

## 2. Zuerst den vollständigen Schreibpfad finden

Eine UI-Änderung ist selten nur eine Livewire-Datei. Ermittle vor dem Editieren:

```text
Route oder Tool
  -> Livewire-Action / Tool-Handler / Job
    -> Fachservice
      -> Model und Beziehungen
        -> Recompute / Observer / Folgejobs
          -> Tests und Dokumentation
```

Suche außerdem nach weiteren Aufrufern desselben Services. Weboberfläche, MCP und
Batchverarbeitung müssen dieselben Invarianten verwenden.

## 3. Nicht verhandelbare Invarianten

### Mandanten- und Ownership-Regel

```text
sichtbar   = globale Datensätze + Datensätze der Team-Ancestry
editierbar = ausschließlich Datensätze des aktuellen Teams
```

- `visibleToTeam($team)` erlaubt Lesen, nicht automatisch Schreiben.
- Vor `update`, `delete`, Alias-/Binding-Änderungen und Verknüpfungen muss
  Ownership geprüft werden.
- Fremdschlüssel aus Formularen oder Tool-Payloads werden team-scoped neu geladen.
- Direkte `find($id)`-Aufrufe auf mandantenfähigen Models in öffentlichen
  Schreibpfaden sind ein Warnsignal.
- Tests enthalten Positiv-, Fremdteam-, Parent- und Global-Fälle.

### Produktkette und Recompute

```text
Lieferantenartikel -> Grundprodukt -> Basisrezept -> Verkaufsgericht
                  -> Konzept/Paket -> Foodbook/Angebot
```

Preis-, Mengen-, Yield- und Zutatenänderungen können die Ebenen darüber verändern.
Verwende den vorhandenen Recompute- und Propagationspfad. Implementiere keine zweite
Berechnungsformel in Livewire, Blade, Tool oder Export.

### Quellen und Freigaben

- KI-Daten bleiben Vorschläge, bis ein definierter Freigabeschritt erfolgt.
- Quelle, Confidence, Zeitstempel und manuelle Übersteuerung dürfen nicht verloren
  gehen.
- Ein Import darf manuell kuratierte Felder nicht ohne explizite Vorrangregel
  überschreiben.
- Exporte dürfen Haftungsdaten nicht als geprüft darstellen, wenn der Freigabestatus
  fehlt.

### Exakte und heuristische Ergebnisse

Wenn ein Solver oder Ranking heuristisch arbeitet, muss das Ergebnis dies
ausweisen. Keine Aussage wie „optimal“, wenn nur ein Teil des Suchraums geprüft
wurde.

## 4. Verantwortlichkeiten im Code

| Schicht | Gehört hierhin | Gehört nicht hierhin |
|---|---|---|
| Livewire | Eingabe, Validierung, UI-Zustand, Delegation | Preisformeln, Tenant-Regeln, große Queries |
| Service | Use Case, Transaktion, Invarianten, Recompute | HTML- oder Livewire-Zustand |
| Model | Beziehungen, Casts, lokale Zustandsregeln | komplette Workflows |
| Policy/Scope | Berechtigung, Sichtbarkeit, Ownership | fachliche Berechnung |
| Tool | Schema, Context, Aufruf eines Services | parallele Fachlogik |
| Job/Command | Orchestrierung, Chunking, Wiederaufnahme | abweichende Schreibregeln |
| View | Darstellung | DB-Zugriff und Geldberechnung |

## 5. Vorgehen für typische Änderungen

### Neue oder geänderte Entität

1. Datenbesitz und `team_id` festlegen.
2. Sichtbarkeit, Vererbung und Löschregel definieren.
3. Migration additiv und rollback-fähig bauen.
4. Model, Policy/Scope und Service anpassen.
5. Web-, Tool-, Import- und Exportpfade prüfen.
6. Recompute- und Audit-Auswirkungen prüfen.
7. Tenant- und Fachtests ergänzen.

### Neuer Tool-Endpunkt

1. Bestehenden Fachservice wiederverwenden.
2. Team ausschließlich aus `ToolContext` beziehen.
3. Payload-IDs team-scoped auflösen.
4. strukturierte, stabile Resultate zurückgeben.
5. Registrierung und Fehlerfall testen.
6. Capability nicht still beim Boot verlieren lassen.

### Importänderung

1. Format und Quelle versionieren.
2. Dry-Run und verständlichen Fehlerbericht erhalten.
3. Feld-Provenienz und Vorrangregel anwenden.
4. parent-/global-geerbte Daten niemals überschreiben.
5. idempotenten Wiederholungslauf testen.
6. Preisdatum, Gültigkeit und Null-/Unbekannt-Semantik testen.

### Berechnungsänderung

1. bestehende Formel und Golden Tests identifizieren.
2. Einheit, Rundung und Nullverhalten dokumentieren.
3. einen zentralen Rechenpfad verändern.
4. Abhängige Ebenen neu berechnen.
5. Performance für Batch- und Einzelpfad messen.

## 6. Verifikation

Führe mindestens die kleinste passende Testsuite aus. Vor einem Release oder bei
Querschnittsänderungen ist die vollständige Modulsuite erforderlich.

```bash
# im Modul
npm run build
composer validate --strict --no-check-publish

# in ../../../sandbox-food-alchemist
php -d memory_limit=1G vendor/bin/pest --testsuite=FoodAlchemist
```

Vor dem Pest-Lauf die Testdatenbank explizit prüfen. SQLite deckt MySQL-spezifische
Migrationen, Indexlängen, SQL-Modi und Querysemantik nicht vollständig ab.

Zusätzlich je Risiko:

- Cross-Tenant-Featuretest bei Reads und Writes
- MySQL-Smoke bei Schema- oder SQL-Änderungen
- Browser-/Livewire-Test bei öffentlichen Actions
- Exportprüfung bei CSV/PDF und Haftungsdaten
- Lastmessung bei Solver, Import, Recompute oder Massenlisten

## 7. Dokumentation mitziehen

| Änderung | Zu aktualisieren |
|---|---|
| Systemgrenze oder Invariante | `docs/ARCHITEKTUR.md` |
| Nutzerablauf | passende Datei unter `docs/` |
| Produktziel oder Gate | Zielbild plus Zielbild-Plan |
| Umsetzungsdetails | begrenzte aktive Spec unter `docs/PLANUNG/`, beim Start des Pakets angelegt |
| lokale Einrichtung | `README.md` |
| wiederkehrende Agent-Regel | `LLM_GUIDE.md` |

Keine erledigten Behauptungen nur anhand vorhandener Klassen oder grüner Unit-Tests
eintragen. Ein Zielbild-Gate gilt erst als erfüllt, wenn sein Nachweis verlinkt ist.

## 8. Stop-Signale

Vor dem Weiterarbeiten klären, wenn:

- fachliche Spezifikation und Zielbild widersprechen,
- Ownership einer Entität unklar ist,
- ein Import manuelle und externe Daten nicht unterscheiden kann,
- eine Änderung eine neue Produktdomäne in das Modul zieht,
- ein Test möglicherweise eine persistente Datenbank verwendet,
- ein Ergebnis Haftungsdaten ohne Freigabestatus exportieren würde.

Im Zweifel keine zusätzliche implizite Regel erfinden. Die Entscheidung als ADR oder
Planungsentscheidung dokumentieren und erst danach implementieren.
