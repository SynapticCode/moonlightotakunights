/* ===========================================================
   recap.js — Miku recap gallery + lightbox
   - Builds masonry tiles from /assets/images/miku-recap/1.jpg .. N.jpg
   - Lazy-loads via IntersectionObserver
   - Lightbox with keyboard nav, swipe, click-outside-to-close
   =========================================================== */
(function () {
    "use strict";

    const PHOTO_COUNT = 20;
    const BASE_PATH   = "/assets/images/miku-recap/";
    const EXT         = ".jpg";

    const grid     = document.getElementById("recap-grid");
    const lightbox = document.getElementById("recap-lightbox");
    const lbImg    = document.getElementById("recap-lightbox-img");
    const lbCap    = document.getElementById("recap-lightbox-caption");
    const lbClose  = document.getElementById("recap-close");
    const lbPrev   = document.getElementById("recap-prev");
    const lbNext   = document.getElementById("recap-next");

    if (!grid || !lightbox) return;

    let currentIndex = 0;
    const photos = [];

    // ---- Build photo list ----
    for (let i = 1; i <= PHOTO_COUNT; i++) {
        photos.push({
            src: `${BASE_PATH}${i}${EXT}`,
            alt: `Hatsune Miku unofficial after-party — photo ${i} of ${PHOTO_COUNT}`,
            caption: `${String(i).padStart(2, "0")} / ${String(PHOTO_COUNT).padStart(2, "0")} — QXT'S Nightclub · 📸 @tenryu.photo`
        });
    }

    // ---- Build tiles ----
    const io = ("IntersectionObserver" in window)
        ? new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const img = entry.target;
                const realSrc = img.getAttribute("data-src");
                if (realSrc) {
                    img.src = realSrc;
                    img.addEventListener("load", () => img.classList.add("is-loaded"), { once: true });
                    img.addEventListener("error", () => {
                        // Hide the broken tile entirely so missing photos don't leave gaps
                        const tile = img.closest(".recap-tile");
                        if (tile && tile.parentNode) tile.parentNode.removeChild(tile);
                    }, { once: true });
                    img.removeAttribute("data-src");
                }
                obs.unobserve(img);
            });
        }, { rootMargin: "200px 0px" })
        : null;

    const frag = document.createDocumentFragment();

    photos.forEach((p, idx) => {
        const tile = document.createElement("button");
        tile.type = "button";
        tile.className = "recap-tile";
        tile.setAttribute("role", "listitem");
        tile.setAttribute("aria-label", `Open photo ${idx + 1}`);
        tile.dataset.index = String(idx);

        const img = document.createElement("img");
        img.alt = p.alt;
        img.loading = "lazy";
        img.decoding = "async";
        if (io) {
            img.setAttribute("data-src", p.src);
        } else {
            img.src = p.src;
            img.addEventListener("load", () => img.classList.add("is-loaded"), { once: true });
            img.addEventListener("error", () => {
                if (tile.parentNode) tile.parentNode.removeChild(tile);
            }, { once: true });
        }

        tile.appendChild(img);
        tile.addEventListener("click", () => openLightbox(idx));
        frag.appendChild(tile);

        if (io) io.observe(img);
    });

    grid.appendChild(frag);

    // ---- Lightbox controls ----
    function openLightbox(index) {
        currentIndex = index;
        showCurrent();
        lightbox.classList.add("is-open");
        lightbox.setAttribute("aria-hidden", "false");
        document.body.classList.add("recap-lightbox-open");
    }

    function closeLightbox() {
        lightbox.classList.remove("is-open");
        lightbox.setAttribute("aria-hidden", "true");
        document.body.classList.remove("recap-lightbox-open");
    }

    function showCurrent() {
        const p = photos[currentIndex];
        if (!p) return;
        lbImg.src = p.src;
        lbImg.alt = p.alt;
        lbCap.textContent = p.caption;
    }

    function step(delta) {
        currentIndex = (currentIndex + delta + photos.length) % photos.length;
        showCurrent();
    }

    lbClose.addEventListener("click", closeLightbox);
    lbPrev.addEventListener("click", () => step(-1));
    lbNext.addEventListener("click", () => step(1));

    // Click outside image to close
    lightbox.addEventListener("click", (e) => {
        if (e.target === lightbox) closeLightbox();
    });

    // Keyboard nav
    document.addEventListener("keydown", (e) => {
        if (!lightbox.classList.contains("is-open")) return;
        if (e.key === "Escape")      closeLightbox();
        else if (e.key === "ArrowLeft")  step(-1);
        else if (e.key === "ArrowRight") step(1);
    });

    // Swipe nav (mobile)
    let touchStartX = 0;
    let touchStartY = 0;
    lightbox.addEventListener("touchstart", (e) => {
        const t = e.changedTouches[0];
        touchStartX = t.clientX;
        touchStartY = t.clientY;
    }, { passive: true });
    lightbox.addEventListener("touchend", (e) => {
        const t = e.changedTouches[0];
        const dx = t.clientX - touchStartX;
        const dy = t.clientY - touchStartY;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
            step(dx > 0 ? -1 : 1);
        }
    }, { passive: true });
})();
