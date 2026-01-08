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
    <title>Multimedia Dashboard</title>
    
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
            --color-upcoming: #06b6d4; /* Cyan */
            --color-total: #10b981;    /* Emerald */
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
            padding: 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
        }

        .stat-title {
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
        }

        .stat-value {
            font-size: 3rem;
            font-weight: 700;
            line-height: 1;
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
            .stat-value { font-size: 2.5rem; }
        }
    </style>
</head>
<body>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <h1><?php echo htmlspecialchars($roleName ?? 'Multimedia'); ?> Dashboard</h1>
        <span class="badge bg-white text-secondary border px-3 py-2 rounded-pill shadow-sm">
            <i class="bi bi-calendar-event me-1"></i> <?php echo date('F j, Y'); ?>
        </span>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Upcoming Events (This Month)</div>
                <div class="stat-value" id="upcomingEventsCount" style="color: var(--color-upcoming);">--</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="dashboard-card stat-card">
                <div class="stat-title">Total Events (Overall)</div>
                <div class="stat-value" id="totalEventsCount" style="color: var(--color-total);">--</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-6">
            <div class="dashboard-card p-4">
                <div class="chart-header">Event Distribution (By Name)</div>
                <div style="position: relative; height: 320px; width: 100%; display: flex; justify-content: center;">
                    <canvas id="eventNameChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="dashboard-card p-4">
                <div class="chart-header" style="border-left-color: var(--color-total);">Event Locations</div>
                <div style="position: relative; height: 320px; width: 100%;">
                    <canvas id="eventLocationChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="dashboard-card p-0">
                <div class="p-4 border-bottom">
                    <h5 class="fw-bold m-0" style="color: var(--text-main);">Recent Multimedia Activity</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-custom table-hover align-middle">
                        <thead>
                            <tr>
                                <th style="width: 20%;">Date</th>
                                <th style="width: 20%;">Module</th>
                                <th style="width: 20%;">Action</th>
                                <th>Performed By</th>
                            </tr>
                        </thead>
                        <tbody id="recentMMActivity">
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
function renderRecentMM(list){
    const tbody = document.getElementById('recentMMActivity');
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

fetch('dashboard/dashboard_multimedia.php')
  .then(res => res.json())
  .then(data => {
    const eventNameData     = data.eventNameData || {};
    const locationData      = data.locationData || {};
    const upcomingCount     = data.upcomingEventsCount ?? 0;
    const totalEventsCount  = data.totalEventsCount ?? 0;

    // Animate Stats
    animateValue('upcomingEventsCount', upcomingCount);
    animateValue('totalEventsCount', totalEventsCount);

    // --- Event Name Chart (Doughnut) ---
    new Chart(document.getElementById('eventNameChart'), {
      type: 'doughnut',
      data: {
        labels: Object.keys(eventNameData),
        datasets: [{
          data: Object.values(eventNameData),
          backgroundColor: [
            '#4f46e5', '#06b6d4', '#10b981', '#f59e0b', 
            '#ef4444', '#8b5cf6', '#ec4899', '#64748b'
          ],
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

    // --- Location Chart (Gradient Bar) ---
    const locCtx = document.getElementById('eventLocationChart').getContext('2d');
    const gradLoc = locCtx.createLinearGradient(0, 0, 0, 300);
    gradLoc.addColorStop(0, 'rgba(16, 185, 129, 0.8)'); // Emerald
    gradLoc.addColorStop(1, 'rgba(16, 185, 129, 0.1)');

    new Chart(locCtx, {
      type: 'bar',
      data: {
        labels: Object.keys(locationData),
        datasets: [{
          label: 'Events',
          data: Object.values(locationData),
          backgroundColor: gradLoc,
          borderRadius: 6,
          borderWidth: 0,
          barPercentage: 0.5
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

    // Recent Activity
    renderRecentMM(data.recentActivities || []);
  })
  .catch(error => {
      console.error('Error fetching data:', error);
      document.getElementById('recentMMActivity').innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4">Error loading data.</td></tr>`;
  });
</script>

</body>
</html>