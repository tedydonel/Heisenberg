@once

<script>
    (() => {
        const pad2 = (n) => String(n).padStart(2, '0');
        const parseLocal = (value) => {
            const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value || '');
            return m ? { y: Number(m[1]), mo: Number(m[2]) - 1, d: Number(m[3]), h: Number(m[4]), mi: Number(m[5]) } : null;
        };
        const formatLocal = (y, mo, d, h, mi) => y + '-' + pad2(mo + 1) + '-' + pad2(d) + 'T' + pad2(h) + ':' + pad2(mi);
        const daysInMonth = (y, mo) => new Date(y, mo + 1, 0).getDate();

        const boot = () => {
            document.querySelectorAll('[data-hb-datepicker]').forEach((root) => {
                if (root.__hbDatePicker) return;

                const hidden = root.querySelector('[data-hb-dtp-value]');
                const label = root.querySelector('[data-hb-dtp-label]');
                const grid = root.querySelector('[data-hb-dtp-grid]');
                const hourInput = root.querySelector('[data-hb-dtp-hour]');
                const minuteInput = root.querySelector('[data-hb-dtp-minute]');
                const todayBtn = root.querySelector('[data-hb-dtp-today]');
                const clearBtn = root.querySelector('[data-hb-dtp-clear]');
                if (!hidden || !label || !grid || !hourInput || !minuteInput) return;

                let selected = null;
                let viewYear = 0;
                let viewMonth = 0;
                let focusDate = null;

                const clampHour = () => {
                    const n = parseInt(hourInput.value, 10);
                    return Number.isFinite(n) ? Math.min(23, Math.max(0, n)) : 0;
                };
                const clampMinute = () => {
                    const n = parseInt(minuteInput.value, 10);
                    return Number.isFinite(n) ? Math.min(59, Math.max(0, n)) : 0;
                };

                const render = () => {
                    label.textContent = new Date(viewYear, viewMonth, 1).toLocaleDateString(undefined, { month: 'long' }) + ' ' + viewYear;

                    const today = new Date();
                    const startOffset = (new Date(viewYear, viewMonth, 1).getDay() + 6) % 7;
                    const inMonth = daysInMonth(viewYear, viewMonth);
                    const prevMonthDays = daysInMonth(viewMonth === 0 ? viewYear - 1 : viewYear, viewMonth === 0 ? 11 : viewMonth - 1);
                    if (!focusDate) focusDate = selected ? { ...selected } : { y: viewYear, mo: viewMonth, d: Math.min(today.getDate(), inMonth) };

                    grid.textContent = '';
                    for (let i = 0; i < 42; i++) {
                        const dayNum = i - startOffset + 1;
                        let y = viewYear;
                        let mo = viewMonth;
                        let d = dayNum;
                        let muted = false;
                        if (dayNum < 1) { d = prevMonthDays + dayNum; mo -= 1; muted = true; }
                        else if (dayNum > inMonth) { d = dayNum - inMonth; mo += 1; muted = true; }
                        if (mo < 0) { mo = 11; y -= 1; }
                        if (mo > 11) { mo = 0; y += 1; }

                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'hb-dtp__day';
                        if (muted) btn.classList.add('hb-dtp__day--muted');
                        if (y === today.getFullYear() && mo === today.getMonth() && d === today.getDate()) btn.classList.add('hb-dtp__day--today');
                        if (selected && selected.y === y && selected.mo === mo && selected.d === d) btn.classList.add('hb-dtp__day--selected');
                        btn.textContent = String(d);
                        btn.tabIndex = (focusDate.y === y && focusDate.mo === mo && focusDate.d === d) ? 0 : -1;
                        btn.setAttribute('aria-label', new Date(y, mo, d).toLocaleDateString());
                        btn.addEventListener('click', () => pick(y, mo, d));
                        grid.appendChild(btn);
                    }
                };

                const commit = () => {
                    hidden.value = selected ? formatLocal(selected.y, selected.mo, selected.d, clampHour(), clampMinute()) : '';
                    render();
                    hidden.dispatchEvent(new Event('input', { bubbles: true }));
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                };

                const pick = (y, mo, d) => {
                    selected = { y, mo, d };
                    focusDate = { y, mo, d };
                    viewYear = y;
                    viewMonth = mo;
                    commit();
                    const cell = grid.querySelector('[tabindex="0"]');
                    if (cell) cell.focus();
                };

                const setValue = (value) => {
                    const parsed = parseLocal(value);
                    if (parsed) {
                        selected = { y: parsed.y, mo: parsed.mo, d: parsed.d };
                        hourInput.value = pad2(parsed.h);
                        minuteInput.value = pad2(parsed.mi);
                        viewYear = parsed.y;
                        viewMonth = parsed.mo;
                    } else {
                        selected = null;
                        hourInput.value = '';
                        minuteInput.value = '';
                        const now = new Date();
                        viewYear = now.getFullYear();
                        viewMonth = now.getMonth();
                    }
                    focusDate = null;
                    hidden.value = parsed ? formatLocal(parsed.y, parsed.mo, parsed.d, parsed.h, parsed.mi) : '';
                    render();
                };

                root.querySelectorAll('[data-hb-dtp-nav]').forEach((nav) => nav.addEventListener('click', () => {
                    const step = nav.dataset.hbDtpNav;
                    if (step === 'prev-year') viewYear -= 1;
                    else if (step === 'next-year') viewYear += 1;
                    else if (step === 'prev-month') { viewMonth -= 1; if (viewMonth < 0) { viewMonth = 11; viewYear -= 1; } }
                    else if (step === 'next-month') { viewMonth += 1; if (viewMonth > 11) { viewMonth = 0; viewYear += 1; } }
                    focusDate = { y: viewYear, mo: viewMonth, d: 1 };
                    render();
                }));

                hourInput.addEventListener('change', () => { if (selected) commit(); });
                minuteInput.addEventListener('change', () => { if (selected) commit(); });

                if (todayBtn) todayBtn.addEventListener('click', () => {
                    const now = new Date();
                    if (!hourInput.value) hourInput.value = pad2(now.getHours());
                    if (!minuteInput.value) minuteInput.value = pad2(now.getMinutes());
                    pick(now.getFullYear(), now.getMonth(), now.getDate());
                });
                if (clearBtn) clearBtn.addEventListener('click', () => {
                    selected = null;
                    hourInput.value = '';
                    minuteInput.value = '';
                    commit();
                });

                grid.addEventListener('keydown', (event) => {
                    const deltas = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        if (focusDate) pick(focusDate.y, focusDate.mo, focusDate.d);
                        return;
                    }
                    if (!(event.key in deltas)) return;
                    event.preventDefault();
                    if (!focusDate) focusDate = selected ? { ...selected } : { y: viewYear, mo: viewMonth, d: 1 };
                    const dt = new Date(focusDate.y, focusDate.mo, focusDate.d);
                    dt.setDate(dt.getDate() + deltas[event.key]);
                    focusDate = { y: dt.getFullYear(), mo: dt.getMonth(), d: dt.getDate() };
                    viewYear = focusDate.y;
                    viewMonth = focusDate.mo;
                    render();
                    const cell = grid.querySelector('[tabindex="0"]');
                    if (cell) cell.focus();
                });

                setValue(hidden.value);
                root.__hbDatePicker = { setValue, getValue: () => hidden.value };
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
@props(['value' => ''])
<div {{ $attributes->merge(['class' => 'hb-dtp']) }} data-hb-datepicker>
    <input type="hidden" data-hb-dtp-value value="{{ $value }}">
    <div class="hb-dtp__head">
        <button type="button" class="hb-dtp__nav" data-hb-dtp-nav="prev-year" aria-label="{{ __('heisenberg::editor.date_picker.prev_year') }}">
            @include('heisenberg::components.ui.icon', ['name' => 'caret-double-left', 'size' => 13])
        </button>
        <button type="button" class="hb-dtp__nav" data-hb-dtp-nav="prev-month" aria-label="{{ __('heisenberg::editor.date_picker.prev_month') }}">
            @include('heisenberg::components.ui.icon', ['name' => 'caret-left', 'size' => 13])
        </button>
        <span class="hb-dtp__label" data-hb-dtp-label></span>
        <button type="button" class="hb-dtp__nav" data-hb-dtp-nav="next-month" aria-label="{{ __('heisenberg::editor.date_picker.next_month') }}">
            @include('heisenberg::components.ui.icon', ['name' => 'caret-right', 'size' => 13])
        </button>
        <button type="button" class="hb-dtp__nav" data-hb-dtp-nav="next-year" aria-label="{{ __('heisenberg::editor.date_picker.next_year') }}">
            @include('heisenberg::components.ui.icon', ['name' => 'caret-double-right', 'size' => 13])
        </button>
    </div>
    <div class="hb-dtp__weekdays">
        @foreach (__('heisenberg::editor.date_picker.weekdays') as $hbDtpWeekday)
            <span>{{ $hbDtpWeekday }}</span>
        @endforeach
    </div>
    <div class="hb-dtp__grid" data-hb-dtp-grid></div>
    <div class="hb-dtp__time" data-hb-dtp-time>
        <input type="number" class="hb-dtp__time-input" data-hb-dtp-hour min="0" max="23" inputmode="numeric" aria-label="{{ __('heisenberg::editor.date_picker.hour') }}">
        <span class="hb-dtp__time-sep">:</span>
        <input type="number" class="hb-dtp__time-input" data-hb-dtp-minute min="0" max="59" inputmode="numeric" aria-label="{{ __('heisenberg::editor.date_picker.minute') }}">
    </div>
    <div class="hb-dtp__foot" data-hb-dtp-footer>
        <button type="button" class="hb-dtp__btn" data-hb-dtp-today>{{ __('heisenberg::editor.date_picker.today') }}</button>
        <button type="button" class="hb-dtp__btn hb-dtp__btn--muted" data-hb-dtp-clear>{{ __('heisenberg::editor.date_picker.clear') }}</button>
    </div>
</div>
