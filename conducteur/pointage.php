<?php
// ============================================================
// FleetIoT — Simandou 2040
// Fichier : conducteur/pointage.php
// Rôle    : Interface self-service de pointage conducteur
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireConducteur();
$idConducteur = $_SESSION['user_id'] ?? 0;

$message = '';
$messageType = '';

// ====== POINTER ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type_pointage'])) {
    $type = $_POST['type_pointage'];
    $note = clean($_POST['note'] ?? '');
    $lat  = !empty($_POST['latitude'])  ? (float) $_POST['latitude']  : null;
    $lng  = !empty($_POST['longitude']) ? (float) $_POST['longitude'] : null;

    $typesValides = ['arrivee', 'pause', 'reprise', 'depart'];
    if (in_array($type, $typesValides)) {
        $pdo->prepare("
            INSERT INTO pointages (id_conducteur, type_pointage, horodatage, latitude, longitude, note)
            VALUES (?, ?, NOW(), ?, ?, ?)
        ")->execute([$idConducteur, $type, $lat, $lng, $note]);

        $libelles = ['arrivee' => 'Arrivée', 'pause' => 'Pause', 'reprise' => 'Reprise', 'depart' => 'Départ'];
        $message = '✅ ' . $libelles[$type] . ' enregistrée à ' . date('H:i');
        $messageType = 'success';
    }
}

// ====== DERNIER POINTAGE DU JOUR ======
$stmt = $pdo->prepare("
    SELECT * FROM pointages
    WHERE id_conducteur = ? AND DATE(horodatage) = CURDATE()
    ORDER BY horodatage DESC
");
$stmt->execute([$idConducteur]);
$pointagesAujourdHui = $stmt->fetchAll();

$dernierPointage = $pointagesAujourdHui[0] ?? null;
$dernierType = $dernierPointage['type_pointage'] ?? null;

// Calculer heures travaillées aujourd'hui
$heuresTravaillees = 0;
$enPause = false;
$debutActuel = null;

$arrivees = array_values(array_filter($pointagesAujourdHui, fn($p) => $p['type_pointage'] === 'arrivee'));
$pauses   = array_values(array_filter($pointagesAujourdHui, fn($p) => $p['type_pointage'] === 'pause'));
$reprises = array_values(array_filter($pointagesAujourdHui, fn($p) => $p['type_pointage'] === 'reprise'));
$departs  = array_values(array_filter($pointagesAujourdHui, fn($p) => $p['type_pointage'] === 'depart'));

// Calcul simple : total - pauses
if (!empty($arrivees)) {
    $debut = strtotime($arrivees[0]['horodatage']);
    $fin   = !empty($departs) ? strtotime($departs[0]['horodatage']) : time();
    $heuresTravaillees = ($fin - $debut) / 3600;

    // Soustraire les pauses
    foreach ($pauses as $i => $pause) {
        $debutPause = strtotime($pause['horodatage']);
        $finPause   = isset($reprises[$i]) ? strtotime($reprises[$i]['horodatage']) : time();
        $heuresTravaillees -= ($finPause - $debutPause) / 3600;
    }
    $heuresTravaillees = max(0, $heuresTravaillees);
}

// Statut actuel
$statutActuel = 'absent';
if ($dernierType === 'arrivee' || $dernierType === 'reprise') $statutActuel = 'present';
elseif ($dernierType === 'pause') $statutActuel = 'pause';
elseif ($dernierType === 'depart') $statutActuel = 'parti';

// Boutons disponibles selon statut
$boutonsDisponibles = [
    'absent' => ['arrivee'],
    'present' => ['pause', 'depart'],
    'pause' => ['reprise'],
    'parti' => [],
];
$boutons = $boutonsDisponibles[$statutActuel] ?? [];

// Historique — filtré par date si demandé, sinon 7 derniers jours
$filtreDate = $_GET['date'] ?? '';
if ($filtreDate) {
    $stmt = $pdo->prepare("
        SELECT DATE(horodatage) AS jour,
               MIN(CASE WHEN type_pointage='arrivee' THEN horodatage END) AS heure_arrivee,
               MAX(CASE WHEN type_pointage='depart' THEN horodatage END) AS heure_depart,
               COUNT(CASE WHEN type_pointage='pause' THEN 1 END) AS nb_pauses
        FROM pointages
        WHERE id_conducteur = ? AND DATE(horodatage) = ?
        GROUP BY DATE(horodatage)
    ");
    $stmt->execute([$idConducteur, $filtreDate]);
} else {
    $stmt = $pdo->prepare("
        SELECT DATE(horodatage) AS jour,
               MIN(CASE WHEN type_pointage='arrivee' THEN horodatage END) AS heure_arrivee,
               MAX(CASE WHEN type_pointage='depart' THEN horodatage END) AS heure_depart,
               COUNT(CASE WHEN type_pointage='pause' THEN 1 END) AS nb_pauses
        FROM pointages
        WHERE id_conducteur = ? AND horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(horodatage)
        ORDER BY jour DESC
    ");
    $stmt->execute([$idConducteur]);
}
$historique = $stmt->fetchAll();

// Infos conducteur
$stmt = $pdo->prepare("SELECT nom, prenom FROM conducteurs WHERE id_conducteur = ?");
$stmt->execute([$idConducteur]);
$conducteur = $stmt->fetch();

$pageTitle = 'Mon pointage';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#128336; Mon pointage</h1>
        <p class="subtitle"><?= htmlspecialchars($conducteur['prenom'] . ' ' . $conducteur['nom']) ?> — <?= date('l d/m/Y') ?></p>
    </div>
</div>

<?php if ($message): ?>
    <div style="padding:14px 18px;margin-bottom:16px;background:<?= $messageType==='success'?'#E1F5EE':'#FCEBEB' ?>;color:<?= $messageType==='success'?'#085041':'#A32D2D' ?>;border-radius:8px;font-size:15px;font-weight:600;text-align:center;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Statut actuel -->
<?php
    $statutColors = ['absent'=>['#5C6B68','#F5F7F6','—'],'present'=>['#1D9E75','#E1F5EE','Au travail'],'pause'=>['#BA7517','#FFFBF0','En pause'],'parti'=>['#5C6B68','#F5F7F6','Journée terminée']];
    $sc = $statutColors[$statutActuel];
?>
<div style="background:<?= $sc[1] ?>;border-radius:12px;padding:24px;text-align:center;margin-bottom:20px;border:2px solid <?= $sc[0] ?>;">
    <p style="font-size:13px;color:#5C6B68;margin-bottom:6px;">Statut actuel</p>
    <p style="font-size:28px;font-weight:800;color:<?= $sc[0] ?>;"><?= $sc[2] ?></p>
    <?php if ($dernierPointage): ?>
        <p style="font-size:12px;color:#5C6B68;margin-top:6px;">Dernier pointage : <?= date('H:i', strtotime($dernierPointage['horodatage'])) ?></p>
    <?php endif; ?>
    <?php if ($heuresTravaillees > 0): ?>
        <p style="font-size:14px;font-weight:600;color:<?= $sc[0] ?>;margin-top:8px;">
            &#9201; <?= floor($heuresTravaillees) ?>h<?= str_pad(round(($heuresTravaillees - floor($heuresTravaillees)) * 60), 2, '0', STR_PAD_LEFT) ?> travaillées aujourd'hui
        </p>
    <?php endif; ?>
</div>

<!-- Boutons de pointage -->
<?php if (!empty($boutons)): ?>
<div style="margin-bottom:20px;">
    <form method="POST" id="formPointage">
        <input type="hidden" name="latitude"  id="inputLat">
        <input type="hidden" name="longitude" id="inputLng">

        <div style="display:grid;grid-template-columns:repeat(<?= count($boutons) ?>,1fr);gap:12px;margin-bottom:12px;">
            <?php
            $btnConfig = [
                'arrivee' => ['#1D9E75','#fff','&#128994; Pointer l\'arrivée','arrivee'],
                'pause'   => ['#BA7517','#fff','&#9208; Prendre une pause','pause'],
                'reprise' => ['#0F6E56','#fff','&#9654; Reprendre le travail','reprise'],
                'depart'  => ['#E24B4A','#fff','&#128997; Pointer le départ','depart'],
            ];
            foreach ($boutons as $btn):
                $bc = $btnConfig[$btn];
            ?>
                <button type="button"
                    onclick="pointer('<?= $bc[3] ?>')"
                    style="background:<?= $bc[0] ?>;color:<?= $bc[1] ?>;border:none;border-radius:12px;padding:24px 16px;font-size:16px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.15);transition:opacity 0.2s;"
                    onmouseover="this.style.opacity='0.85'"
                    onmouseout="this.style.opacity='1'">
                    <?= $bc[2] ?>
                </button>
            <?php endforeach; ?>
        </div>

        <input type="hidden" name="type_pointage" id="typePointage">
        <div style="display:none;" id="divNote">
            <input type="text" name="note" id="inputNote" placeholder="Note optionnelle..." style="width:100%;padding:10px;border:1px solid #E9ECEC;border-radius:8px;font-size:13px;box-sizing:border-box;">
            <button type="submit" class="btn-dash btn-dash-primary" style="width:100%;margin-top:8px;padding:12px;">Confirmer le pointage</button>
        </div>
    </form>
</div>
<?php elseif ($statutActuel === 'parti'): ?>
    <div style="background:#F5F7F6;border-radius:12px;padding:24px;text-align:center;margin-bottom:20px;">
        <p style="font-size:16px;color:#5C6B68;">&#127974; Bonne fin de journée !</p>
        <p style="font-size:13px;color:#999;margin-top:6px;">Votre journée est terminée. À demain !</p>
    </div>
<?php else: ?>
    <div style="background:#F5F7F6;border-radius:12px;padding:24px;text-align:center;margin-bottom:20px;">
        <p style="font-size:16px;color:#5C6B68;">&#128336; Vous n'avez pas encore pointé aujourd'hui.</p>
        <form method="POST">
            <input type="hidden" name="type_pointage" value="arrivee">
            <button type="submit" style="background:#1D9E75;color:#fff;border:none;border-radius:12px;padding:20px 40px;font-size:18px;font-weight:700;cursor:pointer;margin-top:12px;">
                &#128994; Pointer l'arrivée
            </button>
        </form>
    </div>
<?php endif; ?>

<!-- Pointages du jour -->
<?php if (!empty($pointagesAujourdHui)): ?>
<div class="panel" style="padding:20px;margin-bottom:16px;">
    <h3 style="margin-bottom:14px;">&#128203; Pointages d'aujourd'hui</h3>
    <div style="display:flex;flex-direction:column;gap:8px;">
        <?php
        $typeConfig = [
            'arrivee' => ['#1D9E75','&#128994;','Arrivée'],
            'pause'   => ['#BA7517','&#9208;','Pause'],
            'reprise' => ['#0F6E56','&#9654;','Reprise'],
            'depart'  => ['#E24B4A','&#128997;','Départ'],
        ];
        foreach (array_reverse($pointagesAujourdHui) as $p):
            $tc = $typeConfig[$p['type_pointage']];
        ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#F5F7F6;border-left:4px solid <?= $tc[0] ?>;border-radius:6px;">
                <span style="font-size:18px;"><?= $tc[1] ?></span>
                <div style="flex:1;">
                    <span style="font-weight:600;color:<?= $tc[0] ?>;"><?= $tc[2] ?></span>
                    <?php if ($p['note']): ?><span style="font-size:12px;color:#5C6B68;"> — <?= htmlspecialchars($p['note']) ?></span><?php endif; ?>
                </div>
                <span style="font-size:14px;font-weight:600;color:#1A1A1A;"><?= date('H:i', strtotime($p['horodatage'])) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Historique 7 jours -->
<div class="panel" style="padding:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <h3>&#128197; <?= $filtreDate ? 'Pointages du ' . date('d/m/Y', strtotime($filtreDate)) : 'Mes 7 derniers jours' ?></h3>
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <input type="date" name="date" value="<?= htmlspecialchars($filtreDate) ?>" max="<?= date('Y-m-d') ?>"
                   style="padding:6px 10px;border:1px solid #E9ECEC;border-radius:6px;font-size:13px;">
            <button type="submit" class="btn-dash btn-dash-primary" style="height:34px;padding:0 12px;font-size:12px;">Voir</button>
            <?php if ($filtreDate): ?>
                <a href="pointage.php" class="btn-dash btn-dash-outline" style="height:34px;line-height:22px;font-size:12px;">7 jours</a>
            <?php endif; ?>
        </form>
    </div>
    <?php if (empty($historique)): ?>
        <p style="color:#999;text-align:center;padding:16px;">Aucun pointage enregistré.</p>
    <?php else: ?>
    <table style="width:100%;">
        <thead>
            <tr style="background:#F5F7F6;">
                <th style="padding:10px 12px;text-align:left;font-size:12px;color:#5C6B68;border-bottom:1px solid #E9ECEC;">Date</th>
                <th style="padding:10px 12px;text-align:center;font-size:12px;color:#5C6B68;border-bottom:1px solid #E9ECEC;">Arrivée</th>
                <th style="padding:10px 12px;text-align:center;font-size:12px;color:#5C6B68;border-bottom:1px solid #E9ECEC;">Départ</th>
                <th style="padding:10px 12px;text-align:center;font-size:12px;color:#5C6B68;border-bottom:1px solid #E9ECEC;">Pauses</th>
                <th style="padding:10px 12px;text-align:center;font-size:12px;color:#5C6B68;border-bottom:1px solid #E9ECEC;">Durée</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($historique as $h):
                $dureeH = '';
                if ($h['heure_arrivee'] && $h['heure_depart']) {
                    $sec = strtotime($h['heure_depart']) - strtotime($h['heure_arrivee']);
                    $dureeH = floor($sec/3600) . 'h' . str_pad(floor(($sec%3600)/60), 2, '0', STR_PAD_LEFT);
                }
                $estAujourdHui = $h['jour'] === date('Y-m-d');
            ?>
                <tr style="background:<?= $estAujourdHui?'#E8F4F1':'#fff' ?>;border-bottom:1px solid #F0F2F1;">
                    <td style="padding:10px 12px;font-weight:<?= $estAujourdHui?'700':'400' ?>;">
                        <?= $estAujourdHui ? "Aujourd'hui" : date('D d/m', strtotime($h['jour'])) ?>
                    </td>
                    <td style="padding:10px 12px;text-align:center;color:#1D9E75;font-weight:600;"><?= $h['heure_arrivee'] ? date('H:i', strtotime($h['heure_arrivee'])) : '<span style="color:#ccc;">—</span>' ?></td>
                    <td style="padding:10px 12px;text-align:center;color:#E24B4A;font-weight:600;"><?= $h['heure_depart'] ? date('H:i', strtotime($h['heure_depart'])) : '<span style="color:#ccc;">—</span>' ?></td>
                    <td style="padding:10px 12px;text-align:center;color:#5C6B68;"><?= $h['nb_pauses'] ?: '0' ?></td>
                    <td style="padding:10px 12px;text-align:center;font-weight:600;"><?= $dureeH ?: '<span style="color:#ccc;">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
function pointer(type) {
    document.getElementById('typePointage').value = type;
    document.getElementById('divNote').style.display = 'block';

    // Géolocalisation
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(pos) {
            document.getElementById('inputLat').value = pos.coords.latitude;
            document.getElementById('inputLng').value = pos.coords.longitude;
        }, function() {});
    }

    // Scroll vers formulaire
    document.getElementById('divNote').scrollIntoView({behavior: 'smooth'});
    document.getElementById('inputNote').focus();
}
</script>

<?php require_once '../includes/footer.php'; ?>