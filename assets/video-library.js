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

  function bindVideoLightboxDelegated() {
    // Prevent double-binding if your init runs twice
    if ($root.data("svlLightboxBound")) return;
    $root.data("svlLightboxBound", true);

    $root.on("click", "a.nectar_video_lightbox", function (e) {
      // If fancybox exists, force open with fancybox (works after AJAX)
      if (typeof $.fancybox === "function") {
        e.preventDefault();

        const $a = $(this);

        // Salient usually puts the video URL in href, but we’ll fallback
        const url =
          $a.attr("href") ||
          $a.data("video-url") ||
          $a.attr("data-video-url") ||
          "";

        if (!url) return;

        $.fancybox.open({
          src: url,
          type: "iframe",
          opts: {
            iframe: { preload: false },
            smallBtn: true,
            toolbar: true,
          },
        });
      }
      // If fancybox isn't present, let the default behavior happen.
    });
  }

    function requestUpdate() {
      if (isLoading) return;

      updateClearButtonVisibility();
      setLoading(true, SVL?.strings?.loading || "Loading videos…");

      const f = getFilters();

      // FORCE correct values when category is locked (taxonomy archive)
      const perCategory =
        lockedCategoryId > 0 ? -1 : (SVL?.config?.perCategory ?? 3);

      const maxCategories =
        lockedCategoryId > 0 ? "1" : (SVL?.config?.maxCategories ?? "");

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
          perCategory: perCategory,
          maxCategories: maxCategories,
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

          bindVideoLightboxDelegated();
        })
        .always(function () {
          setLoading(false);
          updateClearButtonVisibility();
          bindVideoLightboxDelegated();
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
    bindVideoLightboxDelegated();

    // // On archive pages, initial render is already correct, but ensure state is applied
    // if (lockedCategoryId > 0) {
    //   requestUpdate();
    // }
  });
});