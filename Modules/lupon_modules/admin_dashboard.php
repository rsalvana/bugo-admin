<?php
ini_set('display_errors', 0); 
ini_set('log_errors', 1);     
error_reporting(E_ALL);       

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
include 'class/session_timeout.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupong Tagapamayapa Dashboard</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
        :root {
            --primary-color: #4f46e5; /* Indigo */
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            
            /* Metric Colors specific to Lupon */
            --color-total: #4f46e5;
            --color-ongoing: #f59e0b; /* Amber */
            --color-resolved: #10b981; /* Emerald */
            --color-filed: #06b6d4;    /* Cyan */
            --color-hearings: #ef4444; /* Red */
        }

        body {
            background-color: var(--bg-color);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
        }

        h1 {
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.025em;
        }

        /* Filter Panel Styling */
        .filter-card {
            background: var(--card-bg);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
        }
        
        .form-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.35rem;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border-color: #cbd5e1;
            font-size: 0.9rem;
            padding: 0.5rem 0.75rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        /* Dashboard Cards */
        .dashboard-card {
            background: var(--card-bg);
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
            overflow: hidden;
        }

        .dashboard-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        }

        /* Stat Card Internal Styling */
        .stat-card {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
        }

        .stat-title {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1.2;
        }

        /* Chart Header */
        .chart-header {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
            padding-left: 1rem;
            border-left: 4px solid var(--primary-color);
        }

        /* Table Styling */
        .table-custom { margin-bottom: 0; }
        .table-custom thead th {
            background-color: #f1f5f9;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            border-bottom: none;
            padding: 1rem;
        }
        .table-custom tbody td {
            padding: 1rem;
            vertical-align: middle;
            color: var(--text-main);
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.95rem;
            white-space: nowrap;
        }

        /* Buttons */
        .btn-apply {
            background-color: var(--primary-color);
            border: none;
            color: white;
            font-weight: 500;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            transition: background 0.2s;
        }
        .btn-apply:hover { background-color: #4338ca; }
        
        .btn-reset {
            border: 1px solid #cbd5e1;
            color: var(--text-muted);
            font-weight: 500;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            background: white;
            transition: all 0.2s;
        }
        .btn-reset:hover { background-color: #f1f5f9; color: var(--text-main); }

        @media (max-width: 768px) {
            .stat-value { font-size: 2rem; }
        }
    </style>
</head>
<body>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Lupong Tagapamayapa</h1>
        <span class="badge bg-white text-secondary border px-3 py-2 rounded-pill shadow-sm">
            <i class="bi bi-calendar-event me-1"></i> <?php echo date('F j, Y'); ?>
        </span>
    </div>

    <div class="filter-card mb-5">
        <form id="caseFilters" class="row g-3 align-items-end">
            <div class="col-6 col-lg-3">
                <label class="form-label">Start Date (Filed)</label>
                <input type="date" class="form-control" name="start_date">
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label">End Date (Filed)</label>
                <input type="date" class="form-control" name="end_date">
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label">Offense</label>
                <select class="form-select" name="offense" id="offenseSelect">
                    <option value="all">All Offenses</option>
                    </select>
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="all">All Statuses</option>
                    <option value="Conciliated">Conciliated</option>
                    <option value="Mediated">Mediated</option>
                    <option value="Dismissed">Dismissed</option>
                    <option value="Withdrawn">Withdrawn</option>
                    <option value="Ongoing">Ongoing</option>
                    <option value="Arbitration">Arbitration</option>
                </select>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2 mt-4">
                <button type="button" id="resetFilters" class="btn btn-reset">
                    Reset
                </button>
                <button type="button" id="applyFilters" class="btn btn-apply px-4">
                    Apply Filters
                </button>
            </div>
        </form>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Total Cases</div>
                <div class="stat-value" id="totalCases" style="color: var(--color-total);">--</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Ongoing</div>
                <div class="stat-value" id="ongoingCases" style="color: var(--color-ongoing);">--</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Resolved / Closed</div>
                <div class="stat-value" id="resolvedCases" style="color: var(--color-resolved);">--</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="dashboard-card stat-card py-4 flex-row gap-4">
                <div class="text-center">
                    <div class="stat-title mb-1">Filed Today</div>
                    <div class="stat-value" id="todayFiled" style="color: var(--color-filed); font-size: 2rem;">--</div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="dashboard-card stat-card py-4 flex-row gap-4">
                <div class="text-center">
                    <div class="stat-title mb-1">Upcoming Hearings (30 Days)</div>
                    <div class="stat-value" id="upcomingHearings" style="color: var(--color-hearings); font-size: 2rem;">--</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-6">
            <div class="dashboard-card p-4">
                <div class="chart-header">Cases by Offense Type</div>
                <div style="position: relative; height: 320px; width: 100%;">
                    <canvas id="offenseChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="dashboard-card p-4">
                <div class="chart-header" style="border-left-color: var(--color-resolved);">Case Trend (Last 6 Months)</div>
                <div style="position: relative; height: 320px; width: 100%;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="dashboard-card p-0">
                <div class="p-4 border-bottom">
                    <h5 class="fw-bold m-0" style="color: var(--text-main);">Recent Lupon Activity</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-custom table-hover align-middle">
                        <thead>
                            <tr>
                                <th style="width: 20%;">Date</th>
                                <th style="width: 20%;">Module</th>
                                <th style="width: 25%;">Action</th>
                                <th>Performed By</th>
                            </tr>
                        </thead>
                        <tbody id="recentLuponActivity">
                            <tr><td colspan="4" class="text-center py-5 text-muted">Loading data...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// --- UTILITIES ---
const labelYM = ym => {
    const [y, m] = (ym || '').split('-').map(Number);
    return new Date(y || 2000, (m || 1) - 1, 1)
      .toLocaleString(undefined, { month: 'short', year: 'numeric' });
};

let offenseChart = null, trendChart = null, offenseOptionsLoaded = false;
const destroy = c => c && c.destroy && c.destroy();

function getQueryFromForm(form) {
    if (!form) return '';
    const p = new URLSearchParams();
    new FormData(form).forEach((v, k) => { if (v && v !== 'all') p.set(k, v); });
    return p.toString();
}

function populateOffenseOptions(options) {
    if (offenseOptionsLoaded) return;
    const sel = document.getElementById('offenseSelect');
    if (!sel || !Array.isArray(options)) return;
    options.forEach(opt => {
        const o = document.createElement('option');
        o.value = opt;
        o.textContent = opt;
        sel.appendChild(o);
    });
    offenseOptionsLoaded = true;
}

// Render Helper for Activity Table
function renderRecentLupon(list){
    const tbody = document.getElementById('recentLuponActivity');
    if (!tbody) return;
    const esc = (s) => String(s ?? '')
      .replaceAll('&','&amp;').replaceAll('<','&lt;')
      .replaceAll('>','&gt;').replaceAll('"','&quot;')
      .replaceAll("'",'&#39;');

    if (!list || !list.length) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center py-5 text-muted">No recent activity found.</td></tr>`;
        return;
    }
    tbody.innerHTML = list.map(r => `
        <tr>
            <td class="text-muted small fw-medium">${esc(r.date_human || r.date)}</td>
            <td><span class="badge rounded-pill bg-light text-dark border">${esc((r.module || '').toUpperCase())}</span></td>
            <td><span class="badge rounded-pill bg-primary bg-opacity-10 text-primary">${esc((r.action || '').toUpperCase())}</span></td>
            <td class="fw-semibold text-dark">${esc(r.action_by)}</td>
        </tr>
    `).join('');
}

async function fetchJSON(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    const ct = res.headers.get('content-type') || '';
    const text = await res.text();
    if (!ct.includes('application/json')) {
        throw new Error(`Expected JSON (${res.status}), got ${ct}. Snippet: ${text.slice(0,200)}`);
    }
    const data = JSON.parse(text);
    if (!res.ok) {
        const msg = data.error || `HTTP ${res.status}`;
        throw new Error(msg);
    }
    return data;
}

// Helper for simple number animation
function animateValue(id, value) {
    const el = document.getElementById(id);
    if(el) el.textContent = parseInt(value).toLocaleString();
}

function render(data) {
    // Animate Stats
    animateValue('totalCases', data.totalCases ?? 0);
    animateValue('ongoingCases', data.ongoingCases ?? 0);
    animateValue('resolvedCases', data.resolvedCases ?? 0);
    animateValue('todayFiled', data.todayFiled ?? 0);
    animateValue('upcomingHearings', data.upcomingHearings ?? 0);

    populateOffenseOptions(data.offenseOptions || []);

    // --- Offense Chart (Gradient Bar) ---
    const offenseLabels = Object.keys(data.offenseByType || {});
    const offenseValues = Object.values(data.offenseByType || {});
    
    const offenseCtx = document.getElementById('offenseChart').getContext('2d');
    const gradOffense = offenseCtx.createLinearGradient(0, 0, 0, 300);
    gradOffense.addColorStop(0, 'rgba(79, 70, 229, 0.85)'); // Primary Indigo
    gradOffense.addColorStop(1, 'rgba(79, 70, 229, 0.1)');

    destroy(offenseChart);
    offenseChart = new Chart(offenseCtx, {
        type: 'bar',
        data: {
            labels: offenseLabels,
            datasets: [{
                label: 'Cases',
                data: offenseValues,
                backgroundColor: gradOffense,
                borderRadius: 6,
                borderWidth: 0,
                barPercentage: 0.6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    padding: 12,
                    cornerRadius: 8,
                    titleFont: { family: 'Inter', size: 13 },
                    bodyFont: { family: 'Inter', size: 14 }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, font: { family: 'Inter' }, color: '#94a3b8' },
                    grid: { color: '#f1f5f9', drawBorder: false }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { family: 'Inter' }, color: '#64748b' }
                }
            }
        }
    });

    // --- Trend Chart (Smooth Line with Fill) ---
    const trendLabels = Object.keys(data.casesByMonth || {}).map(labelYM);
    const trendValues = Object.values(data.casesByMonth || {});
    
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const gradTrend = trendCtx.createLinearGradient(0, 0, 0, 300);
    gradTrend.addColorStop(0, 'rgba(16, 185, 129, 0.4)'); // Emerald
    gradTrend.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

    destroy(trendChart);
    trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'Filed',
                data: trendValues,
                borderColor: '#10b981',
                backgroundColor: gradTrend,
                fill: true,
                tension: 0.4, // Smoother curve
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    padding: 12,
                    cornerRadius: 8,
                    titleFont: { family: 'Inter', size: 13 }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, font: { family: 'Inter' }, color: '#94a3b8' },
                    grid: { color: '#f1f5f9', drawBorder: false }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { family: 'Inter' }, color: '#64748b' }
                }
            }
        }
    });

    // Render Recent Activity
    renderRecentLupon(data.recentActivities || []);
}

function loadCases() {
    const form = document.getElementById('caseFilters'); 
    const qs   = getQueryFromForm(form);
    const url  = 'dashboard/dashboard_lupon.php' + (qs ? ('?' + qs) : '');
    
    fetchJSON(url).then(render).catch(err => {
        console.error('Cases dashboard error:', err);
        document.getElementById('totalCases').textContent = '—';
        document.getElementById('recentLuponActivity').innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4">Error loading data.</td></tr>`;
    });
}

const applyBtn = document.getElementById('applyFilters');
if (applyBtn) applyBtn.addEventListener('click', loadCases);

const resetBtn = document.getElementById('resetFilters');
if (resetBtn) resetBtn.addEventListener('click', () => {
    const form = document.getElementById('caseFilters');
    if (form) form.reset();
    loadCases();
});

// Initial load
loadCases();
</script>
</body>
</html>