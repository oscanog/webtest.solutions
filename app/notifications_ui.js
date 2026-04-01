(function () {
  "use strict";

  var menuRoots = Array.prototype.slice.call(
    document.querySelectorAll("[data-notifications-root]")
  );
  var pageRoot = document.querySelector("[data-notifications-page]");
  if (!menuRoots.length && !pageRoot) {
    return;
  }

  var NOTIFICATION_POLL_INTERVAL_MS = 5000;
  var NOTIFICATION_MAX_REALTIME_FAILURES = 3;

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function parseJsonAttribute(node, name) {
    if (!node) {
      return {};
    }

    var raw = node.getAttribute(name) || "{}";
    try {
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function normalizeNotification(record) {
    if (!record || typeof record !== "object") {
      return null;
    }

    var id = Number(record.id || 0);
    if (!Number.isFinite(id) || id <= 0) {
      return null;
    }

    return {
      id: id,
      title: String(record.title || "Notification"),
      body: String(record.body || ""),
      severity: String(record.severity || "default"),
      read_at: record.read_at || null,
      created_at: String(record.created_at || ""),
      link_path: String(record.link_path || "/app/notifications"),
      legacy_path: String(record.legacy_path || "/bugcatcher/app/notifications.php"),
      event_key: String(record.event_key || ""),
    };
  }

  function parseDateValue(value) {
    if (!value) {
      return 0;
    }

    var timestamp = Date.parse(String(value));
    return Number.isFinite(timestamp) ? timestamp : 0;
  }

  function sortNotifications(items) {
    return items.slice().sort(function (left, right) {
      var leftTime = parseDateValue(left.created_at);
      var rightTime = parseDateValue(right.created_at);
      if (leftTime === rightTime) {
        return right.id - left.id;
      }
      return rightTime - leftTime;
    });
  }

  function upsertNotificationRecord(current, notification) {
    var next = current.filter(function (item) {
      return item.id !== notification.id;
    });
    next.unshift(notification);
    return sortNotifications(next);
  }

  function mergeNotifications(items) {
    return sortNotifications(
      items
        .map(normalizeNotification)
        .filter(Boolean)
        .reduce(function (accumulator, item) {
          return upsertNotificationRecord(accumulator, item);
        }, [])
    );
  }

  function countUnread(items) {
    return items.reduce(function (total, item) {
      return total + (item.read_at ? 0 : 1);
    }, 0);
  }

  function priorityCount(items) {
    return items.reduce(function (total, item) {
      return total + (!item.read_at && item.severity === "alert" ? 1 : 0);
    }, 0);
  }

  function formatCount(count) {
    if (count > 99) {
      return "99+";
    }
    return String(Math.max(0, count));
  }

  function severityLabel(severity) {
    if (severity === "alert") {
      return "Priority";
    }
    if (severity === "success") {
      return "Update";
    }
    return "Info";
  }

  function severityClass(severity) {
    if (severity === "alert") {
      return "severity-alert";
    }
    if (severity === "success") {
      return "severity-success";
    }
    return "severity-default";
  }

  function relativeTime(value) {
    var timestamp = parseDateValue(value);
    if (!timestamp) {
      return "Just now";
    }

    var deltaSeconds = Math.max(0, Math.round((Date.now() - timestamp) / 1000));
    if (deltaSeconds < 60) {
      return "Just now";
    }

    var units = [
      { seconds: 86400, label: "day" },
      { seconds: 3600, label: "hour" },
      { seconds: 60, label: "minute" },
    ];

    for (var index = 0; index < units.length; index += 1) {
      if (deltaSeconds >= units[index].seconds) {
        var amount = Math.floor(deltaSeconds / units[index].seconds);
        return amount + " " + units[index].label + (amount === 1 ? "" : "s") + " ago";
      }
    }

    return "Just now";
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

  function fillTemplate(template, notificationId) {
    return String(template || "").replace("__ID__", String(notificationId));
  }

  function buildSocketUrl(path, socketToken) {
    var protocol = window.location.protocol === "https:" ? "wss:" : "ws:";
    var url = new URL(path || "/ws/notifications", protocol + "//" + window.location.host);
    url.searchParams.set("token", socketToken);
    return url.toString();
  }

  function parseRealtimeEvent(message) {
    try {
      var parsed = JSON.parse(message);
      if (
        parsed &&
        (parsed.type === "notification.created" ||
          parsed.type === "notification.read" ||
          parsed.type === "notification.read_all")
      ) {
        return parsed;
      }
    } catch (error) {
      return null;
    }

    return null;
  }

  var sources = menuRoots.slice();
  if (pageRoot) {
    sources.push(pageRoot);
  }

  var initialPayloads = sources.map(function (node) {
    return parseJsonAttribute(node, "data-notifications-initial");
  });

  var configSource = sources[0];
  var state = {
    items: mergeNotifications(
      initialPayloads.reduce(function (accumulator, payload) {
        return accumulator.concat(Array.isArray(payload.items) ? payload.items : []);
      }, [])
    ),
    unreadCount: 0,
    totalCount: 0,
    connectionState: "idle",
    connectionHint: "",
    error: "",
    fetchLimit: pageRoot ? 50 : 10,
    pagePath: (configSource && configSource.getAttribute("data-notifications-page-url")) || "/bugcatcher/app/notifications.php",
    notificationsEndpoint:
      (configSource && configSource.getAttribute("data-notifications-endpoint")) || "/bugcatcher/api/v1/notifications",
    readEndpointTemplate:
      (configSource && configSource.getAttribute("data-notification-read-template")) ||
      "/bugcatcher/api/v1/notifications/__ID__/read",
    readAllEndpoint:
      (configSource && configSource.getAttribute("data-notification-read-all-endpoint")) ||
      "/bugcatcher/api/v1/notifications/read-all",
    socketEndpoint:
      (configSource && configSource.getAttribute("data-notification-socket-endpoint")) ||
      "/bugcatcher/api/v1/realtime/socket-token",
  };

  initialPayloads.forEach(function (payload) {
    state.unreadCount = Math.max(state.unreadCount, Number(payload.unread_count || 0));
    state.totalCount = Math.max(state.totalCount, Number(payload.total_count || 0));
    state.fetchLimit = Math.max(state.fetchLimit, Number(payload.limit || 0));
  });

  if (!state.totalCount) {
    state.totalCount = state.items.length;
  }
  if (!state.unreadCount) {
    state.unreadCount = countUnread(state.items);
  }

  var pageMessage = pageRoot ? pageRoot.querySelector("[data-notifications-page-message]") : null;
  var pageStats = pageRoot ? pageRoot.querySelector("[data-notifications-page-stats]") : null;
  var pageList = pageRoot ? pageRoot.querySelector("[data-notifications-page-list]") : null;
  var pageMarkAll = pageRoot ? pageRoot.querySelector("[data-notifications-mark-all-page]") : null;

  function dropdownItems() {
    return state.items.slice(0, 10);
  }

  function renderDropdownList(items) {
    if (!items.length) {
      return (
        '<div class="bc-notification-empty" data-notification-empty>' +
        "No notifications yet. New project, issue, and checklist updates will appear here." +
        "</div>"
      );
    }

    return items
      .map(function (item) {
        var unreadClass = item.read_at ? "is-read" : "is-unread";
        return (
          '<a href="' +
          escapeHtml(item.legacy_path || state.pagePath) +
          '" class="bc-notification-item ' +
          unreadClass +
          '" data-notification-item data-notification-id="' +
          item.id +
          '" data-notification-destination="' +
          escapeHtml(item.legacy_path || state.pagePath) +
          '">' +
          '<div class="bc-notification-copy">' +
          '<strong class="bc-notification-title">' +
          escapeHtml(item.title) +
          "</strong>" +
          (item.body
            ? '<p class="bc-notification-body">' + escapeHtml(item.body) + "</p>"
            : "") +
          "</div>" +
          '<div class="bc-notification-meta">' +
          '<span class="bc-notification-tag ' +
          severityClass(item.severity) +
          '">' +
          escapeHtml(severityLabel(item.severity)) +
          "</span>" +
          '<span class="bc-notification-time" data-notification-created-at="' +
          escapeHtml(item.created_at) +
          '">' +
          escapeHtml(relativeTime(item.created_at)) +
          "</span>" +
          "</div>" +
          "</a>"
        );
      })
      .join("");
  }

  function renderPageList(items) {
    if (!items.length) {
      return (
        '<div class="bc-card bc-notifications-empty-state">' +
        "<strong>Inbox is clear.</strong>" +
        "<p>There are no notifications to review right now.</p>" +
        "</div>"
      );
    }

    return items
      .map(function (item) {
        var unreadClass = item.read_at ? "is-read" : "is-unread";
        return (
          '<a href="' +
          escapeHtml(item.legacy_path || state.pagePath) +
          '" class="bc-notification-row ' +
          unreadClass +
          '" data-notification-item data-notification-id="' +
          item.id +
          '" data-notification-destination="' +
          escapeHtml(item.legacy_path || state.pagePath) +
          '">' +
          '<div class="bc-notification-row__main">' +
          '<div class="bc-notification-row__topline">' +
          '<span class="bc-notification-tag ' +
          severityClass(item.severity) +
          '">' +
          escapeHtml(severityLabel(item.severity)) +
          "</span>" +
          '<span class="bc-notification-state ' +
          (item.read_at ? "is-read" : "is-unread") +
          '">' +
          (item.read_at ? "Read" : "Unread") +
          "</span>" +
          '<span class="bc-notification-time" data-notification-created-at="' +
          escapeHtml(item.created_at) +
          '">' +
          escapeHtml(relativeTime(item.created_at)) +
          "</span>" +
          "</div>" +
          '<strong class="bc-notification-row__title">' +
          escapeHtml(item.title) +
          "</strong>" +
          (item.body
            ? '<p class="bc-notification-row__body">' + escapeHtml(item.body) + "</p>"
            : "") +
          "</div>" +
          '<span class="bc-notification-row__cta">Open</span>' +
          "</a>"
        );
      })
      .join("");
  }

  function renderPageStatsCards(items) {
    var unread = state.unreadCount;
    var read = Math.max(0, items.length - unread);
    var priority = priorityCount(items);

    return (
      '<div class="bc-stat bc-stat--alert"><span>Unread</span><strong>' +
      unread +
      "</strong><small>new</small></div>" +
      '<div class="bc-stat bc-stat--success"><span>Read</span><strong>' +
      read +
      "</strong><small>seen</small></div>" +
      '<div class="bc-stat bc-stat--steel"><span>Priority</span><strong>' +
      priority +
      "</strong><small>need action</small></div>"
    );
  }

  function renderPageMessage() {
    if (!pageMessage) {
      return;
    }

    if (state.error) {
      pageMessage.innerHTML = '<div class="bc-alert error">' + escapeHtml(state.error) + "</div>";
      return;
    }

    if (state.connectionHint) {
      var tone = state.connectionState === "connected" ? "success" : "info";
      pageMessage.innerHTML = '<div class="bc-alert ' + tone + '">' + escapeHtml(state.connectionHint) + "</div>";
      return;
    }

    pageMessage.innerHTML = "";
  }

  function renderAll() {
    menuRoots.forEach(function (root) {
      var badge = root.querySelector("[data-notification-count]");
      var list = root.querySelector("[data-notification-list]");
      var markAll = root.querySelector("[data-notification-mark-all]");

      if (badge) {
        badge.textContent = formatCount(state.unreadCount);
        badge.classList.toggle("is-empty", state.unreadCount <= 0);
      }

      if (list) {
        list.innerHTML = renderDropdownList(dropdownItems());
      }

      if (markAll) {
        markAll.disabled = state.unreadCount <= 0;
      }
    });

    if (pageRoot) {
      if (pageStats) {
        pageStats.innerHTML = renderPageStatsCards(state.items);
      }
      if (pageList) {
        pageList.innerHTML = renderPageList(state.items);
      }
      if (pageMarkAll) {
        pageMarkAll.disabled = state.unreadCount <= 0;
      }
      renderPageMessage();
    }
  }

  async function requestJson(url, options, fallbackMessage) {
    var response = await fetch(url, Object.assign(
      {
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
        },
      },
      options || {}
    ));

    var payload;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error(fallbackMessage);
    }

    if (!response.ok || !payload || payload.ok === false) {
      throw new Error(readErrorMessage(payload, fallbackMessage));
    }

    return payload.data;
  }

  async function hydrate(limit) {
    try {
      var data = await requestJson(
        state.notificationsEndpoint + "?state=all&limit=" + encodeURIComponent(String(limit || state.fetchLimit)),
        { method: "GET" },
        "Unable to load notifications."
      );
      state.items = mergeNotifications(Array.isArray(data.items) ? data.items : []);
      state.unreadCount = Number(data.unread_count || 0);
      state.totalCount = Number(data.total_count || state.items.length);
      state.error = "";
      renderAll();
    } catch (error) {
      state.error = error instanceof Error ? error.message : "Unable to load notifications.";
      renderAll();
    }
  }

  async function markNotificationRead(notificationId) {
    var result = await requestJson(
      fillTemplate(state.readEndpointTemplate, notificationId),
      { method: "POST" },
      "Unable to mark notification as read."
    );

    var updated = normalizeNotification(result.notification);
    if (updated) {
      state.items = upsertNotificationRecord(state.items, updated);
      state.unreadCount = countUnread(state.items);
      state.totalCount = Math.max(state.totalCount, state.items.length);
      state.error = "";
      renderAll();
    }

    return updated;
  }

  async function markAllRead() {
    await requestJson(
      state.readAllEndpoint,
      { method: "POST" },
      "Unable to mark all notifications as read."
    );

    var now = new Date().toISOString();
    state.items = state.items.map(function (item) {
      if (item.read_at) {
        return item;
      }

      return Object.assign({}, item, { read_at: now });
    });
    state.unreadCount = 0;
    state.error = "";
    renderAll();
  }

  async function openNotification(notificationId, destination) {
    var notification = state.items.find(function (item) {
      return item.id === notificationId;
    });
    var nextPath =
      (notification && notification.legacy_path) || destination || state.pagePath;

    if (!notification || notification.read_at) {
      window.location.href = nextPath;
      return;
    }

    try {
      var updated = await markNotificationRead(notificationId);
      nextPath = (updated && updated.legacy_path) || nextPath;
    } catch (error) {
      state.error = error instanceof Error ? error.message : "Unable to open notification.";
      renderAll();
      return;
    }

    window.location.href = nextPath;
  }

  function applyRealtimeEvent(message) {
    var payload = parseRealtimeEvent(message);
    if (!payload) {
      return;
    }

    if (payload.type === "notification.created" && payload.notification) {
      var created = normalizeNotification(payload.notification);
      if (created) {
        state.items = upsertNotificationRecord(state.items, created);
      }
    }

    if (payload.type === "notification.read" && payload.notification) {
      var updated = normalizeNotification(payload.notification);
      if (updated) {
        state.items = upsertNotificationRecord(state.items, updated);
      }
    }

    if (payload.type === "notification.read_all") {
      var readAt = new Date().toISOString();
      state.items = state.items.map(function (item) {
        return item.read_at ? item : Object.assign({}, item, { read_at: readAt });
      });
    }

    if (typeof payload.unread_count === "number") {
      state.unreadCount = payload.unread_count;
    } else {
      state.unreadCount = countUnread(state.items);
    }

    if (typeof payload.total_count === "number") {
      state.totalCount = payload.total_count;
    } else {
      state.totalCount = Math.max(state.totalCount, state.items.length);
    }

    state.error = "";
    renderAll();
  }

  function initDropdownMenus() {
    function closeMenu(root) {
      var trigger = root.querySelector("[data-notification-trigger]");
      var panel = root.querySelector("[data-notification-panel]");
      root.classList.remove("is-open");
      if (trigger) {
        trigger.setAttribute("aria-expanded", "false");
      }
      if (panel) {
        panel.hidden = true;
      }
    }

    function openMenu(root) {
      menuRoots.forEach(function (candidate) {
        if (candidate !== root) {
          closeMenu(candidate);
        }
      });

      var trigger = root.querySelector("[data-notification-trigger]");
      var panel = root.querySelector("[data-notification-panel]");
      root.classList.add("is-open");
      if (trigger) {
        trigger.setAttribute("aria-expanded", "true");
      }
      if (panel) {
        panel.hidden = false;
      }
    }

    menuRoots.forEach(function (root) {
      var trigger = root.querySelector("[data-notification-trigger]");
      var panel = root.querySelector("[data-notification-panel]");
      if (!trigger || !panel) {
        return;
      }

      trigger.addEventListener("click", function (event) {
        event.stopPropagation();
        if (root.classList.contains("is-open")) {
          closeMenu(root);
        } else {
          openMenu(root);
        }
      });

      panel.addEventListener("click", function (event) {
        event.stopPropagation();
      });
    });

    document.addEventListener("click", function (event) {
      menuRoots.forEach(function (root) {
        if (!root.contains(event.target)) {
          closeMenu(root);
        }
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") {
        return;
      }

      menuRoots.forEach(closeMenu);
    });
  }

  function initInteractions() {
    document.addEventListener("click", function (event) {
      var markAllTrigger = event.target.closest("[data-notification-mark-all], [data-notifications-mark-all-page]");
      if (markAllTrigger) {
        event.preventDefault();
        if (markAllTrigger.disabled) {
          return;
        }

        markAllRead().catch(function (error) {
          state.error = error instanceof Error ? error.message : "Unable to mark all notifications as read.";
          renderAll();
        });
        return;
      }

      var item = event.target.closest("[data-notification-item]");
      if (item) {
        event.preventDefault();
        var notificationId = Number(item.getAttribute("data-notification-id") || "0");
        var destination = item.getAttribute("data-notification-destination") || state.pagePath;
        if (notificationId > 0) {
          void openNotification(notificationId, destination);
        } else {
          window.location.href = destination;
        }
      }
    }, true);
  }

  function initRealtime() {
    var reconnectTimer = null;
    var pollingTimer = null;
    var realtimeFailures = 0;
    var socket = null;

    function stopPolling() {
      if (pollingTimer !== null) {
        window.clearInterval(pollingTimer);
        pollingTimer = null;
      }
    }

    function startPolling(showFallbackHint) {
      if (pollingTimer !== null) {
        return;
      }

      if (showFallbackHint) {
        state.connectionState = "polling";
        state.connectionHint = "Realtime reconnecting. Background refresh is active.";
        renderAll();
      }

      void hydrate(state.fetchLimit);
      pollingTimer = window.setInterval(function () {
        void hydrate(state.fetchLimit);
      }, NOTIFICATION_POLL_INTERVAL_MS);
    }

    function stopRealtimeReconnects() {
      if (reconnectTimer !== null) {
        window.clearTimeout(reconnectTimer);
        reconnectTimer = null;
      }
      startPolling(true);
      state.connectionState = "polling";
      state.connectionHint = "Live notifications unavailable. Background refresh is active.";
      renderAll();
    }

    function scheduleReconnect() {
      startPolling(true);
      realtimeFailures += 1;
      if (realtimeFailures >= NOTIFICATION_MAX_REALTIME_FAILURES) {
        stopRealtimeReconnects();
        return;
      }

      if (reconnectTimer !== null) {
        window.clearTimeout(reconnectTimer);
      }

      reconnectTimer = window.setTimeout(function () {
        void connectRealtime();
      }, 1500 * realtimeFailures);
    }

    async function connectRealtime() {
      startPolling(false);
      state.connectionState = "connecting";
      state.connectionHint = "Connecting live notifications...";
      renderAll();

      try {
        var result = await requestJson(
          state.socketEndpoint,
          { method: "POST" },
          "Unable to connect live notifications."
        );
        if (!result || !result.connection || !result.connection.socket_token) {
          throw new Error("Realtime connection details were missing.");
        }

        socket = new WebSocket(
          buildSocketUrl(result.connection.path, result.connection.socket_token)
        );

        socket.onopen = function () {
          realtimeFailures = 0;
          stopPolling();
          state.connectionState = "connected";
          state.connectionHint = "Live notifications connected.";
          renderAll();
        };

        socket.onmessage = function (messageEvent) {
          applyRealtimeEvent(String(messageEvent.data || ""));
        };

        socket.onerror = function () {
          if (socket) {
            socket.close();
          }
        };

        socket.onclose = function () {
          scheduleReconnect();
        };
      } catch (error) {
        scheduleReconnect();
      }
    }

    window.addEventListener("beforeunload", function () {
      if (socket) {
        socket.close();
        socket = null;
      }
      stopPolling();
      if (reconnectTimer !== null) {
        window.clearTimeout(reconnectTimer);
      }
    });

    startPolling(false);
    void connectRealtime();
  }

  renderAll();
  initDropdownMenus();
  initInteractions();
  initRealtime();
})();
