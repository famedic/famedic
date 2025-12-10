<?php

namespace App\Services;

use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;

class ConstanciaFiscalService
{
    protected $parser;
    
    public function __construct()
    {
        $this->parser = new Parser();
    }
    
    public function procesarConstancia(UploadedFile $archivo)
    {
        try {
            Log::info('=== PROCESANDO CONSTANCIA FISCAL ===');
            Log::info('Archivo:', [
                'nombre' => $archivo->getClientOriginalName(),
                'tamaño' => $archivo->getSize(),
                'mime' => $archivo->getMimeType()
            ]);
            
            // 1. Parsear el PDF
            $pdf = $this->parser->parseContent($archivo->get());
            $texto = $pdf->getText();
            
            Log::info('Texto extraído del PDF (primeros 500 chars):', [
                'texto' => substr($texto, 0, 500)
            ]);
            
            // 2. Extraer información
            $datos = $this->extraerDatos($texto);
            
            // 3. Validar datos mínimos
            if (empty($datos['rfc'])) {
                throw new \Exception('No se pudo extraer el RFC del documento');
            }
            
            Log::info('Datos extraídos exitosamente:', $datos);
            
            return [
                'success' => true,
                'data' => $datos
            ];
            
        } catch (\Exception $e) {
            Log::error('Error procesando constancia fiscal: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => 'Error al procesar el PDF: ' . $e->getMessage()
            ];
        }
    }
    
    protected function extraerDatos(string $texto): array
    {
        $datos = [
            'rfc' => null,
            'nombre' => null,
            'razon_social' => null,
            'codigo_postal' => null,
            'regimen_fiscal' => null,
            'tipo_persona' => null,
            'fecha_emision' => null,
            'estatus_sat' => null,
            'tipo_persona_confianza' => null,
            'texto_completo' => $texto, // Para debug
        ];
        
        // Normalizar texto: quitar espacios múltiples y saltos de línea
        $texto = preg_replace('/\s+/', ' ', $texto);
        $texto = mb_strtoupper($texto, 'UTF-8');
        
        // 1. Extraer RFC (patrones comunes en constancias fiscales)
        $rfc = $this->extraerRFC($texto);
        if ($rfc) {
            $datos['rfc'] = $rfc['rfc'];
            $datos['tipo_persona_confianza'] = $rfc['confianza'];
        }
        
        // 2. Determinar tipo de persona basado en RFC
        if ($datos['rfc']) {
            $datos['tipo_persona'] = $this->determinarTipoPersona($datos['rfc']);
        }
        
        // 3. Extraer nombre/razón social
        $datos['nombre'] = $this->extraerNombre($texto, $datos['rfc']);
        $datos['razon_social'] = $datos['nombre']; // Por defecto
        
        // 4. Extraer código postal
        $datos['codigo_postal'] = $this->extraerCodigoPostal($texto);
        
        // 5. Extraer régimen fiscal
        $datos['regimen_fiscal'] = $this->extraerRegimenFiscal($texto);
        
        // 6. Extraer fecha de emisión
        $datos['fecha_emision'] = $this->extraerFechaEmision($texto);
        
        // 7. Determinar estatus SAT
        $datos['estatus_sat'] = $this->determinarEstatusSAT($texto);
        
        return $datos;
    }
    
    protected function extraerRFC(string $texto): ?array
    {
        // Patrones para RFC en constancias fiscales
        $patrones = [
            // Patrón: RFC: XAXX010101000
            '/RFC[:\-\s]+([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/i' => 95,
            
            // Patrón: RFC XAXX010101000
            '/RFC\s+([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/i' => 90,
            
            // Patrón: Clave en el Registro Federal de Contribuyentes: XAXX010101000
            '/CLAVE.*REGISTRO.*FEDERAL.*CONTRIBUYENTES[:\-\s]+([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/i' => 98,
            
            // Patrón: R.F.C. XAXX010101000
            '/R\.?F\.?C\.?[:\-\s]+([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/i' => 85,
            
            // Buscar directamente el patrón de RFC
            '/([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/' => 70,
        ];
        
        foreach ($patrones as $patron => $confianza) {
            if (preg_match($patron, $texto, $matches)) {
                $rfc = strtoupper(trim($matches[1]));
                
                // Validar formato básico de RFC
                if (preg_match('/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/', $rfc)) {
                    Log::info("RFC encontrado con patrón {$patron}: {$rfc}");
                    return [
                        'rfc' => $rfc,
                        'confianza' => $confianza
                    ];
                }
            }
        }
        
        return null;
    }
    
    protected function determinarTipoPersona(string $rfc): string
    {
        // Personas Morales: 3 letras + 6 números + 3 alfanuméricos
        // Personas Físicas: 4 letras + 6 números + 3 alfanuméricos
        
        $primeraParte = substr($rfc, 0, 4);
        
        // Si la primera parte tiene 4 letras y termina con números, es física
        if (preg_match('/^[A-Z&Ñ]{4}\d{2}/', $rfc)) {
            return 'fisica';
        }
        
        // Si tiene 3 letras al inicio, es moral
        if (preg_match('/^[A-Z&Ñ]{3}\d{6}/', $rfc)) {
            return 'moral';
        }
        
        // Por defecto asumir física
        return 'fisica';
    }
    
    protected function extraerNombre(string $texto, ?string $rfc = null): ?string
    {
        $patrones = [
            // Patrón: Nombre(s): JUAN PEREZ
            '/NOMBRE[\(\)\w\s]*:[-\s]*([A-ZÁÉÍÓÚÑ\s\.]+(?:[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s\.]*)?)(?=\s*(?:RFC|R\.F\.C|DOMICILIO|REGIMEN|$))/i',
            
            // Patrón: Razón Social: EMPRESA SA DE CV
            '/RAZ[ÓO]N\s+SOCIAL[:\s]+([A-ZÁÉÍÓÚÑ\s\.\&]+(?:[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s\.\&]*)?)(?=\s*(?:RFC|R\.F\.C|DOMICILIO|REGIMEN|$))/i',
            
            // Patrón: Denominación o Razón Social: EMPRESA SA DE CV
            '/DENOMINACI[ÓO]N.*RAZ[ÓO]N.*SOCIAL[:\s]+([A-ZÁÉÍÓÚÑ\s\.\&]+(?:[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s\.\&]*)?)/i',
            
            // Buscar texto entre "RFC" y "DOMICILIO"
            '/RFC[^A-Z]*([A-ZÁÉÍÓÚÑ\s\.\&]+(?:[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s\.\&]*)?)\s+DOMICILIO/i',
        ];
        
        foreach ($patrones as $patron) {
            if (preg_match($patron, $texto, $matches)) {
                $nombre = trim($matches[1]);
                
                // Limpiar el nombre
                $nombre = preg_replace('/\s+/', ' ', $nombre);
                $nombre = trim($nombre, " \t\n\r\0\x0B:.-");
                
                if (!empty($nombre) && strlen($nombre) > 3) {
                    Log::info("Nombre encontrado: {$nombre}");
                    return $nombre;
                }
            }
        }
        
        // Si tenemos RFC pero no nombre, buscar alrededor del RFC
        if ($rfc) {
            $posRFC = stripos($texto, $rfc);
            if ($posRFC !== false) {
                // Buscar texto antes del RFC (máximo 100 caracteres)
                $inicio = max(0, $posRFC - 100);
                $textoAntes = substr($texto, $inicio, 100);
                
                // Buscar mayúsculas consecutivas antes del RFC
                if (preg_match('/([A-ZÁÉÍÓÚÑ\s\.\&]{5,})(?=\s*' . preg_quote($rfc) . ')/', $textoAntes . $rfc, $matches)) {
                    $nombre = trim($matches[1]);
                    if (!empty($nombre)) {
                        return $nombre;
                    }
                }
            }
        }
        
        return null;
    }
    
    protected function extraerCodigoPostal(string $texto): ?string
    {
        $patrones = [
            // Código Postal: 64000
            '/C[ÓO]DIGO\s+POSTAL[:\s]*(\d{5})/i',
            
            // C.P. 64000
            '/C\.?P\.?[:\s]*(\d{5})/i',
            
            // CP 64000
            '/CP[:\s]*(\d{5})/i',
            
            // Buscar 5 dígitos consecutivos después de "postal"
            '/POSTAL[^\d]*(\d{5})/i',
            
            // Buscar cualquier código postal de 5 dígitos
            '/(?<!\d)\d{5}(?!\d)/',
        ];
        
        foreach ($patrones as $patron) {
            if (preg_match($patron, $texto, $matches)) {
                $cp = trim($matches[1]);
                if (!empty($cp) && is_numeric($cp)) {
                    Log::info("Código postal encontrado: {$cp}");
                    return $cp;
                }
            }
        }
        
        return null;
    }
    
    protected function extraerRegimenFiscal(string $texto): ?string
    {
        // Lista de regímenes fiscales comunes en México
        $regimenes = [
            'RÉGIMEN DE INCORPORACIÓN FISCAL' => 'Régimen de Incorporación Fiscal',
            'RÉGIMEN DE SUELDOS Y SALARIOS' => 'Régimen de Sueldos y Salarios',
            'RÉGIMEN DE ACTIVIDADES EMPRESARIALES' => 'Régimen de Actividades Empresariales',
            'RÉGIMEN DE ARRENDAMIENTO' => 'Régimen de Arrendamiento',
            'RÉGIMEN DE PERSONAS MORALES' => 'Régimen de Personas Morales',
            'RÉGIMEN DE ENAJENACIÓN DE BIENES' => 'Régimen de Enajenación de Bienes',
            'RÉGIMEN DE RESIDENTES EN EL EXTRANJERO' => 'Régimen de Residentes en el Extranjero',
            'RÉGIMEN DE INTERESES' => 'Régimen de Intereses',
            'RÉGIMEN DE DIVIDENDOS' => 'Régimen de Dividendos',
            'RÉGIMEN GENERAL DE LEY' => 'Régimen General de Ley',
        ];
        
        $patrones = [
            // Patrón explícito: Régimen: Régimen de Incorporación Fiscal
            '/REGIMEN[:\s]+([A-ZÁÉÍÓÚÑ\s\.]+(?:[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s\.]*)?)(?=\s*(?:RFC|DOMICILIO|FECHA|$))/i',
            
            // Buscar después de "Régimen" o "Regimen"
            '/REG[IÍ]MEN[^A-Z]*([A-ZÁÉÍÓÚÑ\s\.]{10,})/i',
        ];
        
        // Primero buscar con patrones específicos
        foreach ($patrones as $patron) {
            if (preg_match($patron, $texto, $matches)) {
                $regimenEncontrado = trim($matches[1]);
                
                // Comparar con la lista de regímenes conocidos
                foreach ($regimenes as $key => $regimen) {
                    if (stripos($regimenEncontrado, $key) !== false) {
                        Log::info("Régimen fiscal encontrado: {$regimen}");
                        return $regimen;
                    }
                }
                
                // Si no coincide exactamente, devolver lo encontrado
                if (!empty($regimenEncontrado)) {
                    Log::info("Régimen fiscal encontrado (no estándar): {$regimenEncontrado}");
                    return $regimenEncontrado;
                }
            }
        }
        
        // Buscar directamente los regímenes en el texto
        foreach ($regimenes as $key => $regimen) {
            if (stripos($texto, $key) !== false) {
                Log::info("Régimen fiscal encontrado (búsqueda directa): {$regimen}");
                return $regimen;
            }
        }
        
        return null;
    }
    
    protected function extraerFechaEmision(string $texto): ?string
    {
        $patrones = [
            // Fecha: 15/01/2024
            '/FECHA[:\s]*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i',
            
            // Fecha de Emisión: 15/01/2024
            '/FECHA.*EMISI[ÓO]N[:\s]*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i',
            
            // Formato: 2024-01-15
            '/(\d{4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,2})/',
            
            // Formato: 15-Ene-2024
            '/FECHA[:\s]*(\d{1,2}[\/\-\. ][A-Z]{3,}[\/\-\. ]\d{2,4})/i',
        ];
        
        foreach ($patrones as $patron) {
            if (preg_match($patron, $texto, $matches)) {
                $fecha = trim($matches[1]);
                
                try {
                    // Intentar parsear la fecha
                    $fechaObj = \Carbon\Carbon::createFromFormat('d/m/Y', $fecha) ?: 
                               \Carbon\Carbon::createFromFormat('Y-m-d', $fecha) ?:
                               \Carbon\Carbon::createFromFormat('d-m-Y', $fecha);
                    
                    if ($fechaObj) {
                        $fechaFormateada = $fechaObj->format('Y-m-d');
                        Log::info("Fecha de emisión encontrada: {$fechaFormateada}");
                        return $fechaFormateada;
                    }
                } catch (\Exception $e) {
                    // Continuar con el siguiente patrón
                    continue;
                }
            }
        }
        
        return null;
    }
    
    protected function determinarEstatusSAT(string $texto): string
    {
        // Buscar indicadores de estatus
        if (stripos($texto, 'VIGENTE') !== false || 
            stripos($texto, 'ACTIVO') !== false ||
            stripos($texto, 'ESTATUS: VIGENTE') !== false) {
            return 'Vigente';
        }
        
        if (stripos($texto, 'CANCELADO') !== false || 
            stripos($texto, 'SUSPENDIDO') !== false ||
            stripos($texto, 'BAJA') !== false) {
            return 'No Vigente';
        }
        
        // Por defecto, asumir vigente
        return 'Vigente';
    }
}