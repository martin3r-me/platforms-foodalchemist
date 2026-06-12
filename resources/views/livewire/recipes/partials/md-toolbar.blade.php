{{-- M9-01j: Markdown-Toolbar (Ist-App: B / I / H2 / H3 / Listen / <>) — wirkt auf die
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
    @foreach([
        ['B', '**', '**', false, 'fett'],
        ['I', '_', '_', false, 'kursiv'],
        ['H2', '## ', '', true, 'Überschrift (Phase)'],
        ['H3', '### ', '', true, 'Unter-Überschrift'],
        ['• Liste', '- ', '', true, 'Aufzählung'],
        ['1. Liste', '1. ', '', true, 'nummerierte Schritte'],
        ['<>', '`', '`', false, 'Code/Maß'],
    ] as [$lbl, $vor, $nach, $block, $tip])
        <button type="button" @click="md(@js($vor), @js($nach), @js($block))"
                class="px-1.5 py-0.5 rounded text-[11px] {{ $lbl === 'B' ? 'font-bold' : ($lbl === 'I' ? 'italic' : '') }} text-gray-500 dark:text-gray-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-violet-600"
                title="{{ $tip }}">{{ $lbl }}</button>
    @endforeach
</div>
