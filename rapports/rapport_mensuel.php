<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : rapports/rapport_mensuel.php
// Rôle    : Rapport mensuel officiel pour les autorités/régulateurs
//           (contenu local, sécurité, activité, conformité)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin(); // rapport officiel : réservé à l'administration

$mois = $_GET['mois'] ?? date('Y-m');
$dateDebut = $mois . '-01';
$dateFin = date('Y-m-t', strtotime($dateDebut));

// ====== CONTENU LOCAL (priorité officielle Simandou 2040) ======
$stmt = $pdo->query("SELECT COUNT(*) FROM conducteurs WHERE statut = 'actif'");
$nbConducteursTotal = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM conducteurs WHERE statut = 'actif' AND nationalite = 'Guinéenne'");
$nbConducteursGuineens = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM conducteurs WHERE statut = 'actif' AND formation_academy = 'certifie'");
$nbFormesAcademy = $stmt->fetchColumn();

$pctGuineens = $nbConducteursTotal > 0 ? round(($nbConducteursGuineens / $nbConducteursTotal) * 100) : 0;
$pctFormes = $nbConducteursTotal > 0 ? round(($nbFormesAcademy / $nbConducteursTotal) * 100) : 0;

// ====== ACTIVITÉ DE LA FLOTTE SUR LE MOIS ======
$stmt = $pdo->query("SELECT COUNT(*) FROM vehicules WHERE statut = 'actif'");
$nbVehicules = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM missions WHERE date_debut BETWEEN ? AND ?
");
$stmt->execute([$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
$nbMissions = $stmt->fetchColumn();

// Distance totale (somme des delta km par véhicule sur le mois)
$stmt = $pdo->prepare("
    SELECT SUM(km_max - km_min) FROM (
        SELECT id_vehicule, MAX(kilometrage) AS km_max, MIN(kilometrage) AS km_min
        FROM telemetrie
        WHERE horodatage BETWEEN ? AND ?
        GROUP BY id_vehicule
    ) t
");
$stmt->execute([$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
$kmTotal = (float) $stmt->fetchColumn();

// ====== SÉCURITÉ : ALERTES DU MOIS ======
$stmt = $pdo->prepare("SELECT COUNT(*) FROM alertes WHERE horodatage BETWEEN ? AND ?");
$stmt->execute([$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
$nbAlertesTotal = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM alertes WHERE horodatage BETWEEN ? AND ? AND statut = 'resolue'");
$stmt->execute([$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
$nbAlertesResolues = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT type_alerte, COUNT(*) AS nb FROM alertes
    WHERE horodatage BETWEEN ? AND ?
    GROUP BY type_alerte
");
$stmt->execute([$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
$repartitionAlertes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$tauxResolution = $nbAlertesTotal > 0 ? round(($nbAlertesResolues / $nbAlertesTotal) * 100) : 100;

// ====== ESTIMATION ENVIRONNEMENTALE (CO2) ======
// Estimation simplifiée : ~2.68 kg de CO2 par litre de diesel consommé
$coefConso = ['minier' => 0.35, 'routier' => 0.12];
$stmtVeh = $pdo->query("SELECT id_vehicule, type, capacite_carburant FROM vehicules WHERE statut = 'actif'");
$litresTotal = 0;
foreach ($stmtVeh->fetchAll() as $v) {
    $stmt = $pdo->prepare("SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?");
    $stmt->execute([$v['id_vehicule'], $dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
    $res = $stmt->fetch();
    $distance = ($res['km_min'] !== null) ? max(0, $res['km_max'] - $res['km_min']) : 0;
    $coef = $coefConso[$v['type']] ?? 0.15;
    $litresTotal += $distance * ($coef / 100) * $v['capacite_carburant'];
}
$co2EstimeKg = round($litresTotal * 2.68, 0);

// ====== VÉHICULES INVESTISSEURS ======
$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.marque, v.modele, v.type,
           v.proprietaire_nom, v.proprietaire_contact_email, v.proprietaire_contact_tel,
           v.contrat_debut, v.poids_max_kg,
           u.nom AS sup_nom, u.prenom AS sup_prenom, u.email AS sup_email
    FROM vehicules v
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    WHERE v.proprietaire_nom IS NOT NULL AND v.proprietaire_nom != ''
    AND v.statut = 'actif'
    ORDER BY v.immatriculation
");
$stmt->execute();
$vehiculesInvestisseurs = $stmt->fetchAll();

// Pour chaque véhicule investisseur, calculer les stats du mois sélectionné
$statsInvestisseurs = [];
foreach ($vehiculesInvestisseurs as $v) {
    $debut = $dateDebut . ' 00:00:00';
    $fin   = $dateFin   . ' 23:59:59';

    // Vérifier que le mois est >= début du contrat
    if (!empty($v['contrat_debut']) && $dateDebut < $v['contrat_debut']) {
        $statsInvestisseurs[$v['id_vehicule']] = null; // mois avant contrat
        continue;
    }

    $stmt = $pdo->prepare("SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max, COUNT(*) AS nb_releves FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?");
    $stmt->execute([$v['id_vehicule'], $debut, $fin]);
    $km = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM missions WHERE id_vehicule = ? AND date_debut BETWEEN ? AND ?");
    $stmt->execute([$v['id_vehicule'], $debut, $fin]);
    $nbMissionsV = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM alertes WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?");
    $stmt->execute([$v['id_vehicule'], $debut, $fin]);
    $nbAlertesV = $stmt->fetchColumn();

    $tonnage = null;
    if ($v['type'] === 'minier') {
        $stmt = $pdo->prepare("SELECT SUM(poids_charge_kg)/1000 FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?");
        $stmt->execute([$v['id_vehicule'], $debut, $fin]);
        $tonnage = round((float) $stmt->fetchColumn(), 1);
    }

    $statsInvestisseurs[$v['id_vehicule']] = [
        'km'        => ($km['km_min'] !== null) ? max(0, $km['km_max'] - $km['km_min']) : 0,
        'missions'  => $nbMissionsV,
        'alertes'   => $nbAlertesV,
        'tonnage'   => $tonnage,
        'releves'   => $km['nb_releves'],
    ];
}

// Générer la liste des mois disponibles depuis le contrat le plus ancien
$contratLePlusAncien = null;
foreach ($vehiculesInvestisseurs as $v) {
    if (!empty($v['contrat_debut'])) {
        if ($contratLePlusAncien === null || $v['contrat_debut'] < $contratLePlusAncien) {
            $contratLePlusAncien = $v['contrat_debut'];
        }
    }
}

$pageTitle = 'Rapport mensuel officiel';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Rapport mensuel d'activité</h1>
        <p class="subtitle">Document destiné aux autorités et partenaires — Programme Simandou 2040</p>
    </div>
    <div class="dash-actions">
        <form method="GET" style="display:flex;gap:8px;">
            <input type="month" name="mois" value="<?= htmlspecialchars($mois) ?>" onchange="this.form.submit()">
        </form>
        <a href="export_rapport_mensuel_pdf.php?mois=<?= htmlspecialchars($mois) ?>" class="btn-dash btn-dash-primary">Exporter en PDF</a>
    </div>
</div>

<?php if (!empty($_GET['statut'])): ?>
    <?php $ok = $_GET['statut'] === 'email_ok'; ?>
    <div style="padding:12px 16px;margin-bottom:14px;background:<?= $ok ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $ok ? '#085041' : '#A32D2D' ?>;border-radius:6px;border:none;">
        <?php if ($ok): ?>
            &#9989; Rapport PDF envoyé avec succès au superviseur pour le véhicule <strong><?= htmlspecialchars(urldecode($_GET['immat'] ?? '')) ?></strong>
        <?php else: ?>
            &#10060; Erreur lors de l'envoi email pour <strong><?= htmlspecialchars(urldecode($_GET['immat'] ?? '')) ?></strong>
            <?php if (!empty($_GET['erreur'])): ?>
                <br><small style="font-size:11px;opacity:0.8;">Détail : <?= htmlspecialchars(urldecode($_GET['erreur'])) ?></small>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="panel" style="padding:16px 20px;margin-bottom:16px;background:#F5F7F6;border:none;">
    <p style="font-size:13px;color:#5C6B68;">
        Période : <strong><?= date('F Y', strtotime($dateDebut)) ?></strong> (du <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?>)
    </p>
</div>

<!-- ====== CONTENU LOCAL ====== -->
<div class="panel" style="padding:24px;margin-bottom:16px;">
    <h3 style="margin-bottom:18px;">&#127470;&#127480; Contenu local (conformité Simandou 2040)</h3>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-info">
                <p class="label">Conducteurs guinéens</p>
                <p class="value"><?= $pctGuineens ?>%</p>
                <p class="trend"><?= $nbConducteursGuineens ?> / <?= $nbConducteursTotal ?> conducteurs actifs</p>
            </div>
            <div class="kpi-icon">&#128100;</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <p class="label">Formés Simandou Academy</p>
                <p class="value"><?= $pctFormes ?>%</p>
                <p class="trend"><?= $nbFormesAcademy ?> conducteur(s) certifié(s)</p>
            </div>
            <div class="kpi-icon">&#127891;</div>
        </div>
    </div>
</div>

<!-- ====== ACTIVITÉ ====== -->
<div class="panel" style="padding:24px;margin-bottom:16px;">
    <h3 style="margin-bottom:18px;">&#128666; Activité de la flotte</h3>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-info">
                <p class="label">Véhicules actifs</p>
                <p class="value"><?= $nbVehicules ?></p>
            </div>
            <div class="kpi-icon">&#128666;</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <p class="label">Missions réalisées</p>
                <p class="value"><?= $nbMissions ?></p>
            </div>
            <div class="kpi-icon">&#128203;</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <p class="label">Distance parcourue</p>
                <p class="value"><?= number_format($kmTotal, 0, ',', ' ') ?> km</p>
            </div>
            <div class="kpi-icon">&#128739;</div>
        </div>
    </div>
</div>

<!-- ====== SÉCURITÉ ====== -->
<div class="panel" style="padding:24px;margin-bottom:16px;">
    <h3 style="margin-bottom:18px;">&#128276; Sécurité et alertes</h3>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-info">
                <p class="label">Alertes déclenchées</p>
                <p class="value"><?= $nbAlertesTotal ?></p>
            </div>
            <div class="kpi-icon">&#9888;</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <p class="label">Taux de résolution</p>
                <p class="value"><?= $tauxResolution ?>%</p>
            </div>
            <div class="kpi-icon">&#9989;</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <p class="label">Hors corridor minier</p>
                <p class="value"><?= $repartitionAlertes['hors_zone'] ?? 0 ?></p>
            </div>
            <div class="kpi-icon">&#128205;</div>
        </div>
    </div>
</div>

<!-- ====== ENVIRONNEMENT ====== -->
<div class="panel" style="padding:24px;">
    <h3 style="margin-bottom:18px;">&#127757; Impact environnemental estimé</h3>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-info">
                <p class="label">Carburant consommé (estimé)</p>
                <p class="value"><?= number_format($litresTotal, 0, ',', ' ') ?> L</p>
            </div>
            <div class="kpi-icon">&#128167;</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <p class="label">Émissions CO2 estimées</p>
                <p class="value"><?= number_format($co2EstimeKg, 0, ',', ' ') ?> kg</p>
                <p class="trend">Basé sur ~2,68 kg CO2/L de diesel</p>
            </div>
            <div class="kpi-icon">&#128168;</div>
        </div>
    </div>
</div>

<!-- ====== SECTION INVESTISSEURS ====== -->
<?php if (!empty($vehiculesInvestisseurs)): ?>
<div class="panel" style="padding:24px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <h3>&#128100; Rapport investisseurs — <?= date('F Y', strtotime($dateDebut)) ?></h3>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <?php if ($contratLePlusAncien): ?>
            <form method="GET" style="display:flex;gap:8px;align-items:center;">
                <label style="font-size:12px;color:#5C6B68;">Sélectionner le mois :</label>
                <select name="mois" onchange="this.form.submit()" style="font-size:13px;padding:6px 10px;border:1px solid #E9ECEC;border-radius:6px;">
                    <?php
                    $moisCourant = date('Y-m');
                    $moisDebut   = substr($contratLePlusAncien, 0, 7); // YYYY-MM
                    $moisIter    = $moisDebut;
                    while ($moisIter <= $moisCourant) {
                        $selected = $moisIter === $mois ? 'selected' : '';
                        $libelle  = date('F Y', strtotime($moisIter . '-01'));
                        echo "<option value=\"$moisIter\" $selected>$libelle</option>";
                        $moisIter = date('Y-m', strtotime($moisIter . '-01 +1 month'));
                    }
                    ?>
                </select>
            </form>
            <?php endif; ?>
            <a href="../rapports/export_investisseur_pdf.php?id_vehicule=0&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>&envoyer_email=0&mode=mensuel"
               class="btn-dash btn-dash-outline" style="font-size:12px;">
                &#128196; Exporter PDF
            </a>
        </div>
    </div>

    <?php if (empty(array_filter($statsInvestisseurs))): ?>
        <p style="color:#999;text-align:center;padding:20px;">Aucune donnée pour ce mois.</p>
    <?php else: ?>
    <table style="width:100%;">
        <thead>
            <tr style="background:#F5F7F6;">
                <th style="padding:10px 12px;text-align:left;font-size:12px;color:#5C6B68;border-bottom:2px solid #E9ECEC;">Véhicule</th>
                <th style="padding:10px 12px;text-align:left;font-size:12px;color:#5C6B68;border-bottom:2px solid #E9ECEC;">Investisseur</th>
                <th style="padding:10px 12px;text-align:left;font-size:12px;color:#5C6B68;border-bottom:2px solid #E9ECEC;">Superviseur</th>
                <th style="padding:10px 12px;text-align:center;font-size:12px;color:#5C6B68;border-bottom:2px solid #E9ECEC;">Début contrat</th>
                <th style="padding:10px 12px;text-align:center;font-size:12px;color:#5C6B68;border-bottom:2px solid #E9ECEC;">Km du mois</th>
                <th style="padding:10px 12px;text-align:center;font-size:12px;color:#5C6B68;border-bottom:2px solid #E9ECEC;">Missions</th>
                <th style="padding:10px 12px;text-align:center;font-size:12px;color:#5C6B68;border-bottom:2px solid #E9ECEC;">Alertes</th>
                <th style="padding:10px 12px;text-align:center;font-size:12px;color:#5C6B68;border-bottom:2px solid #E9ECEC;">Tonnage (t)</th>
                <th style="padding:10px 12px;text-align:center;font-size:12px;color:#5C6B68;border-bottom:2px solid #E9ECEC;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vehiculesInvestisseurs as $i => $v):
                $stats = $statsInvestisseurs[$v['id_vehicule']];
                $avantContrat = $stats === null;
            ?>
                <tr style="background:<?= $avantContrat ? '#F9F9F9' : ($i % 2 === 0 ? '#fff' : '#F8FAF9') ?>;border-bottom:1px solid #F0F2F1;">
                    <td style="padding:12px;">
                        <a href="../admin/fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;">
                            <?= htmlspecialchars($v['immatriculation']) ?>
                        </a>
                        <br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?> — <?= ucfirst($v['type']) ?></span>
                    </td>
                    <td style="padding:12px;">
                        <span style="color:#7C5CBF;font-weight:600;"><?= htmlspecialchars($v['proprietaire_nom']) ?></span>
                        <?php if ($v['proprietaire_contact_email']): ?>
                            <br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($v['proprietaire_contact_email']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px;font-size:12px;"><?= htmlspecialchars(($v['sup_prenom'] ?? '') . ' ' . ($v['sup_nom'] ?? '')) ?></td>
                    <td style="padding:12px;text-align:center;font-size:12px;">
                        <?= !empty($v['contrat_debut']) ? date('d/m/Y', strtotime($v['contrat_debut'])) : '<span style="color:#ccc;">—</span>' ?>
                    </td>
                    <?php if ($avantContrat): ?>
                        <td colspan="5" style="padding:12px;text-align:center;color:#ccc;font-size:12px;font-style:italic;">
                            Contrat non démarré ce mois
                        </td>
                    <?php else: ?>
                        <td style="padding:12px;text-align:center;font-weight:600;"><?= number_format($stats['km'], 0, ',', ' ') ?> km</td>
                        <td style="padding:12px;text-align:center;"><?= $stats['missions'] ?></td>
                        <td style="padding:12px;text-align:center;color:<?= $stats['alertes'] > 0 ? '#E24B4A' : '#1D9E75' ?>;"><?= $stats['alertes'] ?></td>
                        <td style="padding:12px;text-align:center;">
                            <?= $stats['tonnage'] !== null ? number_format($stats['tonnage'], 1, ',', ' ') . ' t' : '<span style="color:#ccc;">—</span>' ?>
                        </td>
                        <td style="padding:12px;text-align:center;">
                            <div style="display:flex;flex-direction:column;gap:6px;align-items:center;">
                                <a href="../rapports/export_investisseur_pdf.php?id_vehicule=<?= $v['id_vehicule'] ?>&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>&envoyer_email=0"
                                   class="btn-dash btn-dash-outline" style="font-size:11px;padding:4px 10px;white-space:nowrap;">
                                    &#128196; Télécharger PDF
                                </a>
                                <?php if (!empty($v['sup_email'])): ?>
                                <a href="../rapports/export_investisseur_pdf.php?id_vehicule=<?= $v['id_vehicule'] ?>&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>&envoyer_email=1"
                                   class="btn-dash btn-dash-primary" style="font-size:11px;padding:4px 10px;white-space:nowrap;"
                                   onclick="return confirm('Envoyer le rapport de <?= htmlspecialchars($v['immatriculation']) ?> à <?= htmlspecialchars(($v['sup_prenom'] ?? '') . ' ' . ($v['sup_nom'] ?? '')) ?> (<?= htmlspecialchars($v['sup_email'] ?? '') ?>) ?')">
                                    &#128231; Envoyer au superviseur
                                </a>
                                <?php else: ?>
                                <span style="font-size:11px;color:#ccc;">Pas d'email superviseur</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>