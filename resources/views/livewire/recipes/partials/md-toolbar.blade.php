{{-- M9-01j: Markdown-Toolbar (Fett · Kursiv · Überschriften · Listen · Maß) — wirkt auf die
     textarea mit id="{{ $ziel }}" (per ID statt $refs: verschachtelte Alpine-Scopes
     teilen keine refs); das input-Event hält wire:model synchron.
     Nutzung: @include(..., ['ziel' => 'vk-plating-text']) --}}
<div class="flex items-center gap-0.5" data-md-toolbar
     x-data="{
        md(vor, nach, block) {
            const ta = document.getElementById(@js($ziel));
            if (!ta) return;
            const [s, e] = [ta.selectionStart, ta.selectionEnd];
            const sel = ta.value.slice(s, e) || 'Text';
            const einsatz = block
                ? (s === 0 || ta.value[s - 1] === '\n' ? '' : '\n') + vor + sel + nach
                : vor + sel + nach;
            ta.value = ta.value.slice(0, s) + einsatz + ta.value.slice(e);
            ta.dispatchEvent(new Event('input'));
            ta.focus();
            ta.selectionEnd = s + einsatz.length - nach.length;
            ta.selectionStart = ta.selectionEnd - sel.length;
        },
     }">
    {{-- fa-pass: Symbole statt Kürzel, Farben nur über Tokens (hell + Werkbank-Modus). --}}
    @foreach([
        ['heroicon-m-bold', '**', '**', false, 'Fett'],
        ['heroicon-m-italic', '_', '_', false, 'Kursiv'],
        ['heroicon-m-h2', '## ', '', true, 'Überschrift (Abschnitt)'],
        ['heroicon-m-h3', '### ', '', true, 'Unterüberschrift'],
        ['heroicon-m-list-bullet', '- ', '', true, 'Aufzählung'],
        ['heroicon-m-numbered-list', '1. ', '', true, 'Nummerierte Schritte'],
        ['heroicon-m-code-bracket', '`', '`', false, 'Maß oder Code hervorheben'],
    ] as [$symbol, $vor, $nach, $block, $tip])
        <button type="button" @click="md(@js($vor), @js($nach), @js($block))"
                class="inline-flex items-center justify-center w-7 h-7 rounded-[var(--fa-radius-control)] text-[var(--fa-ink-3)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]"
                title="{{ $tip }}" aria-label="{{ $tip }}">@svg($symbol, 'w-4 h-4')</button>
    @endforeach
</div>
