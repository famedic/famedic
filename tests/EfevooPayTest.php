<?php
// tests/EfevooPayTest.php

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
        echo "PRUEBAS COMPLETAS EFEVOOPAY API\n";
        echo "========================================\n\n";
        
        // 1. Obtener token
        echo "1. Obteniendo token de cliente...\n";
        $tokenResult = $this->efevooPay->getClientToken();
        
        if ($tokenResult['success']) {
            echo "   ✅ Token obtenido exitosamente\n";
            echo "   Token: " . substr($tokenResult['data']['token'], 0, 30) . "...\n";
            echo "   Duración: " . $tokenResult['data']['duracion'] . "\n\n";
            
            // 2. Buscar transacciones (ya sabemos que funciona)
            echo "2. Buscando transacciones del mes actual...\n";
            $transactions = $this->efevooPay->searchTransactions([
                'range1' => date('Y-m-01 00:00:00'),
                'range2' => date('Y-m-d 23:59:59')
            ]);
            
            if ($transactions['success']) {
                $count = count($transactions['data']['data'] ?? []);
                echo "   ✅ Se encontraron {$count} transacciones\n\n";
                
                // 3. Probar tokenización de tarjeta (prueba con datos de test)
                echo "3. Probando tokenización de tarjeta...\n";
                $tokenizeData = [
                    'card_number' => '4111111111111111', // Tarjeta de prueba
                    'expiry_date' => '2512', // Diciembre 2025
                    'amount' => '1.00'
                ];
                
                $tokenizeResult = $this->efevooPay->tokenizeCard($tokenizeData);
                
                if ($tokenizeResult['success']) {
                    echo "   ✅ Tarjeta tokenizada exitosamente\n";
                    echo "   Respuesta: " . json_encode($tokenizeResult['data'], JSON_PRETTY_PRINT) . "\n\n";
                    
                    // 4. Probar pago (si la tokenización fue exitosa)
                    echo "4. Probando procesamiento de pago...\n";
                    $paymentData = [
                        'track2' => '4111111111111111=2512', // Usar token si se obtuvo
                        'amount' => '100.00',
                        'cvv' => '123',
                        'cav' => 'TEST' . time(),
                        'msi' => 0,
                        'referencia' => 'TEST-' . time()
                    ];
                    
                    $paymentResult = $this->efevooPay->processPayment($paymentData);
                    
                    if ($paymentResult['success']) {
                        echo "   ✅ Pago procesado exitosamente\n";
                        echo "   ID Transacción: " . ($paymentResult['data']['id'] ?? 'N/A') . "\n";
                        echo "   Referencia: " . ($paymentResult['data']['reference'] ?? 'N/A') . "\n";
                        echo "   Estado: " . ($paymentResult['data']['status'] ?? 'N/A') . "\n\n";
                        
                        // 5. Probar reembolso (si el pago fue exitoso)
                        if (isset($paymentResult['data']['id'])) {
                            echo "5. Probando reembolso...\n";
                            $refundResult = $this->efevooPay->refundTransaction($paymentResult['data']['id']);
                            
                            if ($refundResult['success']) {
                                echo "   ✅ Reembolso procesado exitosamente\n";
                            } else {
                                echo "   ⚠ No se pudo procesar reembolso: " . $refundResult['message'] . "\n";
                            }
                        }
                        
                    } else {
                        echo "   ⚠ No se pudo procesar pago: " . $paymentResult['message'] . "\n";
                    }
                    
                } else {
                    echo "   ⚠ No se pudo tokenizar tarjeta: " . $tokenizeResult['message'] . "\n";
                }
                
            } else {
                echo "   ⚠ Error al buscar transacciones: " . $transactions['message'] . "\n";
            }
            
        } else {
            echo "   ❌ Error al obtener token: " . $tokenResult['message'] . "\n";
        }
        
        echo "\n========================================\n";
        echo "PRUEBAS COMPLETADAS\n";
        echo "========================================\n";
    }
}

// Ejecutar pruebas
$test = new EfevooPayTest();
$test->runAllTests();