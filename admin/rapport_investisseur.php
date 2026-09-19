<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/rapport_investisseur.php
// Rôle    : Génération rapport PDF investisseur par véhicule
//           + envoi email au superviseur assigné
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$message = '';
$messageType = '';

if ($_GET['statut'] ?? '' === 'email_ok') {
    $message = 'Rapport PDF généré et envoyé par email au superviseur avec succès.';
    $messageType = 'success';
} elseif ($_GET['statut'] ?? '' === 'email_erreur') {
    $message = 'Rapport PDF généré mais l\'envoi email a échoué. Vérifiez la configuration SMTP.';
    $messageType = 'error';
}

// ====== LISTE VÉHICULES INVESTISSEURS ======
$stmt = $pdo->query("
    SELECT v.*, u.nom AS sup_nom, u.prenom AS sup_prenom, u.email AS sup_email
    FROM vehicules v
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    WHERE v.proprietaire_nom IS NOT NULL AND v.proprietaire_nom != ''
    AND v.statut = 'actif'
    ORDER BY v.immatriculation
");
$vehiculesInvestisseurs = $stmt->fetchAll();

// ====== TRAITEMENT : GÉNÉRER ET ENVOYER ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generer') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée.';
        $messageType = 'error';
    } else {
        $idVehicule = (int) ($_POST['id_vehicule'] ?? 0);
        $dateDebut  = $_POST['date_debut'] ?? date('Y-m-01');
        $dateFin    = $_POST['date_fin']   ?? date('Y-m-d');
        $envoyerEmail = isset($_POST['envoyer_email']);

        // Récupérer le véhicule
        $stmt = $pdo->prepare("
            SELECT v.*, u.nom AS sup_nom, u.prenom AS sup_prenom, u.email AS sup_email
            FROM vehicules v
            LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
            WHERE v.id_vehicule = ?
        ");
        $stmt->execute([$idVehicule]);
        $vehicule = $stmt->fetch();

        if ($vehicule) {
            // Rediriger vers le PDF
            $params = http_build_query([
                'id_vehicule'  => $idVehicule,
                'date_debut'   => $dateDebut,
                'date_fin'     => $dateFin,
                'envoyer_email'=> $envoyerEmail ? '1' : '0',
            ]);
            header("Location: ../rapports/export_investisseur_pdf.php?$params");
            exit();
        } else {
            $message = 'Véhicule introuvable.';
            $messageType = 'error';
        }
    }
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Rapport investisseur';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#128100; Rapport investisseur</h1>
        <p class="subtitle">Générez un rapport PDF par véhicule et envoyez-le au superviseur</p>
    </div>
    <div class="dash-actions">
        <a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if (empty($vehiculesInvestisseurs)): ?>
    <div class="panel" style="padding:30px;text-align:center;color:#999;">
        <p style="font-size:18px;margin-bottom:8px;">&#128100;</p>
        <p>Aucun véhicule avec un investisseur associé.</p>
        <p style="font-size:12px;margin-top:8px;">Renseignez les informations investisseur dans la fiche véhicule.</p>
    </div>
<?php else: ?>

<!-- Formulaire de génération -->
<div class="panel" style="padding:24px;margin-bottom:16px;">
    <h3 style="margin-bottom:20px;">&#128196; Générer un rapport</h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="generer">

        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px;margin-bottom:20px;">
            <div class="form-group" style="margin:0;">
                <label>Véhicule investisseur</label>
                <select name="id_vehicule" id="selectVehicule" required onchange="mettreAJourInfo()">
                    <option value="">— Sélectionnez un véhicule —</option>
                    <?php foreach ($vehiculesInvestisseurs as $v): ?>
                        <option value="<?= $v['id_vehicule'] ?>"
                            data-proprietaire="<?= htmlspecialchars($v['proprietaire_nom']) ?>"
                            data-email="<?= htmlspecialchars($v['proprietaire_contact_email'] ?? '') ?>"
                            data-tel="<?= htmlspecialchars($v['proprietaire_contact_tel'] ?? '') ?>"
                            data-sup="<?= htmlspecialchars(($v['sup_prenom'] ?? '') . ' ' . ($v['sup_nom'] ?? '')) ?>"
                            data-sup-email="<?= htmlspecialchars($v['sup_email'] ?? '') ?>"
                            data-type="<?= $v['type'] ?>">
                            <?= htmlspecialchars($v['immatriculation']) ?>
                            — <?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?>
                            (<?= htmlspecialchars($v['proprietaire_nom']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label>Date début</label>
                <input type="date" name="date_debut" value="<?= date('Y-m-01') ?>" max="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group" style="margin:0;">
                <label>Date fin</label>
                <input type="date" name="date_fin" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <!-- Info investisseur dynamique -->
        <div id="infoInvestisseur" style="display:none;background:#F5F0FF;border-left:4px solid #7C5CBF;border-radius:6px;padding:16px;margin-bottom:20px;">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;font-size:13px;">
                <div>
                    <p style="color:#7C5CBF;font-size:11px;margin-bottom:4px;">Investisseur</p>
                    <p id="infoProprietaire" style="font-weight:600;"></p>
                </div>
                <div>
                    <p style="color:#7C5CBF;font-size:11px;margin-bottom:4px;">Email investisseur</p>
                    <p id="infoEmail" style="font-weight:600;"></p>
                </div>
                <div>
                    <p style="color:#7C5CBF;font-size:11px;margin-bottom:4px;">Tél. investisseur</p>
                    <p id="infoTel" style="font-weight:600;"></p>
                </div>
                <div>
                    <p style="color:#5C6B68;font-size:11px;margin-bottom:4px;">Superviseur assigné</p>
                    <p id="infoSup" style="font-weight:600;"></p>
                </div>
                <div>
                    <p style="color:#5C6B68;font-size:11px;margin-bottom:4px;">Email superviseur</p>
                    <p id="infoSupEmail" style="font-weight:600;"></p>
                </div>
            </div>
        </div>

        <!-- Option envoi email -->
        <div style="background:#F0F8F5;border-radius:8px;padding:16px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
            <input type="checkbox" name="envoyer_email" id="envoyerEmail" value="1" style="width:18px;height:18px;accent-color:#0F6E56;">
            <label for="envoyerEmail" style="font-size:14px;cursor:pointer;">
                &#128231; <strong>Envoyer par email au superviseur</strong>
                <span style="font-size:12px;color:#5C6B68;display:block;margin-top:2px;">Le rapport PDF sera joint à un email envoyé au superviseur assigné au véhicule</span>
            </label>
        </div>

        <div style="display:flex;gap:12px;">
            <button type="submit" class="btn-dash btn-dash-primary" style="padding:10px 24px;">
                &#128196; Générer le rapport PDF
            </button>
        </div>
    </form>
</div>

<!-- Liste des véhicules investisseurs -->
<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>&#128100; Véhicules sous investisseur (<?= count($vehiculesInvestisseurs) ?>)</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Type</th>
                <th>Investisseur</th>
                <th>Email investisseur</th>
                <th>Téléphone</th>
                <th>Superviseur</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vehiculesInvestisseurs as $v): ?>
                <tr>
                    <td>
                        <a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;">
                            <?= htmlspecialchars($v['immatriculation']) ?>
                        </a>
                        <br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?></span>
                    </td>
                    <td><?= ucfirst($v['type']) ?></td>
                    <td style="color:#7C5CBF;font-weight:600;"><?= htmlspecialchars($v['proprietaire_nom']) ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($v['proprietaire_contact_email'] ?? '—') ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($v['proprietaire_contact_tel'] ?? '—') ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars(($v['sup_prenom'] ?? '') . ' ' . ($v['sup_nom'] ?? '')) ?></td>
                    <td>
                        <a href="../rapports/export_investisseur_pdf.php?id_vehicule=<?= $v['id_vehicule'] ?>&date_debut=<?= date('Y-m-01') ?>&date_fin=<?= date('Y-m-d') ?>&envoyer_email=0"
                           class="btn-dash btn-dash-outline" style="font-size:12px;padding:4px 10px;">
                            &#128196; PDF
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
function mettreAJourInfo() {
    var sel = document.getElementById('selectVehicule');
    var opt = sel.options[sel.selectedIndex];
    var div = document.getElementById('infoInvestisseur');

    if (!sel.value) { div.style.display = 'none'; return; }

    document.getElementById('infoProprietaire').textContent = opt.dataset.proprietaire || '—';
    document.getElementById('infoEmail').textContent        = opt.dataset.email        || '—';
    document.getElementById('infoTel').textContent          = opt.dataset.tel          || '—';
    document.getElementById('infoSup').textContent          = opt.dataset.sup          || '—';
    document.getElementById('infoSupEmail').textContent     = opt.dataset.supEmail     || '—';
    div.style.display = 'block';
}
</script>

<?php require_once '../includes/footer.php'; ?>
