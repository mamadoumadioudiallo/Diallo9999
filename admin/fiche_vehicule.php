<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/fiche_vehicule.php
// Rôle    : Fiche détaillée d'un véhicule — historique complet
//           (missions, alertes, télémétrie, statistiques,
//            poids/tare, infos investisseur)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$idVehicule = (int) ($_GET['id'] ?? 0);
$message = '';
$messageType = '';

// ====== UPLOAD PHOTO DU VÉHICULE ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $dossierUpload = '../assets/uploads/vehicules/';
        if (!is_dir($dossierUpload)) {
            mkdir($dossierUpload, 0755, true);
        }
        $extensionsImage = ['jpg', 'jpeg', 'png', 'webp'];
        $tailleMax = 5 * 1024 * 1024;
        $res = uploaderFichier('photo', 'vehicule', $idVehicule, $extensionsImage, $dossierUpload, $tailleMax);
        if (!$res['success']) {
            $message = $res['erreur'];
            $messageType = 'error';
        } elseif ($res['chemin']) {
            $stmt = $pdo->prepare("UPDATE vehicules SET photo = ? WHERE id_vehicule = ?");
            $stmt->execute([$res['chemin'], $idVehicule]);
            enregistrerAudit($pdo, 'upload_photo_vehicule', 'Véhicule ID ' . $idVehicule);
            $message = 'Photo mise à jour avec succès.';
            $messageType = 'success';
        }
    }
}

// ====== MODIFICATION DU VÉHICULE ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $immatriculation = clean($_POST['immatriculation']);
        $marque          = clean($_POST['marque']);
        $modele          = clean($_POST['modele']);
        $annee           = (int) $_POST['annee'];
        $type            = $_POST['type'] === 'minier' ? 'minier' : 'routier';
        $capacite        = (float) $_POST['capacite_carburant'];
        $idSuperviseur   = !empty($_POST['id_superviseur']) ? (int) $_POST['id_superviseur'] : null;
        $nbRoues         = $type === 'minier' ? (int) ($_POST['nb_roues'] ?? 6) : 4;

        // Champs minier
        $poidsMaxKg  = ($type === 'minier' && $_POST['poids_max_kg'] !== '')  ? (int) $_POST['poids_max_kg']  : null;
        $poidsTareKg = ($type === 'minier' && $_POST['poids_tare_kg'] !== '') ? (int) $_POST['poids_tare_kg'] : null;

        // Champs investisseur
        $appartenance      = $_POST['appartenance'] ?? 'entreprise';
        $proprietaireNom   = null;
        $proprietaireEmail = null;
        $proprietaireTel   = null;
        $contratDebut      = null;

        if ($appartenance === 'investisseur') {
            $proprietaireNom   = clean($_POST['proprietaire_nom']          ?? '');
            $proprietaireEmail = clean($_POST['proprietaire_contact_email'] ?? '');
            $proprietaireTel   = clean($_POST['proprietaire_contact_tel']   ?? '');
            $contratDebut      = !empty($_POST['contrat_debut']) ? $_POST['contrat_debut'] : null;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE vehicules SET
                    immatriculation           = ?,
                    marque                    = ?,
                    modele                    = ?,
                    annee                     = ?,
                    type                      = ?,
                    capacite_carburant        = ?,
                    id_superviseur            = ?,
                    nb_roues                  = ?,
                    poids_max_kg              = ?,
                    poids_tare_kg             = ?,
                    proprietaire_nom          = ?,
                    proprietaire_contact_email = ?,
                    proprietaire_contact_tel  = ?,
                    contrat_debut             = ?
                WHERE id_vehicule = ?
            ");
            $stmt->execute([
                $immatriculation, $marque, $modele, $annee, $type, $capacite,
                $idSuperviseur, $nbRoues, $poidsMaxKg, $poidsTareKg,
                $proprietaireNom, $proprietaireEmail, $proprietaireTel,
                $contratDebut, $idVehicule,
            ]);
            enregistrerAudit($pdo, 'modification_vehicule', 'Véhicule ID ' . $idVehicule);
            $message = 'Véhicule modifié avec succès.';
            $messageType = 'success';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = 'Cette immatriculation est déjà utilisée par un autre véhicule.';
            } else {
                $message = 'Erreur lors de la modification.';
            }
            $messageType = 'error';
        }
    }
}

// ====== INFOS DU VÉHICULE ======
$stmt = $pdo->prepare("
    SELECT v.*, u.nom AS sup_nom, u.prenom AS sup_prenom, u.photo AS sup_photo
    FROM vehicules v
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    WHERE v.id_vehicule = ?
");$stmt->execute([$idVehicule]);
$vehicule = $stmt->fetch();

if (!$vehicule) {
    header('Location: vehicules.php');
    exit();
}

// ====== LISTE DES SUPERVISEURS (pour la modale de modification) ======
$superviseurs = $pdo->query("SELECT id_user, nom, prenom FROM utilisateurs WHERE role = 'superviseur' AND statut = 'actif'")->fetchAll();

// ====== STATISTIQUES GLOBALES ======
$stmt = $pdo->prepare("
    SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max,
           AVG(vitesse) AS vitesse_moy, AVG(temperature_moteur) AS temp_moy,
           COUNT(*) AS nb_releves
    FROM telemetrie WHERE id_vehicule = ?
");
$stmt->execute([$idVehicule]);
$statsGlobales = $stmt->fetch();
$kmTotal = ($statsGlobales['km_min'] !== null) ? $statsGlobales['km_max'] - $statsGlobales['km_min'] : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM missions WHERE id_vehicule = ?");
$stmt->execute([$idVehicule]);
$nbMissions = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM alertes WHERE id_vehicule = ?");
$stmt->execute([$idVehicule]);
$nbAlertes = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM alertes WHERE id_vehicule = ? AND statut != 'resolue'");
$stmt->execute([$idVehicule]);
$nbAlertesActives = $stmt->fetchColumn();

// ====== DERNIÈRE TÉLÉMÉTRIE (pour poids et état moteur) ======
$stmt = $pdo->prepare("
    SELECT * FROM telemetrie
    WHERE id_vehicule = ?
    ORDER BY horodatage DESC
    LIMIT 1
");
$stmt->execute([$idVehicule]);
$dernierReleve = $stmt->fetch();

// ====== STATISTIQUES POIDS (minier uniquement) ======
$statsPoidsCharge = null;
if ($vehicule['type'] === 'minier') {
    $stmt = $pdo->prepare("
        SELECT AVG(poids_charge_kg) AS poids_moy,
               MAX(poids_charge_kg) AS poids_max,
               COUNT(CASE WHEN poids_charge_kg > 0 THEN 1 END) AS nb_chargements
        FROM telemetrie
        WHERE id_vehicule = ? AND poids_charge_kg IS NOT NULL
        AND horodatage >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([$idVehicule]);
    $statsPoidsCharge = $stmt->fetch();
}

// ====== HISTORIQUE DES MISSIONS (20 dernières) ======
$stmt = $pdo->prepare("
    SELECT m.*, c.nom AS c_nom, c.prenom AS c_prenom
    FROM missions m
    JOIN conducteurs c ON m.id_conducteur = c.id_conducteur
    WHERE m.id_vehicule = ?
    ORDER BY m.date_debut DESC
    LIMIT 20
");
$stmt->execute([$idVehicule]);
$missions = $stmt->fetchAll();

// ====== HISTORIQUE DES ALERTES (20 dernières) ======
$libellesAlertes = [
    'vitesse_excessive'    => 'Vitesse excessive',
    'temperature_critique' => 'Température critique',
    'carburant_bas'        => 'Carburant bas',
    'hors_zone'            => 'Hors zone',
    'surcharge'            => 'Surcharge',
    'tpms_pression'        => 'Pression pneu (TPMS)',
    'tpms_temperature'     => 'Température pneu (TPMS)',
    'moteur_anomalie'      => 'Anomalie moteur',
];

$stmt = $pdo->prepare("
    SELECT a.*, u.nom AS traitant_nom, u.prenom AS traitant_prenom
    FROM alertes a
    LEFT JOIN utilisateurs u ON a.id_traitant = u.id_user
    WHERE a.id_vehicule = ?
    ORDER BY a.horodatage DESC
    LIMIT 20
");
$stmt->execute([$idVehicule]);
$alertes = $stmt->fetchAll();

// ====== TÉLÉMÉTRIE RÉCENTE 24H ======
$stmt = $pdo->prepare("
    SELECT horodatage, vitesse, carburant, temperature_moteur,
           poids_charge_kg, moteur_etat
    FROM telemetrie
    WHERE id_vehicule = ? AND horodatage >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY horodatage ASC
");
$stmt->execute([$idVehicule]);
$telemetrieRecente = $stmt->fetchAll();

$pageTitle = 'Fiche véhicule — ' . $vehicule['immatriculation'];
$csrfToken = generateCsrfToken();
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#128666; <?= htmlspecialchars($vehicule['immatriculation']) ?></h1>
        <p class="subtitle"><?= htmlspecialchars($vehicule['marque'] . ' ' . $vehicule['modele']) ?> (<?= $vehicule['annee'] ?>) — <?= ucfirst($vehicule['type']) ?></p>
    </div>
    <div class="dash-actions">
        <button class="btn-dash btn-dash-outline" onclick="document.getElementById('modalModifier').style.display='flex'">
            &#9998; Modifier
        </button>
        <a href="vehicules.php" class="btn-dash btn-dash-outline">&#8592; Retour à la liste</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- ====== LAYOUT FICHE : COLONNE GAUCHE + COLONNE DROITE ====== -->
<div style="display:grid;grid-template-columns:240px 1fr;gap:20px;margin-bottom:16px;">

    <!-- Colonne gauche : icône camion + modèle + état moteur -->
    <div class="panel" style="padding:24px;text-align:center;">

        <?php $moteurOn = ($dernierReleve['moteur_etat'] ?? 'off') === 'on'; ?>
        <?php $couleurIcon = $vehicule['statut'] === 'actif' ? '#1D9E75' : '#BA7517'; ?>

        <?php if (!empty($vehicule['photo'])): ?>
            <img src="../<?= htmlspecialchars($vehicule['photo']) ?>"
                 alt="Photo du véhicule"
                 style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:3px solid var(--color-border);margin:0 auto 16px;display:block;">
        <?php elseif ($vehicule['type'] === 'minier'): ?>
            <div style="width:120px;height:120px;border-radius:50%;background:#E1F5EE;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;border:3px solid var(--color-border);">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="<?= $couleurIcon ?>">
                    <path d="M2 17h1.5a2.5 2.5 0 0 0 4.9 0H15a2.5 2.5 0 0 0 4.9 0H22v-3l-2-4h-3V7a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v3H2v7z"/>
                    <circle cx="6" cy="18" r="1.6" fill="#085041"/>
                    <circle cx="17" cy="18" r="1.6" fill="#085041"/>
                </svg>
            </div>
        <?php else: ?>
            <div style="width:120px;height:120px;border-radius:50%;background:#E1F5EE;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;border:3px solid var(--color-border);">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="<?= $couleurIcon ?>">
                    <path d="M3 16V8a1 1 0 011-1h9l5 4v5a1 1 0 01-1 1H4a1 1 0 01-1-1z"/>
                    <circle cx="7" cy="17.5" r="1.6" fill="#085041"/>
                    <circle cx="17" cy="17.5" r="1.6" fill="#085041"/>
                </svg>
            </div>
        <?php endif; ?>

        <h3 style="margin-bottom:4px;font-size:16px;font-weight:700;text-align:center;">
            <?= htmlspecialchars($vehicule['immatriculation']) ?>
        </h3>
        <p style="font-size:13px;color:#5C6B68;margin-bottom:12px;text-align:center;">
            <?= htmlspecialchars($vehicule['marque'] . ' ' . $vehicule['modele']) ?>
        </p>

        <div style="display:flex;justify-content:center;margin-bottom:14px;">
            <span class="badge <?= $moteurOn ? 'badge-success' : 'badge-muted' ?>" style="font-size:13px;padding:6px 16px;">
                <?= $moteurOn ? '&#128268; Moteur ON' : '&#9211; Moteur OFF' ?>
            </span>
        </div>

        <div style="display:flex;justify-content:center;margin-bottom:16px;">
            <a href="#" onclick="document.getElementById('modalPhoto').style.display='flex'; return false;"
               style="color:#0F6E56;font-size:12px;font-weight:600;text-decoration:none;">
                &#128247; Ajouter / changer la photo
            </a>
        </div>

        <div style="padding-top:14px;border-top:1px solid var(--color-border);text-align:left;">
            <p style="font-size:12px;color:#999;margin-bottom:2px;">Type</p>
            <p style="font-size:13px;font-weight:600;margin-bottom:10px;"><?= ucfirst($vehicule['type']) ?></p>

            <p style="font-size:12px;color:#999;margin-bottom:2px;">Année</p>
            <p style="font-size:13px;font-weight:600;margin-bottom:10px;"><?= $vehicule['annee'] ?></p>

            <p style="font-size:12px;color:#999;margin-bottom:2px;">Réservoir</p>
            <p style="font-size:13px;font-weight:600;margin-bottom:10px;"><?= $vehicule['capacite_carburant'] ?> L</p>

            <?php if ($vehicule['type'] === 'minier'): ?>
            <p style="font-size:12px;color:#999;margin-bottom:2px;">Nb. roues</p>
            <p style="font-size:13px;font-weight:600;"><?= $vehicule['nb_roues'] ?? 6 ?> roues</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Colonne droite : KPI statut, carburant, superviseur, télémétrie -->
    <div class="panel" style="padding:24px;">
        <h3 style="margin-bottom:18px;">Vue d'ensemble</h3>
        <div class="kpi-grid">

            <div class="kpi-card <?= $vehicule['statut'] === 'actif' ? '' : 'warning' ?>">
                <div class="kpi-info">
                    <p class="label">Statut</p>
                    <p class="value" style="font-size:18px;"><?= ucfirst($vehicule['statut']) ?></p>
                    <p class="trend"><?= $vehicule['statut'] === 'actif' ? 'Opérationnel' : 'Hors service' ?></p>
                </div>
                <div class="kpi-icon">&#9989;</div>
            </div>

            <div class="kpi-card <?= ($dernierReleve['carburant'] ?? 100) < 10 ? 'danger' : '' ?>">
                <div class="kpi-info">
                    <p class="label">Carburant</p>
                    <p class="value" style="font-size:18px;"><?= $dernierReleve ? round($dernierReleve['carburant']) . ' %' : '—' ?></p>
                    <p class="trend"><?= ($dernierReleve['carburant'] ?? 100) < 10 ? 'Niveau critique' : 'Niveau normal' ?></p>
                </div>
                <div class="kpi-icon">&#128167;</div>
            </div>

            <!-- Superviseur : photo ou initiales -->
            <div class="kpi-card">
                <div class="kpi-info">
                    <p class="label">Superviseur</p>
                    <p class="value" style="font-size:14px;line-height:1.3;">
                        <?= $vehicule['sup_nom'] ? htmlspecialchars($vehicule['sup_prenom'] . ' ' . $vehicule['sup_nom']) : '—' ?>
                    </p>
                    <p class="trend"><?= $vehicule['sup_nom'] ? 'Assigné' : 'Non assigné' ?></p>
                </div>
                <div class="kpi-icon" style="padding:0;">
                    <?php if (!empty($vehicule['sup_photo'])): ?>
                        <img src="../<?= htmlspecialchars($vehicule['sup_photo']) ?>"
                             alt="Photo superviseur"
                             style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--color-border);">
                    <?php elseif ($vehicule['sup_nom']): ?>
                        <div style="width:40px;height:40px;border-radius:50%;background:#E1F5EE;color:#0F6E56;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;">
                            <?= strtoupper(substr($vehicule['sup_prenom'], 0, 1) . substr($vehicule['sup_nom'], 0, 1)) ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:24px;">&#128100;</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-info">
                    <p class="label">Relevés télémétrie</p>
                    <p class="value" style="font-size:18px;"><?= number_format($statsGlobales['nb_releves'], 0, ',', ' ') ?></p>
                    <p class="trend">Total historique</p>
                </div>
                <div class="kpi-icon">&#128225;</div>
            </div>

            <div class="kpi-card <?= ($dernierReleve['temperature_moteur'] ?? 0) > 95 ? 'danger' : '' ?>">
                <div class="kpi-info">
                    <p class="label">Température moteur</p>
                    <p class="value" style="font-size:18px;"><?= $dernierReleve ? round($dernierReleve['temperature_moteur']) . ' °C' : '—' ?></p>
                    <p class="trend"><?= ($dernierReleve['temperature_moteur'] ?? 0) > 95 ? 'Critique !' : 'Normale' ?></p>
                </div>
                <div class="kpi-icon">&#127777;</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-info">
                    <p class="label">Vitesse actuelle</p>
                    <p class="value" style="font-size:18px;"><?= $dernierReleve ? round($dernierReleve['vitesse']) . ' km/h' : '—' ?></p>
                    <p class="trend">Dernier relevé</p>
                </div>
                <div class="kpi-icon">&#128739;</div>
            </div>

            <!-- TPMS : pression des pneus — miniers uniquement -->
            <?php if ($vehicule['type'] === 'minier'): ?>
            <?php
                $tpmsData = !empty($dernierReleve['tpms_pression_json'])
                    ? json_decode($dernierReleve['tpms_pression_json'], true)
                    : null;
                $tpmsAlerte = !empty($dernierReleve['tpms_alerte']);
            ?>
            <div class="kpi-card <?= $tpmsAlerte ? 'danger' : '' ?>" style="grid-column:span 2;">
                <div class="kpi-info" style="width:100%;">
                    <p class="label">Pression des pneus (TPMS)</p>
                    <?php if ($tpmsData): ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                            <?php foreach ($tpmsData as $roue): ?>
                                <?php
                                    $p = (float) $roue['pression'];
                                    $t = (float) ($roue['temperature'] ?? 0);
                                    $ok = $p >= 6.5 && $p <= 9.0 && $t <= 70;
                                    $c = $ok ? '#1D9E75' : '#E24B4A';
                                ?>
                                <div style="background:#F5F7F6;border:1px solid <?= $c ?>;border-radius:6px;padding:6px 10px;text-align:center;min-width:58px;">
                                    <p style="font-size:10px;color:#999;margin-bottom:2px;">R<?= $roue['roue'] ?></p>
                                    <p style="font-size:13px;font-weight:700;color:<?= $c ?>;"><?= $p ?> bar</p>
                                    <p style="font-size:10px;color:#5C6B68;"><?= $t ?>°C</p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="trend" style="margin-top:6px;"><?= $tpmsAlerte ? '&#9888; Anomalie détectée sur un ou plusieurs pneus' : 'Tous les pneus sont dans les normes' ?></p>
                    <?php else: ?>
                        <p class="value" style="font-size:14px;color:#999;margin-top:4px;">Aucune donnée TPMS disponible</p>
                        <p class="trend">En attente du capteur</p>
                    <?php endif; ?>
                </div>
                <div class="kpi-icon">&#127919;</div>
            </div>
            <?php endif; ?>

        </div>

        <?php if ($dernierReleve): ?>
            <p style="font-size:11px;color:#999;margin-top:12px;text-align:right;">
                Dernier relevé : <?= date('d/m/Y à H:i', strtotime($dernierReleve['horodatage'])) ?>
            </p>
        <?php endif; ?>
    </div>

</div>

<?php if ($vehicule['type'] === 'minier'): ?>
<!-- ====== SECTION POIDS / CHARGE (MINIER UNIQUEMENT) ====== -->
<div class="panel" style="padding:20px 24px;margin-bottom:16px;border-left:4px solid #0F6E56;">
    <h3 style="margin-bottom:16px;font-size:15px;">&#9878; Gestion du chargement</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:20px;">

        <div>
            <p style="font-size:12px;color:#999;margin-bottom:4px;">Poids à vide (tare)</p>
            <p style="font-weight:700;font-size:18px;color:#0F6E56;">
                <?= $vehicule['poids_tare_kg'] ? number_format($vehicule['poids_tare_kg'], 0, ',', ' ') . ' kg' : '<span style="color:#999;font-size:14px;">Non renseigné</span>' ?>
            </p>
        </div>

        <div>
            <p style="font-size:12px;color:#999;margin-bottom:4px;">Charge max autorisée</p>
            <p style="font-weight:700;font-size:18px;color:#0F6E56;">
                <?= $vehicule['poids_max_kg'] ? number_format($vehicule['poids_max_kg'], 0, ',', ' ') . ' kg' : '<span style="color:#999;font-size:14px;">Non renseigné</span>' ?>
            </p>
        </div>

        <div>
            <p style="font-size:12px;color:#999;margin-bottom:4px;">Charge nette actuelle</p>
            <?php
                $chargeActuelle = $dernierReleve['poids_charge_kg'] ?? null;
                $chargeMax = $vehicule['poids_max_kg'] ?? null;
                $surcharge = $chargeActuelle && $chargeMax && $chargeActuelle > $chargeMax;
            ?>
            <p style="font-weight:700;font-size:18px;color:<?= $surcharge ? '#E24B4A' : '#1A1A1A' ?>;">
                <?= $chargeActuelle !== null ? number_format($chargeActuelle, 0, ',', ' ') . ' kg' : '<span style="color:#999;font-size:14px;">—</span>' ?>
                <?php if ($surcharge): ?>
                    <span style="font-size:11px;color:#E24B4A;">&#9888; SURCHARGE</span>
                <?php endif; ?>
            </p>
            <?php if ($chargeActuelle !== null && $chargeMax): ?>
                <?php $pct = min(100, round($chargeActuelle / $chargeMax * 100)); ?>
                <div style="background:#E9ECEC;border-radius:4px;height:6px;margin-top:6px;">
                    <div style="background:<?= $pct > 100 ? '#E24B4A' : ($pct > 85 ? '#BA7517' : '#1D9E75') ?>;width:<?= $pct ?>%;height:6px;border-radius:4px;"></div>
                </div>
                <p style="font-size:11px;color:#5C6B68;margin-top:4px;"><?= $pct ?>% de la capacité max</p>
            <?php endif; ?>
        </div>

        <?php if ($statsPoidsCharge): ?>
        <div>
            <p style="font-size:12px;color:#999;margin-bottom:4px;">Charge moy. (30 jours)</p>
            <p style="font-weight:700;font-size:18px;">
                <?= $statsPoidsCharge['poids_moy'] ? number_format($statsPoidsCharge['poids_moy'], 0, ',', ' ') . ' kg' : '—' ?>
            </p>
            <p style="font-size:11px;color:#5C6B68;"><?= $statsPoidsCharge['nb_chargements'] ?> chargements</p>
        </div>

        <div>
            <p style="font-size:12px;color:#999;margin-bottom:4px;">Charge max enregistrée</p>
            <p style="font-weight:700;font-size:18px;">
                <?= $statsPoidsCharge['poids_max'] ? number_format($statsPoidsCharge['poids_max'], 0, ',', ' ') . ' kg' : '—' ?>
            </p>
        </div>
        <?php endif; ?>

    </div>

    <?php if (!$vehicule['poids_tare_kg']): ?>
        <div style="margin-top:14px;padding:10px 14px;background:#FAEEDA;color:#633806;border-radius:6px;font-size:13px;">
            &#9888; La tare (poids à vide) n'est pas encore renseignée pour ce véhicule. Le calcul de la charge nette ne sera pas possible tant que ce champ n'est pas rempli dans la fiche véhicule.
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($vehicule['proprietaire_nom'])): ?>
<!-- ====== SECTION INVESTISSEUR ====== -->
<div class="panel" style="padding:20px 24px;margin-bottom:16px;border-left:4px solid #7C5CBF;">
    <h3 style="margin-bottom:16px;font-size:15px;">&#128100; Propriétaire / Investisseur</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;">
        <div>
            <p style="font-size:12px;color:#999;margin-bottom:4px;">Nom / Société</p>
            <p style="font-weight:600;"><?= htmlspecialchars($vehicule['proprietaire_nom']) ?></p>
        </div>
        <?php if ($vehicule['proprietaire_contact_email']): ?>
        <div>
            <p style="font-size:12px;color:#999;margin-bottom:4px;">Email de contact</p>
            <p style="font-weight:600;">
                <a href="mailto:<?= htmlspecialchars($vehicule['proprietaire_contact_email']) ?>" style="color:#0F6E56;">
                    <?= htmlspecialchars($vehicule['proprietaire_contact_email']) ?>
                </a>
            </p>
        </div>
        <?php endif; ?>
        <?php if ($vehicule['proprietaire_contact_tel']): ?>
        <div>
            <p style="font-size:12px;color:#999;margin-bottom:4px;">Téléphone</p>
            <p style="font-weight:600;"><?= htmlspecialchars($vehicule['proprietaire_contact_tel']) ?></p>
        </div>
        <?php endif; ?>
        <?php if (!empty($vehicule['contrat_debut'])): ?>
        <div>
            <p style="font-size:12px;color:#999;margin-bottom:4px;">Début du contrat</p>
            <p style="font-weight:600;"><?= date('d/m/Y', strtotime($vehicule['contrat_debut'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ====== KPI HISTORIQUE GLOBAL ====== -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Kilométrage total (cumulé)</p>
            <p class="value"><?= number_format($kmTotal, 0, ',', ' ') ?> km</p>
            <p class="trend">Depuis le premier relevé</p>
        </div>
        <div class="kpi-icon">&#128739;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Missions effectuées</p>
            <p class="value"><?= $nbMissions ?></p>
            <p class="trend">Total historique</p>
        </div>
        <div class="kpi-icon">&#128203;</div>
    </div>
    <div class="kpi-card <?= $nbAlertesActives > 0 ? 'danger' : '' ?>">
        <div class="kpi-info">
            <p class="label">Alertes (total / actives)</p>
            <p class="value"><?= $nbAlertes ?> / <?= $nbAlertesActives ?></p>
            <p class="trend"><?= $nbAlertesActives > 0 ? 'Intervention requise' : 'Aucune alerte active' ?></p>
        </div>
        <div class="kpi-icon">&#128276;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Vitesse / Température moy.</p>
            <p class="value"><?= round($statsGlobales['vitesse_moy'] ?? 0) ?> km/h</p>
            <p class="trend"><?= round($statsGlobales['temp_moy'] ?? 0) ?>°C en moyenne</p>
        </div>
        <div class="kpi-icon">&#127777;</div>
    </div>
</div>

<!-- ====== GRAPHIQUE TÉLÉMÉTRIE 24H ====== -->
<div class="panel" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#128202; Télémétrie — 24 dernières heures</h3>
    </div>
    <div style="padding:16px;">
        <?php if (empty($telemetrieRecente)): ?>
            <p style="text-align:center;color:#999;padding:30px;">Aucune donnée de télémétrie sur les dernières 24 heures.</p>
        <?php else: ?>
            <canvas id="telemetrieChart" height="90"></canvas>
            <?php if ($vehicule['type'] === 'minier'): ?>
                <canvas id="poidsChart" height="60" style="margin-top:20px;"></canvas>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ====== HISTORIQUE MISSIONS ====== -->
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#128203; Historique des missions (20 dernières)</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Conducteur</th>
                <th>Départ</th>
                <th>Destination</th>
                <th>Date début</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($missions)): ?>
                <tr><td colspan="5" style="text-align:center;padding:24px;color:#999;">Aucune mission enregistrée pour ce véhicule.</td></tr>
            <?php endif; ?>
            <?php foreach ($missions as $m): ?>
                <?php
                    $statutBadge = ['planifiee' => 'badge-warning', 'en_cours' => 'badge-success', 'terminee' => 'badge-success', 'annulee' => 'badge-danger'];
                    $statutLabel = ['planifiee' => 'Planifiée', 'en_cours' => 'En cours', 'terminee' => 'Terminée', 'annulee' => 'Annulée'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($m['c_prenom'] . ' ' . $m['c_nom']) ?></td>
                    <td><?= htmlspecialchars($m['lieu_depart']) ?></td>
                    <td><?= htmlspecialchars($m['lieu_destination']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($m['date_debut'])) ?></td>
                    <td><span class="badge <?= $statutBadge[$m['statut']] ?>"><?= $statutLabel[$m['statut']] ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== HISTORIQUE ALERTES ====== -->
<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>&#128276; Historique des alertes (20 dernières)</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Type</th>
                <th>Valeur déclenchante</th>
                <th>Date</th>
                <th>Statut</th>
                <th>Traité par</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($alertes)): ?>
                <tr><td colspan="5" style="text-align:center;padding:24px;color:#999;">Aucune alerte enregistrée pour ce véhicule.</td></tr>
            <?php endif; ?>
            <?php foreach ($alertes as $a): ?>
                <?php
                    $statutBadge = ['non_traitee' => 'badge-danger', 'en_cours' => 'badge-warning', 'resolue' => 'badge-success'];
                    $statutLabel = ['non_traitee' => 'Non traitée', 'en_cours' => 'En cours', 'resolue' => 'Résolue'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($libellesAlertes[$a['type_alerte']] ?? ucfirst(str_replace('_', ' ', $a['type_alerte']))) ?></td>
                    <td><?= htmlspecialchars(formaterValeurAlerte($a['type_alerte'], $a['valeur_declenchante'])) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($a['horodatage'])) ?></td>
                    <td><span class="badge <?= $statutBadge[$a['statut']] ?>"><?= $statutLabel[$a['statut']] ?></span></td>
                    <td>
                        <?php if ($a['traitant_nom']): ?>
                            <?= htmlspecialchars($a['traitant_prenom'] . ' ' . $a['traitant_nom']) ?>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($telemetrieRecente)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    var telemetrieData = <?= json_encode($telemetrieRecente) ?>;
    var estMinier = <?= $vehicule['type'] === 'minier' ? 'true' : 'false' ?>;
    var poidsTare = <?= $vehicule['poids_tare_kg'] ?? 'null' ?>;
    var poidsMax  = <?= $vehicule['poids_max_kg']  ?? 'null' ?>;

    var labels = telemetrieData.map(function (t) {
        var d = new Date(t.horodatage.replace(' ', 'T'));
        return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    });

    // Graphique vitesse / température / carburant
    new Chart(document.getElementById('telemetrieChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Vitesse (km/h)',
                    data: telemetrieData.map(function (t) { return t.vitesse; }),
                    borderColor: '#1D9E75',
                    backgroundColor: 'rgba(29,158,117,0.08)',
                    tension: 0.3, yAxisID: 'y'
                },
                {
                    label: 'Température (°C)',
                    data: telemetrieData.map(function (t) { return t.temperature_moteur; }),
                    borderColor: '#E24B4A',
                    backgroundColor: 'rgba(226,75,74,0.08)',
                    tension: 0.3, yAxisID: 'y'
                },
                {
                    label: 'Carburant (%)',
                    data: telemetrieData.map(function (t) { return t.carburant; }),
                    borderColor: '#BA7517',
                    backgroundColor: 'rgba(186,117,23,0.08)',
                    tension: 0.3, yAxisID: 'y'
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: { y: { beginAtZero: true } }
        }
    });

    // Graphique poids de charge (minier uniquement)
    if (estMinier && document.getElementById('poidsChart')) {
        var datasets = [{
            label: 'Charge nette (kg)',
            data: telemetrieData.map(function (t) { return t.poids_charge_kg; }),
            borderColor: '#0F6E56',
            backgroundColor: 'rgba(15,110,86,0.1)',
            tension: 0.3, fill: true
        }];

        if (poidsMax) {
            datasets.push({
                label: 'Charge max autorisée (kg)',
                data: telemetrieData.map(function () { return poidsMax; }),
                borderColor: '#E24B4A',
                borderDash: [6, 3],
                pointRadius: 0,
                tension: 0
            });
        }

        new Chart(document.getElementById('poidsChart'), {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: { title: { display: true, text: 'Poids de charge — 24 dernières heures' } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }
</script>
<?php endif; ?>

<!-- ====== MODAL MODIFICATION VÉHICULE ====== -->
<!-- Modal upload photo -->
<div id="modalPhoto" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:400px;">
        <h3 style="margin-bottom:18px;font-size:17px;">&#128247; Photo du véhicule</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_photo">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="form-group">
                <label>Choisir une photo (JPG, PNG, WEBP — max 5 Mo)</label>
                <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" required>
            </div>
            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;"
                        onclick="document.getElementById('modalPhoto').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<div id="modalModifier" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:18px;font-size:17px;">&#9998; Modifier le véhicule</h3>
        <form method="POST">
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-group">
                <label>Immatriculation *</label>
                <input type="text" name="immatriculation" required value="<?= htmlspecialchars($vehicule['immatriculation']) ?>">
            </div>

            <div class="contact-grid">
                <div class="form-group">
                    <label>Marque *</label>
                    <input type="text" name="marque" required value="<?= htmlspecialchars($vehicule['marque']) ?>">
                </div>
                <div class="form-group">
                    <label>Modèle *</label>
                    <input type="text" name="modele" required value="<?= htmlspecialchars($vehicule['modele']) ?>">
                </div>
            </div>

            <div class="contact-grid">
                <div class="form-group">
                    <label>Année *</label>
                    <input type="number" name="annee" required min="1990" max="<?= date('Y') + 1 ?>" value="<?= $vehicule['annee'] ?>">
                </div>
                <div class="form-group">
                    <label>Type *</label>
                    <select name="type" id="editSelectType" required onchange="gererTypeEdit()">
                        <option value="minier"  <?= $vehicule['type'] === 'minier'  ? 'selected' : '' ?>>Minier</option>
                        <option value="routier" <?= $vehicule['type'] === 'routier' ? 'selected' : '' ?>>Routier</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Capacité réservoir (L)</label>
                <input type="number" name="capacite_carburant" min="20" value="<?= $vehicule['capacite_carburant'] ?>">
            </div>

            <!-- Section minier -->
            <div id="editSectionMinier" style="border:1px solid #E1F5EE;border-radius:8px;padding:16px;margin-bottom:14px;background:#F9FFFE;">
                <p style="font-size:12px;font-weight:600;color:#0F6E56;margin-bottom:12px;">&#9881; Paramètres camion minier</p>
                <div class="contact-grid">
                    <div class="form-group">
                        <label>Poids à vide / Tare (kg)</label>
                        <input type="number" name="poids_tare_kg" min="0"
                               value="<?= $vehicule['poids_tare_kg'] ?? '' ?>"
                               placeholder="ex: 65000">
                    </div>
                    <div class="form-group">
                        <label>Charge max autorisée (kg)</label>
                        <input type="number" name="poids_max_kg" min="0"
                               value="<?= $vehicule['poids_max_kg'] ?? '' ?>"
                               placeholder="ex: 90000">
                    </div>
                </div>
                <div class="form-group">
                    <label>Nombre de roues</label>
                    <select name="nb_roues">
                        <option value="4"  <?= ($vehicule['nb_roues'] ?? 6) == 4  ? 'selected' : '' ?>>4 roues</option>
                        <option value="6"  <?= ($vehicule['nb_roues'] ?? 6) == 6  ? 'selected' : '' ?>>6 roues</option>
                        <option value="10" <?= ($vehicule['nb_roues'] ?? 6) == 10 ? 'selected' : '' ?>>10 roues</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Superviseur assigné</label>
                <select name="id_superviseur">
                    <option value="">— Non assigné —</option>
                    <?php foreach ($superviseurs as $s): ?>
                        <option value="<?= $s['id_user'] ?>" <?= $vehicule['id_superviseur'] == $s['id_user'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Section appartenance -->
            <div class="form-group">
                <label>Ce véhicule appartient à *</label>
                <select name="appartenance" id="editSelectAppartenance" onchange="gererAppartenanceEdit()">
                    <option value="entreprise" <?= empty($vehicule['proprietaire_nom']) ? 'selected' : '' ?>>L'entreprise (Simandou 2040)</option>
                    <option value="investisseur" <?= !empty($vehicule['proprietaire_nom']) ? 'selected' : '' ?>>Un investisseur</option>
                </select>
            </div>

            <!-- Section investisseur -->
            <div id="editSectionInvestisseur" style="display:<?= !empty($vehicule['proprietaire_nom']) ? 'block' : 'none' ?>;border:1px solid #E8E0F5;border-radius:8px;padding:16px;margin-bottom:14px;background:#FAF8FF;">
                <p style="font-size:12px;font-weight:600;color:#7C5CBF;margin-bottom:12px;">&#128100; Informations de l'investisseur</p>
                <div class="form-group">
                    <label>Nom complet / Société *</label>
                    <input type="text" name="proprietaire_nom" id="editInputProprietaireNom"
                           value="<?= htmlspecialchars($vehicule['proprietaire_nom'] ?? '') ?>"
                           placeholder="ex: Moussa Camara ou Société Minière SA">
                </div>
                <div class="contact-grid">
                    <div class="form-group">
                        <label>Email de contact</label>
                        <input type="email" name="proprietaire_contact_email"
                               value="<?= htmlspecialchars($vehicule['proprietaire_contact_email'] ?? '') ?>"
                               placeholder="investisseur@email.com">
                    </div>
                    <div class="form-group">
                        <label>Téléphone</label>
                        <input type="tel" name="proprietaire_contact_tel"
                               value="<?= htmlspecialchars($vehicule['proprietaire_contact_tel'] ?? '') ?>"
                               placeholder="+224 6XX XXX XXX">
                    </div>
                    <div class="form-group">
                        <label>Date de début du contrat</label>
                        <input type="date" name="contrat_debut"
                               value="<?= htmlspecialchars($vehicule['contrat_debut'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;"
                        onclick="document.getElementById('modalModifier').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Enregistrer les modifications</button>
            </div>
        </form>
    </div>
</div>

<script>
function gererTypeEdit() {
    var type = document.getElementById('editSelectType').value;
    document.getElementById('editSectionMinier').style.display = type === 'minier' ? 'block' : 'none';
}

function gererAppartenanceEdit() {
    var val = document.getElementById('editSelectAppartenance').value;
    var section = document.getElementById('editSectionInvestisseur');
    var input   = document.getElementById('editInputProprietaireNom');
    section.style.display = val === 'investisseur' ? 'block' : 'none';
    input.required = val === 'investisseur';
}

// Initialisation
gererTypeEdit();
</script>

<?php require_once '../includes/footer.php'; ?>
