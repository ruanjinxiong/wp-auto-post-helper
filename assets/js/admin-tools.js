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

        if (!button) {
            return;
        }

        var messages = window.wpaphTools.messages || {};
        var workingMessage = messages.working || 'Recounting media usage…';
        var errorMessage = messages.genericError || 'Something went wrong. Please try again.';

        button.addEventListener('click', function (event) {
            event.preventDefault();

            if (button.disabled) {
                return;
            }

            button.disabled = true;

            if (status) {
                status.textContent = workingMessage;
                status.classList.remove('notice-error');
            }

            var params = new window.URLSearchParams();
            params.append('action', 'wpaph_recount_media_usage');
            params.append('nonce', window.wpaphTools.nonce);

            window.fetch(window.wpaphTools.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: params.toString()
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Request failed');
                    }

                    return response.json();
                })
                .then(function (data) {
                    if (!status) {
                        return;
                    }

                    if (data && data.success) {
                        status.textContent = data.data && data.data.message ? data.data.message : '';
                        status.classList.remove('notice-error');
                    } else {
                        var message = errorMessage;

                        if (data && data.data && data.data.message) {
                            message = data.data.message;
                        }

                        status.textContent = message;
                        status.classList.add('notice-error');
                    }
                })
                .catch(function () {
                    if (!status) {
                        return;
                    }

                    status.textContent = errorMessage;
                    status.classList.add('notice-error');
                })
                .finally(function () {
                    button.disabled = false;
                });
        });
    });
})();
