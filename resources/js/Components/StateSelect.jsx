import { useState, useEffect } from 'react';
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Select } from "@/Components/Catalyst/select";
import { Text } from "@/Components/Catalyst/text";

// Lista estática de estados como fallback
const ESTADOS_MEXICO = {
    'AS': 'Aguascalientes',
    'BC': 'Baja California',
    'BS': 'Baja California Sur',
    'CC': 'Campeche',
    'CL': 'Coahuila',
    'CM': 'Colima',
    'CS': 'Chiapas',
    'CH': 'Chihuahua',
    'DF': 'Ciudad de México',
    'DG': 'Durango',
    'GT': 'Guanajuato',
    'GR': 'Guerrero',
    'HG': 'Hidalgo',
    'JC': 'Jalisco',
    'EM': 'Estado de México',
    'MI': 'Michoacán',
    'MO': 'Morelos',
    'NA': 'Nayarit',
    'NL': 'Nuevo León',
    'OA': 'Oaxaca',
    'PU': 'Puebla',
    'QT': 'Querétaro',
    'QR': 'Quintana Roo',
    'SL': 'San Luis Potosí',
    'SI': 'Sinaloa',
    'SO': 'Sonora',
    'TB': 'Tabasco',
    'TM': 'Tamaulipas',
    'TL': 'Tlaxcala',
    'VE': 'Veracruz',
    'YU': 'Yucatán',
    'ZA': 'Zacatecas'
};

/**
 * Componente selector de estados mexicanos con soporte para datos del backend
 * y fallback local en caso de error
 */
export default function StateSelect({ 
    value, 
    onChange, 
    error, 
    backendStates = {}, 
    required = true,
    disabled = false,
    label = "Estado de residencia",
    placeholder = "Selecciona tu estado",
    helperText = "Selecciona el estado donde resides"
}) {
    const [states, setStates] = useState({});
    const [loadError, setLoadError] = useState(false);
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        try {
            // Intentar usar los estados del backend
            if (backendStates && typeof backendStates === 'object' && Object.keys(backendStates).length > 0) {
                setStates(backendStates);
                setLoadError(false);
            } else {
                // Usar fallback local
                console.log('Usando lista local de estados (fallback)');
                setStates(ESTADOS_MEXICO);
                setLoadError(true);
            }
        } catch (err) {
            console.error('Error procesando estados:', err);
            setStates(ESTADOS_MEXICO);
            setLoadError(true);
        } finally {
            setIsLoading(false);
        }
    }, [backendStates]);

    // Obtener entradas de estados de manera segura
    const getStatesEntries = () => {
        try {
            return Object.entries(states);
        } catch (error) {
            console.error('Error al obtener entradas de estados:', error);
            return [];
        }
    };

    return (
        <Field>
            <Label>
                {label} {required && <span className="text-red-500">*</span>}
            </Label>
            
            {loadError && (
                <div className="mb-2 rounded-md bg-amber-50 p-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">
                    ⚠️ Usando lista local de estados. La conexión con el servidor puede estar fallando.
                </div>
            )}
            
            <Select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="w-full"
                required={required}
                disabled={disabled || isLoading}
            >
                <option value="" disabled>
                    {isLoading ? 'Cargando estados...' : placeholder}
                </option>
                {getStatesEntries().map(([clave, nombre]) => (
                    <option key={clave} value={clave}>
                        {nombre}
                    </option>
                ))}
            </Select>
            
            <Text className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {helperText}
            </Text>
            
            {error && <ErrorMessage>{error}</ErrorMessage>}
        </Field>
    );
}