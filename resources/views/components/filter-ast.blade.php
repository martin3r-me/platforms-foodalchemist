{{--
    Der Kind-Ast einer Filter-Sidebar: zeichnet die Führungslinie, an der die Kind-Ebene hängt.

    Vorher waren die Kinder nur per `ml-4` frei eingerückt und schwebten ohne Bezug zum
    Elternteil. Die Linie verankert die Ebene — zusammen mit dem Balken-Modell in
    <x-foodalchemist::filter-row> ist damit auf einen Blick klar, wo man steht.

    Nutzung (data-Marker fließt über $attributes durch, die Schirme haben eigene):

        x-foodalchemist::filter-ast  data-sub-liste
          → Slot: die Kind-Zeilen als filter-row mit level="child"
--}}
<div {{ $attributes->merge(['class' => 'ml-3 mt-1 mb-1 pl-2 border-l border-black/10 space-y-0.5']) }} data-fa-filter-ast>
    {{ $slot }}
</div>
