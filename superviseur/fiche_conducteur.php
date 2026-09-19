<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : superviseur/fiche_conducteur.php
// Rôle    : Fiche conducteur ALLÉGÉE pour le superviseur
//           (photo, statut permis, missions effectuées sur SES véhicules,
//           certifications de sécurité — consultables ET ajoutables)
//           Pas d'accès aux données RH sensibles (CIN, adresse, contact
//           d'urgence, visite médicale, groupe sanguin...) — réservées à l'admin.
//           La suppression d'une certification reste réservée à l'admin.
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();

$idSup = $_SESSION['user_id'];
$idConducteur = (int) ($_GET['id'] ?? 0);
$message = '';
$messageType = '';

// ====== VÉRIFICATION D'ACCÈS ======
// Un superviseur peut consulter un conducteur si celui-ci lui est directement
// assigné, OU s'il a déjà effectué une mission sur l'un de ses véhicules.
$checkStmt = $pdo->prepare("
    SELECT COUNT(*) FROM conducteurs c
    WHERE c.id_conducteur = ? AND (
        c.id_superviseur = ?
        OR EXISTS (
            SELECT 1 FROM missions m
            JOIN vehicules v ON m.id_vehicule = v.id_vehicule
            WHERE m.id_conducteur = c.id_conducteur AND v.id_superviseur = ?
        )
    )
");
$checkStmt->execute([$idConducteur, $idSup, $idSup]);

if ($checkStmt->fetchColumn() == 0) {
    header('Location: mes_missions.php');
    exit();
}

// ====== CONFIGURATION UPLOAD (certificat) ======
$dossierUpload = '../assets/uploads/conducteurs/';
$extensionsDocAutorisees = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
$tailleMaxOctets = 5 * 1024 * 1024; // 5 Mo

// ====== AJOUT D'UNE CERTIFICATION (le superviseur peut ajouter, pas supprimer) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_certification') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $nomCertif = clean($_POST['nom_certification']);
        $organisme = clean($_POST['organisme'] ?? '');
        $dateObtention = $_POST['date_obtention'];
        $dateExpiration = $_POST['date_expiration_certif'] ?: null;

        $resFichier = uploaderFichier('fichier_certif', 'certif', $idConducteur, $extensionsDocAutorisees, $dossierUpload, $tailleMaxOctets);

        if (!$resFichier['success']) {
            $message = $resFichier['erreur'];
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO certifications_conducteur (id_conducteur, nom_certification, organisme, date_obtention, date_expiration, fichier_certif)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$idConducteur, $nomCertif, $organisme, $dateObtention, $dateExpiration, $resFichier['chemin']]);
            $message = 'Certification ajoutée avec succès.';
            $messageType = 'success';
        }
    }
}

// ====== INFOS DU CONDUCTEUR (champs limités, pas de données RH sensibles) ======
$stmt = $pdo->prepare("
    SELECT id_conducteur, nom, prenom, photo, permis_numero, permis_expiration, statut
    FROM conducteurs WHERE id_conducteur = ?
");
$stmt->execute([$idConducteur]);
$conducteur = $stmt->fetch();

if (!$conducteur) {
    header('Location: mes_missions.php');
    exit();
}

// ====== MISSIONS EFFECTUÉES PAR CE CONDUCTEUR, MAIS UNIQUEMENT SUR MES VÉHICULES ======
$stmt = $pdo->prepare("
    SELECT m.*, v.immatriculation, v.type AS type_vehicule
    FROM missions m
    JOIN vehicules v ON m.id_vehicule = v.id_vehicule
    WHERE m.id_conducteur = ? AND v.id_superviseur = ?
    ORDER BY m.date_debut DESC
    LIMIT 20
");
$stmt->execute([$idConducteur, $idSup]);
$missions = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM missions m
    JOIN vehicules v ON m.id_vehicule = v.id_vehicule
    WHERE m.id_conducteur = ? AND v.id_superviseur = ?
");
$stmt->execute([$idConducteur, $idSup]);
$nbMissionsTotal = $stmt->fetchColumn();

// ====== CERTIFICATIONS DE SÉCURITÉ ======
$stmt = $pdo->prepare("SELECT * FROM certifications_conducteur WHERE id_conducteur = ? ORDER BY date_obtention DESC");
$stmt->execute([$idConducteur]);
$certifications = $stmt->fetchAll();

// ====== STATUT DU PERMIS ======
$joursRestantsPermis = (strtotime($conducteur['permis_expiration']) - time()) / 86400;
$permisExpire = $joursRestantsPermis < 0;
$permisExpireProche = $joursRestantsPermis >= 0 && $joursRestantsPermis <= 30;

$csrfToken = generateCsrfToken();
$pageTitle = 'Conducteur — ' . $conducteur['prenom'] . ' ' . $conducteur['nom'];
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#128100; <?= htmlspecialchars($conducteur['prenom'] . ' ' . $conducteur['nom']) ?></h1>
        <p class="subtitle">Fiche conducteur — vue allégée</p>
    </div>
    <div class="dash-actions">
        <a href="mes_missions.php" class="btn-dash btn-dash-outline">&#8592; Retour aux missions</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($permisExpire || $permisExpireProche): ?>
    <div class="panel" style="padding:14px 16px;margin-bottom:14px;background:#FCEBEB;color:#A32D2D;border:none;">
        &#9888; <?= $permisExpire ? 'Le permis de conduire de ce conducteur est expiré.' : 'Le permis de conduire de ce conducteur expire dans moins de 30 jours.' ?>
        Vérifiez sa situation avant de lui confier une nouvelle mission.
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:240px 1fr;gap:20px;margin-bottom:16px;">

    <!-- ====== COLONNE PHOTO ====== -->
    <div class="panel" style="padding:20px;text-align:center;">
        <?php if ($conducteur['photo']): ?>
            <img src="../<?= htmlspecialchars($conducteur['photo']) ?>" alt="Photo" style="width:140px;height:140px;border-radius:50%;object-fit:cover;border:3px solid var(--color-border);margin:0 auto 14px;">
        <?php else: ?>
            <div style="width:140px;height:140px;border-radius:50%;background:#E1F5EE;color:#0F6E56;display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:700;margin:0 auto 14px;">
                <?= strtoupper(substr($conducteur['prenom'], 0, 1) . substr($conducteur['nom'], 0, 1)) ?>
            </div>
        <?php endif; ?>

        <h3 style="margin-bottom:4px;"><?= htmlspecialchars($conducteur['prenom'] . ' ' . $conducteur['nom']) ?></h3>
        <span class="badge <?= $conducteur['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>"><?= ucfirst($conducteur['statut']) ?></span>
    </div>

    <!-- ====== COLONNE STATUT PERMIS + STATS ====== -->
    <div class="panel" style="padding:24px;">
        <h3 style="margin-bottom:18px;">Statut du permis de conduire</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">N° Permis</p>
                <p style="font-weight:600;"><?= htmlspecialchars($conducteur['permis_numero']) ?></p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Date d'expiration</p>
                <p style="font-weight:600;color:<?= $permisExpire ? '#E24B4A' : ($permisExpireProche ? '#BA7517' : '#1A1A1A') ?>;">
                    <?= date('d/m/Y', strtotime($conducteur['permis_expiration'])) ?>
                    <?php if ($permisExpire): ?>
                        <span class="badge badge-danger" style="margin-left:6px;">Expiré</span>
                    <?php elseif ($permisExpireProche): ?>
                        <span class="badge badge-warning" style="margin-left:6px;">Expire bientôt</span>
                    <?php else: ?>
                        <span class="badge badge-success" style="margin-left:6px;">Valide</span>
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Missions effectuées (sur vos véhicules)</p>
                <p style="font-weight:600;"><?= $nbMissionsTotal ?></p>
            </div>
        </div>

        <div class="panel" style="padding:12px 16px;margin-top:18px;background:#F5F7F6;border:none;">
            <p style="font-size:12px;color:#5C6B68;">
                &#128274; Les informations personnelles (CIN, adresse, contact d'urgence, dossier médical...)
                ne sont accessibles qu'à l'administration, conformément à la politique de confidentialité des données RH.
            </p>
        </div>
    </div>
</div>

<!-- ====== CERTIFICATIONS DE SÉCURITÉ ====== -->
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#127891; Certifications de sécurité</h3>
        <button class="btn-dash btn-dash-outline" onclick="document.getElementById('modalAddCertif').style.display='flex'">+ Ajouter</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Certification</th>
                <th>Organisme</th>
                <th>Date d'obtention</th>
                <th>Expiration</th>
                <th>Document</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($certifications)): ?>
                <tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">Aucune certification enregistrée.</td></tr>
            <?php endif; ?>
            <?php foreach ($certifications as $cert): ?>
                <?php $certifExpiree = $cert['date_expiration'] && strtotime($cert['date_expiration']) < time(); ?>
                <tr>
                    <td><?= htmlspecialchars($cert['nom_certification']) ?></td>
                    <td><?= htmlspecialchars($cert['organisme'] ?: '—') ?></td>
                    <td><?= date('d/m/Y', strtotime($cert['date_obtention'])) ?></td>
                    <td>
                        <?php if ($cert['date_expiration']): ?>
                            <?= date('d/m/Y', strtotime($cert['date_expiration'])) ?>
                        <?php else: ?>
                            Sans expiration
                        <?php endif; ?>
                        <?php if ($certifExpiree): ?>
                            <span class="badge badge-danger" style="margin-left:6px;">Expirée</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($cert['fichier_certif']): ?>
                            <a href="../<?= htmlspecialchars($cert['fichier_certif']) ?>" target="_blank" style="color:#0F6E56;">&#128196; Voir</a>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== HISTORIQUE DES MISSIONS (sur mes véhicules uniquement) ====== -->
<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>&#128203; Missions effectuées sur vos véhicules</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Départ</th>
                <th>Destination</th>
                <th>Date début</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($missions)): ?>
                <tr><td colspan="5" style="text-align:center;padding:24px;color:#999;">Aucune mission enregistrée.</td></tr>
            <?php endif; ?>
            <?php foreach ($missions as $m): ?>
                <?php
                    $statutBadge = ['planifiee' => 'badge-warning', 'en_cours' => 'badge-success', 'terminee' => 'badge-success', 'annulee' => 'badge-danger'];
                    $statutLabel = ['planifiee' => 'Planifiée', 'en_cours' => 'En cours', 'terminee' => 'Terminée', 'annulee' => 'Annulée'];
                ?>
                <tr>
                    <td>
                        <a href="fiche_vehicule.php?id=<?= $m['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;">
                            <?= htmlspecialchars($m['immatriculation']) ?>
                        </a>
                        <span style="color:#999;font-size:11px;">(<?= ucfirst($m['type_vehicule']) ?>)</span>
                    </td>
                    <td><?= htmlspecialchars($m['lieu_depart']) ?></td>
                    <td><?= htmlspecialchars($m['lieu_destination']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($m['date_debut'])) ?></td>
                    <td><span class="badge <?= $statutBadge[$m['statut']] ?>"><?= $statutLabel[$m['statut']] ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== MODAL AJOUT CERTIFICATION ====== -->
<div id="modalAddCertif" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:18px;font-size:17px;">Ajouter une certification de sécurité</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_certification">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-group">
                <label>Nom de la certification *</label>
                <input type="text" name="nom_certification" required placeholder="Conduite défensive, Premiers secours...">
            </div>

            <div class="form-group">
                <label>Organisme délivrant</label>
                <input type="text" name="organisme" placeholder="Simandou Academy, Croix-Rouge...">
            </div>

            <div class="contact-grid">
                <div class="form-group">
                    <label>Date d'obtention *</label>
                    <input type="date" name="date_obtention" required>
                </div>
                <div class="form-group">
                    <label>Date d'expiration (si applicable)</label>
                    <input type="date" name="date_expiration_certif">
                </div>
            </div>

            <div class="form-group">
                <label>Document du certificat (image ou PDF, max 5 Mo)</label>
                <input type="file" name="fichier_certif" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;" onclick="document.getElementById('modalAddCertif').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Ajouter</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>