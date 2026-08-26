const RETURN_LABELS = {
    payment_method_settings: "Regresar a métodos de pago",
    laboratory_checkout: "Regresar al checkout",
    online_pharmacy_checkout: "Regresar al checkout",
    medical_attention_checkout: "Regresar al checkout",
    medical_attention_modal: "Regresar",
};

export function recoveryActionIsRenderable(action, recovery) {
    if (!recovery?.context_uuid) {
        return false;
    }

    if (action === "paypal") {
        return Boolean(recovery.recovery_paypal_start_url && recovery.actions?.paypal);
    }

    if (!recovery.recovery_start_url || !recovery.actions?.[action]) {
        return false;
    }

    return true;
}

export function returnActionIsRenderable(recovery) {
    return Boolean(recovery?.return_action?.href && safeReturnLabel(recovery.context_type));
}

export function safeReturnLabel(contextType) {
    return RETURN_LABELS[contextType] ?? null;
}

export function recoveryButtonsForContext(recovery, contextType) {
    const buttons = [];

    if (recoveryActionIsRenderable("retry", recovery)) {
        buttons.push({ action: "retry", label: "Volver a intentar" });
    }

    if (recoveryActionIsRenderable("different_card", recovery)) {
        buttons.push({ action: "different_card", label: "Usar otra tarjeta" });
    }

    if (returnActionIsRenderable(recovery)) {
        buttons.push({
            action: "return",
            label: safeReturnLabel(contextType),
        });
    }

    return buttons;
}
