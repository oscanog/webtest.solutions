(function () {
  "use strict";

  var root = document.querySelector("[data-profile-root]");
  if (!root) {
    return;
  }

  var profileForm = document.getElementById("profileSettingsForm");
  var passwordForm = document.getElementById("passwordSettingsForm");
  var profileMessage = document.getElementById("profileFormMessage");
  var passwordMessage = document.getElementById("passwordFormMessage");
  var usernameInput = document.querySelector("[data-profile-username-input]");
  var profileEndpoint = root.getAttribute("data-profile-endpoint") || "";
  var passwordEndpoint = root.getAttribute("data-password-endpoint") || "";

  function initialsFromUsername(username) {
    var normalized = (username || "").trim();
    if (!normalized) {
      return "U";
    }

    var parts = normalized.split(/[\s._-]+/).filter(Boolean);
    var initials = parts
      .slice(0, 2)
      .map(function (part) {
        return part.charAt(0).toUpperCase();
      })
      .join("");

    return initials || normalized.slice(0, 2).toUpperCase();
  }

  function readErrorMessage(payload, fallbackMessage) {
    if (payload && payload.error && payload.error.message) {
      return payload.error.message;
    }
    if (payload && payload.message) {
      return payload.message;
    }
    return fallbackMessage;
  }

  function renderMessage(target, tone, message) {
    if (!target) {
      return;
    }

    if (!message) {
      target.innerHTML = "";
      return;
    }

    target.innerHTML =
      '<div class="bc-alert ' +
      tone +
      '">' +
      String(message)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;") +
      "</div>";
  }

  function setButtonPending(button, pending) {
    if (!button) {
      return;
    }

    var idleLabel = button.getAttribute("data-idle-label") || button.textContent || "";
    var pendingLabel = button.getAttribute("data-pending-label") || idleLabel;
    button.disabled = pending;
    button.textContent = pending ? pendingLabel : idleLabel;
  }

  function updateUsernameEverywhere(username) {
    document.querySelectorAll("[data-session-username], [data-session-sidebar-username], [data-profile-username]").forEach(function (node) {
      node.textContent = username;
    });

    document.querySelectorAll("[data-session-avatar], [data-profile-avatar]").forEach(function (node) {
      node.textContent = initialsFromUsername(username);
    });
  }

  async function submitJson(endpoint, method, payload, fallbackMessage) {
    var response = await fetch(endpoint, {
      method: method,
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify(payload),
    });

    var result;
    try {
      result = await response.json();
    } catch (error) {
      throw new Error(fallbackMessage);
    }

    if (!response.ok || !result || result.ok === false) {
      throw new Error(readErrorMessage(result, fallbackMessage));
    }

    return result;
  }

  if (profileForm && usernameInput && profileEndpoint) {
    profileForm.addEventListener("submit", async function (event) {
      event.preventDefault();
      var nextUsername = usernameInput.value.trim();
      if (!nextUsername) {
        renderMessage(profileMessage, "error", "Username is required.");
        return;
      }

      var submitButton = profileForm.querySelector('button[type="submit"]');
      renderMessage(profileMessage, "", "");
      setButtonPending(submitButton, true);

      try {
        var result = await submitJson(
          profileEndpoint,
          "PATCH",
          { username: nextUsername },
          "Unable to update profile."
        );
        updateUsernameEverywhere(result.user && result.user.username ? result.user.username : nextUsername);
        usernameInput.value = result.user && result.user.username ? result.user.username : nextUsername;
        renderMessage(profileMessage, "success", result.message || "Profile updated successfully.");
      } catch (error) {
        renderMessage(profileMessage, "error", error instanceof Error ? error.message : "Unable to update profile.");
      } finally {
        setButtonPending(submitButton, false);
      }
    });
  }

  if (passwordForm && passwordEndpoint) {
    passwordForm.addEventListener("submit", async function (event) {
      event.preventDefault();
      var formData = new FormData(passwordForm);
      var currentPassword = String(formData.get("current_password") || "");
      var password = String(formData.get("password") || "");
      var confirmPassword = String(formData.get("confirm_password") || "");

      if (password !== confirmPassword) {
        renderMessage(passwordMessage, "error", "Password does not match.");
        return;
      }

      var submitButton = passwordForm.querySelector('button[type="submit"]');
      renderMessage(passwordMessage, "", "");
      setButtonPending(submitButton, true);

      try {
        var result = await submitJson(
          passwordEndpoint,
          "POST",
          {
            current_password: currentPassword,
            password: password,
            confirm_password: confirmPassword,
          },
          "Unable to change password."
        );
        passwordForm.reset();
        renderMessage(passwordMessage, "success", result.message || "Password updated successfully.");
      } catch (error) {
        renderMessage(passwordMessage, "error", error instanceof Error ? error.message : "Unable to change password.");
      } finally {
        setButtonPending(submitButton, false);
      }
    });
  }
})();
