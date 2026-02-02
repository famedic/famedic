<?php
// tests/efevoopay-test-final.php

require 'vendor/autoload.php';

use App\Services\EfevooPayService;

class EfevooPayTest
{
    private $efevooPay;
    
    public function __construct()
    {
        $this->efevooPay = new EfevooPayService();
    }
    
    public function runAllTests()
    {
        echo "========================================\n";
        echo "PRUEBAS EFEVOOPAY API (MONTOS ≤ $3 MXN)\n";
        echo "========================================\n\n";
        
        // Tarjeta de prueba proporcionada
        $testCard = '5457742750352944';
        $testCVV = '123'; // CVV de prueba
        $expiryDate = '1226'; // Diciembre 2026 (formato YYMM)
        $expiryDateFormatted = '12/26'; // Formato para 3DS
        
        // 1. Obtener token
        echo "1. Obteniendo token de cliente...\n";
        $tokenResult = $this->efevooPay->getClientToken();
        
        if ($tokenResult['success']) {
            echo "   ✅ Token obtenido exitosamente\n";
            echo "   Token: " . substr($tokenResult['data']['token'], 0, 30) . "...\n";
            echo "   Duración: " . $tokenResult['data']['duracion'] . "\n\n";
            
            // 2. Buscar transacciones
            echo "2. Buscando transacciones recientes...\n";
            $transactions = $this->efevooPay->searchTransactions([
                'range1' => date('Y-m-d 00:00:00', strtotime('-7 days')),
                'range2' => date('Y-m-d 23:59:59')
            ]);
            
            if ($transactions['success']) {
                $count = count($transactions['data']['data'] ?? []);
                echo "   ✅ Se encontraron {$count} transacciones\n\n";
                
                // Mostrar últimas 3 transacciones como referencia
                if ($count > 0) {
                    echo "   Últimas transacciones:\n";
                    $recent = array_slice($transactions['data']['data'], 0, 3);
                    foreach ($recent as $index => $tx) {
                        echo "   " . ($index + 1) . ". $" . ($tx['amount'] ?? 'N/A') . 
                             " - " . ($tx['concept'] ?? 'N/A') . 
                             " (" . ($tx['date'] ?? 'N/A') . ")\n";
                    }
                    echo "\n";
                }
            } else {
                echo "   ⚠ No se encontraron transacciones: " . $transactions['message'] . "\n\n";
            }
            
            // 3. Probar tokenización de tarjeta
            echo "3. Probando tokenización de tarjeta...\n";
            $tokenizeData = [
                'card_number' => $testCard,
                'expiry_date' => $expiryDate, // Formato YYMM
                'amount' => '2.50' // Monto de prueba ≤ $3
            ];
            
            echo "   Datos de tarjeta:\n";
            echo "   Número: " . substr($testCard, 0, 6) . "****" . substr($testCard, -4) . "\n";
            echo "   Expiración: " . $expiryDate . " (YYMM)\n";
            echo "   Monto: $2.50 MXN\n\n";
            
            $tokenizeResult = $this->efevooPay->tokenizeCard($tokenizeData);
            
            if ($tokenizeResult['success']) {
                echo "   ✅ Tarjeta tokenizada exitosamente\n";
                echo "   Código: " . ($tokenizeResult['codigo'] ?? 'N/A') . "\n";
                echo "   Mensaje: " . $tokenizeResult['message'] . "\n";
                
                // Guardar token de tarjeta si viene en la respuesta
                $cardToken = null;
                if (isset($tokenizeResult['data']['token'])) {
                    $cardToken = $tokenizeResult['data']['token'];
                    echo "   Token de tarjeta: " . substr($cardToken, 0, 20) . "...\n";
                }
                echo "\n";
                
                // 4. Probar pago directo
                echo "4. Probando procesamiento de pago directo...\n";
                
                // Crear un CAV único (alphanumeric, 8-20 caracteres)
                $cav = 'TEST' . substr(strtoupper(uniqid()), 0, 8);
                
                $paymentData = [
                    'track2' => $cardToken ? $cardToken : ($testCard . '=' . $expiryDate),
                    'amount' => '2.75', // $2.75 MXN
                    'cvv' => $cardToken ? '' : $testCVV, // Si usas token, CVV va vacío
                    'cav' => $cav,
                    'msi' => 0, // Sin meses sin intereses
                    'contrato' => '', // No es pago recurrente
                    'fiid_comercio' => '', // Dejar vacío si no tienes
                    'referencia' => 'FAMEDIC-TEST-' . time()
                ];
                
                echo "   Datos del pago:\n";
                echo "   Monto: $2.75 MXN\n";
                echo "   CAV: " . $cav . "\n";
                echo "   Referencia: " . $paymentData['referencia'] . "\n";
                echo "   MSI: " . ($paymentData['msi'] == 0 ? 'No' : $paymentData['msi'] . ' meses') . "\n\n";
                
                $paymentResult = $this->efevooPay->processPayment($paymentData);
                
                if ($paymentResult['success']) {
                    echo "   ✅ Pago procesado exitosamente\n";
                    echo "   Código: " . ($paymentResult['codigo'] ?? 'N/A') . "\n";
                    echo "   Mensaje: " . $paymentResult['message'] . "\n";
                    
                    // Mostrar detalles de la respuesta
                    if (isset($paymentResult['data']) && is_array($paymentResult['data'])) {
                        echo "   Detalles de la transacción:\n";
                        foreach ($paymentResult['data'] as $key => $value) {
                            if (!is_array($value) && !is_object($value)) {
                                echo "   - " . $key . ": " . $value . "\n";
                            }
                        }
                    }
                    echo "\n";
                    
                    // Guardar ID de transacción para posible reembolso
                    $transactionId = null;
                    if (isset($paymentResult['data']['id'])) {
                        $transactionId = $paymentResult['data']['id'];
                    } elseif (isset($paymentResult['data']['ID'])) {
                        $transactionId = $paymentResult['data']['ID'];
                    } elseif (isset($paymentResult['data']['idOperacion'])) {
                        $transactionId = $paymentResult['data']['idOperacion'];
                    }
                    
                    // 5. Probar reembolso (opcional - solo si la transacción lo permite)
                    if ($transactionId) {
                        echo "5. ¿Desea probar reembolso de la transacción #{$transactionId}? (s/n): ";
                        $handle = fopen("php://stdin", "r");
                        $line = fgets($handle);
                        fclose($handle);
                        
                        if (trim(strtolower($line)) == 's') {
                            echo "   Probando reembolso...\n";
                            $refundResult = $this->efevooPay->refundTransaction($transactionId);
                            
                            if ($refundResult['success']) {
                                echo "   ✅ Reembolso procesado exitosamente\n";
                                echo "   Mensaje: " . $refundResult['message'] . "\n";
                            } else {
                                echo "   ⚠ No se pudo procesar reembolso: " . $refundResult['message'] . "\n";
                                echo "   Código: " . ($refundResult['codigo'] ?? 'N/A') . "\n";
                            }
                            echo "\n";
                        }
                    }
                    
                } else {
                    echo "   ⚠ No se pudo procesar pago\n";
                    echo "   Código: " . ($paymentResult['codigo'] ?? 'N/A') . "\n";
                    echo "   Mensaje: " . $paymentResult['message'] . "\n";
                    
                    // Mostrar detalles del error si existen
                    if (isset($paymentResult['data']) && is_array($paymentResult['data'])) {
                        echo "   Detalles del error:\n";
                        foreach ($paymentResult['data'] as $key => $value) {
                            if (!is_array($value) && !is_object($value)) {
                                echo "   - " . $key . ": " . $value . "\n";
                            }
                        }
                    }
                    echo "\n";
                }
                
                // 6. Probar pago con 3DS (opcional)
                echo "6. ¿Desea probar pago con 3D Secure? (s/n): ";
                $handle = fopen("php://stdin", "r");
                $line = fgets($handle);
                fclose($handle);
                
                if (trim(strtolower($line)) == 's') {
                    echo "   Probando pago 3D Secure...\n";
                    
                    $threeDSData = [
                        'card_number' => $testCard,
                        'cvv' => $testCVV,
                        'expiry_date' => $expiryDateFormatted, // Formato MM/YY
                        'amount' => '1.50', // $1.50 MXN
                        'fiid_comercio' => '', // Dejar vacío si no tienes
                        'msi' => 0
                    ];
                    
                    echo "   Monto: $1.50 MXN\n";
                    echo "   Tarjeta: " . substr($testCard, 0, 6) . "****" . substr($testCard, -4) . "\n\n";
                    
                    $threeDSResult = $this->efevooPay->get3DSPaymentLink($threeDSData);
                    
                    if ($threeDSResult['success']) {
                        echo "   ✅ Link 3DS generado exitosamente\n";
                        echo "   Mensaje: " . $threeDSResult['message'] . "\n";
                        
                        if (isset($threeDSResult['data']['url_3dsecure'])) {
                            echo "   URL 3DS: " . $threeDSResult['data']['url_3dsecure'] . "\n";
                        }
                        if (isset($threeDSResult['data']['token_3dsecure'])) {
                            echo "   Token 3DS: " . substr($threeDSResult['data']['token_3dsecure'], 0, 20) . "...\n";
                        }
                        if (isset($threeDSResult['data']['order_id'])) {
                            echo "   Order ID: " . $threeDSResult['data']['order_id'] . "\n";
                            
                            // Preguntar si quiere verificar estado
                            echo "\n   ¿Desea verificar estado del pago 3DS? (s/n): ";
                            $handle = fopen("php://stdin", "r");
                            $line = fgets($handle);
                            fclose($handle);
                            
                            if (trim(strtolower($line)) == 's') {
                                $statusData = [
                                    'card_number' => $testCard,
                                    'cvv' => $testCVV,
                                    'expiry_date' => $expiryDateFormatted
                                ];
                                
                                $statusResult = $this->efevooPay->get3DSPaymentStatus(
                                    $threeDSResult['data']['order_id'],
                                    $statusData
                                );
                                
                                if ($statusResult['success']) {
                                    echo "   ✅ Estado obtenido exitosamente\n";
                                    echo "   Mensaje: " . $statusResult['message'] . "\n";
                                } else {
                                    echo "   ⚠ Error al obtener estado: " . $statusResult['message'] . "\n";
                                }
                            }
                        }
                    } else {
                        echo "   ⚠ No se pudo generar link 3DS\n";
                        echo "   Mensaje: " . $threeDSResult['message'] . "\n";
                        echo "   Código: " . ($threeDSResult['codigo'] ?? 'N/A') . "\n";
                    }
                    echo "\n";
                }
                
            } else {
                echo "   ⚠ No se pudo tokenizar tarjeta\n";
                echo "   Código: " . ($tokenizeResult['codigo'] ?? 'N/A') . "\n";
                echo "   Mensaje: " . $tokenizeResult['message'] . "\n";
                
                // Intentar pago directo sin tokenización
                echo "\n   Intentando pago directo sin tokenización...\n";
                
                $cav = 'DIRECT' . substr(strtoupper(uniqid()), 0, 8);
                $paymentData = [
                    'track2' => $testCard . '=' . $expiryDate,
                    'amount' => '2.00',
                    'cvv' => $testCVV,
                    'cav' => $cav,
                    'msi' => 0,
                    'referencia' => 'DIRECT-TEST-' . time()
                ];
                
                $paymentResult = $this->efevooPay->processPayment($paymentData);
                
                if ($paymentResult['success']) {
                    echo "   ✅ Pago directo procesado exitosamente\n";
                    echo "   Mensaje: " . $paymentResult['message'] . "\n";
                } else {
                    echo "   ❌ Pago directo también falló\n";
                    echo "   Mensaje: " . $paymentResult['message'] . "\n";
                }
            }
            
        } else {
            echo "   ❌ Error al obtener token\n";
            echo "   Código: " . ($tokenResult['codigo'] ?? 'N/A') . "\n";
            echo "   Mensaje: " . $tokenResult['message'] . "\n";
            echo "   Error: " . ($tokenResult['error'] ?? 'N/A') . "\n";
        }
        
        echo "\n========================================\n";
        echo "PRUEBAS COMPLETADAS\n";
        echo "========================================\n";
        
        // Mostrar resumen
        echo "\nRESUMEN:\n";
        echo "1. Tarjeta usada: " . substr($testCard, 0, 6) . "****" . substr($testCard, -4) . "\n";
        echo "2. Montos probados: ≤ $3.00 MXN\n";
        echo "3. Fecha actual: " . date('Y-m-d H:i:s') . "\n";
        echo "4. Ambiente: " . (config('efevoopay.environment') ?? 'test') . "\n";
    }
}

// Función para cargar configuración si no estamos en Laravel
function loadConfig()
{
    $configFile = __DIR__ . '/../config/efevoopay.php';
    
    if (file_exists($configFile)) {
        return include $configFile;
    }
    
    // Configuración por defecto para pruebas
    return [
        'environment' => 'test',
        'test' => [
            'api_url' => 'https://test-intgapi.efevoopay.com/v1/apiservice',
            'api_user' => 'Efevoo Pay',
            'api_key' => 'Hq#J0hs)jK+YqF6J',
            'totp_secret' => 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q',
            'clave' => '6nugHedWzw27MNB8',
            'cliente' => 'TestFAMEDIC',
            'vector' => 'MszjlcnTjGLNpNy3'
        ]
    ];
}

// Establecer configuración global
$config = loadConfig();

// Verificar si existe la función config (Laravel)
if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        global $config;
        
        if ($key === null) {
            return $config;
        }
        
        $keys = explode('.', $key);
        $value = $config;
        
        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }
        
        return $value;
    }
}

// Ejecutar pruebas
echo "Iniciando pruebas de integración EfevooPay...\n";
echo "Usando tarjeta: 54577427****2944\n";
echo "Máximo monto: $3.00 MXN\n\n";

$test = new EfevooPayTest();
$test->runAllTests();