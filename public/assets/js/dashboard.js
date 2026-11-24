/**
 * SyncClass — dashboard charts (Phase 16, DASH-03).
 *
 * The page renders the widgets server-side; this file is the only consumer of
 * the chart endpoint. It:
 *
 *   1. reads the endpoint from `[data-dashboard-endpoint]` (never a dataset
 *      written into the template),
 *   2. uses the locally vendored Chart.js build the page loads first — the
 *      CSP only permits `script-src 'self'`, so a CDN build would be blocked;
 *      if the library is missing the charts degrade to a readable message,
 *   3. plots every `[data-chart-key]` canvas from the JSON response.
 *
 * The endpoint applies the same role scope as the widgets, and the response is
 * `no-store`, so nothing here caches or widens the data.
 */
(function () {
    'use strict';

    var MAROON = '#8c1d40';
    var GOLD = '#c9a227';
    var INK = '#2b2118';
    var MUTED = '#6b5b4b';
    var BORDER = '#e7ddcf';

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }

        callback();
    }

    function emptyChart(figure, message) {
        var canvas = figure.querySelector('canvas');

        if (canvas !== null) {
            canvas.remove();
        }

        var note = document.createElement('p');
        note.className = 'empty';
        note.textContent = message;
        figure.appendChild(note);
    }

    function baseOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    labels: { color: MUTED, boxWidth: 12, usePointStyle: true }
                },
                tooltip: { intersect: false, mode: 'index' }
            },
            scales: {
                x: {
                    ticks: { color: MUTED, maxRotation: 0, autoSkip: true },
                    grid: { color: BORDER }
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: MUTED, precision: 0 },
                    grid: { color: BORDER }
                }
            }
        };
    }

    function renderChart(figure, Chart, key, series) {
        if (series === undefined || series === null || !Array.isArray(series.labels) || series.labels.length === 0) {
            emptyChart(figure, 'No data for this chart in the selected term.');
            return;
        }

        var canvas = figure.querySelector('canvas');

        if (canvas === null) {
            return;
        }

        var options = baseOptions();

        if (series.type === 'doughnut') {
            options.cutout = '58%';
            delete options.scales;
        }

        if (key === 'workload') {
            options.indexAxis = 'y';
            options.scales.x.beginAtZero = true;
            options.scales.y.ticks.precision = 0;
        }

        if (key === 'utilization') {
            options.scales.y.suggestedMax = 100;
            options.scales.y.ticks.callback = function (value) { return value + '%'; };
        }

        new Chart(canvas, {
            type: series.type || 'bar',
            data: {
                labels: series.labels,
                datasets: (series.datasets || []).map(function (dataset) {
                    return {
                        label: dataset.label || '',
                        data: dataset.data || [],
                        backgroundColor: dataset.backgroundColor || MAROON,
                        borderColor: dataset.borderColor || MAROON,
                        borderWidth: series.type === 'doughnut' ? 1 : 0,
                        borderRadius: series.type === 'bar' ? 4 : 0,
                        maxBarThickness: 28
                    };
                })
            },
            options: options
        });
    }

    function renderAll(root, Chart, payload) {
        var charts = (payload && payload.charts) || {};

        root.querySelectorAll('[data-chart-key]').forEach(function (canvas) {
            var key = canvas.getAttribute('data-chart-key');
            var figure = canvas.closest('.chart-card') || canvas.parentElement;

            if (figure !== null) {
                renderChart(figure, Chart, key, charts[key]);
            }
        });
    }

    function updateStatus(status, text, isError) {
        if (status === null) {
            return;
        }

        status.textContent = text;
        status.classList.toggle('is-error', isError === true);
    }

    function init() {
        var root = document.querySelector('[data-dashboard-endpoint]');

        if (root === null) {
            return;
        }

        var endpoint = root.getAttribute('data-dashboard-endpoint');
        var status = root.querySelector('[data-dashboard-status]');

        if (endpoint === null || endpoint === '') {
            updateStatus(status, 'Charts are unavailable: no endpoint was configured.', true);
            return;
        }

        fetch(endpoint, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('The chart endpoint answered ' + response.status + '.');
            }

            return response.json();
        }).then(function (envelope) {
            if (!envelope || envelope.ok !== true || !envelope.data) {
                throw new Error('The chart endpoint returned an unexpected payload.');
            }

            if (window.Chart === undefined) {
                updateStatus(status, 'Chart.js did not load from the local asset build. The tables above still show the same numbers.', true);
                return;
            }

            renderAll(root, window.Chart, envelope.data);

            var generated = envelope.data.generated_at ? new Date(envelope.data.generated_at) : null;
            var stamp = generated !== null && !isNaN(generated.getTime())
                ? generated.toLocaleTimeString()
                : 'just now';

            updateStatus(status, 'Charts updated at ' + stamp + ' · scope: ' + (envelope.data.scope || 'unknown') + '.', false);
        }).catch(function (error) {
            updateStatus(status, 'Charts could not be loaded: ' + error.message + ' The widgets above are unaffected.', true);
        });
    }

    onReady(init);
})();
