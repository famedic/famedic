<?php
// test-conexion-efevoo.php

$config = [
    'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
    'api_user' => 'Efevoo Pay',
    'api_key' => 'Hq#J0hs)jK+YqF6J'
];

echo "========================================\n";
echo "DIAGNÓSTICO DE CONEXIÓN\n";
echo "========================================\n\n";

// 1. Verificar DNS
echo "1. RESOLUCIÓN DNS:\n";
$host = parse_url($config['api_url'], PHP_URL_HOST);
echo "   Host: $host\n";
$ip = gethostbyname($host);
echo "   IP: $ip\n\n";

// 2. Verificar puerto
echo "2. PUERTO (443 - HTTPS):\n";
$fp = @fsockopen($host, 443, $errno, $errstr, 10);
if ($fp) {
    echo "   ✅ Puerto 443 accesible\n";
    fclose($fp);
} else {
    echo "   ❌ No se puede conectar al puerto 443: $errstr\n";
}

// 3. Probar conexión simple
echo "\n3. PRUEBA DE CONEXIÓN SIMPLE:\n";
$ch = curl_init($config['api_url']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_NOBODY => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

echo "   Intentando conectar... ";
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

if ($error) {
    echo "❌ Error: $error\n";
    echo "   CURL Error No: " . curl_errno($ch) . "\n";
} else {
    echo "✅ Conectado - HTTP Code: $httpCode\n";
}

curl_close($ch);

// 4. Verificar certificado SSL
echo "\n4. VERIFICACIÓN SSL:\n";
$ch = curl_init($config['api_url']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CERTINFO => true,
    CURLOPT_VERBOSE => true,
    CURLOPT_STDERR => fopen('ssl_debug.log', 'w'),
    CURLOPT_TIMEOUT => 10,
]);

curl_exec($ch);
$certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
curl_close($ch);

if ($certInfo) {
    echo "   ✅ Certificado SSL válido\n";
    echo "   Emisor: " . ($certInfo[0]['Issuer'] ?? 'N/A') . "\n";
    echo "   Válido hasta: " . ($certInfo[0]['Expire date'] ?? 'N/A') . "\n";
} else {
    echo "   ❌ No se pudo verificar certificado\n";
}

echo "\n========================================\n";
echo "SOLUCIONES A INTENTAR:\n";
echo "========================================\n";

echo "1. Actualizar certificados CA de PHP:\n";
echo "   Descarga: https://curl.se/docs/caextract.html\n";
echo "   Guarda como: C:/php/extras/ssl/cacert.pem\n\n";

echo "2. Usar IP directamente (si hay problemas DNS):\n";
echo "   nslookup test-intgapi.efevoopay.com\n";
echo "   Luego usa la IP en la URL\n\n";

echo "3. Probar con diferentes versiones TLS:\n";
echo "   Agregar: CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2\n\n";

echo "4. Verificar firewall/proxy:\n";
echo "   - Desactivar temporalmente firewall\n";
echo "   - Configurar proxy si es necesario:\n";
echo "     CURLOPT_PROXY => 'proxy:puerto'\n";