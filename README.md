# Food Alchemist

Food Alchemist ist das fachliche Plattformmodul für Zutaten, Lieferantenartikel,
Rezepte, Gerichte, Kalkulation, Konzepte und Foodbooks. Es verbindet kulinarische
Kreativität mit belastbaren Stamm-, Preis- und Herkunftsdaten.

Das Modul ist **kein eigenständiges Laravel-Produkt**. Es läuft als Package in der
Platform-Shell und verwendet deren Authentifizierung, Teams, Navigation und
Tool-Infrastruktur.

## In fünf Minuten orientiert

1. Lies die [Dokumentationsübersicht](docs/README.md).
2. Lies die [Architektur](docs/ARCHITEKTUR.md), besonders Mandantenmodell und
   Schreibregeln.
3. Lies vor Produktentscheidungen das verbindliche
   [Zielbild 2029](docs/Zielbild_2029_und_Huerden_Food_Alchemist.md).
4. Prüfe vor Änderungen den aktuellen
   [Umsetzungsplan zum Zielbild](docs/PLANUNG/24_Zielbild_2029_Umsetzungsplan.md).
5. Suche die betroffene kleine Funktion und ihren Abnahmetest in der
   [Business-Case-Funktionsmatrix](docs/PLANUNG/25_Business_Case_Funktionsmatrix.md).
6. Verwende für KI-gestützte Änderungen zusätzlich den [LLM Guide](LLM_GUIDE.md).

## Fachlicher Durchstich

```text
Lieferant
  -> Lieferantenartikel mit Preis und Deklaration
    -> Grundprodukt als fachliche Zutat
      -> Basisrezept als produzierte Komponente
        -> Verkaufsgericht mit Darreichung, EK, VK und Marge
          -> Konzept und Paket
            -> Foodbook oder Angebot für den Kunden
```

Änderungen am unteren Ende der Kette können Preise, Allergene, Zusatzstoffe,
Nährwerte und Qualitätsbefunde weiter oben beeinflussen. Deshalb gehören fachliche
Mutation und anschließender Recompute zusammen.

## Architektur in Kürze

- Laravel-Package unter dem Namespace `Platform\FoodAlchemist`.
- MySQL ist die produktive Daten- und Rechenwahrheit; SQLite wird nur in Tests
  verwendet.
- Tabellen tragen überwiegend das Präfix `foodalchemist_`.
- Die Oberfläche besteht hauptsächlich aus Livewire-Komponenten und Blade-Views.
- Fachliche Regeln gehören in Services, nicht in Views oder Livewire-Renderpfade.
- KI und MCP sind Adapter auf dieselben fachlichen Services; sie dürfen keine
  abweichenden Schreibpfade besitzen.
- Globale und geerbte Daten sind für Kind-Teams sichtbar, aber nur Datensätze des
  eigenen Teams dürfen verändert werden.

Die verbindlichen Details stehen in [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md).

## Verzeichnisstruktur

| Pfad | Verantwortung |
|---|---|
| `src/Models` | Eloquent-Modelle und Beziehungen |
| `src/Services` | Fachliche Use Cases, Berechnung und Orchestrierung |
| `src/Livewire` | UI-Zustand und Benutzerinteraktionen |
| `src/Policies` | Autorisierungsregeln |
| `src/Tools` | MCP-/KI-Werkzeuge als Adapter auf Fachservices |
| `src/Jobs` | Asynchrone und größere Verarbeitung |
| `src/Console` | Wartungs-, Import- und Diagnosebefehle |
| `src/Support` | Querschnittshelfer, insbesondere Team-Scoping |
| `database/migrations` | Schemahistorie des Moduls |
| `resources/views` | Blade- und Livewire-Views |
| `routes` | Authentifizierte Modulrouten und öffentliche Assets |
| `tests` | Unit- und Featuretests des Moduls |
| `docs` | Produkt-, Architektur-, Benutzer- und Planungsdokumentation |

## Lokale Entwicklung

Die vollständige Laufzeit befindet sich in der benachbarten Sandbox
`sandbox-food-alchemist`. Das Modul wird dort über ein Composer-Path-Repository
eingebunden. Änderungen am Modul sind dadurch unmittelbar in der Sandbox sichtbar.

### Voraussetzungen

- kompatible PHP- und Composer-Version der Platform-Shell
- Node.js für den JavaScript-Build
- eine bewusst konfigurierte Test- oder Entwicklungsdatenbank
- installierte Abhängigkeiten in Modul und Sandbox

### Häufige Befehle

Vom Modulverzeichnis aus:

```bash
npm run build
composer validate --strict --no-check-publish
```

Die Modultests laufen aus der Sandbox:

```bash
cd ../../../sandbox-food-alchemist
php -d memory_limit=1G vendor/bin/pest --testsuite=FoodAlchemist
```

> **Sicherheitsregel:** Vor jedem Testlauf Verbindung, Host und Datenbanknamen
> prüfen. Die Tests dürfen ausschließlich gegen eine dafür vorgesehene, löschbare
> Testdatenbank laufen. Der technische Hard-Guard dafür ist im Zielbild-Plan als
> Release-Blocker geführt.

Für Änderungen an MySQL-spezifischen Migrationen oder Queries reicht SQLite nicht.
Zusätzlich ist ein gezielter MySQL-Smoke-Test erforderlich.

## Regeln für Änderungen

### Mandantenfähigkeit

- Lesen: nur globale Daten und die für das aktuelle Team sichtbare Vererbungskette.
- Schreiben und Löschen: ausschließlich Datensätze des aktuellen Teams.
- Jede aus Request, Livewire oder Tool übergebene ID wird erneut team-scoped
  aufgelöst.
- `visibleToTeam()` ist keine Schreibberechtigung.
- Rohe Query-Builder-Abfragen verwenden die zentrale Team-Scope-Hilfe.
- Neue öffentliche Actions brauchen mindestens einen negativen Cross-Tenant-Test.

### Fachlogik

- Livewire validiert Eingaben und delegiert einen Use Case.
- Services besitzen Transaktionen, Invarianten und Recompute-Aufrufe.
- Models definieren Beziehungen und kleine lokale Zustandsregeln.
- Tools und Jobs rufen Services auf, statt Fachlogik zu duplizieren.
- Geldbeträge, Portionen und Yield werden nicht in Views berechnet.

### KI und Herkunft

- KI erzeugt Vorschläge, keine stillen Wahrheiten.
- Herkunft, Confidence und Prüfstatus bleiben erhalten.
- Allergene, Zusatzstoffe, Nährwerte und Preise benötigen deterministische
  Fallbacks und einen menschlich nachvollziehbaren Freigabepfad.
- Ein KI- oder Tool-Fehler darf keine Capability unbemerkt entfernen.

### Definition of Done

Eine Änderung ist erst abgeschlossen, wenn:

- der fachliche Use Case und seine Invarianten getestet sind,
- Tenant-Sichtbarkeit und Ownership getestet sind,
- Migrationen vorwärtskompatibel sind,
- MCP-/Tool-Adapter bei betroffenen Use Cases mitgezogen wurden,
- relevante Dokumentation aktualisiert ist,
- PHP-Syntax, Frontend-Build und passende Pest-Suite grün sind,
- bei MySQL-spezifischem Verhalten ein MySQL-Smoke dokumentiert ist.

## Dokumentationsregeln

- `docs/Zielbild_2029_und_Huerden_Food_Alchemist.md` beantwortet **Warum und
  Wohin** und besitzt bei Strategiefragen Vorrang.
- `docs/ARCHITEKTUR.md` beantwortet **Wie ist das System gebaut und welche Regeln
  sind verbindlich**.
- `docs/PLANUNG/24_Zielbild_2029_Umsetzungsplan.md` beantwortet **Was kommt als
  Nächstes und welches Gate entscheidet über den Abschluss**.
- `docs/PLANUNG/25_Business_Case_Funktionsmatrix.md` und
  `26_LLM_MCP_Funktionsmatrix.md` beantworten **Welche Business-, LLM- und
  MCP-Funktion wird wie abgenommen**.
- `docs/PLANUNG/` enthält nur aktive Steuerung, Audit und Arbeitsnachweise.
- `docs/_archiv/` ist historischer Kontext und keine aktuelle Wahrheit.
- `docs/index.md` und die Bereichsdokumente richten sich an Anwender.

Wenn Dokumente widersprechen, gilt die Priorität aus
[docs/README.md](docs/README.md).

## Aktueller Reifehinweis

Das Modul besitzt breite fachliche Fähigkeiten und eine große Testsuite, ist aber
noch nicht als vollständig mandantensicheres, autonom betreibbares SaaS freigegeben.
Die offenen Release-Gates und ihre Reihenfolge stehen im
[Zielbild-Umsetzungsplan](docs/PLANUNG/24_Zielbild_2029_Umsetzungsplan.md). Der
detaillierte Befundkatalog liegt im
[MVP-Audit](docs/PLANUNG/23_MVP_Audit.md).
