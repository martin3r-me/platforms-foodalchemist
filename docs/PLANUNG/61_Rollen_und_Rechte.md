# 61 · Rollen und Rechte im Food Alchemist

**Stand 2026-10-06 · Status: in §3–§6 abgelöst durch [77 · Teams, Bereiche, Standorte und Rechte](77_Teams_Bereiche_Standorte_Rechte.md) (2026-10-08) — Rolle kommt aus der Plattform-Rolle, keine eigene FA-Rollentabelle. §12 und §13 gelten weiter und sind in Spec 77 eingebaut.**

> Bezug: [25 · Business-Case-Funktionsmatrix](25_Business_Case_Funktionsmatrix.md), AD-06 „Nutzerrolle/Berechtigung“
> (Status *teilweise*, Abnahme: „Rollenmatrix für Lesen, Kuratieren, Freigeben, Admin“). Produktionsrollen:
> [35 · Tagesplan-Cockpit](35_Spec_Tagesplan_Cockpit.md) §14. Befund P0 „Sichtbarkeit ≠ Schreibrecht“:
> [23 · MVP-Audit](23_MVP_Audit.md).

## Warum

Der Food Alchemist wird eigenständige Plattform (Host-App `food-alchemist`). Kunden bekommen ein Team, ein
Team-Admin lädt Kolleginnen und Kollegen ein. Heute darf **jedes Teammitglied alles**: Rezepte anlegen,
Preise ändern, freigeben, Einstellungen umbauen. Für Kunden mit mehr als einer Person geht das nicht.

---

## 1 · Ist-Stand (gemessen 2026-10-06, Code fa-pass ≈ main)

| Was | Befund |
|---|---|
| Team-Grenze (Mandant) | vorhanden: `Support/TeamScope` (`applyVisible`, `owns`, `mayWrite`), `Support/Curate::canCurate` (Ziel-Team = aktuelles Team) — in 27 von ~100 Livewire-Dateien und ~40 Services |
| Policy | `Policies/FoodAlchemistPolicy` für 11 Models registriert (`FoodAlchemistServiceProvider` 540–556), **aber nirgends aufgerufen** (kein `can()`/`authorize()`/`@can`) |
| Rollen | **keine**: `team_user.role` (owner/admin/member) wird im FA nirgends gelesen. `Settings/Rollen.php` = Kostenrollen (€/Std), nicht Rechte |
| Lücke | `RecipeService::setStatus` (479–487) prüft nur Sichtbarkeit, nicht Besitz; `Recipes/DetailPanel` (192–199) ruft ohne Guard → Status fremder/geerbter Rezepte änderbar |
| MCP | `Tools/FoodAlchemistTool`: Team-Grenze (`guardOwned`, `guardRecipe`, …), KI darf nur `stub`/`draft` editieren (`KI_EDITIERBARE_STATUS`). `read_only` ist nur Metadaten |
| Core | Modulzugriff ganz oder gar nicht (`Module::hasAccess`, `modulables`). Authz-Graph (`authz_grant`, use/read/write/manage) läuft im Schattenmodus, kennt nur ganze Module. `modulables.role` existiert, wird nie gelesen |

**Folgerung:** Die Rechte-Logik muss im FA-Modul entstehen. Kein Eingriff in Core (tabu). Den Authz-Graphen
später anschließen, wenn er Bereiche kennt — die FA-Rechte so benennen, dass das möglich bleibt.

---

## 2 · Drei Ebenen

| Ebene | Wer | Darf | Wo |
|---|---|---|---|
| 1 · Plattform-Admin | BHG.DIGITAL (`PLATFORM_ADMINS`) | alle Teams, Benutzer, Modulfreigaben | Host-App, `/verwaltung` — **gebaut** |
| 2 · Team-Admin | `team_user.role` owner/admin | eigenes Team + Unter-Teams: Mitglieder, Einladungen, Rollen, Unter-Teams; Modulfreigaben nur lesen; Inhaber vergibt nur ein Inhaber | Host-App, `/verwaltung` — **gebaut** (serverseitig geprüft) |
| 3 · FA-Rolle | jedes Mitglied | was es *im Food Alchemist* tun darf | **FA-Modul — diese Spec** |

---

## 3 · Die vier FA-Rollen (entschieden 2026-10-06)

Rollen sind **aufsteigend**: jede enthält die vorige.

| Rolle | Kurz |
|---|---|
| **Lesen** | **alles** sehen — Rezepturen, Allergene, Einkaufspreise, Marge, Kalkulation; drucken, exportieren. Nur nicht schreiben. (Entscheidung F1) |
| **Kuratieren** | anlegen, bearbeiten **und Rezepte auf aktiv stellen** (Entscheidung Dominique: Kuratieren = Rezepturen anlegen + aktiv stellen) |
| **Freigeben** | was nach außen wirkt: VK-Freigabe, veröffentlichen (Foodbook/Präsentation), Angebote versenden, Bestellungen senden, Beschaffung freigeben — *Zuschnitt zu bestätigen* |
| **FA-Admin** | Einstellungen, Kataloge, Wissen, Preislogik des Teams |

- **Standard für neue Mitglieder: Lesen** (entschieden). Team-Admin hebt bewusst an.
- **Team-Inhaber und -Admins (Ebene 2) sind automatisch FA-Admin** — nicht aussperrbar.
- **Plattform-Admin** ist in jedem Team FA-Admin.

### Hierarchie: Haupt-Team und Unter-Teams (Branchenstandard „Organisation → Arbeitsbereiche")

- **Rollen vererben sich nach unten, nie nach oben oder seitwärts.** Die Rolle im Haupt-Team gilt in allen
  Unter-Teams *mindestens*; im Unter-Team kann jemand zusätzlich eine höhere Rolle haben.
- **Team-Oberhaupt** (Inhaber/Admin des Haupt-Teams) ist FA-Admin in **jedem** Unter-Team und kann in jedes
  Unter-Team wechseln und dort arbeiten, ohne dort einzeln Mitglied zu sein.
  Umsetzung: Team-Wechsel bietet dem Oberhaupt alle Unter-Teams an; für den Core-Teamkontext wird er beim
  ersten Wechsel als Mitglied (Rolle admin) eingetragen — sichtbar und entfernbar in `/verwaltung`.
- **Lesen über Teams hinweg:** wer im Haupt-Team *Lesen* hat, liest alle Unter-Teams. Geschwister-Teams
  sehen einander nicht (Mandantentrennung zwischen Betrieben bleibt).
- Stammdaten des Haupt-Teams (Master/Parent) sehen Unter-Teams schon heute lesend (`TeamScope::applyVisible`).

### Rechte-Matrix nach Bereich

Status-Namen aus dem Code (Enums). „K“ = ab Kuratieren, „F“ = ab Freigeben, „A“ = FA-Admin.

| Bereich | Kuratieren (K) | Freigeben (F) | FA-Admin (A) |
|---|---|---|---|
| Rezepte, Basisrezepte, Gerichte | anlegen, bearbeiten, duplizieren, KI-Generator/Import, alle Status inkl. **`approved` (aktiv)** und `deprecated` | löschen *(F3)* | — |
| Grundprodukte | anlegen/bearbeiten als `tentative`/`review` | `approved`, `rejected`, Merge | — |
| Lieferanten, Lieferantenartikel | anlegen, bearbeiten, Allergene pflegen | Status `gesperrt`/`aktiv` | — |
| Kalkulation, VK | Simulation, VK-Entwürfe, Darreichungen | VK-Freigabe (`VkSnapshotService::release`) | Aufschlagsklassen, Kalkulationsdefaults |
| Foodbook, Präsentation, Speisekarte, Speiseplan | Entwurf bearbeiten | `aktiv`, `inaktiv`, `archiviert`; `PresentationService::publish` | — |
| Konzepte, Angebote, Formate, Pakete, Planung | anlegen, bearbeiten | Angebot versenden, angenommen/abgelehnt setzen | — |
| Produktion, Tagesplan | planen, Mengen, Aufgaben | Beschaffung freigeben (`procurement_released_at`) | — |
| Bestellungen | Entwurf | senden, bestätigen, stornieren | — |
| Wissen | lesen | — | Team-Dossiers anlegen/aktivieren (global bleibt Master) |
| Einstellungen (26 Bereiche) | — | — | alle |
| Rollen der Mitglieder | — | — | über `/verwaltung` (Ebene 2) |

**Lesen** sieht alles inkl. Einkaufspreise und Marge (F1 entschieden).

---

## 4 · Datenmodell

Neue FA-Tabelle (FA-Modul, keine Core-Tabelle):

```
foodalchemist_team_member_roles
  id, uuid, team_id (index), user_id (index), rolle (lesen|kuratieren|freigeben|admin),
  created_at, updated_at · unique(team_id, user_id)
```

- **Kein Eintrag = Lesen.** Owner/Admin im `team_user` = FA-Admin (berechnet, nicht gespeichert).
- **Unter-Teams:** gilt die Rolle des Unter-Teams; fehlt sie, die Rolle im Haupt-Team (wie Ebene 2).
- Enum `FaRolle` mit `rang()` und `mindestens(FaRolle)`.
- **Migration Bestand:** siehe *Offene Frage F2*.

---

## 5 · Durchsetzung — eine Stelle, alle Wege

1. **Service `FaRechte`** (FA-Modul): `darf(User, Team, Recht): bool` und `pruefe(...)` (wirft `FaRechtFehlt`).
   Rechte als Konstanten: `fa.lesen`, `fa.kuratieren`, `fa.freigeben`, `fa.verwalten` — so benannt, dass sie
   später auf den Core-Authz-Graphen (read/write/manage) abbildbar sind.
2. **Service-Schicht prüft** (nicht nur die Oberfläche): jede schreibende Service-Methode ruft `pruefe()`.
   Damit greift dieselbe Regel für Livewire, Bulk-Jobs und MCP. Status-Übergänge zentral in den
   `setStatus`-Methoden (Recipe, Concept, Format, Angebot, Order, PlanningSession, Foodbook …).
3. **MCP:** `FoodAlchemistTool` prüft zusätzlich vor jedem schreibenden Tool (`read_only = false`) mindestens
   Kuratieren; Freigabe-Tools Freigeben. Die KI kann nie mehr als ihr Benutzer. KI-Benutzer (`users.type =
   ai_user`) bekommen eine eigene Rolle pro Team (Standard: Kuratieren, nie Freigeben — bestehende Regel
   `KI_EDITIERBARE_STATUS` bleibt zusätzlich).
4. **Oberfläche:** Knöpfe, die nicht erlaubt sind, werden ausgeblendet (nicht nur deaktiviert); Editor im
   Nur-Lesen-Modus für Lesen. Blade-Helper `@faDarf('fa.freigeben') … @endfaDarf`.
5. **Lücke schließen:** `RecipeService::setStatus` prüft Besitz (`TeamScope::owns`) **und** Rolle.
6. **Policy nutzen:** `FoodAlchemistPolicy::update/delete` delegiert an `FaRechte` — dann wirken auch
   künftige `can()`-Aufrufe richtig.

## 6 · Verwaltung der Rollen

Der Team-Admin vergibt FA-Rollen in der Plattform-Verwaltung (`/verwaltung` → Teams und Mitglieder, neue Spalte
„Food Alchemist“). Die Host-App schreibt **nicht** direkt in die FA-Tabelle, sondern ruft den FA-Service
(`FaRechte::setzeRolle`) — die Regel „wer darf Rollen vergeben“ bleibt im Modul. MCP-Tool
`foodalchemist.member_roles.GET/PUT` (Lockstep-Regel).

---

## 7 · Abnahme (Tests, T+B)

| # | Fall | Erwartung |
|---|---|---|
| R1 | Lesen öffnet Rezept | sieht alles, kein Bearbeiten-/Freigeben-Knopf; Livewire-Aufruf `save` → 403 |
| R2 | Kuratieren legt Rezept an, setzt `review` | erlaubt; `approved` → 403 / Knopf fehlt |
| R3 | Freigeben setzt `approved`, VK-Freigabe | erlaubt |
| R4 | Kuratieren öffnet Einstellungen | Bereich fehlt, Direkt-URL → 403 |
| R5 | MCP `recipes.GENERATE` mit Lesen-Benutzer | Fehler „Recht fehlt“, nichts geschrieben |
| R6 | MCP-Freigabe-Tool mit Kuratieren | verweigert |
| R7 | Team-Inhaber ohne Eintrag | FA-Admin |
| R8 | Unter-Team ohne Eintrag, Haupt-Team Freigeben | Freigeben |
| R9 | `setStatus` auf geerbtes Master-Rezept | verweigert (Lücke geschlossen) |
| R10 | Rolle vergeben als Kuratieren | verweigert; als Team-Admin erlaubt |

## 8 · Reihenfolge

1. `FaRechte` + Tabelle + Enum + Tests R7/R8 (kein Verhalten ändert sich, Standard noch „alle Admin“-Schalter)
2. Lücke `setStatus` (R9) — sofort, unabhängig
3. Service-Prüfungen Bereich für Bereich (Rezepte → GP/LA → Kalkulation → Ausgabe → Produktion/Bestellungen → Einstellungen)
4. MCP-Durchsetzung (R5/R6)
5. Oberfläche (Knöpfe, Nur-Lesen-Editor)
6. Rollen-Spalte in `/verwaltung` + MCP-Tool
7. Schalter umlegen: Standard „Lesen“ aktiv

Schritt 1–2 ohne Risiko; ab 3 bereichsweise mit Suite (`fa_test.sh`) und Browser-Abnahme.

## 9 · Offene Fragen (Dominique)

- ~~F1~~ **entschieden:** Lesen sieht alles (inkl. EK, Marge), schreibt nichts.
- **F1b** Zuschnitt *Freigeben* (VK-Freigabe, veröffentlichen, versenden, bestellen) richtig — oder reicht Lesen/Kuratieren/Admin?
- **F2** Bestandsmitglieder: alle auf *Kuratieren* migrieren (niemand verliert heute Möglichkeiten), oder auf *Lesen* (strenger, Team-Admins heben an)?
- **F3** Darf *Kuratieren* löschen (eigene Entwürfe) oder nur *Freigeben*?
- **F4** Produktionsrollen aus Spec 35 §14 (ansehen/ausführen/planen/freigeben) — in die vier Rollen falten (Vorschlag: ausführen = Kuratieren) oder eigene Achse?
- ~~F5~~ **entschieden:** Umsetzung direkt in **fa-pass** (inhaltlich weiterentwickelt). fa-pass wird später als
  Ganzes zum Modul-Repo (siehe §10). Start erst, wenn die Pairing-Session ihren uncommitteten Stand in fa-pass
  gesichert hat (Überschneidung `FoodAlchemistServiceProvider`, `Recipes/DetailPanel`).

## 10 · Weg von fa-pass zum Modul-Repo (Skizze)

1. fa-pass-Stand vollständig committen (Design, Pairing, Speiseplan, Rechte).
2. Branch `design/pass` einmalig nach GitHub `platforms-foodalchemist` pushen und per PR nach `main` übernehmen
   („austauschen“: fa-pass wird die Hauptlinie; Konflikte einmal lösen, nicht dauerhaft zwei Linien pflegen).
3. fa-pass-Klon auflösen; Plattform (`food-alchemist/composer.server.json`) zieht `platform-foodalchemist: dev-main`
   → jeder Server-Deploy (`composer update`) holt den neuen Stand automatisch.
4. **Vorher klären:** demo/office binden dasselbe Modul ein — nach Schritt 2 bekäme demo die neue Hülle/Design.
  Entweder demo zieht mit, oder demo pinnt den alten Stand bzw. bindet den FA aus.

---

## 11 · Wissen: Plattform-Wissen unsichtbar, Team-Wissen sichtbar (Idee zur Entscheidung)

**Zielmodell steht seit 2026-09-06** (Dominique): kuratiertes Wissen wird global, nur der Plattform-Admin
schreibt und sieht es; Kunden sehen es nicht, die KI nutzt es für alle; Kunden bringen eigenes Team-Wissen ein.
Blockiert war es an Baustein 1 „Admin-Identität plattformweit (Core-Thema)“ — **das ist jetzt ohne Core lösbar.**

| Schicht | Wer sieht | Wer schreibt | KI nutzt |
|---|---|---|---|
| Plattform-Wissen (`team_id NULL`, heute Team 6) | nur Plattform-Admin | nur Plattform-Admin | ja, für alle Teams |
| Team-Wissen (Haupt-Team) | Team + Unter-Teams | FA-Admin des Teams | ja, für das Team |
| Unter-Team-Wissen | Unter-Team (+ Oberhaupt) | FA-Admin des Unter-Teams | ja |

- **Admin-Identität:** Die Plattform (Host) definiert das Gate `foodalchemist.plattform-admin`
  (`PLATFORM_ADMINS`). Das FA-Modul fragt nur dieses Gate; ist es nicht definiert (demo/office), gilt *nein* —
  dort ändert sich nichts.
- **Unsichtbar heißt überall unsichtbar:** Wissens-Browser, Kontext-Inspektor (zeigt nur „Plattform-Wissen,
  n Dossiers“ ohne Inhalt) **und MCP-Lesetools** (`knowledge.GET/SEARCH`). Sonst holt sich ein Kunde das Wissen
  über seinen eigenen KI-Assistenten. Die Generatoren (Rezept, Concepter, Speiseplan) nutzen es intern weiter.
- **Schalter:** `foodalchemist.wissen_plattform_sichtbar` (Standard `false` auf der Plattform).
- **Reihenfolge (zwingend):** erst Schreibpfad für den Plattform-Admin, dann Team-6-Wissen auf global umhängen
  (inkl. Kanon-Zeilen, Embedding-Partition off-peak). Sonst sperrt sich der Admin selbst aus.

## 12 · Rezepte: Freigabe über Ausgaben — Foodbook, Speiseplan, Speisekarte (Entscheidung Dominique 2026-10-06)

**Heute:** Ein Unter-Team sieht automatisch **alles** vom Haupt-Team plus den globalen Bestand
(`TeamScope::applyVisible` = NULL ∪ Ahnenkette) — alles oder nichts.

**Verworfen:** Schalter pro Rezept (nicht pflegbar) und eigene Kataloge (zusätzliches Konzept neben dem Foodbook).

**Entschieden: Eine Ausgabe wird einem Team freigeschaltet** — Foodbook, Speiseplan oder Speisekarte (alle drei
sind Ausgaben, `HatAusgabeZuordnung`). Die Ausgabe ist die Einheit, mit der gearbeitet und die einem Betrieb
übergeben wird.

1. Das Besitzer-Team schaltet eine Ausgabe für ein oder mehrere Teams/Unter-Teams frei
   (Tabelle `foodalchemist_ausgabe_freigaben`: ausgabe_typ, ausgabe_id, team_id, freigegeben_von, gueltig_bis?).
2. Das empfangende Team sieht **genau die Daten dieser Ausgabe**: Kapitel/Blöcke bzw. Plan-/Kartenzeilen → Gerichte →
   Basisrezepte (rekursiv, alle Ebenen) → Grundprodukte → Lieferantenartikel, inkl. Allergene, Kalkulation,
   Darreichungen. Nicht mehr pauschal alles vom Haupt-Team.
3. Die Sichtbarkeit wird als **Hülle (Closure) berechnet und gespeichert**, wenn freigeschaltet oder das
   Foodbook geändert wird (`foodalchemist_freigabe_objekte`: team_id, typ, objekt_id, quelle_foodbook_id).
   `TeamScope::applyVisible` liest: eigenes Team ∪ global ∪ diese Freigabe-Objekte.
4. **Anpassen (entschieden):** Freigegebene Inhalte sind lesend; Änderungen des Besitzers kommen sofort an.
   Wer anpassen will (ab Kuratieren), übernimmt eine **eigene Kopie** ins eigene Team (Herkunft gemerkt,
   Hinweis wenn sich das Original ändert).
5. **Kunden-IP-Regel bleibt:** Kunden-Foodbooks werden nie für fremde Kunden freigeschaltet.
6. **Übergang ohne Bruch:** Bis Freigaben gepflegt sind, behalten bestehende Unter-Teams die heutige
   Vererbung (Schalter pro Team „erbt alles vom Haupt-Team“, Standard an). Neue Teams starten ohne Vererbung.

## 13 · Betriebsmodus je Kunde: kuratiert oder autark (Entscheidung Dominique 2026-10-06)

Zwei Fälle muss es geben, umschaltbar in den Einstellungen (Plattform-Admin, je Haupt-Team):

| Modus | Beispiel | Wer kuratiert | Was gilt |
|---|---|---|---|
| **Kuratiert** | Broich Catering mit eigenen Teams; Foodpol kommt neu dazu und arbeitet „unter“ dem Kurator | ein **Kurator-Team** (z. B. Dominique / BHG.DIGITAL) | Kurator behält immer Zugriff auf seine Rezepturen und schaltet dem Kunden-Haupt-Team und dessen Unter-Teams Ausgaben (§12) frei, wie sie gebraucht werden. Der Kunde verwaltet seine Leute selbst (Ebene 2) und kann eigene Rezepte zusätzlich anlegen. |
| **Autark** | ein Betrieb nutzt den Food Alchemist komplett selbst | der Kunde selbst | kein Kurator; der Kunde pflegt und kuratiert alles allein. Plattform-Wissen (§11) nutzt die KI trotzdem. |

- **Kurator ≠ Eltern-Team.** Ein Kunde wie Broich Catering bleibt eigenes Haupt-Team mit eigenen Unter-Teams.
  Die Beziehung „wird kuratiert von“ ist eine eigene Zuordnung, nicht `parent_team_id`
  (Tabelle `foodalchemist_kuratierung`: kurator_team_id, kunden_team_id, aktiv_seit).
- **Neuer Betrieb unter dem Kurator:** Plattform-Admin legt das Haupt-Team an, setzt Modus *kuratiert* und den
  Kurator; ab dann kann der Kurator Ausgaben freischalten.
- **Wechsel autark ↔ kuratiert** ändert nur, wer zuspielen darf; Daten des Kunden bleiben unberührt.
- **Kunden-IP-Regel bleibt:** Was ein Kunde selbst anlegt, gehört ihm und wird nie für andere Kunden freigeschaltet.

### Sichtbarkeit der Kundendaten (F8, entschieden)

| Modus | Kurator / Plattform sieht die eigenen Rezepte des Kunden? |
|---|---|
| **Kuratiert** | **ja, lesend** — der Kurator will sehen, was der Kunde macht (Pflege, Qualität). Schreiben nur, wenn er dort Mitglied mit Rolle ist. |
| **Autark** | **nein** — weder Kurator noch Plattform-Admin sehen Rezepte des Kunden im Food Alchemist. Einzige Ausnahme: man ist Mitglied des Teams; das steuert der Kunde selbst und es ist für ihn in seiner Mitgliederliste sichtbar. |

- Plattform-Admin bleibt Betreiber (Teams, Benutzer, Module), aber **ohne Inhaltszugriff** auf autarke Kunden.
  Technisch: FA-Sichtbarkeit fragt Mitgliedschaft bzw. Kuratierung, nicht das Plattform-Admin-Gate.
- MCP gleich: kein Lesen fremder Kunden-Rezepte über Tools.

### Start eines autarken Kunden (F9, Richtung entschieden, Details offen)

- Bekommt eine **Grundeinstellung**: Warengruppen, Einheiten, Kalkulationsdefaults, Aufschlagsklassen,
  Concepter-Dimensionen (baut auf AD-01 „Team mit Defaults anlegen“ auf).
- Bekommt die **gepflegten Grundprodukte** (globaler GP-Stamm, Regelwerk Grundprodukte) — er verknüpft sie nur noch
  mit seinen eigenen Lieferantenartikeln.
- **Lieferantenartikel** aus dem Plattform-Bestand (z. B. Hanos, Chefs Culinar) kann er übernehmen, wenn er beim
  selben Lieferanten kauft. *Achtung:* Einkaufspreise sind kundenindividuell (eigene Konditionen) — übernommen wird
  der Artikel, der Preis kommt vom Kunden.
- **Offen F9b:** Bekommt er auch Basisrezepte (Fonds, Saucen, Pürees) als Startpaket, oder startet er ohne Rezepte?
