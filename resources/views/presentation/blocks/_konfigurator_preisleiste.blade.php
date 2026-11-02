{{-- Spec 94 Stufe 5 · Gäste −/Zahl/+ und Preis pro Person/gesamt (oben am Desktop, unten am Handy; beide über dieselben Daten-Marker). --}}
<div class="pt-konfig-stepper">
    <span class="pt-konfig-leise">Gäste</span>
    <button type="button" class="pt-konfig-stepper-knopf" aria-label="Weniger Gäste" data-konfig-pax-minus>−</button>
    <input type="number" min="{{ $min }}" max="{{ $max }}" value="{{ $start }}" inputmode="numeric" aria-label="Gäste" class="pt-konfig-stepper-zahl" data-konfig-pax>
    <button type="button" class="pt-konfig-stepper-knopf" aria-label="Mehr Gäste" data-konfig-pax-plus>+</button>
</div>
<div class="pt-konfig-summen">
    <p><span class="pt-konfig-leise"><span data-konfig-ab>ab · </span>Pro Person</span><strong data-konfig-pp>—</strong></p>
    <p><span class="pt-konfig-leise"><span data-konfig-ab>ab · </span>Gesamt</span><strong data-konfig-gesamt>—</strong></p>
</div>
