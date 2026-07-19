# Lieferanten-Management — die kommerzielle Beziehungs-Ebene (R9)

> **ROADMAP-Bezug:** R9.1 + R9.2 (eigener Track; Dominique-Wunsch 2026-07-05). **Ziel:** Die Beziehung zu einem Lieferanten aktiv **steuern** — Verträge, Konditionen, Absprachen, Zusagen, wer wofür Lead ist.
> **Scope-Grenze ✅ ENTSCHIEDEN (2026-07-18):** R9 = **nur** kommerzielle/strategische Ebene („mit wem zu welchen Bedingungen"). NICHT Bestellen/Wareneingang/Rechnungskontrolle (N-Track).
> **Reifegrad: 🟢 bau-reif** (Code-Kartierung 2026-07-19). Vorher ⚪ Dossier.

---

## 0. Code-Kartierung (verifiziert 2026-07-19)

**R9.2-Engine ist bereits da (reuse):** `LeadLaService` —
- `rangliste(gp, team): Collection` `:47` — die V-27-Kette (Strategie-Stufe → aktiv → hat-Preis → Vergleichspreis → …), annotiert je LA `ist_stamm/locked/gepinnt`. **Rang 1 = Lead-Kandidat, Rest = Ausweich/Zweitquelle → R9.2-Fallback fällt schon ab.**
- `pickLeadLa` `:84` (Vorschlag), `effektiverLead` `:105` (Pin gewinnt), `applyLeadLa` `:93` (schreibt globalen `gps.lead_la_supplier_item_id`), `setLeadLa(team,gp,laId)` `:114` (**manueller Override — OHNE Begründung** → NEU für R9.2), `sperren/pinnen/entknuepfen/verknuepfen`. Strategie: `LeadLaStrategieResolver` + Enum `GuenstigsterPreis|StammLieferant|PrioritaetsKette`.

**Supplier-Stammdaten (reuse):** `foodalchemist_suppliers` (`name/branch/gln/postal_code/city/address/homepage/email_order/is_inactive`; Finanz-Felder `iban/bic/vat_number` laut Migration-Header „P2 aufgeschoben"). `SupplierService` (`listWithCounts/create/update/setInactive`), `StammLieferantService` (`matrixFor/stammSupplierIdsFor/setStamm/unsetStamm`). Stamm-Matrix = EINE Tabelle `foodalchemist_preferred_suppliers` (`commodity_group_code` NULL=global / gefüllt=per-WG). UI `Suppliers/Index.php` + view (item-katalog-zentrisch, `leadSetzen`-★, `darfLieferantEdit`=`Curate::canCurate`) — **kein Supplier-Detail-Subview** → R9-Tabs neu; Vorlage `Gps/DetailPanel.php` (tabbed).

**Prioritätskette (reuse, aber begrenzt):** `supplier_priorities` existiert NICHT; Priorität = `team_settings.lead_la_prioritaeten` (geordnete supplier_id-JSON, **manuell, KEIN Volumen**). → **R9.2 „Volumen/Umsatz × Konditionen" hat KEINE Datenquelle** (kein Spend/Umsatz im Modul). Nächstes Signal = Nutzungs-Häufigkeit (`recipe_ingredients` via Lead-LA, wie `ConvenienceHighlightService`) als **Proxy**.

**Signal-Infra (reuse):** `SignalService::erzeuge` + `SignalTyp`-Enum (+`label()/icon()`) + Detektor-Muster `veraltetePreise(Team, tageSchwelle=180)` `:418` = **Vorlage für Fristen-Signal**. Runner `SignaleDetektorCommand`.

**MCP (reuse-Muster):** `ArtikelSearchTool/ArtikelListTool` read-only; Write-Tool-Vorlage `ConvenienceHighlightsPutTool` (`visibleToTeam`+`isOwnedBy`-Gate). `FoodAlchemistSupplier` hat `isOwnedBy`+`visibleToTeam`. **Kein `suppliers.*`-Tool** → neu.

**ALLES NEU (kein Scaffolding):** Absprachen-Log, Konditionen-Store, Dokumenten-Ablage. Latent: `foodalchemist_gp_team_overrides.note` (unbenutzt) bzw. `gp_la_preferences` (hat `LogsActivity`) als Ort für Override-Begründung.

---

## 1. Vorhandener Kern (Startpunkt, kein Neubau)
`lead_la_supplier_item_id` + `pick_lead_la`-Heuristik · `foodalchemist_preferred_suppliers` (Stamm-Matrix) · `team_settings.lead_la_prioritaeten` (manuelle Kette). → R9 **bündelt und bedient** das.

## 2. Festgezurrte Entscheidungen (2026-07-19)

| # | Frage | Entscheid | Begründung |
|---|---|---|---|
| E1 | Kontakte/Status | **Status-Enum-Spalte auf `suppliers` (aktiv/zweitquelle/gesperrt) + Child-Tabelle `foodalchemist_supplier_contacts` (name/rolle/tel/mail).** | `is_inactive` reicht nicht; mehrere Kontakte je Lieferant. |
| E2 | Absprachen-Log | **NEU `foodalchemist_supplier_agreements`** (supplier_id, typ, note, gilt_ab/gilt_bis, wiedervorlage_at, autor, LogsActivity). | Kein Scaffolding; datierte Zusagen mit Wiedervorlage. |
| E3 | Dokumente | **NEU `foodalchemist_supplier_documents`** (kind, file_ref, term_start/end, notice_period_days). v1 Metadaten + externer File-Ref; echter Upload (S3) später/Martin. | Keine Attachment-Infra im Modul; Metadaten+Frist reichen fürs Fristen-Signal. |
| E4 | Konditionen-Ort | **Spalten auf `suppliers` (Rückvergütung%, Zahlungsziel, Mindestbestellwert, Frei-Haus-Grenze) — EINE Migration, geteilt mit [13](13_Preis_Katalog_Ingest_Q2.md)/Q2.** | Konditionen per-Lieferant; kein Doppel-Schema mit dem Ingest. |
| E5 | Override-Begründung + Historie | **`setLeadLa` um `reason` erweitern → `reason`-Spalte auf `gp_la_preferences` (hat LogsActivity → Historie).** | Minimal-invasiv; Historie fällt über LogsActivity ab. |
| E6 | Volumen/Umsatz-Quelle | **v1 Nutzungs-Proxy** (recipe_ingredients via Lead-LA); echtes Spend/Umsatz = **deferred** (braucht Q2-Einkaufs-/Umsatzdaten). Ehrlich als Proxy markiert. | Kein Spend im Modul; Proxy gibt sofort „wo lohnt Bündelung"-Indiz. |
| E7 | Fristen-Signal | **NEU `SignalTyp::VertragsfristFaellig` + Detektor** (scannt `supplier_documents.notice`-Frist, Muster `veraltetePreise`). | Vorhandene Signal-Infra, nur Case+Detektor. |

## 3. R9.1 — Lieferanten-Stammblatt + Absprachen-Log · L · Etappe S1 · hängt an nichts · ✅ GEBAUT 2026-07-19 (Engine+MCP)

> **Gebaut 2026-07-19:** 4 Migrationen (E1 Status + E4 Konditionen auf `suppliers`; `supplier_contacts`/`supplier_agreements`/`supplier_documents`) + 3 Models + `SupplierStatus`-Enum + `SupplierService`-Erweiterung (setStatus/updateConditions/addContact/`stammblatt` inkl. WG-Abdeckung aus `foodalchemist_preferred_suppliers`) + `SupplierAgreementService` (create/forSupplier/dueForFollowUp/addDocument/documentsFor/`documentsDueForNotice`) + `SignalTyp::VertragsfristFaellig` + Detektor (E7) in `laufen()` + MCP `suppliers.GET`/`suppliers.PUT`/`supplier_agreements.POST` (D1-Gate). `SupplierRelationTest` (3 Pest) + 51er-Regression grün. **Offen (Folge-Slice):** Livewire-Detail-Tabs im `Suppliers/Index` (Vorlage `Gps/DetailPanel`).

**Bau (Spec):** Migrationen (E1/E2/E3/E4) + `SupplierAgreementService` + `SupplierService`-Erweiterung; Supplier-Detail-Tabs (Stammblatt/Absprachen/Konditionen/Lead-Steuerung) im `Suppliers/Index`-Detailbereich (Vorlage `Gps/DetailPanel`); Fristen-Detektor (E7); MCP `suppliers.GET/PUT` + `supplier_agreements.POST` (Write-Gate `isOwnedBy`).

**DoD:**
- [~] Lieferanten-Detailseite: Kontakte, Rollen, Status, WG-Abdeckung — Aggregat (`stammblatt`) + MCP da; Livewire-Tabs = Folge-Slice.
- [x] Absprachen-/Zusagen-Log je Lieferant: datierte Einträge, Wiedervorlage/Erinnerung, Autor.
- [x] Vertrags-/Dokumenten-Ablage mit Laufzeit + Kündigungsfrist → Fristen-Signal (E7).
- [x] Konditionen strukturiert: Rückvergütung/Bonus %, Zahlungsziel, Mindestbestellwert, Frei-Haus-Grenze (E4, geteilt mit Q2).
- [x] Team-scoped, LogsActivity, MCP `suppliers.GET/PUT` + `supplier_agreements.POST` (Lockstep, D1-Gate).

## 4. R9.2 — Lead-Lieferant-Steuerung als bediente Strecke · M · Etappe S2 · hängt an R9.1 + R1

**Bau:** Lead-Steuerung-Tab reutzt `rangliste`+`effektiverLead` direkt; `setLeadLa(reason)` (E5) + Historie via LogsActivity; Zweit-/Ausweichquelle hinterlegbar (fällt aus `rangliste`-Rang>1); Volumen×Konditionen-Auswertung auf Nutzungs-Proxy (E6).

**DoD:**
- [ ] Lead-LA je GP/WG sichtbar + überschreibbar in der UI — mit Begründungs-Vermerk bei manuellem Override (E5).
- [ ] Vorschlag = `pickLeadLa` (Vollständigkeit > Aktualität > Preis/Einheit); Mensch bestätigt/übersteuert, Entscheid protokolliert (LogsActivity).
- [ ] Zweit-/Ausweichquelle je GP hinterlegbar (aus `rangliste`).
- [ ] Auswertung: Volumen (Nutzungs-Proxy) je Lieferant × Konditionen → „wo lohnt Bündelung/Nachverhandlung" (E6, Proxy markiert).
- [ ] Test: Override setzt Lead korrekt (+reason), Recompute nutzt neuen Lead-EK; Historie nachvollziehbar.

## 5. Reuse-vs-Neu

| Reuse | Neu |
|---|---|
| `LeadLaService` (rangliste/pick/effektiverLead/apply/set), `SupplierService`/`StammLieferantService`, `foodalchemist_preferred_suppliers`, `SignalService`+Detektor-Muster, `ConvenienceHighlightsPutTool`-Write-Muster, `Gps/DetailPanel`-Tab-Vorlage | Status-Enum + `supplier_contacts`, `supplier_agreements`, `supplier_documents`, Konditions-Spalten (geteilt Q2), `reason` auf `gp_la_preferences`, `SignalTyp::VertragsfristFaellig`+Detektor, `suppliers.GET/PUT`+`supplier_agreements.POST`, Volumen-Proxy-Auswertung |

## 6. Verzahnung mit dem Kern
- **[13](13_Preis_Katalog_Ingest_Q2.md)/Q2:** Konditionen (R9) × frische Preise (Q2) = ehrliche EK-Wahrheit; Preis-Import kann Absprachen-Verstöße sichtbar machen. **Konditions-Migration geteilt (E4).**
- **[07](07_LA_First_GP_Mint_ueberall.md)/LA-First:** Sourcing-Wünsche (keine LA) landen beim Lieferanten-Management.
- **06/Convenience-Highlights:** Highlight-Kuratierung kann R9-Prioritäten als Score-Input nutzen (der Nutzungs-Proxy ist derselbe).
- **R2:** Konditionen fließen langfristig in die Marge (Rückvergütung ≠ Listen-EK).

## 7. Bewusste Nicht-Ziele
- Kein Bestellen/Wareneingang/Rechnungsprüfung (N-Track) — Scope-Grenze (Entscheid #1).
- Keine automatische Lead-Umschaltung ohne Menschen (Vorschlag ja, Commit menschlich).
- Kein CRM-Doppel zum Office-CRM — FA führt die warenwirtschaftliche Beziehung, nicht den Vertriebskontakt.

*Verzahnt: [13](13_Preis_Katalog_Ingest_Q2.md), [07](07_LA_First_GP_Mint_ueberall.md), 06, R2.1-Signale, `pick_lead_la`/Skript 213. Dossier 2026-07-18, bau-reif 2026-07-19.*
