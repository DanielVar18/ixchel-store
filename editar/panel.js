(function () {
  var root = new URL("../", window.location.href);
  var csrf = "";
  var defaults = {};
  var savedTexts = {};
  var savedImages = {};
  var login = document.getElementById("login");
  var app = document.getElementById("app");

  function api(action, payload) {
    return fetch(new URL("api/cms.php", root), {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF": csrf },
      body: JSON.stringify(Object.assign({ action: action }, payload || {}))
    }).then(readJson);
  }

  function readJson(r) {
    return r.json().catch(function () {
      throw new Error("El editor todavía no responde. Vuelve a desplegar el sitio en Hostinger.");
    }).then(function (data) {
      if (!r.ok || data.ok === false) throw new Error(data.error || "No se pudo completar.");
      return data;
    });
  }

  function showError(id, message) {
    var el = document.getElementById(id);
    el.hidden = !message;
    el.textContent = message || "";
  }

  document.getElementById("loginForm").addEventListener("submit", function (e) {
    e.preventDefault();
    showError("loginErr", "");
    var fd = new FormData(e.target);
    api("login", { usuario: String(fd.get("usuario") || "").trim(), password: String(fd.get("password") || "") })
      .then(function (data) {
        csrf = data.csrf;
        openApp(data.editor);
      })
      .catch(function (err) { showError("loginErr", err.message); });
  });

  document.getElementById("outBtn").addEventListener("click", function () {
    api("logout").catch(function () {}).then(function () { location.reload(); });
  });

  document.getElementById("passBtn").addEventListener("click", function () {
    var form = document.getElementById("passForm");
    form.hidden = !form.hidden;
  });

  document.getElementById("passForm").addEventListener("submit", function (e) {
    e.preventDefault();
    showError("passErr", "");
    var fd = new FormData(e.target);
    api("password", { actual: String(fd.get("actual") || ""), nueva: String(fd.get("nueva") || "") })
      .then(function () {
        e.target.reset();
        e.target.hidden = true;
        document.getElementById("saveMsg").textContent = "Contraseña actualizada.";
      })
      .catch(function (err) { showError("passErr", err.message); });
  });

  document.getElementById("saveBtn").addEventListener("click", saveTexts);

  function openApp(editor) {
    login.hidden = true;
    app.hidden = false;
    document.getElementById("who").textContent = "· " + editor.nombre;
    Promise.all([
      fetch(new URL("index.html", root)).then(function (r) { return r.text(); }),
      fetch(new URL("api/cms.php?action=content", root), { credentials: "same-origin" }).then(function (r) { return r.json(); })
    ]).then(function (parts) {
      var doc = new DOMParser().parseFromString(parts[0], "text/html");
      savedTexts = parts[1].texts || {};
      savedImages = parts[1].images || {};
      var groups = {};
      var seen = {};
      var nodes = [];
      doc.querySelectorAll("[data-cms]").forEach(function (el) { nodes.push(el); });
      doc.querySelectorAll("template").forEach(function (tpl) {
        tpl.content.querySelectorAll("[data-cms]").forEach(function (el) { nodes.push(el); });
      });
      nodes.forEach(function (el) {
        var key = el.getAttribute("data-cms");
        if (!key || seen[key]) return;
        seen[key] = true;
        var group = el.getAttribute("data-cms-group") || "Página";
        var label = el.getAttribute("data-cms-label") || key;
        if (!groups[group]) groups[group] = [];
        if (el.tagName === "IMG") {
          defaults[key] = el.getAttribute("src");
          groups[group].push({ key: key, label: label, type: "image" });
        } else {
          defaults[key] = el.textContent;
          groups[group].push({ key: key, label: label, type: "text" });
        }
      });
      var box = document.getElementById("fields");
      box.innerHTML = "";
      Object.keys(groups).forEach(function (name) {
        var section = document.createElement("section");
        section.className = "group";
        section.innerHTML = "<h2>" + name + "</h2>";
        groups[name].forEach(function (field) { section.appendChild(renderField(field)); });
        box.appendChild(section);
      });
    }).catch(function () {
      document.getElementById("fields").textContent = "No se pudo leer la página.";
    });
  }

  function currentImage(key) {
    return savedImages[key] || defaults[key] || "";
  }

  function renderField(field) {
    var wrap = document.createElement("div");
    wrap.className = "field";
    wrap.dataset.key = field.key;
    if (field.type === "text") {
      var value = Object.prototype.hasOwnProperty.call(savedTexts, field.key) ? savedTexts[field.key] : defaults[field.key];
      wrap.innerHTML = '<div class="top"><label>' + field.label + '</label><button type="button" class="ghost">Restaurar</button></div>';
      var area = document.createElement("textarea");
      area.value = value || "";
      area.dataset.original = defaults[field.key] || "";
      wrap.appendChild(area);
      wrap.querySelector("button").addEventListener("click", function () {
        area.value = area.dataset.original;
        area.dataset.reset = "1";
      });
    } else {
      wrap.innerHTML = '<div class="top"><label>' + field.label + '</label><button type="button" class="ghost">Restaurar</button></div><div class="preview"><img alt=""><input type="file" accept="image/jpeg,image/png,image/webp,image/gif"></div><p class="err" hidden></p>';
      wrap.querySelector("button").addEventListener("click", function () {
        api("save", { texts: {}, reset: [field.key] }).then(function (data) {
          savedImages = data.images || {};
          wrap.querySelector("img").src = new URL(defaults[field.key] || "", root).href + "?t=" + Date.now();
          document.getElementById("saveMsg").textContent = "Imagen restaurada.";
        }).catch(function (e) {
          var err = wrap.querySelector(".err");
          err.hidden = false;
          err.textContent = e.message;
        });
      });
      wrap.querySelector("img").src = new URL(currentImage(field.key), root).href;
      wrap.querySelector("input").addEventListener("change", function () {
        uploadImage(field.key, this.files[0], wrap);
      });
    }
    return wrap;
  }

  function uploadImage(key, file, wrap) {
    var err = wrap.querySelector(".err");
    err.hidden = true;
    if (!file) return;
    var body = new FormData();
    body.append("action", "upload");
    body.append("key", key);
    body.append("file", file);
    fetch(new URL("api/cms.php", root), { method: "POST", credentials: "same-origin", headers: { "X-CSRF": csrf }, body: body })
      .then(readJson)
      .then(function (data) {
        savedImages[key] = data.url;
        wrap.querySelector("img").src = new URL(data.url, root).href + "?t=" + Date.now();
        document.getElementById("saveMsg").textContent = "Imagen actualizada.";
      })
      .catch(function (e) { err.hidden = false; err.textContent = e.message; });
  }

  function saveTexts() {
    var texts = {};
    var reset = [];
    document.querySelectorAll(".field textarea").forEach(function (area) {
      var key = area.closest(".field").dataset.key;
      if (area.dataset.reset === "1" && area.value === area.dataset.original) {
        reset.push(key);
        return;
      }
      texts[key] = area.value;
    });
    document.getElementById("saveMsg").textContent = "Guardando…";
    api("save", { texts: texts, reset: reset }).then(function (data) {
      savedTexts = data.texts || {};
      document.querySelectorAll(".field textarea").forEach(function (area) { delete area.dataset.reset; });
      document.getElementById("saveMsg").textContent = "Textos guardados. Recarga la página pública para verlos.";
    }).catch(function (err) {
      document.getElementById("saveMsg").textContent = err.message;
    });
  }

  api("me").then(function (data) {
    csrf = data.csrf;
    openApp(data.editor);
  }).catch(function () {});
})();
