import assert from "node:assert/strict";
import test from "node:test";

import {
    isThreeDSTerminalVisualState,
    pollingResponseSummary,
    shouldNavigateFromThreeDSVisualState,
    shouldShowThreeDSIframe,
    threeDSVisualState,
} from "../../resources/js/lib/threeDSRedirectState.js";

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

test("declined and cancelled navigate to the result surface", () => {
    assert.equal(threeDSVisualState("declined", { final: true }), "failed");
    assert.equal(threeDSVisualState("cancelled", { final: true }), "failed");
    assert.equal(shouldNavigateFromThreeDSVisualState("failed"), true);
});

test("confirmation pending does not show false success and stops polling", () => {
    for (const status of ["provider_confirmation_pending", "tokenization_confirmation_pending", "unknown"]) {
        const state = threeDSVisualState(status, { final: true, hasChallenge: true });

        assert.equal(state, "confirmation_pending");
        assert.equal(shouldShowThreeDSIframe(state), false);
        assert.equal(shouldNavigateFromThreeDSVisualState(state), false);
        assert.equal(isThreeDSTerminalVisualState(state), true);
    }
});

test("polling summaries normalize terminal and processing responses", () => {
    assert.deepEqual(pollingResponseSummary({ final: false, status: "TOKENIZING", message: "ok" }), {
        status: "tokenizing",
        final: false,
        visualState: "tokenizing",
        message: "ok",
    });

    assert.deepEqual(pollingResponseSummary({ final: true, status: "expired" }), {
        status: "expired",
        final: true,
        visualState: "failed",
        message: null,
    });
});

test("strict rerenders do not require a new provider link or tokenization action", () => {
    assert.equal(threeDSVisualState("pending", { hasChallenge: true }), "challenge");
    assert.equal(threeDSVisualState("pending", { hasChallenge: true }), "challenge");
});

test("terminal visual states stop polling", () => {
    assert.equal(isThreeDSTerminalVisualState("completed"), true);
    assert.equal(isThreeDSTerminalVisualState("failed"), true);
    assert.equal(isThreeDSTerminalVisualState("confirmation_pending"), true);
    assert.equal(isThreeDSTerminalVisualState("tokenizing"), false);
});

test("fallback navigation is outside the state classifier", () => {
    assert.equal(shouldNavigateFromThreeDSVisualState("completed"), true);
    assert.equal(shouldNavigateFromThreeDSVisualState("failed"), true);
});
