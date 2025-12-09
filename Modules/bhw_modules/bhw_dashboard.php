<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mt-4 mb-4">
        <h1 class="h3 mb-0 text-gray-800 fw-bold">Health & Medicine Dashboard</h1>
        <button class="btn btn-sm btn-primary shadow-sm" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise"></i> Refresh Data
        </button>
    </div>

    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body p-3">
            <form id="dashboardFilter" class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-bold small text-muted text-uppercase">Start Date</label>
                    <input type="date" class="form-control" name="start_date" id="startDate">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-bold small text-muted text-uppercase">End Date</label>
                    <input type="date" class="form-control" name="end_date" id="endDate">
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary fw-bold">Apply Filter</button>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="button" id="resetBtn" class="btn btn-outline-secondary fw-bold">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 py-2 border-start border-4 border-primary rounded-3">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Requests</div>
                            <div class="h3 mb-0 font-weight-bold text-gray-800" id="val_total_req">--</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-file-earmark-medical text-gray-300 display-6"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 py-2 border-start border-4 border-warning rounded-3">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Requests</div>
                            <div class="h3 mb-0 font-weight-bold text-gray-800" id="val_pending">--</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-hourglass-split text-gray-300 display-6"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 py-2 border-start border-4 border-success rounded-3">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Medicines Available</div>
                            <div class="h3 mb-0 font-weight-bold text-gray-800" id="val_meds_avail">--</div>
                            <div class="small text-muted">Categories</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-capsule text-gray-300 display-6"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 py-2 border-start border-4 border-info rounded-3">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Stock Count</div>
                            <div class="h3 mb-0 font-weight-bold text-gray-800" id="val_total_stock">--</div>
                            <div class="small text-muted">Total Units</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-boxes text-gray-300 display-6"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 rounded-4">
                <div class="card-header py-3 bg-white border-0">
                    <h6 class="m-0 fw-bold text-primary">Request Status Overview</h6>
                </div>
                <div class="card-body">
                    <div class="chart-pie pt-4 pb-2" style="position: relative; height: 250px;">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 rounded-4">
                <div class="card-header py-3 bg-white border-0">
                    <h6 class="m-0 fw-bold text-primary">Top 5 Requested Medicines</h6>
                </div>
                <div class="card-body">
                    <div class="chart-bar" style="position: relative; height: 250px;">
                        <canvas id="topMedsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 rounded-4">
                <div class="card-header py-3 bg-white border-0">
                    <h6 class="m-0 fw-bold text-primary">Recent Requests</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Resident</th>
                                    <th>Date</th>
                                    <th class="text-end pe-3">Status</th>
                                </tr>
                            </thead>
                            <tbody id="recentActivityList">
                                <tr><td colspan="3" class="text-center py-4 text-muted">Loading data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // --- Chart Objects ---
    let statusChartObj = null;
    let topMedsChartObj = null;

    // --- Helper: Fetch JSON ---
    async function fetchData(url) {
        try {
            const res = await fetch(url);
            if(!res.ok) throw new Error('Network error');
            return await res.json();
        } catch(e) {
            console.error(e);
            return null;
        }
    }

    // --- Main Render Function ---
    function renderDashboard(data) {
        if(!data) return;

        // 1. Update Cards
        document.getElementById('val_total_req').textContent = data.totalRequests;
        document.getElementById('val_pending').textContent = data.pendingRequests;
        document.getElementById('val_meds_avail').textContent = data.medsAvailable;
        document.getElementById('val_total_stock').textContent = data.totalStock;

        // 2. Render Status Chart (Pie)
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        if(statusChartObj) statusChartObj.destroy();
        
        statusChartObj = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(data.statusData),
                datasets: [{
                    data: Object.values(data.statusData),
                    backgroundColor: ['#f6c23e', '#36b9cc', '#4e73df', '#1cc88a', '#e74a3b', '#858796'],
                    hoverBorderColor: "rgba(234, 236, 244, 1)",
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                },
                cutout: '70%',
            },
        });

        // 3. Render Top Meds Chart (Bar)
        const medsCtx = document.getElementById('topMedsChart').getContext('2d');
        if(topMedsChartObj) topMedsChartObj.destroy();

        topMedsChartObj = new Chart(medsCtx, {
            type: 'bar',
            data: {
                labels: Object.keys(data.topMeds),
                datasets: [{
                    label: "Quantity Requested",
                    backgroundColor: "#4e73df",
                    hoverBackgroundColor: "#2e59d9",
                    borderColor: "#4e73df",
                    data: Object.values(data.topMeds),
                    borderRadius: 5,
                }],
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { borderDash: [2] } }
                },
                plugins: { legend: { display: false } }
            },
        });

        // 4. Render Recent Activity List
        const listBody = document.getElementById('recentActivityList');
        if(data.recentActivity.length === 0) {
            listBody.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-muted">No recent requests.</td></tr>';
        } else {
            listBody.innerHTML = data.recentActivity.map(item => {
                let badgeColor = 'secondary';
                if(item.status === 'Pending') badgeColor = 'warning text-dark';
                if(item.status === 'Approved') badgeColor = 'info text-dark';
                if(item.status === 'Delivered') badgeColor = 'success';
                if(item.status === 'Rejected') badgeColor = 'danger';

                return `
                <tr>
                    <td class="ps-3 fw-bold text-dark">${item.resident}</td>
                    <td class="small text-muted">${item.date_human}</td>
                    <td class="text-end pe-3">
                        <span class="badge bg-${badgeColor} rounded-pill px-2">${item.status}</span>
                    </td>
                </tr>
                `;
            }).join('');
        }
    }

    // --- Load Logic ---
    function loadDashboard() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        
        let url = 'api/bhw_dashboard_stats.php';
        const params = new URLSearchParams();
        if(startDate) params.append('start_date', startDate);
        if(endDate) params.append('end_date', endDate);
        
        if(params.toString()) url += '?' + params.toString();

        fetchData(url).then(renderDashboard);
    }

    // --- Events ---
    document.getElementById('dashboardFilter').addEventListener('submit', function(e) {
        e.preventDefault();
        loadDashboard();
    });

    document.getElementById('resetBtn').addEventListener('click', function() {
        document.getElementById('dashboardFilter').reset();
        loadDashboard();
    });

    // Initial Load
    loadDashboard();
</script>