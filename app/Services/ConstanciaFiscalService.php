<?php

namespace App\Services;

use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use Throwable;

class ConstanciaFiscalService
{
    public const MIN_TEXT_LENGTH = 80;

    protected $parser;

    public function __construct()
    {
        $this->parser = new Parser;
    }

    /**
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function procesarConstancia(UploadedFile $archivo): array
    {
        $startedAt = microtime(true);

        try {
            Log::info('Procesando constancia fiscal', [
                'operation' => 'constancia_extract',
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()?->customer?->id,
                'mime_type' => $archivo->getMimeType(),
                'size_bytes' => $archivo->getSize(),
            ]);

            $texto = $this->extractText($archivo);
            $datos = $this->extractDeterministicData($texto);

            if (empty($datos['rfc'])) {
                Log::warning('Extracción de constancia sin RFC detectable', [
                    'operation' => 'constancia_extract',
                    'user_id' => auth()->id(),
                    'customer_id' => auth()->user()?->customer?->id,
                    'result' => 'rfc_missing',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);

                return [
                    'success' => false,
                    'error' => 'No pudimos extraer los datos de la constancia. Puedes capturarlos manualmente.',
                ];
            }

            Log::info('Extracción de constancia completada', [
                'operation' => 'constancia_extract',
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()?->customer?->id,
                'result' => 'success',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'fields_present' => array_keys(array_filter(
                    $datos,
                    fn ($value) => $value !== null && $value !== ''
                )),
            ]);

            return [
                'success' => true,
                'data' => $datos,
            ];
        } catch (ConstanciaExtractionException $e) {
            Log::warning('Extracción de constancia rechazada', [
                'operation' => 'constancia_extract',
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()?->customer?->id,
                'result' => $e->errorCode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return [
                'success' => false,
                'error' => $e->publicMessage(),
            ];
        } catch (Throwable $e) {
            Log::error('Error procesando constancia fiscal', [
                'operation' => 'constancia_extract',
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()?->customer?->id,
                'result' => 'exception',
                'exception_class' => $e::class,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return [
                'success' => false,
                'error' => 'No pudimos extraer los datos de la constancia. Puedes capturarlos manualmente.',
            ];
        }
    }

    public function extractText(UploadedFile $archivo): string
    {
        try {
            $pdf = $this->parser->parseContent($archivo->get());
            $texto = (string) $pdf->getText();
        } catch (Throwable $e) {
            if ($this->looksProtected($e)) {
                throw ConstanciaExtractionException::protectedDocument();
            }

            throw ConstanciaExtractionException::invalidDocument(
                'No pudimos leer el PDF de la constancia. Verifica que no esté corrupto o captura los datos manualmente.'
            );
        }

        $texto = trim($texto);
        if ($texto === '' || mb_strlen($texto) < self::MIN_TEXT_LENGTH) {
            throw ConstanciaExtractionException::unreadable();
        }

        return $texto;
    }

    /**
     * @return array<string, mixed>
     */
    public function extractDeterministicData(string $texto): array
    {
        return $this->extraerDatos($texto);
    }

    /**
     * @return array<string, mixed>
     */
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

        $textoNormalizado = $this->normalizarTexto($texto);

        $rfc = $this->extraerRFC($textoNormalizado);
        if ($rfc) {
            $datos['rfc'] = $rfc['rfc'];
            $datos['tipo_persona_confianza'] = $rfc['confianza'];
        }

        if ($datos['rfc']) {
            $datos['tipo_persona'] = $this->determinarTipoPersona($datos['rfc']);
        }

        $datos['nombre'] = $this->extraerNombreCorrecto($texto, $textoNormalizado, $datos['rfc']);
        $datos['razon_social'] = $datos['nombre'];
        $datos['codigo_postal'] = $this->extraerCodigoPostal($textoNormalizado);
        $datos['regimen_fiscal'] = $this->extraerRegimenFiscal($textoNormalizado);
        $datos['fecha_emision'] = $this->extraerFechaEmision($textoNormalizado);
        $datos['estatus_sat'] = $this->determinarEstatusSAT($textoNormalizado);

        return $datos;
    }

    protected function normalizarTexto(string $texto): string
    {
        $texto = preg_replace('/\s+/', ' ', $texto);
        $texto = mb_strtoupper($texto, 'UTF-8');
        $texto = preg_replace('/[^\x20-\x7EÁÉÍÓÚÑáéíóúñ]/u', ' ', $texto);

        return trim((string) $texto);
    }

    /**
     * @return array{rfc: string, confianza: int}|null
     */
    protected function extraerRFC(string $texto): ?array
    {
        $patrones = [
            '/RFC[:\-\s]+([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/' => 95,
            '/RFC\s+([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/' => 90,
            '/([A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3})/' => 70,
        ];

        foreach ($patrones as $patron => $confianza) {
            if (preg_match($patron, $texto, $matches) && isset($matches[1])) {
                $rfc = strtoupper(trim($matches[1]));
                if (preg_match('/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/', $rfc)) {
                    return [
                        'rfc' => $rfc,
                        'confianza' => $confianza,
                    ];
                }
            }
        }

        return null;
    }

    protected function determinarTipoPersona(string $rfc): string
    {
        if (preg_match('/^[A-Z&Ñ]{4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            return 'fisica';
        }

        if (preg_match('/^[A-Z&Ñ]{3}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            return 'moral';
        }

        return 'desconocido';
    }

    protected function extraerNombreCorrecto(string $textoOriginal, string $textoNormalizado, ?string $rfc = null): ?string
    {
        if (preg_match('/([A-ZÁÉÍÓÚÑ\s]{10,})\s+NOMBRE,\s*DENOMINACION\s*O\s*RAZON\s*SOCIAL/i', $textoNormalizado, $matches)) {
            if (isset($matches[1])) {
                $nombre = trim(preg_replace('/^(CONSTANCIA|SITUACION|FISCAL|CÉDULA|IDENTIFICACIÓN)\s+/i', '', trim($matches[1])) ?? '');
                if ($nombre !== '' && strlen($nombre) > 5) {
                    return $nombre;
                }
            }
        }

        if ($rfc) {
            $patronNombre = '/'.preg_quote($rfc, '/').'\s+([A-ZÁÉÍÓÚÑ\s]{10,})\s+NOMBRE,\s*DENOMINACION/i';
            if (preg_match($patronNombre, $textoNormalizado, $matches) && isset($matches[1])) {
                return trim($matches[1]);
            }
        }

        if (preg_match('/DATOS DE IDENTIFICACION DEL CONTRIBUYENTE:(.*?)(?:RFC|CURP|DOMICILIO|$)/si', $textoNormalizado, $matches)) {
            if (isset($matches[1])) {
                $seccion = $matches[1];
                if (preg_match('/NOMBRE\s*\(?S\)?\s*:\s*([A-ZÁÉÍÓÚÑ]+)/i', $seccion, $nameMatches)) {
                    $primerNombre = trim($nameMatches[1]);
                    $apellidos = '';
                    if (preg_match('/PRIMER\s+APELLIDO\s*:\s*([A-ZÁÉÍÓÚÑ]+)/i', $seccion, $apellido1Matches)) {
                        $apellidos .= ' '.trim($apellido1Matches[1]);
                    }
                    if (preg_match('/SEGUNDO\s+APELLIDO\s*:\s*([A-ZÁÉÍÓÚÑ]+)/i', $seccion, $apellido2Matches)) {
                        $apellidos .= ' '.trim($apellido2Matches[1]);
                    }
                    $nombreCompleto = trim($primerNombre.$apellidos);
                    if ($nombreCompleto !== '') {
                        return $nombreCompleto;
                    }
                }
            }
        }

        if (preg_match('/NOMBRE COMERCIAL\s*:\s*([A-ZÁÉÍÓÚÑ\s]+)/i', $textoNormalizado, $matches)) {
            $nombre = trim($matches[1]);
            if ($nombre !== '' && strlen($nombre) > 5 && ! preg_match('/^\s*(RFC|CURP|DOMICILIO)/i', $nombre)) {
                return $nombre;
            }
        }

        $lineas = explode("\n", $textoOriginal);
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if (preg_match('/^[A-ZÁÉÍÓÚÑ]{3,}\s+[A-ZÁÉÍÓÚÑ]{3,}\s+[A-ZÁÉÍÓÚÑ]{3,}$/', $linea)) {
                return $linea;
            }
        }

        return null;
    }

    protected function extraerCodigoPostal(string $texto): ?string
    {
        if (preg_match('/DOMICILIO\s+REGISTRADO(.*?)CODIGO POSTAL\s*:\s*(\d{5})/si', $texto, $matches)) {
            return trim($matches[2]);
        }

        if (preg_match('/CODIGO POSTAL\s*:\s*(\d{5})/i', $texto, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    protected function extraerRegimenFiscal(string $texto): ?string
    {
        if (preg_match('/REGIMENES:(.*?)R[EÉ]GIMEN\s+DE\s+([A-ZÁÉÍÓÚÑ\s]+)/si', $texto, $matches)) {
            $regimen = trim($matches[2]);
            if ($regimen !== '') {
                return $regimen;
            }
        }

        if (preg_match('/R[EÉ]GIMEN\s+DE\s+(SUELDOS\s+Y\s+SALARIOS[^,]*)/i', $texto, $matches)) {
            return 'Régimen de '.trim($matches[1]);
        }

        if (str_contains($texto, 'SUELDOS') && str_contains($texto, 'SALARIOS')) {
            return 'Régimen de Sueldos y Salarios e Ingresos Asimilados a Salarios';
        }

        return null;
    }

    protected function extraerFechaEmision(string $texto): ?string
    {
        if (preg_match('/LUGAR\s+Y\s+FECHA\s+DE\s+EMISION[^,]*,\s*(\d{1,2})\s+DE\s+([A-Z]+)\s+DE\s+(\d{4})/i', $texto, $matches)) {
            $meses = [
                'ENERO' => '01', 'FEBRERO' => '02', 'MARZO' => '03', 'ABRIL' => '04',
                'MAYO' => '05', 'JUNIO' => '06', 'JULIO' => '07', 'AGOSTO' => '08',
                'SEPTIEMBRE' => '09', 'OCTUBRE' => '10', 'NOVIEMBRE' => '11', 'DICIEMBRE' => '12',
            ];

            $dia = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $mes = $meses[strtoupper($matches[2])] ?? '01';
            $anio = $matches[3];

            return "{$anio}-{$mes}-{$dia}";
        }

        return null;
    }

    protected function determinarEstatusSAT(string $texto): string
    {
        if (stripos($texto, 'ACTIVO') !== false) {
            return 'ACTIVO';
        }

        if (stripos($texto, 'CANCELADO') !== false
            || stripos($texto, 'SUSPENDIDO') !== false
            || stripos($texto, 'BAJA') !== false) {
            return 'NO ACTIVO';
        }

        return 'ACTIVO';
    }

    private function looksProtected(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'encrypt')
            || str_contains($message, 'password')
            || str_contains($message, 'secured')
            || str_contains($message, 'proteg');
    }
}
