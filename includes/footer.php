</main>

    <!-- ============ FOOTER PRIVÉ ============ -->
    <footer class="dash-footer">
        <span>&copy; <?= date('Y') ?> FleetIoT — Tous droits réservés</span>
        <div class="dash-footer-status">
            <span class="dash-dot"></span>
            <span>Simulateur IoT actif</span>
        </div>
    </footer>

    <script>
        // ====== MENU MOBILE (hamburger) ======
        var burger = document.getElementById('dashBurger');
        var navMenu = document.getElementById('dashNavMenu');

        if (burger && navMenu) {
            burger.addEventListener('click', function () {
                var ouvert = navMenu.classList.toggle('open');
                burger.classList.toggle('open');
                burger.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
            });

            // Ferme le menu après avoir cliqué un lien (évite qu'il reste ouvert après navigation)
            navMenu.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    navMenu.classList.remove('open');
                    burger.classList.remove('open');
                });
            });
        }

        // ====== MENU DÉROULANT UTILISATEUR (Mon profil / Déconnexion) ======
        var userToggle = document.getElementById('dashUserToggle');
        var userDropdown = document.getElementById('dashUserDropdown');

        if (userToggle && userDropdown) {
            userToggle.addEventListener('click', function (e) {
                e.stopPropagation();
                userDropdown.classList.toggle('open');
            });

            // Ferme le menu si on clique n'importe où ailleurs sur la page
            document.addEventListener('click', function (e) {
                if (!userDropdown.contains(e.target) && e.target !== userToggle) {
                    userDropdown.classList.remove('open');
                }
            });
        }
    </script>

</body>
</html>