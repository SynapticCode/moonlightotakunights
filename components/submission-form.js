/**
 * Shared submission form handler.
 * Any <form data-mln-submission="dj|idol|vendor|sponsor|investor">
 * gets wired automatically on DOMContentLoaded.
 */

(function () {
    'use strict';

    const ENDPOINT = '/api/submission.php';

    function serialize(form) {
        const data = {};
        for (const el of form.elements) {
            if (!el.name) continue;
            if (el.type === 'checkbox') {
                data[el.name] = el.checked ? 1 : 0;
            } else {
                data[el.name] = el.value;
            }
        }
        return data;
    }

    function setStatus(form, kind, message) {
        const box = form.querySelector('.sub-status');
        if (!box) return;
        box.className = 'sub-status ' + kind;
        box.textContent = message;
    }

    function validateSelects(form) {
        for (const el of form.elements) {
            if (el.tagName !== 'SELECT') continue;
            if (!el.required) continue;
            if (!el.value) {
                el.focus();
                return el.labels?.[0]?.textContent?.replace(/\s*\*\s*$/, '').trim()
                    || el.name;
            }
        }
        return null;
    }

    function wire(form) {
        const kind = form.getAttribute('data-mln-submission');
        if (!kind) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const submitBtn = form.querySelector('button[type=submit]');

            const missingSelect = validateSelects(form);
            if (missingSelect) {
                setStatus(form, 'err', `Please choose an option for "${missingSelect}".`);
                return;
            }

            if (submitBtn) submitBtn.disabled = true;

            const payload = serialize(form);
            payload.kind = kind;
            payload.source_page = location.pathname;

            try {
                const res = await fetch(ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const json = await res.json();

                if (json.ok) {
                    setStatus(form, 'ok', "Got it. Redirecting you to next steps…");
                    form.reset();

                    // Fire dataLayer event for GA4/GTM
                    if (window.dataLayer) {
                        window.dataLayer.push({
                            event: 'submission_success',
                            submission_kind: kind,
                            submission_id: json.submission_id,
                        });
                    }
                    // Meta pixel client-side dedupe (server fires too)
                    if (window.fbq) {
                        window.fbq('track', 'Lead', {
                            content_category: kind + '_apply',
                            content_name: kind.charAt(0).toUpperCase() + kind.slice(1) + ' Application',
                        }, { eventID: json.event_id });
                    }

                    // Per-kind thank-you redirect
                    const thanksMap = {
                        sponsor:  '/sponsors/thank-you/',
                        dj:       '/djs/thank-you/',
                        idol:     '/idols/thank-you/',
                        vendor:   '/vendors/thank-you/',
                        investor: '/investors/thank-you/',
                    };
                    const dest = thanksMap[kind];
                    if (dest) setTimeout(() => { window.location.href = dest; }, 900);
                } else {
                    setStatus(form, 'err', json.error || 'Something went wrong. Try again.');
                    if (submitBtn) submitBtn.disabled = false;
                }
            } catch (err) {
                setStatus(form, 'err', 'Network error. Check your connection and try again.');
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('form[data-mln-submission]').forEach(wire);
    });
})();
