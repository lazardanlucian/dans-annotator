(function($){
    if (typeof AnnotateData === 'undefined') return;

        const ROOT = AnnotateData.root.replace(/\/?$/, '');
        const NONCE = AnnotateData.nonce || '';
        const CURRENT_USER = AnnotateData.current_user_id;
        const CURRENT_ACTOR = (AnnotateData.current_actor && typeof AnnotateData.current_actor === 'object') ? AnnotateData.current_actor : {
            id: CURRENT_USER || 0,
            is_collaborator: false,
            type: 'user'
        };
        const IS_COLLABORATOR_SESSION = !!AnnotateData.is_collaborator_session;
        const COLLABORATOR_CONTROLS_DISABLED = !!(AnnotateData && AnnotateData.disable_collaborator_controls);
        const ASSETS_URL = (AnnotateData && AnnotateData.assets_url) ? AnnotateData.assets_url.replace(/\/?$/, '/') : '';
        const SHOW_DONATE_BUTTON = (typeof AnnotateData.show_donate_button === 'undefined') ? true : !!AnnotateData.show_donate_button;
        const DONATE_PANEL_URL = ASSETS_URL + 'donate-panel.html';
        const CURRENT_PAGE_URL = (function(){
            try {
                const url = new URL(window.location.href);
                return url.origin + url.pathname;
            } catch(e) {
                const origin = window.location.origin || (window.location.protocol + '//' + window.location.host);
            return origin + window.location.pathname;
        }
    })();
    const THREAD_QUERY_PARAM = 'annotate-id';
    let pendingThreadIdFromQuery = getThreadIdFromQuery();
    let allThreadsCache = [];
    const L10N = (AnnotateData && AnnotateData.i18n) || {};

    function t(key, fallback, replacements){
        let str = (L10N && L10N[key]) || fallback || '';
        if (!replacements) return str;
        const list = Array.isArray(replacements) ? replacements.slice() : [replacements];
        let idx = 0;
        return str.replace(/%[sd]/g, function(){
            const val = typeof list[idx] !== 'undefined' ? list[idx] : '';
            idx++;
            return val;
        });
    }

    function getThreadIdFromQuery(){
        try {
            const params = new URLSearchParams(window.location.search || '');
            const value = params.get(THREAD_QUERY_PARAM);
            if (!value) return null;
            const parsed = parseInt(value, 10);
            return Number.isNaN(parsed) ? null : parsed;
        } catch(e) {
            return null;
        }
    }

    function buildThreadShareUrl(threadId){
        try {
            const url = new URL(window.location.href);
            url.searchParams.set(THREAD_QUERY_PARAM, threadId);
            return url.toString();
        } catch(e) {
            const base = window.location.origin + window.location.pathname;
            const glue = base.indexOf('?') === -1 ? '?' : '&';
            return base + glue + THREAD_QUERY_PARAM + '=' + threadId;
        }
    }

    let enabledChangeCallback = null;

    function buildHeaders(extra){
        const headers = Object.assign({}, extra || {});
        if (NONCE) {
            headers['X-WP-Nonce'] = NONCE;
        }
        return headers;
    }

    function enabled() {
        return localStorage.getItem('annotate-enabled') !== '0';
    }

    function setEnabled(v) {
        localStorage.setItem('annotate-enabled', v ? '1' : '0');
        $('body').toggleClass('annotate-enabled', !!v);
        $('#annotate-toggle-state').text(v ? t('state_on', 'On') : t('state_off', 'Off'));
        if (typeof enabledChangeCallback === 'function') {
            enabledChangeCallback(v);
        }
    }

    $(function(){
        // track current scope (element being annotated)
        let currentScope = null;
        let currentThread = null;
        let cachedThreads = [];
        let createMode = false;
        let lastEl = null;
        let focusedElement = null;
        let panelHideTimer = null;
        let activePanelThread = null;

        // initialize toggle label
        if ($('#annotate-toggle-state').length || IS_COLLABORATOR_SESSION) {
            const forceEnable = !!pendingThreadIdFromQuery || IS_COLLABORATOR_SESSION;
            const initial = enabled();
            if (forceEnable && !initial) {
                setEnabled(true);
            } else {
                setEnabled(initial);
            }
            if ($('#annotate-toggle-state').length) {
                $(document).on('click', '#wp-admin-bar-annotate-toggle a', function(e){
                    e.preventDefault();
                    e.stopPropagation();
                    const v = !enabled();
                    setEnabled(v);
                });
            }
        }

        const PANEL_MODES = { THREAD: 'thread', LIST: 'view-all' };
        const PANEL_ANIMATION_DURATION = 220;

        const $panel = $('#annotate-panel');
        const $threadView = $('#annotate-thread-view');
        const $viewAllView = $('#annotate-viewall-view');
        const $floatingControls = $('.annotate-floating-controls');
        const $floatingViewAll = $floatingControls.find('.annotate-floating-view');
        const $floatingCreate = $floatingControls.find('.annotate-floating-create');
        const $floatingCancel = $floatingControls.find('.annotate-floating-cancel');
        const $floatingDisconnect = $('#annotate-collab-disconnect');
        const $copyLinkButton = $('#annotate-copy-link');
        const $copyLinkHeader = $('#annotate-copy-link-header');
        const $autocomplete = $('#annotate-autocomplete');
        if ($autocomplete.length) {
            $('body').append($autocomplete);
            $autocomplete.on('mousedown click', function(ev){
                ev.stopPropagation();
            });
        }

        function setHiddenState($el, hidden){
            if (!$el || !$el.length) return;
            if (hidden) {
                $el.attr({ hidden: true, 'aria-hidden': 'true' });
            } else {
                $el.removeAttr('hidden').attr('aria-hidden', 'false');
            }
        }

        function updateFloatingCreateButtons(){
            if (!$floatingControls.length) return;
            if ($floatingCreate.length) {
                setHiddenState($floatingCreate, createMode);
            }
            if ($floatingCancel.length) {
                setHiddenState($floatingCancel, !createMode);
            }
        }

        function updateViewAllButton(missingCount){
            if (missingCount && missingCount > 0) {
                $floatingViewAll.text(t('view_all_hidden_label', 'View All (%d hidden)', missingCount));
            } else {
                $floatingViewAll.text(t('view_all_label', 'View All'));
            }
        }
        updateViewAllButton(0);

        function updateFloatingVisibility(){
            $floatingControls.toggleClass('annotate-floating-visible', enabled());
        }

        function clearFocus(){
            if (focusedElement && focusedElement.classList) {
                focusedElement.classList.remove('annotate-focus');
            }
            focusedElement = null;
        }

        function focusElement(el, opts){
            if (!el || !el.classList) return;
            if (focusedElement && focusedElement !== el && focusedElement.classList) {
                focusedElement.classList.remove('annotate-focus');
            }
            focusedElement = el;
            el.classList.add('annotate-focus');
            if (opts && opts.scroll !== false) {
                try { el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' }); } catch(e) {}
            }
        }

        function caretCoordinates($ta, position){
            const ta = $ta && $ta[0];
            if (!ta) return null;
            const style = window.getComputedStyle(ta);
            const rect = ta.getBoundingClientRect();
            const mirror = document.createElement('div');
            const props = [
                'font-size','font-family','font-weight','font-style','letter-spacing',
                'text-transform','text-indent','white-space','word-break','padding-top',
                'padding-right','padding-bottom','padding-left','border-top-width','border-right-width',
                'border-bottom-width','border-left-width','box-sizing','line-height','text-align'
            ];
            props.forEach(prop => {
                mirror.style[prop] = style[prop];
            });
            mirror.style.position = 'absolute';
            mirror.style.visibility = 'hidden';
            mirror.style.whiteSpace = 'pre-wrap';
            mirror.style.wordWrap = 'break-word';
            mirror.style.width = rect.width + 'px';
            mirror.style.top = rect.top + window.scrollY + 'px';
            mirror.style.left = rect.left + window.scrollX + 'px';
            mirror.style.pointerEvents = 'none';
            const text = ta.value.substring(0, typeof position === 'number' ? position : ta.value.length);
            mirror.textContent = text;
            const span = document.createElement('span');
            span.textContent = '\u200b';
            mirror.appendChild(span);
            document.body.appendChild(mirror);
            const spanRect = span.getBoundingClientRect();
            const coords = {
                top: spanRect.bottom + window.scrollY,
                left: spanRect.left + window.scrollX
            };
            document.body.removeChild(mirror);
            return coords;
        }

        function setPanelMode(mode){
            if (!$panel.length) return;
            $panel.attr('data-panel-mode', mode || '');
            $panel.toggleClass('annotate-panel-view-list', mode === PANEL_MODES.LIST);
        }

        function showThreadView(){
            setHiddenState($threadView, false);
            setHiddenState($viewAllView, true);
        }

        function showViewAllView(){
            setHiddenState($viewAllView, false);
            setHiddenState($threadView, true);
        }

        function hideAutocomplete(){
            if (!$autocomplete.length) return;
            $autocomplete.removeClass('annotate-autocomplete-visible').empty().attr('aria-hidden', 'true');
            $('#annotate-content').off('keydown.annotateAC');
        }

        function resetThreadView(){
            if (!$threadView.length) return;
            $threadView.find('[data-slot="subtitle"]').text(t('thread_open_subtitle', 'Discuss this element'));
            $threadView.find('.annotate-selector-text').text('');
            $('#annotate-comments').empty();
            $('#annotate-content').val('');
            setHiddenState($('#annotate-back-to-list'), false);
            setHiddenState($('#annotate-cancel-new'), false);
            setHiddenState($('#annotate-close-thread'), false);
            setHiddenState($('#annotate-close-confirm'), false);
            setHiddenState($('#annotate-delete-thread'), false);
            setHiddenState($('#annotate-delete-confirm'), false);
            setHiddenState($copyLinkButton, true);
            if ($copyLinkHeader.length) {
                $copyLinkHeader.addClass('annotate-btn-icon-hidden').prop('disabled', true);
            }
            $threadView.find('.annotate-confirm-panel').addClass('annotate-confirm-hidden');
            applyConfirmSpaceState($panel);
        }

        function openPanelContainer(mode){
            if (!$panel.length) return;
            window.clearTimeout(panelHideTimer);
            setPanelMode(mode);
            $panel.addClass('annotate-panel-visible');
            requestAnimationFrame(function(){
                $panel.addClass('open').attr('aria-hidden', 'false');
            });
        }

        function closePanelContainer(){
            if (!$panel.length) return;
            $panel.removeClass('open');
            window.clearTimeout(panelHideTimer);
            panelHideTimer = window.setTimeout(function(){
                $panel.removeClass('annotate-panel-visible annotate-panel-view-list');
                $panel.attr('aria-hidden', 'true').removeAttr('data-panel-mode');
                setHiddenState($threadView, true);
                setHiddenState($viewAllView, true);
            }, PANEL_ANIMATION_DURATION);
        }

        function closeAndResetPanel(){
            hideAutocomplete();
            closePanelContainer();
            resetThreadView();
            activePanelThread = null;
        }

        function setCreateModeState(state){
            const newState = !!state && enabled();
            if (createMode === newState) {
                syncSelectingVisualState();
                return;
            }
            createMode = newState;
            $('body').toggleClass('annotate-creating', createMode);
            updateFloatingCreateButtons();
            if (createMode) {
                closeAndResetPanel();
            }
            if (!createMode && lastEl && lastEl.classList) {
                lastEl.classList.remove('annotate-highlight');
                lastEl = null;
            }
            syncSelectingVisualState();
        }

        $floatingViewAll.on('click', function(e){
            e.preventDefault();
            if (!enabled()) return;
            openViewAllPanel();
        });

        if ($floatingDisconnect.length) {
            $floatingDisconnect.on('click', function(e){
                e.preventDefault();
                const confirmMsg = t('collab_disconnect_confirm', 'Disconnect from this session? You will need to reopen the link from your email.');
                if (!window.confirm(confirmMsg)) {
                    return;
                }
                fetch( ROOT + '/session/disconnect', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: buildHeaders({ 'Content-Type': 'application/json' })
                }).catch(()=>({})).then(()=>{
                    const successMsg = t('collab_disconnect_success', 'Disconnected. Please revisit the email link to continue annotating.');
                    window.alert(successMsg);
                    setEnabled(false);
                    window.setTimeout(function(){
                        window.location.reload();
                    }, 250);
                });
            });
        }

        $(document).on('click', '#annotate-close-panel, #annotate-panel-close', function(e){
            e.preventDefault();
            closeAndResetPanel();
            handlePanelClose();
        });

        $floatingCreate.on('click', function(e){
            e.preventDefault();
            if (!enabled()) return;
            if (currentThread && currentThread.id === null) {
                handlePanelClose();
                return;
            }
            setCreateModeState(!createMode);
        });

        $floatingCancel.on('click', function(e){
            e.preventDefault();
            if (!enabled()) return;
            handlePanelClose();
        });

        const sharedCopyHandler = function(e){
            e.preventDefault();
            copyCurrentThreadLink();
        };
        $copyLinkButton.on('click', sharedCopyHandler);
        $copyLinkHeader.on('click', sharedCopyHandler);

        function ensureDonateModal(){
            if (!SHOW_DONATE_BUTTON) return $();
            let $modal = $('#annotate-donate-modal');
            if ($modal.length) return $modal;

            const donateMarkup = `
                <div id="annotate-donate-modal" class="annotate-donate-modal" hidden aria-hidden="true">
                    <div class="annotate-donate-backdrop"></div>
                    <div class="annotate-donate-card" role="dialog" aria-modal="true" aria-label="Donate to Dan\'s Annotator">
                        <button type="button" class="annotate-donate-close" aria-label="Close donation modal">✕</button>
                        <iframe class="annotate-donate-iframe" src="${DONATE_PANEL_URL}" title="Donate to Dan\'s Annotator" loading="lazy" referrerpolicy="no-referrer"></iframe>
                    </div>
                </div>
            `;
            $('body').append(donateMarkup);
            $modal = $('#annotate-donate-modal');

            const $close = $modal.find('.annotate-donate-close');
            const $backdrop = $modal.find('.annotate-donate-backdrop');

            function hideModal(){
                $modal.attr('hidden', true).attr('aria-hidden', 'true').removeClass('open');
                $('body').removeClass('annotate-donate-open');
            }

            function showModal(){
                $modal.removeAttr('hidden').attr('aria-hidden', 'false').addClass('open');
                $('body').addClass('annotate-donate-open');
            }

            $modal.data('show', showModal);
            $modal.data('hide', hideModal);

            $close.on('click', hideModal);
            $backdrop.on('click', hideModal);
            $(document).on('keydown.annotateDonate', function(ev){
                if (ev.key === 'Escape' && $modal.hasClass('open')) {
                    hideModal();
                }
            });

            return $modal;
        }

        function openDonateModal(){
            if (!SHOW_DONATE_BUTTON) return;
            const $modal = ensureDonateModal();
            if (!$modal.length) return;
            const show = $modal.data('show');
            if (typeof show === 'function') {
                show();
            }
        }

        const $floatingDonate = (function(){
            if (!SHOW_DONATE_BUTTON || !$floatingControls.length) return $();
            let $btn = $floatingControls.find('.annotate-floating-donate');
            if ($btn.length) return $btn;
            $btn = $('<button type="button" class="annotate-floating-btn annotate-floating-donate" aria-haspopup="dialog">Donate</button>');
            $floatingControls.append($btn);
            $btn.on('click', function(e){
                e.preventDefault();
                if (!enabled()) return;
                openDonateModal();
            });
            return $btn;
        })();

        enabledChangeCallback = function(state){
            updateFloatingVisibility();
            if (!state) {
                resetInteractionState();
                $('.annotate-highlight').removeClass('annotate-highlight');
                if (lastEl && lastEl.classList) {
                    lastEl.classList.remove('annotate-highlight');
                    lastEl = null;
                }
                clearFocus();
                closeAndResetPanel();
                $('[data-annotate-thread-id]').removeAttr('data-annotate-thread-id').removeClass('annotate-has-annotation');
                $('.annotate-badge').remove();
                const $donateModal = $('#annotate-donate-modal');
                if ($donateModal.length) {
                    $donateModal.attr('hidden', true).attr('aria-hidden', 'true').removeClass('open');
                    $('body').removeClass('annotate-donate-open');
                }
                cachedThreads = [];
                allThreadsCache = [];
            } else {
                loadAllBadges().then(maybeOpenThreadFromQuery);
            }
        };
        updateFloatingVisibility();
        updateFloatingCreateButtons();

        function isSelecting(){
            return createMode || (currentThread && currentThread.id === null);
        }

        function syncSelectingVisualState(){
            const selecting = isSelecting();
            const isDraft = currentThread && currentThread.id === null;
            $('body')
                .toggleClass('annotate-selecting-existing-disabled', selecting)
                .toggleClass('annotate-selecting-child-mode', selecting && isDraft);
        }

        function clearDraftSelection(){
            if (currentScope && currentThread && currentThread.id === null) {
                $(currentScope).children('.annotate-badge').remove();
            }
            currentScope = null;
            currentThread = null;
            clearFocus();
            syncSelectingVisualState();
        }

        function resetInteractionState(){
            clearDraftSelection();
            setCreateModeState(false);
        }

        // highlight on hover (while selecting a new annotation)
        document.addEventListener('mousemove', function(e){
            if (!enabled()) return;
            const selecting = isSelecting();
            if (!selecting) {
                if (lastEl && lastEl.classList) {
                    lastEl.classList.remove('annotate-highlight');
                    lastEl = null;
                }
                return;
            }
            let el = e.target;
            // exclude admin-bar, annotate panel, floating controls, and donate modal
            if ( $(el).closest('#wp-adminbar, #wpadminbar, #annotate-panel, .annotate-floating-controls, .annotate-donate-modal').length ) return;
            // exclude badge elements themselves
            if (el.classList && el.classList.contains('annotate-badge')) return;
            const ownsThread = elementOwnsThread(el);
            if (ownsThread) {
                if (lastEl && lastEl.classList) {
                    lastEl.classList.remove('annotate-highlight');
                    lastEl = null;
                }
                return;
            }
            if (el === lastEl) return;
            if (lastEl) lastEl.classList && lastEl.classList.remove('annotate-highlight');
            el.classList && el.classList.add('annotate-highlight');
            lastEl = el;
        }, { passive: true });

        // on click create badge and open panel
        document.addEventListener('click', function(e){
            if (!enabled()) return;
            // exclude admin-bar, floating controls, annotate panel, and donate modal
            if ( $(e.target).closest('#wp-adminbar, #wpadminbar, #annotate-panel, .annotate-floating-controls, .annotate-donate-modal').length ) return;
            // if changing scope before posting, remove stale badge from previous scope
            if (currentScope && currentThread && currentThread.id === null && currentScope !== e.target) {
                $(currentScope).children('.annotate-badge').remove();
            }
            let el = e.target;
            const selecting = isSelecting();
            // if badge clicked, use its parent as target (unless selecting a new one)
            try {
                if (el.classList && el.classList.contains('annotate-badge')) {
                    if (selecting) {
                        e.preventDefault();
                        e.stopPropagation();
                        return;
                    }
                    el = el.parentElement || el;
                } else if (el.closest) {
                    const b = el.closest('.annotate-badge');
                    if (b) {
                        if (selecting) {
                            e.preventDefault();
                            e.stopPropagation();
                            return;
                        }
                        el = b.parentElement || el;
                    }
                }
            } catch(e) {}
            const existingThread = findThreadForTarget(el);
            const ownsThread = elementOwnsThread(el);
            if (existingThread && (!selecting || ownsThread)) {
                e.preventDefault();
                e.stopPropagation();
                setCreateModeState(false);
                currentScope = el;
                currentThread = existingThread;
                openThread(existingThread, { scroll: false });
                focusElement(el, { scroll: false });
                return;
            }
            if (!selecting) return;
            e.preventDefault();
            e.stopPropagation();
            beginSelectionForElement(el);
        }, true);

        function beginSelectionForElement(el){
            const selector = generateSelector(el);
            const tempThread = { id: null, selector: selector, post_url: CURRENT_PAGE_URL, is_closed: false };
            currentScope = el;
            currentThread = tempThread;
            syncSelectingVisualState();
            renderTemporaryBadge(el);
            focusElement(el, { scroll: false });
            renderThreadPanel(tempThread, selector);
        }

        function dismissPanelFromPage(){
            handlePanelClose();
        }

        document.addEventListener('contextmenu', function(e){
            if (!enabled() || !isSelecting()) return;
            e.preventDefault();
            e.stopPropagation();
            dismissPanelFromPage();
        }, true);

        document.addEventListener('keydown', function(e){
            if (!enabled() || !isSelecting()) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                dismissPanelFromPage();
            }
        }, true);

        document.addEventListener('click', function(e){
            if (!enabled()) return;
            const $panel = $('#annotate-panel');
            if (!$panel.length || !$panel.hasClass('open')) return;
            if ( $(e.target).closest('#annotate-panel, .annotate-floating-controls, #wp-adminbar, #wpadminbar, .annotate-autocomplete, .annotate-donate-modal').length ) return;
            dismissPanelFromPage();
        });

        function getOrderForThread(thread){
            if (!thread || !thread.id) return null;
            const idx = cachedThreads.findIndex(t => t.id === thread.id);
            return idx === -1 ? null : idx + 1;
        }

        function getHighestBadgeNumber(){
            const possible = [];
            if (cachedThreads.length) {
                possible.push(cachedThreads.length);
            }
            if (allThreadsCache && allThreadsCache.length) {
                const openCount = allThreadsCache.filter(t => !isThreadClosed(t)).length;
                if (openCount) {
                    possible.push(openCount);
                }
            }
            document.querySelectorAll('.annotate-badge').forEach(badge => {
                const n = parseInt(badge.textContent, 10);
                if (!Number.isNaN(n)) {
                    possible.push(n);
                }
            });
            return possible.length ? Math.max.apply(null, possible) : 0;
        }

        function getNextBadgeNumber(){
            return getHighestBadgeNumber() + 1;
        }

        function isThreadClosed(thread){
            if (!thread || typeof thread.is_closed === 'undefined' || thread.is_closed === null) return false;
            if (typeof thread.is_closed === 'boolean') return thread.is_closed;
            if (typeof thread.is_closed === 'string') return thread.is_closed === '1';
            return Number(thread.is_closed) === 1;
        }

        function fetchThreadsForCurrentPage(){
            const urls = [CURRENT_PAGE_URL];
            const fullUrl = window.location.href;
            if (fullUrl !== CURRENT_PAGE_URL) {
                urls.push(fullUrl);
            }
            const requests = urls.map(url => {
                return fetch( ROOT + '/threads/query', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: buildHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ url: url })
                }).then(r => r.json()).catch(() => []);
            });
            return Promise.all(requests).then(results => {
                const seen = new Map();
                const merged = [];
                results.forEach(list => {
                    list.forEach(thread => {
                        if (!seen.has(thread.id)) {
                            seen.set(thread.id, true);
                            merged.push(thread);
                        }
                    });
                });
                return merged;
            });
        }

        function findThreadForTarget(target){
            if (!target) return null;
            try {
                const el = target.closest('[data-annotate-thread-id]');
                if (!el) return null;
                const id = el.getAttribute('data-annotate-thread-id');
                if (!id) return null;
                return cachedThreads.find(t => String(t.id) === String(id)) || null;
            } catch(e) {
                return null;
            }
        }

        function elementOwnsThread(el){
            try {
                return el && el.getAttribute && el.getAttribute('data-annotate-thread-id');
            } catch(e) {
                return null;
            }
        }

        function ensureRelativeTarget(el){
            try {
                const cs = window.getComputedStyle(el);
                if (!cs || !cs.position || cs.position === 'static') {
                    el.classList.add('annotate-relative-target');
                }
            } catch(e) {}
        }

        function ensureBadgeElement(el){
            if (!el) return null;
            ensureRelativeTarget(el);
            let $badge = $(el).children('.annotate-badge');
            if ($badge.length === 0) {
                $badge = $('<span class="annotate-badge"></span>');
                $(el).append($badge);
            }
            return $badge;
        }

        function renderTemporaryBadge(el){
            const $badge = ensureBadgeElement(el);
            if (!$badge || !$badge.length) return;
            $badge.text(getNextBadgeNumber());
        }

        // Load existing badges on page load
        if (enabled()) {
            setTimeout(function(){
                loadAllBadges().then(maybeOpenThreadFromQuery);
            }, 500);
        }

        // Improved selector generator: tries id, unique class, data attributes, climbs tree and tests uniqueness. Falls back to a generated data-annotate-id.
        function generateSelector(el) {
            if (!el || el.nodeType !== 1) return '';

            // 1) id
            if (el.id) return '#' + CSS.escape(el.id);

            // 2) any data-annotate-id
            if (el.hasAttribute('data-annotate-id')) return `[data-annotate-id="${el.getAttribute('data-annotate-id')}"]`;

            // 3) try single class on element
            const classes = Array.from(el.classList || []).filter(cls => !/^annotate-/.test(cls));
            for (const cls of classes) {
                const sel = `${el.tagName.toLowerCase()}.${CSS.escape(cls)}`;
                try {
                    if (document.querySelectorAll(sel).length === 1) return sel;
                } catch(e) {}
            }

            // 4) try attributes like name, title, alt, placeholder
            const attrs = ['name','title','alt','placeholder','aria-label'];
            for (const a of attrs) {
                if (el.getAttribute(a)) {
                    const val = el.getAttribute(a);
                    const sel = `${el.tagName.toLowerCase()}[${a}="${CSS.escape(val)}"]`;
                    try { if (document.querySelectorAll(sel).length === 1) return sel; } catch(e) {}
                }
            }

            // 5) climb up building path, testing for uniqueness at each step
            let node = el;
            const parts = [];
            while (node && node.nodeType === 1) {
                let part = node.tagName.toLowerCase();
                if (node.classList && node.classList.length) {
                    const usable = Array.from(node.classList).filter(cls => !/^annotate-/.test(cls));
                    if (usable.length) {
                        part += '.' + usable.map(cls => CSS.escape(cls)).join('.');
                    }
                }
                const parent = node.parentElement;
                if (parent) {
                    const sameTag = Array.from(parent.children).filter(c => c.tagName === node.tagName);
                    if (sameTag.length > 1) {
                        const position = sameTag.indexOf(node) + 1;
                        part += `:nth-of-type(${position})`;
                    }
                }
                parts.unshift(part);
                const testSel = parts.join(' > ');
                try {
                    if (document.querySelectorAll(testSel).length === 1) return testSel;
                } catch(e) {}
                node = parent;
            }

            // 6) fallback: longest path we built (html > ... > element)
            return parts.join(' > ');
        }

        function placeBadge(el, thread, order){
            const $badge = ensureBadgeElement(el);
            if (!$badge || !$badge.length) return;
            if (thread && thread.id) {
                try {
                    el.setAttribute('data-annotate-thread-id', thread.id);
                    el.classList.add('annotate-has-annotation');
                } catch(e) {}
            }
            let label = typeof order === 'number' ? order : getOrderForThread(thread);
            if (!label) label = getNextBadgeNumber();
            $badge.text(label);
        }

        // Load and render all badges for threads on current page
        function loadAllBadges(){
            return fetchThreadsForCurrentPage().then(list => {
                allThreadsCache = Array.isArray(list) ? list.slice() : [];
                document.querySelectorAll('[data-annotate-thread-id]').forEach(el => {
                    try {
                        el.removeAttribute('data-annotate-thread-id');
                        el.classList.remove('annotate-has-annotation');
                    } catch(e) {}
                });
                document.querySelectorAll('.annotate-badge').forEach(badge => {
                    if (badge && badge.parentNode) {
                        badge.parentNode.removeChild(badge);
                    }
                });
                const sorted = list.slice().sort((a, b) => (a.id || 0) - (b.id || 0));
                const visible = [];
                let renderableCount = 0;
                sorted.forEach(thread => {
                    const normalized = Object.assign({}, thread, { is_closed: isThreadClosed(thread) });
                    if (normalized.is_closed) return;
                    renderableCount++;
                    try {
                        const el = document.querySelector(normalized.selector);
                        if (el) {
                            visible.push(normalized);
                            placeBadge(el, normalized, visible.length);
                        }
                    } catch(e) {}
                });
                cachedThreads = visible;
                const missingCount = Math.max(renderableCount - visible.length, 0);
                updateViewAllButton(missingCount);
                return list;
            });
        }

        function findThreadInCache(id){
            if (!id || !allThreadsCache || !allThreadsCache.length) return null;
            return allThreadsCache.find(t => String(t.id) === String(id)) || null;
        }

        function maybeOpenThreadFromQuery(){
            if (!pendingThreadIdFromQuery) return;
            const thread = findThreadInCache(pendingThreadIdFromQuery);
            if (thread) {
                pendingThreadIdFromQuery = null;
                openThread(thread, { scroll: true });
            }
        }

        function closeThreadRequest(thread){
            if (!thread || !thread.id) return Promise.reject(new Error('missing_thread'));
            return fetch( ROOT + '/threads/' + thread.id + '/close', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders()
            }).then(r => {
                if (!r.ok) throw new Error('request_failed');
                return r.json();
            }).then(() => {
                thread.is_closed = true;
                return loadComments(thread.id).then(() => {
                    updateBadgeForSelector();
                    openThread(thread, { scroll: false });
                });
            });
        }

        function deleteThreadRequest(thread){
            if (!thread || !thread.id) return Promise.reject(new Error('missing_thread'));
            return fetch( ROOT + '/threads/' + thread.id, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: buildHeaders()
            }).then(r => {
                if (!r.ok) throw new Error('request_failed');
                return r.json();
            }).then(res => {
                if (!res.deleted) throw new Error('delete_failed');
                handlePanelClose();
                updateBadgeForSelector();
            });
        }

        const CONFIRM_PANEL_CONFIG = {
            close: {
                key: 'close',
                panelId: 'annotate-close-confirm',
                triggerId: 'annotate-close-thread',
                confirmId: 'annotate-confirm-close',
                cancelId: 'annotate-cancel-close',
                message: t('confirm_close_message', 'Really close this thread? No new comments can be added.'),
                confirmLabel: 'Yes, Close',
                confirmClass: 'annotate-btn annotate-btn-primary',
                shouldRender: thread => !!thread.id && !isThreadClosed(thread),
                onConfirm: closeThreadRequest,
                errorMessage: t('error_close_thread', 'Could not close thread.')
            },
            delete: {
                key: 'delete',
                panelId: 'annotate-delete-confirm',
                triggerId: 'annotate-delete-thread',
                confirmId: 'annotate-confirm-delete',
                cancelId: 'annotate-cancel-delete',
                message: t('confirm_delete_message', 'Really delete this thread? This cannot be undone.'),
                confirmLabel: 'Yes, Delete',
                confirmClass: 'annotate-btn annotate-btn-danger',
                shouldRender: thread => !!thread.id && isThreadClosed(thread),
                onConfirm: deleteThreadRequest,
                errorMessage: t('error_delete_thread', 'Failed to delete thread.')
            }
        };

        function applyConfirmSpaceState($panel){
            if (!$panel || !$panel.length) return;
            const hasConfirm = $panel.find('.annotate-confirm-panel').filter(function(){
                return !$(this).hasClass('annotate-confirm-hidden') && !this.hidden;
            }).length > 0;
            $panel.toggleClass('annotate-panel-reserve-confirm', hasConfirm);
        }

        function configureThreadView(thread, selector){
            if (!$threadView.length) return;
            showThreadView();
            const isClosed = isThreadClosed(thread);
            const hasId = !!thread.id;
            const isCollab = COLLABORATOR_CONTROLS_DISABLED;
            const canClose = hasId && !isClosed && !isCollab;
            const canDelete = hasId && isClosed && !isCollab;
            $threadView.find('[data-slot="subtitle"]').text(isClosed ? t('thread_closed_subtitle', 'Thread is closed') : t('thread_open_subtitle', 'Discuss this element'));
            $threadView.find('.annotate-selector-text').text(selector || '');
            setHiddenState($('#annotate-back-to-list'), !hasId);
            setHiddenState($('#annotate-cancel-new'), hasId);
            const $closeButton = $('#annotate-close-thread');
            const $closeConfirm = $('#annotate-close-confirm');
            setHiddenState($closeButton, !canClose);
            setHiddenState($closeConfirm, !canClose);
            if (canClose) {
                $closeConfirm.addClass('annotate-confirm-hidden');
            } else {
                $closeConfirm.addClass('annotate-confirm-hidden');
            }
            const $deleteButton = $('#annotate-delete-thread');
            const $deleteConfirm = $('#annotate-delete-confirm');
            setHiddenState($deleteButton, !canDelete);
            setHiddenState($deleteConfirm, !canDelete);
            if (canDelete) {
                $deleteConfirm.addClass('annotate-confirm-hidden');
            } else {
                $deleteConfirm.addClass('annotate-confirm-hidden');
            }
            setHiddenState($copyLinkButton, true);
            if ($copyLinkHeader.length) {
                $copyLinkHeader.toggleClass('annotate-btn-icon-hidden', !hasId);
                $copyLinkHeader.prop('disabled', !hasId);
            }
            applyConfirmSpaceState($panel);
        }

        function scrollCommentsToBottom(){
            const $c = $('#annotate-comments');
            if ($c.length) {
                $c.scrollTop($c[0].scrollHeight);
            }
        }

        function renderThreadPanel(thread, selector){
            if (!$panel.length) return;
            activePanelThread = thread;
            configureThreadView(thread, selector);
            openPanelContainer(PANEL_MODES.THREAD);
            const loadPromise = thread.id ? loadComments(thread.id) : Promise.resolve();
            const finish = function(){
                scrollCommentsToBottom();
                initializeConfirmPanels(thread);
            };
            loadPromise.then(finish).catch(finish);
        }

        function escapeHtml(str){ return String(str).replace(/[&<>"]/g, function(s){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[s]; }); }

        function escapeRegExp(str){ return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

        function buildTagPlaceholder(tag){
            if (!tag || typeof tag.ID === 'undefined') {
                return '';
            }
            const prefix = tag.is_collaborator ? 'c' : 'u';
            return `tag://${prefix}${tag.ID}`;
        }

        function highlightMentions(text, tags){
            if (!text) return '';
            let output = text;
            const hasTokens = output.indexOf('tag://') !== -1;
            if (hasTokens) {
                if (Array.isArray(tags) && tags.length) {
                    tags.forEach(tag => {
                        if (!tag) return;
                        const placeholder = buildTagPlaceholder(tag);
                        if (!placeholder) return;
                        const pattern = new RegExp(escapeRegExp(placeholder), 'gi');
                        const userLabel = buildUserLabel(tag);
                        const titleAttr = userLabel.title ? ` title="${userLabel.title}"` : '';
                        const mentionMarkup = `<span class="annotate-mention"${titleAttr}>@${userLabel.label}</span>`;
                        output = output.replace(pattern, mentionMarkup);
                    });
                }
                output = output.replace(/tag:\/\/[a-z]?\d+/gi, '@');
                return output;
            }
            if (!Array.isArray(tags) || tags.length === 0) return output;
            tags.forEach(tag => {
                if (!tag) return;
                const identifier = tag.user_email || tag.user_login;
                if (!identifier) return;
                const boundary = tag.user_email ? '' : '(?![\\w\\-])';
                const pattern = new RegExp('@' + escapeRegExp(identifier) + boundary, 'gi');
                output = output.replace(pattern, function(match){
                    const emailInfo = tag.user_email ? deriveEmailLabel(tag.user_email) : null;
                    const displayText = emailInfo ? '@' + emailInfo.prefix : match;
                    const titleValue = tag.user_email ? '@' + tag.user_email : '@' + identifier;
                    const titleAttr = titleValue ? ` title="${escapeHtml(titleValue)}"` : '';
                    return `<span class="annotate-mention"${titleAttr}>${escapeHtml(displayText)}</span>`;
                });
            });
            return output;
        }

        function linkifyText(text){
            const urlRegex = /(https?:\/\/[^\s<]+)/gi;
            return text.replace(urlRegex, function(url){
                let hostLabel = url;
                try {
                    const parsed = new URL(url);
                    hostLabel = parsed.host;
                    if (parsed.pathname && parsed.pathname !== '/' ) hostLabel += '/…';
                } catch(e) {}
                const safeUrl = escapeHtml(url);
                const display = escapeHtml(hostLabel);
                return `<a href="${safeUrl}" target="_blank" rel="noopener noreferrer">${display}</a>`;
            });
        }

        function deriveEmailLabel(value){
            if (!value) return null;
            const str = String(value);
            if (str.indexOf('@') === -1) return null;
            const prefix = str.split('@')[0] || str;
            return { prefix, full: str };
        }

        function formatCommentBody(cm){
            if (!cm) return '';
            const holder = document.createElement('div');
            holder.innerHTML = cm.content || '';
            let text = holder.textContent || holder.innerText || '';
            text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            text = escapeHtml(text);
            text = highlightMentions(text, cm.tags || []);
            text = linkifyText(text);
            return text.replace(/\n/g, '<br>');
        }

        function buildUserLabel(user){
            if (!user) {
                const fallback = escapeHtml(t('snippet_unknown_user', 'User'));
                return { label: fallback, title: '' };
            }
            const email = user.user_email ? String(user.user_email) : '';
            if (email) {
                const info = deriveEmailLabel(email);
                if (info) {
                    return { label: escapeHtml(info.prefix), title: escapeHtml(email) };
                }
                return { label: escapeHtml(email), title: escapeHtml(email) };
            }
            const displayRaw = user.display_name || t('snippet_unknown_user', 'User');
            const displayInfo = deriveEmailLabel(displayRaw);
            if (displayInfo) {
                return { label: escapeHtml(displayInfo.prefix), title: escapeHtml(displayRaw) };
            }
            return { label: escapeHtml(displayRaw), title: '' };
        }

        function buildLabelFromText(text){
            if (!text) return { label: '', title: '' };
            const info = deriveEmailLabel(text);
            if (info) {
                return { label: escapeHtml(info.prefix), title: escapeHtml(text) };
            }
            return { label: escapeHtml(text), title: '' };
        }

        function actorsMatchCurrent(actor){
            if (!actor) return false;
            return String(actor.ID) === String(CURRENT_ACTOR.id || 0) &&
                !!actor.is_collaborator === !!CURRENT_ACTOR.is_collaborator;
        }

        function loadComments(thread_id){
            const $c = $('#annotate-comments');
            if (!thread_id) {
                $c.empty();
                return Promise.resolve();
            }
            return fetch( ROOT + '/comments/query', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: buildHeaders({ 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' }),
                body: JSON.stringify({ thread_id: thread_id })
            } )
                .then(r=>r.json())
                .then(list => {
                    $c.empty();
                    list.forEach(function(cm){
                        const body = formatCommentBody(cm);
                        const isOutgoing = cm.user ? actorsMatchCurrent(cm.user) : false;
                        const direction = isOutgoing ? '🡕' : '🡖';
                        const userLabel = buildUserLabel(cm.user);
                        const titleAttr = userLabel.title ? ` title="${userLabel.title}"` : '';
                        const html = `<div class="annotate-comment"><div class="annotate-comment-meta"><strong${titleAttr}>${direction} ${userLabel.label}</strong> <span class="time">${cm.created_at || ''}</span></div><div class="annotate-comment-body">${body}</div></div>`;
                        $c.append(html);
                    });
                    if ($c.length) {
                        $c.scrollTop($c[0].scrollHeight);
                    }
                }).catch(()=>{ $c.empty(); });
        }

        function isDraftThread(thread){
            return !!(thread && !thread.id);
        }

        function ensureThreadPersisted(thread){
            if (!isDraftThread(thread)) {
                return Promise.resolve(thread);
            }
            return fetch( ROOT + '/threads', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ url: CURRENT_PAGE_URL, selector: thread.selector })
            }).then(r=>r.json()).then(obj => {
                thread.id = obj.id;
                configureThreadView(thread, thread.selector);
                return thread;
            });
        }

        function persistComment(thread, content){
            return fetch( ROOT + '/comments', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ thread_id: thread.id, content: content, url: CURRENT_PAGE_URL })
            }).then(r=>r.json()).then(() => {
                $('#annotate-content').val('');
                return loadComments(thread.id).then(() => {
                    scrollCommentsToBottom();
                    currentScope = null;
                    currentThread = null;
                    updateBadgeForSelector();
                    setCreateModeState(false);
                    configureThreadView(thread, thread.selector);
                });
            });
        }

        function handleComposerSubmit(){
            const thread = activePanelThread;
            if (!thread) return;
            const content = $('#annotate-content').val();
            if (!content) {
                alert(t('alert_write_comment', 'Please write a comment'));
                return;
            }
            hideAutocomplete();
            ensureThreadPersisted(thread).then(saved => persistComment(saved, content));
        }

        function copyCurrentThreadLink(){
            const thread = activePanelThread;
            if (!thread || !thread.id) {
                return;
            }
            const url = buildThreadShareUrl(thread.id);
            const afterCopy = () => {
                animateCopyIcon($copyLinkHeader);
                animateCopyIcon($copyLinkButton);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(afterCopy).catch(afterCopy);
                return;
            }
            try {
                const temp = document.createElement('textarea');
                temp.value = url;
                temp.setAttribute('readonly', '');
                temp.style.position = 'absolute';
                temp.style.left = '-9999px';
                document.body.appendChild(temp);
                temp.select();
                document.execCommand('copy');
                document.body.removeChild(temp);
                afterCopy();
            } catch(e) {
                afterCopy();
            }
        }

        function animateCopyIcon($btn){
            if (!$btn || !$btn.length) return;
            const copyIcon = $btn.data('icon-copy') || $btn.text();
            const checkIcon = $btn.data('icon-check') || '✓';
            window.clearTimeout($btn.data('icon-timeout') || 0);
            $btn.text(checkIcon).addClass('annotate-copy-success');
            const timeout = window.setTimeout(() => {
                $btn.text(copyIcon).removeClass('annotate-copy-success');
            }, 1400);
            $btn.data('icon-timeout', timeout);
        }

        function handleMentionInput(){
            const val = $(this).val();
            const at = val.lastIndexOf('@');
            if (at === -1) {
                hideAutocomplete();
                return;
            }
            const q = val.slice(at+1);
            if (q.length < 2) {
                hideAutocomplete();
                return;
            }
            const $ta = $('#annotate-content');
            const caretPos = $ta.length ? $ta[0].selectionStart || val.length : val.length;
            const caretCoord = caretCoordinates($ta, caretPos);
            fetch( ROOT + '/users/search', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ term: q })
            } )
                .then(r=>r.json()).then(list=>{
                    if (!$autocomplete.length) return;
                    $autocomplete.empty().addClass('annotate-autocomplete-visible').attr('aria-hidden', 'false');
                    if (caretCoord) {
                        $autocomplete.css({
                            top: caretCoord.top + 6 + 'px',
                            left: caretCoord.left + 'px'
                        });
                    } else if ($ta.length) {
                        const offset = $ta.offset();
                        $autocomplete.css({
                            top: offset.top + $ta.outerHeight() + 6 + 'px',
                            left: offset.left + 'px'
                        });
                    }
                    list.forEach(u => {
                        const token = u.email || u.login;
                        if (!token) return;
                        let label = token;
                        if (u.display && u.display !== token) {
                            label += ' (' + u.display + ')';
                        }
                        const $it = $('<div class="annotate-ac-item"></div>').text(label).attr('data-token', token);
                        $it.on('click', function(){
                            const selectedToken = $(this).attr('data-token');
                            const before = val.slice(0, at+1);
                            const after = val.slice(at + 1 + q.length);
                            $('#annotate-content').val(before + selectedToken + after).focus();
                            hideAutocomplete();
                        });
                        $autocomplete.append($it);
                    });
                    bindAutocompleteNavigation();
                }).catch(()=>{ hideAutocomplete(); });
        }

        function bindAutocompleteNavigation(){
            const $content = $('#annotate-content');
            const items = $autocomplete.find('.annotate-ac-item');
            let acIndex = -1;
            $content.off('keydown.annotateAC').on('keydown.annotateAC', function(ev){
                if (!$autocomplete.hasClass('annotate-autocomplete-visible') || items.length === 0) return;
                if (ev.key === 'ArrowDown') {
                    ev.preventDefault();
                    acIndex = Math.min(acIndex + 1, items.length - 1);
                    items.removeClass('active').eq(acIndex).addClass('active');
                } else if (ev.key === 'ArrowUp') {
                    ev.preventDefault();
                    acIndex = Math.max(acIndex - 1, 0);
                    items.removeClass('active').eq(acIndex).addClass('active');
                } else if (ev.key === 'Enter') {
                    if (acIndex >= 0) {
                        ev.preventDefault();
                        items.eq(acIndex).trigger('click');
                    }
                } else if (ev.key === 'Tab') {
                    ev.preventDefault();
                    const targetIndex = acIndex >= 0 ? acIndex : 0;
                    items.eq(targetIndex).trigger('click');
                } else if (ev.key === 'Escape') {
                    hideAutocomplete();
                }
            });
        }

        function bindComposerEvents(){
            const $content = $('#annotate-content');
            $content.on('keydown.annotateComposer', function(ev){
                if (ev.key === 'Enter' && !ev.shiftKey && !ev.ctrlKey && !ev.metaKey) {
                    ev.preventDefault();
                    handleComposerSubmit();
                }
            });
            $content.on('input.annotateComposer', handleMentionInput);
        }

        function handlePanelClose(){
            resetInteractionState();
            closeAndResetPanel();
        }

        function bindPanelChromeEvents(){
            $('#annotate-cancel-new').on('click', function(ev){
                ev.preventDefault();
                handlePanelClose();
            });
            $('#annotate-back-to-list').on('click', function(ev){
                ev.preventDefault();
                handlePanelClose();
                openViewAllPanel();
            });
            $('#annotate-post').on('click', function(ev){
                ev.preventDefault();
                handleComposerSubmit();
            });
        }

        bindComposerEvents();
        bindPanelChromeEvents();

        function initializeConfirmPanels(thread){
            const configs = getActiveConfirmConfigs(thread);
            if (!configs.length) return;
            const entries = configs.map(cfg => ({
                cfg: cfg,
                $panel: $('#' + cfg.panelId),
                $trigger: $('#' + cfg.triggerId),
                $confirm: $('#' + cfg.confirmId),
                $cancel: $('#' + cfg.cancelId)
            })).filter(entry => entry.$panel.length && entry.$trigger.length);
            if (!entries.length) return;
            const hideAllPanels = () => {
                entries.forEach(entry => entry.$panel.addClass('annotate-confirm-hidden'));
            };
            const showPanel = entry => {
                entries.forEach(item => item.$panel.addClass('annotate-confirm-hidden'));
                entry.$panel.removeClass('annotate-confirm-hidden');
                applyConfirmSpaceState($panel);
            };
            entries.forEach(entry => {
                entry.$panel.addClass('annotate-confirm-hidden');
                entry.$trigger.off('.annotateConfirm').on('click.annotateConfirm', function(ev){
                    ev.preventDefault();
                    showPanel(entry);
                });
                if (entry.$cancel.length) {
                    entry.$cancel.off('.annotateConfirm').on('click.annotateConfirm', function(ev){
                        ev.preventDefault();
                        hideAllPanels();
                        applyConfirmSpaceState($panel);
                    });
                }
                if (entry.$confirm.length) {
                    entry.$confirm.off('.annotateConfirm').on('click.annotateConfirm', function(ev){
                        ev.preventDefault();
                        const action = entry.cfg.onConfirm ? entry.cfg.onConfirm(thread) : Promise.resolve();
                        Promise.resolve(action)
                            .then(() => { hideAllPanels(); applyConfirmSpaceState($panel); })
                            .catch(() => {
                                if (entry.cfg && entry.cfg.errorMessage) {
                                    alert(entry.cfg.errorMessage);
                                }
                            });
                    });
                }
            });
        }

        function getActiveConfirmConfigs(thread){
            return Object.keys(CONFIRM_PANEL_CONFIG)
                .map(key => CONFIRM_PANEL_CONFIG[key])
                .filter(cfg => typeof cfg.shouldRender === 'function' ? cfg.shouldRender(thread) : false);
        }

        // Update badge order/labels (used after posting)
        function updateBadgeForSelector(){
            if (!enabled()) return;
            loadAllBadges();
        }

        function createThreadCardElement(thread){
            const card = document.createElement('div');
            const missingClass = (!thread.exists && !thread.is_closed) ? ' annotate-thread-missing' : '';
            card.className = 'annotate-thread-card' + missingClass;
            card.setAttribute('data-thread-id', thread.id);
            card.setAttribute('data-selector', thread.selector || '');
            card.setAttribute('data-closed', thread.is_closed ? '1' : '0');

            const header = document.createElement('div');
            header.className = 'annotate-thread-header';
            const code = document.createElement('code');
            code.className = 'annotate-thread-selector';
            const selectorText = thread.selector && thread.selector.length > 70 ? thread.selector.slice(0, 67) + '…' : (thread.selector || '');
            code.textContent = selectorText;
            const time = document.createElement('span');
            time.className = 'annotate-thread-time';
            time.textContent = thread.last_activity || thread.created_at || '';
            header.appendChild(code);
            header.appendChild(time);
            card.appendChild(header);

            const snippet = document.createElement('div');
            snippet.className = 'annotate-thread-snippet';
            const author = (thread.last_comment_author && thread.last_comment_author.trim()) || '';
            const snippetText = (thread.last_comment_excerpt && thread.last_comment_excerpt.trim()) || t('snippet_no_comments', 'No comments yet');
            if (author) {
                const authorSpan = document.createElement('span');
                authorSpan.className = 'annotate-thread-author';
                const label = buildLabelFromText(author);
                authorSpan.textContent = label.label;
                if (label.title) {
                    authorSpan.setAttribute('title', label.title);
                }
                snippet.appendChild(authorSpan);
                snippet.appendChild(document.createTextNode(': '));
            }
            snippet.appendChild(document.createTextNode(snippetText));
            card.appendChild(snippet);

            card.addEventListener('click', function(){
                openThreadById(thread.id, thread.selector, { is_closed: thread.is_closed });
            });

            return card;
        }

        function openViewAllPanel(){
            resetInteractionState();
            activePanelThread = null;
            fetchThreadsForCurrentPage()
                .then(list=>{
                    if (!$panel.length) return;
                    const $p = $panel;
                    $p.removeClass('annotate-panel-reserve-confirm');
                    $p.attr('data-panel-mode', 'view-all');
                    showViewAllView();

                    const $count = $('#annotate-viewall-count');
                    const $subtitle = $('#annotate-viewall-subtitle');
                    const $pill = $('#annotate-viewall-pill');
                    const $emptyState = $('#annotate-viewall-empty');
                    const $sectionsWrapper = $('#annotate-viewall-list .annotate-viewall-sections');
                    const sections = {
                        missing: $sectionsWrapper.find('[data-section="missing"]'),
                        open: $sectionsWrapper.find('[data-section="open"]'),
                        closed: $sectionsWrapper.find('[data-section="closed"]')
                    };
                    Object.keys(sections).forEach(key => {
                        const $section = sections[key];
                        setHiddenState($section, true);
                        $section.find('.annotate-section-body').empty();
                    });

                    const missing = [];
                    const present = [];
                    list.forEach(t => {
                        let exists = true;
                        try { exists = !!document.querySelector(t.selector); } catch(e) { exists = false; }
                        const enriched = Object.assign({}, t, {
                            exists: exists,
                            last_activity_ts: t.last_activity ? Date.parse(t.last_activity) : 0,
                            is_closed: isThreadClosed(t)
                        });
                        if (exists || enriched.is_closed) {
                            present.push(enriched);
                        } else {
                            missing.push(enriched);
                        }
                    });
                    const sortThreads = arr => arr.sort((a, b) => (b.last_activity_ts || 0) - (a.last_activity_ts || 0));
                    const missingSorted = sortThreads(missing);
                    const openThreads = sortThreads(present.filter(t => !t.is_closed));
                    const closedThreads = sortThreads(present.filter(t => t.is_closed));
                    updateViewAllButton(missingSorted.length);
                    $count.text(list.length);
                    if (missingSorted.length) {
                        $pill.text(t('view_all_missing_pill', '%d missing selectors', missingSorted.length));
                        setHiddenState($pill, false);
                        setHiddenState($subtitle, true);
                    } else {
                        setHiddenState($pill, true);
                        setHiddenState($subtitle, false);
                        $subtitle.text(t('view_all_default_subtitle', 'Everything on this page'));
                    }

                    const hasThreads = list.length > 0;
                    setHiddenState($emptyState, hasThreads);
                    setHiddenState($sectionsWrapper, !hasThreads);

                    const renderSection = (key, threads) => {
                        const $section = sections[key];
                        if (!$section.length || !threads.length) return;
                        setHiddenState($section, false);
                        $section.find('.annotate-section-count').text(threads.length);
                        const body = $section.find('.annotate-section-body').empty()[0];
                        threads.forEach(t => {
                            const card = createThreadCardElement(t);
                            body.appendChild(card);
                        });
                    };

                    renderSection('missing', missingSorted);
                    renderSection('open', openThreads);
                    renderSection('closed', closedThreads);

                    hideAutocomplete();
                    applyConfirmSpaceState($p);
                    openPanelContainer(PANEL_MODES.LIST);
                });
        }

        function openThread(thread, opts){
            if (!thread) return;
            const scroll = !(opts && opts.scroll === false);
            const selector = thread.selector;
            const closed = isThreadClosed(thread);
            currentScope = null;
            currentThread = thread && thread.id ? { id: thread.id } : null;
            syncSelectingVisualState();
            setCreateModeState(false);
            renderThreadPanel(thread, selector);
            if (closed) {
                clearFocus();
                return;
            }
            // try to place badge if selector matches
            try{
                const el = document.querySelector(selector);
                if (el) {
                    placeBadge(el, thread);
                    focusElement(el, { scroll: scroll });
                } else {
                    clearFocus();
                }
            } catch(e){ clearFocus(); }
        }

        function openThreadById(id, selector, options){
            const closed = options && typeof options.is_closed !== 'undefined' ? isThreadClosed({ is_closed: options.is_closed }) : false;
            const thread = { id: id, selector: selector, is_closed: closed };
            const opts = Object.assign({}, options || {});
            openThread(thread, opts);
        }
    });
})(jQuery);
