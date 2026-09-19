<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/fiche_superviseur.php
// Rôle    : Fiche détaillée d'un superviseur — véhicules et
//           conducteurs sous sa responsabilité, statistiques
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$idSuperviseur = (int) ($_GET['id'] ?? 0);
$message = '';
$messageType = '';

// ====== UPLOAD DE LA PHOTO ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $dossierUpload = '../assets/uploads/conducteurs/'; // dossier partagé déjà sécurisé pour les photos de profil
        $extensionsImage = ['jpg', 'jpeg', 'png', 'webp'];
        $tailleMax = 5 * 1024 * 1024;

        $res = uploaderFichier('photo', 'photo_sup', $idSuperviseur, $extensionsImage, $dossierUpload, $tailleMax);

        if (!$res['success']) {
            $message = $res['erreur'];
            $messageType = 'error';
        } elseif ($res['chemin']) {
            $stmt = $pdo->prepare("UPDATE utilisateurs SET photo = ? WHERE id_user = ?");
            $stmt->execute([$res['chemin'], $idSuperviseur]);
            $message = 'Photo mise à jour avec succès.';
            $messageType = 'success';
        }
    }
}

// ====== RÉCUPÉRATION DU SUPERVISEUR ======
$stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id_user = ? AND role = 'superviseur'");
$stmt->execute([$idSuperviseur]);
$superviseur = $stmt->fetch();

if (!$superviseur) {
    header('Location: superviseurs.php');
    exit();
}

// ====== VÉHICULES ASSIGNÉS ======
$stmt = $pdo->prepare("
    SELECT id_vehicule, immatriculation, marque, modele, type, statut
    FROM vehicules WHERE id_superviseur = ?
    ORDER BY immatriculation
");
$stmt->execute([$idSuperviseur]);
$vehicules = $stmt->fetchAll();

// ====== CONDUCTEURS ASSIGNÉS ======
$stmt = $pdo->prepare("
    SELECT id_conducteur, nom, prenom, telephone, permis_expiration, statut
    FROM conducteurs WHERE id_superviseur = ?
    ORDER BY nom
");
$stmt->execute([$idSuperviseur]);
$conducteurs = $stmt->fetchAll();

// ====== STATISTIQUES ======
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM missions m
    JOIN vehicules v ON m.id_vehicule = v.id_vehicule
    WHERE v.id_superviseur = ?
");
$stmt->execute([$idSuperviseur]);
$nbMissionsTotal = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM missions m
    JOIN vehicules v ON m.id_vehicule = v.id_vehicule
    WHERE v.id_superviseur = ? AND m.statut = 'en_cours'
");
$stmt->execute([$idSuperviseur]);
$nbMissionsEnCours = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    WHERE v.id_superviseur = ? AND a.statut != 'resolue'
");
$stmt->execute([$idSuperviseur]);
$nbAlertesActives = $stmt->fetchColumn();

$pageTitle = 'Fiche superviseur — ' . $superviseur['prenom'] . ' ' . $superviseur['nom'];
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1><?= htmlspecialchars($superviseur['prenom'] . ' ' . $superviseur['nom']) ?></h1>
        <p class="subtitle">Fiche superviseur</p>
    </div>
    <div class="dash-actions">
        <a href="superviseurs.php" class="btn-dash btn-dash-outline">&#8592; Retour à la liste</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:240px 1fr;gap:20px;margin-bottom:16px;">

    <!-- ====== COLONNE PHOTO ====== -->
    <div class="panel" style="padding:24px;text-align:center;">
        <?php if (!empty($superviseur['photo'])): ?>
            <img src="../<?= htmlspecialchars($superviseur['photo']) ?>" alt="Photo" style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:3px solid var(--color-border);margin:0 auto 16px;">
        <?php else: ?>
            <div style="width:120px;height:120px;border-radius:50%;background:#E1F5EE;color:#0F6E56;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;margin:0 auto 16px;">
                <?= strtoupper(substr($superviseur['prenom'], 0, 1) . substr($superviseur['nom'], 0, 1)) ?>
            </div>
        <?php endif; ?>
        <h3 style="margin-bottom:6px;font-size:16px;"><?= htmlspecialchars($superviseur['prenom'] . ' ' . $superviseur['nom']) ?></h3>
        <span class="badge <?= $superviseur['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>"><?= ucfirst($superviseur['statut']) ?></span>
        <p style="font-size:12px;color:#999;margin-top:14px;word-break:break-word;"><?= htmlspecialchars($superviseur['email']) ?></p>
        <p style="font-size:12px;color:#999;"><?= htmlspecialchars($superviseur['telephone'] ?: 'Téléphone non renseigné') ?></p>

        <a href="#" onclick="document.getElementById('modalPhoto').style.display='flex'; return false;" style="display:inline-block;margin-top:14px;color:#0F6E56;font-size:12px;font-weight:600;text-decoration:none;">
            &#128247; <?= !empty($superviseur['photo']) ? 'Changer la photo' : 'Ajouter une photo' ?>
        </a>
    </div>

    <!-- ====== COLONNE STATS ====== -->
    <div class="panel" style="padding:24px;">
        <h3 style="margin-bottom:18px;">Vue d'ensemble</h3>
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-info">
                    <p class="label">Véhicules assignés</p>
                    <p class="value"><?= count($vehicules) ?></p>
                </div>
                <div class="kpi-icon">&#128666;</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-info">
                    <p class="label">Conducteurs assignés</p>
                    <p class="value"><?= count($conducteurs) ?></p>
                </div>
                <div class="kpi-icon">&#128100;</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-info">
                    <p class="label">Missions (total / en cours)</p>
                    <p class="value"><?= $nbMissionsTotal ?> / <?= $nbMissionsEnCours ?></p>
                </div>
                <div class="kpi-icon">&#128203;</div>
            </div>
            <div class="kpi-card <?= $nbAlertesActives > 0 ? 'danger' : '' ?>">
                <div class="kpi-info">
                    <p class="label">Alertes actives</p>
                    <p class="value"><?= $nbAlertesActives ?></p>
                </div>
                <div class="kpi-icon">&#128276;</div>
            </div>
        </div>
    </div>
</div>

<!-- ====== VÉHICULES ASSIGNÉS ====== -->
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#128666; Véhicules sous sa responsabilité</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Immatriculation</th>
                <th>Marque / Modèle</th>
                <th>Type</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vehicules)): ?>
                <tr><td colspan="4" style="text-align:center;padding:24px;color:#999;">Aucun véhicule assigné à ce superviseur.</td></tr>
            <?php endif; ?>
            <?php foreach ($vehicules as $v): ?>
                <tr>
                    <td><a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;"><?= htmlspecialchars($v['immatriculation']) ?></a></td>
                    <td><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?></td>
                    <td><?= ucfirst($v['type']) ?></td>
                    <td><span class="badge <?= $v['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>"><?= ucfirst($v['statut']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== CONDUCTEURS ASSIGNÉS ====== -->
<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>&#128100; Conducteurs sous sa responsabilité</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Nom complet</th>
                <th>Téléphone</th>
                <th>Expiration permis</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($conducteurs)): ?>
                <tr><td colspan="4" style="text-align:center;padding:24px;color:#999;">Aucun conducteur assigné à ce superviseur.</td></tr>
            <?php endif; ?>
            <?php foreach ($conducteurs as $c): ?>
                <?php
                    $permisExpire = strtotime($c['permis_expiration']) < time();
                ?>
                <tr>
                    <td><a href="fiche_conducteur.php?id=<?= $c['id_conducteur'] ?>" style="color:#0F6E56;"><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></a></td>
                    <td><?= htmlspecialchars($c['telephone'] ?: '—') ?></td>
                    <td style="color:<?= $permisExpire ? '#E24B4A' : '#1A1A1A' ?>;"><?= date('d/m/Y', strtotime($c['permis_expiration'])) ?></td>
                    <td><span class="badge <?= $c['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>"><?= ucfirst($c['statut']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== MODAL UPLOAD PHOTO ====== -->
<div id="modalPhoto" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:420px;">
        <h3 style="margin-bottom:18px;font-size:17px;">Photo du superviseur</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_photo">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">

            <div class="form-group">
                <label>Photo (JPG, PNG ou WEBP, max 5 Mo)</label>
                <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" required>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;" onclick="document.getElementById('modalPhoto').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>