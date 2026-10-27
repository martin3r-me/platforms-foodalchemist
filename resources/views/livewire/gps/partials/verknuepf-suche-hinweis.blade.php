{{-- „Artikel verknüpfen“ (Bugfix 27.10.): Hinweis, dass mehr Treffer da sind als angezeigt — $suche aus VerknuepfbareArtikelSuche::suche() --}}
@if($suche['mehr_als'] !== null)
    <p class="px-2 py-1 {{ $leise }}" data-la-suche-weitere>Mehr als {{ $suche['mehr_als'] }} Treffer, Suche verfeinern.</p>
@elseif($suche['weitere'] > 0)
    <p class="px-2 py-1 {{ $leise }}" data-la-suche-weitere>{{ $suche['weitere'] }} weitere Treffer, Suche verfeinern.</p>
@endif
