<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : superviseur/mes_conducteurs.php
// Rôle    : Liste en lecture seule des conducteurs assignés
//           au superviseur connecté (vue allégée, sans données RH sensibles)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();

$idSup = $_SESSION['user_id'];

// ====== RECHERCHE ======
$search = clean($_GET['q'] ?? '');

$sql = "
    SELECT id_conducteur, nom, prenom, photo, permis_numero, permis_expiration, telephone, statut
    FROM conducteurs
    WHERE id_superviseur = ?
";
$params = [$idSup];

if ($search) {
    $sql .= " AND (nom LIKE ? OR prenom LIKE ? OR permis_numero LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql .= " ORDER BY nom";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$conducteurs = $stmt->fetchAll();

$pageTitle = 'Mes conducteurs';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Mes conducteurs</h1>
        <p class="subtitle"><?= count($conducteurs) ?> conducteur(s) sous votre supervision</p>
    </div>
</div>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Liste de mes conducteurs</h3>
        <form method="GET" style="display:flex;gap:8px;">
            <input type="text" name="q" class="search-input" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn-dash btn-dash-outline" type="submit">Rechercher</button>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th></th>
                <th>Nom complet</th>
                <th>N° Permis</th>
                <th>Expiration permis</th>
                <th>Téléphone</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($conducteurs)): ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">Aucun conducteur ne vous est assigné pour le moment.</td></tr>
            <?php endif; ?>
            <?php foreach ($conducteurs as $c): ?>
                <?php
                    $joursRestants = (strtotime($c['permis_expiration']) - time()) / 86400;
                    $permisExpire = $joursRestants < 0;
                    $permisExpireProche = $joursRestants >= 0 && $joursRestants <= 30;
                ?>
                <tr>
                    <td>
                        <?php if ($c['photo']): ?>
                            <img src="../<?= htmlspecialchars($c['photo']) ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:32px;height:32px;border-radius:50%;background:#E1F5EE;color:#0F6E56;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;">
                                <?= strtoupper(substr($c['prenom'], 0, 1) . substr($c['nom'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="fiche_conducteur.php?id=<?= $c['id_conducteur'] ?>" style="color:#0F6E56;font-weight:600;text-decoration:underline;">
                            <?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($c['permis_numero']) ?></td>
                    <td>
                        <?= date('d/m/Y', strtotime($c['permis_expiration'])) ?>
                        <?php if ($permisExpire): ?>
                            <span class="badge badge-danger" style="margin-left:6px;">Expiré</span>
                        <?php elseif ($permisExpireProche): ?>
                            <span class="badge badge-warning" style="margin-left:6px;">Expire bientôt</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($c['telephone'] ?: '—') ?></td>
                    <td>
                        <span class="badge <?= $c['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>">
                            <?= ucfirst($c['statut']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>