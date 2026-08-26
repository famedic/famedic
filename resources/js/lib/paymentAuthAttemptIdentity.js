export function createAttemptUuid() {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }

    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (char) => {
        const random = Math.floor(Math.random() * 16);
        const value = char === "x" ? random : (random & 0x3) | 0x8;

        return value.toString(16);
    });
}

export function recoveryAttemptStorageKey(storageKey, recoverySubmissionIdentity) {
    if (!storageKey || !recoverySubmissionIdentity) {
        return null;
    }

    return `${storageKey}:recovery:${recoverySubmissionIdentity}`;
}

export function loadAttemptUuid(storageKey, { isRecoveryForm = false, recoverySubmissionIdentity = null } = {}) {
    if (!storageKey) {
        return createAttemptUuid();
    }

    try {
        if (isRecoveryForm && recoverySubmissionIdentity) {
            const scopedKey = recoveryAttemptStorageKey(storageKey, recoverySubmissionIdentity);
            const existingRecovery = window.sessionStorage.getItem(scopedKey);

            if (existingRecovery) {
                return existingRecovery;
            }

            window.sessionStorage.removeItem(storageKey);
            const next = createAttemptUuid();
            window.sessionStorage.setItem(scopedKey, next);

            return next;
        }

        const existing = window.sessionStorage.getItem(storageKey);

        if (existing) {
            return existing;
        }

        const next = createAttemptUuid();
        window.sessionStorage.setItem(storageKey, next);

        return next;
    } catch {
        return createAttemptUuid();
    }
}

export function clearRecoveryAttemptIdentities(storageKey) {
    if (!storageKey) {
        return;
    }

    try {
        const legacyKey = `${storageKey}:recovery`;
        const prefix = `${storageKey}:recovery:`;

        window.sessionStorage.removeItem(legacyKey);

        for (let index = window.sessionStorage.length - 1; index >= 0; index -= 1) {
            const key = window.sessionStorage.key(index);

            if (key?.startsWith(prefix)) {
                window.sessionStorage.removeItem(key);
            }
        }
    } catch {
        // Never persist card data in storage.
    }
}

export function clearAttemptStorage(storageKey, { includeRecovery = false } = {}) {
    if (!storageKey) {
        return;
    }

    try {
        window.sessionStorage.removeItem(storageKey);

        if (includeRecovery) {
            clearRecoveryAttemptIdentities(storageKey);
        }
    } catch {
        // Never persist card data in storage.
    }
}
