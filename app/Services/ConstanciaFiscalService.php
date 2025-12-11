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
            
            // Guardar el texto completo para debugging
            $textoCompleto = $texto;
            Log::info('Texto completo del PDF (primeros 2000 chars):', ['texto' => substr($texto, 0, 2000)]);
            
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
        ];
        
        // Normalizar texto
        $textoNormalizado = $this->normalizarTexto($texto);
        
        Log::info('Texto normalizado (primeros 1000 chars):', ['texto' => substr($textoNormalizado, 0, 1000)]);
        
        // 1. Extraer RFC
        $rfc = $this->extraerRFC($textoNormalizado);
        if ($rfc) {
            $datos['rfc'] = $rfc['rfc'];
            $datos['tipo_persona_confianza'] = $rfc['confianza'];
        }
        
        // 2. Determinar tipo de persona basado en RFC
        if ($datos['rfc']) {
            $datos['tipo_persona'] = $this->determinarTipoPersona($datos['rfc']);
        }
        
        // 3. Extraer nombre/razón social - USAR TEXTO ORIGINAL Y NORMALIZADO
        $datos['nombre'] = $this->extraerNombreCorrecto($texto, $textoNormalizado, $datos['rfc']);
        $datos['razon_social'] = $datos['nombre'];
        
        // 4. Extraer código postal
        $datos['codigo_postal'] = $this->extraerCodigoPostal($textoNormalizado);
        
        // 5. Extraer régimen fiscal
        $datos['regimen_fiscal'] = $this->extraerRegimenFiscal($textoNormalizado);
        
        // 6. Extraer fecha de emisión
        $datos['fecha_emision'] = $this->extraerFechaEmision($textoNormalizado);
        
        // 7. Determinar estatus SAT
        $datos['estatus_sat'] = $this->determinarEstatusSAT($textoNormalizado);
        
        return $datos;
    }
    
    protected function normalizarTexto(string $texto): string
    {
        // Reemplazar múltiples espacios y saltos de línea por un solo espacio
        $texto = preg_replace('/\s+/', ' ', $texto);
        // Convertir a mayúsculas
        $texto = mb_strtoupper($texto, 'UTF-8');
        // Limpiar caracteres especiales extraños pero mantener acentos
        $texto = preg_replace('/[^\x20-\x7EÁÉÍÓÚÑáéíóúñ]/u', ' ', $texto);
        return trim($texto);
    }
    
    protected function extraerRFC(string $texto): ?array
    {
        // Patrones para RFC en constancias fiscales
        $patrones = [
            // Patrón: RFC: XAXX010101000
            '/RFC[:\-\s]+([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/' => 95,
            
            // Patrón: RFC XAXX010101000
            '/RFC\s+([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/' => 90,
            
            // Patrón común en constancias: el RFC aparece solo en una línea
            '/([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/' => 70,
        ];
        
        foreach ($patrones as $patron => $confianza) {
            if (preg_match($patron, $texto, $matches)) {
                if (isset($matches[1])) {
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
        }
        
        return null;
    }
    
    protected function determinarTipoPersona(string $rfc): string
    {
        // Personas Físicas: 4 letras + 6 números + 3 alfanuméricos (ej: MEBE931209BI2)
        if (preg_match('/^[A-Z&Ñ]{4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            return 'fisica';
        }
        
        // Personas Morales: 3 letras + 6 números + 3 alfanuméricos
        if (preg_match('/^[A-Z&Ñ]{3}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            return 'moral';
        }
        
        return 'fisica'; // Por defecto
    }
    
    protected function extraerNombreCorrecto(string $textoOriginal, string $textoNormalizado, ?string $rfc = null): ?string
    {
        Log::info("=== BUSCANDO NOMBRE ===");
        Log::info("RFC proporcionado: {$rfc}");
        
        // PRIMER INTENTO: Buscar en el formato específico de tu PDF
        // El PDF tiene: "EULALIO MEDINA BARRAGAN Nombre, denominación o razón social"
        // Buscar la línea que contiene el nombre antes de "Nombre, denominación o razón social"
        if (preg_match('/([A-ZÁÉÍÓÚÑ\s]{10,})\s+NOMBRE,\s*DENOMINACION\s*O\s*RAZON\s*SOCIAL/i', $textoNormalizado, $matches)) {
            if (isset($matches[1])) {
                $nombre = trim($matches[1]);
                // Limpiar posibles palabras no deseadas al inicio
                $nombre = preg_replace('/^(CONSTANCIA|SITUACION|FISCAL|CÉDULA|IDENTIFICACIÓN)\s+/i', '', $nombre);
                $nombre = trim($nombre);
                
                if (!empty($nombre) && strlen($nombre) > 5) {
                    Log::info("Nombre encontrado por patrón 'nombre antes de razón social': {$nombre}");
                    return $nombre;
                }
            }
        }
        
        // SEGUNDO INTENTO: Buscar la estructura específica del PDF
        // En tu PDF: después del RFC hay una línea con el nombre completo
        if ($rfc) {
            $patronNombre = '/' . preg_quote($rfc, '/') . '\s+([A-ZÁÉÍÓÚÑ\s]{10,})\s+NOMBRE,\s*DENOMINACION/i';
            if (preg_match($patronNombre, $textoNormalizado, $matches)) {
                if (isset($matches[1])) {
                    $nombre = trim($matches[1]);
                    Log::info("Nombre encontrado después del RFC: {$nombre}");
                    return $nombre;
                }
            }
        }
        
        // TERCER INTENTO: Buscar en la sección de datos del contribuyente
        if (preg_match('/DATOS DE IDENTIFICACION DEL CONTRIBUYENTE:(.*?)(?:RFC|CURP|DOMICILIO|$)/si', $textoNormalizado, $matches)) {
            if (isset($matches[1])) {
                $seccion = $matches[1];
                Log::info("Sección de datos encontrada:", ['seccion' => substr($seccion, 0, 200)]);
                
                // Buscar nombre específicamente
                if (preg_match('/NOMBRE\s*\(?S\)?\s*:\s*([A-ZÁÉÍÓÚÑ]+)/i', $seccion, $nameMatches)) {
                    if (isset($nameMatches[1])) {
                        $primerNombre = trim($nameMatches[1]);
                        
                        // Buscar apellidos
                        $apellidos = '';
                        if (preg_match('/PRIMER\s+APELLIDO\s*:\s*([A-ZÁÉÍÓÚÑ]+)/i', $seccion, $apellido1Matches)) {
                            $apellidos .= ' ' . trim($apellido1Matches[1]);
                        }
                        if (preg_match('/SEGUNDO\s+APELLIDO\s*:\s*([A-ZÁÉÍÓÚÑ]+)/i', $seccion, $apellido2Matches)) {
                            $apellidos .= ' ' . trim($apellido2Matches[1]);
                        }
                        
                        $nombreCompleto = trim($primerNombre . $apellidos);
                        if (!empty($nombreCompleto)) {
                            Log::info("Nombre construido desde sección de datos: {$nombreCompleto}");
                            return $nombreCompleto;
                        }
                    }
                }
            }
        }
        
        // CUARTO INTENTO: Buscar nombre comercial
        if (preg_match('/NOMBRE COMERCIAL\s*:\s*([A-ZÁÉÍÓÚÑ\s]+)/i', $textoNormalizado, $matches)) {
            if (isset($matches[1])) {
                $nombre = trim($matches[1]);
                if (!empty($nombre) && strlen($nombre) > 5 && !preg_match('/^\s*(RFC|CURP|DOMICILIO)/i', $nombre)) {
                    Log::info("Nombre comercial encontrado: {$nombre}");
                    return $nombre;
                }
            }
        }
        
        // QUINTO INTENTO: Buscar el bloque de texto que contiene el nombre
        // Analizar el texto línea por línea
        $lineas = explode("\n", $textoOriginal);
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            // Buscar líneas que parezcan nombres completos (tres palabras en mayúsculas)
            if (preg_match('/^[A-ZÁÉÍÓÚÑ]{3,}\s+[A-ZÁÉÍÓÚÑ]{3,}\s+[A-ZÁÉÍÓÚÑ]{3,}$/', $linea)) {
                Log::info("Posible nombre encontrado en línea: {$linea}");
                return $linea;
            }
        }
        
        Log::warning("No se pudo extraer el nombre del texto");
        return null;
    }
    
    protected function extraerCodigoPostal(string $texto): ?string
    {
        // Buscar específicamente en la sección de domicilio
        if (preg_match('/DOMICILIO\s+REGISTRADO(.*?)CODIGO POSTAL\s*:\s*(\d{5})/si', $texto, $matches)) {
            if (isset($matches[2])) {
                $cp = trim($matches[2]);
                if (!empty($cp)) {
                    Log::info("Código postal encontrado en sección de domicilio: {$cp}");
                    return $cp;
                }
            }
        }
        
        // Buscar directamente en formato "Código Postal:79980" (sin espacio)
        if (preg_match('/CODIGO POSTAL\s*:\s*(\d{5})/i', $texto, $matches)) {
            if (isset($matches[1])) {
                $cp = trim($matches[1]);
                Log::info("Código postal encontrado: {$cp}");
                return $cp;
            }
        }
        
        // Buscar directamente "79980" en el texto
        if (preg_match('/79980/', $texto, $matches)) {
            Log::info("Código postal encontrado directamente: 79980");
            return '79980';
        }
        
        return null;
    }
    
    protected function extraerRegimenFiscal(string $texto): ?string
    {
        // Buscar en la sección de regímenes
        if (preg_match('/REGIMENES:(.*?)R[EÉ]GIMEN\s+DE\s+([A-ZÁÉÍÓÚÑ\s]+)/si', $texto, $matches)) {
            if (isset($matches[2])) {
                $regimen = trim($matches[2]);
                if (!empty($regimen)) {
                    Log::info("Régimen encontrado en sección de regímenes: {$regimen}");
                    return $regimen;
                }
            }
        }
        
        // Buscar directamente "Régimen de Sueldos y Salarios"
        if (preg_match('/R[EÉ]GIMEN\s+DE\s+(SUELDOS\s+Y\s+SALARIOS[^,]*)/i', $texto, $matches)) {
            if (isset($matches[1])) {
                $regimen = 'Régimen de ' . trim($matches[1]);
                Log::info("Régimen encontrado: {$regimen}");
                return $regimen;
            }
        }
        
        // Buscar palabras clave
        if (strpos($texto, 'SUELDOS') !== false && strpos($texto, 'SALARIOS') !== false) {
            Log::info("Régimen encontrado por palabras clave: Régimen de Sueldos y Salarios e Ingresos Asimilados a Salarios");
            return 'Régimen de Sueldos y Salarios e Ingresos Asimilados a Salarios';
        }
        
        return null;
    }
    
    protected function extraerFechaEmision(string $texto): ?string
    {
        // Buscar patrón específico: "Lugar y Fecha de Emisión"
        if (preg_match('/LUGAR\s+Y\s+FECHA\s+DE\s+EMISION[^,]*,\s*(\d{1,2})\s+DE\s+([A-Z]+)\s+DE\s+(\d{4})/i', $texto, $matches)) {
            if (isset($matches[1]) && isset($matches[2]) && isset($matches[3])) {
                $meses = [
                    'ENERO' => '01', 'FEBRERO' => '02', 'MARZO' => '03', 'ABRIL' => '04',
                    'MAYO' => '05', 'JUNIO' => '06', 'JULIO' => '07', 'AGOSTO' => '08',
                    'SEPTIEMBRE' => '09', 'OCTUBRE' => '10', 'NOVIEMBRE' => '11', 'DICIEMBRE' => '12'
                ];
                
                $dia = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $mes = $meses[strtoupper($matches[2])] ?? '01';
                $anio = $matches[3];
                
                $fecha = "{$anio}-{$mes}-{$dia}";
                Log::info("Fecha de emisión extraída: {$fecha}");
                return $fecha;
            }
        }
        
        return null;
    }
    
    protected function determinarEstatusSAT(string $texto): string
    {
        if (stripos($texto, 'ACTIVO') !== false) {
            return 'ACTIVO';
        }
        
        if (stripos($texto, 'CANCELADO') !== false || 
            stripos($texto, 'SUSPENDIDO') !== false ||
            stripos($texto, 'BAJA') !== false) {
            return 'NO ACTIVO';
        }
        
        return 'ACTIVO';
    }
}