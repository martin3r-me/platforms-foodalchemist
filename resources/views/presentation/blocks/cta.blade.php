{{-- Call-to-Action (Text + optionaler Link). Link nur bei http(s). --}}
@php
    $cta = $settings['cta'] ?? [];
    $text = trim((string) ($cta['text'] ?? ''));
    $link = trim((string) ($cta['link'] ?? ''));
    $linkOk = $link !== '' && \Illuminate\Support\Str::startsWith($link, ['http://', 'https://']);
@endphp
@if($text !== '')
    <div class="pt-measure">
        <div class="pt-cta">
            @if($linkOk)
                <a class="pt-cta-btn" href="{{ $link }}" target="_blank" rel="noopener noreferrer nofollow">{{ $text }}</a>
            @else
                <span class="pt-cta-btn">{{ $text }}</span>
            @endif
        </div>
    </div>
@endif
