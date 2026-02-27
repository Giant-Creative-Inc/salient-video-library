jQuery(function ($) {
  $("[data-svl]").each(function () {
    const $root = $(this);

    const $status = $root.find("[data-svl-status]");
    const $loader = $root.find("[data-svl-loader]");
    const $clearBtn = $root.find("[data-svl-clear]");
    const $results = $root.find("[data-svl-results]");

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
      return {
        market: $root.find('[data-svl-filter="market"]').val() || "0",
        product: $root.find('[data-svl-filter="product"]').val() || "0",
        project: $root.find('[data-svl-filter="project"]').val() || "0",
        videoCategory: $root.find('[data-svl-filter="video-category"]').val() || "0",
      };
    }

    function updateClearButtonVisibility() {
      const filters = getFilters();
      const active =
        (filters.market !== "0") ||
        (filters.product !== "0") ||
        (filters.project !== "0") ||
        (filters.videoCategory !== "0");
      $clearBtn.prop("hidden", !active);
    }

    function rebuildSelect($select, placeholder, options, keepValue) {
      const current = keepValue || "0";
      $select.empty();
      $select.append($("<option/>").attr("value", "0").text(placeholder));

      (options || []).forEach((opt) => {
        $select.append($("<option/>").attr("value", String(opt.id)).text(opt.name));
      });

      // Restore if still valid; otherwise reset to 0.
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

      rebuildSelect(
        $root.find('[data-svl-filter="video-category"]'),
        "Category",
        termPayload.videoCategory,
        current.videoCategory
      );
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
          perCategory: SVL?.config?.perCategory || 3,
          maxCategories: SVL?.config?.maxCategories || "",
          eagerFirst: SVL?.config?.eagerFirst || 3,
        },
      })
        .done(function (res) {
          if (!res || !res.success) return;

          // Update dropdown options (dependent filters).
          if (res.data && res.data.terms) {
            applyTermOptions(res.data.terms);
          }

          // Update sections.
          if (res.data && typeof res.data.html === "string") {
            $results.html(res.data.html);
          }

          // Update schema JSON-LD (replace existing script if present).
          if (res.data && typeof res.data.schema === "string") {
            $root.find('script[type="application/ld+json"]').remove();
            $root.append(res.data.schema);
          }
        })
        .always(function () {
          setLoading(false);
          updateClearButtonVisibility();
        });
    }

    // Filter changes
    $root.on("change", "[data-svl-filter]", function () {
      requestUpdate();
    });

    // Clear filters
    $root.on("click", "[data-svl-clear]", function () {
      $root.find("[data-svl-filter]").val("0");
      updateClearButtonVisibility();
      requestUpdate();
    });

    // Init
    updateClearButtonVisibility();
  });
});