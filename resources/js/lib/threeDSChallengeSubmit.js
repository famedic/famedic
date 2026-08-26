export const THREE_DS_CHALLENGE_FIELD = "creq";
export const THREE_DS_CHALLENGE_IFRAME_NAME = "threeDSFrame";

export function challengeSubmittedStorageKey(sessionId) {
    return `efevoo_3ds_challenge_submitted:${sessionId}`;
}

export function hasChallengeBeenSubmitted(sessionId) {
    if (!sessionId) {
        return false;
    }

    try {
        return window.sessionStorage.getItem(challengeSubmittedStorageKey(sessionId)) === "1";
    } catch {
        return false;
    }
}

export function markChallengeSubmitted(sessionId) {
    if (!sessionId) {
        return;
    }

    try {
        window.sessionStorage.setItem(challengeSubmittedStorageKey(sessionId), "1");
    } catch {
        // ignore storage failures
    }
}

export function challengeFormMatchesIframe(form, iframe) {
    if (!form || !iframe) {
        return false;
    }

    return form.target === iframe.name && iframe.name === THREE_DS_CHALLENGE_IFRAME_NAME;
}

export function createThreeDSChallengeForm({ url, token, iframeName = THREE_DS_CHALLENGE_IFRAME_NAME }) {
    const form = document.createElement("form");
    form.method = "POST";
    form.action = url;
    form.target = iframeName;

    const input = document.createElement("input");
    input.type = "hidden";
    input.name = THREE_DS_CHALLENGE_FIELD;
    input.value = token;
    form.appendChild(input);

    return form;
}

export function submitThreeDSChallenge({ url, token, iframeName = THREE_DS_CHALLENGE_IFRAME_NAME, sessionId = null }) {
    const iframe = document.querySelector(`iframe[name="${iframeName}"]`);

    if (!iframe || !url || !token) {
        return {
            submitted: false,
            iframe_present: Boolean(iframe),
            form_target_matches: false,
            field_name: THREE_DS_CHALLENGE_FIELD,
            reason: iframe ? "missing_challenge_contract" : "iframe_missing",
        };
    }

    if (sessionId && hasChallengeBeenSubmitted(sessionId)) {
        return {
            submitted: false,
            iframe_present: true,
            form_target_matches: challengeFormMatchesIframe(
                createThreeDSChallengeForm({ url, token, iframeName }),
                iframe
            ),
            field_name: THREE_DS_CHALLENGE_FIELD,
            reason: "already_submitted",
        };
    }

    const form = createThreeDSChallengeForm({ url, token, iframeName });
    const formTargetMatches = challengeFormMatchesIframe(form, iframe);

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);

    if (sessionId) {
        markChallengeSubmitted(sessionId);
    }

    return {
        submitted: true,
        iframe_present: true,
        form_target_matches: formTargetMatches,
        field_name: THREE_DS_CHALLENGE_FIELD,
    };
}

export function scheduleThreeDSChallengeSubmit(options, onAttempt) {
    const timer = window.setTimeout(() => {
        const result = submitThreeDSChallenge(options);
        onAttempt?.(result);
    }, 0);

    return () => window.clearTimeout(timer);
}
