<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> <!--mao ni e dugang kada page -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-5">
    <h1 class="text-center mb-5 fw-bold text-dark">Indigency Staff Dashboard</h1>

    <div class="row g-4 mb-4">
        <div class="col-6 col-md-4">
            <div class="card text-center p-4 shadow-sm border-0">
                <div class="stat-title mb-1 text-muted fw-bold">TOTAL INDIGENCY APPS</div>
                <div class="stat-value text-primary fw-bold fs-1" id="totalIndigencyApp">--</div> 
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center p-4 shadow-sm border-0">
                <div class="stat-title mb-1 text-muted fw-bold">TODAY'S REQUESTS</div>
                <div class="stat-value text-danger fw-bold fs-1" id="todayRequests">--</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center p-4 shadow-sm border-0">
                <div class="stat-title mb-1 text-muted fw-bold">PENDING</div>
                <div class="stat-value text-warning fw-bold fs-1" id="pendingAppointments">--</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card p-4 shadow-sm border-0" style="min-height: 400px;">
                <h5 class="fw-bold text-center mb-3">Gender Distribution</h5>
                <div style="position: relative; height: 300px; width: 100%;">
                    <canvas id="genderChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card p-4 shadow-sm border-0" style="min-height: 400px;">
                <h5 class="fw-bold text-center mb-3">Recent Activity</h5>
                <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                    <table class="table table-sm align-middle mb-0 table-hover">
                        <thead class="table-light sticky-top">
                            <tr><th>Date</th><th>Action</th><th>Module</th></tr>
                        </thead>
                        <tbody id="recentActivityTable">
                            <tr><td colspan="3" class="text-center py-3">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// --- LOGOUT FUNCTION (Added) ---
// This makes the "Logout" link in your navbar work
function confirmLogout() {
    Swal.fire({
        title: 'Are you sure?',
        text: "You will be logged out.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, logout'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Logging out...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            setTimeout(() => {
                window.location.href = "logout.php";
            }, 1000);
        }
    });
    return false;
}

document.addEventListener("DOMContentLoaded", function() {
    // 2. FETCH DATA FROM YOUR BACKEND
    fetch('dashboard/dashboard_indigency.php')
      .then(response => {
          if (!response.ok) { throw new Error("HTTP error " + response.status); }
          return response.json();
      })
      .then(data => {
          // 3. UPDATE NUMBER CARDS
          if(document.getElementById('totalIndigencyApp')) 
              document.getElementById('totalIndigencyApp').textContent = data.total ?? 0;
          
          if(document.getElementById('todayRequests')) 
              document.getElementById('todayRequests').textContent = data.urgent ?? 0; 
          
          if(document.getElementById('pendingAppointments')) 
              document.getElementById('pendingAppointments').textContent = data.pending ?? 0;
          
          // 4. UPDATE RECENT ACTIVITY TABLE
          const tbody = document.getElementById('recentActivityTable');
          if (data.recentActivities && data.recentActivities.length > 0) {
              tbody.innerHTML = data.recentActivities.map(r => `
                  <tr>
                      <td class="small">${r.date_human || r.created_at}</td>
                      <td><span class="badge bg-info text-dark">${r.action}</span></td>
                      <td class="small text-muted">${r.module || 'System'}</td>
                  </tr>
              `).join('');
          } else {
              tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No recent activity</td></tr>';
          }

          // 5. RENDER GENDER CHART
          if(document.getElementById('genderChart')) {
              const ctx = document.getElementById('genderChart').getContext('2d');
              
              if (window.myGenderChart) { window.myGenderChart.destroy(); }

              window.myGenderChart = new Chart(ctx, {
                  type: 'doughnut', 
                  data: {
                      labels: Object.keys(data.genderData || {'Male':0, 'Female':0}),
                      datasets: [{
                          data: Object.values(data.genderData || {'Male':0, 'Female':0}),
                          backgroundColor: ['#3498db', '#e91e63', '#95a5a6'],
                          borderWidth: 1
                      }]
                  },
                  options: {
                      responsive: true,
                      maintainAspectRatio: false,
                      plugins: {
                          legend: { position: 'bottom' }
                      }
                  }
              });
          }
      })
      .catch(err => {
          console.error("Dashboard Error:", err);
          if(document.getElementById('totalIndigencyApp')) document.getElementById('totalIndigencyApp').innerText = "Err";
      });
});
</script>