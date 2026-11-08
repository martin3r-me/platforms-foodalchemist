@php use Platform\FoodAlchemist\Support\TrendVokabular as V; @endphp
<div class="fa-obs-radar-summary"><span class="fa-obs-subtitle">{{ $trends->count() }} von {{ $radarGesamt }} freigegebenen Entwicklungen</span>@if($radarFilterAktiv)<button class="fa-obs-button" type="button" wire:click="radarFilterZuruecksetzen">Radarfilter zurücksetzen</button>@endif</div>
@if($trends->isEmpty() && $radarFilterAktiv)
    <div class="fa-obs-empty"><h2>Keine Entwicklungen für diese Filter.</h2><p>Ändere Kategorie, Sparte oder Einordnung in der Bereichsspalte.</p><button class="fa-obs-button" type="button" wire:click="radarFilterZuruecksetzen">Radarfilter zurücksetzen</button></div>
@elseif($trends->isEmpty())
    <div class="fa-obs-empty"><h2>Noch keine freigegebenen Entwicklungen.</h2><p>Hier erscheinen Trends und Hypes mit dem Status „Auf dem Radar“ oder „In Umsetzung“.</p>@if($kuratiertHier)<a class="fa-obs-button" href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'liste']) }}" wire:navigate>In Trend Research prüfen</a>@endif</div>
@else
    <div class="fa-obs-briefing fa-obs-radar">
        @unless($istHandy)
        <svg class="fa-obs-radar-chart" data-observatorium-radar-chart viewBox="0 0 520 550" role="img" aria-label="Trendradar mit {{ $trends->count() }} freigegebenen Entwicklungen">
            @foreach(\Platform\FoodAlchemist\Services\TrendService::RINGE as $ebene => [$innen, $aussen])<circle cx="260" cy="260" r="{{ $aussen * 220 }}" fill="none" stroke="var(--obs-line)"/><text x="267" y="{{ 260 - $aussen * 220 + 14 }}">{{ V::EBENEN[$ebene] }} · {{ $trends->where('ebene', $ebene)->count() }}</text>@endforeach
            <line x1="260" y1="34" x2="260" y2="486" stroke="var(--obs-line)"/>
            @foreach([-29,29] as $grad)<line x1="260" y1="260" x2="{{ 260 + 220 * cos(deg2rad($grad)) }}" y2="{{ 260 - 220 * sin(deg2rad($grad)) }}" stroke="var(--obs-line)"/>@endforeach
            <text x="35" y="510">FOOD</text><text x="375" y="510">NON-FOOD</text>
            <text x="377" y="69">Service &amp; Format</text><text x="470" y="252" text-anchor="end">Ambiente</text><text x="390" y="450">Getränke</text>
            @foreach($trends as $t)@php
                $position = $radar[$t->id];
                $farbe = $t->kategorie === 'food' ? 'var(--fa-warn)' : 'var(--fa-ok)';
                $deck = ['hoch' => 1, 'mittel' => .62, 'niedrig' => .45][$t->wirksameKonfidenz()] ?? .45;
            @endphp
                <g role="button" tabindex="0" wire:click="trendOeffnen({{ $t->id }})" x-on:keydown.enter="$wire.trendOeffnen({{ $t->id }})" aria-label="{{ $t->name }}: Details ansehen" data-observatorium-radar-punkt="{{ $t->id }}">
                    <circle cx="{{ $position['x'] }}" cy="{{ $position['y'] }}" r="17" fill="transparent"/>
                    @if($t->befragung_bestaetigt)<circle cx="{{ $position['x'] }}" cy="{{ $position['y'] }}" r="11.5" fill="none" stroke="var(--fa-gold-2)" stroke-width="2"/>@endif
                    <circle opacity="{{ $deck }}" cx="{{ $position['x'] }}" cy="{{ $position['y'] }}" r="7" fill="{{ $t->typ === 'hype' ? 'var(--obs-card)' : $farbe }}" stroke="{{ $farbe }}" stroke-width="2" @if($t->typ === 'hype') stroke-dasharray="3 2" @endif/>
                    <title>{{ $t->name }} · {{ V::TYPEN[$t->typ] ?? $t->typ }} · {{ V::EBENEN[$t->ebene] ?? $t->ebene }}</title>
                </g>
            @endforeach
        </svg>
        @endunless
        <div class="fa-obs-entwicklungen" data-observatorium-entwicklungskarten>@foreach($trends as $t)<article class="fa-obs-trend"><span class="fa-obs-pill {{ $t->typ === 'trend' ? 'idea' : 'market' }}">{{ V::TYPEN[$t->typ] ?? $t->typ }} · {{ V::EBENEN[$t->ebene] ?? $t->ebene }}</span><h3><button type="button" class="fa-obs-trend-titel" wire:click="trendOeffnen({{ $t->id }})" data-observatorium-trend-oeffnen="{{ $t->id }}">{{ $t->name }}</button></h3>@if($istHandy)<div class="fa-obs-meta"><span>{{ V::KATEGORIEN[$t->kategorie] ?? 'Kategorie offen' }}</span><span>Konfidenz: {{ V::KONFIDENZ[$t->wirksameKonfidenz()] }}</span></div>@endif<p>{{ \Illuminate\Support\Str::limit($t->definition, 230) }}</p>@if($kuratiertHier)<a class="fa-obs-link" href="{{ route('foodalchemist.trendradar.index', ['ansicht' => 'liste', 'trend' => $t->id]) }}" wire:navigate>{{ $t->belege_count }} Belege · Einordnung ansehen @svg('heroicon-o-arrow-up-right', 'fa-obs-inline-icon', ['width' => 16, 'height' => 16])</a>@else<button type="button" class="fa-obs-link" wire:click="trendOeffnen({{ $t->id }})">{{ $t->belege_count }} Belege · Details ansehen</button>@endif</article>@endforeach</div>
    </div>
    @unless($istHandy)<div class="fa-obs-radar-legend"><span>● Trend · ◌ Hype</span><span>Goldring: Befragung bestätigt · blass: unsichere Belege</span><span>Innen: Mode · außen: Metatrend</span><span>Trends ohne Sparte gelten für alle Sparten.</span></div>@endunless
@endif
