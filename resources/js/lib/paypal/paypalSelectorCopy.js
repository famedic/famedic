/**
 * Etiquetas informativas para el resumen del método PayPal seleccionado.
 *
 * @param {{ loading: boolean, cardEligible: boolean, paypalEligible: boolean }} params
 * @returns {string[]}
 */
export function getPayPalSelectedOptionLabels({
    loading,
    cardEligible,
    paypalEligible,
}) {
    if (loading) {
        return [];
    }

    const labels = [];

    if (cardEligible) {
        labels.push("Tarjeta de crédito o débito");
    }

    if (paypalEligible) {
        labels.push("Cuenta PayPal");
    }

    return labels;
}
