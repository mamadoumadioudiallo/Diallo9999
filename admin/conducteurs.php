<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/conducteurs.php
// Rôle    : Gestion CRUD des conducteurs (chauffeurs)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$message = '';
$messageType = '';

// ====== AJOUT D'UN CONDUCTEUR ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $nom = clean($_POST['nom']);
        $prenom = clean($_POST['prenom']);
        $cin = clean($_POST['cin']);
        $permisNumero = clean($_POST['permis_numero']);
        $permisExpiration = $_POST['permis_expiration'];
        $telephone = clean($_POST['telephone'] ?? '');
        $idSuperviseur = !empty($_POST['id_superviseur']) ? (int) $_POST['id_superviseur'] : null;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO conducteurs (nom, prenom, cin, permis_numero, permis_expiration, telephone, id_superviseur)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nom, $prenom, $cin, $permisNumero, $permisExpiration, $telephone, $idSuperviseur]);
            $message = 'Conducteur ' . $prenom . ' ' . $nom . ' ajouté avec succès.';
            $messageType = 'success';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = 'Ce numéro de CIN est déjà enregistré dans le système.';
            } else {
                $message = 'Erreur lors de l\'ajout du conducteur.';
            }
            $messageType = 'error';
        }
    }
}

// ====== MODIFICATION D'UN CONDUCTEUR ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {

    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $id = (int) $_POST['id_conducteur'];
        $nom = clean($_POST['nom']);
        $prenom = clean($_POST['prenom']);
        $permisNumero = clean($_POST['permis_numero']);
        $permisExpiration = $_POST['permis_expiration'];
        $telephone = clean($_POST['telephone'] ?? '');
        $idSuperviseur = !empty($_POST['id_superviseur']) ? (int) $_POST['id_superviseur'] : null;

        $stmt = $pdo->prepare("
            UPDATE conducteurs SET nom = ?, prenom = ?, permis_numero = ?, permis_expiration = ?, telephone = ?, id_superviseur = ?
            WHERE id_conducteur = ?
        ");
        $stmt->execute([$nom, $prenom, $permisNumero, $permisExpiration, $telephone, $idSuperviseur, $id]);
        $message = 'Conducteur mis à jour avec succès.';
        $messageType = 'success';
    }
}

// ====== DÉSACTIVATION / RÉACTIVATION ======
if (isset($_GET['deactivate']) && is_numeric($_GET['deactivate'])) {
    $stmt = $pdo->prepare("UPDATE conducteurs SET statut = 'inactif' WHERE id_conducteur = ?");
    $stmt->execute([$_GET['deactivate']]);
    $message = 'Conducteur désactivé.';
    $messageType = 'success';
}
if (isset($_GET['activate']) && is_numeric($_GET['activate'])) {
    $stmt = $pdo->prepare("UPDATE conducteurs SET statut = 'actif' WHERE id_conducteur = ?");
    $stmt->execute([$_GET['activate']]);
    $message = 'Conducteur réactivé.';
    $messageType = 'success';
}

// ====== LISTE DES SUPERVISEURS (pour le select) ======
$superviseurs = $pdo->query("SELECT id_user, nom, prenom FROM utilisateurs WHERE role = 'superviseur' AND statut = 'actif' ORDER BY nom")->fetchAll();

// ====== LISTE DES CONDUCTEURS ======
$search = clean($_GET['q'] ?? '');
$sql = "
    SELECT c.*, u.nom AS sup_nom, u.prenom AS sup_prenom
    FROM conducteurs c
    LEFT JOIN utilisateurs u ON c.id_superviseur = u.id_user
";
if ($search) {
    $sql .= " WHERE c.nom LIKE ? OR c.prenom LIKE ? OR c.cin LIKE ? OR c.permis_numero LIKE ?";
}
$sql .= " ORDER BY c.id_conducteur DESC";

$stmt = $pdo->prepare($sql);
if ($search) {
    $like = "%$search%";
    $stmt->execute([$like, $like, $like, $like]);
} else {
    $stmt->execute();
}
$conducteurs = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
$pageTitle = 'Gestion des conducteurs';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Gestion des conducteurs</h1>
        <p class="subtitle"><?= count($conducteurs) ?> conducteur(s) enregistré(s)</p>
    </div>
    <div class="dash-actions">
        <button class="btn-dash btn-dash-primary" onclick="document.getElementById('modalAdd').style.display='flex'">
            + Ajouter un conducteur
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Liste des conducteurs</h3>
        <form method="GET" style="display:flex;gap:8px;">
            <input type="text" name="q" class="search-input" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn-dash btn-dash-outline" type="submit">Rechercher</button>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>Nom complet</th>
                <th>CIN</th>
                <th>N° Permis</th>
                <th>Expiration permis</th>
                <th>Téléphone</th>
                <th>Superviseur</th>
                <th>Statut</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($conducteurs)): ?>
                <tr><td colspan="8" style="text-align:center;padding:30px;color:#999;">Aucun conducteur enregistré. Cliquez sur "Ajouter un conducteur" pour commencer.</td></tr>
            <?php endif; ?>
            <?php foreach ($conducteurs as $c): ?>
                <?php
                    $joursRestants = (strtotime($c['permis_expiration']) - time()) / 86400;
                    $permisExpireProche = $joursRestants <= 30;
                    $permisExpire = $joursRestants < 0;
                ?>
                <tr>
                    <td><a href="fiche_conducteur.php?id=<?= $c['id_conducteur'] ?>" style="color:#0F6E56;font-weight:600;text-decoration:underline;"><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></a></td>
                    <td><?= htmlspecialchars($c['cin']) ?></td>
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
                    <td><?= $c['sup_nom'] ? htmlspecialchars($c['sup_prenom'] . ' ' . $c['sup_nom']) : '<span style="color:#999;">Non assigné</span>' ?></td>
                    <td>
                        <span class="badge <?= $c['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>">
                            <?= ucfirst($c['statut']) ?>
                        </span>
                    </td>
                    <td style="display:flex;gap:10px;">
                        <a href="#" style="color:#0F6E56;font-size:12px;"
                           onclick="openEditModal(<?= htmlspecialchars(json_encode($c)) ?>); return false;">Modifier</a>
                        <?php if ($c['statut'] === 'actif'): ?>
                            <a href="?deactivate=<?= $c['id_conducteur'] ?>" style="color:#E24B4A;font-size:12px;" onclick="return confirm('Désactiver ce conducteur ?')">Désactiver</a>
                        <?php else: ?>
                            <a href="?activate=<?= $c['id_conducteur'] ?>" style="color:#1D9E75;font-size:12px;">Réactiver</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== MODAL AJOUT CONDUCTEUR ====== -->
<div id="modalAdd" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:18px;font-size:17px;">Ajouter un conducteur</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="contact-grid">
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom" required placeholder="Camara">
                </div>
                <div class="form-group">
                    <label>Prénom *</label>
                    <input type="text" name="prenom" required placeholder="Mamadou">
                </div>
            </div>

            <div class="form-group">
                <label>Numéro CIN *</label>
                <input type="text" name="cin" required placeholder="GN-2026-001234">
            </div>

            <div class="contact-grid">
                <div class="form-group">
                    <label>N° Permis de conduire *</label>
                    <input type="text" name="permis_numero" required placeholder="PC-45678">
                </div>
                <div class="form-group">
                    <label>Date d'expiration du permis *</label>
                    <input type="date" name="permis_expiration" required>
                </div>
            </div>

            <div class="form-group">
                <label>Téléphone</label>
                <input type="text" name="telephone" placeholder="+224 6XX XX XX XX">
            </div>

            <div class="form-group">
                <label>Superviseur assigné</label>
                <select name="id_superviseur">
                    <option value="">— Aucun pour le moment —</option>
                    <?php foreach ($superviseurs as $s): ?>
                        <option value="<?= $s['id_user'] ?>"><?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;" onclick="document.getElementById('modalAdd').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Ajouter</button>
            </div>
        </form>
    </div>
</div>

<!-- ====== MODAL MODIFICATION CONDUCTEUR ====== -->
<div id="modalEdit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:18px;font-size:17px;">Modifier le conducteur</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id_conducteur" id="edit_id">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="contact-grid">
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom" id="edit_nom" required>
                </div>
                <div class="form-group">
                    <label>Prénom *</label>
                    <input type="text" name="prenom" id="edit_prenom" required>
                </div>
            </div>

            <div class="form-group">
                <label>CIN (non modifiable)</label>
                <input type="text" id="edit_cin" disabled style="background:#f5f5f5;">
            </div>

            <div class="contact-grid">
                <div class="form-group">
                    <label>N° Permis de conduire *</label>
                    <input type="text" name="permis_numero" id="edit_permis_numero" required>
                </div>
                <div class="form-group">
                    <label>Date d'expiration du permis *</label>
                    <input type="date" name="permis_expiration" id="edit_permis_expiration" required>
                </div>
            </div>

            <div class="form-group">
                <label>Téléphone</label>
                <input type="text" name="telephone" id="edit_telephone">
            </div>

            <div class="form-group">
                <label>Superviseur assigné</label>
                <select name="id_superviseur" id="edit_id_superviseur">
                    <option value="">— Aucun pour le moment —</option>
                    <?php foreach ($superviseurs as $s): ?>
                        <option value="<?= $s['id_user'] ?>"><?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;" onclick="document.getElementById('modalEdit').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(conducteur) {
    document.getElementById('edit_id').value = conducteur.id_conducteur;
    document.getElementById('edit_nom').value = conducteur.nom;
    document.getElementById('edit_prenom').value = conducteur.prenom;
    document.getElementById('edit_cin').value = conducteur.cin;
    document.getElementById('edit_permis_numero').value = conducteur.permis_numero;
    document.getElementById('edit_permis_expiration').value = conducteur.permis_expiration;
    document.getElementById('edit_telephone').value = conducteur.telephone || '';
    document.getElementById('edit_id_superviseur').value = conducteur.id_superviseur || '';
    document.getElementById('modalEdit').style.display = 'flex';
}
</script>

<?php require_once '../includes/footer.php'; ?>