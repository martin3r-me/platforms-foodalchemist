# 62 · Server-Start und Startpaket

**Stand 2026-10-06 · Status: Entwurf (Vorgaben Dominique eingearbeitet) · gehört zur Plattform `food-alchemist`**

> Bezug: [61 · Rollen und Rechte](61_Rollen_und_Rechte.md) §11 (Plattform-Wissen), §13 (kuratiert/autark, F9 Start
> eines autarken Kunden). Host-App: `15_GITHUB/food-alchemist` (README „Weg auf den Server“).

## Vorgaben (Dominique, 2026-10-06)

1. **Leere Datenbank** auf dem Server. Keine Rezepte, Grundprodukte, Lieferantenartikel aus dem Altbestand.
2. **Nur das Wissen kommt automatisch mit.**
3. **Alles andere überspielt Dominique händisch** (Grundprodukte, Lieferantenartikel, Rezepte, Einstellungen).
4. Zu einem selbst gewählten Zeitpunkt wird der Stand als **Ankerpunkt** festgehalten: „das ist der Status quo,
   wenn ein neuer Kunde kommt“.

---

## 1 · Leere Datenbank

`php artisan migrate --force` auf frischer MySQL-DB. Leer heißt *ohne Fachdaten*; Struktur- und Referenzwerte, die
Migrationen selbst anlegen (Modul-Registrierung, Allergen-Liste, Einheiten-Grundwerte, Status-Vokabular), sind
Teil des Schemas und bleiben.

**Gemessen 2026-10-06 (frische DB `foodalchemist_server`):** 793 Migrationen laufen in **einem** Durchgang durch,
**wenn PHP 1 GB Speicher hat**. Mit dem Standard (128 MB) bricht PHP nach ~645 Migrationen **still** ab (Exit 255,
keine Meldung) — das sah zuerst wie ein Fehler im Organization-Modul aus, ist es nicht. Die Organization-Seed-Fehler
treten nur in der kopierten Alt-DB auf (fehlende Entity-Type-Gruppe); frisch ist die Gruppe vorhanden. → **S2 erledigt.**

Installation: `php artisan fa:installieren --email=… --name=… --team=BHG.DIGITAL` (setzt 1 GB selbst, migriert,
legt Kurator-Team + ersten Admin als Inhaber an, schaltet Module frei; idempotent).

## 2 · Wissenspaket (automatisch)

**Quelle der Wahrheit ist demo, Team 6** (FA-Wissensmodul = SSOT seit 2026-08-21) — nicht lokale Kopien
(lokal `foodalchemist_platform`: 358 Dossiers, veraltet).

**Inhalt des Pakets**

| Teil | Tabellen | Bemerkung |
|---|---|---|
| Dossiers | `knowledge_documents` (+ Abschnitte, Aliasse, Routings, Ebenen, Kategorien, Kanon, Bindungen, Verweise, Budgets) | nur **aktive**; Inaktive als Option |
| Pairing-Grundlage | `vocab_pairing_anchors`, `pairing_anchor_edges`, `anchor_ingredient_map`, `anchor_taste_axis` | Dossiers hängen per `anker_id` daran — ohne Anker fehlen Bezüge. **Inspire ist kommerziell**: Datei nie ins Repo; Import per `foodalchemist:inspire-import --source=` aus hochgeladener Datei |
| Embeddings | — | werden **nicht** übertragen, sondern auf dem Server neu berechnet (`knowledge-embed`, off-peak) |

**Ablauf**
1. `foodalchemist:wissen-paket-export` auf demo → eine Datei (JSON, gz) mit Paket-Version und Zählern.
2. Datei auf den Server (nie ins Repo).
3. `foodalchemist:wissen-paket-import --datei=` → schreibt das Wissen als **Plattform-Wissen** (`team_id NULL`,
   unsichtbar für Kunden, Spec 61 §11), idempotent per Slug.
4. Inspire-Import, dann Embeddings neu.
5. Prüfung: Zähler Paket = Zähler Server; Recall-Probe (`wissen-recall-probe`) grün.

**Reihenfolge-Pflicht (aus Spec 61 §11):** Plattform-Wissen braucht den Admin-Schreibpfad (Gate
`foodalchemist.plattform-admin`), sonst kann es danach niemand mehr pflegen. Entweder §11 vor dem Server-Start
bauen — oder das Paket zuerst in ein **Kurator-Team** importieren und später mit `wissen-global-heben` global machen.

Vorhandene Bausteine: `knowledge-export` (Modul → Vault-Dateien), `knowledge-import` (D4, Vault → Modul),
`wissen-global-heben`, `inspire-import`, `knowledge-embed`. Neu nötig: Paket-Export/Import als *eine* Datei mit
Version (statt Vault-Ordner), damit der Server nicht vom Vault abhängt.

### Stand 2026-10-06: eingespielt in `foodalchemist_server`

Quelle: `07_WISSEN/_FA_Wissen_Stand_2026-10-05/` (Abzug demo Team 6, 14.248 Dossiers) + `_system/canon_slugs.json`
(Kanon mit Slugs, live von demo per `knowledge_canon.GET`). Befehl der Plattform: `fa:wissen-einspielen <ordner>`.
Ergebnis: 14.250 Dossiers (14.246 aktiv, alle global), 13.939 Aliasse, 124 Kanon-Zeilen (alle aufgelöst),
139 Routings, 4 Budgets, 22 Kategorien. Dauer 1,5 min. **S4 entschieden:** direkt global; pflegbar über
`FOODALCHEMIST_MASTER_TEAM_ID` = Kurator-Team (Master darf globale Zeilen schreiben). Offen: Pairing-Import
(nach Abschluss der Pairing-Session), danach `--nur-anker`; Embeddings auf dem Server (S3).
**Entschieden:** Maßgeblich ist nur der demo-Stand. Die 5.392 lokalen Kochbuch-Dossiers (`07.06_…/Kochbücher/_Dossiers`, nie hochgeladen) kommen nicht mit.

## 3 · Händisch überspielen

Dominique pflegt im Kurator-Team (Modus *kuratiert*, Spec 61 §13): Einstellungen, Grundprodukte,
Lieferantenartikel, Basisrezepte, Gerichte, Foodbooks. Werkzeuge: Oberfläche, MCP (`gps.*`, `recipes.EXTRACT`,
`recipes.*`), Lieferanten-Import.

## 4 · Startpaket (Ankerpunkt für neue Kunden)

Wenn Dominique sagt „das ist der Stand“:

- `foodalchemist:startpaket-sichern --name="Start 2026-11"` — hält aus dem Kurator-Team fest:
  Grundeinstellung (Warengruppen, Einheiten, Kalkulationsdefaults, Aufschlagsklassen, Concepter-Dimensionen),
  gepflegte Grundprodukte, optional Lieferantenartikel-Vorlagen (ohne Preise) und Basisrezepte (F9b).
  Gespeichert **versioniert** (Tabelle `foodalchemist_startpakete` + Inhalt als JSON), nie überschrieben.
- `foodalchemist:startpaket-anwenden --team= --paket=` — legt den Inhalt als **eigene Kopie** im neuen
  Kunden-Team an (Herkunft: Paket + Version). Später auch als Knopf in `/verwaltung` beim Team-Anlegen.
- So ist jederzeit nachvollziehbar, mit welchem Stand ein Kunde gestartet ist.

## Offene Fragen

- ~~S1~~ **entschieden: ja, Pairing kommt mit.** Zählen die Pairing-Daten (Inspire-Anker und -Kanten) zum Wissen, das automatisch mitkommt? (Vorschlag: ja,
  ohne sie fehlen Dossier-Bezüge und Pairing-Funktionen.)
- ~~S2~~ erledigt: frische DB migriert sauber (Speicherlimit war die Ursache).
- **S3** Embeddings auf dem Server (Qdrant wie auf demo, eigener Dienst?) — **mit Martin klären** (Server-Einrichtung).
- ~~S4~~ entschieden: direkt global, pflegbar über Master-Team.
