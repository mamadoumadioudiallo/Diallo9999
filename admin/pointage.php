<?php
// ============================================================
// FleetIoT — Simandou 2040
// Fichier : admin/pointage.php
// Rôle    : Récapitulatif pointages toute la flotte
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur(); // Accessible admin ET superviseur
$estAdmin = isAdmin();
$idSup    = $_SESSION['user_id'];

$date       = $_GET['date']       ?? date('Y-m-d');
$mois       = $_GET['mois']       ?? date('Y-m');
$filtreSup  = $estAdmin ? ($_GET['sup'] ?? '') : $idSup;
$vue        = $_GET['vue']        ?? 'jour'; // jour ou mois

$dateDebut = $vue === 'mois' ? $mois . '-01' : $date;
$dateFin   = $vue === 'mois' ? date('Y-m-t', strtotime($mois . '-01')) : $date;

// ====== KPI DU JOUR ======
$kpiWhere = "DATE(p.horodatage) = ?";
$kpiParams = [$date];
if ($filtreSup) { $kpiWhere .= " AND c.id_superviseur = ?"; $kpiParams[] = $filtreSup; }

$stmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT p.id_conducteur) AS nb_pointes,
        COUNT(DISTINCT CASE WHEN dern.dernier_type IN ('arrivee','reprise') THEN p.id_conducteur END) AS nb_presents,
        COUNT(DISTINCT CASE WHEN dern.dernier_type = 'pause' THEN p.id_conducteur END) AS nb_pauses,
        COUNT(DISTINCT CASE WHEN dern.dernier_type = 'depart' THEN p.id_conducteur END) AS nb_partis
    FROM pointages p
    JOIN conducteurs c ON p.id_conducteur = c.id_conducteur
    JOIN (
        SELECT id_conducteur, type_pointage AS dernier_type
        FROM pointages p2
        WHERE DATE(horodatage) = ?
        AND horodatage = (SELECT MAX(horodatage) FROM pointages WHERE id_conducteur = p2.id_conducteur AND DATE(horodatage) = ?)
    ) dern ON p.id_conducteur = dern.id_conducteur
    WHERE $kpiWhere
");
$kpiParamsFull = array_merge([$date, $date], $kpiParams);
$stmt->execute($kpiParamsFull);
$kpi = $stmt->fetch();

// Total conducteurs actifs
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM conducteurs c WHERE c.statut = 'actif'" . ($filtreSup ? " AND c.id_superviseur = ?" : ""));
$totalStmt->execute($filtreSup ? [$filtreSup] : []);
$nbTotal = $totalStmt->fetchColumn();

// ====== POINTAGES PAR CONDUCTEUR ======
$condWhere = $filtreSup ? "AND c.id_superviseur = ?" : "";
$condParams = $filtreSup ? [$filtreSup] : [];

$stmt = $pdo->prepare("
    SELECT * FROM (
        SELECT c.id_conducteur, c.nom, c.prenom,
               u.nom AS sup_nom, u.prenom AS sup_prenom,
               MIN(CASE WHEN p.type_pointage='arrivee' THEN p.horodatage END) AS heure_arrivee,
               MAX(CASE WHEN p.type_pointage='depart' THEN p.horodatage END) AS heure_depart,
               COUNT(CASE WHEN p.type_pointage='pause' THEN 1 END) AS nb_pauses,
               COUNT(CASE WHEN p.type_pointage='reprise' THEN 1 END) AS nb_reprises,
               MAX(p.type_pointage) AS dernier_type,
               MAX(p.horodatage) AS dernier_horodatage,
               GROUP_CONCAT(p.note SEPARATOR ' | ') AS notes
        FROM conducteurs c
        LEFT JOIN pointages p ON c.id_conducteur = p.id_conducteur AND DATE(p.horodatage) BETWEEN ? AND ?
        LEFT JOIN utilisateurs u ON c.id_superviseur = u.id_user
        WHERE c.statut = 'actif' $condWhere
        GROUP BY c.id_conducteur, c.nom, c.prenom, u.nom, u.prenom
    ) t
    ORDER BY (heure_arrivee IS NULL) ASC, heure_arrivee ASC, nom ASC
");
$stmt->execute(array_merge([$dateDebut, $dateFin], $condParams));
$conducteurs = $stmt->fetchAll();

// Superviseurs pour filtre admin
$superviseurs = [];
if ($estAdmin) {
    $superviseurs = $pdo->query("SELECT id_user, nom, prenom FROM utilisateurs WHERE role='superviseur' AND statut='actif' ORDER BY nom")->fetchAll();
}

$pageTitle = 'Pointage conducteurs';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#128336; Pointage conducteurs</h1>
        <p class="subtitle"><?= $vue === 'mois' ? date('F Y', strtotime($mois . '-01')) : date('d/m/Y', strtotime($date)) ?></p>
    </div>
    <div class="dash-actions">
        <a href="../rapports/export_pointage_pdf.php?date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?><?= $filtreSup ? '&sup=' . $filtreSup : '' ?>" class="btn-dash btn-dash-outline" target="_blank">&#128196; PDF</a>
        <a href="../rapports/export_pointage_csv.php?date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?><?= $filtreSup ? '&sup=' . $filtreSup : '' ?>" class="btn-dash btn-dash-outline">&#128196; CSV</a>
    </div>
</div>

<!-- KPI -->
<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card"><div class="kpi-info"><p class="label">Conducteurs actifs</p><p class="value"><?= $nbTotal ?></p><p class="trend">Total flotte</p></div><div class="kpi-icon">&#128104;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Présents</p><p class="value" style="color:#1D9E75;"><?= $kpi['nb_presents'] ?? 0 ?></p><p class="trend">Au travail maintenant</p></div><div class="kpi-icon">&#128994;</div></div>
    <div class="kpi-card warning"><div class="kpi-info"><p class="label">En pause</p><p class="value"><?= $kpi['nb_pauses'] ?? 0 ?></p><p class="trend">Pause en cours</p></div><div class="kpi-icon">&#9208;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Ont pointé</p><p class="value"><?= $kpi['nb_pointes'] ?? 0 ?></p><p class="trend">Aujourd'hui</p></div><div class="kpi-icon">&#128336;</div></div>
</div>

<!-- Filtres -->
<div class="panel" style="padding:14px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Vue</label>
            <select name="vue" onchange="this.form.submit()">
                <option value="jour"  <?= $vue==='jour' ?'selected':'' ?>>Par jour</option>
                <option value="mois" <?= $vue==='mois'?'selected':'' ?>>Par mois</option>
            </select>
        </div>
        <?php if ($vue === 'jour'): ?>
        <div class="form-group" style="margin:0;"><label style="font-size:12px;">Date</label><input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>"></div>
        <?php else: ?>
        <div class="form-group" style="margin:0;"><label style="font-size:12px;">Mois</label><input type="month" name="mois" value="<?= htmlspecialchars($mois) ?>" max="<?= date('Y-m') ?>"></div>
        <?php endif; ?>
        <?php if ($estAdmin && !empty($superviseurs)): ?>
        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
            <label style="font-size:12px;">Superviseur</label>
            <select name="sup">
                <option value="">Tous</option>
                <?php foreach ($superviseurs as $s): ?>
                    <option value="<?= $s['id_user'] ?>" <?= $filtreSup==$s['id_user']?'selected':'' ?>><?= htmlspecialchars($s['prenom'].' '.$s['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn-dash btn-dash-primary" style="height:38px;">Filtrer</button>
        <a href="pointage.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<!-- Tableau conducteurs -->
<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>&#128203; Récapitulatif — <?= $vue === 'mois' ? date('F Y', strtotime($mois.'-01')) : date('d/m/Y', strtotime($date)) ?></h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Conducteur</th>
                <?php if ($estAdmin): ?><th>Superviseur</th><?php endif; ?>
                <th>Arrivée</th>
                <th>Départ</th>
                <th>Pauses</th>
                <th>Durée travail</th>
                <th>Statut</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($conducteurs as $c):
                $duree = '';
                if ($c['heure_arrivee'] && $c['heure_depart']) {
                    $sec = strtotime($c['heure_depart']) - strtotime($c['heure_arrivee']);
                    $duree = floor($sec/3600) . 'h' . str_pad(floor(($sec%3600)/60), 2, '0', STR_PAD_LEFT);
                }
                $statutMap = ['arrivee'=>['Présent','badge-success'],'reprise'=>['Présent','badge-success'],'pause'=>['En pause','badge-warning'],'depart'=>['Parti','badge-muted'],null=>['Absent','badge-muted']];
                $sl = $statutMap[$c['dernier_type']] ?? ['Absent','badge-muted'];
                $absent = $c['heure_arrivee'] === null;
            ?>
                <tr style="background:<?= $absent?'#FAFAFA':'' ?>;">
                    <td>
                        <strong style="color:<?= $absent?'#999':'#1A1A1A' ?>;"><?= htmlspecialchars($c['prenom'].' '.$c['nom']) ?></strong>
                    </td>
                    <?php if ($estAdmin): ?><td style="font-size:12px;"><?= htmlspecialchars(($c['sup_prenom']??'').' '.($c['sup_nom']??'')) ?></td><?php endif; ?>
                    <td style="color:#1D9E75;font-weight:600;"><?= $c['heure_arrivee'] ? date('H:i', strtotime($c['heure_arrivee'])) : '<span style="color:#ccc;">—</span>' ?></td>
                    <td style="color:#E24B4A;font-weight:600;"><?= $c['heure_depart'] ? date('H:i', strtotime($c['heure_depart'])) : '<span style="color:#ccc;">—</span>' ?></td>
                    <td style="text-align:center;"><?= $c['nb_pauses'] ?: '0' ?></td>
                    <td style="font-weight:600;"><?= $duree ?: '<span style="color:#ccc;">—</span>' ?></td>
                    <td><span class="badge <?= $sl[1] ?>"><?= $sl[0] ?></span></td>
                    <td style="font-size:11px;color:#5C6B68;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($c['notes'] ?? '') ?>"><?= htmlspecialchars(mb_substr($c['notes'] ?? '', 0, 40)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>