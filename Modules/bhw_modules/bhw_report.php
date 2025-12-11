<?php 
// Modules/bhw_modules/bhw_report.php
require_once __DIR__ . '/../../include/connection.php'; 
?>

<style>
    /* --- SCREEN STYLES --- */
    body {
        overflow-x: hidden; /* Prevents horizontal scrollbar */
    }

    .report-card {
        border-radius: 1rem;
        padding: 2rem;
        background-color: #ffffff;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    /* --- PRINT STYLES (The Fixes) --- */
    @media print {
        @page {
            /* Top/Bottom: 10mm, Left/Right: 20mm (Centers content nicely) */
            margin: 10mm 20mm; 
            size: auto;
        }

        /* 1. Hide everything except the report */
        body * {
            visibility: hidden;
            height: 0;
        }
        #reportResult, #reportResult * {
            visibility: visible;
            height: auto;
        }

        /* 2. Position the report container */
        #reportResult {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            margin: 0;
            padding: 0;
        }

        /* 3. FIX: Stop content from being cut off on the left */
        .row {
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
        .col-md-12, .col-12, .container-fluid {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        /* 4. Hide Buttons */
        .no-print {
            display: none !important;
        }
    }

    /* --- REPORT TABLE STYLES (Matches Reference) --- */
    /* Wrapper Border */
    .report-box {
        border: 1px solid #dee2e6;
        padding: 5px;
        border-radius: 4px;
        margin-top: 15px;
        margin-bottom: 20px;
    }

    /* Blue Header */
    .table-custom thead th {
        background-color: #0d6efd !important; /* Blue */
        color: white !important;
        font-weight: 600;
        font-size: 13px;
        text-align: left;
        padding: 8px 12px;
        vertical-align: middle;
        border: 1px solid #9ec5fe; /* Lighter blue border */
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Table Body */
    .table-custom tbody td {
        padding: 8px 12px;
        vertical-align: middle;
        font-size: 13px;
        border: 1px solid #dee2e6;
        color: #333;
    }

    /* Zebra Striping */
    .table-custom tbody tr:nth-child(even) {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
    }
</style>

<div class="container-fluid p-0">
    <div class="report-card mb-4">
        <h2 class="mb-4 text-primary"><i class="bi bi-prescription2"></i> BHW Reports</h2>

        <div class="mb-3 no-print">
            <label class="form-label fw-bold">Select Report Type:</label>
            <select id="reportType" class="form-select" onchange="toggleFilters()">
                <option value="">-- Select Report --</option>
                <option value="request_list">Request List</option>
                <option value="inventory">Medicine Inventory</option>
            </select>
        </div>

        <div id="requestFilters" class="mb-3 no-print" style="display: none;">
            <div class="row g-2">
                <div class="col-md-6">
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
                <div class="col-md-6">
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

        <div id="reportResult"></div>
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
        if (type === "inventory") loadReport();
    }
}

function loadReport() {
    const type = document.getElementById("reportType").value;
    let url = "";
    const basePath = "Modules/bhw_modules/load_report/";

    if (type === "request_list") {
        const month = document.getElementById("filterMonth").value;
        const year  = document.getElementById("filterYear").value;
        url = `${basePath}load_bhw_request_report.php?month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`;
    } else if (type === "inventory") {
        url = `${basePath}load_bhw_inventory_report.php`;
    }

    if (!url) return;

    document.getElementById("reportResult").innerHTML = "<div class='text-center p-3'><div class='spinner-border text-primary'></div> Loading...</div>";

    fetch(url)
        .then(res => res.text())
        .then(data => {
            document.getElementById("reportResult").innerHTML = data;
        })
        .catch(error => {
            document.getElementById("reportResult").innerHTML = "<div class='text-danger text-center'>Failed to load report. Check file paths.</div>";
        });
}

function printReport() {
    window.print();
}
</script>