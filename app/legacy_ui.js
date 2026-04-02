(function () {
  var storageKey = "bugcatcher-theme";

  function getRootTheme() {
    var root = document.documentElement;
    var theme = root.getAttribute("data-theme");
    return theme === "dark" ? "dark" : "light";
  }

  function setTheme(nextTheme) {
    var theme = nextTheme === "dark" ? "dark" : "light";
    var root = document.documentElement;

    root.setAttribute("data-theme", theme);
    root.style.colorScheme = theme;

    try {
      window.localStorage.setItem(storageKey, theme);
    } catch (error) {
      // Ignore storage failures and keep the in-memory theme.
    }

    document.querySelectorAll("[data-theme-toggle]").forEach(function (toggle) {
      var label = toggle.querySelector("[data-theme-toggle-label]");
      var nextLabel = theme === "dark" ? "Dark" : "Light";
      var switchLabel = theme === "dark" ? "light" : "dark";

      toggle.classList.toggle("is-dark", theme === "dark");
      toggle.setAttribute("aria-pressed", theme === "dark" ? "true" : "false");
      toggle.setAttribute("aria-label", "Switch to " + switchLabel + " mode");
      toggle.setAttribute("title", "Switch to " + switchLabel + " mode");

      if (label) {
        label.textContent = nextLabel;
      }
    });
  }

  function closeDropdown(dropdown) {
    if (!dropdown) {
      return;
    }

    dropdown.classList.remove("is-open");
    dropdown.classList.remove("open");

    var trigger = dropdown.querySelector("[data-dropdown-trigger], .bc-dropdown__trigger, .gh-dd-btn");
    if (trigger) {
      trigger.setAttribute("aria-expanded", "false");
    }
  }

  function openDropdown(dropdown) {
    if (!dropdown) {
      return;
    }

    document.querySelectorAll(".bc-dropdown, .gh-dd").forEach(function (item) {
      if (item !== dropdown) {
        closeDropdown(item);
      }
    });

    dropdown.classList.add("is-open");
    dropdown.classList.add("open");

    var trigger = dropdown.querySelector("[data-dropdown-trigger], .bc-dropdown__trigger, .gh-dd-btn");
    if (trigger) {
      trigger.setAttribute("aria-expanded", "true");
    }

    var searchInput = dropdown.querySelector("[data-dropdown-search], .bc-dropdown__search input, .gh-dd-search input");
    if (searchInput) {
      window.setTimeout(function () {
        searchInput.focus();
      }, 0);
    }
  }

  function toggleDropdown(dropdown) {
    if (!dropdown) {
      return;
    }

    var isOpen = dropdown.classList.contains("is-open") || dropdown.classList.contains("open");
    if (isOpen) {
      closeDropdown(dropdown);
      return;
    }

    openDropdown(dropdown);
  }

  function setupDropdownSearch(input) {
    if (!input || input.dataset.dropdownBound === "true") {
      return;
    }

    input.dataset.dropdownBound = "true";
    input.addEventListener("input", function () {
      var dropdown = input.closest(".bc-dropdown, .gh-dd");
      if (!dropdown) {
        return;
      }

      var listName = input.getAttribute("data-search");
      var list = listName
        ? dropdown.querySelector('[data-list="' + listName + '"]')
        : dropdown.querySelector("[data-dropdown-list], .bc-dropdown__list, .gh-dd-list");

      if (!list) {
        return;
      }

      var query = input.value.trim().toLowerCase();
      list.querySelectorAll("[data-dropdown-item], .bc-dropdown__item, .gh-dd-item").forEach(function (item) {
        var rawText = item.getAttribute("data-text") || item.innerText || "";
        var haystack = rawText.toLowerCase();
        var keepVisible = item.getAttribute("data-static-option") === "true";
        item.style.display = keepVisible || query === "" || haystack.indexOf(query) !== -1 ? "" : "none";
      });
    });
  }

  document.addEventListener("click", function (event) {
    var trigger = event.target.closest("[data-dropdown-trigger], .bc-dropdown__trigger, .gh-dd-btn");
    if (trigger) {
      event.preventDefault();
      event.stopPropagation();
      toggleDropdown(trigger.closest(".bc-dropdown, .gh-dd"));
      return;
    }

    document.querySelectorAll(".bc-dropdown.is-open, .bc-dropdown.open, .gh-dd.is-open, .gh-dd.open").forEach(closeDropdown);
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      document.querySelectorAll(".bc-dropdown.is-open, .bc-dropdown.open, .gh-dd.is-open, .gh-dd.open").forEach(closeDropdown);
    }
  });

  document.addEventListener("click", function (event) {
    var toggle = event.target.closest("[data-theme-toggle]");
    if (!toggle) {
      return;
    }

    event.preventDefault();
    setTheme(getRootTheme() === "dark" ? "light" : "dark");
  });

  document.addEventListener("DOMContentLoaded", function () {
    setTheme(getRootTheme());
    document
      .querySelectorAll("[data-dropdown-search], .bc-dropdown__search input, .gh-dd-search input")
      .forEach(setupDropdownSearch);
  });
})();
