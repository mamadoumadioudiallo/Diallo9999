<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/configuration.php
// Rôle    : Configuration des seuils d'alerte utilisés par le simulateur
//           (table `configurations`, clé/valeur)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$message = '';
$messageType = '';

// ====== DÉFINITION DES PARAMÈTRES CONNUS ======
// (mêmes clés que celles lues par simulation/simulator.php)
$parametresDefinition = [
    'seuil_vitesse_minier'   => ['label' => 'Seuil vitesse — véhicules miniers',  'unite' => 'km/h', 'defaut' => 80,  'min' => 10, 'max' => 200],
    'seuil_vitesse_routier'  => ['label' => 'Seuil vitesse — véhicules routiers', 'unite' => 'km/h', 'defaut' => 120, 'min' => 10, 'max' => 200],
    'seuil_temperature'      => ['label' => 'Seuil température moteur critique', 'unite' => '°C',   'defaut' => 95,  'min' => 60, 'max' => 130],
    'seuil_carburant'        => ['label' => 'Seuil carburant bas',               'unite' => '%',    'defaut' => 10,  'min' => 1,  'max' => 50],
    'seuil_corridor_km'      => ['label' => 'Distance max. hors du corridor minier', 'unite' => 'km', 'defaut' => 15, 'min' => 1,  'max' => 100],
    'intervalle_simulation'  => ['label' => 'Intervalle entre deux cycles de simulation', 'unite' => 's', 'defaut' => 5, 'min' => 1, 'max' => 60],
];

// ====== ENREGISTREMENT DES PARAMÈTRES ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO configurations (parametre, valeur)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE valeur = ?
        ");

        foreach ($parametresDefinition as $cle => $def) {
            if (isset($_POST[$cle])) {
                $valeur = (float) $_POST[$cle];
                // On respecte les bornes définies pour éviter une config absurde
                $valeur = max($def['min'], min($def['max'], $valeur));
                $stmt->execute([$cle, $valeur, $valeur]);
            }
        }

        $message = 'Configuration enregistrée avec succès. Les nouveaux seuils seront appliqués au prochain cycle du simulateur.';
        $messageType = 'success';
    }
}

// ====== LECTURE DES VALEURS ACTUELLES ======
$configStmt = $pdo->query("SELECT parametre, valeur FROM configurations");
$configActuelle = [];
foreach ($configStmt->fetchAll() as $row) {
    $configActuelle[$row['parametre']] = $row['valeur'];
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Configuration';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Configuration</h1>
        <p class="subtitle">Seuils d'alerte utilisés par le simulateur IoT</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="panel" style="padding:24px;max-width:640px;">
    <h3 style="margin-bottom:18px;">Seuils de simulation et d'alerte</h3>
    <form method="POST">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <?php foreach ($parametresDefinition as $cle => $def): ?>
            <?php $valeurActuelle = $configActuelle[$cle] ?? $def['defaut']; ?>
            <div class="form-group">
                <label><?= htmlspecialchars($def['label']) ?> (<?= htmlspecialchars($def['unite']) ?>)</label>
                <input
                    type="number"
                    name="<?= $cle ?>"
                    value="<?= htmlspecialchars($valeurActuelle) ?>"
                    min="<?= $def['min'] ?>"
                    max="<?= $def['max'] ?>"
                    step="0.5"
                    required
                >
            </div>
        <?php endforeach; ?>

        <div style="margin-top:24px;">
            <button type="submit" class="btn-dash btn-dash-primary">Enregistrer la configuration</button>
        </div>
    </form>
</div>

<div class="panel" style="padding:16px 20px;margin-top:16px;background:#FAEEDA;color:#633806;border:none;max-width:640px;">
    &#9888; Ces paramètres pilotent en temps réel le moteur de simulation et le système de détection d'alertes.
    Toute modification est prise en compte automatiquement dès le prochain cycle, sans interruption de service.
</div>

<?php require_once '../includes/footer.php'; ?>