(function () {
  "use strict";

  function initDrawerToggles() {
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
  }

  function initUserMenu() {
    var menus = Array.prototype.slice.call(document.querySelectorAll("[data-user-menu]"));
    if (!menus.length) {
      return;
    }

    function closeMenu(menu) {
      var trigger = menu.querySelector("[data-user-menu-trigger]");
      var panel = menu.querySelector("[data-user-menu-panel]");
      menu.classList.remove("is-open");
      if (trigger) {
        trigger.setAttribute("aria-expanded", "false");
      }
      if (panel) {
        panel.hidden = true;
      }
    }

    function openMenu(menu) {
      var trigger = menu.querySelector("[data-user-menu-trigger]");
      var panel = menu.querySelector("[data-user-menu-panel]");
      menus.forEach(function (candidate) {
        if (candidate !== menu) {
          closeMenu(candidate);
        }
      });
      menu.classList.add("is-open");
      if (trigger) {
        trigger.setAttribute("aria-expanded", "true");
      }
      if (panel) {
        panel.hidden = false;
      }
    }

    menus.forEach(function (menu) {
      var trigger = menu.querySelector("[data-user-menu-trigger]");
      var panel = menu.querySelector("[data-user-menu-panel]");
      if (!trigger || !panel) {
        return;
      }

      trigger.addEventListener("click", function (event) {
        event.stopPropagation();
        if (menu.classList.contains("is-open")) {
          closeMenu(menu);
        } else {
          openMenu(menu);
        }
      });

      panel.addEventListener("click", function (event) {
        event.stopPropagation();
      });
    });

    document.addEventListener("click", function (event) {
      menus.forEach(function (menu) {
        if (!menu.contains(event.target)) {
          closeMenu(menu);
        }
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") {
        return;
      }
      menus.forEach(closeMenu);
    });
  }

  initDrawerToggles();
  initUserMenu();
})();
