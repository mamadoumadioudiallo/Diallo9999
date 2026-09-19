<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireConducteur();
$idConducteur = $_SESSION['user_id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT f.*, fi.statut AS mon_statut, fi.score_quiz, fi.nb_tentatives, fi.date_completion, fi.id_inscription
    FROM formations f
    JOIN formation_inscriptions fi ON f.id_formation = fi.id_formation
    WHERE fi.id_conducteur = ? AND f.statut = 'active'
    ORDER BY fi.statut ASC, f.date_creation DESC
");
$stmt->execute([$idConducteur]);
$formations = $stmt->fetchAll();

$nbAssignes  = count(array_filter($formations, fn($f) => $f['mon_statut'] === 'assigne'));
$nbEnCours   = count(array_filter($formations, fn($f) => $f['mon_statut'] === 'en_cours'));
$nbCertifies = count(array_filter($formations, fn($f) => $f['mon_statut'] === 'certifie'));

$typesColors = ['conduite_minier'=>'#0F6E56','ecoconduite'=>'#1D9E75','maintenance'=>'#BA7517','securite'=>'#E24B4A','reglementation'=>'#7C5CBF','environnement'=>'#2196F3'];
$typesLibelles = ['conduite_minier'=>'Conduite minier','ecoconduite'=>'Éco-conduite','maintenance'=>'Maintenance','securite'=>'Sécurité','reglementation'=>'Réglementation','environnement'=>'Environnement'];

$pageTitle = 'Mes formations';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#127979; Simandou Academy</h1>
        <p class="subtitle">Mes formations et certifications</p>
    </div>
</div>

<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card"><div class="kpi-info"><p class="label">À commencer</p><p class="value"><?= $nbAssignes ?></p><p class="trend">Nouvelles formations</p></div><div class="kpi-icon">&#128218;</div></div>
    <div class="kpi-card warning"><div class="kpi-info"><p class="label">En cours</p><p class="value"><?= $nbEnCours ?></p><p class="trend">Continuer</p></div><div class="kpi-icon">&#9201;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Certifiés</p><p class="value" style="color:#1D9E75;"><?= $nbCertifies ?></p><p class="trend">Quiz validé ✅</p></div><div class="kpi-icon">&#127941;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Total</p><p class="value"><?= count($formations) ?></p><p class="trend">Formations assignées</p></div><div class="kpi-icon">&#127979;</div></div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
    <?php if (empty($formations)): ?>
        <div class="panel" style="padding:40px;text-align:center;color:#999;grid-column:1/-1;">
            <p style="font-size:24px;">&#127979;</p>
            <p>Aucune formation assignée pour le moment.</p>
        </div>
    <?php endif; ?>
    <?php foreach ($formations as $f): ?>
        <?php
            $couleur = $typesColors[$f['categorie']] ?? '#0F6E56';
            $statutLabels = ['assigne'=>['À commencer','#5C6B68'],'en_cours'=>['En cours','#BA7517'],'certifie'=>['Certifié ✅','#1D9E75']];
            $sl = $statutLabels[$f['mon_statut']] ?? ['—','#999'];
        ?>
        <div class="panel" style="padding:0;overflow:hidden;">
            <div style="background:<?= $couleur ?>;padding:10px 16px;">
                <span style="color:#fff;font-size:11px;font-weight:600;"><?= $typesLibelles[$f['categorie']] ?></span>
            </div>
            <div style="padding:16px;">
                <h3 style="margin-bottom:6px;font-size:14px;"><?= htmlspecialchars($f['titre']) ?></h3>
                <p style="font-size:12px;color:#5C6B68;margin-bottom:10px;"><?= htmlspecialchars(mb_substr($f['description'] ?? '', 0, 80)) ?>...</p>

                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
                    <?php if ($f['contenu_texte']): ?><span style="background:#E8F4F1;color:#0F6E56;font-size:11px;padding:2px 8px;border-radius:10px;">Cours</span><?php endif; ?>
                    <?php if ($f['contenu_pdf']): ?><span style="background:#FFF0E8;color:#BA7517;font-size:11px;padding:2px 8px;border-radius:10px;">PDF</span><?php endif; ?>
                    <?php if ($f['contenu_url']): ?><span style="background:#F0E8FF;color:#7C5CBF;font-size:11px;padding:2px 8px;border-radius:10px;">Vidéo</span><?php endif; ?>
                    <?php if ($f['duree_minutes']): ?><span style="background:#F5F7F6;color:#5C6B68;font-size:11px;padding:2px 8px;border-radius:10px;">&#9201; <?= $f['duree_minutes'] ?> min</span><?php endif; ?>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="color:<?= $sl[1] ?>;font-weight:600;font-size:12px;"><?= $sl[0] ?></span>
                    <?php if ($f['score_quiz'] !== null): ?>
                        <span style="font-size:11px;color:#5C6B68;">Meilleur score : <?= $f['score_quiz'] ?> %</span>
                    <?php endif; ?>
                </div>

                <?php if ($f['mon_statut'] === 'certifie'): ?>
                    <div style="display:flex;gap:8px;">
                        <a href="formation_detail.php?id=<?= $f['id_formation'] ?>" class="btn-dash btn-dash-outline" style="flex:1;text-align:center;font-size:12px;">Revoir</a>
                        <a href="../rapports/attestation_pdf.php?id_conducteur=<?= $idConducteur ?>&id_formation=<?= $f['id_formation'] ?>" class="btn-dash btn-dash-primary" style="flex:1;text-align:center;font-size:12px;">&#127941; Attestation</a>
                    </div>
                <?php else: ?>
                    <a href="formation_detail.php?id=<?= $f['id_formation'] ?>" class="btn-dash btn-dash-primary" style="display:block;text-align:center;font-size:12px;">
                        <?= $f['mon_statut'] === 'assigne' ? '▶ Commencer' : '▶ Continuer' ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
