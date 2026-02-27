/**
 * Componente de depuración que solo se muestra en desarrollo
 */
export default function DebugInfo({ 
    recaptchaLoaded, 
    recaptchaToken, 
    gendersCount, 
    statesCount,
    onReloadRecaptcha 
}) {
    if (process.env.NODE_ENV !== 'development') return null;

    return (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs dark:border-amber-800 dark:bg-amber-900/20">
            <div className="font-semibold">🔍 DEBUG:</div>
            <div>reCAPTCHA: {recaptchaLoaded ? '✅' : '⏳'}</div>
            <div>Token: {recaptchaToken ? '✅' : '❌'}</div>
            <div>Genders: {gendersCount}</div>
            <div>States: {statesCount}</div>
            <button
                type="button"
                onClick={onReloadRecaptcha}
                className="mt-2 text-blue-600 hover:text-blue-800"
            >
                Recargar reCAPTCHA
            </button>
        </div>
    );
}