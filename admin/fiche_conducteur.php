<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/fiche_conducteur.php
// Rôle    : Fiche détaillée et confidentielle d'un conducteur
//           (infos personnelles, documents, historique missions)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/mailer.php';

requireAdmin();

$idConducteur = (int) ($_GET['id'] ?? 0);
$message = '';
$messageType = '';

// ====== CONFIGURATION UPLOAD ======
$dossierUpload = '../assets/uploads/conducteurs/';
$extensionsImageAutorisees = ['jpg', 'jpeg', 'png', 'webp'];
$extensionsDocAutorisees = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
$tailleMaxOctets = 5 * 1024 * 1024; // 5 Mo

// (fonction uploaderFichier() désormais centralisée dans includes/functions.php)

// ====== MISE À JOUR DES INFORMATIONS COMPLÉMENTAIRES ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_infos') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $dateNaissance = chiffrer($_POST['date_naissance'] ?: '');
        $adresse = chiffrer(clean($_POST['adresse'] ?? ''));
        $contactNom = chiffrer(clean($_POST['contact_urgence_nom'] ?? ''));
        $contactTel = chiffrer(clean($_POST['contact_urgence_telephone'] ?? ''));
        $categoriePermis = clean($_POST['categorie_permis'] ?? '');
        $groupeSanguin = chiffrer(clean($_POST['groupe_sanguin'] ?? ''));
        $dateEmbauche = $_POST['date_embauche'] ?: null;
        $dateVisiteMedicale = $_POST['date_visite_medicale'] ?: null;
        $nationalite = clean($_POST['nationalite'] ?? '');
        $formationAcademy = in_array($_POST['formation_academy'] ?? '', ['non', 'en_cours', 'certifie'], true) ? $_POST['formation_academy'] : 'non';
        $dateFormationAcademy = $_POST['date_formation_academy'] ?: null;

        $erreurs = [];

        // ====== UPLOADS (photo, permis, CIN) ======
        $resPhoto = uploaderFichier('photo', 'photo', $idConducteur, $extensionsImageAutorisees, $dossierUpload, $tailleMaxOctets);
        if (!$resPhoto['success']) $erreurs[] = $resPhoto['erreur'];

        $resPermis = uploaderFichier('permis_scan', 'permis', $idConducteur, $extensionsDocAutorisees, $dossierUpload, $tailleMaxOctets);
        if (!$resPermis['success']) $erreurs[] = $resPermis['erreur'];

        $resCin = uploaderFichier('cin_scan', 'cin', $idConducteur, $extensionsDocAutorisees, $dossierUpload, $tailleMaxOctets);
        if (!$resCin['success']) $erreurs[] = $resCin['erreur'];

        if (!empty($erreurs)) {
            $message = implode(' ', $erreurs);
            $messageType = 'error';
        } else {
            $sql = "UPDATE conducteurs SET
                        date_naissance = ?, adresse = ?, contact_urgence_nom = ?, contact_urgence_telephone = ?,
                        categorie_permis = ?, groupe_sanguin = ?, date_embauche = ?, date_visite_medicale = ?,
                        nationalite = ?, formation_academy = ?, date_formation_academy = ?";
            $params = [$dateNaissance, $adresse, $contactNom, $contactTel, $categoriePermis, $groupeSanguin, $dateEmbauche, $dateVisiteMedicale, $nationalite, $formationAcademy, $dateFormationAcademy];

            if ($resPhoto['chemin']) {
                $sql .= ", photo = ?";
                $params[] = $resPhoto['chemin'];
            }
            if ($resPermis['chemin']) {
                $sql .= ", permis_scan = ?";
                $params[] = $resPermis['chemin'];
            }
            if ($resCin['chemin']) {
                $sql .= ", cin_scan = ?";
                $params[] = $resCin['chemin'];
            }

            $sql .= " WHERE id_conducteur = ?";
            $params[] = $idConducteur;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $message = 'Informations mises à jour avec succès.';
            $messageType = 'success';
        }
    }
}

// ====== AJOUT D'UNE CERTIFICATION DE SÉCURITÉ ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_certification') {

    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
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

// ====== CRÉATION / GESTION DE L'ACCÈS AU PORTAIL CONDUCTEUR ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'creer_acces') {

    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $emailAcces = clean($_POST['email_acces'] ?? '');

        if (!filter_var($emailAcces, FILTER_VALIDATE_EMAIL)) {
            $message = 'Adresse email invalide.';
            $messageType = 'error';
        } else {
            // Mot de passe temporaire généré aléatoirement, à changer par le conducteur si on ajoute cette option plus tard
            $motDePasseTemp = bin2hex(random_bytes(4)) . 'A1!'; // respecte la politique (maj/min/chiffre)
            $hash = password_hash($motDePasseTemp, PASSWORD_BCRYPT);

            try {
                $stmt = $pdo->prepare("
                    UPDATE conducteurs SET email = ?, mot_de_passe = ?, compte_actif = 'actif'
                    WHERE id_conducteur = ?
                ");
                $stmt->execute([$emailAcces, $hash, $idConducteur]);

                $corpsEmail = "
                    <h3>Bienvenue sur votre espace FleetIoT</h3>
                    <p>Un accès à votre espace personnel (pointage, missions, formations) a été créé.</p>
                    <p><strong>Email :</strong> " . htmlspecialchars($emailAcces) . "</p>
                    <p><strong>Mot de passe temporaire :</strong> " . htmlspecialchars($motDePasseTemp) . "</p>
                    <p>Connectez-vous sur la page de connexion habituelle avec ces identifiants.</p>
                ";
                envoyerEmail($emailAcces, '[FleetIoT] Votre accès à l\'espace conducteur', $corpsEmail);

                enregistrerAudit($pdo, 'creation_acces_conducteur', 'Conducteur ID ' . $idConducteur);
                $message = 'Accès créé avec succès. Les identifiants ont été envoyés par email à ' . htmlspecialchars($emailAcces) . '.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = ($e->getCode() == 23000)
                    ? 'Cette adresse email est déjà utilisée par un autre compte.'
                    : 'Erreur lors de la création de l\'accès.';
                $messageType = 'error';
            }
        }
    }
}

// ====== DÉSACTIVATION DE L'ACCÈS AU PORTAIL ======
if (isset($_GET['desactiver_acces'])) {
    $stmt = $pdo->prepare("UPDATE conducteurs SET compte_actif = 'inactif' WHERE id_conducteur = ?");
    $stmt->execute([$idConducteur]);
    $message = 'Accès au portail désactivé.';
    $messageType = 'success';
}
if (isset($_GET['reactiver_acces'])) {
    $stmt = $pdo->prepare("UPDATE conducteurs SET compte_actif = 'actif' WHERE id_conducteur = ?");
    $stmt->execute([$idConducteur]);
    $message = 'Accès au portail réactivé.';
    $messageType = 'success';
}

// ====== SUPPRESSION D'UNE CERTIFICATION ======
if (isset($_GET['delete_certif']) && is_numeric($_GET['delete_certif'])) {
    $stmt = $pdo->prepare("DELETE FROM certifications_conducteur WHERE id_certification = ? AND id_conducteur = ?");
    $stmt->execute([$_GET['delete_certif'], $idConducteur]);
    $message = 'Certification supprimée.';
    $messageType = 'success';
}

// ====== RÉCUPÉRATION DU CONDUCTEUR ======
$stmt = $pdo->prepare("SELECT * FROM conducteurs WHERE id_conducteur = ?");
$stmt->execute([$idConducteur]);
$conducteur = $stmt->fetch();

if ($conducteur) {
    // Déchiffrement unique des champs sensibles, pour que tout le reste
    // de la page (affichage + pré-remplissage du formulaire) reçoive du clair.
    $conducteur['adresse'] = dechiffrer($conducteur['adresse']);
    $conducteur['contact_urgence_nom'] = dechiffrer($conducteur['contact_urgence_nom']);
    $conducteur['contact_urgence_telephone'] = dechiffrer($conducteur['contact_urgence_telephone']);
    $conducteur['date_naissance'] = dechiffrer($conducteur['date_naissance']) ?: null;
    $conducteur['groupe_sanguin'] = dechiffrer($conducteur['groupe_sanguin']);
}

if (!$conducteur) {
    header('Location: conducteurs.php');
    exit();
}

// ====== HISTORIQUE DES MISSIONS DE CE CONDUCTEUR ======
$stmt = $pdo->prepare("
    SELECT m.*, v.immatriculation, v.type AS type_vehicule
    FROM missions m
    JOIN vehicules v ON m.id_vehicule = v.id_vehicule
    WHERE m.id_conducteur = ?
    ORDER BY m.date_debut DESC
    LIMIT 20
");
$stmt->execute([$idConducteur]);
$missions = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM missions WHERE id_conducteur = ?");
$stmt->execute([$idConducteur]);
$nbMissionsTotal = $stmt->fetchColumn();

// ====== CERTIFICATIONS DE SÉCURITÉ ======
$stmt = $pdo->prepare("SELECT * FROM certifications_conducteur WHERE id_conducteur = ? ORDER BY date_obtention DESC");
$stmt->execute([$idConducteur]);
$certifications = $stmt->fetchAll();

// ====== CALCULS (ancienneté, alertes documents) ======
$age = $conducteur['date_naissance'] ? floor((time() - strtotime($conducteur['date_naissance'])) / 31557600) : null;
$anciennete = $conducteur['date_embauche'] ? floor((time() - strtotime($conducteur['date_embauche'])) / 31557600) : null;

$joursRestantsPermis = (strtotime($conducteur['permis_expiration']) - time()) / 86400;
$permisExpire = $joursRestantsPermis < 0;
$permisExpireProche = $joursRestantsPermis >= 0 && $joursRestantsPermis <= 30;

$visiteMedicaleAlerte = false;
if ($conducteur['date_visite_medicale']) {
    $joursDepuisVisite = (time() - strtotime($conducteur['date_visite_medicale'])) / 86400;
    $visiteMedicaleAlerte = $joursDepuisVisite > 365; // visite médicale considérée valable 1 an
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Fiche conducteur — ' . $conducteur['prenom'] . ' ' . $conducteur['nom'];
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#128100; <?= htmlspecialchars($conducteur['prenom'] . ' ' . $conducteur['nom']) ?></h1>
        <p class="subtitle">Fiche confidentielle — accès réservé aux administrateurs</p>
    </div>
    <div class="dash-actions">
        <a href="conducteurs.php" class="btn-dash btn-dash-outline">&#8592; Retour à la liste</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($permisExpire || $permisExpireProche || $visiteMedicaleAlerte): ?>
    <div class="panel" style="padding:14px 16px;margin-bottom:14px;background:#FCEBEB;color:#A32D2D;border:none;">
        &#9888;
        <?php if ($permisExpire): ?>Le permis de conduire est <strong>expiré</strong>. <?php elseif ($permisExpireProche): ?>Le permis de conduire expire dans moins de 30 jours. <?php endif; ?>
        <?php if ($visiteMedicaleAlerte): ?>La visite médicale d'aptitude date de plus d'un an et doit être renouvelée.<?php endif; ?>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:280px 1fr;gap:20px;margin-bottom:16px;">

    <!-- ====== COLONNE PHOTO + DOCUMENTS ====== -->
    <div class="panel" style="padding:20px;text-align:center;">
        <?php if ($conducteur['photo']): ?>
            <img src="../<?= htmlspecialchars($conducteur['photo']) ?>" alt="Photo" style="width:160px;height:160px;border-radius:50%;object-fit:cover;border:3px solid var(--color-border);margin:0 auto 14px;">
        <?php else: ?>
            <div style="width:160px;height:160px;border-radius:50%;background:#E1F5EE;color:#0F6E56;display:flex;align-items:center;justify-content:center;font-size:42px;font-weight:700;margin:0 auto 14px;">
                <?= strtoupper(substr($conducteur['prenom'], 0, 1) . substr($conducteur['nom'], 0, 1)) ?>
            </div>
        <?php endif; ?>

        <h3 style="margin-bottom:4px;"><?= htmlspecialchars($conducteur['prenom'] . ' ' . $conducteur['nom']) ?></h3>
        <span class="badge <?= $conducteur['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>"><?= ucfirst($conducteur['statut']) ?></span>

        <div style="margin-top:18px;text-align:left;font-size:13px;">
            <p style="margin-bottom:8px;"><strong>Documents :</strong></p>
            <?php if ($conducteur['permis_scan']): ?>
                <a href="../<?= htmlspecialchars($conducteur['permis_scan']) ?>" target="_blank" style="display:block;color:#0F6E56;margin-bottom:6px;">&#128196; Voir le permis scanné</a>
            <?php else: ?>
                <p style="color:#999;margin-bottom:6px;">&#128196; Permis non numérisé</p>
            <?php endif; ?>

            <?php if ($conducteur['cin_scan']): ?>
                <a href="../<?= htmlspecialchars($conducteur['cin_scan']) ?>" target="_blank" style="display:block;color:#0F6E56;">&#128196; Voir la CIN scannée</a>
            <?php else: ?>
                <p style="color:#999;">&#128196; CIN non numérisée</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== COLONNE INFOS ====== -->
    <div class="panel" style="padding:24px;">
        <h3 style="margin-bottom:18px;">Informations confidentielles</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:24px;">
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">CIN</p>
                <p style="font-weight:600;"><?= htmlspecialchars($conducteur['cin']) ?></p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Téléphone</p>
                <p style="font-weight:600;"><?= htmlspecialchars($conducteur['telephone'] ?: '—') ?></p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">N° Permis</p>
                <p style="font-weight:600;"><?= htmlspecialchars($conducteur['permis_numero']) ?> <?= $conducteur['categorie_permis'] ? '(Cat. ' . htmlspecialchars($conducteur['categorie_permis']) . ')' : '' ?></p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Expiration permis</p>
                <p style="font-weight:600;color:<?= $permisExpire ? '#E24B4A' : ($permisExpireProche ? '#BA7517' : '#1A1A1A') ?>;">
                    <?= date('d/m/Y', strtotime($conducteur['permis_expiration'])) ?>
                </p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Date de naissance</p>
                <p style="font-weight:600;"><?= $conducteur['date_naissance'] ? date('d/m/Y', strtotime($conducteur['date_naissance'])) . ' (' . $age . ' ans)' : '—' ?></p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Groupe sanguin</p>
                <p style="font-weight:600;"><?= htmlspecialchars($conducteur['groupe_sanguin'] ?: '—') ?></p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Adresse</p>
                <p style="font-weight:600;"><?= htmlspecialchars($conducteur['adresse'] ?: '—') ?></p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Contact d'urgence</p>
                <p style="font-weight:600;">
                    <?= $conducteur['contact_urgence_nom'] ? htmlspecialchars($conducteur['contact_urgence_nom']) . ' — ' . htmlspecialchars($conducteur['contact_urgence_telephone']) : '—' ?>
                </p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Date d'embauche</p>
                <p style="font-weight:600;"><?= $conducteur['date_embauche'] ? date('d/m/Y', strtotime($conducteur['date_embauche'])) . ' (' . $anciennete . ' an(s))' : '—' ?></p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Dernière visite médicale</p>
                <p style="font-weight:600;color:<?= $visiteMedicaleAlerte ? '#E24B4A' : '#1A1A1A' ?>;">
                    <?= $conducteur['date_visite_medicale'] ? date('d/m/Y', strtotime($conducteur['date_visite_medicale'])) : '—' ?>
                </p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Missions effectuées</p>
                <p style="font-weight:600;"><?= $nbMissionsTotal ?></p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Nationalité</p>
                <p style="font-weight:600;"><?= htmlspecialchars($conducteur['nationalite'] ?: '—') ?></p>
            </div>
            <div>
                <p style="font-size:12px;color:#999;margin-bottom:4px;">Formation Simandou Academy</p>
                <p style="font-weight:600;">
                    <?php
                        $labelsFormation = ['non' => 'Non formé', 'en_cours' => 'En cours', 'certifie' => 'Certifié'];
                        $badgeFormation = ['non' => 'badge-warning', 'en_cours' => 'badge-warning', 'certifie' => 'badge-success'];
                    ?>
                    <span class="badge <?= $badgeFormation[$conducteur['formation_academy']] ?>"><?= $labelsFormation[$conducteur['formation_academy']] ?></span>
                </p>
            </div>
        </div>

        <button class="btn-dash btn-dash-primary" onclick="document.getElementById('modalEditInfos').style.display='flex'">
            Modifier les informations / documents
        </button>

        <hr style="margin:20px 0;border-color:var(--color-border);">

        <h4 style="margin-bottom:10px;font-size:14px;">Accès à l'espace conducteur (Simandou Academy)</h4>
        <?php if ($conducteur['compte_actif'] === 'actif'): ?>
            <p style="font-size:13px;margin-bottom:10px;">
                <span class="badge badge-success">Actif</span>
                — <?= htmlspecialchars($conducteur['email']) ?>
            </p>
            <a href="?id=<?= $idConducteur ?>&desactiver_acces=1" style="color:#E24B4A;font-size:12px;" onclick="return confirm('Désactiver l\'accès au portail pour ce conducteur ?')">Désactiver l'accès</a>
        <?php elseif ($conducteur['compte_actif'] === 'inactif'): ?>
            <p style="font-size:13px;margin-bottom:10px;">
                <span class="badge badge-warning">Désactivé</span>
                — <?= htmlspecialchars($conducteur['email']) ?>
            </p>
            <a href="?id=<?= $idConducteur ?>&reactiver_acces=1" style="color:#1D9E75;font-size:12px;">Réactiver l'accès</a>
        <?php else: ?>
            <p style="font-size:13px;color:#999;margin-bottom:10px;">Aucun accès créé pour le moment.</p>
            <button class="btn-dash btn-dash-outline" onclick="document.getElementById('modalCreerAcces').style.display='flex'">
                Créer l'accès au portail
            </button>
        <?php endif; ?>
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
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($certifications)): ?>
                <tr><td colspan="6" style="text-align:center;padding:20px;color:#999;">Aucune certification enregistrée.</td></tr>
            <?php endif; ?>
            <?php foreach ($certifications as $cert): ?>
                <?php
                    $certifExpiree = $cert['date_expiration'] && strtotime($cert['date_expiration']) < time();
                ?>
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
                    <td>
                        <a href="?id=<?= $idConducteur ?>&delete_certif=<?= $cert['id_certification'] ?>" style="color:#E24B4A;font-size:12px;" onclick="return confirm('Supprimer cette certification ?')">Supprimer</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== HISTORIQUE DES MISSIONS ====== -->
<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>&#128203; Historique des missions (20 dernières)</h3>
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
                <tr><td colspan="5" style="text-align:center;padding:24px;color:#999;">Aucune mission enregistrée pour ce conducteur.</td></tr>
            <?php endif; ?>
            <?php foreach ($missions as $m): ?>
                <?php
                    $statutBadge = ['planifiee' => 'badge-warning', 'en_cours' => 'badge-success', 'terminee' => 'badge-success', 'annulee' => 'badge-danger'];
                    $statutLabel = ['planifiee' => 'Planifiée', 'en_cours' => 'En cours', 'terminee' => 'Terminée', 'annulee' => 'Annulée'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($m['immatriculation']) ?> <span style="color:#999;font-size:11px;">(<?= ucfirst($m['type_vehicule']) ?>)</span></td>
                    <td><?= htmlspecialchars($m['lieu_depart']) ?></td>
                    <td><?= htmlspecialchars($m['lieu_destination']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($m['date_debut'])) ?></td>
                    <td><span class="badge <?= $statutBadge[$m['statut']] ?>"><?= $statutLabel[$m['statut']] ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== MODAL MODIFICATION INFOS + DOCUMENTS ====== -->
<div id="modalEditInfos" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:18px;font-size:17px;">Modifier les informations confidentielles</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_infos">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="contact-grid">
                <div class="form-group">
                    <label>Date de naissance</label>
                    <input type="date" name="date_naissance" value="<?= $conducteur['date_naissance'] ?>">
                </div>
                <div class="form-group">
                    <label>Groupe sanguin</label>
                    <select name="groupe_sanguin">
                        <option value="">—</option>
                        <?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $gs): ?>
                            <option value="<?= $gs ?>" <?= $conducteur['groupe_sanguin'] === $gs ? 'selected' : '' ?>><?= $gs ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Adresse</label>
                <input type="text" name="adresse" value="<?= htmlspecialchars($conducteur['adresse'] ?? '') ?>" placeholder="Quartier, ville">
            </div>

            <div class="contact-grid">
                <div class="form-group">
                    <label>Contact d'urgence (nom)</label>
                    <input type="text" name="contact_urgence_nom" value="<?= htmlspecialchars($conducteur['contact_urgence_nom'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Contact d'urgence (téléphone)</label>
                    <input type="text" name="contact_urgence_telephone" value="<?= htmlspecialchars($conducteur['contact_urgence_telephone'] ?? '') ?>">
                </div>
            </div>

            <div class="contact-grid">
                <div class="form-group">
                    <label>Catégorie de permis</label>
                    <input type="text" name="categorie_permis" value="<?= htmlspecialchars($conducteur['categorie_permis'] ?? '') ?>" placeholder="C, CE...">
                </div>
                <div class="form-group">
                    <label>Date d'embauche</label>
                    <input type="date" name="date_embauche" value="<?= $conducteur['date_embauche'] ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Dernière visite médicale d'aptitude</label>
                <input type="date" name="date_visite_medicale" value="<?= $conducteur['date_visite_medicale'] ?>">
            </div>

            <hr style="margin:18px 0;border-color:var(--color-border);">
            <p style="font-size:13px;font-weight:600;margin-bottom:12px;">Contenu local (Simandou 2040)</p>

            <div class="contact-grid">
                <div class="form-group">
                    <label>Nationalité</label>
                    <input type="text" name="nationalite" value="<?= htmlspecialchars($conducteur['nationalite'] ?? 'Guinéenne') ?>" placeholder="Guinéenne">
                </div>
                <div class="form-group">
                    <label>Formation Simandou Academy</label>
                    <select name="formation_academy">
                        <option value="non" <?= $conducteur['formation_academy'] === 'non' ? 'selected' : '' ?>>Non formé</option>
                        <option value="en_cours" <?= $conducteur['formation_academy'] === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                        <option value="certifie" <?= $conducteur['formation_academy'] === 'certifie' ? 'selected' : '' ?>>Certifié</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Date de formation Simandou Academy (si applicable)</label>
                <input type="date" name="date_formation_academy" value="<?= $conducteur['date_formation_academy'] ?>">
            </div>

            <hr style="margin:18px 0;border-color:var(--color-border);">

            <div class="form-group">
                <label>Photo d'identité (JPG/PNG/WEBP, max 5 Mo)</label>
                <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp">
            </div>

            <div class="form-group">
                <label>Scan du permis de conduire (image ou PDF, max 5 Mo)</label>
                <input type="file" name="permis_scan" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>

            <div class="form-group">
                <label>Scan de la CIN (image ou PDF, max 5 Mo)</label>
                <input type="file" name="cin_scan" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;" onclick="document.getElementById('modalEditInfos').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Enregistrer</button>
            </div>
        </form>
    </div>
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

<!-- ====== MODAL CRÉATION ACCÈS PORTAIL ====== -->
<div id="modalCreerAcces" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:420px;">
        <h3 style="margin-bottom:18px;font-size:17px;">Créer l'accès au portail conducteur</h3>
        <form method="POST">
            <input type="hidden" name="action" value="creer_acces">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-group">
                <label>Adresse email du conducteur *</label>
                <input type="email" name="email_acces" required placeholder="conducteur@email.com">
            </div>

            <p style="font-size:12px;color:#999;margin-bottom:14px;">
                Un mot de passe temporaire sera généré automatiquement et envoyé à cette adresse,
                avec les instructions de connexion.
            </p>

            <div style="display:flex;gap:10px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;" onclick="document.getElementById('modalCreerAcces').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Créer l'accès</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>