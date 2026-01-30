$(document).ready(function () {
  // --------------------------
  // Case search input (with debounce)
  // --------------------------
  let debounceTimer;
  $("#searchInput").on("input", function () {
    clearTimeout(debounceTimer);
    const searchTerm = $(this).val();
    debounceTimer = setTimeout(() => {
      loadCases(searchTerm, 1);
    }, 500);
  });

  $("#searchInput").on("keypress", function (e) {
    if (e.which == 13) {
      loadCases($(this).val(), 1);
    }
  });

  // Initial load
  loadCases();

  // --------------------------
  // Load cases via AJAX
  // --------------------------
  function loadCases(search = "", page = 1) {
    $.ajax({
      url: "Search/case_search.php",
      method: "GET",
      data: { search: search, pagenum: page },
      success: function (response) {
        let res = typeof response === "string" ? JSON.parse(response) : response;
        let tableBody = $("#residentTableBody");
        tableBody.empty();

        if (!res.cases || res.cases.length === 0) {
          tableBody.append(
            '<tr><td colspan="10" class="text-center">No cases found.</td></tr>'
          );
        } else {
          res.cases.forEach((row) => {
            // 1. View Button
            const viewBtn = `
              <button class="btn btn-sm btn-outline-primary view-details-btn" 
                data-bs-toggle="modal" 
                data-bs-target="#viewCaseModal"
                data-case='${JSON.stringify(row)}'
                title="View">
                <i class="bi bi-eye"></i>
              </button>`;

            // 2. Status Update Button
            const statusBtn = `
              <button class="btn btn-sm btn-outline-success update-status-btn" 
                data-bs-toggle="modal" 
                data-bs-target="#statusModal"
                data-case-number="${row.case_number}" 
                data-current-action="${row.action_taken}"
                title="Update Status">
                <i class="bi bi-pencil-square"></i>
              </button>`;

            // 3. Appearance Update Button (Individualized)
            const appearanceBtn = `
              <button class="btn btn-sm btn-outline-warning update-appearance-btn" 
                data-bs-toggle="modal" 
                data-bs-target="#appearanceModal"
                data-case-number="${row.case_number}" 
                title="Update Individual Attendance">
                <i class="bi bi-journal-check"></i>
              </button>`;

            let actionColumn = "";
            if ((userRole || "").toLowerCase() === "punong barangay") {
              actionColumn = `<div class="d-flex gap-1">${viewBtn}</div>`;
            } else {
              actionColumn = `
                <div class="d-flex gap-1">
                  ${viewBtn}
                  ${statusBtn}
                  ${appearanceBtn}
                </div>`;
            }

            // --- BADGE LOGIC ---
            let attBadge = '<span class="badge bg-secondary">Pending</span>';
            if (row.attendance_status === 'Appearance') {
                attBadge = '<span class="badge bg-success">Appeared</span>';
            } else if (row.attendance_status === 'Non-Appearance') {
                attBadge = '<span class="badge bg-danger">Absent</span>';
            }

            const compDisplay = row.complainant_list || "N/A";
            const respDisplay = row.respondent_list || "N/A";

            tableBody.append(`
              <tr>
                <td style="vertical-align: middle;">${row.case_number}</td>
                <td style="vertical-align: middle;">${compDisplay}</td>
                <td style="vertical-align: middle;">${respDisplay}</td>
                <td style="vertical-align: middle;">${row.nature_offense}</td>
                <td style="vertical-align: middle;">${row.date_filed}</td>
                <td style="vertical-align: middle;">${row.time_filed}</td>
                <td style="vertical-align: middle;">${row.date_hearing}</td>
                <td style="vertical-align: middle;">${attBadge}</td>
                <td style="vertical-align: middle;"><span>${row.action_taken || "No status"}</span></td>
                <td style="vertical-align: middle;">${actionColumn}</td>
              </tr>
            `);
          });
        }
        renderPagination(res.total_pages, res.current_page, search);
      },
    });
  }

  // --------------------------
  // Enhanced Pagination rendering
  // --------------------------
  function renderPagination(totalPages, currentPage, search) {
    let html = "";
    html += `<li class="page-item ${currentPage === 1 ? "disabled" : ""}">
        <button class="page-link" data-page="1" data-search="${encodeURIComponent(search)}"><i class="fa fa-angle-double-left"></i></button>
      </li>`;
    html += `<li class="page-item ${currentPage === 1 ? "disabled" : ""}">
        <button class="page-link" data-page="${currentPage - 1}" data-search="${encodeURIComponent(search)}"><i class="fa fa-angle-left"></i></button>
      </li>`;

    const windowSize = 5;
    let start = Math.max(1, currentPage - Math.floor(windowSize / 2));
    let end = Math.min(totalPages, start + windowSize - 1);
    if (end - start + 1 < windowSize) start = Math.max(1, end - windowSize + 1);

    if (start > 1) html += `<li class="page-item disabled"><span class="page-link">…</span></li>`;

    for (let i = start; i <= end; i++) {
      html += `<li class="page-item ${i === currentPage ? "active" : ""}">
          <button class="page-link" data-page="${i}" data-search="${encodeURIComponent(search)}">${i}</button>
        </li>`;
    }

    if (end < totalPages) html += `<li class="page-item disabled"><span class="page-link">…</span></li>`;

    html += `<li class="page-item ${currentPage === totalPages ? "disabled" : ""}">
        <button class="page-link" data-page="${currentPage + 1}" data-search="${encodeURIComponent(search)}"><i class="fa fa-angle-right"></i></button>
      </li>`;
    html += `<li class="page-item ${currentPage === totalPages ? "disabled" : ""}">
        <button class="page-link" data-page="${totalPages}" data-search="${encodeURIComponent(search)}"><i class="fa fa-angle-double-right"></i></button>
      </li>`;

    $(".pagination").html(html);

    $(".pagination .page-link").click(function () {
      const p = $(this).data("page");
      if (p && !$(this).parent().hasClass("disabled")) {
        loadCases(decodeURIComponent($(this).data("search")), p);
      }
    });
  }

  // --------------------------
  // Individual Participant Loader
  // --------------------------
  function loadParticipants(caseNumber) {
    const container = $("#participant_list_container");
    container.html('<tr><td colspan="4" class="text-center"><div class="spinner-border spinner-border-sm text-warning"></div> Loading...</td></tr>');

    // Fetch from the specific lupon subfolder
    fetch(`Modules/lupon_modules/get_participants.php?case_number=${encodeURIComponent(caseNumber)}`)
      .then(response => response.json())
      .then(data => {
        container.empty();
        if (!data || data.length === 0) {
          container.append('<tr><td colspan="4" class="text-center">No participants found.</td></tr>');
          return;
        }

        data.forEach(p => {
          container.append(`
            <tr>
              <td><strong>${p.first_name} ${p.last_name}</strong></td>
              <td><span class="badge bg-secondary">${p.role}</span></td>
              <td>
                <select name="attendance[${p.participant_id}][status]" class="form-select form-select-sm">
                  <option value="Appearance" ${p.action_taken === 'Appearance' ? 'selected' : ''}>Present</option>
                  <option value="Non-Appearance" ${p.action_taken === 'Non-Appearance' ? 'selected' : ''}>Absent</option>
                </select>
              </td>
              <td>
                <input type="text" name="attendance[${p.participant_id}][remarks]" 
                       class="form-control form-control-sm" 
                       value="${p.remarks || ''}" placeholder="Optional notes">
              </td>
            </tr>`);
        });
      })
      .catch(err => {
        console.error("Fetch error:", err);
        container.html('<tr><td colspan="4" class="text-danger text-center">Error loading data.</td></tr>');
      });
  }

  // --------------------------
  // Modal Trigger Handlers
  // --------------------------

  // View Details
  $(document).on("click", ".view-details-btn", function () {
    const row = $(this).data("case");
    $("#modal_complainant_list").html(row.complainant_list || "<span class='text-muted'>No Data</span>");
    $("#modal_respondent_list").html(row.respondent_list || "<span class='text-muted'>No Data</span>");
    $("#modal_case_number").val(row.case_number);
    $("#Comp_First_Name").val(row.Comp_First_Name || "");
    $("#Comp_Middle_Name").val(row.Comp_Middle_Name || "");
    $("#Comp_Last_Name").val(row.Comp_Last_Name || "");
    $("#Comp_Suffix_Name").val(row.Comp_Suffix_Name || "");
    $("#Resp_First_Name").val(row.Resp_First_Name || "");
    $("#Resp_Middle_Name").val(row.Resp_Middle_Name || "");
    $("#Resp_Last_Name").val(row.Resp_Last_Name || "");
    $("#Resp_Suffix_Name").val(row.Resp_Suffix_Name || "");
    $("#modal_nature_offense").val(row.nature_offense);
    $("#modal_date_filed").val(row.date_filed);
    $("#modal_time_filed").val(row.time_filed);
    $("#modal_date_hearing").val(row.date_hearing);

    $.post("logs/logs_trig.php", { filename: 5, viewedID: row.case_number });
  });

  // Status Update
  $(document).on("click", ".update-status-btn", function () {
    $("#status_case_number").val($(this).data("case-number"));
    $("#status_action_taken").val($(this).data("current-action"));
  });

  // Appearance Update (Trigger Fetch)
  $(document).on("click", ".update-appearance-btn", function () {
    const caseNumber = $(this).data("case-number");
    $("#appearance_case_number").val(caseNumber);
    $("#appearanceModal form")[0].reset();
    $("#appearance_case_number").val(caseNumber); // Re-set after reset
    
    loadParticipants(caseNumber);
  });

  // Punong Barangay Restriction
  $("#viewCaseModal").on("show.bs.modal", function () {
    $("#saveChangesBtn").prop(
      "disabled",
      (userRole || "").toLowerCase() === "punong barangay"
    );
  });
});