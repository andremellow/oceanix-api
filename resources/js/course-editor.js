export function createCourseEditorState(initial = {}) {
    return {
        state: initial.state || 'clean',
        dirty: Boolean(initial.dirty),
        ready: false,
        uploadInProgress: Boolean(initial.uploadInProgress),
        localGeneration: Number(initial.localGeneration || 0),
        savingGeneration: null,
        operationErrors: { ...(initial.operationErrors || {}) },
        activeOperations: new Set(),
        activeOperationMeta: {},
        observedValues: { ...(initial.observedValues || {}) },
        fieldGenerations: {},
        fieldValues: {},
        latestDroppedRecordKeys: [],
        operationalGuidance: '',
        operationalGuidanceTarget: '',
        operationalGuidanceAction: '',
        operationalGuidanceSeverity: 'status',
        confirmationFailureActive: false,
        pdfTransportFailure: null,
        retryPdfRequest() {
            const call = this.pdfTransportFailure;
            // The request failure hook exposes recovery; consume the rejected action promise.
            if (call) this.$wire[call.method](...call.params).catch(() => {});
        },
        messages: {
            loading: 'Loading the editor before :action.',
            permission: 'Permission was removed. :action is unavailable; copy any local values you need.',
            saving: 'Wait for Save to finish before :action.',
            dirty: 'Save or recover your authored changes before :action.',
            upload: 'Wait for the conflicting upload to finish before :action.',
            operation: 'Wait for the current editor operation to finish before :action.',
            failed: 'The response for :action on :target was lost. Automatic retry is unavailable because the outcome is unknown. Check the target, then use the original action only if it is still needed.',
            confirmationFailed: 'Confirmation for :action on :target could not be opened. No change was applied; try the original action again.',
            denied: 'Permission was removed. :action on :target was not applied. Your local values remain available to copy.',
            pending: ':action in progress for :target…',
            ...(initial.messages || {}),
        },
        pendingFocus: null,
        scheduledFocusGeneration: 0,
        beforeUnloadHandler: null,
        keyHandler: null,
        saveBarObserver: null,
        controlsObserver: null,

        markDirty(event) {
            const field = event?.target?.closest?.('[data-editor-field]');
            const control = event?.target;
            if (!field || !control) return;

            const authoredControl = this.authoredControl(field) || control;
            if (authoredControl.type === 'radio' && authoredControl.name) {
                this.stageRadioGroup(authoredControl);

                return;
            }
            const rawValue = this.rawControlValue(authoredControl, event.detail);
            const value = JSON.stringify(rawValue);
            const identity = this.controlIdentity(field);
            const previous = this.observedValues[identity];
            if (previous === value) return;

            this.observedValues[identity] = value;
            this.localGeneration++;
            this.fieldGenerations[identity] = this.localGeneration;
            this.fieldValues[identity] = rawValue;
            field.dataset.editorFieldKey = identity;
            field.dataset.editorGeneration = String(this.localGeneration);
            this.dirty = true;
            if (!['validation-error', 'conflict', 'network-error', 'unknown-outcome', 'permission-lost'].includes(this.state)) {
                this.state = 'dirty';
            }
            this.$wire?.set?.('localGeneration', this.localGeneration, false);
            this.$wire?.set?.('editorDirty', true, false);
            if (field.dataset.fieldName === 'module.content') {
                const keys = new Set(this.$wire?.dirtyContentKeys || []);
                keys.add(field.closest('[data-editor-record]')?.dataset.recordKey || '');
                this.$wire?.set?.('dirtyContentKeys', [...keys].filter(Boolean), false);
            }
            this.synchronizeOperationalControls();
        },

        stageRadioGroup(control) {
            const escapedName = globalThis.CSS?.escape ? globalThis.CSS.escape(control.name) : control.name.replaceAll('"', '\\"');
            const group = [...this.$root.querySelectorAll(`input[type="radio"][name="${escapedName}"]`)]
                .map(radio => ({ radio, field: radio.closest('[data-editor-field]') }))
                .filter(item => item.field);
            const changed = group.some(({ radio, field }) => {
                const selected = radio === control;

                return this.observedValues[this.controlIdentity(field)] !== JSON.stringify(selected);
            });
            if (!changed) return;

            this.localGeneration++;
            group.forEach(({ radio, field }) => {
                const identity = this.controlIdentity(field);
                const value = radio === control;
                radio.checked = value;
                field.dataset.editorFieldKey = identity;
                field.dataset.editorGeneration = String(this.localGeneration);
                this.observedValues[identity] = JSON.stringify(value);
                this.fieldGenerations[identity] = this.localGeneration;
                this.fieldValues[identity] = value;
                const model = this.wireModel(radio);
                if (model) this.$wire?.set?.(model, value, false);
            });
            this.dirty = true;
            if (!['validation-error', 'conflict', 'network-error', 'unknown-outcome', 'permission-lost'].includes(this.state)) {
                this.state = 'dirty';
            }
            this.$wire?.set?.('localGeneration', this.localGeneration, false);
            this.$wire?.set?.('editorDirty', true, false);
            this.synchronizeOperationalControls();
        },

        controlIdentity(field) {
            const name = field.dataset.fieldName || '';
            const keys = [];
            let record = field.closest('[data-editor-record]');
            while (record) {
                keys.unshift(record.dataset.recordKey || 'root');
                record = record.parentElement?.closest?.('[data-editor-record]') || null;
            }
            return `${keys.join('/') || 'root'}/${name}`;
        },

        controlValue(control, fallback = '') {
            return JSON.stringify(this.rawControlValue(control, fallback));
        },

        rawControlValue(control, fallback = '') {
            const checkable = ['checkbox', 'radio'].includes(control.type) || control.localName === 'ui-checkbox';
            if (checkable) return Boolean(control.checked);
            if (control.value !== undefined) return control.value;
            if (typeof fallback === 'string' || typeof fallback === 'number' || typeof fallback === 'boolean') return fallback;
            return control.textContent ?? '';
        },

        authoredControl(field) {
            const modelSelector = '[wire\\:model], [wire\\:model\\.defer], [wire\\:model\\.live], [wire\\:model\\.blur]';
            if (field.matches?.(modelSelector)) return field;
            return field.querySelector?.(modelSelector)
                || field.querySelector?.('input, textarea, select, [contenteditable="true"]')
                || null;
        },

        seedObservedValues() {
            this.$root.querySelectorAll('[data-editor-field]').forEach(field => {
                const control = this.authoredControl(field);
                if (!control) return;
                const identity = this.controlIdentity(field);
                field.dataset.editorFieldKey = identity;
                field.dataset.editorGeneration = String(this.fieldGenerations[identity] || 0);
                this.observedValues[identity] = this.controlValue(control);
            });
        },

        captureOperationRequest() {
            return { generation: this.localGeneration };
        },

        wireModel(control) {
            if (control?.dataset?.editorModel) return control.dataset.editorModel;
            for (const name of ['wire:model', 'wire:model.defer', 'wire:model.live', 'wire:model.blur']) {
                const model = control?.getAttribute?.(name);
                if (model) return model;
            }
            return null;
        },

        reapplyOperationOverlay(request, droppedRecordKeys = []) {
            if (!request) return;
            const dropped = new Set(droppedRecordKeys.map(String));
            const fields = [...this.$root.querySelectorAll('[data-editor-field]')];
            const byIdentity = new Map(fields.map(field => {
                const identity = this.controlIdentity(field);
                field.dataset.editorFieldKey = identity;
                field.dataset.editorGeneration = String(this.fieldGenerations[identity] || 0);
                return [identity, field];
            }));

            Object.entries(this.fieldGenerations).forEach(([identity, generation]) => {
                if (Number(generation) <= Number(request.generation)) return;
                if (identity.split('/').some(segment => dropped.has(segment))) return;
                const field = byIdentity.get(identity);
                if (!field) return;
                const control = this.authoredControl(field);
                const model = this.wireModel(control);
                if (!control || !model) return;
                const value = this.fieldValues[identity];
                if (['checkbox', 'radio'].includes(control.type) || control.localName === 'ui-checkbox') control.checked = Boolean(value);
                else if (control.value !== undefined) control.value = value ?? '';
                this.$wire?.set?.(model, value, false);
                this.observedValues[identity] = JSON.stringify(value);
            });
            this.$wire?.set?.('localGeneration', this.localGeneration, false);
            this.$wire?.set?.('editorDirty', this.dirty, false);
        },

        beginSave() {
            if (!this.dirty || ['saving', 'conflict', 'unknown-outcome', 'permission-lost'].includes(this.state)) return false;
            this.savingGeneration = this.localGeneration;
            this.state = 'saving';
            return true;
        },

        failSave() {
            this.state = 'network-error';
            this.dirty = true;
            this.savingGeneration = null;
        },

        submitSave(close = false) {
            if (this.hasOpenDialog() || this.state === 'saving') return;
            if (!this.dirty && !close) return;
            if (this.dirty && !this.beginSave()) return;

            return Promise.resolve(this.$wire.saveDraft(close)).catch(() => this.failSave());
        },

        finishSave(detail = {}) {
            const acknowledged = Number(detail.generation ?? -1);
            const serverState = detail.state || this.$wire?.saveState || 'saved';
            if (serverState !== 'saved') {
                this.state = serverState;
                this.dirty = true;
                this.savingGeneration = null;
                if (serverState === 'permission-lost') this.disableOperationalControls();
                if (serverState === 'validation-error') {
                    this.restoreInvalidFocus(detail.invalidField);
                }
                return;
            }
            if (acknowledged !== this.localGeneration) {
                this.state = 'dirty';
                this.dirty = true;
                return;
            }
            this.state = 'saved';
            this.dirty = false;
            this.savingGeneration = null;
            this.$nextTick?.(() => this.seedObservedValues());
        },

        focusInvalid(fieldName = null) {
            const controls = [...this.$root.querySelectorAll('input, textarea, select, [contenteditable="true"]')];
            const control = (fieldName && controls.find(item => item.dataset?.validationFor === fieldName
                || item.getAttribute('wire:model') === fieldName
                || item.getAttribute('wire:model.defer') === fieldName))
                || this.$root.querySelector('[aria-invalid="true"] input, [aria-invalid="true"] textarea, [aria-invalid="true"] select, input[aria-invalid="true"], textarea[aria-invalid="true"], select[aria-invalid="true"], [contenteditable="true"][aria-invalid="true"]');
            control?.focus();
        },

        restoreInvalidFocus(fieldName = null) {
            const focus = () => this.focusInvalid(fieldName);
            this.$nextTick?.(() => typeof requestAnimationFrame === 'undefined' ? focus() : requestAnimationFrame(focus));
            [60, 160, 320, 480, 700].forEach(delay => globalThis.setTimeout(focus, delay));
        },

        reloadDraft() {
            this.state = 'clean';
            this.dirty = false;
            this.savingGeneration = null;
            this.$nextTick?.(() => this.seedObservedValues());
        },

        beginOperation(key) {
            this.activeOperations.add(key);
            delete this.operationErrors[key];
            this.synchronizeOperationalControls();
        },

        beginExternalOperation(detail = {}) {
            const key = detail.key || `${detail.kind || 'media'}:${detail.action || 'operation'}:${detail.target || 'editor'}`;
            const identity = {
                kind: detail.kind || 'media',
                action: detail.action || 'upload',
                detail: detail.detail || detail.action || 'operation',
                target: detail.target || 'editor',
                key,
                external: true,
            };
            this.activeOperationMeta[key] = identity;
            this.activeOperations.add(key);
            delete this.operationErrors[key];
            this.synchronizeOperationalControls();
        },

        finishExternalOperation(detail = {}) {
            const key = detail.key || `${detail.kind || 'media'}:${detail.action || 'operation'}:${detail.target || 'editor'}`;
            const identity = this.activeOperationMeta[key] || {
                detail: detail.detail || detail.action || 'upload',
                target: detail.target || 'editor',
            };
            this.activeOperations.delete(key);
            delete this.activeOperationMeta[key];
            if (detail.state === 'failed') this.operationErrors[key] = detail.message || 'failed';
            this.operationalGuidance = detail.state === 'failed' ? (detail.message || this.messages.failed
                .replace(':action', identity.detail)
                .replace(':target', identity.target)) : '';
            this.operationalGuidanceAction = detail.state === 'failed' ? identity.detail : '';
            this.operationalGuidanceTarget = detail.state === 'failed' ? identity.target : '';
            this.operationalGuidanceSeverity = detail.state === 'failed' ? 'danger' : 'status';
            this.synchronizeOperationalControls();
        },

        finishOperation(detail = {}) {
            const key = detail.operation || 'operation';
            if (['conflict', 'permission-lost'].includes(detail.editorState)) {
                this.state = detail.editorState;
                this.dirty = true;
            }
            this.latestDroppedRecordKeys = [...(detail.droppedRecordKeys || [])];
            this.activeOperations.delete(key);
            if (detail.state === 'failed') this.operationErrors[key] = detail.message || 'failed';
            this.activeOperations.clear();
            this.activeOperationMeta = {};
            this.operationalGuidance = '';
            this.operationalGuidanceAction = '';
            this.operationalGuidanceTarget = '';
            this.operationalGuidanceSeverity = 'status';
            this.synchronizeOperationalControls();
        },

        operationalControls() {
            return [...this.$root.querySelectorAll('[data-editor-structure-action], [data-editor-media-action]')];
        },

        authoredControls() {
            return [...this.$root.querySelectorAll('[data-editor-field] input:not([type="hidden"]), [data-editor-field] textarea, [data-editor-field] select, [data-editor-field] [contenteditable]')];
        },

        hasActiveUpload() {
            return this.uploadInProgress || Object.values(this.activeOperationMeta)
                .some(operation => operation.external && operation.kind === 'media' && operation.action === 'upload');
        },

        blockClose(event) {
            if (!this.hasActiveUpload()) return true;
            event?.preventDefault?.();
            event?.stopImmediatePropagation?.();
            event?.stopPropagation?.();

            return false;
        },

        operationIdentity(control) {
            const kind = control.hasAttribute('data-editor-structure-action') ? 'structure' : 'media';
            const action = control.dataset.editorStructureAction || control.dataset.editorMediaAction || 'operation';
            const detail = control.dataset.editorActionDetail || action;
            const family = kind === 'media'
                ? (control.dataset.editorMediaFamily || (detail.includes('image') ? 'image' : 'video'))
                : 'structure';
            const target = control.dataset.editorTargetKey
                || control.closest('[data-editor-record], [data-record-key]')?.dataset.recordKey
                || 'editor';

            const key = kind === 'media'
                ? `${kind}:${family}:${detail}:${target}`
                : `${kind}:${detail}:${target}`;

            return { kind, family, action, detail, target, key };
        },

        operationLabel(control) {
            return (control.getAttribute('aria-label') || control.innerText || control.textContent || 'This action').trim().replace(/\s+/g, ' ');
        },

        operationTargetLabel(control) {
            const record = control.closest('[data-editor-record], [data-record-key]');
            const primary = record?.querySelector?.('[data-editor-primary-field] input, [data-editor-primary-field] textarea, [data-editor-primary-field] select');
            return control.dataset.editorTargetLabel
                || primary?.value?.trim?.()
                || record?.querySelector?.('.font-bold')?.textContent?.trim?.()
                || this.operationIdentity(control).target;
        },

        hasConflictingUpload(control) {
            const identity = this.operationIdentity(control);
            const activeRows = [...this.$root.querySelectorAll('[data-editor-upload][data-upload-state="uploading"], [data-editor-upload][data-upload-state="processing"], [data-editor-upload][data-upload-state="retrying"]')];
            const serverActive = this.uploadInProgress || activeRows.length > 0;
            if (!serverActive || identity.action === 'retry-upload') return false;
            if (identity.family === 'image') return false;
            if (identity.kind === 'structure' && identity.detail !== 'remove-record') return false;

            const escapedTarget = globalThis.CSS?.escape ? globalThis.CSS.escape(identity.target) : identity.target.replaceAll('"', '\\"');
            return activeRows.some(row => row.dataset.recordKey === identity.target)
                || this.$root.querySelector(`[data-editor-upload-picker][data-record-key="${escapedTarget}"][data-upload-state="uploading"]`) !== null;
        },

        hasConflictingOperation(control) {
            if (this.activeOperations.size === 0) return false;
            const identity = this.operationIdentity(control);

            return [...this.activeOperations].some(key => {
                const active = this.activeOperationMeta[key];
                if (!active) return true;
                if (active.external && active.action === 'upload' && identity.family === 'image') return false;
                if (active.external && active.action === 'upload' && identity.kind === 'media' && identity.action === 'upload') {
                    return active.target === identity.target;
                }

                return true;
            });
        },

        operationBlockReason(control) {
            const label = this.operationLabel(control);
            const action = label.toLowerCase();
            if (!this.ready) return this.messages.loading.replace(':action', action);
            if (this.state === 'permission-lost') return this.messages.permission.replace(':action', label);
            if (this.state === 'saving') return this.messages.saving.replace(':action', action);
            if (['conflict', 'unknown-outcome'].includes(this.state)) {
                return this.messages.dirty.replace(':action', action);
            }
            if (this.hasConflictingUpload(control)) return this.messages.upload.replace(':action', action);
            if (this.hasConflictingOperation(control)) return this.messages.operation.replace(':action', action);

            return '';
        },

        describeOperationalControl(control) {
            const descriptionId = 'editor-operational-guidance';
            const describedBy = new Set((control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
            describedBy.add(descriptionId);
            control.setAttribute('aria-describedby', [...describedBy].join(' '));
        },

        setClientDisabled(control, disabled) {
            if (disabled && control.dataset.editorClientDisabled !== 'true') {
                control.dataset.editorWasDisabled = control.disabled ? 'true' : 'false';
                control.dataset.editorClientDisabled = 'true';
                control.disabled = true;
            } else if (!disabled && control.dataset.editorClientDisabled === 'true') {
                if (control.dataset.editorWasDisabled !== 'true' && control.dataset.editorBaseDisabled !== 'true') control.disabled = false;
                delete control.dataset.editorClientDisabled;
                delete control.dataset.editorWasDisabled;
            }
        },

        synchronizeOperationalControls() {
            this.authoredControls().forEach(control => {
                if (control.hasAttribute('contenteditable')) {
                    if (!this.ready && control.dataset.editorHydrationLocked !== 'true') {
                        control.dataset.editorHydrationLocked = 'true';
                        control.dataset.editorPreviousContenteditable = control.getAttribute('contenteditable') || 'true';
                        control.setAttribute('contenteditable', 'false');
                        control.setAttribute('aria-disabled', 'true');
                    } else if (this.ready && control.dataset.editorHydrationLocked === 'true') {
                        control.setAttribute('contenteditable', control.dataset.editorPreviousContenteditable || 'true');
                        control.removeAttribute('aria-disabled');
                        delete control.dataset.editorHydrationLocked;
                        delete control.dataset.editorPreviousContenteditable;
                    }
                    return;
                }

                this.setClientDisabled(control, !this.ready);
                if (!this.ready) control.setAttribute('aria-disabled', 'true');
                else if (!control.disabled) control.removeAttribute('aria-disabled');
            });
            this.operationalControls().forEach(control => {
                this.describeOperationalControl(control);
                const reason = this.operationBlockReason(control);
                const originatingSubmit = control.type === 'submit'
                    && !control.getAttribute('wire:click')
                    && control.dataset.editorOperationState === 'pending';
                const hardLock = (!this.ready || ['saving', 'conflict', 'unknown-outcome', 'permission-lost'].includes(this.state) || this.hasConflictingOperation(control))
                    && !originatingSubmit;
                this.setClientDisabled(control, hardLock);
                if (reason) control.setAttribute('aria-disabled', 'true');
                else if (!control.disabled) control.removeAttribute('aria-disabled');
            });
            this.$root.querySelectorAll('[data-editor-publish-action], [data-editor-save]').forEach(control => {
                this.setClientDisabled(control, !this.ready || ['unknown-outcome', 'permission-lost'].includes(this.state));
            });
            this.$root.querySelectorAll('[data-editor-save-close]').forEach(control => {
                this.setClientDisabled(control, !this.ready || ['conflict', 'unknown-outcome', 'permission-lost'].includes(this.state) || this.hasActiveUpload());
            });
            this.$root.querySelectorAll('[data-editor-cancel]').forEach(control => {
                this.setClientDisabled(control, !this.ready);
                control.setAttribute('aria-disabled', !this.ready || this.hasActiveUpload() ? 'true' : 'false');
            });
        },

        showOperationalGuidance(control, message) {
            const identity = this.operationIdentity(control);
            this.operationalGuidance = message;
            this.operationalGuidanceTarget = identity.target;
            this.operationalGuidanceAction = identity.detail;
            this.operationalGuidanceSeverity = 'status';
        },

        handleOperationalClick(event) {
            const control = event?.target?.closest?.('[data-editor-structure-action], [data-editor-media-action]');
            if (!control || !this.$root.contains(control)) return true;
            const reason = this.operationBlockReason(control);
            if (reason) {
                event.preventDefault?.();
                event.stopImmediatePropagation?.();
                event.stopPropagation?.();
                this.showOperationalGuidance(control, reason);
                return false;
            }

            const wireClick = control.getAttribute('wire:click') || '';
            const opensConfirmation = wireClick.startsWith('confirm');
            const opensPicker = wireClick.startsWith('open') || control.type === 'button' && !wireClick;
            if (opensPicker && !['open-image-library', 'open-video-library', 'open-pdf-modal'].includes(control.dataset.editorActionDetail)) return true;

            const identity = this.operationIdentity(control);
            identity.label = this.operationLabel(control);
            identity.targetLabel = this.operationTargetLabel(control);
            if (opensConfirmation) identity.readOnly = true;
            this.confirmationFailureActive = false;
            this.activeOperationMeta[identity.key] = identity;
            this.activeOperations.add(identity.key);
            delete this.operationErrors[identity.key];
            control.dataset.editorOperationState = 'pending';
            control.setAttribute('aria-busy', 'true');
            this.showOperationalGuidance(
                control,
                this.messages.pending
                    .replace(':action', this.operationLabel(control))
                    .replace(':target', this.operationTargetLabel(control)),
            );
            control.dataset.editorOperationMessage = this.operationalGuidance;
            this.synchronizeOperationalControls();
            return true;
        },

        clearLocalOperations(includeExternal = false, clearGuidance = true) {
            [...this.activeOperations].forEach(key => {
                if (!includeExternal && this.activeOperationMeta[key]?.external) return;
                this.activeOperations.delete(key);
                delete this.activeOperationMeta[key];
            });
            this.$root.querySelectorAll('[data-editor-operation-state="pending"]').forEach(control => {
                delete control.dataset.editorOperationState;
                delete control.dataset.editorOperationMessage;
                control.removeAttribute('aria-busy');
            });
            if (clearGuidance) {
                this.operationalGuidance = '';
                this.operationalGuidanceAction = '';
                this.operationalGuidanceTarget = '';
                this.operationalGuidanceSeverity = 'status';
                this.confirmationFailureActive = false;
            }
            this.synchronizeOperationalControls();
        },

        shouldWarn() {
            return this.dirty || this.state === 'saving' || ['validation-error', 'conflict', 'network-error', 'unknown-outcome'].includes(this.state);
        },

        previewBlocked() {
            return this.shouldWarn() || this.state === 'permission-lost';
        },

        disableOperationalControls() {
            this.synchronizeOperationalControls();
            if (this.state !== 'permission-lost') return;
            const scope = typeof document === 'undefined' ? this.$root : document;
            scope.querySelectorAll('[data-editor-publish-action]').forEach(control => {
                control.disabled = true;
                control.setAttribute('aria-disabled', 'true');
            });
        },

        hasOpenDialog() {
            return Boolean(document.querySelector('dialog[open], [role="dialog"][aria-modal="true"]:not([hidden])'));
        },

        observeSaveBar(element) {
            this.saveBarObserver?.disconnect();
            this.saveBarObserver = new ResizeObserver(entries => {
                this.$root.style.setProperty('--editor-save-bar-height', `${entries[0].contentRect.height}px`);
            });
            this.saveBarObserver.observe(element);
        },

        restoreFocus(recordKey, action, generation = 0) {
            const focusGeneration = Number(generation || 0);
            if (focusGeneration > 0) {
                if (focusGeneration <= this.scheduledFocusGeneration) return;
                this.scheduledFocusGeneration = focusGeneration;
            }
            let restored = false;
            const focus = () => {
                if (focusGeneration > 0 && focusGeneration < this.scheduledFocusGeneration) return;
                if (restored) return;
                const recordSelector = `[data-record-key="${CSS.escape(String(recordKey))}"]`;
                const primaryAuthoredSelector = `${recordSelector} [data-editor-primary-field] input:not([disabled]), ${recordSelector} [data-editor-primary-field] textarea:not([disabled]), ${recordSelector} [data-editor-primary-field] select:not([disabled]), ${recordSelector} [data-editor-primary-field] [contenteditable="true"]`;
                const authoredSelector = `${recordSelector} [data-editor-field] input:not([disabled]), ${recordSelector} [data-editor-field] textarea:not([disabled]), ${recordSelector} [data-editor-field] select:not([disabled]), ${recordSelector} [data-editor-field] [contenteditable="true"]`;
                const actionSelector = `${recordSelector} > div:first-child [data-editor-structure-action="${CSS.escape(action)}"]:not([disabled])`;
                const target = (action === 'first-authored-field' ? (document.querySelector(primaryAuthoredSelector) || document.querySelector(authoredSelector)) : null)
                    || document.querySelector(actionSelector)
                    || document.querySelector(`${recordSelector} > div:first-child [data-editor-structure-action]:not([disabled])`)
                    || document.querySelector(`${recordSelector} [data-editor-expand], ${recordSelector} input, ${recordSelector} button:not([disabled])`)
                    || document.querySelector(recordSelector);
                if (!target) return;
                target.focus();
                restored = true;
            };
            this.$nextTick(() => requestAnimationFrame(focus));
            [60, 140, 240].forEach(delay => window.setTimeout(focus, delay));
        },

        rememberFocus(event) {
            const control = event?.target?.closest?.('[data-editor-structure-action]');
            const action = control?.dataset.editorStructureAction;
            const recordKey = control?.closest?.('[data-editor-record]')?.dataset.recordKey;
            if (!recordKey || !['move-up', 'move-down'].includes(action)) return;
            this.pendingFocus = { recordKey, action };
        },

        init() {
            this.seedObservedValues();
            this.synchronizeOperationalControls();
            this.$wire?.$watch?.('uploadInProgress', value => {
                this.uploadInProgress = Boolean(value);
                this.synchronizeOperationalControls();
            });
            const finishHydration = () => {
                this.ready = true;
                this.synchronizeOperationalControls();
                this.$dispatch?.('oceanix:editor-ready');
            };
            if (this.$nextTick) this.$nextTick(finishHydration);
            else queueMicrotask(finishHydration);
            if (typeof MutationObserver !== 'undefined' && typeof document !== 'undefined' && document.body) {
                this.controlsObserver = new MutationObserver(() => this.synchronizeOperationalControls());
                this.controlsObserver.observe(document.body, { childList: true, subtree: true });
            }
            this.$wire?.$hook?.('request', ({ payload, succeed, fail }) => {
                const body = typeof payload === 'string' ? JSON.parse(payload) : payload;
                if (body?.components?.some(component => component.calls?.some(call => ['openPdfModal', 'cancelArchivePdf'].includes(call.method)))) this.pdfTransportFailure = null;
                const calls = body?.components?.flatMap(component => component.calls || []) || [];
                const pdfMethods = ['openPdfModal', 'loadPdfLibrary', 'searchPdfs', 'clearPdfSearch', 'reusePdf', 'requestArchivePdf', 'archivePdf'];
                const pdfCall = calls.length === 1 && pdfMethods.includes(calls[0].method) ? calls[0] : null;
                if (pdfCall) this.pdfTransportFailure = null;
                const focus = this.pendingFocus;
                const operationRequest = [...this.activeOperations]
                    .some(key => !this.activeOperationMeta[key]?.external && !this.activeOperationMeta[key]?.readOnly)
                    ? this.captureOperationRequest()
                    : null;
                succeed?.(() => {
                    const droppedRecordKeys = this.latestDroppedRecordKeys;
                    this.clearLocalOperations();
                    this.$nextTick?.(() => {
                        this.reapplyOperationOverlay(operationRequest, droppedRecordKeys);
                        this.$root.dispatchEvent?.(new CustomEvent('oceanix:editor-overlay-restored', { bubbles: true }));
                    });
                    this.latestDroppedRecordKeys = [];
                    if (!focus) return;
                    this.pendingFocus = null;
                    this.restoreFocus(focus.recordKey, focus.action);
                });
                fail?.(({ status, preventDefault } = {}) => {
                    if (pdfCall && (Number(status) === 0 || Number(status) >= 500)) {
                        preventDefault?.();
                        this.pdfTransportFailure = pdfCall;
                        this.clearLocalOperations(false, false);
                        this.synchronizeOperationalControls();
                        this.$dispatch?.('oceanix:pdf-archive-failed');
                        return;
                    }
                    if (this.activeOperations.size > 0) {
                        const failedOperation = [...this.activeOperations]
                            .map(key => this.activeOperationMeta[key])
                            .find(identity => identity && !identity.external);
                        if (failedOperation) {
                            const permissionDenied = Number(status) === 403;
                            const readOnlyFailure = failedOperation.readOnly && !permissionDenied;
                            const failureState = permissionDenied ? 'permission-lost' : 'unknown-outcome';
                            if (!readOnlyFailure) {
                                this.state = failureState;
                                if (!failedOperation.readOnly) this.dirty = true;
                                this.$wire?.set?.('saveState', failureState, false);
                                this.$wire?.set?.('errorKind', permissionDenied ? 'permission' : 'unknown-outcome', false);
                            }
                            this.confirmationFailureActive = readOnlyFailure;
                            this.operationalGuidanceAction = failedOperation.detail;
                            this.operationalGuidanceTarget = failedOperation.target;
                            this.operationalGuidanceSeverity = 'danger';
                            const message = permissionDenied
                                ? this.messages.denied
                                : (failedOperation.readOnly ? this.messages.confirmationFailed : this.messages.failed);
                            this.operationalGuidance = message
                                .replace(':action', failedOperation.label || failedOperation.detail)
                                .replace(':target', failedOperation.targetLabel || failedOperation.target);
                            this.$dispatch?.('editor-request-terminal', { operation: failedOperation.key, state: 'failed' });
                        }
                        this.clearLocalOperations(false, false);
                    }
                    if (this.state === 'saving') this.failSave();
                    this.synchronizeOperationalControls();
                });
            });
            this.beforeUnloadHandler = event => {
                if (!this.shouldWarn()) return;
                event.preventDefault();
                event.returnValue = '';
            };
            this.keyHandler = event => {
                if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 's') return;
                event.preventDefault();
                this.submitSave(false);
            };
            window.addEventListener('beforeunload', this.beforeUnloadHandler);
            window.addEventListener('keydown', this.keyHandler);
        },

        destroy() {
            window.removeEventListener('beforeunload', this.beforeUnloadHandler);
            window.removeEventListener('keydown', this.keyHandler);
            this.saveBarObserver?.disconnect();
            this.controlsObserver?.disconnect();
        },
    };
}

if (typeof window !== 'undefined') {
    window.courseEditorState = createCourseEditorState;
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('courseEditorState', createCourseEditorState);
    });
}
