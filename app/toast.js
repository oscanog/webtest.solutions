(function () {
  "use strict";

  var root = null;
  var nextToastId = 0;

  function ensureRoot() {
    if (root) {
      return root;
    }

    root = document.createElement("div");
    root.className = "bc-toast-root";
    root.setAttribute("aria-live", "polite");
    root.setAttribute("aria-atomic", "false");
    document.body.appendChild(root);
    return root;
  }

  function removeToast(node) {
    if (!node || !node.parentNode) {
      return;
    }

    node.classList.add("is-leaving");
    window.setTimeout(function () {
      if (node.parentNode) {
        node.parentNode.removeChild(node);
      }
    }, 180);
  }

  function showToast(kind, message, options) {
    if (!message) {
      return;
    }

    var config = options || {};
    var duration =
      typeof config.duration === "number" && config.duration > 0
        ? config.duration
        : 3200;
    var container = ensureRoot();
    var toast = document.createElement("div");
    var toastId = "bc-toast-" + nextToastId++;
    var title =
      typeof config.title === "string" && config.title.trim() !== ""
        ? config.title.trim()
        : kind === "success"
          ? "Success"
          : kind === "error"
            ? "Action failed"
            : "Update";

    toast.className = "bc-toast bc-toast-" + kind;
    toast.id = toastId;
    toast.setAttribute("role", kind === "error" ? "alert" : "status");
    toast.innerHTML =
      '<div class="bc-toast-copy">' +
      '<strong class="bc-toast-title"></strong>' +
      '<div class="bc-toast-message"></div>' +
      "</div>" +
      '<button type="button" class="bc-toast-close" aria-label="Dismiss notification">&times;</button>';
    toast.querySelector(".bc-toast-title").textContent = title;
    toast.querySelector(".bc-toast-message").textContent = String(message);
    toast.querySelector(".bc-toast-close").addEventListener("click", function () {
      removeToast(toast);
    });

    container.appendChild(toast);
    window.requestAnimationFrame(function () {
      toast.classList.add("is-visible");
    });

    window.setTimeout(function () {
      removeToast(toast);
    }, duration);
  }

  window.bcToast = {
    success: function (message, options) {
      showToast("success", message, options);
    },
    error: function (message, options) {
      showToast("error", message, options);
    },
    info: function (message, options) {
      showToast("info", message, options);
    },
  };
})();
