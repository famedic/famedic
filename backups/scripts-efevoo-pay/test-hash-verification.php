<?php
// test-hash-verification.php

$config = [
    'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q',
    'clave' => '6nugHedWzw27MNB8'
];

echo "========================================\n";
echo "VERIFICACIÓN DE HASH/TOTP\n";
echo "========================================\n\n";

// Generar TOTP
function generateTOTP($secret) {
    $timestamp = floor(time() / 30);
    
    // Decodificar base32
    $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32Lookup = array_flip(str_split($base32Chars));
    
    $buffer = 0;
    $bitsLeft = 0;
    $key = '';
    
    for ($i = 0; $i < strlen($secret); $i++) {
        $ch = $secret[$i];
        if (!isset($base32Lookup[$ch])) continue;
        
        $buffer = ($buffer << 5) | $base32Lookup[$ch];
        $bitsLeft += 5;
        
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $key .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    
    // Generar HMAC-SHA1
    $timeBytes = pack('N*', 0) . pack('N*', $timestamp);
    $hash = hash_hmac('sha1', $timeBytes, $key, true);
    
    // Extraer código
    $offset = ord($hash[19]) & 0xF;
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

// Probar varias veces (TOTP cambia cada 30 segundos)
echo "🔑 PRUEBAS DE TOTP (cambia cada 30s):\n";
for ($i = 0; $i < 3; $i++) {
    $totp = generateTOTP($config['totp_secret']);
    echo "  Intento $i: $totp\n";
    sleep(1);
}

echo "\n🔒 PRUEBAS DE HASH:\n";
$totp = generateTOTP($config['totp_secret']);
echo "  TOTP actual: $totp\n";

// Diferentes formas de generar el hash
echo "\n  Hash con clave como mensaje:\n";
$hash1 = hash_hmac('sha256', $config['clave'], $totp);
echo "    hash_hmac('sha256', clave, totp): $hash1\n";

echo "\n  Hash con totp como mensaje:\n";
$hash2 = hash_hmac('sha256', $totp, $config['clave']);
echo "    hash_hmac('sha256', totp, clave): $hash2\n";

echo "\n  Hash Base64:\n";
$hash3 = base64_encode(hash_hmac('sha256', $config['clave'], $totp, true));
echo "    base64: $hash3\n";

echo "\n  Hash con binario raw:\n";
$hash4 = hash_hmac('sha256', $config['clave'], $totp, true);
echo "    raw (binario): " . bin2hex($hash4) . "\n";