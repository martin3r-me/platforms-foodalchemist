@php use Platform\FoodAlchemist\Support\TrendVokabular as V; @endphp
@if($trends->isEmpty())
    <div class="fa-obs-empty"><h2>Noch keine freigegebenen Entwicklungen.</h2><p>Hier erscheinen Trends und Hypes mit dem Status „Auf dem Radar“ oder „In Umsetzung“.</p><a class="fa-obs-button" href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'liste']) }}" wire:navigate>In Trend Research prüfen</a></div>
@else
    <div class="fa-obs-briefing fa-obs-radar">
        <svg viewBox="0 0 520 550" role="img" aria-label="Trendradar mit {{ $trends->count() }} freigegebenen Entwicklungen">
            @foreach(\Platform\FoodAlchemist\Services\TrendService::RINGE as $ebene => [$innen, $aussen])<circle cx="260" cy="260" r="{{ $aussen * 220 }}" fill="none" stroke="var(--obs-line)"/><text x="267" y="{{ 260 - $aussen * 220 + 14 }}">{{ V::EBENEN[$ebene] }}</text>@endforeach
            <line x1="260" y1="34" x2="260" y2="486" stroke="var(--obs-line)"/>
            @foreach([-29,29] as $grad)<line x1="260" y1="260" x2="{{ 260 + 220 * cos(deg2rad($grad)) }}" y2="{{ 260 - 220 * sin(deg2rad($grad)) }}" stroke="var(--obs-line)"/>@endforeach
            <text x="35" y="510">FOOD</text><text x="375" y="510">NON-FOOD</text>
            @foreach($trends as $t)@php $position = $radar[$t->id]; @endphp
                <a href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'liste', 'trend' => $t->id]) }}" aria-label="{{ $t->name }} in Trend Research öffnen">
                    <circle cx="{{ $position['x'] }}" cy="{{ $position['y'] }}" r="17" fill="transparent"/>
                    <circle cx="{{ $position['x'] }}" cy="{{ $position['y'] }}" r="7" fill="{{ $t->typ === 'hype' ? 'var(--obs-card)' : 'var(--obs-accent)' }}" stroke="var(--obs-accent)" stroke-width="2" @if($t->typ === 'hype') stroke-dasharray="3 2" @endif/>
                    <title>{{ $t->name }} · {{ V::TYPEN[$t->typ] ?? $t->typ }} · {{ V::EBENEN[$t->ebene] ?? $t->ebene }}</title>
                </a>
            @endforeach
        </svg>
        <div>@foreach($trends as $t)<article class="fa-obs-trend"><span class="fa-obs-pill {{ $t->typ === 'trend' ? 'idea' : 'market' }}">{{ V::TYPEN[$t->typ] ?? $t->typ }} · {{ V::EBENEN[$t->ebene] ?? $t->ebene }}</span><h3>{{ $t->name }}</h3><p>{{ \Illuminate\Support\Str::limit($t->definition, 230) }}</p><a class="fa-obs-link" href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'liste', 'trend' => $t->id]) }}" wire:navigate>{{ $t->belege_count }} Belege · Einordnung ansehen @svg('heroicon-o-arrow-up-right')</a></article>@endforeach</div>
    </div>
@endif
