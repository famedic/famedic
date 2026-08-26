import assert from "node:assert/strict";
import test from "node:test";

import {
    clearRecoveryAttemptIdentities,
    loadAttemptUuid,
    recoveryAttemptStorageKey,
} from "../../resources/js/lib/paymentAuthAttemptIdentity.js";
import {
    recoveryActionIsRenderable,
    recoveryButtonsForContext,
    returnActionIsRenderable,
    safeReturnLabel,
} from "../../resources/js/lib/threeDSResultRecovery.js";

class MemoryStorage {
    constructor() {
        this.store = new Map();
    }

    getItem(key) {
        return this.store.has(key) ? this.store.get(key) : null;
    }

    setItem(key, value) {
        this.store.set(key, String(value));
    }

    removeItem(key) {
        this.store.delete(key);
    }

    key(index) {
        return Array.from(this.store.keys())[index] ?? null;
    }

    get length() {
        return this.store.size;
    }
}

globalThis.window = { sessionStorage: new MemoryStorage() };

test("recovery submission identity rotates uuid per explicit recovery", () => {
    const storageKey = "efevoopay:card-auth-attempt:customer-1";
    const firstIdentity = "recovery-cycle-1";
    const secondIdentity = "recovery-cycle-2";

    const firstUuid = loadAttemptUuid(storageKey, {
        isRecoveryForm: true,
        recoverySubmissionIdentity: firstIdentity,
    });
    const sameCycle = loadAttemptUuid(storageKey, {
        isRecoveryForm: true,
        recoverySubmissionIdentity: firstIdentity,
    });
    const nextCycle = loadAttemptUuid(storageKey, {
        isRecoveryForm: true,
        recoverySubmissionIdentity: secondIdentity,
    });

    assert.notEqual(firstUuid, nextCycle);
    assert.equal(firstUuid, sameCycle);
});

test("clearRecoveryAttemptIdentities removes scoped recovery keys", () => {
    const storageKey = "efevoopay:card-auth-attempt:customer-2";
    const identity = "recovery-cycle-3";
    const scopedKey = recoveryAttemptStorageKey(storageKey, identity);

    window.sessionStorage.setItem(scopedKey, "uuid-value");
    window.sessionStorage.setItem(`${storageKey}:recovery`, "legacy-value");

    clearRecoveryAttemptIdentities(storageKey);

    assert.equal(window.sessionStorage.getItem(scopedKey), null);
    assert.equal(window.sessionStorage.getItem(`${storageKey}:recovery`), null);
});

test("different_card renders only with action flag and recovery endpoints", () => {
    const recovery = {
        context_uuid: "ctx-1",
        context_type: "payment_method_settings",
        recovery_start_url: "/recovery/start",
        actions: { different_card: true, retry: true },
        return_action: { href: "/payment-methods" },
    };

    assert.equal(recoveryActionIsRenderable("different_card", recovery), true);
    assert.equal(recoveryActionIsRenderable("different_card", { ...recovery, recovery_start_url: null }), false);
    assert.equal(recoveryActionIsRenderable("different_card", { ...recovery, actions: { different_card: false } }), false);
});

test("paypal is omitted without start url even when action flag is true", () => {
    const recovery = {
        context_uuid: "ctx-1",
        actions: { paypal: true },
    };

    assert.equal(recoveryActionIsRenderable("paypal", recovery), false);
});

test("payment_method_settings shows usable otra tarjeta label and no empty buttons", () => {
    const recovery = {
        context_uuid: "ctx-1",
        context_type: "payment_method_settings",
        recovery_start_url: "/recovery/start",
        actions: { different_card: true, retry: true, paypal: true },
        return_action: { href: "/payment-methods" },
    };

    const buttons = recoveryButtonsForContext(recovery, "payment_method_settings");

    assert.deepEqual(
        buttons.map((button) => button.label),
        ["Volver a intentar", "Usar otra tarjeta", "Regresar a métodos de pago"]
    );
    assert.equal(buttons.every((button) => button.label && button.action), true);
});

test("return action requires href and known label", () => {
    assert.equal(returnActionIsRenderable({ return_action: { href: "/x" }, context_type: "payment_method_settings" }), true);
    assert.equal(returnActionIsRenderable({ return_action: { href: "" }, context_type: "payment_method_settings" }), false);
    assert.equal(safeReturnLabel("payment_method_settings"), "Regresar a métodos de pago");
});
