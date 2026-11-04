@php
    $zwecke = $e->observatorium['verwendungen'] ?? [];
    $istIdee = in_array('idee', $zwecke, true);
    $istMarkt = in_array('markt', $zwecke, true) || in_array('chance', $zwecke, true);
    $ton = $istIdee ? 'idea' : ($istMarkt ? 'market' : 'news');
    $label = in_array('chance', $zwecke, true) ? 'Chance prüfen' : ($istIdee ? 'Food-Idee' : ($istMarkt ? 'Marktbewegung' : 'Branchennews'));
    $merkerEintrag = $merker->get($e->id);
@endphp
<article class="fa-obs-story" wire:key="obs-artikel-{{ $e->id }}" data-observatorium-artikel="{{ $e->id }}">
    @if($mitIllustration ?? false)
        <div class="fa-obs-category-art {{ $ton }}" aria-hidden="true"><span></span><span></span><span></span></div>
    @endif
    <div class="fa-obs-story-body">
        <div class="fa-obs-meta"><span class="fa-obs-pill {{ $ton }}">{{ $label }}</span><span>{{ $e->veroeffentlicht_am?->format('d.m.Y') ?? 'Datum offen' }}</span></div>
        <h3><button type="button" wire:click="oeffnen({{ $e->id }})">{{ $e->titel }}</button></h3>
        <div class="fa-obs-source">{{ $e->herausgeber ?: ($e->quelle?->name ?: 'Eigene Recherche') }}</div>
        @if($e->zusammenfassung || $e->teaser)<p>{{ \Illuminate\Support\Str::limit($e->zusammenfassung ?: $e->teaser, 190) }}</p>@endif
        <div class="fa-obs-story-foot">
            <button type="button" class="fa-obs-link" wire:click="oeffnen({{ $e->id }})">Entdecken @svg('heroicon-o-arrow-up-right')</button>
            <button type="button" class="fa-obs-save" wire:click="merken({{ $e->id }})" wire:loading.attr="disabled" wire:target="merken({{ $e->id }})" aria-pressed="{{ $merkerEintrag?->gemerkt ? 'true' : 'false' }}" aria-label="{{ $merkerEintrag?->gemerkt ? 'Nicht mehr merken' : 'Beitrag merken' }}: {{ $e->titel }}">
                @svg($merkerEintrag?->gemerkt ? 'heroicon-s-bookmark' : 'heroicon-o-bookmark')
            </button>
        </div>
        @if($merkerEintrag?->trend_pruefen)<div class="fa-obs-source" style="margin:10px 0 0">Zur persönlichen Trendprüfung vorgemerkt</div>@endif
    </div>
</article>
