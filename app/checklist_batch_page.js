(function () {
  "use strict";

  function normalize(value) {
    return String(value || "").toLowerCase();
  }

  function showToast(kind, message, options) {
    if (
      window.bcToast &&
      typeof window.bcToast[kind] === "function"
    ) {
      window.bcToast[kind](message, options || {});
    }
  }

  function initChecklistTable(section) {
    var rows = Array.prototype.slice.call(
      section.querySelectorAll("[data-checklist-row]")
    );
    var filterMode =
      section.getAttribute("data-checklist-filter-mode") || "client";
    var orgId = parseInt(section.getAttribute("data-checklist-org-id") || "0", 10) || 0;
    var searchInput = section.querySelector("[data-checklist-filter-search]");
    var statusSelect = section.querySelector("[data-checklist-filter-status]");
    var assignmentSelect = section.querySelector(
      "[data-checklist-filter-assignment]"
    );
    var emptyState = section.querySelector("[data-checklist-empty]");
    var feedback = section.querySelector("[data-checklist-feedback]");

    function setFeedback(kind, message) {
      if (!feedback) {
        return;
      }

      feedback.hidden = !message;
      feedback.textContent = message || "";
      feedback.className = "bc-alert bc-inline-alert";

      if (!message) {
        return;
      }

      if (kind === "error") {
        feedback.classList.add("error");
        return;
      }

      if (kind === "success") {
        feedback.classList.add("success");
        return;
      }

      feedback.classList.add("info");
    }

    function getSummaryNode(name) {
      return section.querySelector('[data-checklist-count="' + name + '"]');
    }

    function getSummaryValue(name) {
      var node = getSummaryNode(name);
      return node ? parseInt(node.textContent || "0", 10) || 0 : 0;
    }

    function setSummaryValue(name, value) {
      var node = getSummaryNode(name);
      if (node) {
        node.textContent = String(Math.max(0, value));
      }
    }

    function adjustSummaryValue(name, delta) {
      if (!getSummaryNode(name)) {
        return;
      }
      setSummaryValue(name, getSummaryValue(name) + delta);
    }

    function updateEmptyState() {
      if (!emptyState) {
        return;
      }

      var visibleRows = rows.filter(function (row) {
        return !row.hidden;
      }).length;
      emptyState.hidden = visibleRows > 0;
    }

    function updateSummary() {
      var total = rows.length;
      var visible = 0;
      var assigned = 0;
      var unassigned = 0;
      var open = 0;

      rows.forEach(function (row) {
        if (row.hidden) {
          return;
        }

        visible += 1;
        if (row.getAttribute("data-assignment") === "assigned") {
          assigned += 1;
        } else {
          unassigned += 1;
        }

        if (row.getAttribute("data-status") === "open") {
          open += 1;
        }
      });

      setSummaryValue("total", total);
      setSummaryValue("visible", visible);
      setSummaryValue("assigned", assigned);
      setSummaryValue("unassigned", unassigned);
      setSummaryValue("open", open);
    }

    function applyFilters() {
      if (filterMode !== "client") {
        updateEmptyState();
        return;
      }

      var searchNeedle = normalize(searchInput ? searchInput.value : "");
      var statusNeedle = statusSelect ? statusSelect.value : "";
      var assignmentNeedle = assignmentSelect ? assignmentSelect.value : "";
      var matches = 0;

      rows.forEach(function (row) {
        var searchable =
          normalize(row.getAttribute("data-search-base")) +
          " " +
          normalize(row.getAttribute("data-search-assignee"));
        var isMatch = true;

        if (searchNeedle && searchable.indexOf(searchNeedle) === -1) {
          isMatch = false;
        }
        if (statusNeedle && row.getAttribute("data-status") !== statusNeedle) {
          isMatch = false;
        }
        if (
          assignmentNeedle &&
          row.getAttribute("data-assignment") !== assignmentNeedle
        ) {
          isMatch = false;
        }

        row.hidden = !isMatch;
        if (isMatch) {
          matches += 1;
        }
      });

      if (emptyState) {
        emptyState.hidden = rows.length === 0 || matches > 0;
      }

      updateSummary();
    }

    function bindFilters() {
      if (filterMode !== "client") {
        return;
      }

      [searchInput, statusSelect, assignmentSelect].forEach(function (control) {
        if (!control) {
          return;
        }

        control.addEventListener("input", applyFilters);
        control.addEventListener("change", applyFilters);
      });
    }

    function setBusy(container, isBusy) {
      container.setAttribute("data-busy", isBusy ? "1" : "0");
      Array.prototype.forEach.call(
        container.querySelectorAll("button, select"),
        function (control) {
          control.disabled = isBusy;
        }
      );
    }

    function updateAssigneeCell(row, item) {
      var badge = row.querySelector("[data-checklist-assignee-label]");
      var meta = row.querySelector("[data-checklist-assignee-meta]");
      var updatedCell = row.querySelector("[data-checklist-updated-cell]");
      var assigneeName = String(item.assigned_to_name || "").trim();
      var isAssigned = assigneeName !== "";

      row.setAttribute("data-assignment", isAssigned ? "assigned" : "unassigned");
      row.setAttribute("data-search-assignee", normalize(assigneeName));

      if (badge) {
        badge.textContent = isAssigned ? assigneeName : "Unassigned";
        badge.classList.toggle("bc-badge-tester", isAssigned);
        badge.classList.toggle("bc-badge-muted", !isAssigned);
      }

      if (meta) {
        meta.textContent = isAssigned
          ? "QA Tester"
          : "Needs QA Tester assignment";
      }

      if (updatedCell) {
        updatedCell.textContent = "Just now";
      }
    }

    function syncServerCounts(row, previousAssignment, nextAssignment) {
      if (filterMode === "client") {
        return;
      }

      var assignmentFilter =
        section.getAttribute("data-checklist-assignment-filter") || "";
      var rowShouldHide =
        (assignmentFilter === "assigned" && nextAssignment !== "assigned") ||
        (assignmentFilter === "unassigned" && nextAssignment !== "unassigned");

      if (assignmentFilter === "") {
        if (previousAssignment !== nextAssignment) {
          adjustSummaryValue(
            previousAssignment === "assigned" ? "assigned" : "unassigned",
            -1
          );
          adjustSummaryValue(
            nextAssignment === "assigned" ? "assigned" : "unassigned",
            1
          );
        }
      } else if (rowShouldHide && previousAssignment !== nextAssignment) {
        adjustSummaryValue("visible", -1);
        adjustSummaryValue(
          assignmentFilter === "assigned" ? "assigned" : "unassigned",
          -1
        );
        row.hidden = true;
      }

      updateEmptyState();
    }

    function buildRequestBody(requestedValue) {
      var payload = {
        assigned_to_user_id: parseInt(requestedValue, 10) || 0,
      };

      if (orgId > 0) {
        payload.org_id = orgId;
      }

      return JSON.stringify(payload);
    }

    function bindAssignments() {
      Array.prototype.forEach.call(
        section.querySelectorAll("[data-checklist-assignment-form]"),
        function (container) {
          var select = container.querySelector('select[name="assigned_to_user_id"]');
          var applyButton = container.querySelector("[data-checklist-apply]");
          var clearButton = container.querySelector("[data-checklist-clear]");
          var status = container.querySelector("[data-checklist-form-status]");
          var endpoint = container.getAttribute("data-endpoint");
          var row = container.closest("[data-checklist-row]");

          if (!select || !applyButton || !endpoint || !row) {
            return;
          }

          var lastCommittedValue = String(select.value || "0");

          function runAssignment(forceClear) {
            var previousValue = lastCommittedValue;
            var previousAssignment =
              row.getAttribute("data-assignment") || "unassigned";
            var requestedValue = forceClear ? "0" : String(select.value || "0");

            if (!forceClear && requestedValue === previousValue) {
              showToast("info", "QA Tester is already up to date.", {
                title: "No changes",
              });
              return Promise.resolve();
            }

            if (forceClear) {
              select.value = "0";
            }

            setBusy(container, true);
            if (status) {
              status.textContent = forceClear ? "Clearing..." : "Saving...";
            }
            setFeedback("", "");

            return fetch(endpoint, {
              method: "PATCH",
              credentials: "same-origin",
              headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
              },
              body: buildRequestBody(requestedValue),
            })
              .then(function (response) {
                return response
                  .json()
                  .catch(function () {
                    return null;
                  })
                  .then(function (body) {
                    return {
                      ok: response.ok,
                      body: body,
                    };
                  });
              })
              .then(function (result) {
                if (
                  !result.ok ||
                  !result.body ||
                  result.body.ok !== true ||
                  !result.body.data ||
                  !result.body.data.item
                ) {
                  var errorMessage =
                    result.body &&
                    result.body.error &&
                    result.body.error.message
                      ? result.body.error.message
                      : "Failed to update the QA Tester.";
                  throw new Error(errorMessage);
                }

                var item = result.body.data.item;
                var nextAssignment =
                  parseInt(item.assigned_to_user_id, 10) > 0
                    ? "assigned"
                    : "unassigned";

                select.value = item.assigned_to_user_id
                  ? String(item.assigned_to_user_id)
                  : "0";
                lastCommittedValue = select.value;
                updateAssigneeCell(row, item);
                syncServerCounts(row, previousAssignment, nextAssignment);
                applyFilters();

                if (status) {
                  status.textContent = item.assigned_to_user_id
                    ? "Saved"
                    : "Cleared";
                }

                var successMessage = item.assigned_to_user_id
                  ? "QA Tester assignment updated."
                  : "QA Tester assignment cleared.";
                setFeedback("success", successMessage);
                showToast("success", successMessage, {
                  title: item.assigned_to_user_id ? "Tester assigned" : "Assignment cleared",
                });
              })
              .catch(function (error) {
                select.value = previousValue;
                if (status) {
                  status.textContent = "Try again";
                }
                var errorMessage =
                  error && error.message
                    ? error.message
                    : "Failed to update the QA Tester.";
                setFeedback("error", errorMessage);
                showToast("error", errorMessage);
              })
              .finally(function () {
                setBusy(container, false);
              });
          }

          container.addEventListener("submit", function (event) {
            event.preventDefault();
            runAssignment(false);
          });

          applyButton.addEventListener("click", function (event) {
            event.preventDefault();
            runAssignment(false);
          });

          select.addEventListener("change", function () {
            runAssignment(false);
          });

          if (clearButton) {
            clearButton.addEventListener("click", function (event) {
              event.preventDefault();
              runAssignment(true);
            });
          }
        }
      );
    }

    bindFilters();
    bindAssignments();
    applyFilters();
  }

  Array.prototype.forEach.call(
    document.querySelectorAll("[data-checklist-table]"),
    function (section) {
      initChecklistTable(section);
    }
  );
})();
