@props([
    'smooth' => 0.06,
    'wheelMultiplier' => 1,
    'container' => null,
])

@once
    <style>
        html.hb-scrollbar-enabled::-webkit-scrollbar {
            width: 0;
            height: 0;
            background: transparent;
        }
        /* Belt-and-suspenders alongside the wheel handler's stopPropagation() below — this covers
           scroll paths the JS wheel listener doesn't see (touch momentum, keyboard-driven native
           scroll) so a nested scroll region never chains into whatever scrollable ancestor wraps it. */
        .hb-scroll-container { scrollbar-width: none; -ms-overflow-style: none; overscroll-behavior: contain; }
        .hb-scroll-container::-webkit-scrollbar { width: 0; height: 0; background: transparent; }

        .hb-custom-scrollbar {
            height: 100vh;
            opacity: .5;
            position: fixed;
            right: 1px;
            top: 0;
            transform-origin: center right;
            transition: transform .3s cubic-bezier(.165,.84,.44,1), opacity .3s cubic-bezier(.65,0,.35,1);
            width: 11px;
            z-index: 1000;
        }
        /* Container mode (data-hb-scroll-container set): anchor to the scroll container instead of
           the viewport. The container needs position:relative for this to anchor correctly. */
        .hb-custom-scrollbar[data-hb-scroll-container] {
            position: absolute;
            height: 100%;
        }

        .hb-custom-scrollbar[data-scrolling="true"],
        .hb-custom-scrollbar[data-dragging="true"],
        .hb-custom-scrollbar:hover {
            opacity: .8;
            transform: scaleX(1.2);
        }

        .hb-custom-scrollbar[data-scrolling="true"] .hb-custom-scrollbar__thumb,
        .hb-custom-scrollbar[data-dragging="true"] .hb-custom-scrollbar__thumb,
        .hb-custom-scrollbar:hover .hb-custom-scrollbar__thumb {
            opacity: 1;
        }

        .hb-custom-scrollbar[data-scrolling="true"] .hb-custom-scrollbar__thumb,
        .hb-custom-scrollbar[data-dragging="true"] .hb-custom-scrollbar__thumb {
            cursor: grabbing;
        }

        .hb-custom-scrollbar__thumb {
            background-color: #9e9e9e;
            border-radius: 14px;
            cursor: grab;
            height: 48px;
            margin: 2px;
            opacity: .5;
            position: absolute;
            right: 0;
            top: 0;
            transform: translate3d(0, 0, 0);
            transition: opacity .3s cubic-bezier(.65,0,.35,1);
            width: 3px;
            will-change: transform;
        }

        .hb-scrollable-x::-webkit-scrollbar { height: 1px; }
        .hb-scrollable-x::-webkit-scrollbar-thumb { background: #0e0e0e; }
        .hb-scrollable-x::-webkit-scrollbar-track { background: #fff; }
        .hb-scrollable-y::-webkit-scrollbar { width: 4px; }
        .hb-scrollable-y::-webkit-scrollbar-thumb { background: #0e0e0e; }
        .hb-scrollable-y::-webkit-scrollbar-track { background: #fff; }

        @media (pointer: coarse) {
            .hb-custom-scrollbar { display: none !important; }
        }
    </style>

    <script>
        (() => {
            const boot = () => {
                document.querySelectorAll('[data-hb-custom-scrollbar]').forEach((bar) => {
                    // A bar that boots while its container is inside a [hidden] ancestor (a
                    // not-yet-active tab/panel) measures a 0-height track and renders the thumb
                    // pinned accordingly. The ResizeObserver below is SUPPOSED to catch it becoming
                    // visible later and recompute — in practice that doesn't always fire in time, so
                    // every hb:refresh (dispatched on every tab/panel switch — see sidebar.blade.php's
                    // showPanel() and ui/partials/tablist-script.blade.php's activate()) also forces
                    // an already-booted bar to recheck its now-possibly-different dimensions directly,
                    // rather than only scanning for brand-new bars.
                    if (bar.__hbScrollbar) { bar.__hbScrollbar.refresh(); return; }

                    const containerSelector = bar.dataset.hbScrollContainer || '';
                    // Falls back to window-level scrolling when no container is given (original
                    // behavior, used by the gallery page). With a container, every window-scoped call
                    // below is replaced by the container's own scrollTop/scrollHeight/clientHeight so
                    // this scrollbar only ever drives ITS container, not the page.
                    const container = containerSelector ? bar.closest(containerSelector) || document.querySelector(containerSelector) : null;
                    const isWindow = !container;
                    if (!isWindow) {
                        container.classList.add('hb-scroll-container');
                        container.style.overflowY = 'auto';
                    } else {
                        document.documentElement.classList.add('hb-scrollbar-enabled');
                    }

                    const thumb = bar.querySelector('[data-hb-scrollbar-thumb]');
                    const smooth = Number(bar.dataset.smooth || 0.06);
                    const wheelMultiplier = Number(bar.dataset.wheelMultiplier || 1);
                    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
                    const useSmooth = !reduceMotion && !coarsePointer && smooth !== false;

                    const getScrollTop = () => (isWindow ? (window.scrollY || window.pageYOffset || 0) : container.scrollTop);
                    const setScrollTop = (v) => { if (isWindow) window.scrollTo(0, v); else container.scrollTop = v; };
                    const viewportHeight = () => (isWindow ? window.innerHeight : container.clientHeight);
                    const scrollHeight = () => (isWindow ? document.documentElement.scrollHeight : container.scrollHeight);
                    const eventTarget = isWindow ? window : container;

                    let target = getScrollTop();
                    let current = target;
                    let frameId = null;
                    let lastFrame = 0;
                    let dragging = false;
                    let idleTimer = null;
                    let syncing = false;

                    const maxScroll = () => Math.max(0, scrollHeight() - viewportHeight());
                    const clamp = (value) => Math.min(maxScroll(), Math.max(0, value));
                    const setState = (name, value) => bar.dataset[name] = value ? 'true' : 'false';

                    const render = (position) => {
                        const bounds = maxScroll();
                        const trackHeight = bar.clientHeight;
                        const thumbHeight = thumb.offsetHeight;
                        bar.hidden = bounds <= 0;
                        if (!bounds) return;
                        const travel = Math.max(0, trackHeight - thumbHeight - 4);
                        const offset = Math.max(0, Math.min(travel, position / bounds * travel));
                        thumb.style.transform = `translate3d(0, ${offset.toFixed(2)}px, 0)`;
                    };

                    const markScrolling = () => {
                        setState('scrolling', true);
                        clearTimeout(idleTimer);
                        idleTimer = setTimeout(() => setState('scrolling', false), 140);
                    };

                    const emit = () => {
                        render(dragging ? target : current);
                        markScrolling();
                    };

                    const animate = (time) => {
                        const delta = lastFrame ? Math.min(.1, (time - lastFrame) / 1000) : 1 / 60;
                        lastFrame = time;
                        const factor = 1 - Math.pow(1 - Math.min(.99, Math.max(.001, smooth)), delta * 60);
                        current += (target - current) * factor;
                        if (Math.abs(target - current) < .4) current = target;

                        syncing = true;
                        setScrollTop(current);
                        syncing = false;
                        emit();

                        if (current !== target) {
                            frameId = requestAnimationFrame(animate);
                        } else {
                            frameId = null;
                            lastFrame = 0;
                        }
                    };

                    const start = () => {
                        if (!useSmooth) return;
                        if (!frameId) frameId = requestAnimationFrame(animate);
                    };

                    const wheel = (event) => {
                        if (!useSmooth || event.ctrlKey) return;
                        event.preventDefault();
                        // A nested scroll region (e.g. a dropdown menu inside a scrollable panel) sits
                        // inside this region's own DOM subtree, so a wheel event over it bubbles up
                        // through here too — without this, scrolling the inner region ALSO scrolled
                        // whatever scrollable ancestor wraps it, since preventDefault() alone only
                        // stops the browser's native scroll, not this component's own ancestor
                        // listener from independently reacting to the same event.
                        event.stopPropagation();
                        let delta = event.deltaY;
                        if (event.deltaMode === 1) delta *= 16;
                        if (event.deltaMode === 2) delta *= viewportHeight();
                        target = clamp(target + delta * wheelMultiplier);
                        start();
                    };

                    const keydown = (event) => {
                        if (!useSmooth) return;
                        const element = event.target;
                        if (element?.isContentEditable || /^(input|textarea|select)$/i.test(element?.tagName || '')) return;
                        if (!isWindow && !container.contains(element) && element !== document.body) return;

                        const viewport = viewportHeight();
                        let step = 0;
                        if (event.key === 'ArrowDown') step = 90;
                        else if (event.key === 'ArrowUp') step = -90;
                        else if (event.key === 'PageDown') step = viewport * .9;
                        else if (event.key === 'PageUp') step = -viewport * .9;
                        else if (event.key === ' ') step = (event.shiftKey ? -1 : 1) * viewport * .9;
                        else if (event.key === 'Home') { step = -Infinity; }
                        else if (event.key === 'End') { step = Infinity; }
                        else return;

                        event.preventDefault();
                        target = step === Infinity ? maxScroll() : step === -Infinity ? 0 : clamp(target + step);
                        start();
                    };

                    const scroll = () => {
                        if (syncing || frameId) return;
                        target = current = getScrollTop();
                        emit();
                    };

                    const resize = () => {
                        // Re-reads the container's REAL scrollTop, not just re-clamping the stale
                        // `target`/`current` this instance booted with — a container that was
                        // 0-height at boot time (inside a [hidden] tab/panel) reports scrollTop 0
                        // then, and nothing else ever resyncs it once real dimensions exist, so the
                        // thumb stayed frozen at whatever offset that stale pair produced even after
                        // becoming scrollable.
                        target = current = clamp(getScrollTop());
                        render(current);
                    };

                    const pointerDown = (event) => {
                        event.preventDefault();
                        dragging = true;
                        setState('dragging', true);
                        document.body.style.cursor = 'grabbing';
                        thumb.setPointerCapture?.(event.pointerId);
                    };

                    const pointerMove = (event) => {
                        if (!dragging) return;
                        const bounds = maxScroll();
                        const barRect = bar.getBoundingClientRect();
                        const ratio = Math.max(0, Math.min(1, (event.clientY - barRect.top) / bar.clientHeight));
                        target = ratio * bounds;
                        current = target;
                        syncing = true;
                        setScrollTop(target);
                        syncing = false;
                        render(target);
                        markScrolling();
                    };

                    const pointerUp = () => {
                        if (!dragging) return;
                        dragging = false;
                        setState('dragging', false);
                        document.body.style.cursor = '';
                    };

                    eventTarget.addEventListener('wheel', wheel, { passive: false });
                    window.addEventListener('keydown', keydown);
                    eventTarget.addEventListener('scroll', scroll, { passive: true });
                    window.addEventListener('resize', resize);
                    thumb.addEventListener('pointerdown', pointerDown);
                    window.addEventListener('pointermove', pointerMove);
                    window.addEventListener('pointerup', pointerUp);
                    window.addEventListener('pointercancel', pointerUp);

                    // A container that starts inside a [hidden] ancestor (e.g. a not-yet-active
                    // panel/tab) measures 0x0 at boot, so render() below permanently marks the thumb
                    // hidden — the window 'resize' listener above never fires just because a tab was
                    // switched. ResizeObserver reports the container's real size once its ancestor
                    // is un-hidden and it actually gets laid out, so this recovers correctly.
                    let ro = null;
                    if (!isWindow && 'ResizeObserver' in window) {
                        ro = new ResizeObserver(() => { target = clamp(target); render(current); });
                        ro.observe(container);
                    }

                    bar.__hbScrollbar = {
                        refresh: resize,
                        destroy() {
                            eventTarget.removeEventListener('wheel', wheel);
                            window.removeEventListener('keydown', keydown);
                            eventTarget.removeEventListener('scroll', scroll);
                            window.removeEventListener('resize', resize);
                            thumb.removeEventListener('pointerdown', pointerDown);
                            window.removeEventListener('pointermove', pointerMove);
                            window.removeEventListener('pointerup', pointerUp);
                            window.removeEventListener('pointercancel', pointerUp);
                            ro?.disconnect();
                            cancelAnimationFrame(frameId);
                            clearTimeout(idleTimer);
                            document.body.style.cursor = '';
                            delete bar.__hbScrollbar;
                        }
                    };

                    render(current);
                });
            };

            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
            else boot();
            document.addEventListener('hb:refresh', boot);
        })();
    </script>
@endonce

<div
    {{ $attributes->merge(['class' => 'hb-custom-scrollbar']) }}
    data-hb-custom-scrollbar
    @if ($container) data-hb-scroll-container="{{ $container }}" @endif
    data-smooth="{{ $smooth }}"
    data-wheel-multiplier="{{ $wheelMultiplier }}"
    data-scrolling="false"
    data-dragging="false"
    aria-hidden="true"
>
    <div data-hb-scrollbar-thumb class="hb-custom-scrollbar__thumb"></div>
</div>
