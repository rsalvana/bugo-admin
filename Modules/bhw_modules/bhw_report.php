<?php 
// Modules/bhw_modules/bhw_report.php
// Note: connection is already open in index_bhw.php, but require_once is safe to keep.
require_once __DIR__ . '/../../include/connection.php'; 
?>

<style>
/* Scoped styles for the report card only */
.report-card {
    border-radius: 1rem;
    padding: 2rem;
    background-color: #ffffff;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}
.report-table { 
    width: 100%; 
    margin-bottom: 1rem; 
    font-size: 0.875rem; 
    border-collapse: collapse; 
}
.report-table th { 
    background-color: #198754; 
    color: white; 
    font-weight: 600; 
    font-size: 0.75rem; 
    text-align: center; 
    padding: 0.6rem; 
    vertical-align: middle; 
}
.report-table td { 
    padding: 0.5rem; 
    text-align: center; 
    vertical-align: middle; 
    border: 1px solid #dee2e6; 
}
@media print {
    body * { visibility: hidden; }
    #reportResult, #reportResult * { visibility: visible; }
    #reportResult { position: absolute; left: 0; top: 0; width: 100%; }
    .no-print { display: none !important; }
}
</style>

<div class="container-fluid">
    <div class="report-card mb-4">
        <h2 class="mb-4 text-success"><i class="bi bi-prescription2"></i> BHW Reports</h2>

        <div class="mb-3">
            <label class="form-label fw-bold">Select Report Type:</label>
            <select id="reportType" class="form-select" onchange="toggleFilters()">
                <option value="">-- Select Report --</option>
                <option value="request_list">Request List</option>
                <option value="inventory">Medicine Inventory</option>
            </select>
        </div>

        <div id="requestFilters" class="mb-3" style="display: none;">
            <div class="row">
                <div class="col-md-6 mb-2">
                    <label class="form-label">Select Month:</label>
                    <select id="filterMonth" class="form-select">
                        <option value="">All Months</option>
                        <option value="01">January</option>
                        <option value="02">February</option>
                        <option value="03">March</option>
                        <option value="04">April</option>
                        <option value="05">May</option>
                        <option value="06">June</option>
                        <option value="07">July</option>
                        <option value="08">August</option>
                        <option value="09">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                    </select>
                </div>
                <div class="col-md-6 mb-2">
                    <label class="form-label">Select Year:</label>
                    <select id="filterYear" class="form-select">
                        <option value="">All Years</option>
                        <?php
                        $currentYear = date('Y');
                        for ($y = $currentYear; $y >= 2020; $y--) {
                            echo "<option value='$y'>$y</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mb-3 no-print">
            <button class="btn btn-success" onclick="loadReport()">
                <i class="bi bi-arrow-repeat"></i> Load Report
            </button>
            <button class="btn btn-dark" onclick="printReport()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>

        <div class="table-responsive" id="reportResult">
            </div>
    </div>
</div>

<script>
function toggleFilters() {
    const type = document.getElementById("reportType").value;
    const reqFilters = document.getElementById("requestFilters");
    
    document.getElementById("reportResult").innerHTML = "";

    if (type === "request_list") {
        reqFilters.style.display = "block";
    } else {
        reqFilters.style.display = "none";
        if (type === "inventory") {
            loadReport(); 
        }
    }
}

function loadReport() {
    const type = document.getElementById("reportType").value;
    let url = "";

    // IMPORTANT: The path must include 'Modules/bhw_modules/' because we are on index_bhw.php
    const basePath = "Modules/bhw_modules/load_report/";

    if (type === "request_list") {
        const month = document.getElementById("filterMonth").value;
        const year  = document.getElementById("filterYear").value;
        url = `${basePath}load_bhw_request_report.php?month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`;
    } else if (type === "inventory") {
        url = `${basePath}load_bhw_inventory_report.php`;
    }

    if (!url) return;

    document.getElementById("reportResult").innerHTML = "<div class='text-center p-3'><div class='spinner-border text-success'></div> Loading...</div>";

    fetch(url)
        .then(res => {
            if (!res.ok) throw new Error("HTTP " + res.status);
            return res.text();
        })
        .then(data => {
            document.getElementById("reportResult").innerHTML = data;
        })
        .catch(error => {
            console.error("Error:", error);
            document.getElementById("reportResult").innerHTML = "<div class='text-danger text-center'>Failed to load report. Check console for path errors.</div>";
        });
}

function printReport() {
    window.print();
}
</script>