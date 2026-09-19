<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/journal_audit.php
// Rôle    : Consultation du journal d'audit (traçabilité des
//           actions sensibles : connexions, création de comptes,
//           changements de mot de passe, clés API...)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$filtreAction = $_GET['action'] ?? '';

$sql = "
    SELECT j.*, u.nom, u.prenom
    FROM journal_audit j
    LEFT JOIN utilisateurs u ON j.id_user = u.id_user
";
$params = [];

if ($filtreAction) {
    $sql .= " WHERE j.action = ?";
    $params[] = $filtreAction;
}
$sql .= " ORDER BY j.horodatage DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actionsDisponibles = $pdo->query("SELECT DISTINCT action FROM journal_audit ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

$labelsAction = [
    'connexion_reussie' => 'Connexion réussie',
    'connexion_echouee' => 'Connexion échouée',
    'generation_cle_api' => 'Génération clé API',
];

$pageTitle = 'Journal d\'audit';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Journal d'audit</h1>
        <p class="subtitle">Traçabilité des actions sensibles (200 dernières entrées)</p>
    </div>
</div>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Historique</h3>
        <form method="GET" style="display:flex;gap:8px;">
            <select name="action" class="search-input" onchange="this.form.submit()" style="width:220px;">
                <option value="">Toutes les actions</option>
                <?php foreach ($actionsDisponibles as $a): ?>
                    <option value="<?= htmlspecialchars($a) ?>" <?= $filtreAction === $a ? 'selected' : '' ?>>
                        <?= htmlspecialchars($labelsAction[$a] ?? $a) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Utilisateur</th>
                <th>Action</th>
                <th>Détails</th>
                <th>Adresse IP</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">Aucune entrée dans le journal pour le moment.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= date('d/m/Y H:i:s', strtotime($log['horodatage'])) ?></td>
                    <td><?= $log['nom'] ? htmlspecialchars($log['prenom'] . ' ' . $log['nom']) : '<span style="color:#999;">Anonyme</span>' ?></td>
                    <td>
                        <?php
                            $estEchec = strpos($log['action'], 'echouee') !== false;
                        ?>
                        <span class="badge <?= $estEchec ? 'badge-danger' : 'badge-success' ?>">
                            <?= htmlspecialchars($labelsAction[$log['action']] ?? $log['action']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($log['details'] ?: '—') ?></td>
                    <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($log['adresse_ip']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>