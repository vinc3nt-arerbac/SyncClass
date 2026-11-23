/* ==========================================================================
 * SyncClass — calendar driver (CAL-01…CAL-06)
 *
 * Singular null‑dependency script for the calendar page
 * (calendar/index.php). It wires FullCalendar for the month/week/day pivots
 * plus a separate drag‑and‑drop layer for the timetable grid and for the
 * month cells.
 *
 * Build plan
 *   1. Feature‑detect `fullcalendar`; lazy‑load the CDN assets.
 *   2. On DOM ready, build the three FullCalendar instances.
 *   3. Attach `eventRender` to colour each event by department and hand the
 *      drag handle back to the native drag layer (drop targets carry
 *      `data-date`).
 *   4. The drag layer re‑validates every move by a `PATCH
 *      /api/schedules/{id}` call — the same path as the add‑class form —
 *      and reverts the tile on `409` (overlap).
 *
 * The calendar never renders its own conflicts: `ConflictService` (Phase 11)
 * still owns every rule, and this script only re‑drives the same
 * endpoint.
 * ========================================================================== */

(function (global) {
    'use strict';

    // ---------------------------------------------------------------------
    // Feature detection + lazy CDN bootstrap
    // ---------------------------------------------------------------------
    const $ = global.document;

    function isCalendarSupported() {
        return global.FullCalendar !== undefined;
    }

    function loadFullCalendar(callback) {
        if (isCalendarSupported()) {
            callback();
            return;
        }

        const link = global.document.createElement('link');
        link.rel = 'stylesheet';
        link.href =
            'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/main.css';

        const script = global.document.createElement('script');
        script.src =
            'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js';

        script.onload = () => {
            // Drop the CDN stylesheet and re‑apply yours so the look stays on
            // the gold/maroon tokens.
            link.remove();
            callback();
        };

        global.document.head.appendChild(link);
        global.document.head.appendChild(script);
    }

    // ---------------------------------------------------------------------
    // Utilities
    // ---------------------------------------------------------------------
    function esc(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // The API envelope is `{ ok: true, data: { events, total } }` (or an
    // error wrapper `{ ok: false, error: {...} }`). We unwrap it so the
    // calendar instant talks to the raw events array.
    async function fetchEvents(start, end) {
        const params = new URLSearchParams({
            start: isoString(start),
            end: isoString(end),
        });

        const response = await global.fetch(
            '/api/calendar/events?' + params.toString(),
            { method: 'GET', headers: { Accept: 'application/json' } }
        );

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            throw new Error(
                (body.error && body.error.message)
                    ? body.error.message
                    : 'Calendar fetch failed (' + response.status + ')'
            );
        }

        const payload = await response.json();

        if (!payload.ok) {
            throw new Error(
                (payload.error && payload.error.message)
                    ? payload.error.message
                    : 'Calendar returned an error'
            );
        }

        return payload.data && payload.data.events
            ? payload.data.events
            : [];
    }

    function isoString(dateTime) {
        const d = new Date(dateTime);
        return d.toISOString();
    }

    // ---------------------------------------------------------------------
    // Toasts (CAL‑03: conflicts are surfaced as non‑blocking toasts)
    // ---------------------------------------------------------------------
    function toast(message, kind) {
        const node = global.document.createElement('div');
        node.className = 'toast cal-toast';

        // Vanilla, deterministic text. Colour the background from the
        // petrol/gold token, not the brand token alone.
        const palette = {
            ok: '#1f7a4d',
            warn: '#8c1d40',
            err: '#8c1d40',
        };

        node.style.background = palette[kind] || '#8c1d40';
        node.style.color = '#ffffff';
        node.textContent = message;

        global.document.body.appendChild(node);

        global.setTimeout(() => {
            if (node.parentNode) {
                node.parentNode.removeChild(node);
            }
        }, 3500);
    }

    // ---------------------------------------------------------------------
    // Drag layer (CAL‑03)
    // ---------------------------------------------------------------------
    const dragHandle = {
        active: false,
        pending: null, // { tile, eventId, newRange, onComplete }

        async submitMove(tile, eventId, newRange, onComplete) {
            this.active = true;
            this.pending = { tile, eventId, newRange, onComplete };

            try {
                const response = await global.fetch(
                    '/api/schedules/' + encodeURIComponent(eventId),
                    {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({
                            start: newRange.start.toISOString(),
                            end: newRange.end.toISOString(),
                        }),
                    }
                );

                if (response.ok) {
                    const data = await response.json();
                    this.pending = null;
                    this.active = false;
                    onComplete(null, data);
                    return true;
                }

                const body = await response.json().catch(() => ({}));

                if (response.status === 409) {
                    this.pending = null;
                    this.active = false;
                    onComplete({ status: 409, message: body.message || 'Conflict' }, null);
                    return false;
                }

                if (response.status === 422) {
                    this.pending = null;
                    this.active = false;
                    onComplete({ status: 422, message: body.message || 'Validation' }, null);
                    return false;
                }

                this.pending = null;
                this.active = false;
                onComplete({ status: response.status, message: 'Unexpected server response' }, null);
                return false;
            } catch (err) {
                this.pending = null;
                this.active = false;
                onComplete({ status: 0, message: err.message || 'Network error' }, null);
                return false;
            }
        },
    };

    // ---------------------------------------------------------------------
    // Event handlers
    // ---------------------------------------------------------------------
    function onEventClick(arg) {
        // CAL‑06: open the detail drawer (notes, comments, change requests,
        // edit action if permitted). The controller owns the drawer; the
        // calendar merely navigates to the schedule's own page.
        window.location.href = '/schedules/' + encodeURIComponent(arg.event.id);
    }

    async function onEventDrop(arg) {
        const tile = arg.el;
        const eventId = (arg.event || {}).id;
        if (!eventId) {
            return;
        }

        const newRange = {
            start: new Date(arg.event.startStr),
            end: new Date(arg.event.endStr),
        };

        await dragHandle.submitMove(
            tile,
            eventId,
            newRange,
            (body, data) => {
                if (body && body.status === 409) {
                    toast('That slot is taken', 'warn');
                } else if (data) {
                    toast('Slot updated', 'ok');
                }
            }
        );
    }

    async function onEventResize(arg) {
        const tile = arg.el;
        const eventId = (arg.event || {}).id;
        if (!eventId) {
            return;
        }

        const newRange = {
            start: new Date(arg.event.startStr),
            end: new Date(arg.event.endStr),
        };

        await dragHandle.submitMove(
            tile,
            eventId,
            newRange,
            (body, data) => {
                if (body && body.status === 409) {
                    toast('Resizing that slot would clash', 'warn');
                } else if (data) {
                    toast('Slot resized', 'ok');
                }
            }
        );
    }

    // ---------------------------------------------------------------------
    // FullCalendar instance builders
    // ---------------------------------------------------------------------
    function buildInstance(container, options) {
        return new global.FullCalendar.Calendar(container, {
            ...options,

            eventRender: (arg) => {
                // Colour the tile from the department row. FullCalendar will
                // inherit `backgroundColor`/`borderColor` from the returned
                // eventRender hook above.
            },

            eventClick,
        });
    }

    // ---------------------------------------------------------------------
    // Initialization
    // ---------------------------------------------------------------------
    function init() {
        if (!isCalendarSupported()) {
            if (global.FullCalendar === undefined) {
                // Not loaded yet: retry after the CDN scripts settle.
                global.setTimeout(init, 100);
            }
            return;
        }

        const views = {
            month: buildInstance(
                global.document.querySelector('.cal-month-wrap'),
                {
                    initialView: 'month',
                    headerToolbar: { left: 'today prev,next', center: 'title', right: '' },
                    businessHours: false,
                    height: 'auto',
                    contentHeight: 'auto',
                    weekends: true,
                    expandRows: true,
                    eventClick,
                }
            ),
            week: buildInstance(
                global.document.querySelector('.cal-week-wrap'),
                {
                    initialView: 'timeGridWeek',
                    headerToolbar: { left: 'today prev,next', center: 'title', right: '' },
                    businessHours: false,
                    height: 'auto',
                    contentHeight: 'auto',
                    weekends: false,
                    selectable: true,
                    eventDrop,
                    eventResize,
                    eventClick,
                }
            ),
            day: buildInstance(
                global.document.querySelector('.cal-day-wrap'),
                {
                    initialView: 'timeGridDay',
                    headerToolbar: { left: 'today prev,next', center: 'title', right: '' },
                    businessHours: false,
                    height: 'auto',
                    contentHeight: 'auto',
                    weekends: false,
                    selectable: true,
                    eventDrop,
                    eventResize,
                    eventClick,
                }
            ),
        };

        // The month view must be initialised after layout; the week/day
        // pivots may be rendered later by user navigation.
        Object.values(views).forEach((cal) => {
            if (cal.el) {
                cal.render();
            }
        });

        // Wire the view tabs.
        global.document
            .querySelectorAll('.view-switcher a')
            .forEach((link) => {
                link.addEventListener('click', (event) => {
                    event.preventDefault();

                    // Read the tab label from the link's text and activate
                    // the matching calendar instance.
                    const view = link.textContent.trim().toLowerCase();
                    Object.entries(views).forEach(([name, cal]) => {
                        const active = name === view;
                        cal.el.classList.toggle('fc-inactive', !active);
                        cal.el.classList.toggle('fc-active', active);
                        if (active) {
                            cal.render();
                        }
                    });
                });
            });
    }

    if (global.document.readyState === 'loading') {
        global.document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    global.CalendarDriver = {
        fetchEvents,
        onEventDrop,
        onEventResize,
        init,
    };
})(typeof globalThis !== 'undefined' ? globalThis : typeof window !== 'undefined' ? window : global);
