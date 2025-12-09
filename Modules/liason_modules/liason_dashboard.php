<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mt-4 mb-4">
        <h1 class="h3 mb-0 text-gray-800 fw-bold">Liaison / Delivery Dashboard</h1>
        <button class="btn btn-sm btn-primary shadow-sm" onclick="loadDashboard()">
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
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Request List</div>
                            <div class="h3 mb-0 font-weight-bold text-dark" id="val_total_req">--</div>
                        </div>
                        <div class="col-auto"><i class="bi bi-file-earmark-medical text-gray-300 display-6"></i></div>
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
                            <div class="h3 mb-0 font-weight-bold text-dark" id="val_pending">--</div>
                        </div>
                        <div class="col-auto"><i class="bi bi-hourglass-split text-gray-300 display-6"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 py-2 border-start border-4 border-success rounded-3">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Delivered</div>
                            <div class="h3 mb-0 font-weight-bold text-dark" id="val_delivered">--</div>
                            <div class="small text-muted">Completed</div>
                        </div>
                        <div class="col-auto"><i class="bi bi-check-circle text-gray-300 display-6"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 py-2 border-start border-4 border-info rounded-3">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">On Delivery</div>
                            <div class="h3 mb-0 font-weight-bold text-dark" id="val_on_delivery">--</div>
                            <div class="small text-muted">In Transit</div>
                        </div>
                        <div class="col-auto"><i class="bi bi-truck text-gray-300 display-6"></i></div>
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
                    <div style="position: relative; height: 250px;">
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
                    <div style="position: relative; height: 250px;">
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
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Resident</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="recentList">
                                <tr><td colspan="2" class="text-center py-3 text-muted">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let statusChart = null;
    let topMedsChart = null;

    async function loadDashboard() {
        const start = document.getElementById('startDate').value;
        const end = document.getElementById('endDate').value;
        
        // POINTING TO LIAISON API
        let url = 'api/liason_dashboard_stats.php';
        if(start || end) url += `?start_date=${start}&end_date=${end}`;

        try {
            const res = await fetch(url);
            const data = await res.json();
            renderDashboard(data);
        } catch(err) {
            console.error(err);
        }
    }

    function renderDashboard(data) {
        // 1. Cards
        document.getElementById('val_total_req').innerText = data.totalRequests;
        document.getElementById('val_pending').innerText = data.pendingRequests;
        document.getElementById('val_delivered').innerText = data.delivered;
        document.getElementById('val_on_delivery').innerText = data.onDelivery;

        // 2. Status Chart (Pie)
        const ctx1 = document.getElementById('statusChart').getContext('2d');
        if(statusChart) statusChart.destroy();
        statusChart = new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: data.statusLabels,
                datasets: [{
                    data: data.statusValues,
                    backgroundColor: ['#f6c23e', '#1cc88a', '#4e73df', '#36b9cc', '#e74a3b', '#858796'],
                }]
            },
            options: { maintainAspectRatio: false, cutout: '70%' }
        });

        // 3. Top Meds Chart (Bar)
        const ctx2 = document.getElementById('topMedsChart').getContext('2d');
        if(topMedsChart) topMedsChart.destroy();
        topMedsChart = new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: data.topMedsLabels,
                datasets: [{
                    label: 'Qty',
                    data: data.topMedsValues,
                    backgroundColor: '#4e73df',
                    borderRadius: 4
                }]
            },
            options: { 
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } },
                plugins: { legend: { display: false } }
            }
        });

        // 4. Recent Activity
        const list = document.getElementById('recentList');
        if(data.recentActivity.length === 0) {
            list.innerHTML = '<tr><td colspan="2" class="text-center py-3 text-muted">No recent requests</td></tr>';
        } else {
            list.innerHTML = data.recentActivity.map(item => `
                <tr>
                    <td class="ps-3">
                        <div class="fw-bold text-dark">${item.resident}</div>
                        <div class="small text-muted">${item.date_human}</div>
                    </td>
                    <td><span class="badge bg-secondary">${item.status}</span></td>
                </tr>
            `).join('');
        }
    }

    document.getElementById('dashboardFilter').addEventListener('submit', (e) => {
        e.preventDefault();
        loadDashboard();
    });

    document.getElementById('resetBtn').addEventListener('click', () => {
        document.getElementById('dashboardFilter').reset();
        loadDashboard();
    });

    // Initial Load
    loadDashboard();
</script>