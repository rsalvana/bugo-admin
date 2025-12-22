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
    <title>Residents Dashboard</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
        :root {
            --primary-color: #4f46e5; /* Indigo */
            --secondary-color: #64748b; /* Slate */
            --accent-color: #0ea5e9; /* Sky Blue */
            --success-color: #10b981; /* Emerald */
            --bg-color: #f8fafc; /* Very light slate */
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
        }

        body {
            background-color: var(--bg-color);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }

        h1 {
            font-weight: 700;
            color: var(--text-main);
            font-size: 2.25rem;
            letter-spacing: -0.025em;
            margin-bottom: 2rem;
        }

        /* Card Styling */
        .dashboard-card {
            background: var(--card-bg);
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
            overflow: hidden;
        }

        .dashboard-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025);
        }

        /* Stat Cards */
        .stat-card {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            position: relative;
        }
        
        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .stat-card:hover::after {
            opacity: 1;
        }

        .stat-label {
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            background: -webkit-linear-gradient(45deg, var(--text-main), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Chart Cards */
        .chart-container {
            padding: 1.5rem;
        }
        
        .card-header-custom {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 1.5rem;
            text-align: left;
            border-left: 4px solid var(--primary-color);
            padding-left: 1rem;
        }

        /* Recent Activity Table */
        .table-custom {
            margin-bottom: 0;
        }
        
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
        }
        
        .table-custom tbody tr:last-child td {
            border-bottom: none;
        }

        .badge-pill {
            border-radius: 50px;
            padding: 0.35em 0.8em;
            font-weight: 500;
            font-size: 0.75rem;
            letter-spacing: 0.02em;
        }
        
        .badge-module { background-color: #e2e8f0; color: #475569; }
        .badge-action { background-color: #e0f2fe; color: #0284c7; }

        @media (max-width: 768px) {
            h1 { font-size: 1.75rem; }
            .stat-number { font-size: 2rem; }
        }
    </style>
</head>
<body>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <h1><?php echo htmlspecialchars($roleName ?? 'Staff'); ?> Dashboard</h1>
        <span class="text-muted small"><?php echo date('l, F j, Y'); ?></span>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-label">Total Residents</div>
                <div class="stat-number" id="totalResidents">--</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-label">Male Population</div>
                <div class="stat-number" id="totalMales" style="-webkit-text-fill-color: #3b82f6;">--</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-label">Female Population</div>
                <div class="stat-number" id="totalFemales" style="-webkit-text-fill-color: #ec4899;">--</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-7">
            <div class="dashboard-card chart-container">
                <div class="card-header-custom">Age Distribution</div>
                <div style="position: relative; height: 300px; width: 100%;">
                    <canvas id="ageChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="dashboard-card chart-container">
                <div class="card-header-custom">Gender Ratio</div>
                <div style="position: relative; height: 300px; width: 100%; display: flex; justify-content: center;">
                    <canvas id="genderChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="dashboard-card p-0">
                <div class="p-4 border-bottom">
                    <h5 class="fw-bold m-0" style="color: var(--text-main);">Recent Activity Logs</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-custom table-hover align-middle">
                        <thead>
                            <tr>
                                <th style="width: 20%;">Date</th>
                                <th style="width: 15%;">Module</th>
                                <th style="width: 25%;">Action</th>
                                <th style="width: 40%;">Performed By</th>
                            </tr>
                        </thead>
                        <tbody id="recentPBActivity">
                            <tr><td colspan="4" class="text-center py-5 text-muted">Loading data...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// --- Chart Config & Data Fetching ---

// Modern Chart Colors
const chartColors = {
    primary: '#4f46e5',
    secondary: '#0ea5e9',
    male: '#3b82f6',
    female: '#ec4899',
    bg: '#f8fafc'
};

function renderRecentPB(list) {
    const tbody = document.getElementById('recentPBActivity');
    if (!tbody) return;

    const esc = (s) => String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    if (!list || !list.length) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center py-5 text-muted">No recent activity found.</td></tr>`;
        return;
    }

    tbody.innerHTML = list.map(r => `
        <tr>
            <td class="text-muted small fw-medium">${esc(r.date_human || r.date)}</td>
            <td><span class="badge badge-pill badge-module">${esc((r.module || '').toUpperCase())}</span></td>
            <td><span class="badge badge-pill badge-action">${esc(r.action)}</span></td>
            <td class="fw-semibold text-dark">${esc(r.action_by)}</td>
        </tr>
    `).join('');
}

fetch('dashboard/admin_dashboard.php')
    .then(r => r.json())
    .then(data => {
        const ageData    = (data.ageData || []).map(Number);
        const genderData = data.genderData || {};

        // Update Stat Cards
        const totalResidents = ageData.reduce((a, b) => a + b, 0);
        document.getElementById('totalResidents').innerText = totalResidents.toLocaleString();
        document.getElementById('totalMales').innerText     = (genderData.Male ?? 0).toLocaleString();
        document.getElementById('totalFemales').innerText   = (genderData.Female ?? 0).toLocaleString();

        // --- Age Chart ---
        const ageCtx = document.getElementById('ageChart').getContext('2d');
        // Create gradient
        const ageGradient = ageCtx.createLinearGradient(0, 0, 0, 300);
        ageGradient.addColorStop(0, 'rgba(79, 70, 229, 0.8)'); // Primary color top
        ageGradient.addColorStop(1, 'rgba(79, 70, 229, 0.1)'); // Faded bottom

        new Chart(ageCtx, {
            type: 'bar',
            data: {
                labels: ['0-18', '19-35', '36-50', '51-65', '65+'],
                datasets: [{
                    label: 'Residents',
                    data: ageData,
                    backgroundColor: ageGradient,
                    borderColor: chartColors.primary,
                    borderWidth: 1,
                    borderRadius: 6, // Rounded bars
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
                        bodyFont: { family: 'Inter', size: 13 }
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

        // --- Gender Chart ---
        new Chart(document.getElementById('genderChart'), {
            type: 'doughnut',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: [genderData.Male ?? 0, genderData.Female ?? 0],
                    backgroundColor: [chartColors.male, chartColors.female],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%', // Thinner doughnut
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: { family: 'Inter', size: 12 }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        padding: 12,
                        cornerRadius: 8
                    }
                }
            }
        });

        // Load Activity
        renderRecentPB(data.recentActivities);
    })
    .catch(err => {
        console.error('Error fetching dashboard data:', err);
        document.getElementById('recentPBActivity').innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4">Failed to load data.</td></tr>`;
    });
</script>
</body>
</html>