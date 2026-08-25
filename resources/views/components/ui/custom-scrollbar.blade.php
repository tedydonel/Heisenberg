@props([
    'smooth' => 0.06,
    'wheelMultiplier' => 1,
    'container' => null,
    'axis' => 'y',
])

@once
    

    <script>
        (() => {
            const boot = () => {
                document.querySelectorAll('[data-hb-custom-scrollbar]').forEach((bar) => {
                    if (bar.__hbScrollbar) { bar.__hbScrollbar.refresh(); return; }

                    const containerSelector = bar.dataset.hbScrollContainer || '';
                    const container = containerSelector
                        ? (bar.closest(containerSelector)
                            || bar.parentElement?.querySelector(containerSelector)
                            || document.querySelector(containerSelector))
                        : null;
                    const isWindow = !container;
                    const horizontal = bar.dataset.axis === 'x';
                    if (!isWindow) {
                        container.classList.add('hb-scroll-container');
                        container.style[horizontal ? 'overflowX' : 'overflowY'] = 'auto';
                    } else {
                        document.documentElement.classList.add('hb-scrollbar-enabled');
                    }

                    const thumb = bar.querySelector('[data-hb-scrollbar-thumb]');
                    const smoothRaw = bar.dataset.smooth;
                    const smooth = (smoothRaw === '' || smoothRaw === 'false') ? 0 : (Number(smoothRaw) || 0);
                    const wheelMultiplier = Number(bar.dataset.wheelMultiplier || 1);
                    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
                    const useSmooth = !reduceMotion && !coarsePointer && smooth > 0;

                    const getScrollPos = () => {
                        if (isWindow) return horizontal ? (window.scrollX || 0) : (window.scrollY || window.pageYOffset || 0);
                        return horizontal ? container.scrollLeft : container.scrollTop;
                    };
                    const setScrollPos = (v) => {
                        if (isWindow) window.scrollTo(horizontal ? v : (window.scrollX || 0), horizontal ? (window.scrollY || 0) : v);
                        else if (horizontal) container.scrollLeft = v;
                        else container.scrollTop = v;
                    };
                    const viewportSize = () => {
                        if (isWindow) return horizontal ? window.innerWidth : window.innerHeight;
                        return horizontal ? container.clientWidth : container.clientHeight;
                    };
                    const scrollSize = () => {
                        if (isWindow) return horizontal ? document.documentElement.scrollWidth : document.documentElement.scrollHeight;
                        return horizontal ? container.scrollWidth : container.scrollHeight;
                    };
                    const eventTarget = isWindow ? window : container;

                    let target = getScrollPos();
                    let current = target;
                    let frameId = null;
                    let lastFrame = 0;
                    let dragging = false;
                    let idleTimer = null;
                    let syncing = false;

                    const maxScroll = () => Math.max(0, scrollSize() - viewportSize());
                    const clamp = (value) => Math.min(maxScroll(), Math.max(0, value));
                    const setState = (name, value) => bar.dataset[name] = value ? 'true' : 'false';

                    const render = (position) => {
                        const bounds = maxScroll();
                        const trackSize = horizontal ? bar.clientWidth : bar.clientHeight;
                        const thumbSize = horizontal ? thumb.offsetWidth : thumb.offsetHeight;
                        bar.hidden = bounds <= 0;
                        if (!bounds) return;
                        const travel = Math.max(0, trackSize - thumbSize - 4);
                        const offset = Math.max(0, Math.min(travel, position / bounds * travel));
                        thumb.style.transform = horizontal
                            ? `translate3d(${offset.toFixed(2)}px, 0, 0)`
                            : `translate3d(0, ${offset.toFixed(2)}px, 0)`;
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
                        setScrollPos(current);
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
                        event.stopPropagation();
                        let delta = horizontal ? (event.deltaX || (event.shiftKey ? event.deltaY : 0)) : event.deltaY;
                        if (event.deltaMode === 1) delta *= 16;
                        if (event.deltaMode === 2) delta *= viewportSize();
                        target = clamp(target + delta * wheelMultiplier);
                        start();
                    };

                    const keydown = (event) => {
                        if (!useSmooth || horizontal) return;
                        const element = event.target;
                        if (element?.isContentEditable || /^(input|textarea|select)$/i.test(element?.tagName || '')) return;
                        if (!isWindow && !container.contains(element) && element !== document.body) return;

                        const viewport = viewportSize();
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
                        target = current = getScrollPos();
                        emit();
                    };

                    const resize = () => {
                        target = current = clamp(getScrollPos());
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
                        const ratio = horizontal
                            ? Math.max(0, Math.min(1, (event.clientX - barRect.left) / bar.clientWidth))
                            : Math.max(0, Math.min(1, (event.clientY - barRect.top) / bar.clientHeight));
                        target = ratio * bounds;
                        current = target;
                        syncing = true;
                        setScrollPos(target);
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
    data-axis="{{ $axis === 'x' ? 'x' : 'y' }}"
    data-smooth="{{ $smooth }}"
    data-wheel-multiplier="{{ $wheelMultiplier }}"
    data-scrolling="false"
    data-dragging="false"
    aria-hidden="true"
>
    <div data-hb-scrollbar-thumb class="hb-custom-scrollbar__thumb"></div>
</div>
