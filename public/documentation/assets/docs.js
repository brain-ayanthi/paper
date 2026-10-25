/* ==========================================================================
   POS Documentation — runtime shell
   Renders sidebar, breadcrumbs, "on this page" TOC, search (Cmd/Ctrl-K),
   theme toggle, mobile menu, reading progress and screenshot placeholders
   from a single per-page skeleton + the nav model in nav.js.
   ========================================================================== */
(function () {
  "use strict";

  /* ---- Icon set (Lucide-style, 24x24 stroke) --------------------------- */
  var P = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9v11h14V9"/>',
    rocket: '<path d="M5 16c-2 1-2 4-2 4s3 0 4-2"/><path d="M9 14c5-9 10-9 11-9s0 6-9 11"/><path d="M9 14l-3-1m5 5 1-3"/>',
    dashboard: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
    compass: '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z"/>',
    pos: '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M3 9h18M7 21h10"/>',
    tag: '<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-7 7z"/><circle cx="8" cy="8" r="1.4"/>',
    card: '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 9.5h19"/>',
    scale: '<path d="M12 3v18M7 21h10M5 7h14"/><path d="M5 7 3 13h4zM19 7l-2 6h4z"/>',
    refund: '<path d="M3 8a9 9 0 1 1-1.5 5"/><path d="M3 4v4h4"/>',
    offline: '<path d="M5 12.5a9 9 0 0 1 14 0M8.5 16a4.5 4.5 0 0 1 7 0"/><path d="M12 19.5h.01M3 3l18 18"/>',
    receipt: '<path d="M5 3v18l2-1 2 1 2-1 2 1 2-1 2 1V3l-2 1-2-1-2 1-2-1-2 1z"/><path d="M9 8h6M9 12h6"/>',
    customers: '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16 6.5a3 3 0 0 1 0 5.8M17.5 19a5 5 0 0 0-3-4.6"/>',
    cash: '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 9.5v5M18 9.5v5"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
    box: '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
    tree: '<rect x="4" y="3" width="6" height="5" rx="1.5"/><rect x="14" y="16" width="6" height="5" rx="1.5"/><rect x="4" y="16" width="6" height="5" rx="1.5"/><path d="M7 8v4h10v4M7 12v4"/>',
    grip: '<circle cx="7" cy="7" r="1.4"/><circle cx="12" cy="7" r="1.4"/><circle cx="17" cy="7" r="1.4"/><circle cx="7" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="17" cy="12" r="1.4"/><circle cx="7" cy="17" r="1.4"/><circle cx="12" cy="17" r="1.4"/><circle cx="17" cy="17" r="1.4"/>',
    barcode: '<path d="M4 6v12M7 6v12M10 6v12M13 6v12M16 6v12M20 6v12"/>',
    archive: '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11h14V8M10 12h4"/>',
    calendar: '<rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    edit: '<path d="M4 20h4l10-10-4-4L4 16z"/><path d="m14 6 4 4"/>',
    checkall: '<path d="m2 12 5 5 5-5M11 12l5 5L22 9"/>',
    transfer: '<path d="M4 8h13l-3-3M20 16H7l3 3"/>',
    truck: '<rect x="2.5" y="6" width="12" height="9" rx="1.5"/><path d="M14.5 9h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/>',
    cart: '<circle cx="9" cy="20" r="1.6"/><circle cx="18" cy="20" r="1.6"/><path d="M2.5 3h2l2.2 12.4a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L21 7H6"/>',
    pie: '<path d="M12 3a9 9 0 1 0 9 9h-9z"/><path d="M12 3v9h9A9 9 0 0 0 12 3z" opacity=".4"/>',
    percent: '<path d="M19 5 5 19"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
    note: '<path d="M5 3h10l4 4v14H5z"/><path d="M14 3v5h5M8 13h8M8 17h5"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
    shield: '<path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6z"/><path d="m9 12 2 2 4-4"/>',
    store: '<path d="M4 9.5 5 4h14l1 5.5M4 9.5V20h16V9.5M4 9.5h16"/><path d="M9 20v-5h6v5"/>',
    printer: '<path d="M7 9V3h10v6"/><rect x="4" y="9" width="16" height="8" rx="1.5"/><path d="M7 14h10v6H7z"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 2.5 15 0 18M12 3c-2.5 2.5-2.5 15 0 18"/>',
    database: '<ellipse cx="12" cy="5.5" rx="8" ry="3"/><path d="M4 5.5v13c0 1.7 3.6 3 8 3s8-1.3 8-3v-13M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4M21 4v4h-4"/>',
    help: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.5 1.5c0 1.7-2.5 2-2.5 3.5M12 17h.01"/>',
    wrench: '<path d="M14.5 6a4 4 0 0 0-5 5L4 16.5 7.5 20l5.5-5.5a4 4 0 0 0 5-5l-2.5 2.5-2.5-.5-.5-2.5z"/>',
    book: '<path d="M5 4h11a2 2 0 0 1 2 2v14H7a2 2 0 0 0-2 2z"/><path d="M5 4v16M18 18H7"/>',
    star: '<path d="m12 3.5 2.6 5.4 5.9.8-4.3 4.1 1.1 5.9-5.3-2.9-5.3 2.9 1.1-5.9-4.3-4.1 5.9-.8z"/>',
    /* ui */
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5 4 4M20 20l-1-1M5 19l-1 1M20 4l-1 1"/>',
    moon: '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
    chevR: '<path d="m9 6 6 6-6 6"/>',
    chevD: '<path d="m6 9 6 6 6-6"/>',
    arrowL: '<path d="M19 12H5m6-6-6 6 6 6"/>',
    arrowR: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
    link: '<path d="M9 15l6-6M10 6l1-1a4 4 0 0 1 6 6l-1 1M14 18l-1 1a4 4 0 0 1-6-6l1-1"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="m4 18 5-5 4 4 3-3 4 4"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    check: '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
    warn: '<path d="M12 3 2.5 20h19z"/><path d="M12 10v4M12 17h.01"/>',
    danger: '<path d="M8 2h8l6 6v8l-6 6H8l-6-6V8z"/><path d="M12 8v5M12 16h.01"/>',
    bulb: '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-4 10.5c.7.7 1 1.2 1 2.5h6c0-1.3.3-1.8 1-2.5A6 6 0 0 0 12 3z"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
  };
  function ico(name, cls) {
    // width/height default to 1em so any icon without an explicit CSS size
    // scales to its surrounding text instead of ballooning to fill its box.
    return '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round"' + (cls ? ' class="' + cls + '"' : '') + '>' +
      (P[name] || P.info) + '</svg>';
  }
  window.docIcon = ico;

  /* ---- Flatten nav ----------------------------------------------------- */
  var NAV = window.DOCS_NAV || [];
  var FLAT = [];
  NAV.forEach(function (sec) {
    sec.items.forEach(function (it) { FLAT.push(Object.assign({ section: sec.title }, it)); });
  });
  function findPage(id) { return FLAT.filter(function (p) { return p.id === id; })[0]; }

  var BODY = document.body;
  var CURRENT = BODY.getAttribute("data-nav") || "home";
  var page = findPage(CURRENT) || FLAT[0];

  /* ---- Theme ----------------------------------------------------------- */
  function applyTheme(t) {
    document.documentElement.setAttribute("data-theme", t);
    try { localStorage.setItem("docs-theme", t); } catch (e) {}
  }
  function initTheme() {
    var saved;
    try { saved = localStorage.getItem("docs-theme"); } catch (e) {}
    if (!saved) saved = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    document.documentElement.setAttribute("data-theme", saved);
  }
  initTheme();

  /* ---- Build sidebar --------------------------------------------------- */
  function buildSidebar() {
    var nav = document.getElementById("docs-sidebar-nav");
    if (!nav) return;
    var html = "";
    NAV.forEach(function (sec) {
      html += '<div class="doc-sidebar__group">';
      html += '<div class="doc-sidebar__title">' + esc(sec.title) + "</div>";
      sec.items.forEach(function (it) {
        html += '<a class="doc-navlink" data-navid="' + it.id + '" href="' + it.href + '">' +
          ico(it.icon) + "<span>" + esc(it.label) + "</span></a>";
      });
      html += "</div>";
    });
    nav.innerHTML = html;
    setActiveNav();
  }
  function setActiveNav() {
    document.querySelectorAll("#docs-sidebar-nav .doc-navlink").forEach(function (a) {
      a.classList.toggle("is-active", a.getAttribute("data-navid") === CURRENT);
    });
  }

  /* ---- Breadcrumbs ----------------------------------------------------- */
  function buildCrumbs() {
    var el = document.getElementById("docs-breadcrumbs");
    if (!el || !page) return;
    var sep = ico("chevR");
    var parts = [
      '<a href="/documentation/index.html">Documentation</a>', sep,
      '<span>' + esc(page.section) + "</span>", sep,
      '<span class="current">' + esc(page.label) + "</span>",
    ];
    el.innerHTML = parts.join(" ");
  }

  /* ---- On-this-page TOC + scroll spy ----------------------------------- */
  var spyTargets = [];
  function buildToc() {
    var toc = document.getElementById("docs-toc");
    var article = document.querySelector(".doc-article");
    if (!article) return;
    var heads = article.querySelectorAll("h2, h3");
    var links = [];
    heads.forEach(function (h) {
      if (!h.id) h.id = slug(h.textContent);
      // anchor affordance
      var a = document.createElement("a");
      a.className = "anchor"; a.href = "#" + h.id; a.setAttribute("aria-label", "Link to section");
      a.innerHTML = ico("link");
      h.appendChild(a);
      links.push({ id: h.id, text: h.firstChild ? h.textContent.replace("", "") : h.textContent, lvl: h.tagName === "H3" ? 3 : 2, el: h });
    });
    if (toc) {
      var aside = toc.closest(".doc-toc");
      if (aside) aside.style.display = links.length ? "" : "none";
      var html = '<div class="doc-toc__title">On this page</div>';
      links.forEach(function (l) {
        html += '<a href="#' + l.id + '" class="lvl-' + l.lvl + '" data-spy="' + l.id + '">' + esc(cleanHead(l.el)) + "</a>";
      });
      toc.innerHTML = html;
    }
    spyTargets = links;
  }
  function cleanHead(h) {
    var c = h.cloneNode(true);
    var a = c.querySelector(".anchor"); if (a) a.remove();
    return c.textContent.trim();
  }
  function spy() {
    if (!spyTargets.length) return;
    var pos = window.scrollY + 120, current = spyTargets[0].id;
    for (var i = 0; i < spyTargets.length; i++) {
      // Document-relative top — NOT offsetTop, which is measured from the
      // nearest positioned ancestor. Headings nested inside positioned cards
      // (e.g. the .feature-card grid on the home/installation pages) have a
      // tiny offsetTop that always tests true, which pinned the highlight to
      // the last one. getBoundingClientRect + scrollY is always page-absolute.
      var top = spyTargets[i].el.getBoundingClientRect().top + window.scrollY;
      if (top <= pos) current = spyTargets[i].id;
    }
    document.querySelectorAll("#docs-toc a").forEach(function (a) {
      a.classList.toggle("is-active", a.getAttribute("data-spy") === current);
    });
  }

  /* ---- Reading progress ------------------------------------------------ */
  function progress() {
    var bar = document.getElementById("docs-progress-bar");
    if (!bar) return;
    var h = document.documentElement;
    var max = h.scrollHeight - h.clientHeight;
    var pct = max > 0 ? (window.scrollY / max) * 100 : 0;
    bar.style.width = Math.min(100, Math.max(0, pct)) + "%";
  }

  /* ---- Prev / next pager ---------------------------------------------- */
  function buildPager() {
    var el = document.getElementById("docs-pager");
    if (!el) return;
    var idx = FLAT.indexOf(page);
    var prev = idx > 0 ? FLAT[idx - 1] : null;
    var next = idx < FLAT.length - 1 ? FLAT[idx + 1] : null;
    var html = "";
    html += prev
      ? '<a class="next-card" href="' + prev.href + '"><span class="dir">' + ico("arrowL") + " Previous</span><span class=\"ttl\">" + esc(prev.label) + "</span></a>"
      : "<span></span>";
    html += next
      ? '<a class="next-card is-next" href="' + next.href + '"><span class="dir">Next ' + ico("arrowR") + "</span><span class=\"ttl\">" + esc(next.label) + "</span></a>"
      : "<span></span>";
    el.innerHTML = html;
  }

  /* ---- Screenshot placeholders ---------------------------------------- */
  function wireScreens() {
    document.querySelectorAll("figure.shot").forEach(function (fig) {
      var img = fig.querySelector("img");
      if (!img) return;
      var src = img.getAttribute("src") || "";
      var show = function () {
        if (fig.querySelector(".shot__ph")) return;
        img.style.display = "none";
        var ph = document.createElement("div");
        ph.className = "shot__ph";
        ph.innerHTML = ico("image") +
          '<div>Screenshot placeholder</div>' +
          '<div class="path">' + esc(src) + "</div>";
        (fig.querySelector(".shot__frame") || fig).appendChild(ph);
      };
      if (img.complete && img.naturalWidth === 0) show();
      img.addEventListener("error", show);
      img.addEventListener("load", function () { if (img.naturalWidth === 0) show(); });
    });
  }

  /* ---- Mobile sidebar -------------------------------------------------- */
  function wireMobile() {
    var btn = document.getElementById("docs-menu-btn");
    var sb = document.querySelector(".doc-sidebar");
    var scrim = document.getElementById("docs-scrim");
    if (!btn || !sb) return;
    function close() { sb.classList.remove("is-open"); scrim && scrim.classList.remove("is-open"); }
    btn.addEventListener("click", function () {
      sb.classList.toggle("is-open");
      scrim && scrim.classList.toggle("is-open");
    });
    scrim && scrim.addEventListener("click", close);
    sb.addEventListener("click", function (e) { if (e.target.closest("a")) close(); });
  }

  /* ---- Theme button ---------------------------------------------------- */
  function wireTheme() {
    var btn = document.getElementById("docs-theme-btn");
    if (!btn) return;
    btn.addEventListener("click", function () {
      var cur = document.documentElement.getAttribute("data-theme");
      applyTheme(cur === "dark" ? "light" : "dark");
      applyLogo(); // swap to the dark/light logo variant if one exists
    });
  }

  /* ---- Search (Cmd/Ctrl-K) -------------------------------------------- */
  function wireSearch() {
    var overlay = document.getElementById("docs-search");
    var input = document.getElementById("docs-search-input");
    var results = document.getElementById("docs-search-results");
    var openers = document.querySelectorAll("[data-open-search]");
    if (!overlay || !input || !results) return;
    var sel = 0, items = [];

    function render(q) {
      q = (q || "").trim().toLowerCase();
      var list = FLAT.filter(function (p) {
        if (!q) return true;
        var hay = (p.label + " " + p.section + " " + (p.keywords || "")).toLowerCase();
        return hay.indexOf(q) !== -1;
      });
      items = list; sel = 0;
      if (!list.length) { results.innerHTML = '<div class="doc-search__empty">No results for &ldquo;' + esc(q) + '&rdquo;</div>'; return; }
      results.innerHTML = list.map(function (p, i) {
        return '<a href="' + p.href + '" class="' + (i === 0 ? "is-active" : "") + '" data-i="' + i + '">' +
          ico(p.icon) + '<span class="r-title">' + esc(p.label) + '</span>' +
          '<span class="r-sec">' + esc(p.section) + "</span></a>";
      }).join("");
    }
    function move(d) {
      var as = results.querySelectorAll("a");
      if (!as.length) return;
      as[sel] && as[sel].classList.remove("is-active");
      sel = (sel + d + as.length) % as.length;
      as[sel].classList.add("is-active");
      as[sel].scrollIntoView({ block: "nearest" });
    }
    function open() { overlay.classList.add("is-open"); input.value = ""; render(""); setTimeout(function () { input.focus(); }, 30); }
    function close() { overlay.classList.remove("is-open"); }

    openers.forEach(function (b) { b.addEventListener("click", open); });
    overlay.querySelector(".doc-search__backdrop").addEventListener("click", close);
    input.addEventListener("input", function () { render(input.value); });
    input.addEventListener("keydown", function (e) {
      if (e.key === "ArrowDown") { e.preventDefault(); move(1); }
      else if (e.key === "ArrowUp") { e.preventDefault(); move(-1); }
      else if (e.key === "Enter") { e.preventDefault(); var a = results.querySelectorAll("a")[sel]; if (a) { close(); navigate(a.href, true); } }
      else if (e.key === "Escape") { close(); }
    });
    document.addEventListener("keydown", function (e) {
      if ((e.metaKey || e.ctrlKey) && (e.key === "k" || e.key === "K")) { e.preventDefault(); overlay.classList.contains("is-open") ? close() : open(); }
      else if (e.key === "/" && !/input|textarea/i.test((e.target.tagName || "")) && !overlay.classList.contains("is-open")) { e.preventDefault(); open(); }
    });
  }

  /* ---- helpers --------------------------------------------------------- */
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
    return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
  }); }
  function slug(s) { return String(s).toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, ""); }

  /* ---- per-page render (everything that changes between pages) --------- */
  function renderPage() {
    setActiveNav();
    buildCrumbs();
    buildToc();
    buildPager();
    ensureFooter();   // footer is part of the content column; re-add after pjax swaps
    applyName();
    applyLogo();
    wireScreens();
    progress();
    spy();
  }

  /* ---- Content-only navigation (SPA-style) ----------------------------
     Internal documentation links swap just the <main> content instead of
     doing a full page load, so the sidebar and top bar never flicker. */
  function isInternalDoc(url) {
    // matches /documentation, /documentation/ and /documentation/<page>.html
    // (but not asset paths like /documentation/assets/docs.css)
    return url.origin === location.origin &&
      /^\/documentation(\/[a-z0-9-]+\.html)?\/?$/i.test(url.pathname);
  }
  function scrollToHash(hash) {
    if (!hash) { window.scrollTo(0, 0); return; }
    var el = document.getElementById(hash.replace(/^#/, ""));
    if (el) el.scrollIntoView();
    else window.scrollTo(0, 0);
  }
  function swap(html, urlStr, push) {
    var doc;
    try { doc = new DOMParser().parseFromString(html, "text/html"); } catch (e) { location.href = urlStr; return; }
    var nm = doc.querySelector(".doc-main"), cm = document.querySelector(".doc-main");
    if (!nm || !cm) { location.href = urlStr; return; }
    cm.innerHTML = nm.innerHTML;
    if (doc.title) document.title = doc.title;
    var nav = doc.body.getAttribute("data-nav");
    if (nav) { BODY.setAttribute("data-nav", nav); CURRENT = nav; }
    page = findPage(CURRENT) || page;
    var u = new URL(urlStr, location.href);
    if (push) history.pushState({ docs: 1 }, "", u.pathname + u.hash);
    renderPage();
    cm.classList.remove("is-swapping");
    scrollToHash(u.hash);
  }
  function navigate(urlStr, push) {
    var cm = document.querySelector(".doc-main");
    cm && cm.classList.add("is-swapping");
    fetch(urlStr, { headers: { "X-Requested-With": "fetch" }, credentials: "same-origin" })
      .then(function (r) { if (!r.ok) throw new Error("http " + r.status); return r.text(); })
      .then(function (html) { swap(html, urlStr, push); })
      .catch(function () { location.href = urlStr; });
  }
  function wirePjax() {
    document.addEventListener("click", function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest("a");
      if (!a) return;
      if (a.target && a.target !== "_self") return;
      if (a.hasAttribute("download")) return;
      var href = a.getAttribute("href") || "";
      if (!href || href.charAt(0) === "#") return;            // in-page anchor → native scroll
      var url;
      try { url = new URL(a.href, location.href); } catch (_) { return; }
      if (!isInternalDoc(url)) return;                          // external / asset → native nav
      e.preventDefault();
      // close the mobile drawer / search overlay if open
      var sb = document.querySelector(".doc-sidebar"); sb && sb.classList.remove("is-open");
      var scrim = document.getElementById("docs-scrim"); scrim && scrim.classList.remove("is-open");
      var ov = document.getElementById("docs-search"); ov && ov.classList.remove("is-open");
      if (url.pathname === location.pathname) { scrollToHash(url.hash); return; }
      navigate(url.href, true);
    });
    window.addEventListener("popstate", function () {
      if (isInternalDoc(new URL(location.href))) navigate(location.href, false);
    });
  }

  /* ---- One-time chrome: menu icon, logo, footer ------------------------ */
  function setMenuIcon() {
    var b = document.getElementById("docs-menu-btn");
    if (b && !b.querySelector("svg")) b.innerHTML = ico("menu");
  }
  /* ---- Branding: logo image + name -------------------------------------
     The logo is a single static file you can replace with your own:
       public/assets/documentation/images/logo.svg
     The product name lives in one place — window.DOCS_SITE.name (nav.js). */
  var SITE = window.DOCS_SITE || {};
  var SITE_NAME = SITE.name || "POS";
  var DEFAULT_LOGO = SITE.logo || "/assets/documentation/images/logo.svg";
  var BRAND = null; // filled from /documentation-brand on an installed app

  function logoUrl() {
    if (BRAND) {
      var theme = document.documentElement.getAttribute("data-theme");
      var u = theme === "dark" ? (BRAND.logoDark || BRAND.logo) : (BRAND.logo || BRAND.logoDark);
      if (u) return u;
    }
    return DEFAULT_LOGO;
  }
  function brandName() { return (BRAND && BRAND.name) || SITE_NAME; }

  function applyLogo() {
    var url = logoUrl();
    document.querySelectorAll(".doc-brand__mark").forEach(function (mark) {
      if (mark.getAttribute("data-logo") === url) return;
      var img = new Image();
      img.onload = function () {
        mark.setAttribute("data-logo", url);
        mark.classList.add("has-img"); mark.innerHTML = "";
        img.className = "doc-brand__img"; img.alt = brandName() + " logo"; mark.appendChild(img);
        // A real uploaded logo already carries the brand name — hide the
        // adjacent wordmark so the two don't collide. The square placeholder
        // keeps its wordmark (it has no text of its own).
        var holder = mark.closest(".doc-brand, .doc-footer__brand");
        if (holder) holder.classList.toggle("has-logo", url !== DEFAULT_LOGO);
      };
      img.src = url; // on error, the inline mark simply stays
    });
  }
  function applyName() {
    var name = brandName();
    if (/^pos$/i.test(name)) return; // default markup already says "POS"
    var nm = esc(name) + ' <span style="color:var(--text-tertiary);font-weight:500">User Guide</span>';
    document.querySelectorAll(".doc-brand__name, .doc-footer__name").forEach(function (el) { el.innerHTML = nm; });
    var copy = document.querySelector(".doc-footer__copy");
    if (copy) copy.textContent = "© 2026 " + name + ". All rights reserved.";
  }
  function loadBrand() {
    // Pull the customer's real logo/name from the app (when installed).
    // Any failure is silent — the bundled placeholder stays.
    fetch("/documentation-brand", { headers: { "X-Requested-With": "fetch" }, credentials: "same-origin" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (b) { if (b && (b.logo || b.name)) { BRAND = b; applyName(); applyLogo(); } })
      .catch(function () {});
  }

  /* ---- Footer (lives inside the content column, not under the sidebar) - */
  function footerHtml() {
    return '<div class="doc-footer__inner">' +
        '<div class="doc-footer__brand"><span class="doc-brand__mark">' + ico("pos") +
          '</span><span class="doc-footer__name">POS <span style="color:var(--text-tertiary);font-weight:500">User Guide</span></span></div>' +
        '<p class="doc-footer__tag">A point of sale you own outright — no monthly fees, ever.</p>' +
        '<div class="doc-footer__links">' +
          '<a href="/documentation/index.html">Home</a>' +
          '<a href="/documentation/getting-started.html">Getting started</a>' +
          '<a href="/documentation/installation.html">Installation</a>' +
          '<a href="/documentation/faq.html">FAQ</a>' +
          '<a href="/documentation/troubleshooting.html">Troubleshooting</a>' +
          '<a href="/documentation/glossary.html">Glossary</a>' +
        '</div>' +
        '<p class="doc-footer__copy">© 2026 POS. All rights reserved.</p>' +
        '<p class="doc-footer__made">Made with <span class="doc-footer__heart">&hearts;</span> by ' +
          '<a href="https://infinitietech.com/" target="_blank" rel="noopener">Infinitie Technologies</a></p>' +
      '</div>';
  }
  function ensureFooter() {
    // Inserted as a SIBLING right after .doc-layout — never inside it. That
    // way it (a) survives the PJAX swap (which only replaces .doc-main's
    // innerHTML), (b) spans the full page width as a body-level block, and
    // (c) sits OUTSIDE the sticky sidebar/TOC's containing block, so those
    // full-height sticky columns can't slide down and overlap it.
    var layout = document.querySelector(".doc-layout");
    if (!layout) return;
    var next = layout.nextElementSibling;
    if (next && next.classList.contains("doc-footer")) return;
    var f = document.createElement("footer");
    f.className = "doc-footer";
    f.innerHTML = footerHtml();
    layout.parentNode.insertBefore(f, layout.nextSibling);
  }

  /* ---- boot ------------------------------------------------------------ */
  buildSidebar();   // built once — never reloaded
  setMenuIcon();
  wireMobile();
  wireTheme();
  wireSearch();
  wirePjax();
  renderPage();     // builds footer + applies placeholder logo/name immediately
  loadBrand();      // then upgrades to the app's real logo/name when available

  var ticking = false;
  window.addEventListener("scroll", function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () { progress(); spy(); ticking = false; });
  }, { passive: true });
})();
