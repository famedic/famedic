import assert from "node:assert/strict";
import test from "node:test";

import {
    elapsedSecondsFromClock,
    formatClockSeconds,
    isThreeDSTerminalVisualState,
    pollingResponseSummary,
    remainingSecondsFromClock,
    shouldNavigateFromThreeDSVisualState,
    shouldShowThreeDSIframe,
    shouldStopThreeDSPolling,
    threeDSCopyForVisualState,
    threeDSVisualState,
} from "../../resources/js/lib/threeDSRedirectState.js";
import {
    scheduleThreeDSChallengeSubmit,
    THREE_DS_CHALLENGE_FIELD,
    THREE_DS_CHALLENGE_IFRAME_NAME,
} from "../../resources/js/lib/threeDSChallengeSubmit.js";

test("pending and redirect_required keep the bank iframe visible", () => {
    assert.equal(threeDSVisualState("pending", { hasChallenge: true }), "challenge");
    assert.equal(threeDSVisualState("redirect_required", { hasChallenge: true }), "challenge");
    assert.equal(shouldShowThreeDSIframe("challenge"), true);
});

test("authenticated and approved hide the iframe while confirmation continues", () => {
    assert.equal(threeDSVisualState("authenticated", { hasChallenge: true }), "confirming");
    assert.equal(threeDSVisualState("approved", { hasChallenge: true }), "confirming");
    assert.equal(shouldShowThreeDSIframe("confirming"), false);
});

test("tokenizing hides the iframe and enters secure card saving state", () => {
    const state = threeDSVisualState("tokenizing", { hasChallenge: true });

    assert.equal(state, "tokenizing");
    assert.equal(shouldShowThreeDSIframe(state), false);
});

test("completed navigates once according to the visual state contract", () => {
    const first = threeDSVisualState("completed", { final: true });
    const second = threeDSVisualState("completed", { final: true });

    assert.equal(first, "completed");
    assert.equal(second, "completed");
    assert.equal(shouldNavigateFromThreeDSVisualState(first), true);
    assert.equal(shouldNavigateFromThreeDSVisualState(second), true);
});

test("completed before rendering never needs the iframe", () => {
    const state = threeDSVisualState("completed", { hasChallenge: true, final: true });

    assert.equal(state, "completed");
    assert.equal(shouldShowThreeDSIframe(state), false);
});

test("declined and rejected navigate to the result surface", () => {
    assert.equal(threeDSVisualState("declined", { final: true }), "failed");
    assert.equal(threeDSVisualState("rejected", { final: true }), "failed");
    assert.equal(threeDSVisualState("cancelled", { final: true }), "failed");
    assert.equal(shouldNavigateFromThreeDSVisualState("failed"), true);
});

test("unknown initial is not confirmation pending when a challenge exists", () => {
    assert.equal(threeDSVisualState("unknown", { hasChallenge: true }), "challenge");
    assert.equal(threeDSVisualState("", { hasChallenge: true }), "challenge");
    assert.equal(threeDSVisualState(null, { hasChallenge: true }), "challenge");
    assert.equal(threeDSVisualState("provider_confirmation_pending", { hasChallenge: true, final: true }), "confirmation_pending");
    assert.equal(shouldShowThreeDSIframe("confirmation_pending"), false);
    assert.equal(shouldNavigateFromThreeDSVisualState("confirmation_pending"), false);
    assert.equal(isThreeDSTerminalVisualState("confirmation_pending"), true);
});

test("confirmation pending does not show false success and stops polling", () => {
    for (const status of ["provider_confirmation_pending", "tokenization_confirmation_pending"]) {
        const state = threeDSVisualState(status, { final: true, hasChallenge: true });

        assert.equal(state, "confirmation_pending");
        assert.equal(shouldShowThreeDSIframe(state), false);
        assert.equal(shouldNavigateFromThreeDSVisualState(state), false);
        assert.equal(isThreeDSTerminalVisualState(state), true);
    }
});

test("expired clock does not invent a bank result", () => {
    assert.equal(threeDSVisualState("expired", { final: true }), "expired");
    assert.equal(shouldNavigateFromThreeDSVisualState("expired"), false);
    assert.equal(isThreeDSTerminalVisualState("expired"), true);
});

test("polling summaries normalize terminal and processing responses", () => {
    assert.deepEqual(pollingResponseSummary({ final: false, status: "TOKENIZING", message: "ok" }), {
        status: "tokenizing",
        final: false,
        visualState: "tokenizing",
        message: "ok",
        expiresAt: null,
        startedAt: null,
        serverNow: null,
        supportReference: null,
    });

    assert.deepEqual(pollingResponseSummary({ final: true, status: "expired" }), {
        status: "expired",
        final: true,
        visualState: "expired",
        message: null,
        expiresAt: null,
        startedAt: null,
        serverNow: null,
        supportReference: null,
    });
});

test("tokenizing copy highlights approved verification and saving state", () => {
    const copy = threeDSCopyForVisualState("tokenizing");

    assert.equal(copy.title, "Verificación aprobada");
    assert.match(copy.message, /guardando tu tarjeta/i);
});

test("strict rerenders do not require a new provider link or tokenization action", () => {
    assert.equal(threeDSVisualState("pending", { hasChallenge: true }), "challenge");
    assert.equal(threeDSVisualState("pending", { hasChallenge: true }), "challenge");
});

test("terminal visual states stop polling", () => {
    assert.equal(isThreeDSTerminalVisualState("completed"), true);
    assert.equal(isThreeDSTerminalVisualState("failed"), true);
    assert.equal(isThreeDSTerminalVisualState("confirmation_pending"), true);
    assert.equal(isThreeDSTerminalVisualState("expired"), true);
    assert.equal(isThreeDSTerminalVisualState("tokenizing"), false);
});

test("tokenizing and terminal states stop automatic polling; confirming keeps polling", () => {
    assert.equal(shouldStopThreeDSPolling("confirming"), false);
    assert.equal(shouldStopThreeDSPolling("tokenizing"), true);
    assert.equal(shouldStopThreeDSPolling("completed"), true);
    assert.equal(shouldStopThreeDSPolling("confirmation_pending"), true);
    assert.equal(shouldStopThreeDSPolling("challenge"), false);
});

test("fallback navigation is outside the state classifier", () => {
    assert.equal(shouldNavigateFromThreeDSVisualState("completed"), true);
    assert.equal(shouldNavigateFromThreeDSVisualState("failed"), true);
});

test("clock remaining uses expires_at and server_now without extra http", () => {
    const serverNow = "2026-08-26T16:00:00.000Z";
    const expiresAt = "2026-08-26T16:05:00.000Z";
    const receivedAt = Date.parse(serverNow);
    const now = receivedAt + 120000;

    assert.equal(remainingSecondsFromClock({ expiresAt, serverNow, now, receivedAt }), 180);
    assert.equal(elapsedSecondsFromClock({
        startedAt: "2026-08-26T15:59:00.000Z",
        serverNow,
        now,
        receivedAt,
    }), 180);
    assert.equal(formatClockSeconds(180), "03:00");
});

test("challenge submit waits until after cleanup and posts once with creq", async () => {
    const submits = [];
    const iframe = { name: THREE_DS_CHALLENGE_IFRAME_NAME };
    const body = {
        appendChild(node) {
            this.child = node;
        },
        removeChild() {
            this.child = null;
        },
    };

    globalThis.document = {
        querySelector(selector) {
            return selector === `iframe[name="${THREE_DS_CHALLENGE_IFRAME_NAME}"]` ? iframe : null;
        },
        createElement(tag) {
            if (tag === "form") {
                const form = {
                    method: "",
                    action: "",
                    target: "",
                    children: [],
                    appendChild(node) {
                        this.children.push(node);
                    },
                    querySelector() {
                        return this.children[0] || null;
                    },
                    submit() {
                        submits.push({
                            action: this.action,
                            target: this.target,
                            field: this.children[0]?.name,
                        });
                    },
                };

                return form;
            }

            return { type: "", name: "", value: "" };
        },
        body,
    };
    globalThis.window = { setTimeout, clearTimeout };

    const first = scheduleThreeDSChallengeSubmit({
        url: "http://localhost:8080/__local/3ds-fake-acs",
        token: "harness-creq",
    });
    first();
    const second = scheduleThreeDSChallengeSubmit({
        url: "http://localhost:8080/__local/3ds-fake-acs",
        token: "harness-creq",
    });

    await new Promise((resolve) => setTimeout(resolve, 20));
    second();

    assert.equal(submits.length, 1);
    assert.equal(submits[0].target, THREE_DS_CHALLENGE_IFRAME_NAME);
    assert.equal(submits[0].field, THREE_DS_CHALLENGE_FIELD);
    assert.match(submits[0].action, /3ds-fake-acs/);
});
