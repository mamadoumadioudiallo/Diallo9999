// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : assets/js/charts.js
// Rôle    : Graphiques du tableau de bord (évolution km/alertes,
//           répartition des types d'alertes) — alimentés par api/stats.php
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

    var evolutionCanvas = document.getElementById('evolutionChart');
    var repartitionCanvas = document.getElementById('repartitionChart');

    if (!evolutionCanvas && !repartitionCanvas) return; // Page sans graphiques

    fetch('../api/stats.php?jours=7')
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (evolutionCanvas) {
                dessinerGraphiqueEvolution(data.evolution);
            }
            if (repartitionCanvas) {
                dessinerGraphiqueRepartition(data.repartition_alertes, data.tonnage_actuel_t || 0);
            }
        })
        .catch(function (err) {
            console.error('Erreur de chargement des statistiques :', err);
        });

    function formaterDate(isoDate) {
        var d = new Date(isoDate + 'T00:00:00');
        return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
    }

    function dessinerGraphiqueEvolution(evolution) {
        var labels = evolution.periode.map(formaterDate);

        new Chart(evolutionCanvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Kilométrage (km)',
                        data: evolution.km,
                        backgroundColor: '#1D9E75',
                        borderRadius: 4,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Alertes',
                        data: evolution.alertes,
                        type: 'line',
                        borderColor: '#E24B4A',
                        backgroundColor: '#E24B4A',
                        tension: 0.3,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: {
                        type: 'linear',
                        position: 'left',
                        title: { display: true, text: 'km' },
                        beginAtZero: true
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        title: { display: true, text: 'Alertes' },
                        beginAtZero: true,
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    function dessinerGraphiqueRepartition(repartition, tonnageActuelT) {
        var libelles = {
            vitesse_excessive: 'Vitesse excessive',
            temperature_critique: 'Température critique',
            carburant_bas: 'Carburant bas',
            hors_zone: 'Hors corridor',
            surcharge: 'Surcharge',
            tpms_pression: 'Pression pneu (TPMS)',
            tpms_temperature: 'Température pneu (TPMS)',
            moteur_anomalie: 'Anomalie moteur'
        };
        var couleurs = {
            vitesse_excessive: '#E24B4A',
            temperature_critique: '#BA7517',
            carburant_bas: '#3B82C4',
            hors_zone: '#7C5CBF',
            surcharge: '#D9534F',
            tpms_pression: '#1D9E75',
            tpms_temperature: '#E08A1E',
            moteur_anomalie: '#888888'
        };
        var couleurDefaut = '#9FA8B0';

        var typesPresents = Object.keys(repartition).filter(function (type) {
            return repartition[type] > 0;
        });

        if (typesPresents.length === 0) {
            repartitionCanvas.parentElement.innerHTML = '<p style="text-align:center;color:#5C6B68;padding:30px 0;">Aucune alerte sur la période.</p>';
            return;
        }

        var labels = typesPresents.map(function (type) {
            return libelles[type] || (type.charAt(0).toUpperCase() + type.slice(1).replace(/_/g, ' '));
        });
        var valeurs = typesPresents.map(function (type) { return repartition[type]; });
        var couleursFinales = typesPresents.map(function (type) { return couleurs[type] || couleurDefaut; });

        // Plugin personnalisé : affiche le tonnage au centre du donut
        var pluginCentreTonnage = {
            id: 'centreTonnage',
            beforeDraw: function (chart) {
                var ctx = chart.ctx;
                var width = chart.width;
                var height = chart.height;
                var legendHeight = chart.legend ? chart.legend.height : 60;
                var cx = width / 2;
                var cy = (height - legendHeight) / 2;

                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                var texte = tonnageActuelT > 0
                    ? String(tonnageActuelT).replace('.', ',') + ' t'
                    : '0 t';

                ctx.font = 'bold 16px Arial';
                ctx.fillStyle = '#085041';
                ctx.fillText(texte, cx, cy - 10);

                ctx.font = '10px Arial';
                ctx.fillStyle = '#5C6B68';
                ctx.fillText('en transit', cx, cy + 10);
                ctx.restore();
            }
        };

        Chart.register(pluginCentreTonnage);

        new Chart(repartitionCanvas, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: valeurs,
                    backgroundColor: couleursFinales,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                cutout: '60%',
                layout: {
                    padding: { top: 10, bottom: 10 }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        align: 'start', // aligné à gauche
                        labels: {
                            boxWidth: 12,
                            padding: 12,
                            font: { size: 11 },
                            textAlign: 'left'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var total = context.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct = Math.round(context.parsed / total * 100);
                                return context.label + ' : ' + context.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            },
            plugins: [pluginCentreTonnage]
        });

        // Désenregistrer après usage pour ne pas affecter d'autres graphiques
        Chart.unregister(pluginCentreTonnage);
    }

});