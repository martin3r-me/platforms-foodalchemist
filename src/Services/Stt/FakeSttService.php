<?php

namespace Platform\FoodAlchemist\Services\Stt;

/** M7-10: Sandbox/Test-STT — liefert den konfigurierten Text (kein Netz, kein Key). */
class FakeSttService implements SttServiceContract
{
    public function transcribe(string $audioBinary, string $mimeType = 'audio/webm'): string
    {
        return (string) config('foodalchemist.stt.fake_text', 'Suche BBQ Sauce');
    }
}
