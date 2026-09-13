document.addEventListener('DOMContentLoaded', () => {
    const confirmedClicks = new WeakSet();
    const confirmedForms = new WeakSet();

    const readConfirmAttribute = (element, name) => {
        if (!element || typeof element.getAttribute !== 'function') {
            return '';
        }

        return (element.getAttribute(name) || '').trim();
    };

    const normalizeConfirmVariant = (variant) => {
        if (variant === 'danger' || variant === 'warning' || variant === 'default') {
            return variant;
        }

        return '';
    };

    const inferConfirmVariant = (element, message) => {
        const actionText = `${message} ${element ? element.textContent || '' : ''}`;
        if (
            (element && element.closest && element.closest('.btn-danger'))
            || /\b(delete|restore|deactivate|replace|remove|disable|reset|revoke)\b/i.test(actionText)
        ) {
            return 'danger';
        }

        if (/\b(migration|migrations)\b/i.test(actionText)) {
            return 'warning';
        }

        return 'default';
    };

    const getConfirmOptions = (messageElement, toneElement) => {
        const message = readConfirmAttribute(messageElement, 'data-confirm') || 'Are you sure?';
        const configuredVariant = normalizeConfirmVariant(
            readConfirmAttribute(messageElement, 'data-confirm-variant')
            || readConfirmAttribute(toneElement, 'data-confirm-variant')
        );
        const variant = configuredVariant || inferConfirmVariant(toneElement || messageElement, message);
        const fallbackTitle = variant === 'danger'
            ? 'Confirm high-risk action'
            : (variant === 'warning' ? 'Review this action' : 'Confirm action');

        return {
            label: readConfirmAttribute(messageElement, 'data-confirm-label')
                || readConfirmAttribute(toneElement, 'data-confirm-label')
                || 'Confirm',
            message,
            title: readConfirmAttribute(messageElement, 'data-confirm-title')
                || readConfirmAttribute(toneElement, 'data-confirm-title')
                || fallbackTitle,
            variant,
        };
    };

    const createConfirmModal = () => {
        const modal = document.createElement('div');
        modal.className = 'confirm-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="confirm-modal__backdrop" data-confirm-cancel></div>
            <section class="confirm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title" aria-describedby="confirm-modal-message" tabindex="-1">
                <div class="confirm-modal__body">
                    <div class="confirm-modal__icon" aria-hidden="true">!</div>
                    <div class="confirm-modal__copy">
                        <p class="confirm-modal__eyebrow">Please confirm</p>
                        <h2 class="confirm-modal__title" id="confirm-modal-title">Confirm action</h2>
                        <p class="confirm-modal__message" id="confirm-modal-message"></p>
                    </div>
                </div>
                <div class="confirm-modal__actions">
                    <button class="btn btn-secondary" type="button" data-confirm-cancel>Cancel</button>
                    <button class="btn confirm-modal__accept" type="button" data-confirm-accept>Confirm</button>
                </div>
            </section>
        `;
        document.body.appendChild(modal);

        return {
            acceptButton: modal.querySelector('[data-confirm-accept]'),
            cancelButtons: modal.querySelectorAll('[data-confirm-cancel]'),
            dialog: modal.querySelector('.confirm-modal__dialog'),
            message: modal.querySelector('#confirm-modal-message'),
            modal,
            title: modal.querySelector('#confirm-modal-title'),
        };
    };

    let confirmModal = null;
    let activeConfirm = null;

    const getFocusableConfirmElements = () => {
        if (!confirmModal || !confirmModal.dialog) {
            return [];
        }

        return Array.from(confirmModal.dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter((element) => {
            return !element.disabled && element.getAttribute('aria-hidden') !== 'true';
        });
    };

    const closeConfirmModal = (result) => {
        if (!confirmModal || !activeConfirm) {
            return;
        }

        const { previousFocus, resolve } = activeConfirm;
        activeConfirm = null;

        confirmModal.modal.classList.remove('is-visible');
        confirmModal.modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('confirm-modal-open');
        document.removeEventListener('keydown', handleConfirmKeydown);
        resolve(result);

        window.setTimeout(() => {
            if (previousFocus && typeof previousFocus.focus === 'function' && document.contains(previousFocus)) {
                previousFocus.focus({ preventScroll: true });
            }
        }, 0);
    };

    function handleConfirmKeydown(event) {
        if (!activeConfirm) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeConfirmModal(false);
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusableElements = getFocusableConfirmElements();
        if (!focusableElements.length) {
            event.preventDefault();
            confirmModal.dialog.focus();
            return;
        }

        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];
        if (event.shiftKey && document.activeElement === firstElement) {
            event.preventDefault();
            lastElement.focus();
            return;
        }

        if (!event.shiftKey && document.activeElement === lastElement) {
            event.preventDefault();
            firstElement.focus();
        }
    }

    const requestConfirmation = (options) => {
        if (!document.body || !window.Promise || !document.createElement) {
            return Promise.resolve(window.confirm(options.message));
        }

        try {
            if (!confirmModal) {
                confirmModal = createConfirmModal();
                confirmModal.acceptButton.addEventListener('click', () => closeConfirmModal(true));
                confirmModal.cancelButtons.forEach((button) => {
                    button.addEventListener('click', () => closeConfirmModal(false));
                });
            }

            if (activeConfirm) {
                closeConfirmModal(false);
            }

            return new Promise((resolve) => {
                const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                activeConfirm = { previousFocus, resolve };

                confirmModal.title.textContent = options.title;
                confirmModal.message.textContent = options.message;
                confirmModal.acceptButton.textContent = options.label;
                confirmModal.acceptButton.className = 'btn confirm-modal__accept';
                confirmModal.modal.classList.remove('confirm-modal--danger', 'confirm-modal--warning');

                if (options.variant === 'danger') {
                    confirmModal.acceptButton.classList.add('btn-danger');
                    confirmModal.modal.classList.add('confirm-modal--danger');
                } else if (options.variant === 'warning') {
                    confirmModal.acceptButton.classList.add('confirm-modal__accept-warning');
                    confirmModal.modal.classList.add('confirm-modal--warning');
                }

                document.body.classList.add('confirm-modal-open');
                confirmModal.modal.classList.add('is-visible');
                confirmModal.modal.setAttribute('aria-hidden', 'false');
                document.addEventListener('keydown', handleConfirmKeydown);

                window.setTimeout(() => {
                    confirmModal.acceptButton.focus();
                }, 0);
            });
        } catch (error) {
            return Promise.resolve(window.confirm(options.message));
        }
    };

    const isSubmitControl = (element) => {
        return (
            (element instanceof HTMLButtonElement && element.type === 'submit')
            || (element instanceof HTMLInputElement && (element.type === 'submit' || element.type === 'image'))
        );
    };

    const submitConfirmedForm = (form, submitter) => {
        confirmedForms.add(form);

        if (typeof form.requestSubmit === 'function') {
            if (submitter && isSubmitControl(submitter) && submitter.form === form) {
                form.requestSubmit(submitter);
                return;
            }

            form.requestSubmit();
            return;
        }

        HTMLFormElement.prototype.submit.call(form);
    };

    const minLengthInputs = Array.from(document.querySelectorAll('[data-min-length]')).filter((input) => {
        return input instanceof HTMLInputElement;
    });

    const getMinLengthErrorElement = (input) => {
        const selector = input.getAttribute('data-min-length-error');
        if (!selector) {
            return null;
        }

        const element = document.querySelector(selector);
        return element instanceof HTMLElement ? element : null;
    };

    const validateMinLengthInput = (input) => {
        const minimum = Number.parseInt(input.getAttribute('data-min-length') || '0', 10);
        if (Number.isNaN(minimum) || minimum <= 0) {
            return true;
        }

        const message = input.getAttribute('data-min-length-message')
            || `This field must be at least ${minimum} characters.`;
        const isTooShort = input.value.length > 0 && input.value.length < minimum;
        const errorElement = getMinLengthErrorElement(input);

        input.setCustomValidity(isTooShort ? message : '');
        input.setAttribute('aria-invalid', isTooShort ? 'true' : 'false');

        if (errorElement) {
            errorElement.textContent = message;
            errorElement.hidden = !isTooShort;
        }

        return !isTooShort;
    };

    const validateMinLengthFields = (scope, focusInvalid = false) => {
        const fields = Array.from(scope.querySelectorAll('[data-min-length]')).filter((input) => {
            return input instanceof HTMLInputElement;
        });
        let firstInvalid = null;

        fields.forEach((input) => {
            if (!validateMinLengthInput(input) && firstInvalid === null) {
                firstInvalid = input;
            }
        });

        if (focusInvalid && firstInvalid) {
            firstInvalid.focus();
        }

        return firstInvalid === null;
    };

    minLengthInputs.forEach((input) => {
        validateMinLengthInput(input);
        input.addEventListener('input', () => {
            validateMinLengthInput(input);
        });
        input.addEventListener('blur', () => {
            validateMinLengthInput(input);
        });
    });

    const continueConfirmedClick = (item) => {
        if (isSubmitControl(item) && item.form) {
            submitConfirmedForm(item.form, item);
            return;
        }

        if (typeof item.click === 'function') {
            confirmedClicks.add(item);
            item.click();
            window.setTimeout(() => {
                confirmedClicks.delete(item);
            }, 0);
        }
    };

    const clickableConfirms = Array.from(document.querySelectorAll('[data-confirm]')).filter((item) => item.tagName !== 'FORM');
    clickableConfirms.forEach((item) => {
        item.addEventListener('click', async (event) => {
            if (confirmedClicks.has(item)) {
                confirmedClicks.delete(item);
                return;
            }

            if (event.defaultPrevented) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            if (isSubmitControl(item) && item.form && !validateMinLengthFields(item.form, true)) {
                return;
            }

            const confirmed = await requestConfirmation(getConfirmOptions(item, item));
            if (confirmed) {
                continueConfirmedClick(item);
            }
        }, true);
    });

    const confirmForms = document.querySelectorAll('form[data-confirm]');
    confirmForms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (confirmedForms.has(form)) {
                confirmedForms.delete(form);
                return;
            }

            if (event.defaultPrevented) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            if (!validateMinLengthFields(form, true)) {
                return;
            }

            const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
            const confirmed = await requestConfirmation(getConfirmOptions(form, submitter || form));
            if (confirmed) {
                submitConfirmedForm(form, submitter);
            }
        }, true);
    });

    const passwordToggles = document.querySelectorAll('[data-password-toggle]');
    passwordToggles.forEach((toggle) => {
        const targetSelector = toggle.getAttribute('data-password-target');
        if (!targetSelector) {
            return;
        }

        const input = document.querySelector(targetSelector);
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        const syncToggleLabel = () => {
            const isVisible = input.type === 'text';
            toggle.textContent = isVisible ? 'Hide' : 'Show';
            toggle.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
        };

        syncToggleLabel();

        toggle.addEventListener('click', () => {
            input.type = input.type === 'password' ? 'text' : 'password';
            syncToggleLabel();
            input.focus();
        });
    });

    const resendButton = document.querySelector('[data-resend-button]');
    const resendHint = document.querySelector('[data-resend-remaining]');
    if (resendButton instanceof HTMLButtonElement && resendHint instanceof HTMLElement) {
        let remaining = Number.parseInt(resendHint.getAttribute('data-resend-remaining') || '0', 10);
        const formatResendCountdown = (seconds) => {
            const safeSeconds = Math.max(0, seconds);
            const minutes = Math.floor(safeSeconds / 60);
            const secondsPart = safeSeconds % 60;

            return `${String(minutes).padStart(2, '0')}:${String(secondsPart).padStart(2, '0')}`;
        };

        if (!Number.isNaN(remaining) && remaining > 0) {
            const updateResendState = () => {
                if (remaining <= 0) {
                    resendButton.disabled = false;
                    resendHint.textContent = 'Need another code? Request a new one here.';
                    return true;
                }

                resendButton.disabled = true;
                resendHint.textContent = `You can request a new code in ${formatResendCountdown(remaining)}.`;
                remaining -= 1;

                return false;
            };

            if (!updateResendState()) {
                const interval = window.setInterval(() => {
                    if (updateResendState()) {
                        window.clearInterval(interval);
                    }
                }, 1000);
            }
        }
    }

    const completedOnCreation = document.querySelector('[data-completed-on-creation]');
    const initialStagePreview = document.querySelector('[data-initial-stage-preview]');
    if (completedOnCreation instanceof HTMLInputElement && initialStagePreview instanceof HTMLInputElement) {
        const syncInitialStagePreview = () => {
            initialStagePreview.value = completedOnCreation.checked ? 'Completed' : 'Under Action';
        };

        syncInitialStagePreview();
        completedOnCreation.addEventListener('change', syncInitialStagePreview);
    }

    const liveSearchFocusKey = 'dtrmsLiveSearchFocus';
    let pendingLiveSearchFocus = null;
    try {
        pendingLiveSearchFocus = JSON.parse(window.sessionStorage.getItem(liveSearchFocusKey) || 'null');
        window.sessionStorage.removeItem(liveSearchFocusKey);
    } catch (error) {
        pendingLiveSearchFocus = null;
    }

    const liveSearchForms = Array.from(document.querySelectorAll('form.filter-grid')).filter((form) => {
        return form.method.toLowerCase() === 'get';
    });
    liveSearchForms.forEach((form) => {
        const searchInput = form.querySelector('input[name="q"]');
        if (!(searchInput instanceof HTMLInputElement)) {
            return;
        }

        let searchTimer = null;
        let isComposing = false;
        let lastSubmittedValue = searchInput.value;

        const submitSearch = () => {
            const nextValue = searchInput.value;
            if (nextValue === lastSubmittedValue) {
                return;
            }

            lastSubmittedValue = nextValue;
            try {
                window.sessionStorage.setItem(liveSearchFocusKey, JSON.stringify({
                    name: searchInput.name,
                    path: window.location.pathname,
                }));
            } catch (error) {
                // Focus restore is helpful but not required for filtering.
            }

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.submit();
        };

        const scheduleSearch = () => {
            if (searchTimer) {
                window.clearTimeout(searchTimer);
            }

            searchTimer = window.setTimeout(() => {
                if (!isComposing) {
                    submitSearch();
                }
            }, 350);
        };

        searchInput.addEventListener('compositionstart', () => {
            isComposing = true;
        });

        searchInput.addEventListener('compositionend', () => {
            isComposing = false;
            scheduleSearch();
        });

        searchInput.addEventListener('input', () => {
            if (!isComposing) {
                scheduleSearch();
            }
        });

        if (
            pendingLiveSearchFocus
            && pendingLiveSearchFocus.path === window.location.pathname
            && pendingLiveSearchFocus.name === searchInput.name
        ) {
            window.setTimeout(() => {
                searchInput.focus();
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
            }, 0);
        }
    });

    const wrappedTables = document.querySelectorAll('.table-wrap > table');
    wrappedTables.forEach((table) => {
        const headers = Array.from(table.querySelectorAll('thead th')).map((header) => {
            const label = (header.textContent || '').replace(/\s+/g, ' ').trim();
            return label || 'Action';
        });

        if (!headers.length) {
            return;
        }

        table.querySelectorAll('tbody tr').forEach((row) => {
            if (!(row instanceof HTMLTableRowElement)) {
                return;
            }

            let headerIndex = 0;
            Array.from(row.cells).forEach((cell) => {
                const colspan = Number.parseInt(cell.getAttribute('colspan') || '1', 10);
                const span = Number.isNaN(colspan) ? 1 : Math.max(1, colspan);

                if (cell.hasAttribute('colspan')) {
                    headerIndex += span;
                    return;
                }

                if (!cell.getAttribute('data-label')) {
                    cell.setAttribute('data-label', headers[headerIndex] || 'Action');
                }

                headerIndex += span;
            });
        });
    });

    const sidebar = document.getElementById('app-sidebar');
    const toggles = document.querySelectorAll('[data-sidebar-toggle]');

    const syncSidebarState = () => {
        const isOpen = document.body.classList.contains('sidebar-open');
        toggles.forEach((toggle) => {
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    };

    const closeSidebar = () => {
        document.body.classList.remove('sidebar-open');
        syncSidebarState();
    };

    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-open');
            syncSidebarState();
        });
    });

    document.addEventListener('click', (event) => {
        if (!document.body.classList.contains('sidebar-open') || !sidebar) {
            return;
        }

        if (!(event.target instanceof Node)) {
            return;
        }

        const clickedToggle = Array.from(toggles).some((toggle) => toggle.contains(event.target));
        if (!sidebar.contains(event.target) && !clickedToggle) {
            closeSidebar();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 920) {
            closeSidebar();
        }
    });

    syncSidebarState();
});
