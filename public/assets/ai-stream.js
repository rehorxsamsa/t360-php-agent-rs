/*
 * AI příklad 06 – Asistent psaní: živý výstup modelu (ADR-0008, plán 008).
 *
 * Formulář se odešle přes fetch (POST + FormData včetně _csrf), ne přes EventSource – ten umí jen GET
 * a placené volání nikdy nesmí jít přes GET. Odpověď je proud Server-Sent Events:
 *   ": start" · "event: delta" + data {"text"} (0..n×) · "event: done" + data {stopReason, model, provider, providerLabel,
 *   inputTokens, outputTokens, costUsd} · nebo "event: error" + data {"message"}.
 * Výstup modelu je nedůvěryhodný (LLM05): do stránky jde výhradně jako text (textContent / textový uzel),
 * nikdy jako HTML. „Přerušit“ = AbortController – server pozná odpojení a volání zaloguje jako přerušené.
 */
(function () {
    'use strict';

    var form = document.getElementById('writing-assistant');
    var output = document.getElementById('ai-stream-output');
    var statusLine = document.getElementById('ai-stream-status');
    var abortButton = document.getElementById('ai-stream-abort');
    var errorBox = document.getElementById('ai-stream-error');
    var meta = document.getElementById('ai-stream-meta');
    if (!form || !output || !statusLine || !abortButton) {
        return;
    }
    var submitButton = form.querySelector('button[type="submit"]');

    var SESSION_EXPIRED = 'Formulář vypršel nebo jste byli odhlášeni – obnovte stránku.';
    var CONNECTION_FAILED = 'Spojení se serverem selhalo, zkuste to prosím znovu.';
    var INCOMPLETE = 'Proud skončil bez dokončení, výstup může být neúplný.';

    var controller = null;

    function setStatus(text) {
        statusLine.textContent = text;
    }

    function showError(message) {
        if (errorBox) {
            errorBox.textContent = message;
            errorBox.hidden = false;
        }
        setStatus('Chyba.');
    }

    function clearError() {
        if (errorBox) {
            errorBox.textContent = '';
            errorBox.hidden = true;
        }
    }

    function setRunning(running) {
        output.setAttribute('aria-busy', running ? 'true' : 'false');
        abortButton.disabled = !running;
        if (submitButton) {
            submitButton.disabled = running;
        }
    }

    function formatNumber(value, decimals) {
        var number = Number(value);
        if (!isFinite(number)) {
            return '?';
        }

        return number.toLocaleString('cs-CZ', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function showSummary(data) {
        if (!meta || data === null || typeof data !== 'object') {
            return;
        }
        meta.textContent = 'Model ' + String(data.model) +
            ' · poskytovatel ' + String(data.providerLabel || data.provider) +
            ' · tokeny vstup ' + formatNumber(data.inputTokens, 0) +
            ' / výstup ' + formatNumber(data.outputTokens, 0) +
            ' · cena ' + formatNumber(data.costUsd, 6) + ' USD';
    }

    /**
     * Rozebere jeden rámec SSE (text mezi prázdnými řádky) na {event, data}. Komentáře (":") se ignorují,
     * víc řádků "data:" se spojí "\n". Rámec bez dat (např. ": start") vrátí null.
     */
    function parseFrame(frame) {
        var event = 'message';
        var data = [];
        frame.split('\n').forEach(function (line) {
            if (line === '' || line.charAt(0) === ':') {
                return;
            }
            var colon = line.indexOf(':');
            var field = colon === -1 ? line : line.slice(0, colon);
            var value = colon === -1 ? '' : line.slice(colon + 1);
            if (value.charAt(0) === ' ') {
                value = value.slice(1);
            }
            if (field === 'event') {
                event = value;
            } else if (field === 'data') {
                data.push(value);
            }
        });
        if (data.length === 0) {
            return null;
        }

        return { event: event, data: data.join('\n') };
    }

    /** Zpracuje jednu událost; vrací true, pokud proud skončil (done/error). */
    function handleEvent(message) {
        var data;
        try {
            data = JSON.parse(message.data);
        } catch (exception) {
            return false;
        }
        if (data === null || typeof data !== 'object') {
            return false;
        }

        if (message.event === 'delta' && typeof data.text === 'string') {
            // Jen textový uzel – žádné HTML z výstupu modelu se neinterpretuje.
            output.appendChild(document.createTextNode(data.text));

            return false;
        }
        if (message.event === 'done') {
            setStatus(data.stopReason === 'max_tokens' ? 'Hotovo (useknuto limitem max_tokens).' : 'Hotovo.');
            showSummary(data);

            return true;
        }
        if (message.event === 'error') {
            showError(typeof data.message === 'string' ? data.message : CONNECTION_FAILED);

            return true;
        }

        return false;
    }

    function readStream(response, signal) {
        var reader = response.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var buffer = '';
        var finished = false;

        function pump() {
            return reader.read().then(function (chunk) {
                if (chunk.done) {
                    buffer += decoder.decode();
                } else {
                    buffer += decoder.decode(chunk.value, { stream: true });
                }
                // Konce řádků sjednotit na "\n"; osamělé "\r" na konci kousku počká na další ("\r\n" rozdělené mezi kousky).
                var pendingCr = '';
                if (!chunk.done && buffer.charAt(buffer.length - 1) === '\r') {
                    pendingCr = '\r';
                    buffer = buffer.slice(0, -1);
                }
                buffer = buffer.replace(/\r\n?/g, '\n');

                var boundary = buffer.indexOf('\n\n');
                while (boundary !== -1) {
                    var message = parseFrame(buffer.slice(0, boundary));
                    buffer = buffer.slice(boundary + 2);
                    if (message !== null && handleEvent(message)) {
                        finished = true;
                    }
                    boundary = buffer.indexOf('\n\n');
                }
                buffer += pendingCr;

                if (chunk.done) {
                    if (!finished && !signal.aborted) {
                        showError(INCOMPLETE);
                    }

                    return undefined;
                }

                return pump();
            });
        }

        return pump();
    }

    function handleFailedResponse(response) {
        var type = response.headers.get('Content-Type') || '';
        // 422 (neplatný vstup) i 429 (rate limit AI, plán 013) nesou JSON {"error": …} – zobrazí se hláška serveru.
        if ((response.status === 422 || response.status === 429) && type.indexOf('application/json') === 0) {
            return response.json().then(function (data) {
                showError(data && typeof data.error === 'string' ? data.error : CONNECTION_FAILED);
            }, function () {
                showError(CONNECTION_FAILED);
            });
        }
        // 403 (neplatný CSRF token) nebo přesměrování na přihlášení (fetch dojde na HTML stránku).
        if (response.status === 403 || response.redirected) {
            showError(SESSION_EXPIRED);
        } else {
            showError(CONNECTION_FAILED);
        }

        return Promise.resolve();
    }

    form.addEventListener('submit', function (submitEvent) {
        submitEvent.preventDefault();
        if (controller !== null) {
            return;
        }

        controller = new AbortController();
        var signal = controller.signal;
        output.textContent = '';
        if (meta) {
            meta.textContent = '';
        }
        clearError();
        setRunning(true);
        setStatus('Generuji…');

        // getAttribute: pole formuláře se jménem „action“ by jinak přepsalo vlastnost form.action.
        fetch(form.getAttribute('action'), {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { Accept: 'text/event-stream' },
            signal: signal
        }).then(function (response) {
            var type = response.headers.get('Content-Type') || '';
            if (!response.ok || type.indexOf('text/event-stream') !== 0 || !response.body) {
                return handleFailedResponse(response);
            }

            return readStream(response, signal);
        }).catch(function (exception) {
            if (signal.aborted || (exception && exception.name === 'AbortError')) {
                setStatus('Přerušeno.');

                return;
            }
            showError(CONNECTION_FAILED);
        }).then(function () {
            controller = null;
            setRunning(false);
        });
    });

    abortButton.addEventListener('click', function () {
        if (controller !== null) {
            controller.abort();
        }
    });
}());
