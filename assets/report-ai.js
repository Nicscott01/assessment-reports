(function () {
    if (typeof window === 'undefined') {
        return;
    }

    var settings = window.AssessmentReportsAI || {};
    var container = document.querySelector('.assessment-report-overlay');
    if (!container || !settings.entryHash || !settings.generateUrl || !settings.statusUrl) {
        return;
    }

    var attempt = 0;
    var maxAttempts = 40;

    function updateMessage(message) {
        var messageNode = container.querySelector('p');
        if (messageNode) {
            messageNode.textContent = message;
        }
    }

    function handleFailure(message) {
        updateMessage(message || 'We are still preparing your report. Please refresh in a moment.');
        container.classList.add('is-error');
    }

    function pollStatus() {
        attempt += 1;
        if (attempt > maxAttempts) {
            handleFailure();
            return;
        }

        fetch(settings.statusUrl + '?entry_hash=' + encodeURIComponent(settings.entryHash), {
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || data.error) {
                    handleFailure('We could not load your report yet. Please refresh.');
                    return;
                }

                if (data.ready) {
                    window.location.reload();
                    return;
                }

                if (data.failed) {
                    handleFailure('We ran into an issue generating your report. Please refresh.');
                    return;
                }

                setTimeout(pollStatus, 1500 + attempt * 250);
            })
            .catch(function () {
                handleFailure();
            });
    }

    fetch(settings.generateUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            entry_hash: settings.entryHash
        })
    })
        .then(function () {
            setTimeout(pollStatus, 1200);
        })
        .catch(function () {
            handleFailure();
        });
})();
