(function () {
  var base = document.querySelector("script[src*='cms.js']");
  var root = base ? base.getAttribute("src").replace(/js\/cms\.js.*$/, "") : "";
  fetch(root + "api/cms.php?action=content", { credentials: "same-origin" })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var texts = data.texts || {};
      var images = data.images || {};
      window.IX_CMS_TEXTS = texts;
      function apply(root) {
        root.querySelectorAll("[data-cms]").forEach(function (el) {
          var key = el.getAttribute("data-cms");
          if (el.tagName === "IMG") {
            if (images[key]) el.src = images[key];
          } else if (typeof texts[key] === "string") {
            el.textContent = texts[key];
          }
        });
      }
      apply(document);
      document.querySelectorAll("template").forEach(function (tpl) { apply(tpl.content); });
      if (window.IX_APPLY_MEDIA) window.IX_APPLY_MEDIA(images);
    })
    .catch(function () {});
})();
