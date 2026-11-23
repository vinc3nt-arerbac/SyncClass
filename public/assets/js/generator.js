/* ==========================================================================
 * SyncClass — timetable generator driver (GEN-13).
 *
 * Wires the planning form (plan.php) and the run result (result.php) to the
 * deterministic constraint-solver endpoint. No generative AI: the same
 * seed + inputs always produce the same draft (GEN-01, ADR-06).
 *
 * Endpoints
 *   POST /generator/preview   -> { ok, best, metrics, unsat, error }
 *   POST /generator/commit    -> one transaction, inserts as `draft`
 *
 * The controller owns the write; the client only renders the response.
 * ========================================================================== */

(function (global) {
    'use strict';

    const $ = global.document;

    function esc(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function error(el, message) {
        const container = el.closest('.card') || el;
        const node = global.document.createElement('div');
        node.className = 'alert alert-danger';
        node.textContent = message;
        container.appendChild(node);
    }

    async function postForm(form, url) {
        const payload = new FormData(form);
        const response = await global.fetch(url, {
            method: 'POST',
            body: payload,
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            throw new Error(body.error || 'Request failed (' + response.status + ')');
        }

        return response.json();
    }

    function renderResult(container, plan, metrics) {
        // Re-render the preview HTML from the generator's draft.
        const rows = plan && plan.best ? plan.best.placements : [];
        const slots = [];
        const occupied = new Set();

        for (const p of rows) {
            const day = parseInt(p.day_of_week ?? 1, 10) || 1;
            const start = p.start_time ?? '09:00';
            const end = p.end_time ?? '10:30';
            const label = p.room_code ?? p.subject_code ?? 'unknown';
            const code = p.faculty_code ?? p.subject_code ?? 'unknown';

            occupied.add(day + '|' + start + '|' + end);

            slots.push({
                day,
                start,
                end,
                label,
                code,
                occupied: true,
            });
        }

        // Keep the grid sorted by room/faculty code.
        const order = [...new Set(slots.map((s) => s.day + '|' + s.start + '|' + s.end))].sort();

        // Render the week grid.
        const grid = global.document.getElementById('scheduler-grid');
        if (!grid) {
            return;
        }

        // Build the header.
        const head = grid.querySelector('.cal-head');
        for (let day = 1; day <= 7; day++) {
            const cell = head.querySelector('[data-day="' + day + '"]');
            if (cell) {
                cell.textContent = weekdayLabel(day);
            }
        }

        // Mark occupied slots.
        for (const p of rows) {
            const day = parseInt(p.day_of_week ?? 1, 10) || 1;
            const start = p.start_time ?? '09:00';
            const end = p.end_time ?? '10:30';
            const key = day + '|' + start + '|' + end;
            const cells = grid.querySelectorAll('[data-day="' + day + '"][data-start="' + start + '"][data-end="' + end + '"]');
            for (const cell of cells) {
                cell.classList.add('cal-slot-occupied');
            }
        }
    }

    function weekdayLabel(day) {
        const names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        return names[day - 1] || day;
    }

    function initGenerateQuestion() {
        // (Plug in a small inline preview modal if you want; this demo renders
        // the run result directly.)
    }

    if (!global.CalendarDriver) {
        global.CalendarDriver = {};
    }

    global.CalendarDriver.runPreview = async function (form) {
        const container = form.querySelector('.card-body');
        container.innerHTML = '<p class="text-muted">Running deterministic generator…</p>';

        try {
            const plan = await postForm(form, '<?= $route('generator.preview') ?>');
            renderResult(container, plan, plan.metrics);
        } catch (err) {
            container.innerHTML = '';
            error(container, err.message || 'Generator failed');
        }
    };

    global.CalendarDriver.commitDraft = async function (link) {
        const form = global.document.createElement('form');
        form.method = 'POST';
        form.action = '<?= $route('generator.commit') ?>';
        form.innerHTML = '<?= $e($csrf_meta) ?>' + link.dataset.csrf;

        try {
            const plan = await postForm(form, '<?= $route('generator.commit') ?>');
            renderResult(form.closest('.card-body'), plan, plan.metrics);
        } catch (err) {
            error(link, err.message || 'Commit failed');
        }
    };

    if (global.document.readyState === 'loading') {
        global.document.addEventListener('DOMContentLoaded', initGenerateQuestion);
    } else {
        initGenerateQuestion();
    }
})(typeof globalThis !== 'undefined' ? globalThis : typeof window !== 'undefined' ? window : global);
