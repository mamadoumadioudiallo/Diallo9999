// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : assets/js/ajax.js
// Rôle    : Appels AJAX génériques de la zone privée
//           (prise en charge des alertes depuis le dashboard)
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

    var alertsList = document.getElementById('alertsList');
    if (!alertsList) return; // Cette page n'a pas de liste d'alertes

    var csrfToken = alertsList.getAttribute('data-csrf');

    alertsList.addEventListener('click', function (e) {
        var btn = e.target.closest('.alert-take');
        if (!btn) return;

        var idAlerte = btn.getAttribute('data-id');
        var alertItem = btn.closest('.alert-item');
        var originalText = btn.textContent;

        btn.textContent = 'En cours...';
        btn.style.pointerEvents = 'none';

        fetch('../api/alertes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=take&id_alerte=' + encodeURIComponent(idAlerte) + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    alertItem.style.opacity = '0';
                    setTimeout(function () {
                        alertItem.remove();
                        if (!alertsList.querySelector('.alert-item')) {
                            alertsList.innerHTML = '<p class="alert-empty">Aucune alerte active pour le moment.</p>';
                        }
                    }, 200);

                    updateAlertCounters();
                } else {
                    alert(data.error || 'Erreur lors de la prise en charge de l\'alerte.');
                    btn.textContent = originalText;
                    btn.style.pointerEvents = 'auto';
                }
            })
            .catch(function () {
                alert('Erreur réseau, veuillez réessayer.');
                btn.textContent = originalText;
                btn.style.pointerEvents = 'auto';
            });
    });

    // ====== MISE À JOUR DES COMPTEURS (KPI, carte, panneau) ======
    function updateAlertCounters() {
        var kpiValue = document.getElementById('kpiAlertesValue');
        var kpiTrend = document.getElementById('kpiAlertesTrend');
        var kpiCard = document.getElementById('kpiAlertesCard');
        var mapBadge = document.getElementById('mapAlertesBadge');
        var countBadge = document.getElementById('alertsCountBadge');

        if (!kpiValue) return; // Pas de KPI sur cette page

        var nouveauTotal = Math.max(0, parseInt(kpiValue.textContent, 10) - 1);
        kpiValue.textContent = nouveauTotal;

        if (countBadge) {
            countBadge.textContent = Math.max(0, parseInt(countBadge.textContent, 10) - 1);
        }
        if (mapBadge) {
            mapBadge.innerHTML = '&#9679; ' + nouveauTotal + ' alertes';
        }
        if (nouveauTotal === 0) {
            kpiTrend.textContent = 'Tout est calme';
            kpiCard.classList.remove('danger');
        }
    }

});