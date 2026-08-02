{{-- Erfolg-Tab — die Erlösseite. Bis Spec 32 · C3 hier ein ehrlicher Leerzustand statt
     Null-Kacheln: es gibt im Modul KEIN Verkaufs-Ist. Die Kostenseite hat mit
     `foodalchemist_purchase_transactions` ein Ist-Journal, die Erlösseite nichts —
     also kein Umsatz, keine Ist-Marge, kein Renner/Penner und keine Soll-Ist-Abweichung.

     Diese Datei verschwindet mit C3 (sales_facts + Import + MenuEngineeringService). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal-section title="Erlösseite">
    <div class="space-y-3" data-ctrl-erfolg-leer>
        <p class="text-xs text-gray-600">
            Für diesen Tab fehlt die Datengrundlage: das Modul kennt heute nur die Kostenseite.
            Was tatsächlich verkauft wurde — Menge und Umsatz je Gericht — wird nirgends geführt.
        </p>
        <p class="text-xs text-gray-500">
            Solange das so ist, sind <strong>Ist-Marge</strong>, <strong>Menu-Engineering</strong>
            (Renner/Penner) und die <strong>Abweichung zwischen theoretischem und tatsächlichem
            Wareneinsatz</strong> nicht rechenbar. Was du heute siehst, ist Kalkulation — also Soll.
        </p>
        <p class="text-[11px] text-gray-500">
            Nächster Schritt: Verkaufs-Ist einlesen (CSV-Export aus Kasse oder Abrechnung, mit
            Spalten-Zuordnung und Trockenlauf). Bis dahin läuft die Popularitäts-Achse ersatzweise
            über das Praxis-Feedback je Gericht.
        </p>
    </div>
</x-foodalchemist::modal-section>
