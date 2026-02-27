jQuery(function ($) {
  $("[data-svl]").each(function () {
    const $root = $(this);

    const $status = $root.find("[data-svl-status]");
    const $loader = $root.find("[data-svl-loader]");
    const $clearBtn = $root.find("[data-svl-clear]");
    const $results = $root.find("[data-svl-results]");

    // NEW: if we're on a video-category archive, PHP passes this non-zero value
    const lockedCategoryId = parseInt(SVL?.config?.lockedCategoryId || 0, 10);

    let isLoading = false;

    function setLoading(on, message) {
      isLoading = !!on;
      $status.attr("aria-busy", on ? "true" : "false");

      if (on) {
        $loader.prop("hidden", false);
        if (message) $loader.find(".svl__loader-text").text(message);
      } else {
        $loader.prop("hidden", true);
      }
    }

    function getFilters() {
      const f = {
        market: $root.find('[data-svl-filter="market"]').val() || "0",
        product: $root.find('[data-svl-filter="product"]').val() || "0",
        project: $root.find('[data-svl-filter="project"]').val() || "0",
        videoCategory:
          $root.find('[data-svl-filter="video-category"]').val() || "0",
      };

      // NEW: lock category to the archive term so AJAX can’t drift
      if (lockedCategoryId > 0) {
        f.videoCategory = String(lockedCategoryId);
      }

      return f;
    }

    function updateClearButtonVisibility() {
      const f = getFilters();

      // NEW: when locked, category shouldn't count as "active" for Clear Filters
      const active =
        f.market !== "0" || f.product !== "0" || f.project !== "0";

      $clearBtn.prop("hidden", !active);
    }

    function rebuildSelect($select, placeholder, options, keepValue) {
      const current = keepValue || "0";
      $select.empty();
      $select.append($("<option/>").attr("value", "0").text(placeholder));

      (options || []).forEach((opt) => {
        $select.append(
          $("<option/>").attr("value", String(opt.id)).text(opt.name)
        );
      });

      const has = $select.find('option[value="' + current + '"]').length > 0;
      $select.val(has ? current : "0");
    }

    function applyTermOptions(termPayload) {
      const current = getFilters();

      rebuildSelect(
        $root.find('[data-svl-filter="market"]'),
        "Market",
        termPayload.market,
        current.market
      );
      rebuildSelect(
        $root.find('[data-svl-filter="product"]'),
        "Product",
        termPayload.product,
        current.product
      );
      rebuildSelect(
        $root.find('[data-svl-filter="project"]'),
        "Project",
        termPayload.project,
        current.project
      );

      // NEW: if the category is locked, do not rebuild it (it’s hidden/disabled in PHP)
      if (lockedCategoryId <= 0) {
        rebuildSelect(
          $root.find('[data-svl-filter="video-category"]'),
          "Category",
          termPayload.videoCategory,
          current.videoCategory
        );
      }
    }

    function reinitLightboxes() {
      try {
        // Some Salient builds expose Nectar init helpers
        if (window.Nectar && typeof window.Nectar.lightboxInit === "function") {
          window.Nectar.lightboxInit();
        }

        // prettyPhoto (common Salient setup)
        if (typeof window.prettyPhoto === "function" && $.fn.prettyPhoto) {
          $("a.pretty_photo").prettyPhoto();
        }

        // magnific (some installs)
        if ($.fn.magnificPopup) {
          $("a.pretty_photo").magnificPopup({ type: "iframe" });
        }
      } catch (e) {
        // no-op
      }
    }

    function requestUpdate() {
      if (isLoading) return;

      updateClearButtonVisibility();
      setLoading(true, SVL?.strings?.loading || "Loading videos…");

      const f = getFilters();

      $.ajax({
        url: SVL.ajaxUrl,
        method: "POST",
        dataType: "json",
        data: {
          action: "svl_filter",
          nonce: SVL.nonce,
          market: f.market,
          product: f.product,
          project: f.project,
          videoCategory: f.videoCategory,

          // NEW: if locked, PHP already sets perCategory=-1 and maxCategories=1 via localization,
          // but we still pass them through explicitly.
          perCategory: SVL?.config?.perCategory ?? 3,
          maxCategories: SVL?.config?.maxCategories ?? "",
          eagerFirst: SVL?.config?.eagerFirst ?? 3,
        },
      })
        .done(function (res) {
          if (!res || !res.success) return;

          if (res.data && res.data.terms) {
            applyTermOptions(res.data.terms);
          }

          if (res.data && typeof res.data.html === "string") {
            $results.html(res.data.html);
          }

          if (res.data && typeof res.data.schema === "string") {
            $root.find('script[type="application/ld+json"]').remove();
            $root.append(res.data.schema);
          }

          reinitLightboxes();
        })
        .always(function () {
          setLoading(false);
          updateClearButtonVisibility();
        });
    }

    // If locked, ensure the hidden/disabled select (if present) matches lock
    if (lockedCategoryId > 0) {
      $root
        .find('[data-svl-filter="video-category"]')
        .val(String(lockedCategoryId))
        .prop("disabled", true);

      // If your PHP used hidden attribute on wrapper, this is just a safety net:
      $root.find(".svl__category-wrap").prop("hidden", true);
    }

    $root.on("change", "[data-svl-filter]", function (e) {
      // NEW: if somehow category select is visible, ignore changes
      if (
        lockedCategoryId > 0 &&
        $(e.target).is('[data-svl-filter="video-category"]')
      ) {
        $(e.target).val(String(lockedCategoryId));
        return;
      }

      requestUpdate();
    });

    $root.on("click", "[data-svl-clear]", function () {
      // Only reset non-locked selects
      $root.find('[data-svl-filter="market"]').val("0");
      $root.find('[data-svl-filter="product"]').val("0");
      $root.find('[data-svl-filter="project"]').val("0");

      if (lockedCategoryId <= 0) {
        $root.find('[data-svl-filter="video-category"]').val("0");
      }

      updateClearButtonVisibility();
      requestUpdate();
    });

    // init
    updateClearButtonVisibility();
    reinitLightboxes();

    // On archive pages, initial render is already correct, but ensure state is applied
    if (lockedCategoryId > 0) {
      requestUpdate();
    }
  });
});