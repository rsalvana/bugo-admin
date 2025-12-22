<?php
ini_set('display_errors', 0); 
ini_set('log_errors', 1);     
error_reporting(E_ALL);       

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}
if (!empty($_SESSION['force_change_password'])) {
    header("Location: /change_password.php");
    exit();
}
include 'class/session_timeout.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Residents Dashboard</title>
    
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
            
            /* Metric Colors */
            --color-residents: #4f46e5;
            --color-male: #3b82f6;
            --color-female: #ec4899;
            --color-urgent: #ef4444;
            --color-pending: #f59e0b;
            --color-regular: #10b981;
            --color-cases: #8b5cf6;
            --color-events: #06b6d4;
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
            font-size: 2.25rem;
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

        /* Custom Button */
        .btn-apply {
            background-color: var(--primary-color);
            border: none;
            color: white;
            font-weight: 500;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            transition: background 0.2s;
        }
        .btn-apply:hover {
            background-color: #4338ca;
        }

        @media (max-width: 768px) {
            .stat-value { font-size: 1.75rem; }
        }
    </style>
</head>
<body>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><?php echo htmlspecialchars($roleName ?? 'Admin'); ?> Dashboard</h1>
        <span class="badge bg-white text-secondary border px-3 py-2 rounded-pill shadow-sm">
            <i class="bi bi-calendar-event me-1"></i> <?php echo date('F j, Y'); ?>
        </span>
    </div>

    <div class="filter-card mb-5">
        <form id="reqFilters" class="row g-3 align-items-end">
            <div class="col-6 col-lg-2">
                <label class="form-label">Start date</label>
                <input type="date" class="form-control" name="start_date">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label">End date</label>
                <input type="date" class="form-control" name="end_date">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label">Type</label>
                <select class="form-select" name="req_type">
                    <option value="all">All Types</option>
                    <option value="urgent">Urgent</option>
                    <option value="regular">Regular</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <option value="all">All Cats</option>
                    <option value="beso">BESO</option>
                    <option value="cedula">Cedula</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">Any Status</option>
                    <option value="Pending">Pending</option>
                    <option value="Approved">Approved</option>
                    <option value="Rejected">Rejected</option>
                    <option value="Released">Released</option>
                </select>
            </div>
            <div class="col-6 col-lg-2 d-grid">
                <button type="button" id="applyReqFilters" class="btn btn-apply">
                    Filter Data
                </button>
            </div>
        </form>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Total Residents</div>
                <div class="stat-value" id="totalResidents" style="color: var(--color-residents);">--</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Male Population</div>
                <div class="stat-value" id="totalMales" style="color: var(--color-male);">--</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Female Population</div>
                <div class="stat-value" id="totalFemales" style="color: var(--color-female);">--</div>
            </div>
        </div>
    </div>

    <h5 class="text-muted fw-bold mb-3 ps-1" style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em;">Operational Overview</h5>
    <div class="row g-3 mb-5">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="dashboard-card stat-card py-4">
                <div class="stat-title">Urgent</div>
                <div class="stat-value" id="urgentRequests" style="color: var(--color-urgent); font-size: 1.75rem;">--</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="dashboard-card stat-card py-4">
                <div class="stat-title">Pending</div>
                <div class="stat-value" id="pendingAppointments" style="color: var(--color-pending); font-size: 1.75rem;">--</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="dashboard-card stat-card py-4">
                <div class="stat-title">Regular</div>
                <div class="stat-value" id="regularRequests" style="color: var(--color-regular); font-size: 1.75rem;">--</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="dashboard-card stat-card py-4">
                <div class="stat-title">Events</div>
                <div class="stat-value" id="upcomingEvents" style="color: var(--color-events); font-size: 1.75rem;">--</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="dashboard-card stat-card py-4">
                <div class="stat-title">Active Cases</div>
                <div class="stat-value" id="totalCases" style="color: var(--color-cases); font-size: 1.75rem;">--</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="dashboard-card stat-card py-4">
                <div class="stat-title">BESO</div>
                <div class="stat-value" id="totalBeso" style="color: var(--text-main); font-size: 1.75rem;">--</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="dashboard-card p-4">
                <div class="chart-header">Age Demographics</div>
                <div style="position: relative; height: 320px; width: 100%;">
                    <canvas id="ageChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="dashboard-card p-4">
                <div class="chart-header" style="border-left-color: var(--color-female);">Gender Ratio</div>
                <div style="position: relative; height: 320px; width: 100%; display: flex; justify-content: center;">
                    <canvas id="genderChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// --- UTILITIES ---
function buildReqQuery() {
    const form = document.getElementById('reqFilters');
    if (!form) return '';
    const p = new URLSearchParams();
    new FormData(form).forEach((v,k) => {
        v = (v || '').toString().trim();
        if (v && v.toLowerCase() !== 'all') p.set(k, v);
    });
    return p.toString();
}

async function fetchJSON(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    const text = await res.text();
    if (!res.ok) throw new Error(text || `HTTP ${res.status}`);
    try { return JSON.parse(text); } catch { throw new Error('Invalid JSON'); }
}

// --- RENDER LOGIC ---
function renderAll(data) {
    const {
        ageData,
        genderData,
        urgentRequests,
        regularRequests,
        pendingAppointments,
        upcomingEventsCount,
        totalCases,
        totalBeso
    } = data;

    // 1. Primary Stats
    const totalResidents = (ageData || []).reduce((a,b) => a+(+b||0), 0);
    animateValue('totalResidents', totalResidents);
    animateValue('totalMales', genderData?.Male ?? 0);
    animateValue('totalFemales', genderData?.Female ?? 0);

    // 2. Operational Stats
    animateValue('urgentRequests', urgentRequests ?? 0);
    animateValue('pendingAppointments', pendingAppointments ?? 0);
    // Note: data.regularRequests might override the destructured var depending on PHP
    animateValue('regularRequests', data.regularRequests ?? regularRequests ?? 0); 
    animateValue('upcomingEvents', upcomingEventsCount ?? 0);
    animateValue('totalCases', totalCases ?? 0);
    animateValue('totalBeso', totalBeso ?? 0);

    // 3. Charts
    initCharts(ageData, genderData);
}

// Helper: Simple number animation
function animateValue(id, value) {
    const el = document.getElementById(id);
    if(el) el.textContent = parseInt(value).toLocaleString();
}

let ageChartInstance = null;
let genderChartInstance = null;

function initCharts(ageData, genderData) {
    // --- AGE CHART ---
    const ageCtx = document.getElementById('ageChart').getContext('2d');
    
    // Gradient Fill for Age
    const ageGradient = ageCtx.createLinearGradient(0, 0, 0, 300);
    ageGradient.addColorStop(0, 'rgba(79, 70, 229, 0.85)');
    ageGradient.addColorStop(1, 'rgba(79, 70, 229, 0.1)');

    if(ageChartInstance) ageChartInstance.destroy();

    ageChartInstance = new Chart(ageCtx, {
        type: 'bar',
        data: {
            labels: ['0-18', '19-35', '36-50', '51-65', '65+'],
            datasets: [{
                label: 'Residents',
                data: ageData || [],
                backgroundColor: ageGradient,
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
                    displayColors: false,
                    titleFont: { family: 'Inter', size: 13 },
                    bodyFont: { family: 'Inter', size: 14, weight: 'bold' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9', drawBorder: false },
                    ticks: { font: { family: 'Inter' }, color: '#94a3b8' }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { family: 'Inter' }, color: '#64748b' }
                }
            }
        }
    });

    // --- GENDER CHART ---
    const genderCtx = document.getElementById('genderChart');
    if(genderChartInstance) genderChartInstance.destroy();

    genderChartInstance = new Chart(genderCtx, {
        type: 'doughnut',
        data: {
            labels: Object.keys(genderData || {}),
            datasets: [{
                data: Object.values(genderData || {}),
                backgroundColor: ['#3b82f6', '#ec4899', '#f59e0b'], // Male, Female, Other
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '75%', // Modern thin ring
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                        font: { family: 'Inter', size: 12 },
                        color: '#64748b'
                    }
                }
            }
        }
    });
}

// --- INIT ---
async function loadDashboard() {
    try {
        const qs = buildReqQuery();
        const url = 'dashboard/admin_dashboard.php' + (qs ? ('?'+qs) : '');
        const data = await fetchJSON(url);
        renderAll(data);
    } catch (e) {
        console.error("Dashboard Load Error:", e);
    }
}

document.getElementById('applyReqFilters')?.addEventListener('click', loadDashboard);

// Initial Load
loadDashboard();
</script>
</body>
</html>