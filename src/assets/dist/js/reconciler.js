(function() {
    'use strict';

    var config = window.StripeReconciler || {};

    /**
     * Returns the CSRF parameter name and value Craft expects on POSTs.
     */
    function csrf() {
        var name = (window.Craft && window.Craft.csrfTokenName) || window.csrfTokenName;
        var value = (window.Craft && window.Craft.csrfTokenValue) || window.csrfTokenValue;
        var data = {};

        if (name && value) {
            data[name] = value;
        }

        return data;
    }

    /**
     * Shorthand for a translated string in the plugin's category.
     */
    function t(message, params) {
        return window.Craft.t('stripe-reconciler', message, params || {});
    }

    /**
     * POSTs to a plugin action and resolves with the decoded JSON body.
     */
    function post(action, params) {
        var body = new FormData();
        var tokens = csrf();

        Object.keys(tokens).forEach(function(key) {
            body.append(key, tokens[key]);
        });

        Object.keys(params).forEach(function(key) {
            body.append(key, params[key]);
        });

        return fetch(window.Craft.getActionUrl(action), {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: body,
        }).then(function(response) {
            return response.json();
        });
    }

    /**
     * Writes an outcome into a row's status cell.
     */
    function renderOutcome(cell, data) {
        var dot = document.createElement('span');
        dot.className = 'status ' + (data.statusColour || 'grey');

        var text = document.createElement('span');
        text.textContent = data.message;

        cell.innerHTML = '';
        cell.appendChild(dot);
        cell.appendChild(text);
    }

    /**
     * Flags the history table as out of date. It was rendered before this action.
     */
    function markHistoryStale() {
        var note = document.querySelector('[data-sr-stale]');

        if (note) {
            note.removeAttribute('hidden');
        }
    }

    /**
     * Puts a button back to a usable state.
     */
    function reset(button, label) {
        button.classList.remove('disabled');
        button.removeAttribute('disabled');
        button.textContent = label;
    }

    /**
     * Marks a button as working.
     */
    function busy(button, label) {
        button.classList.add('disabled');
        button.setAttribute('disabled', 'disabled');
        button.textContent = label;
    }

    /**
     * Adds the second-stage Reconcile button once a check reports it is payable.
     */
    function offerReconcile(cell, orderId) {
        var wrap = document.createElement('div');
        wrap.className = 'sr-decide';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn small submit';
        button.textContent = t('Reconcile this order');

        button.addEventListener('click', function() {
            busy(button, t('Reconciling…'));

            post('stripe-reconciler/reconcile/commit', { orderId: orderId })
                .then(function(data) {
                    if (!data.success) {
                        window.Craft.cp.displayError(data.error);
                        reset(button, t('Reconcile this order'));
                        return;
                    }

                    renderOutcome(cell, data);
                    markHistoryStale();
                    window.Craft.cp.displayNotice(data.message);
                })
                .catch(function() {
                    window.Craft.cp.displayError(t('Could not reach the server.'));
                    reset(button, t('Reconcile this order'));
                });
        });

        wrap.appendChild(button);
        cell.appendChild(wrap);
    }

    /**
     * Wires a single row's check button.
     */
    function bindCheck(button) {
        button.addEventListener('click', function() {
            var cell = button.closest('[data-status]');
            var orderId = button.getAttribute('data-order-id');

            busy(button, t('Checking Stripe…'));

            post('stripe-reconciler/reconcile/check', { orderId: orderId })
                .then(function(data) {
                    if (!data.success) {
                        window.Craft.cp.displayError(data.error);
                        reset(button, t('Check Stripe'));
                        return;
                    }

                    renderOutcome(cell, data);
                    markHistoryStale();

                    if (data.reconcilable) {
                        offerReconcile(cell, orderId);
                    }
                })
                .catch(function() {
                    window.Craft.cp.displayError(t('Could not reach the server.'));
                    reset(button, t('Check Stripe'));
                });
        });
    }

    /**
     * Wires the "check them all" button. Queues checks; never commits.
     */
    function bindCheckAll(button) {
        button.addEventListener('click', function() {
            busy(button, t('Queueing…'));

            post('stripe-reconciler/reconcile/all', {})
                .then(function(data) {
                    if (!data.success) {
                        window.Craft.cp.displayError(data.error);
                        reset(button, t('Check them all'));
                        return;
                    }

                    window.Craft.cp.displayNotice(
                        t('Queued {count} order(s) to check. Reload when the queue has run.', {
                            count: data.queued,
                        })
                    );
                    reset(button, t('Check them all'));
                })
                .catch(function() {
                    window.Craft.cp.displayError(t('Could not reach the server.'));
                    reset(button, t('Check them all'));
                });
        });
    }

    function init() {
        if (!config.canReconcile) {
            return;
        }

        document.querySelectorAll('[data-sr-check]').forEach(bindCheck);

        var all = document.querySelector('[data-sr-check-all]');

        if (all) {
            bindCheckAll(all);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
