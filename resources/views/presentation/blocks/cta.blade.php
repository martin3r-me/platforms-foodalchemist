{{-- Call-to-Action: Text + optionaler Link (nur http/https, extern, nofollow). --}}
@php $cta = $settings['cta'] ?? []; $link = trim((string) ($cta['link'] ?? '')); $safe = \Illuminate\Support\Str::startsWith($link, ['http://', 'https://']); @endphp
@if(!empty($cta['text']))
    <div class="pt-cta">
        @if($link !== '' && $safe)
            <a class="pt-cta-btn" href="{{ $link }}" target="_blank" rel="noopener noreferrer nofollow">{{ $cta['text'] }}</a>
        @else
            <span class="pt-cta-btn">{{ $cta['text'] }}</span>
        @endif
    </div>
@endif
