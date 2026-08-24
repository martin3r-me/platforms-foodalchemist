# Spec — Conceptor-Kaskade: Format → Concept → Paket (ein Modul, eine Logik)

**Status:** Entwurf zur Review (Dominique) · **Autor:** Cooking Jarvis · **Datum:** 2026-08-24
**Anlass:** Paket soll den Conceptor spiegeln; Format war als eigenes Modul ein Fehler und gehört als oberste Ebene in den Conceptor. Ziel: eine saubere, in sich logische Kaskade.

---

## 1. Vision (was wir wollen)

Eine **Kompositions-Kaskade** auf **einem** Conceptor-Muster:

```
FORMAT   (oben)   — bündelt mehrere Concepts zu einem Foodkonzept  (z. B. „Taiste & Fly")
  └─ CONCEPT      — wird gebaut aus Gerichten / Paketen / Struktur-Blöcken
       └─ PAKET   — wiederverwendbares Bündel mit eigenem Preis, in Concepts einfügbar
            └─ Gericht / Basisrezept
```

Leitprinzipien:
- **Ein Editor, ein Design** für alle drei Ebenen (Picker links, Positions-Tabelle, Vorschau, breit).
- **Gleiche Facetten** auf allen drei Ebenen: Eventtyp · Servierform · Einsatzmoment · Saison (betreffen dieselbe Range → gleicher Filter).
- **Ein Browser** mit drei Reitern (Format / Concept / Paket), gleiche Filter.
- **Format-Modul wird zurückgebaut** — es wird die oberste Conceptor-Ebene, kein Parallel-Modul.

---

## 2. Ist-Stand (grounded, kein Raten — Code-Scan 2026-08-24)

### Paket (`foodalchemist_packages` + `foodalchemist_package_dishes`)
- Posten = `package_dishes` (dish + `quantity`), **flach** — keine Struktur-Blöcke (Header/Text/Leerzeile).
- Felder: `class`, `level`, `role` (seit 2026-08-24 UI-seitig entfernt, Spalte schläft), `price_mode`, `price_per_person`.
- **Keine Facetten.** Geschirr je Posten: 2026-08-24 nachgerüstet (`package_dishes.tableware_item_id/_alt`).
- Einbettbar in ein Concept: `concept_slots.type='paket'` + `package_id`.

### Concept (`foodalchemist_concepts` + `foodalchemist_concept_slots`)
- Slots **polymorph**: `type ∈ gericht | basisrezept | paket | text | spacer | header | header_preis`.
- **Alle Facetten hier:** `serving_form_id`, `event_type_id` (Spalten) + `service_moments`, `seasons`, `target_groups` (Pivots) + Sektor-Eignung (hasMany).
- Preis: `price_mode` (auto=Σ / manuell) + `price_per_person`. Geschirr je Slot vorhanden.
- Der **Concepter\Editor** bedient Concept UND Paket schon heute (`type='concepts'|'pakete'`).

### Format (`foodalchemist_formats` + `foodalchemist_format_images`)
- **Identitäts-Wrapper:** name, consumer_name, claim, story, origin, customer (IP-Guard), status, note + Bildergalerie/Hero.
- **Kein Preis** (bewusst — read-only Min–Max-Range über die Editionen), **keine Facetten**, **keine Slots**.
- Mitgliedschaft = **`concepts.format_id`** (1:n, „Editionen") + `concepts.format_position` (Reihenfolge). Kein Pivot.
- Eigener Editor (`Formate\Editor`, Tabs Identität/Editionen/Bilder/Notizen), eigener Browser (`Formate\Browser`, Filter nur search/status/origin), Route `/formate`, Sidebar-Eintrag.
- **Live-Hooks:** Foodbook-Kapitel (`chapter.format_id`) + Speisekarten-Rubrik (`section.format_id`) rendern ein Format live (Identität + Editionen). **Diese Hooks dürfen nicht brechen.**
- MCP: `formats.LIST/GET/POST/PUT/SEARCH/DELETE` + `format_editions.POST/DELETE` + Foodbook/Speisekarte-Insert.

### Concepter-Browser
- Genau **2 Reiter** (concepts/pakete). Facetten-Filter (Servierform/Eventtyp/Moment/Saison) nur im Concepts-Reiter. Zeilen je Reiter über `…Service::paginateBrowser`.

### Einbettung & Rekursion
- Nur Concept→Paket→Gericht (fixe 3-Ebenen-Kette). **Kein** Concept-in-Concept / Format-in-Concept. **Kein Rekursions-/Tiefen-Guard** im Code.

---

## 3. Ehrliche Architektur-Einschätzung

**Die Kaskade ist richtig.** Aber „exakte Kopie / ein Modell" trifft die drei Ebenen unterschiedlich stark, weil die **Kind-Typen verschieden** sind (Paket→Gerichte, Concept→Gerichte/Pakete/Blöcke, Format→Concepts) und Format eine **andere Natur** hat (Identität + flache Editionen, kein Preis, kein Slot, Live-Foodbook/Speisekarte-Hooks).

Daraus zwei mögliche Ziel-Architekturen:

**A — Voll-Merge (eine Tabelle `concepts` + `kind` + polymorpher Slot-Kind-Typ dish|paket|concept).**
Format-Editionen würden zu Slots (child=concept). Maximal „ein Modell".
→ *Preis:* Größte Migration (packages+package_dishes+formats+format_images+format_id/position+concept_slots-Polymorphie), **Rekursions-Guard neu nötig**, und die **Foodbook/Speisekarte-Live-Hooks müssen von `format_id`+Editionen auf kind=format+concept-Slots umgebaut werden** (Bruchgefahr). Format hat keinen Preis — kind-spezifische Sonderregeln. Hoch-Risiko, mehrere Sessions.

**B — Ein Conceptor, drei Ebenen (empfohlen).**
- **Paket ⇄ Concept: echt mergen** auf das Slot-Modell mit `kind` (`concept` | `paket`). Paket-Posten wandern `package_dishes → concept_slots`. **Damit bekommt das Paket automatisch Struktur-Blöcke (Header/Text/Leerzeile), die reiche Positions-Tabelle, Geschirr-Erbe und die exakte Kopie** — genau das, was du willst. Das ist die Ebene, wo „ein Modell" sachlich stimmt (gleiche Form).
- **Format: eigene oberste Ebene, aber im selben Conceptor** — bleibt ein Identitäts-/Gruppierungs-Objekt (Editionen via `format_id`), **bekommt aber den Conceptor-Editor (breit, Vorschau, Picker der Concepts als „Positionen") + dieselben Facetten**. Kein Zwang in die Slot-Tabelle → **Foodbook/Speisekarte-Hooks bleiben unangetastet**, kein Preis-Sonderfall, kein Rekursions-Risiko.
- **Facetten auf allen drei Ebenen** (an Paket via Merge automatisch mit Concept vereint; an Format neu ergänzt).
- **Browser:** dritter Reiter „Formate", gleiche Facetten-Filter → deine gemeinsame Liste fällt gratis ab.
- **Format-Modul-UI (Route/Sidebar/Formate\Browser+Editor) wird zurückgebaut**, die Logik lebt im Conceptor + FormatService bleibt Fachservice.

**Empfehlung: B.** Es liefert dein ganzes Zielbild (Kaskade, gleiche Filter, Conceptor-Look, ein Modul, Format-Modul weg) und die **echte** Paket-Kopie inkl. Blöcke — **ohne** die riskante Voll-Tabellen-Fusion und **ohne** die Live-Hooks zu gefährden. „Ein Modell" gilt dort, wo es sachlich gilt (Paket=Concept); Format bleibt die logisch andere Ebene, teilt aber Editor + Facetten + Kaskade. Falls du später doch A willst, ist B ein sauberer Zwischenstand dorthin.

---

## 4. Zielmodell (bei Empfehlung B)

- `concepts.kind` (String 16, default `concept`; Werte `concept` | `paket`). Index.
- **Paket-Migration:** je `package` → `concepts`-Zeile (kind=paket, name/consumer_name/class/level/price_mode/price_per_person übernehmen); je `package_dish` → `concept_slots` (type gericht/basisrezept, quantity, tableware_item_id/_alt). `packages`/`package_dishes` nach Verifikation stilllegen (erst Shadow, dann drop).
- **Einbettung bleibt:** `concept_slots.type='paket'` + `package_id` → wird zu `type='paket'` + Referenz auf die kind=paket-`concepts.id` (neue Spalte `embedded_concept_id`, ODER `package_id` umdeuten auf concepts.id während der Migration). Genau **eine** Ebene tief (Concept→Paket), **kein** Paket-in-Paket (Guard: kind=paket-Concept darf keine Slots mit type='paket' haben).
- **Facetten an Paket:** durch den Merge automatisch (kind=paket nutzt dieselben concepts-Facettenspalten/-Pivots).
- **Format:** unverändert `formats` + `format_id`/`format_position`; **neu:** Facettenspalten/-Pivots an Format (serving_form_id, event_type_id, service_moments, seasons) — gespiegelt vom Concept-Schema.

---

## 5. Editor- & Browser-Vereinheitlichung

- **Conceptor-Aufbau als geteilter Baustein:** Picker (Tabs je Ebene) + Positions-Tabelle + Struktur-Buttons + Menü-Vorschau/Bearbeiten-Toggle in Blade-Partials, parametrisiert nach Ebene. (Der `gericht-baum`-Filter-Partial ist schon geteilt — das ist der Startpunkt.)
  - **Paket** (kind=paket): Picker-Tabs Gerichte/Basisrezepte (+ Struktur-Blöcke), Positions-Tabelle wie Concept, Preis eigen.
  - **Concept:** wie heute (zusätzlich Paket-Tab im Picker).
  - **Format:** Picker-Tab „Concepts" (Editionen zuordnen/anlegen), Positions-Liste = Editionen (Reihenfolge), Vorschau = Editionen-Menüs, Identität/Bilder-Tabs bleiben. Preis read-only Range.
- **Facetten-Kopf** (Servierform/Eventtyp/Einsatzmoment/Saison) als geteilter Partial für alle drei.
- **Browser:** dritter Reiter „Formate"; `normalisiereTab`/`wechselTab`/Blade-Toggle erweitern; `FormatService::paginateBrowser` um die Facetten-Filter ergänzen (nach Schema-Ergänzung §4). Facetten-Pills für alle Reiter freischalten.

---

## 6. MCP-Lockstep, Money, Guards

- **MCP:** Paket-Tools → auf kind=paket über die Concept-Contracts abbilden (oder Paket-Tools als dünne kind-Filter behalten). Format-Tools bleiben (formats.*, format_editions.*) + Facetten-Felder ergänzen. Reads `visibleToTeam`, Writes `isOwnedBy`.
- **Money-Paths:** Paket-Preis/Swap/Zielpreis laufen nach dem Merge über die concepts-Struktur (kind=paket); Format bleibt preislos (Range). Recompute-Pfade prüfen.
- **Rekursions-Guard (neu):** Concept darf Paket einbetten, Paket darf kein Paket/Concept einbetten; Format referenziert nur kind=concept. Ein einfacher kind-basierter Guard beim `fillSlot`/`attachEdition`.

---

## 7. Phasen (deploybar geschnitten)

- **Phase 0 — Baseline sichern:** aktuelle 4 Commits (Picker-Merge, Rolle raus, Geschirr-Tab, Bugfixes + Namens-Fix) committen + deployen (FA-scoped migrate). Sauberer Ausgangspunkt.
- **Phase 1 — Paket ⇄ Concept (kind):** Merge package(_dishes)→concepts(_slots) + `kind`; Paket bekommt Positions-Tabelle + Struktur-Blöcke + Geschirr-Erbe; MCP + Money + Tests; Bestandsdaten-Migration + Backup. **Größter Brocken, eigener PR.** *(Liefert die „exakte Kopie" fürs Paket.)*
- **Phase 2 — Facetten überall + Browser-Kaskade:** Facetten an Format ergänzen; Browser dritter Reiter + gemeinsame Filter; Facetten-Kopf-Partial für alle drei.
- **Phase 3 — Format in den Conceptor + Modul-Rückbau:** Format-Editor auf Conceptor-Look (breit, Vorschau, Concept-Picker) heben; `/formate`-Route + Sidebar + `Formate\Browser` zurückbauen (Logik lebt im Conceptor + FormatService); Foodbook/Speisekarte-Hooks verifizieren (dürfen nicht brechen).

Jede Phase: Sandbox-Migrate-Verify → PR → demo-Deploy (FA-scoped migrate) → Suite grün.

---

## 8. Risiken

- **Bestandsdaten-Migration** packages→concepts (Phase 1): DESTRUKTIV → Pflicht-Backup, Shadow-Phase (beide Tabellen parallel bis verifiziert), erst dann drop.
- **Foodbook/Speisekarte-Live-Hooks** am Format (Phase 3): nicht brechen — bei B bleiben sie unangetastet (Format-Tabelle bleibt).
- **MCP + ~20 Tests** referenzieren paket/role/format — Lockstep zwingend.
- **`concept_slots`-Polymorphie**: Paket-Kind-Slots dürfen keine Paket-Slots enthalten (Guard).

---

## 9. Offene Entscheidungen für Dominique

1. **Architektur A vs. B** (Empfehlung: **B**).
2. **Format-Preis:** bleibt read-only Range (heute so) — bestätigt?
3. **Format-Struktur:** braucht Format Struktur-Blöcke (Header/Text zwischen Editionen), oder reicht die geordnete Editionen-Liste?
4. **Einbettung:** Concept→Paket eine Ebene (kein Paket-in-Paket) — bestätigt?
5. **Reihenfolge:** erst Phase 0 (Baseline deployen) — ja?
