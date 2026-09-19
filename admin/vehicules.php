<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/vehicules.php
// Rôle    : Gestion CRUD des véhicules de la flotte
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$message = '';
$messageType = '';

// ====== AJOUT D'UN VÉHICULE ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $immatriculation  = clean($_POST['immatriculation']);
        $marque           = clean($_POST['marque']);
        $modele           = clean($_POST['modele']);
        $annee            = (int) $_POST['annee'];
        $type             = $_POST['type'] === 'minier' ? 'minier' : 'routier';
        $capacite         = (float) $_POST['capacite_carburant'];
        $idSuperviseur    = !empty($_POST['id_superviseur']) ? (int) $_POST['id_superviseur'] : null;
        $nbRoues          = $type === 'minier' ? (int) ($_POST['nb_roues'] ?? 6) : 4;

        // Champs minier uniquement
        $poidsMaxKg  = ($type === 'minier' && !empty($_POST['poids_max_kg']))  ? (int) $_POST['poids_max_kg']  : null;
        $poidsTareKg = ($type === 'minier' && !empty($_POST['poids_tare_kg'])) ? (int) $_POST['poids_tare_kg'] : null;

        // Champs investisseur (si appartenance = investisseur)
        $appartenance         = $_POST['appartenance'] ?? 'entreprise';
        $proprietaireNom      = null;
        $proprietaireEmail    = null;
        $proprietaireTel      = null;

        if ($appartenance === 'investisseur') {
            $proprietaireNom   = clean($_POST['proprietaire_nom'] ?? '');
            $proprietaireEmail = clean($_POST['proprietaire_contact_email'] ?? '');
            $proprietaireTel   = clean($_POST['proprietaire_contact_tel'] ?? '');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO vehicules
                    (immatriculation, marque, modele, annee, type, capacite_carburant,
                     id_superviseur, id_createur, nb_roues,
                     poids_max_kg, poids_tare_kg,
                     proprietaire_nom, proprietaire_contact_email, proprietaire_contact_tel)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $immatriculation, $marque, $modele, $annee, $type, $capacite,
                $idSuperviseur, $_SESSION['user_id'], $nbRoues,
                $poidsMaxKg, $poidsTareKg,
                $proprietaireNom, $proprietaireEmail, $proprietaireTel,
            ]);
            $message = 'Véhicule ' . $immatriculation . ' ajouté avec succès.';
            $messageType = 'success';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = 'Cette immatriculation est déjà enregistrée dans le système.';
            } else {
                $message = 'Erreur lors de l\'ajout du véhicule.';
            }
            $messageType = 'error';
        }
    }
}

// ====== DÉSACTIVATION ======
if (isset($_GET['deactivate']) && is_numeric($_GET['deactivate'])) {
    $stmt = $pdo->prepare("UPDATE vehicules SET statut = 'inactif' WHERE id_vehicule = ?");
    $stmt->execute([$_GET['deactivate']]);
    $message = 'Véhicule désactivé.';
    $messageType = 'success';
}

// ====== RÉACTIVATION ======
if (isset($_GET['activate']) && is_numeric($_GET['activate'])) {
    $stmt = $pdo->prepare("UPDATE vehicules SET statut = 'actif' WHERE id_vehicule = ?");
    $stmt->execute([$_GET['activate']]);
    $message = 'Véhicule réactivé.';
    $messageType = 'success';
}

// ====== SUPPRESSION ======
if (isset($_GET['supprimer']) && is_numeric($_GET['supprimer'])) {
    $idV = (int) $_GET['supprimer'];

    // Vérifier qu'aucune mission n'est en cours
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM missions WHERE id_vehicule = ? AND statut = 'en_cours'");
    $stmtCheck->execute([$idV]);
    $nbMissionsEnCours = (int) $stmtCheck->fetchColumn();

    if ($nbMissionsEnCours > 0) {
        $message = 'Impossible de supprimer ce véhicule : une mission est en cours.';
        $messageType = 'error';
    } else {
        // Supprimer les données liées
        $pdo->prepare("DELETE FROM telemetrie WHERE id_vehicule = ?")->execute([$idV]);
        $pdo->prepare("DELETE FROM alertes WHERE id_vehicule = ?")->execute([$idV]);
        $pdo->prepare("DELETE FROM missions WHERE id_vehicule = ?")->execute([$idV]);
        $pdo->prepare("DELETE FROM vehicules WHERE id_vehicule = ?")->execute([$idV]);
        enregistrerAudit($pdo, 'suppression_vehicule', 'Véhicule ID ' . $idV);
        $message = 'Véhicule supprimé définitivement.';
        $messageType = 'success';
    }
}

// ====== GÉNÉRATION CLÉ API ======
$cleApiGeneree = '';
if (isset($_GET['generate_key']) && is_numeric($_GET['generate_key'])) {
    $cleApiGeneree = bin2hex(random_bytes(24));
    $expirationCle = date('Y-m-d H:i:s', strtotime('+1 year'));
    $stmt = $pdo->prepare("UPDATE vehicules SET api_key = ?, api_key_expire = ? WHERE id_vehicule = ?");
    $stmt->execute([$cleApiGeneree, $expirationCle, $_GET['generate_key']]);
    enregistrerAudit($pdo, 'generation_cle_api', 'Véhicule ID ' . $_GET['generate_key']);
    $message = 'Nouvelle clé API générée (valide jusqu\'au ' . date('d/m/Y', strtotime($expirationCle)) . ').';
    $messageType = 'success';
}

// ====== LISTE DES SUPERVISEURS ======
$superviseurs = $pdo->query("SELECT id_user, nom, prenom FROM utilisateurs WHERE role = 'superviseur' AND statut = 'actif'")->fetchAll();

// ====== LISTE DES VÉHICULES ======
$search = clean($_GET['q'] ?? '');
$sql = "
    SELECT v.*, u.nom AS sup_nom, u.prenom AS sup_prenom,
           c.nom AS createur_nom, c.prenom AS createur_prenom
    FROM vehicules v
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    LEFT JOIN utilisateurs c ON v.id_createur = c.id_user
";
if ($search) {
    $sql .= " WHERE v.immatriculation LIKE ? OR v.marque LIKE ? OR v.modele LIKE ?";
}
$sql .= " ORDER BY v.id_vehicule DESC";

$stmt = $pdo->prepare($sql);
if ($search) {
    $like = "%$search%";
    $stmt->execute([$like, $like, $like]);
} else {
    $stmt->execute();
}
$vehicules = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
$pageTitle = 'Gestion des véhicules';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Gestion des véhicules</h1>
        <p class="subtitle"><?= count($vehicules) ?> véhicule(s) enregistré(s)</p>
    </div>
    <div class="dash-actions">
        <button class="btn-dash btn-dash-primary" onclick="document.getElementById('modalAdd').style.display='flex'">
            + Ajouter un véhicule
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($cleApiGeneree): ?>
    <div class="panel" style="padding:14px 16px;margin-bottom:14px;background:#FAEEDA;color:#633806;border:none;">
        <strong>Clé API du boîtier (à coller dans le firmware, ne sera plus affichée en clair) :</strong>
        <div style="background:#fff;border:1px dashed #BA7517;border-radius:6px;padding:10px;margin-top:8px;font-family:monospace;font-size:13px;word-break:break-all;">
            <?= htmlspecialchars($cleApiGeneree) ?>
        </div>
    </div>
<?php endif; ?>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Liste des véhicules</h3>
        <form method="GET" style="display:flex;gap:8px;">
            <input type="text" name="q" class="search-input" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn-dash btn-dash-outline" type="submit">Rechercher</button>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>Immatriculation</th>
                <th>Marque / Modèle</th>
                <th>Année</th>
                <th>Type</th>
                <th>Capacité</th>
                <th>Superviseur</th>
                <th>Créé par</th>
                <th>Statut</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vehicules)): ?>
                <tr><td colspan="9" style="text-align:center;padding:30px;color:#999;">Aucun véhicule enregistré.</td></tr>
            <?php endif; ?>
            <?php foreach ($vehicules as $v): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($v['immatriculation']) ?></strong></td>
                    <td><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?></td>
                    <td><?= $v['annee'] ?></td>
                    <td>
                        <?= ucfirst($v['type']) ?>
                        <?php if (!empty($v['proprietaire_nom'])): ?>
                            <br><span style="font-size:11px;color:#7C5CBF;"> <?= htmlspecialchars($v['proprietaire_nom']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $v['capacite_carburant'] ?> L</td>
                    <td><?= $v['sup_nom'] ? htmlspecialchars($v['sup_prenom'] . ' ' . $v['sup_nom']) : '<span style="color:#999;">Non assigné</span>' ?></td>
                    <td style="font-size:12px;color:#5C6B68;">
                        <?= $v['createur_nom'] ? htmlspecialchars($v['createur_prenom'] . ' ' . $v['createur_nom']) : '—' ?>
                    </td>
                    <td>
                        <span class="badge <?= $v['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>">
                            <?= ucfirst($v['statut']) ?>
                        </span>
                    </td>
                    <td style="display:flex;gap:0;justify-content:space-between;width:100%;min-width:260px;">
                        <a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-size:12px;">Fiche</a>
                        <a href="?generate_key=<?= $v['id_vehicule'] ?>" style="color:#7C5CBF;font-size:12px;"
                           onclick="return confirm('<?= $v['api_key'] ? 'Régénérer la clé API ? L\'ancienne cessera de fonctionner immédiatement.' : 'Générer une clé API pour ce véhicule ?' ?>')">
                            <?= $v['api_key'] ? 'Régénérer clé' : 'Clé API' ?>
                        </a>
                        <?php if ($v['api_key'] && $v['api_key_expire']): ?>
                            <?php $cleExpiree = strtotime($v['api_key_expire']) < time(); ?>
                            <span style="font-size:11px;color:<?= $cleExpiree ? '#E24B4A' : '#999' ?>;">
                                <?= $cleExpiree ? 'Clé expirée' : 'Exp. ' . date('d/m/Y', strtotime($v['api_key_expire'])) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($v['statut'] === 'actif'): ?>
                            <a href="?deactivate=<?= $v['id_vehicule'] ?>" style="color:#E24B4A;font-size:12px;"
                               onclick="return confirm('Désactiver ce véhicule ?')">Désactiver</a>
                        <?php else: ?>
                            <a href="?activate=<?= $v['id_vehicule'] ?>" style="color:#1D9E75;font-size:12px;">Réactiver</a>
                        <?php endif; ?>
                        
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== MODAL AJOUT VÉHICULE ====== -->
<div id="modalAdd" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:18px;font-size:17px;">Ajouter un véhicule</h3>
        <form method="POST" id="formAddVehicule">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-group">
                <label>Immatriculation *</label>
                <input type="text" name="immatriculation" required placeholder="KG-123-GN">
            </div>

            <div class="contact-grid">
                <div class="form-group">
                    <label>Marque *</label>
                    <input type="text" name="marque" required placeholder="Caterpillar">
                </div>
                <div class="form-group">
                    <label>Modèle *</label>
                    <input type="text" name="modele" required placeholder="797F">
                </div>
            </div>

            <div class="contact-grid">
                <div class="form-group">
                    <label>Année *</label>
                    <input type="number" name="annee" required min="1990" max="<?= date('Y') + 1 ?>" value="<?= date('Y') ?>">
                </div>
                <div class="form-group">
                    <label>Type *</label>
                    <select name="type" id="selectType" required onchange="gererAffichageType()">
                        <option value="minier">Minier</option>
                        <option value="routier">Routier</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Capacité réservoir (L)</label>
                <input type="number" name="capacite_carburant" value="200" min="20">
            </div>

            <!-- ====== SECTION MINIER — visible uniquement si type = minier ====== -->
            <div id="sectionMinier" style="border:1px solid #E1F5EE;border-radius:8px;padding:16px;margin-bottom:14px;background:#F9FFFE;">
                <p style="font-size:12px;font-weight:600;color:#0F6E56;margin-bottom:12px;">&#9881; Paramètres camion minier</p>

                <div class="contact-grid">
                    <div class="form-group">
                        <label>Poids à vide / Tare (kg)</label>
                        <input type="number" name="poids_tare_kg" min="0" placeholder="ex: 65000"
                               title="Poids du camion vide — utilisé pour calculer la charge nette après mesure du capteur">
                    </div>
                    <div class="form-group">
                        <label>Charge max autorisée (kg)</label>
                        <input type="number" name="poids_max_kg" min="0" placeholder="ex: 90000"
                               title="Capacité de charge utile maximale — déclenche une alerte si dépassée">
                    </div>
                </div>

                <div class="form-group">
                    <label>Nombre de roues</label>
                    <select name="nb_roues">
                        <option value="4">4 roues</option>
                        <option value="6" selected>6 roues</option>
                        <option value="10">10 roues</option>
                    </select>
                </div>

                <p style="font-size:11px;color:#5C6B68;margin-top:4px;">
                    &#8505; Le calcul de la charge nette = Poids total mesuré par le capteur − Tare saisie ci-dessus.
                </p>
            </div>

            <div class="form-group">
                <label>Superviseur assigné</label>
                <select name="id_superviseur">
                    <option value="">— Non assigné —</option>
                    <?php foreach ($superviseurs as $s): ?>
                        <option value="<?= $s['id_user'] ?>"><?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ====== SECTION APPARTENANCE ====== -->
            <div class="form-group">
                <label>Ce véhicule appartient à *</label>
                <select name="appartenance" id="selectAppartenance" onchange="gererAppartenance()">
                    <option value="entreprise">L'entreprise (Simandou 2040)</option>
                    <option value="investisseur">Un investisseur</option>
                </select>
            </div>

            <!-- ====== SECTION INVESTISSEUR — dépliée si appartenance = investisseur ====== -->
            <div id="sectionInvestisseur" style="display:none;border:1px solid #E8E0F5;border-radius:8px;padding:16px;margin-bottom:14px;background:#FAF8FF;">
                <p style="font-size:12px;font-weight:600;color:#7C5CBF;margin-bottom:12px;">&#128100; Informations de l'investisseur</p>

                <div class="form-group">
                    <label>Nom complet / Société *</label>
                    <input type="text" name="proprietaire_nom" placeholder="ex: Moussa Camara ou Société Minière SA"
                           id="inputProprietaireNom">
                </div>

                <div class="contact-grid">
                    <div class="form-group">
                        <label>Email de contact</label>
                        <input type="email" name="proprietaire_contact_email" placeholder="investisseur@email.com">
                    </div>
                    <div class="form-group">
                        <label>Téléphone</label>
                        <input type="tel" name="proprietaire_contact_tel" placeholder="+224 6XX XXX XXX">
                    </div>
                </div>

                <p style="font-size:11px;color:#5C6B68;margin-top:4px;">
                    &#8505; Ces informations permettront au superviseur de transmettre les rapports d'activité à l'investisseur.
                </p>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;"
                        onclick="document.getElementById('modalAdd').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Ajouter</button>
            </div>
        </form>
    </div>
</div>

<script>
// Affiche/masque la section minier selon le type sélectionné
function gererAffichageType() {
    var type = document.getElementById('selectType').value;
    var section = document.getElementById('sectionMinier');
    section.style.display = type === 'minier' ? 'block' : 'none';
}

// Affiche/masque la section investisseur selon l'appartenance
function gererAppartenance() {
    var val = document.getElementById('selectAppartenance').value;
    var section = document.getElementById('sectionInvestisseur');
    var input = document.getElementById('inputProprietaireNom');
    section.style.display = val === 'investisseur' ? 'block' : 'none';
    input.required = val === 'investisseur';
}

// Initialisation au chargement (minier sélectionné par défaut)
gererAffichageType();
gererAppartenance();
</script>

<?php require_once '../includes/footer.php'; ?>