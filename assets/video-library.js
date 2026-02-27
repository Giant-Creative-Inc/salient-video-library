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
      const f = getFilters();
      const active =
        f.market !== "0" ||
        f.product !== "0" ||
        f.project !== "0" ||
        f.videoCategory !== "0";
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
      rebuildSelect(
        $root.find('[data-svl-filter="video-category"]'),
        "Category",
        termPayload.videoCategory,
        current.videoCategory
      );
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
          perCategory: SVL?.config?.perCategory || 3,
          maxCategories: SVL?.config?.maxCategories || "",
          eagerFirst: SVL?.config?.eagerFirst || 3,
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

    $root.on("change", "[data-svl-filter]", function () {
      requestUpdate();
    });

    $root.on("click", "[data-svl-clear]", function () {
      $root.find("[data-svl-filter]").val("0");
      updateClearButtonVisibility();
      requestUpdate();
    });

    // init
    updateClearButtonVisibility();
    reinitLightboxes();
  });
});