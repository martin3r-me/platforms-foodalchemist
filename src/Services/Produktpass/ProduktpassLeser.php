<?php

namespace Platform\FoodAlchemist\Services\Produktpass;

use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\ContextFile;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Support\ProduktpassFelder;

/**
 * Quelle „produktpass": liest die hochgeladene Datei (PDF oder Foto) über den Core-LLM-Contract
 * ({@see AiGatewayService::propose}, Prompt `produktpass.extract`, Vision/PDF wie beim Rezept-Import) und gibt den
 * normalisierten Extrakt zurück. Nichts wird geraten: was die KI nicht ablesen kann, fehlt im Ergebnis.
 */
class ProduktpassLeser implements ProduktdatenQuelle
{
    public function __construct(private AiGatewayService $ai)
    {
    }

    public function name(): string
    {
        return ProduktpassFelder::QUELLE_PASS;
    }

    /** @param array{produktpass?: FoodAlchemistProduktpass} $optionen */
    public function liefere(FoodAlchemistSupplierItem $la, array $optionen = []): array
    {
        $pass = $optionen['produktpass'] ?? null;
        if (! $pass instanceof FoodAlchemistProduktpass) {
            throw new \RuntimeException('Kein Produktpass übergeben.');
        }
        $teil = $this->dateiTeil($pass);
        $vorschlag = $this->ai->propose('produktpass.extract', [
            'schluessel' => ProduktpassFelder::schluessel(),
            'allergene' => ProduktpassFelder::allergene(),
            'hinweis' => 'Der Produktpass bzw. das Datenblatt steht im Anhang. Lies wörtlich ab; Fehlendes weglassen.',
        ], ['bilder' => [$teil]]);

        $werte = is_array($vorschlag->werte ?? null) ? $vorschlag->werte : [];
        $aus = ProduktpassNormalisierer::normalisiere($werte, ProduktpassFelder::QUELLE_PASS);
        $aus['modell'] = $vorschlag->model;

        return $aus;
    }

    /** @return array{typ:string,url?:string,detail?:string,name?:string,data?:string} */
    private function dateiTeil(FoodAlchemistProduktpass $pass): array
    {
        $datei = $pass->context_file_id ? ContextFile::find($pass->context_file_id) : null;
        if ($datei === null || ! Storage::disk($datei->disk)->exists($datei->path)) {
            throw new \RuntimeException('Die Datei des Produktpasses ist nicht mehr im Speicher.');
        }
        $bytes = Storage::disk($datei->disk)->get($datei->path);
        $mime = strtolower((string) ($pass->mime ?: $datei->mime_type));
        if ($mime === 'application/pdf') {
            return ['typ' => 'pdf', 'name' => (string) ($pass->original_name ?: 'produktpass.pdf'), 'data' => 'data:application/pdf;base64,'.base64_encode($bytes)];
        }

        return ['typ' => 'image', 'url' => 'data:'.$mime.';base64,'.base64_encode($bytes), 'detail' => 'high'];
    }
}
