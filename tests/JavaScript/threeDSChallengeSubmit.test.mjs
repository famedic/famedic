import test from "node:test";
import assert from "node:assert/strict";
import {
    hasChallengeBeenSubmitted,
    markChallengeSubmitted,
    submitThreeDSChallenge,
    challengeSubmittedStorageKey,
    THREE_DS_CHALLENGE_IFRAME_NAME,
} from "../../resources/js/lib/threeDSChallengeSubmit.js";

test("sessionStorage prevents duplicate challenge submit on remount", () => {
    const storage = new Map();
    globalThis.window = globalThis;
    globalThis.sessionStorage = {
        getItem(key) {
            return storage.get(key) ?? null;
        },
        setItem(key, value) {
            storage.set(key, value);
        },
        removeItem(key) {
            storage.delete(key);
        },
    };

    const sessionId = "session-remount-test";
    const iframe = { name: THREE_DS_CHALLENGE_IFRAME_NAME };
    globalThis.document = {
        querySelector() {
            return iframe;
        },
        createElement() {
            return {
                method: "",
                action: "",
                target: "",
                children: [],
                appendChild(node) {
                    this.children.push(node);
                },
                submit() {},
            };
        },
        body: {
            appendChild() {},
            removeChild() {},
        },
    };

    markChallengeSubmitted(sessionId);
    assert.equal(hasChallengeBeenSubmitted(sessionId), true);

    const result = submitThreeDSChallenge({
        url: "http://localhost:8080/__local/3ds-fake-acs",
        token: "harness-creq",
        sessionId,
    });

    assert.equal(result.submitted, false);
    assert.equal(result.reason, "already_submitted");
    assert.equal(challengeSubmittedStorageKey(sessionId), `efevoo_3ds_challenge_submitted:${sessionId}`);
});
