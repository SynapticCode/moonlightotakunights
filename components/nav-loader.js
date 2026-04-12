(function() {
    fetch('/components/nav.html')
        .then(function(response) {
            if (!response.ok) throw new Error('Nav fetch failed: ' + response.status);
            return response.text();
        })
        .then(function(html) {
            var navElements = document.querySelectorAll('nav');
            navElements.forEach(function(nav) {
                nav.innerHTML = html;
            });
            initNavInteractions();
            markActiveNavLink();
        })
        .catch(function(err) {
            console.error('[Moonlight] Shared nav failed to load:', err);
        });

    function initNavInteractions() {
        var mobileToggle = document.querySelector('.mobile-toggle');
        var navLinks = document.querySelector('.nav-links');
        if (mobileToggle && navLinks) {
            mobileToggle.addEventListener('click', function() {
                navLinks.classList.toggle('active');
                mobileToggle.classList.toggle('active');
            });
            document.querySelectorAll('.nav-links a').forEach(function(link) {
                link.addEventListener('click', function() {
                    navLinks.classList.remove('active');
                    mobileToggle.classList.remove('active');
                });
            });
        }

        document.querySelectorAll('.nav-links a[href^="#"], .nav-links a[href^="/#"]').forEach(function(anchor) {
            anchor.addEventListener('click', function(e) {
                var href = this.getAttribute('href');
                var hashIndex = href.indexOf('#');
                if (hashIndex === -1) return;
                var targetId = href.substring(hashIndex);
                var onHomepage = window.location.pathname === '/' || window.location.pathname === '/index.html';
                if (onHomepage && targetId.length > 1) {
                    var target = document.querySelector(targetId);
                    if (target) {
                        e.preventDefault();
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            });
        });
    }

    function markActiveNavLink() {
        var currentPath = window.location.pathname;
        document.querySelectorAll('.nav-links a').forEach(function(link) {
            var linkPath = link.getAttribute('href');
            if (linkPath === currentPath || (currentPath.indexOf(linkPath) === 0 && linkPath !== '/' && linkPath.indexOf('#') === -1 && linkPath.indexOf('http') !== 0)) {
                link.setAttribute('aria-current', 'page');
                link.classList.add('active');
            }
        });
    }
})();
