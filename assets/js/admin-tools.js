(function () {
    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    onReady(function () {
        if (typeof window.wpaphTools === 'undefined') {
            return;
        }

        var button = document.getElementById('wpaph-recount-button');
        var status = document.getElementById('wpaph-recount-status');

        if (!button || !status) {
            return;
        }

        var messages = window.wpaphTools.messages || {};
        var workingMessage = messages.working || 'Recounting media usage…';
        var errorMessage = messages.genericError || 'Something went wrong. Please try again.';
        var logTemplate = messages.logEntry || 'Post #%1$s • links found: %2$d • saved: %3$d • dead: %4$d';
        var logTemplateProgress = messages.logEntryProgress || 'Post #%1$s • links found: %2$d • saved: %3$d • dead: %4$d • progress: %5$s';
        var progressTemplate = messages.progressTotals || '%1$d/%2$d processed';
        var deadSummaryTemplate = messages.deadSummary || 'Total dead links: %d';
        var sessionKey = null;
        var logList = null;
        var statusMessage = null;
        var isProcessing = false;

        function formatString(template, values) {
            if (typeof template !== 'string') {
                return '';
            }

            return template.replace(/%(\d+)\$[sd]/g, function (match, index) {
                var value = values[parseInt(index, 10) - 1];

                if (typeof value === 'undefined' || value === null) {
                    return '';
                }

                return value;
            });
        }

        function resetStatusArea() {
            status.classList.remove('notice-error');
            status.innerHTML = '';

            statusMessage = document.createElement('div');
            statusMessage.className = 'wpaph-status-message';
            statusMessage.textContent = workingMessage;
            status.appendChild(statusMessage);

            logList = document.createElement('ul');
            logList.className = 'wpaph-recount-log';
            status.appendChild(logList);
        }

        function setStatusMessage(text, isError) {
            if (!statusMessage) {
                statusMessage = document.createElement('div');
                statusMessage.className = 'wpaph-status-message';
                status.insertBefore(statusMessage, status.firstChild);
            }

            statusMessage.textContent = text;

            if (isError) {
                status.classList.add('notice-error');
            } else {
                status.classList.remove('notice-error');
            }
        }

        function appendLogEntry(data) {
            if (!logList) {
                return;
            }

            if (!data || !data.postId) {
                return;
            }

            var item = document.createElement('li');
            item.className = 'wpaph-recount-entry';

            var progressLabel = '';
            var template = logTemplate;

            if (typeof data.processed === 'number' && typeof data.totalPosts === 'number' && data.totalPosts > 0) {
                progressLabel = formatString(progressTemplate, [data.processed, data.totalPosts]);
                template = logTemplateProgress;
            }

            var text = formatString(template, [
                data.postId,
                data.linksFound,
                data.linksSaved,
                data.deadLinks,
                progressLabel
            ]);

            item.textContent = text;
            logList.appendChild(item);
        }

        function appendSummary(totalDead) {
            if (!logList) {
                return;
            }

            var summary = formatString(deadSummaryTemplate, [totalDead]);

            if (!summary) {
                return;
            }

            var item = document.createElement('li');
            item.className = 'wpaph-recount-summary';
            item.textContent = summary;
            logList.appendChild(item);
        }

        function sendRequest(stage, extraParams) {
            var params = new window.URLSearchParams();
            params.append('action', 'wpaph_recount_media_usage');
            params.append('nonce', window.wpaphTools.nonce);
            params.append('stage', stage);

            if (extraParams) {
                Object.keys(extraParams).forEach(function (key) {
                    var value = extraParams[key];

                    if (typeof value === 'undefined' || value === null) {
                        return;
                    }

                    params.append(key, value);
                });
            }

            return window.fetch(window.wpaphTools.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: params.toString()
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('request_failed');
                    }

                    return response.json();
                })
                .then(function (payload) {
                    if (payload && payload.success) {
                        return payload.data;
                    }

                    var message = errorMessage;

                    if (payload && payload.data && payload.data.message) {
                        message = payload.data.message;
                    }

                    var error = new Error(message);
                    error.displayMessage = message;
                    throw error;
                });
        }

        function updateProgressDisplay(processed, total) {
            if (!statusMessage) {
                return;
            }

            if (typeof total === 'number' && total > 0) {
                var progressLabel = formatString(progressTemplate, [processed, total]);
                setStatusMessage(workingMessage + ' (' + progressLabel + ')', false);
            } else {
                setStatusMessage(workingMessage, false);
            }
        }

        function processNext() {
            if (!sessionKey) {
                return Promise.reject(new Error('missing_session'));
            }

            return sendRequest('process', { session: sessionKey })
                .then(function (data) {
                    appendLogEntry(data);
                    updateProgressDisplay(data.processed || 0, data.totalPosts || 0);

                    if (data.hasMore) {
                        return processNext();
                    }

                    return finishRecount();
                });
        }

        function finishRecount() {
            if (!sessionKey) {
                return Promise.reject(new Error('missing_session'));
            }

            return sendRequest('complete', { session: sessionKey })
                .then(function (data) {
                    setStatusMessage(data.message || workingMessage, false);

                    if (typeof data.dead_total === 'number') {
                        appendSummary(data.dead_total);
                    }

                    return data;
                });
        }

        function startRecount() {
            isProcessing = true;
            sessionKey = null;
            resetStatusArea();

            sendRequest('init')
                .then(function (data) {
                    sessionKey = data.session;

                    if (!sessionKey) {
                        var error = new Error('missing_session');
                        error.displayMessage = errorMessage;
                        throw error;
                    }

                    updateProgressDisplay(data.processed || 0, data.totalPosts || 0);

                    if (data.hasMore) {
                        return processNext();
                    }

                    return finishRecount();
                })
                .catch(function (error) {
                    var message = error && error.displayMessage ? error.displayMessage : errorMessage;
                    setStatusMessage(message, true);
                })
                .finally(function () {
                    isProcessing = false;
                    sessionKey = null;
                    button.disabled = false;
                });
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();

            if (button.disabled || isProcessing) {
                return;
            }

            button.disabled = true;
            startRecount();
        });
    });
})();
