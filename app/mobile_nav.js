(function () {
  "use strict";

  var toggles = Array.prototype.slice.call(
    document.querySelectorAll("[data-drawer-toggle]")
  );
  if (!toggles.length) {
    return;
  }

  function hasOpenDrawer() {
    return !!document.querySelector("[data-drawer].is-open");
  }

  toggles.forEach(function (toggle) {
    var targetId = toggle.getAttribute("data-drawer-target");
    if (!targetId) {
      return;
    }

    var drawer = document.getElementById(targetId);
    var backdrop = document.querySelector("[data-drawer-backdrop]");
    if (!drawer || !backdrop) {
      return;
    }

    var breakpoint = parseInt(
      drawer.getAttribute("data-drawer-breakpoint") || "960",
      10
    );
    if (Number.isNaN(breakpoint)) {
      breakpoint = 960;
    }

    var mediaQuery = window.matchMedia("(max-width: " + breakpoint + "px)");
    var isOpen = false;

    function setExpanded(value) {
      toggle.setAttribute("aria-expanded", value ? "true" : "false");
      if (value) {
        drawer.setAttribute("aria-hidden", "false");
      } else if (mediaQuery.matches) {
        drawer.setAttribute("aria-hidden", "true");
      } else {
        drawer.removeAttribute("aria-hidden");
      }
    }

    function openDrawer() {
      if (!mediaQuery.matches) {
        return;
      }

      isOpen = true;
      drawer.classList.add("is-open");
      backdrop.hidden = false;
      backdrop.classList.add("is-visible");
      document.body.classList.add("drawer-open");
      setExpanded(true);
    }

    function closeDrawer() {
      isOpen = false;
      drawer.classList.remove("is-open");
      backdrop.classList.remove("is-visible");
      backdrop.hidden = true;
      if (!hasOpenDrawer()) {
        document.body.classList.remove("drawer-open");
      }
      setExpanded(false);
    }

    function syncForViewport() {
      if (!mediaQuery.matches) {
        closeDrawer();
        drawer.removeAttribute("aria-hidden");
      } else if (!isOpen) {
        drawer.setAttribute("aria-hidden", "true");
      }
    }

    toggle.addEventListener("click", function () {
      if (isOpen) {
        closeDrawer();
      } else {
        openDrawer();
      }
    });

    backdrop.addEventListener("click", closeDrawer);

    drawer.querySelectorAll("a[href]").forEach(function (link) {
      link.addEventListener("click", function () {
        if (mediaQuery.matches) {
          closeDrawer();
        }
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && isOpen) {
        closeDrawer();
      }
    });

    window.addEventListener("resize", syncForViewport);
    syncForViewport();
  });
})();
