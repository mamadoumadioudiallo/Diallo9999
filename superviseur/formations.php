<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();
$idSup = $_SESSION['user_id'];

// Conducteurs du superviseur
$stmt = $pdo->prepare("
    SELECT c.id_conducteur, c.nom, c.prenom,
           COUNT(fi.id_inscription) AS nb_formations,
           COUNT(CASE WHEN fi.statut='certifie' THEN 1 END) AS nb_certifies,
           COUNT(CASE WHEN fi.statut='en_cours' THEN 1 END) AS nb_en_cours,
           COUNT(CASE WHEN fi.statut='assigne' THEN 1 END) AS nb_assignes
    FROM conducteurs c
    LEFT JOIN formation_inscriptions fi ON c.id_conducteur = fi.id_conducteur
    WHERE c.id_superviseur = ? AND c.statut = 'actif'
    GROUP BY c.id_conducteur, c.nom, c.prenom
    ORDER BY c.nom ASC
");
$stmt->execute([$idSup]);
$conducteurs = $stmt->fetchAll();

// Détail formations par conducteur sélectionné
$idConducteurSel = (int) ($_GET['conducteur'] ?? 0);
$detailFormations = [];
if ($idConducteurSel) {
    $stmt = $pdo->prepare("
        SELECT f.id_formation, f.titre, f.categorie, f.note_minimale,
               fi.statut, fi.score_quiz, fi.nb_tentatives, fi.date_completion
        FROM formation_inscriptions fi
        JOIN formations f ON fi.id_formation = f.id_formation
        WHERE fi.id_conducteur = ?
        ORDER BY fi.statut ASC, f.titre ASC
    ");
    $stmt->execute([$idConducteurSel]);
    $detailFormations = $stmt->fetchAll();
}

$typesColors = ['conduite_minier'=>'#0F6E56','ecoconduite'=>'#1D9E75','maintenance'=>'#BA7517','securite'=>'#E24B4A','reglementation'=>'#7C5CBF','environnement'=>'#2196F3'];
$typesLibelles = ['conduite_minier'=>'Conduite minier','ecoconduite'=>'Éco-conduite','maintenance'=>'Maintenance','securite'=>'Sécurité','reglementation'=>'Réglementation','environnement'=>'Environnement'];

$pageTitle = 'Formations — Mes conducteurs';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#127979; Simandou Academy</h1>
        <p class="subtitle">Progression de mes conducteurs</p>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:16px;">

    <!-- Liste conducteurs -->
    <div class="panel fleet-table-wrap">
        <div class="panel-header"><h3>&#128104; Mes conducteurs</h3></div>
        <table>
            <thead><tr><th>Conducteur</th><th>Certifiés</th><th>En cours</th></tr></thead>
            <tbody>
                <?php if (empty($conducteurs)): ?>
                    <tr><td colspan="3" style="text-align:center;padding:20px;color:#999;">Aucun conducteur.</td></tr>
                <?php endif; ?>
                <?php foreach ($conducteurs as $c): ?>
                    <?php $selected = $idConducteurSel === $c['id_conducteur']; ?>
                    <tr style="background:<?= $selected?'#E8F4F1':'' ?>;cursor:pointer;" onclick="window.location='formations.php?conducteur=<?= $c['id_conducteur'] ?>'">
                        <td>
                            <strong style="color:<?= $selected?'#0F6E56':'#1A1A1A' ?>;"><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></strong>
                            <br><span style="font-size:11px;color:#999;"><?= $c['nb_formations'] ?> formation(s)</span>
                        </td>
                        <td style="text-align:center;color:#1D9E75;font-weight:600;"><?= $c['nb_certifies'] ?> ✅</td>
                        <td style="text-align:center;color:#BA7517;"><?= $c['nb_en_cours'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Détail formations -->
    <div>
        <?php if (!$idConducteurSel): ?>
            <div class="panel" style="padding:40px;text-align:center;color:#999;">
                <p style="font-size:20px;margin-bottom:8px;">&#128203;</p>
                <p>Cliquez sur un conducteur pour voir ses formations.</p>
            </div>
        <?php else: ?>
            <?php $conducteurSel = array_values(array_filter($conducteurs, fn($c) => $c['id_conducteur'] === $idConducteurSel))[0] ?? null; ?>
            <?php if ($conducteurSel): ?>
                <div class="panel" style="padding:20px;margin-bottom:16px;">
                    <h3 style="margin-bottom:4px;">&#128104; <?= htmlspecialchars($conducteurSel['prenom'] . ' ' . $conducteurSel['nom']) ?></h3>
                    <div style="display:flex;gap:16px;font-size:12px;color:#5C6B68;margin-top:8px;">
                        <span>&#127941; <?= $conducteurSel['nb_certifies'] ?> certifié(s)</span>
                        <span>&#9201; <?= $conducteurSel['nb_en_cours'] ?> en cours</span>
                        <span>&#128218; <?= $conducteurSel['nb_assignes'] ?> à commencer</span>
                    </div>
                </div>

                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php if (empty($detailFormations)): ?>
                        <div class="panel" style="padding:24px;text-align:center;color:#999;">Aucune formation assignée.</div>
                    <?php endif; ?>
                    <?php foreach ($detailFormations as $f): ?>
                        <?php
                            $sc = ['assigne'=>['Assigné','#5C6B68','#F5F7F6'],'en_cours'=>['En cours','#BA7517','#FFFBF0'],'certifie'=>['Certifié ✅','#1D9E75','#E1F5EE']][$f['statut']];
                            $couleur = $typesColors[$f['categorie']] ?? '#0F6E56';
                        ?>
                        <div class="panel" style="padding:16px;border-left:4px solid <?= $couleur ?>;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                                <div>
                                    <span style="font-size:10px;color:<?= $couleur ?>;font-weight:600;text-transform:uppercase;"><?= $typesLibelles[$f['categorie']] ?></span>
                                    <h4 style="margin:4px 0;font-size:13px;"><?= htmlspecialchars($f['titre']) ?></h4>
                                    <p style="font-size:11px;color:#5C6B68;">
                                        Tentatives : <?= $f['nb_tentatives'] ?>
                                        <?= $f['score_quiz'] !== null ? ' — Score : ' . $f['score_quiz'] . '%' : '' ?>
                                        <?= $f['date_completion'] ? ' — Certifié le ' . date('d/m/Y', strtotime($f['date_completion'])) : '' ?>
                                    </p>
                                </div>
                                <div style="text-align:right;flex-shrink:0;">
                                    <span style="background:<?= $sc[2] ?>;color:<?= $sc[1] ?>;font-size:11px;padding:3px 10px;border-radius:10px;font-weight:600;"><?= $sc[0] ?></span>
                                    <?php if ($f['statut'] === 'certifie'): ?>
                                        <br><a href="../rapports/attestation_pdf.php?id_conducteur=<?= $idConducteurSel ?>&id_formation=<?= $f['id_formation'] ?>" style="font-size:11px;color:#0F6E56;margin-top:4px;display:inline-block;">&#128196; Attestation</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
