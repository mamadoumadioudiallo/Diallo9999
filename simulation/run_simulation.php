<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : simulation/run_simulation.php
// Rôle    : Interface de contrôle du simulateur IoT (admin)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

// Appel manuel d'un cycle (bouton "Exécuter un cycle maintenant")
$cycleResult = [];
if (isset($_POST['run_cycle'])) {
    require_once 'simulator.php';
    $cycleResult = runSimulationCycle($pdo);
}

// Compter les véhicules actifs
$nbActifs = $pdo->query("SELECT COUNT(*) FROM vehicules WHERE statut='actif'")->fetchColumn();

$pageTitle = 'Simulateur IoT';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Simulateur IoT</h1>
        <p class="subtitle"><?= $nbActifs ?> véhicule(s) actif(s) — <?= $nbActifs > 0 ? 'prêt à simuler' : 'aucun véhicule à simuler' ?></p>
    </div>
</div>

<div class="panel" style="padding:24px;margin-bottom:16px;">
    <h3 style="margin-bottom:12px;font-size:15px;">Mode manuel</h3>
    <p style="color:#5C6B68;font-size:13px;margin-bottom:16px;">
        Exécute un cycle de simulation unique : génère une nouvelle position GPS, vitesse, niveau de carburant
        et température moteur pour chaque véhicule actif, et vérifie les seuils d'alerte.
    </p>
    <form method="POST">
        <button type="submit" name="run_cycle" class="btn-dash btn-dash-primary" <?= $nbActifs == 0 ? 'disabled' : '' ?>>
            &#9654; Exécuter un cycle maintenant
        </button>
    </form>

    <?php if (!empty($cycleResult)): ?>
        <div style="margin-top:18px;background:#F5F7F6;border-radius:8px;padding:14px;font-family:monospace;font-size:12px;max-height:300px;overflow-y:auto;">
            <?php foreach ($cycleResult as $line): ?>
                <div><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="panel" style="padding:24px;margin-bottom:16px;">
    <h3 style="margin-bottom:12px;font-size:15px;">Mode automatique (continu)</h3>
    <p style="color:#5C6B68;font-size:13px;margin-bottom:16px;">
        Lance la simulation en boucle toutes les 5 secondes directement dans ce navigateur.
        Laissez cette page ouverte dans un onglet pendant que vous consultez le tableau de bord dans un autre onglet.
    </p>
    <button id="btnAuto" class="btn-dash btn-dash-primary">&#9654; Démarrer la simulation continue</button>
    <span id="autoStatus" style="margin-left:12px;font-size:13px;color:#5C6B68;"></span>
    <div id="autoLog" style="margin-top:18px;background:#F5F7F6;border-radius:8px;padding:14px;font-family:monospace;font-size:12px;max-height:300px;overflow-y:auto;display:none;"></div>
</div>

<div class="panel" style="padding:24px;">
    <h3 style="margin-bottom:12px;font-size:15px;">&#9888; Production — automatisation recommandée</h3>
    <p style="color:#5C6B68;font-size:13px;">
        Pour un déploiement réel, ce script doit être exécuté automatiquement toutes les <?= 5 ?> secondes via une tâche planifiée :
    </p>
    <ul style="margin-top:10px;font-size:12px;color:#5C6B68;list-style:disc;padding-left:20px;">
        <li><strong>Windows</strong> : Planificateur de tâches → exécuter <code>php simulator.php</code> en boucle</li>
        <li><strong>Linux</strong> : Cron job avec un wrapper bash + sleep, ou un service systemd</li>
        <li><strong>Production cloud</strong> : worker dédié (Supervisor, PM2 avec wrapper, ou tâche planifiée serverless)</li>
    </ul>
</div>

<script>
var autoRunning = false;
var autoInterval = null;
var btnAuto = document.getElementById('btnAuto');
var autoStatus = document.getElementById('autoStatus');
var autoLog = document.getElementById('autoLog');

btnAuto.addEventListener('click', function () {
    if (!autoRunning) {
        autoRunning = true;
        btnAuto.textContent = '⏸ Arrêter la simulation';
        autoStatus.textContent = 'Simulation active...';
        autoLog.style.display = 'block';

        runCycleAjax(); // premier cycle immédiat
        autoInterval = setInterval(runCycleAjax, 5000);
    } else {
        autoRunning = false;
        btnAuto.textContent = '▶ Démarrer la simulation continue';
        autoStatus.textContent = 'Arrêtée.';
        clearInterval(autoInterval);
    }
});

function runCycleAjax() {
    fetch('simulator.php?run=1')
        .then(res => res.json())
        .then(data => {
            var time = new Date().toLocaleTimeString();
            var entry = document.createElement('div');
            entry.innerHTML = '<strong>[' + time + ']</strong><br>' + data.log.join('<br>');
            entry.style.marginBottom = '10px';
            entry.style.borderBottom = '1px solid #ddd';
            entry.style.paddingBottom = '8px';
            autoLog.prepend(entry);
        })
        .catch(err => console.error('Erreur simulation:', err));
}
</script>

<?php require_once '../includes/footer.php'; ?>