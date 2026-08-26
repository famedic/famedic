<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Harness 3DS local</title>
    <style>
        body { font-family: sans-serif; margin: 0; background: #fafafa; color: #18181b; }
        main { max-width: 960px; margin: 0 auto; padding: 1.5rem; }
        iframe { width: 100%; height: 420px; border: 1px solid #d4d4d8; background: #fff; }
        #status { margin: 1rem 0; padding: 1rem; border: 1px solid #d4d4d8; background: #fff; }
        button { margin-right: 0.5rem; margin-top: 0.5rem; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <main id="three-ds-redirect-root" data-harness="{{ $harnessId }}">
        <h1>Harness de challenge 3DS</h1>
        <p>ACS ficticio same-origin. No llama al proveedor ni autoriza un pago.</p>
        <section id="status" aria-live="polite">
            <h2 id="status-title">Completa la verificacion con tu banco.</h2>
            <p id="status-message">Sigue las instrucciones de tu banco para continuar.</p>
            <p id="clock">Tiempo transcurrido: 0s · restante: 300s</p>
            <p id="observations"></p>
        </section>
        <div id="challenge-frame-wrap">
            <iframe name="threeDSFrame" title="3D Secure Challenge"></iframe>
        </div>
        <p>
            <button type="button" id="btn-pending">Simular pending</button>
            <button type="button" id="btn-confirmation">Simular confirmation_pending</button>
            <button type="button" id="btn-approved">Simular approved</button>
            <button type="button" id="btn-rerender">Rerender</button>
        </p>
    </main>
    <script type="module">
        const ACS_URL = @json($acsUrl);
        const OBSERVATION_URL = @json($observationUrl);
        const TOKEN = @json($challengeToken);
        const FIELD = "creq";
        const IFRAME_NAME = "threeDSFrame";
        const observations = [];
        const startedAt = Date.now();
        const expiresAt = startedAt + 5 * 60 * 1000;
        let visualState = "challenge";
        let pollingTimer = null;
        let submitCleanup = null;

        function observe(event, details = {}) {
            observations.push({ event, ...details });
            document.getElementById("three-ds-redirect-root").dataset.lastObservation = event;
            document.getElementById("observations").textContent = observations.map((item) => item.event).join(", ");
        }

        function submitChallenge() {
            const iframe = document.querySelector(`iframe[name="${IFRAME_NAME}"]`);
            if (!iframe) {
                observe("challenge_submit_attempted", { submitted: false, reason: "iframe_missing" });
                return;
            }

            const form = document.createElement("form");
            form.method = "POST";
            form.action = ACS_URL;
            form.target = iframe.name;
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = FIELD;
            input.value = TOKEN;
            form.appendChild(input);
            document.body.appendChild(form);
            observe("challenge_submit_attempted", {
                submitted: true,
                form_target_matches: form.target === iframe.name,
            });
            form.submit();
            document.body.removeChild(form);
        }

        function scheduleSubmit() {
            const timer = window.setTimeout(submitChallenge, 0);
            return () => window.clearTimeout(timer);
        }

        function showIframe(state) {
            return state === "challenge";
        }

        function render(state) {
            visualState = state;
            const wrap = document.getElementById("challenge-frame-wrap");
            const wasHidden = wrap.classList.contains("hidden");
            wrap.classList.toggle("hidden", !showIframe(state));
            if (!showIframe(state) && !wasHidden) {
                observe("challenge_ui_hidden", { reason: state });
                document.getElementById("three-ds-redirect-root").dataset.hiddenReason = state;
            }

            const copy = {
                challenge: ["Completa la verificacion con tu banco.", "Sigue las instrucciones de tu banco para continuar."],
                confirmation_pending: ["Estamos confirmando el resultado de tu verificacion.", "No se realizara otro intento sin tu autorizacion."],
                confirming: ["Verificacion aprobada", "Estamos confirmando el resultado..."],
            }[state] || ["Preparando verificacion segura...", "Estamos preparando la conexion segura."];

            document.getElementById("status-title").textContent = copy[0];
            document.getElementById("status-message").textContent = copy[1];
        }

        function startPolling() {
            if (pollingTimer) {
                window.clearInterval(pollingTimer);
            }
            observe("polling_started");
            pollingTimer = window.setInterval(() => {}, 5000);
            return () => {
                window.clearInterval(pollingTimer);
                pollingTimer = null;
                observe("polling_stopped");
            };
        }

        function mount() {
            submitCleanup = scheduleSubmit();
            const stopPolling = startPolling();
            return () => {
                submitCleanup?.();
                stopPolling();
            };
        }

        const iframe = document.querySelector(`iframe[name="${IFRAME_NAME}"]`);
        iframe?.addEventListener("load", () => {
            observe("iframe_load_observed");
        });

        let unmount = mount();

        document.getElementById("btn-pending").addEventListener("click", () => render("challenge"));
        document.getElementById("btn-confirmation").addEventListener("click", () => render("confirmation_pending"));
        document.getElementById("btn-approved").addEventListener("click", () => render("confirming"));
        document.getElementById("btn-rerender").addEventListener("click", () => {
            unmount();
            unmount = mount();
            render(visualState);
        });

        setInterval(() => {
            const elapsed = Math.floor((Date.now() - startedAt) / 1000);
            const remaining = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
            document.getElementById("clock").textContent = `Tiempo transcurrido: ${elapsed}s · restante: ${remaining}s`;
        }, 1000);

        window.__FAMEDIC_3DS_OBSERVATIONS__ = observations;
        window.__FAMEDIC_3DS_HARNESS__ = { observationUrl: OBSERVATION_URL, acsUrl: ACS_URL };
    </script>
</body>
</html>
