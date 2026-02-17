<?php
// scripts/check-efevoo-tokens.php

use App\Models\EfevooToken;
use Illuminate\Support\Facades\Log;

// Verificar todos los tokens en la base de datos
$tokens = EfevooToken::all();

foreach ($tokens as $token) {
    echo "========================================\n";
    echo "Token ID: {$token->id}\n";
    echo "Alias: {$token->alias}\n";
    echo "Últimos 4 dígitos: {$token->card_last_four}\n";
    echo "Marca: {$token->card_brand}\n";
    echo "Cliente ID: {$token->customer_id}\n";
    echo "Activo: " . ($token->is_active ? 'Sí' : 'No') . "\n";
    
    // Token de cliente
    if ($token->client_token) {
        echo "Client Token: " . substr($token->client_token, 0, 30) . "...\n";
        echo "  Longitud: " . strlen($token->client_token) . "\n";
    } else {
        echo "Client Token: NO TIENE\n";
    }
    
    // Token de tarjeta (CRÍTICO)
    if ($token->card_token) {
        echo "Card Token: " . substr($token->card_token, 0, 30) . "...\n";
        echo "  Longitud: " . strlen($token->card_token) . "\n";
        
        // Verificar si es el formato correcto
        $isBase64 = base64_decode($token->card_token, true) !== false;
        echo "  Es Base64: " . ($isBase64 ? 'Sí' : 'No') . "\n";
        
        // Verificar longitud típica
        $typicalLength = strlen($token->card_token) > 200;
        echo "  Longitud típica (>200): " . ($typicalLength ? 'Sí' : 'No') . "\n";
    } else {
        echo "Card Token: NO TIENE (¡PROBLEMA!)\n";
    }
    
    echo "\n";
}

// Verificar específicamente el token ID 17 que estás usando
$token17 = EfevooToken::find(17);
if ($token17) {
    echo "🔍 ANALIZANDO TOKEN ID 17:\n";
    echo "Card Token preview: " . substr($token17->card_token ?? 'NO TIENE', 0, 50) . "...\n";
    echo "Longitud card_token: " . strlen($token17->card_token ?? '0') . "\n";
    
    // Comparar con el token que SÍ funciona
    $workingToken = 'AQICAHgOOyb5rLTXp65fv05fzH9angY+rzs05S23YASQChzr8AGkZy//Iu//Q60j1pDVjR0YAAAAizCBiAYJKoZIhvcNAQcGoHsweQIBADB0BgkqhkiG9w0BBwEwHgYJYIZIAWUDBAEuMBEEDKZJ7T2uce63h+auKAIBEIBHi2PRngZ+wKNnOsd1nIQyxMLVKaYNGgb7zX6llxLWnFFy3DZLAbIJmEl3rG9+y128fda6CbfFSlG/YuzwJ6orSwE0/KoKYNw=';
    
    if ($token17->card_token === $workingToken) {
        echo "✅ El token 17 ES EL CORRECTO\n";
    } else {
        echo "❌ El token 17 NO es el token que funciona\n";
        echo "Longitud working token: " . strlen($workingToken) . "\n";
    }
}