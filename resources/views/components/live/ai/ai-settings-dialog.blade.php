@once
<style>
    .hb-aidialog { width: 680px; height: 600px; }
    .hb-aidialog__tabs { flex: none; width: 340px; }
    .hb-aidialog__err {
        flex: none; padding: 10px var(--hb-space-4, 16px) 0;
        font-size: var(--hb-fs-sm, 12px); color: var(--hb-danger); text-align: center;
    }
    .hb-aidialog__err[hidden] { display: none; }

    .hb-aidialog__body { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-aidialog__body[hidden] { display: none; }
    .hb-aidialog__scroll {
        height: 100%; overflow-y: auto;
        padding: var(--hb-space-4, 16px);
        display: flex; flex-direction: column; gap: var(--hb-space-3, 12px);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }

    .hb-aidialog__intro { font-size: var(--hb-fs-sm, 12px); line-height: 1.5; color: var(--hb-text-secondary); margin: 0; }
    .hb-aidialog__group {
        font-size: var(--hb-fs-xs, 11px); font-weight: 600; letter-spacing: .04em;
        text-transform: uppercase; color: var(--hb-text-muted);
        margin-top: var(--hb-space-2, 8px);
    }

    .hb-aidialog__row {
        display: flex; align-items: center; gap: var(--hb-space-3, 12px);
        padding: 10px 12px;
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
    }
    .hb-aidialog__row--stacked { flex-direction: column; align-items: stretch; gap: var(--hb-space-2, 8px); }
    .hb-aidialog__row[data-active="true"] { border-color: var(--hb-accent); }
    .hb-aidialog__rowhead { display: flex; align-items: center; gap: var(--hb-space-3, 12px); }
    .hb-aidialog__icon { display: inline-flex; width: 20px; height: 20px; flex: none; color: var(--hb-text-secondary); }
    .hb-aidialog__meta { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .hb-aidialog__name { font-size: var(--hb-fs-sm, 12px); font-weight: 600; color: var(--hb-text-primary); }
    .hb-aidialog__hint {
        font-size: var(--hb-fs-xs, 11px); line-height: 1.4;
        color: var(--hb-text-muted); overflow-wrap: anywhere;
    }

    .hb-aidialog__field { display: flex; flex-direction: column; gap: var(--hb-space-2, 8px); }
    .hb-aidialog__field[hidden] { display: none; }
    .hb-aidialog__slot { display: inline-flex; flex: none; }
    .hb-aidialog__slot[hidden] { display: none; }

    .hb-aidialog__panel {
        display: flex; flex-direction: column; gap: var(--hb-space-2, 8px);
        padding-top: var(--hb-space-2, 8px);
        border-top: 1px solid var(--hb-border);
    }
    .hb-aidialog__panel[hidden] { display: none; }
    .hb-aidialog__actions { display: flex; gap: var(--hb-space-2, 8px); flex-wrap: wrap; }

    .hb-aidialog__empty {
        padding: 32px var(--hb-space-4, 16px); text-align: center;
        font-size: var(--hb-fs-base, 13px); color: var(--hb-text-muted);
    }
    .hb-aidialog__empty[hidden] { display: none; }
    .hb-aidialog__code {
        font-family: var(--hb-font-mono, 'JetBrains Mono', monospace);
        font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-primary);
        background: var(--hb-bg-subtle);
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-sm, 3px);
        padding: 6px 8px; overflow-wrap: anywhere;
    }

    .hb-aidialog__addform {
        display: flex; flex-direction: column; gap: var(--hb-space-2, 8px);
        padding: var(--hb-space-3, 12px);
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg-subtle);
    }
    .hb-aidialog__addform[hidden] { display: none; }

    .hb-aidialog__tools { display: flex; flex-wrap: wrap; gap: var(--hb-space-2, 8px); }
    .hb-aidialog__tool {
        display: inline-flex; align-items: center; gap: var(--hb-space-1, 4px);
        font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-secondary); cursor: pointer;
    }

    .hb-aidialog__foot {
        flex: none; display: flex; align-items: center; justify-content: flex-end;
        gap: var(--hb-space-3, 12px);
        padding: 10px var(--hb-space-4, 16px);
        border-top: 1px solid var(--hb-border);
    }
    .hb-aidialog__status {
        flex: 1 1 auto; font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-muted);
    }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-ai-settings]').forEach((scrim) => {
                if (scrim.__hbAiSettings) return;
                scrim.__hbAiSettings = true;

                const msg = (key) => scrim.dataset[key] || '';
                const errEl = scrim.querySelector('[data-hb-ai-err]');
                const statusEl = scrim.querySelector('[data-hb-ai-status]');
                const saveBtn = scrim.querySelector('[data-hb-ai-save]');
                const bodies = Array.from(scrim.querySelectorAll('[data-hb-tab-body]'));
                const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                const payload = JSON.parse(scrim.dataset.payload || '{}');
                const urls = JSON.parse(scrim.dataset.urls || '{}');

                const state = {
                    providers: (payload.settings && payload.settings.providers) || [],
                    models: (payload.settings && payload.settings.models) || [],
                    activeModel: (payload.settings && payload.settings.active_model) || null,
                    tools: (payload.settings && payload.settings.tools) || [],
                    mcpServers: (payload.settings && payload.settings.mcp_servers) || [],
                };
                let described = payload.providers || [];
                let presets = payload.presets || [];

                const setStatus = (t) => { if (statusEl) statusEl.textContent = t || ''; };
                const showError = (t) => { if (errEl) { errEl.textContent = t || ''; errEl.hidden = !t; } };
                const tmpl = (name) => scrim.querySelector(`[data-hb-tmpl="${name}"]`).content.firstElementChild.cloneNode(true);
                const describedFor = (id) => described.find((p) => p.id === id) || {};
                const providerLabel = (id) => (state.providers.find((p) => p.id === id) || {}).label || id;
                const inputOf = (root, sel) => root.querySelector(sel + ' .hb-input__value');

                const request = (url, method, body) => window.fetch(url, {
                    method: method,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: body ? JSON.stringify(body) : undefined,
                }).then((r) => r.json().catch(() => ({})).then((data) => ({ ok: r.ok, status: r.status, data })));

                const persist = () => {
                    showError('');
                    setStatus(msg('msgSaving'));

                    return request(urls.settings, 'PUT', {
                        settings: {
                            providers: state.providers,
                            models: state.models,
                            active_model: state.activeModel,
                            tools: state.tools,
                            mcp_servers: state.mcpServers,
                        },
                    })
                        .then(({ ok, status, data }) => {
                            if (!ok) {
                                setStatus('');
                                showError((data.errors || [])[0] || (status === 403 ? msg('msgForbidden') : msg('msgSaveFailed')));
                                return false;
                            }
                            state.providers = (data.settings && data.settings.providers) || state.providers;
                            state.models = (data.settings && data.settings.models) || state.models;
                            state.activeModel = (data.settings && data.settings.active_model) || null;
                            described = data.providers || described;
                            presets = data.presets || presets;
                            setStatus(msg('msgSaved'));
                            scrim.dispatchEvent(new CustomEvent('hb:ai-settings-change', { bubbles: true, detail: data.settings || null }));
                            renderProviders();
                            renderPresets();
                            renderModels();
                            renderProviderOptions();
                            return true;
                        })
                        .catch(() => { setStatus(''); showError(msg('msgSaveFailed')); return false; });
                };

                const renderProviderOptions = () => {
                    const select = scrim.querySelector('[data-hb-model-new-provider]');
                    const menu = select?.querySelector('[data-hb-select-menu]');
                    if (!menu) return;

                    menu.innerHTML = '';
                    state.providers.forEach((provider) => {
                        const option = tmpl('select-option');
                        option.dataset.hbSelectOption = provider.id;
                        option.querySelector('span').textContent = provider.label;
                        option.addEventListener('click', () => setSelect(select, provider.id));
                        menu.appendChild(option);
                    });

                    const current = select.dataset.value;
                    const stillThere = state.providers.some((p) => p.id === current);
                    setSelect(select, stillThere ? current : ((state.providers[0] || {}).id || ''));
                };

                const renderProviders = () => {
                    const list = scrim.querySelector('[data-hb-prov-list]');
                    list.innerHTML = '';
                    scrim.querySelector('[data-hb-prov-empty]').hidden = state.providers.length > 0;

                    state.providers.forEach((provider, index) => {
                        const info = describedFor(provider.id);
                        const row = tmpl('provider');
                        const models = state.models.filter((m) => m.provider === provider.id);

                        row.dataset.hbProvRow = provider.id;

                        row.querySelector('[data-hb-prov-label]').textContent = provider.label;
                        row.querySelector('[data-hb-prov-sub]').textContent = models.length
                            ? models.map((m) => m.label || m.id).join(' · ')
                            : msg('msgNoModelsYet');

                        const tag = row.querySelector('[data-hb-prov-status] .hb-statustag');
                        const connected = !!info.connected;
                        tag.querySelector('span:last-child').textContent = connected ? msg('msgConnected') : msg('msgNotConnected');
                        tag.className = 'hb-statustag hb-statustag--' + (connected ? 'success' : 'neutral');

                        const panel = row.querySelector('[data-hb-prov-panel]');
                        row.querySelector('[data-hb-prov-toggle]')?.addEventListener('click', () => {
                            panel.hidden = !panel.hidden;
                        });

                        const keyInput = inputOf(row, '[data-hb-prov-key]');
                        keyInput.placeholder = info.has_key ? msg('msgKeySaved') : msg('msgKeyEnter');
                        row.querySelector('[data-hb-prov-keyhint]').textContent = info.key_from_env
                            ? msg('msgKeyFromEnv').replace(':env', info.key_env || '')
                            : (info.key_env ? msg('msgKeyEnvHint').replace(':env', info.key_env) : '');

                        inputOf(row, '[data-hb-prov-url]').value = provider.base_url || '';
                        inputOf(row, '[data-hb-prov-url]').addEventListener('input', (e) => {
                            provider.base_url = e.target.value.trim();
                            setStatus('');
                        });

                        row.querySelector('[data-hb-prov-savekey]')?.addEventListener('click', () => {
                            const key = keyInput.value;
                            setStatus(msg('msgSaving'));
                            request(urls.key.replace('__ID__', provider.id), 'PUT', { key: key })
                                .then(({ ok, data }) => {
                                    if (!ok) { setStatus(''); showError((data.errors || [])[0] || msg('msgSaveFailed')); return; }
                                    described = data.providers || described;
                                    keyInput.value = '';
                                    setStatus(msg('msgSaved'));
                                    showError('');
                                    renderProviders();
                                })
                                .catch(() => { setStatus(''); showError(msg('msgSaveFailed')); });
                        });

                        row.querySelector('[data-hb-prov-discover]')?.addEventListener('click', () => {
                            setStatus(msg('msgDiscovering'));
                            showError('');
                            request(urls.discover.replace('__ID__', provider.id), 'POST')
                                .then(({ ok, data }) => {
                                    setStatus('');
                                    if (!ok || !data.ok) { showError(data.error || msg('msgDiscoverFailed')); return; }
                                    if (data.empty) { showError(msg('msgDiscoverEmpty')); return; }
                                    renderDiscovered(row, provider, data.models);
                                })
                                .catch(() => { setStatus(''); showError(msg('msgDiscoverFailed')); });
                        });

                        row.querySelector('[data-hb-prov-remove]')?.addEventListener('click', () => {
                            state.providers.splice(index, 1);
                            state.models = state.models.filter((m) => m.provider !== provider.id);
                            if (state.activeModel && state.activeModel.indexOf(provider.id + ':') === 0) {
                                state.activeModel = null;
                            }
                            persist();
                        });

                        list.appendChild(row);
                    });
                };

                const renderDiscovered = (row, provider, ids) => {
                    const holder = row.querySelector('[data-hb-prov-discovered]');
                    holder.innerHTML = '';
                    holder.hidden = false;
                    ids.forEach((id) => {
                        const node = tmpl('tool');
                        const check = node.querySelector('input');
                        node.querySelector('span').textContent = id;
                        check.checked = state.models.some((m) => m.provider === provider.id && m.id === id);
                        check.addEventListener('change', () => {
                            if (check.checked) {
                                if (!state.models.some((m) => m.provider === provider.id && m.id === id)) {
                                    state.models.push({ id: id, label: id, provider: provider.id, enabled: true, effort: 'high' });
                                }
                            } else {
                                state.models = state.models.filter((m) => !(m.provider === provider.id && m.id === id));
                            }
                            renderModels();
                            renderProviders();
                            setStatus('');
                        });
                        holder.appendChild(node);
                    });
                };

                const renderPresets = () => {
                    const list = scrim.querySelector('[data-hb-preset-list]');
                    list.innerHTML = '';
                    const taken = state.providers.map((p) => p.id);
                    presets.filter((p) => taken.indexOf(p.id) === -1).forEach((preset) => {
                        const node = tmpl('preset');
                        node.querySelector('[data-hb-preset-label]').textContent = preset.label;
                        node.querySelector('[data-hb-preset-sub]').textContent = preset.base_url;
                        node.querySelector('[data-hb-preset-add]')?.addEventListener('click', () => {
                            state.providers.push({
                                id: preset.id, label: preset.label, format: preset.format,
                                base_url: preset.base_url, key_env: preset.key_env || null,
                                icon: preset.icon || null, built_in: true,
                            });
                            persist().then((saved) => {
                                if (!saved) return;
                                const row = scrim.querySelector(`[data-hb-prov-row="${preset.id}"] [data-hb-prov-panel]`);
                                if (row) row.hidden = false;
                            });
                        });
                        list.appendChild(node);
                    });
                };

                scrim.querySelector('[data-hb-prov-add-toggle]')?.addEventListener('click', () => {
                    const form = scrim.querySelector('[data-hb-prov-form]');
                    form.hidden = !form.hidden;
                });

                scrim.querySelector('[data-hb-prov-add]')?.addEventListener('click', () => {
                    const form = scrim.querySelector('[data-hb-prov-form]');
                    const label = (inputOf(form, '[data-hb-prov-new-label]').value || '').trim();
                    const url = (inputOf(form, '[data-hb-prov-new-url]').value || '').trim();
                    const env = (inputOf(form, '[data-hb-prov-new-env]').value || '').trim();
                    const format = form.querySelector('[data-hb-prov-new-format]').dataset.value || 'openai';
                    const id = label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

                    if (!id || !/^https?:\/\//.test(url)) { showError(msg('msgProvBadInput')); return; }
                    if (state.providers.some((p) => p.id === id)) { showError(msg('msgProvDuplicate')); return; }

                    showError('');
                    state.providers.push({
                        id: id, label: label, format: format, base_url: url,
                        key_env: env || null, icon: null, built_in: false,
                    });

                    persist().then((saved) => {
                        if (!saved) {
                            state.providers = state.providers.filter((p) => p.id !== id);
                            renderProviders();
                            return;
                        }
                        ['[data-hb-prov-new-label]', '[data-hb-prov-new-url]', '[data-hb-prov-new-env]']
                            .forEach((s) => { inputOf(form, s).value = ''; });
                        form.hidden = true;
                        const panel = scrim.querySelector(`[data-hb-prov-row="${id}"] [data-hb-prov-panel]`);
                        if (panel) panel.hidden = false;
                    });
                });

                const renderModels = () => {
                    const list = scrim.querySelector('[data-hb-model-list]');
                    list.innerHTML = '';
                    scrim.querySelector('[data-hb-model-empty]').hidden = state.models.length > 0;

                    state.providers.forEach((provider) => {
                        const models = state.models.filter((m) => m.provider === provider.id);
                        if (!models.length) return;

                        const head = document.createElement('div');
                        head.className = 'hb-aidialog__group';
                        head.textContent = provider.label;
                        list.appendChild(head);

                        models.forEach((model) => {
                            const row = tmpl('model');
                            const key = model.provider + ':' + model.id;
                            const isActive = key === state.activeModel;

                            row.dataset.active = isActive ? 'true' : 'false';
                            row.querySelector('[data-hb-model-name]').textContent = model.label || model.id;
                            row.querySelector('[data-hb-model-sub]').textContent =
                                model.id + '  ·  ' + msg('msgEffort') + ' ' + model.effort;
                            row.querySelector('[data-hb-model-active-tag]').hidden = !isActive;
                            row.querySelector('[data-hb-model-use]').hidden = isActive || !model.enabled;

                            const toggle = row.querySelector('[data-hb-model-enabled] input');
                            toggle.checked = !!model.enabled;
                            toggle.addEventListener('change', () => {
                                model.enabled = toggle.checked;
                                if (!model.enabled && state.activeModel === key) state.activeModel = null;
                                renderModels();
                                setStatus('');
                            });

                            row.querySelector('[data-hb-model-use]')?.addEventListener('click', () => {
                                state.activeModel = key;
                                renderModels();
                                setStatus('');
                            });

                            row.querySelector('[data-hb-model-edit]')?.addEventListener('click', () => openModelForm(model));

                            row.querySelector('[data-hb-model-remove]')?.addEventListener('click', () => {
                                state.models = state.models.filter((m) => m !== model);
                                if (state.activeModel === key) state.activeModel = null;
                                renderModels();
                                renderProviders();
                                setStatus('');
                            });

                            list.appendChild(row);
                        });
                    });
                };

                let editing = null;
                const modelForm = scrim.querySelector('[data-hb-model-form]');

                const openModelForm = (model) => {
                    editing = model || null;
                    modelForm.hidden = false;
                    inputOf(modelForm, '[data-hb-model-new-id]').value = model ? model.id : '';
                    inputOf(modelForm, '[data-hb-model-new-label]').value = model ? (model.label || '') : '';
                    setSelect(modelForm.querySelector('[data-hb-model-new-provider]'), model ? model.provider : (state.providers[0] || {}).id);
                    setSelect(modelForm.querySelector('[data-hb-model-new-effort]'), model ? model.effort : 'high');
                    scrim.querySelector('[data-hb-model-form-title]').textContent = model ? msg('msgEditModel') : msg('msgAddModel');
                };

                const setSelect = (root, value) => {
                    if (!root) return;
                    root.dataset.value = value || '';
                    const option = root.querySelector(`[data-hb-select-option="${value}"]`);
                    const label = root.querySelector('[data-hb-select-value]');
                    if (option && label) label.textContent = option.textContent.trim();
                    root.querySelectorAll('[data-hb-select-option]').forEach((o) => {
                        o.setAttribute('aria-selected', o.dataset.hbSelectOption === value ? 'true' : 'false');
                    });
                };

                scrim.querySelector('[data-hb-model-add-toggle]')?.addEventListener('click', () => {
                    if (!state.providers.length) { showError(msg('msgNeedProvider')); return; }
                    showError('');
                    modelForm.hidden ? openModelForm(null) : (modelForm.hidden = true);
                });

                scrim.querySelector('[data-hb-model-form-save]')?.addEventListener('click', () => {
                    const id = (inputOf(modelForm, '[data-hb-model-new-id]').value || '').trim();
                    const label = (inputOf(modelForm, '[data-hb-model-new-label]').value || '').trim();
                    const provider = modelForm.querySelector('[data-hb-model-new-provider]').dataset.value || '';
                    const effort = modelForm.querySelector('[data-hb-model-new-effort]').dataset.value || 'high';

                    if (!/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/.test(id) || !provider) {
                        showError(msg('msgModelBadInput'));
                        return;
                    }
                    const clash = state.models.some((m) => m !== editing && m.provider === provider && m.id === id);
                    if (clash) { showError(msg('msgModelDuplicate')); return; }

                    showError('');
                    if (editing) {
                        Object.assign(editing, { id: id, label: label || id, provider: provider, effort: effort });
                    } else {
                        state.models.push({ id: id, label: label || id, provider: provider, enabled: true, effort: effort });
                        if (!state.activeModel) state.activeModel = provider + ':' + id;
                    }
                    editing = null;
                    modelForm.hidden = true;
                    renderModels();
                    renderProviders();
                });

                scrim.querySelector('[data-hb-model-form-cancel]')?.addEventListener('click', () => {
                    editing = null;
                    modelForm.hidden = true;
                    showError('');
                });

                const renderServers = () => {
                    const list = scrim.querySelector('[data-hb-mcp-list]');
                    if (!list) return;
                    list.innerHTML = '';
                    scrim.querySelector('[data-hb-mcp-empty]').hidden = state.mcpServers.length > 0;

                    state.mcpServers.forEach((server, index) => {
                        const row = tmpl('mcp');
                        row.querySelector('[data-hb-mcp-label]').textContent = server.label || server.id;
                        row.querySelector('[data-hb-mcp-url]').textContent =
                            server.url + (server.auth_env ? '  ·  ' + server.auth_env : '');

                        const toggle = row.querySelector('[data-hb-mcp-enabled] input');
                        toggle.checked = !!server.enabled;
                        toggle.addEventListener('change', () => { server.enabled = toggle.checked; setStatus(''); });

                        row.querySelector('[data-hb-mcp-remove]')?.addEventListener('click', () => {
                            state.mcpServers.splice(index, 1);
                            renderServers();
                            setStatus('');
                        });

                        row.querySelector('[data-hb-mcp-test]')?.addEventListener('click', () => {
                            showError('');
                            setStatus(msg('msgMcpTesting'));
                            request(urls.mcpTest, 'POST', server)
                                .then(({ ok, data }) => {
                                    setStatus('');
                                    if (!ok || !data.ok) { showError(data.error || msg('msgMcpTestFailed')); return; }
                                    if (!data.tools.length) { showError(msg('msgMcpNoTools')); return; }
                                    const holder = row.querySelector('[data-hb-mcp-tools]');
                                    holder.innerHTML = '';
                                    holder.hidden = false;
                                    data.tools.forEach((tool) => {
                                        const node = tmpl('tool');
                                        const check = node.querySelector('input');
                                        node.querySelector('span').textContent = tool.name;
                                        check.checked = (server.allowed_tools || []).indexOf(tool.name) !== -1;
                                        check.addEventListener('change', () => {
                                            const set = new Set(server.allowed_tools || []);
                                            if (check.checked) set.add(tool.name); else set.delete(tool.name);
                                            server.allowed_tools = Array.from(set);
                                            setStatus('');
                                        });
                                        holder.appendChild(node);
                                    });
                                })
                                .catch(() => { setStatus(''); showError(msg('msgMcpTestFailed')); });
                        });

                        list.appendChild(row);
                    });
                };

                scrim.querySelector('[data-hb-mcp-add]')?.addEventListener('click', () => {
                    const form = scrim.querySelector('[data-hb-mcp-form]');
                    const id = (inputOf(form, '[data-hb-mcp-new-id]').value || '').trim();
                    const url = (inputOf(form, '[data-hb-mcp-new-url]').value || '').trim();
                    if (!/^[a-z0-9][a-z0-9-]*$/.test(id) || !/^https?:\/\//.test(url)) {
                        showError(msg('msgMcpBadInput'));
                        return;
                    }
                    showError('');
                    state.mcpServers.push({
                        id: id, label: id, url: url,
                        auth_env: (inputOf(form, '[data-hb-mcp-new-env]').value || '').trim() || null,
                        enabled: false, allowed_tools: [],
                    });
                    ['[data-hb-mcp-new-id]', '[data-hb-mcp-new-url]', '[data-hb-mcp-new-env]']
                        .forEach((s) => { inputOf(form, s).value = ''; });
                    renderServers();
                });

                scrim.querySelector('[data-hb-tablist]')?.addEventListener('change', (event) => {
                    bodies.forEach((b) => { b.hidden = b.dataset.hbTabBody !== event.detail.value; });
                });

                saveBtn?.addEventListener('click', () => {
                    saveBtn.disabled = true;
                    persist().finally(() => { saveBtn.disabled = false; });
                });

                scrim.querySelector('.hb-mediadialog__close')?.addEventListener('click', () => {
                    if (scrim.hbClose) scrim.hbClose(); else scrim.hidden = true;
                });

                renderProviders();
                renderPresets();
                renderModels();
                renderProviderOptions();
                renderServers();
            });

            if (!document.__hbAiSettingsOpen) {
                document.__hbAiSettingsOpen = true;
                document.addEventListener('click', (event) => {
                    const opener = event.target.closest('[data-hb-ai-settings-open]');
                    if (!opener) return;
                    const scrim = document.querySelector('[data-hb-ai-settings]');
                    if (!scrim) return;
                    if (scrim.hbOpen) scrim.hbOpen(opener); else scrim.hidden = false;
                });
            }
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    'payload' => [],
    'settingsUrl' => null,
    'keyUrlTemplate' => null,
    'discoverUrlTemplate' => null,
    'mcpTestUrl' => null,
])
@php
    $tabs = [
        ['value' => 'providers', 'label' => __('heisenberg::editor.ai.tab_providers')],
        ['value' => 'models', 'label' => __('heisenberg::editor.ai.tab_models')],
        ['value' => 'mcp', 'label' => __('heisenberg::editor.ai.tab_mcp')],
        ['value' => 'expose', 'label' => __('heisenberg::editor.ai.tab_expose')],
    ];

    $effortOptions = array_map(
        static fn (string $e): array => ['value' => $e, 'label' => ucfirst($e)],
        \Heisenberg\Ai\AiRequest::EFFORTS,
    );
    $formatOptions = array_map(
        static fn (string $id, string $label): array => ['value' => $id, 'label' => $label],
        array_keys((array) ($payload['formats'] ?? [])),
        array_values((array) ($payload['formats'] ?? [])),
    );
    $providerOptions = array_map(
        static fn (array $p): array => ['value' => $p['id'], 'label' => $p['label']],
        (array) ($payload['settings']['providers'] ?? []),
    );

    $mcpClientEnabled = (bool) config('heisenberg.ai.mcp.client.enabled', false);
    $mcpServerEnabled = (bool) config('heisenberg.ai.mcp.server.enabled', false);
    $mcpServerPath = (string) config('heisenberg.ai.mcp.server.path', 'heisenberg/mcp');
    $mcpTokensEnv = (string) config('heisenberg.ai.mcp.server.tokens_env', '');
    $exposedTools = app(\Heisenberg\Services\McpToolRegistry::class)->describeAll();
@endphp

<div class="hb-mediadialog__scrim" data-hb-ai-settings hidden
    data-payload="{{ json_encode($payload) }}"
    data-urls="{{ json_encode([
        'settings' => $settingsUrl,
        'key' => $keyUrlTemplate,
        'discover' => $discoverUrlTemplate,
        'mcpTest' => $mcpTestUrl,
    ]) }}"
    data-msg-saving="{{ __('heisenberg::editor.ai.saving') }}"
    data-msg-saved="{{ __('heisenberg::editor.ai.saved') }}"
    data-msg-save-failed="{{ __('heisenberg::editor.ai.save_failed') }}"
    data-msg-forbidden="{{ __('heisenberg::editor.ai.forbidden') }}"
    data-msg-connected="{{ __('heisenberg::editor.ai.connected') }}"
    data-msg-not-connected="{{ __('heisenberg::editor.ai.not_connected') }}"
    data-msg-no-models-yet="{{ __('heisenberg::editor.ai.no_models_yet') }}"
    data-msg-key-saved="{{ __('heisenberg::editor.ai.key_saved') }}"
    data-msg-key-enter="{{ __('heisenberg::editor.ai.key_enter') }}"
    data-msg-key-from-env="{{ __('heisenberg::editor.ai.key_from_env') }}"
    data-msg-key-env-hint="{{ __('heisenberg::editor.ai.key_env_hint') }}"
    data-msg-discovering="{{ __('heisenberg::editor.ai.discovering') }}"
    data-msg-discover-failed="{{ __('heisenberg::editor.ai.discover_failed') }}"
    data-msg-discover-empty="{{ __('heisenberg::editor.ai.discover_empty') }}"
    data-msg-prov-bad-input="{{ __('heisenberg::editor.ai.provider_bad_input') }}"
    data-msg-prov-duplicate="{{ __('heisenberg::editor.ai.provider_duplicate') }}"
    data-msg-need-provider="{{ __('heisenberg::editor.ai.need_provider') }}"
    data-msg-add-model="{{ __('heisenberg::editor.ai.model_add') }}"
    data-msg-edit-model="{{ __('heisenberg::editor.ai.model_edit') }}"
    data-msg-effort="{{ __('heisenberg::editor.ai.effort_label') }}"
    data-msg-model-bad-input="{{ __('heisenberg::editor.ai.model_bad_input') }}"
    data-msg-model-duplicate="{{ __('heisenberg::editor.ai.model_duplicate') }}"
    data-msg-mcp-testing="{{ __('heisenberg::editor.ai.mcp_testing') }}"
    data-msg-mcp-test-failed="{{ __('heisenberg::editor.ai.mcp_test_failed') }}"
    data-msg-mcp-no-tools="{{ __('heisenberg::editor.ai.mcp_no_tools') }}"
    data-msg-mcp-bad-input="{{ __('heisenberg::editor.ai.mcp_bad_input') }}">
    <div class="hb-mediadialog hb-aidialog" role="dialog" aria-modal="true"
        aria-label="{{ __('heisenberg::editor.ai.settings_title') }}" tabindex="-1">

        <div class="hb-mediadialog__top">
            <span class="hb-mediadialog__title">{{ __('heisenberg::editor.ai.settings_title') }}</span>
            <span class="hb-aidialog__tabs"><x-heisenberg::ui.tabs :items="$tabs" :active-index="0" /></span>
            <button type="button" class="hb-mediadialog__close" aria-label="{{ __('heisenberg::editor.common.close') }}">
                @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 16])
            </button>
        </div>

        <div class="hb-aidialog__err" data-hb-ai-err hidden></div>

        <div class="hb-aidialog__body" data-hb-tab-body="providers">
            <div class="hb-aidialog__scroll" data-hb-ai-providers-scroll>
                <p class="hb-aidialog__intro">{{ __('heisenberg::editor.ai.providers_intro') }}</p>

                <div data-hb-prov-list></div>
                <div class="hb-aidialog__empty" data-hb-prov-empty>{{ __('heisenberg::editor.ai.providers_empty') }}</div>

                <div class="hb-aidialog__group">{{ __('heisenberg::editor.ai.presets_title') }}</div>
                <div data-hb-preset-list></div>

                <div><x-heisenberg::ui.button variant="secondary" data-hb-prov-add-toggle>{{ __('heisenberg::editor.ai.provider_add') }}</x-heisenberg::ui.button></div>
                <div class="hb-aidialog__addform" data-hb-prov-form hidden>
                    <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.provider_name') }}</x-heisenberg::ui.field-label>
                    <x-heisenberg::ui.input data-hb-prov-new-label placeholder="MiniMax" />
                    <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.provider_format') }}</x-heisenberg::ui.field-label>
                    <x-heisenberg::ui.select data-hb-prov-new-format :options="$formatOptions" value="openai"
                        :aria-label="__('heisenberg::editor.ai.provider_format')" />
                    <span class="hb-aidialog__hint">{{ __('heisenberg::editor.ai.provider_format_hint') }}</span>
                    <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.base_url_label') }}</x-heisenberg::ui.field-label>
                    <x-heisenberg::ui.input data-hb-prov-new-url placeholder="https://api.minimax.io/v1" />
                    <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.key_env_label') }}</x-heisenberg::ui.field-label>
                    <x-heisenberg::ui.input data-hb-prov-new-env placeholder="HEISENBERG_AI_MINIMAX_KEY" />
                    <span class="hb-aidialog__hint">{{ __('heisenberg::editor.ai.key_env_optional') }}</span>
                    <div><x-heisenberg::ui.button variant="primary" data-hb-prov-add>{{ __('heisenberg::editor.ai.provider_add_button') }}</x-heisenberg::ui.button></div>
                </div>
            </div>
            <x-heisenberg::ui.custom-scrollbar container="[data-hb-ai-providers-scroll]" />
        </div>

        <div class="hb-aidialog__body" data-hb-tab-body="models" hidden>
            <div class="hb-aidialog__scroll" data-hb-ai-models-scroll>
                <p class="hb-aidialog__intro">{{ __('heisenberg::editor.ai.models_intro') }}</p>

                <div data-hb-model-list></div>
                <div class="hb-aidialog__empty" data-hb-model-empty>{{ __('heisenberg::editor.ai.model_none') }}</div>

                <div><x-heisenberg::ui.button variant="secondary" data-hb-model-add-toggle>{{ __('heisenberg::editor.ai.model_add') }}</x-heisenberg::ui.button></div>

                <div class="hb-aidialog__addform" data-hb-model-form hidden>
                    <span class="hb-aidialog__name" data-hb-model-form-title></span>
                    <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.model_id_label') }}</x-heisenberg::ui.field-label>
                    <x-heisenberg::ui.input data-hb-model-new-id placeholder="claude-opus-5" />
                    <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.model_label_label') }}</x-heisenberg::ui.field-label>
                    <x-heisenberg::ui.input data-hb-model-new-label placeholder="Opus 5" />
                    <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.model_provider_label') }}</x-heisenberg::ui.field-label>
                    <x-heisenberg::ui.select data-hb-model-new-provider :options="$providerOptions"
                        :aria-label="__('heisenberg::editor.ai.model_provider_label')" />
                    <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.effort_label') }}</x-heisenberg::ui.field-label>
                    <x-heisenberg::ui.select data-hb-model-new-effort :options="$effortOptions" value="high"
                        :aria-label="__('heisenberg::editor.ai.effort_label')" />
                    <span class="hb-aidialog__hint">{{ __('heisenberg::editor.ai.effort_hint') }}</span>
                    <div class="hb-aidialog__actions">
                        <x-heisenberg::ui.button variant="primary" data-hb-model-form-save>{{ __('heisenberg::editor.common.save') }}</x-heisenberg::ui.button>
                        <x-heisenberg::ui.button variant="ghost" data-hb-model-form-cancel>{{ __('heisenberg::editor.common.cancel') }}</x-heisenberg::ui.button>
                    </div>
                </div>
            </div>
            <x-heisenberg::ui.custom-scrollbar container="[data-hb-ai-models-scroll]" />
        </div>

        <div class="hb-aidialog__body" data-hb-tab-body="mcp" hidden>
            <div class="hb-aidialog__scroll" data-hb-ai-mcp-scroll>
                <p class="hb-aidialog__intro">{{ __('heisenberg::editor.ai.mcp_intro') }}</p>
                @unless ($mcpClientEnabled)
                    <span class="hb-aidialog__hint">{{ __('heisenberg::editor.ai.mcp_disabled') }}</span>
                @endunless

                <div data-hb-mcp-list></div>
                <div class="hb-aidialog__empty" data-hb-mcp-empty>{{ __('heisenberg::editor.ai.mcp_empty') }}</div>

                <x-heisenberg::ui.divider />
                <div class="hb-aidialog__addform" data-hb-mcp-form>
                    <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.mcp_add') }}</x-heisenberg::ui.field-label>
                    <x-heisenberg::ui.input data-hb-mcp-new-id placeholder="linear" />
                    <x-heisenberg::ui.input data-hb-mcp-new-url placeholder="https://mcp.linear.app/mcp" />
                    <x-heisenberg::ui.input data-hb-mcp-new-env placeholder="HEISENBERG_MCP_LINEAR_TOKEN" />
                    <span class="hb-aidialog__hint">{{ __('heisenberg::editor.ai.mcp_auth_hint') }}</span>
                    <div><x-heisenberg::ui.button variant="secondary" data-hb-mcp-add>{{ __('heisenberg::editor.ai.mcp_add_button') }}</x-heisenberg::ui.button></div>
                </div>
            </div>
            <x-heisenberg::ui.custom-scrollbar container="[data-hb-ai-mcp-scroll]" />
        </div>

        <div class="hb-aidialog__body" data-hb-tab-body="expose" hidden>
            <div class="hb-aidialog__scroll" data-hb-ai-expose-scroll>
                <p class="hb-aidialog__intro">{{ __('heisenberg::editor.ai.expose_intro') }}</p>
                @unless ($mcpServerEnabled)
                    <span class="hb-aidialog__hint">{{ __('heisenberg::editor.ai.expose_disabled') }}</span>
                @endunless
                <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.expose_endpoint') }}</x-heisenberg::ui.field-label>
                <span class="hb-aidialog__code">{{ url($mcpServerPath) }}</span>
                @if ($mcpTokensEnv !== '')
                    <span class="hb-aidialog__hint">{{ __('heisenberg::editor.ai.expose_tokens', ['env' => $mcpTokensEnv]) }}</span>
                @endif

                @if ($exposedTools !== [])
                    <x-heisenberg::ui.divider />
                    <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.expose_tools') }}</x-heisenberg::ui.field-label>
                    @foreach ($exposedTools as $tool)
                        <div class="hb-aidialog__row">
                            <span class="hb-aidialog__meta">
                                <span class="hb-aidialog__name">{{ $tool['name'] }}</span>
                                <span class="hb-aidialog__hint">{{ $tool['description'] }}</span>
                            </span>
                            <x-heisenberg::ui.status-tag :label="$tool['tier']" :status="$tool['tier'] === 'read' ? 'neutral' : 'success'" />
                        </div>
                    @endforeach
                @endif
            </div>
            <x-heisenberg::ui.custom-scrollbar container="[data-hb-ai-expose-scroll]" />
        </div>

        <div class="hb-aidialog__foot">
            <span class="hb-aidialog__status" data-hb-ai-status></span>
            <x-heisenberg::ui.button variant="primary" data-hb-ai-save>{{ __('heisenberg::editor.ai.save') }}</x-heisenberg::ui.button>
        </div>
    </div>

    <template data-hb-tmpl="provider">
        <div class="hb-aidialog__row hb-aidialog__row--stacked">
            <div class="hb-aidialog__rowhead">
                <span class="hb-aidialog__meta">
                    <span class="hb-aidialog__name" data-hb-prov-label></span>
                    <span class="hb-aidialog__hint" data-hb-prov-sub></span>
                </span>
                <span class="hb-aidialog__slot" data-hb-prov-status><x-heisenberg::ui.status-tag label="" status="neutral" /></span>
                <span class="hb-aidialog__slot"><x-heisenberg::ui.icon-button icon="caret-down" :label="__('heisenberg::editor.ai.provider_configure')" data-hb-prov-toggle /></span>
                <span class="hb-aidialog__slot"><x-heisenberg::ui.icon-button icon="trash-simple" :label="__('heisenberg::editor.ai.provider_remove')" data-hb-prov-remove /></span>
            </div>
            <div class="hb-aidialog__panel" data-hb-prov-panel hidden>
                <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.api_key_label') }}</x-heisenberg::ui.field-label>
                <x-heisenberg::ui.input data-hb-prov-key />
                <span class="hb-aidialog__hint" data-hb-prov-keyhint></span>
                <x-heisenberg::ui.field-label>{{ __('heisenberg::editor.ai.base_url_label') }}</x-heisenberg::ui.field-label>
                <x-heisenberg::ui.input data-hb-prov-url />
                <div class="hb-aidialog__actions">
                    <x-heisenberg::ui.button variant="secondary" data-hb-prov-savekey>{{ __('heisenberg::editor.ai.key_save') }}</x-heisenberg::ui.button>
                    <x-heisenberg::ui.button variant="secondary" data-hb-prov-discover>{{ __('heisenberg::editor.ai.discover_models') }}</x-heisenberg::ui.button>
                </div>
                <div class="hb-aidialog__tools" data-hb-prov-discovered hidden></div>
            </div>
        </div>
    </template>

    <template data-hb-tmpl="preset">
        <div class="hb-aidialog__row">
            <span class="hb-aidialog__meta">
                <span class="hb-aidialog__name" data-hb-preset-label></span>
                <span class="hb-aidialog__hint" data-hb-preset-sub></span>
            </span>
            <span class="hb-aidialog__slot"><x-heisenberg::ui.button variant="secondary" data-hb-preset-add>{{ __('heisenberg::editor.ai.preset_add') }}</x-heisenberg::ui.button></span>
        </div>
    </template>

    <template data-hb-tmpl="model">
        <div class="hb-aidialog__row">
            <span class="hb-aidialog__slot" data-hb-model-enabled><x-heisenberg::ui.toggle /></span>
            <span class="hb-aidialog__meta">
                <span class="hb-aidialog__name" data-hb-model-name></span>
                <span class="hb-aidialog__hint" data-hb-model-sub></span>
            </span>
            <span class="hb-aidialog__slot" data-hb-model-active-tag hidden>
                <x-heisenberg::ui.status-tag :label="__('heisenberg::editor.ai.model_in_use')" status="success" />
            </span>
            <span class="hb-aidialog__slot" data-hb-model-use><x-heisenberg::ui.button variant="secondary">{{ __('heisenberg::editor.ai.provider_use') }}</x-heisenberg::ui.button></span>
            <span class="hb-aidialog__slot"><x-heisenberg::ui.icon-button icon="pencil-simple" :label="__('heisenberg::editor.ai.model_edit')" data-hb-model-edit /></span>
            <span class="hb-aidialog__slot"><x-heisenberg::ui.icon-button icon="trash-simple" :label="__('heisenberg::editor.ai.model_remove')" data-hb-model-remove /></span>
        </div>
    </template>

    <template data-hb-tmpl="mcp">
        <div class="hb-aidialog__row hb-aidialog__row--stacked">
            <div class="hb-aidialog__rowhead">
                <span class="hb-aidialog__meta">
                    <span class="hb-aidialog__name" data-hb-mcp-label></span>
                    <span class="hb-aidialog__hint" data-hb-mcp-url></span>
                </span>
                <span class="hb-aidialog__slot" data-hb-mcp-enabled><x-heisenberg::ui.toggle /></span>
                <span class="hb-aidialog__slot"><x-heisenberg::ui.button variant="secondary" data-hb-mcp-test>{{ __('heisenberg::editor.ai.mcp_test') }}</x-heisenberg::ui.button></span>
                <span class="hb-aidialog__slot"><x-heisenberg::ui.icon-button icon="trash-simple" :label="__('heisenberg::editor.ai.mcp_remove')" data-hb-mcp-remove /></span>
            </div>
            <div class="hb-aidialog__tools" data-hb-mcp-tools hidden></div>
        </div>
    </template>

    <template data-hb-tmpl="tool">
        <label class="hb-aidialog__tool"><input type="checkbox"><span></span></label>
    </template>

    <template data-hb-tmpl="select-option">
        <button type="button" role="option" data-hb-select-option="" aria-selected="false" class="hb-select__option">
            <span></span>
            <span class="hb-select__check" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 14])
            </span>
        </button>
    </template>
</div>
