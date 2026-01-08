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
    <title>Revenue Dashboard</title>
    
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
            --color-total: #4f46e5;
            --color-male: #3b82f6;
            --color-female: #ec4899;
            --color-urgent: #ef4444;
            --color-pending: #f59e0b;
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

        @media (max-width: 768px) {
            .stat-value { font-size: 2rem; }
        }
    </style>
</head>
<body>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <h1><?php echo htmlspecialchars($roleName ?? 'Revenue'); ?> Dashboard</h1>
        <span class="badge bg-white text-secondary border px-3 py-2 rounded-pill shadow-sm">
            <i class="bi bi-calendar-event me-1"></i> <?php echo date('F j, Y'); ?>
        </span>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Total Applications</div>
                <div class="stat-value" id="totalBesoApplications" style="color: var(--color-total);">--</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Male Applicants</div>
                <div class="stat-value" id="maleApplicants" style="color: var(--color-male);">--</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Female Applicants</div>
                <div class="stat-value" id="femaleApplicants" style="color: var(--color-female);">--</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="dashboard-card stat-card py-4 flex-row gap-4">
                <div class="text-center">
                    <div class="stat-title mb-1">Today's Urgent Requests</div>
                    <div class="stat-value" id="urgentRequests" style="color: var(--color-urgent); font-size: 2rem;">--</div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="dashboard-card stat-card py-4 flex-row gap-4">
                <div class="text-center">
                    <div class="stat-title mb-1">Today's Pending Appointments</div>
                    <div class="stat-value" id="pendingAppointments" style="color: var(--color-pending); font-size: 2rem;">--</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-6">
            <div class="dashboard-card p-4">
                <div class="chart-header">Age Distribution</div>
                <div style="position: relative; height: 320px; width: 100%;">
                    <canvas id="ageChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="dashboard-card p-4">
                <div class="chart-header" style="border-left-color: var(--color-female);">Gender Distribution</div>
                <div style="position: relative; height: 320px; width: 100%; display: flex; justify-content: center;">
                    <canvas id="genderChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="dashboard-card p-0">
                <div class="p-4 border-bottom">
                    <h5 class="fw-bold m-0" style="color: var(--text-main);">Recent Revenue Activity</h5>
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
                        <tbody id="recentRevenueActivity">
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
function renderRecentRevenue(list){
    const tbody = document.getElementById('recentRevenueActivity');
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

// Helper: Simple number animation
function animateValue(id, value) {
    const el = document.getElementById(id);
    if(el) el.textContent = parseInt(value).toLocaleString();
}

fetch('dashboard/dashboard_revenue.php')
  .then(response => response.json())
  .then(data => {
    // Animate Stats
    animateValue('totalBesoApplications', data.total ?? 0);
    animateValue('maleApplicants', data.males ?? 0);
    animateValue('femaleApplicants', data.females ?? 0);
    animateValue('urgentRequests', data.urgent ?? 0);
    animateValue('pendingAppointments', data.pending ?? 0);

    // --- Age Chart (Gradient Bar) ---
    const ageCtx = document.getElementById('ageChart').getContext('2d');
    const gradAge = ageCtx.createLinearGradient(0, 0, 0, 300);
    gradAge.addColorStop(0, 'rgba(79, 70, 229, 0.85)'); // Primary Indigo
    gradAge.addColorStop(1, 'rgba(79, 70, 229, 0.1)');

    new Chart(ageCtx, {
      type: 'bar',
      data: {
        labels: ['0-18', '19-35', '36-50', '51-65', '65+'],
        datasets: [{
          label: 'Applicants',
          data: (data.ageData || []).map(Number),
          backgroundColor: gradAge,
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

    // --- Gender Chart (Doughnut) ---
    new Chart(document.getElementById('genderChart'), {
      type: 'doughnut',
      data: {
        labels: Object.keys(data.genderData || {}),
        datasets: [{
          data: Object.values(data.genderData || {}).map(Number),
          backgroundColor: ['#3b82f6', '#ec4899'],
          borderWidth: 0,
          hoverOffset: 8
        }]
      },
      options: { 
        responsive: true, 
        maintainAspectRatio: false,
        cutout: '70%', 
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

    // Recent Activity
    renderRecentRevenue(data.recentActivities || []);
  })
  .catch(error => {
      console.error('Error fetching dashboard data:', error);
      document.getElementById('recentRevenueActivity').innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4">Error loading data.</td></tr>`;
  });
</script>

</body>
</html>