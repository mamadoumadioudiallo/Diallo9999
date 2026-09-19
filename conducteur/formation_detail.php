<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireConducteur();
$idConducteur = $_SESSION['user_id'] ?? 0;
$idFormation  = (int) ($_GET['id'] ?? 0);

// Vérifier que le conducteur est bien inscrit
$stmt = $pdo->prepare("
    SELECT f.*, fi.statut AS mon_statut, fi.score_quiz, fi.nb_tentatives, fi.id_inscription
    FROM formations f
    JOIN formation_inscriptions fi ON f.id_formation = fi.id_formation
    WHERE f.id_formation = ? AND fi.id_conducteur = ?
");
$stmt->execute([$idFormation, $idConducteur]);
$formation = $stmt->fetch();
if (!$formation) { header('Location: formations.php'); exit(); }

// ====== PASSER LE QUIZ ======
$resultatQuiz = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'passer_quiz') {
    $questions = $pdo->prepare("SELECT * FROM formation_questions WHERE id_formation = ? ORDER BY ordre ASC");
    $questions->execute([$idFormation]);
    $questions = $questions->fetchAll();

    $nbBonnes = 0;
    $detail = [];
    foreach ($questions as $q) {
        $reponse = $_POST['reponse_' . $q['id_question']] ?? '';
        $correct = $reponse === $q['bonne_reponse'];
        if ($correct) $nbBonnes++;
        $detail[] = ['question' => $q, 'reponse' => $reponse, 'correct' => $correct];
    }

    $score = count($questions) > 0 ? round($nbBonnes / count($questions) * 100) : 0;
    $certifie = $score >= $formation['note_minimale'];

    // Mettre à jour l'inscription
    $nouveauStatut = $certifie ? 'certifie' : 'en_cours';
    $dateCompletion = $certifie ? date('Y-m-d H:i:s') : null;

    // Garder le meilleur score
    $meilleurScore = max($score, (int) ($formation['score_quiz'] ?? 0));

    $pdo->prepare("
        UPDATE formation_inscriptions
        SET statut = ?, score_quiz = ?, nb_tentatives = nb_tentatives + 1,
            date_completion = COALESCE(date_completion, ?)
        WHERE id_formation = ? AND id_conducteur = ?
    ")->execute([$nouveauStatut, $meilleurScore, $dateCompletion, $idFormation, $idConducteur]);

    $resultatQuiz = ['score' => $score, 'certifie' => $certifie, 'nbBonnes' => $nbBonnes, 'total' => count($questions), 'detail' => $detail];

    // Recharger
    $stmt = $pdo->prepare("SELECT f.*, fi.statut AS mon_statut, fi.score_quiz, fi.nb_tentatives, fi.id_inscription FROM formations f JOIN formation_inscriptions fi ON f.id_formation = fi.id_formation WHERE f.id_formation = ? AND fi.id_conducteur = ?");
    $stmt->execute([$idFormation, $idConducteur]);
    $formation = $stmt->fetch();
}

// Marquer en cours si on commence
if ($formation['mon_statut'] === 'assigne') {
    $pdo->prepare("UPDATE formation_inscriptions SET statut = 'en_cours' WHERE id_formation = ? AND id_conducteur = ?")->execute([$idFormation, $idConducteur]);
    $formation['mon_statut'] = 'en_cours';
}

$questions = $pdo->prepare("SELECT * FROM formation_questions WHERE id_formation = ? ORDER BY ordre ASC");
$questions->execute([$idFormation]);
$questions = $questions->fetchAll();

$typesColors = ['conduite_minier'=>'#0F6E56','ecoconduite'=>'#1D9E75','maintenance'=>'#BA7517','securite'=>'#E24B4A','reglementation'=>'#7C5CBF','environnement'=>'#2196F3'];
$couleur = $typesColors[$formation['categorie']] ?? '#0F6E56';

$pageTitle = $formation['titre'];
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#127979; <?= htmlspecialchars($formation['titre']) ?></h1>
        <p class="subtitle"><?= htmlspecialchars($formation['description'] ?? '') ?></p>
    </div>
    <div class="dash-actions">
        <a href="formations.php" class="btn-dash btn-dash-outline">&#8592; Mes formations</a>
        <?php if ($formation['mon_statut'] === 'certifie'): ?>
            <a href="../rapports/attestation_pdf.php?id_conducteur=<?= $idConducteur ?>&id_formation=<?= $idFormation ?>" class="btn-dash btn-dash-primary">&#127941; Mon attestation</a>
        <?php endif; ?>
    </div>
</div>

<!-- Résultat quiz -->
<?php if ($resultatQuiz !== null): ?>
    <div class="panel" style="padding:24px;margin-bottom:16px;text-align:center;background:<?= $resultatQuiz['certifie']?'#E1F5EE':'#FFF8F0' ?>;border:none;">
        <?php if ($resultatQuiz['certifie']): ?>
            <p style="font-size:36px;margin-bottom:8px;">&#127941;</p>
            <h2 style="color:#085041;margin-bottom:6px;">Félicitations ! Vous êtes certifié !</h2>
            <p style="color:#085041;">Score : <strong><?= $resultatQuiz['score'] ?> %</strong> (<?= $resultatQuiz['nbBonnes'] ?> / <?= $resultatQuiz['total'] ?> bonnes réponses)</p>
            <a href="../rapports/attestation_pdf.php?id_conducteur=<?= $idConducteur ?>&id_formation=<?= $idFormation ?>" class="btn-dash btn-dash-primary" style="margin-top:12px;display:inline-block;">&#128196; Télécharger mon attestation</a>
        <?php else: ?>
            <p style="font-size:36px;margin-bottom:8px;">&#128219;</p>
            <h2 style="color:#BA7517;margin-bottom:6px;">Pas encore certifié</h2>
            <p style="color:#5C6B68;">Score : <strong><?= $resultatQuiz['score'] ?> %</strong> — Note minimale requise : <strong><?= $formation['note_minimale'] ?> %</strong></p>
            <p style="color:#5C6B68;font-size:13px;margin-top:6px;">Relisez le cours et réessayez !</p>
        <?php endif; ?>
    </div>

    <!-- Détail réponses -->
    <div class="panel" style="padding:20px;margin-bottom:16px;">
        <h3 style="margin-bottom:14px;">Détail de vos réponses</h3>
        <?php foreach ($resultatQuiz['detail'] as $i => $d): ?>
            <div style="background:<?= $d['correct']?'#F0FDF5':'#FFF5F5' ?>;border-left:3px solid <?= $d['correct']?'#1D9E75':'#E24B4A' ?>;border-radius:6px;padding:12px;margin-bottom:8px;">
                <p style="font-weight:600;font-size:13px;margin-bottom:6px;"><?= $i+1 ?>. <?= htmlspecialchars($d['question']['question']) ?></p>
                <p style="font-size:12px;">Votre réponse : <strong><?= strtoupper($d['reponse']) ?>. <?= htmlspecialchars($d['question']['option_' . $d['reponse']] ?? '—') ?></strong>
                    <?= $d['correct'] ? '✅' : '❌' ?></p>
                <?php if (!$d['correct']): ?>
                    <p style="font-size:12px;color:#1D9E75;">Bonne réponse : <?= strtoupper($d['question']['bonne_reponse']) ?>. <?= htmlspecialchars($d['question']['option_' . $d['question']['bonne_reponse']]) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Contenu de la formation -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px;">
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Vidéo -->
        <?php if (!empty($formation['contenu_url'])): ?>
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:14px;">&#127916; Vidéo de formation</h3>
            <?php
                $url = $formation['contenu_url'];
                // Convertir YouTube en embed
                if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $m)) {
                    $embedUrl = 'https://www.youtube.com/embed/' . $m[1];
                } elseif (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
                    $embedUrl = 'https://player.vimeo.com/video/' . $m[1];
                } else {
                    $embedUrl = null;
                }
            ?>
            <?php if ($embedUrl): ?>
                <div style="position:relative;padding-bottom:56.25%;height:0;border-radius:8px;overflow:hidden;">
                    <iframe src="<?= htmlspecialchars($embedUrl) ?>" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none;" allowfullscreen></iframe>
                </div>
            <?php else: ?>
                <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="btn-dash btn-dash-outline">&#127916; Ouvrir la vidéo</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Contenu texte -->
        <?php if (!empty($formation['contenu_texte'])): ?>
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:14px;">&#128218; Contenu du cours</h3>
            <div style="font-size:13px;line-height:1.7;color:#1A1A1A;white-space:pre-wrap;"><?= htmlspecialchars($formation['contenu_texte']) ?></div>
        </div>
        <?php endif; ?>

        <!-- PDF -->
        <?php if (!empty($formation['contenu_pdf'])): ?>
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:14px;">&#128196; Support PDF</h3>
            <a href="../<?= htmlspecialchars($formation['contenu_pdf']) ?>" target="_blank" class="btn-dash btn-dash-outline" style="display:inline-flex;align-items:center;gap:8px;">
                &#128196; Télécharger le support PDF
            </a>
        </div>
        <?php endif; ?>

    </div>

    <!-- Infos + Quiz -->
    <div style="display:flex;flex-direction:column;gap:16px;">
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:14px;">&#128200; Ma progression</h3>
            <div style="text-align:center;margin-bottom:12px;">
                <?php $statutLabels = ['assigne'=>['À commencer','#5C6B68'],'en_cours'=>['En cours','#BA7517'],'certifie'=>['Certifié ✅','#1D9E75']]; $sl = $statutLabels[$formation['mon_statut']]; ?>
                <span style="background:<?= $sl[1] ?>;color:#fff;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:600;"><?= $sl[0] ?></span>
            </div>
            <?php if ($formation['score_quiz'] !== null): ?><p style="text-align:center;font-size:13px;color:#5C6B68;">Meilleur score : <strong><?= $formation['score_quiz'] ?> %</strong></p><?php endif; ?>
            <p style="text-align:center;font-size:12px;color:#999;">Tentatives : <?= $formation['nb_tentatives'] ?></p>
            <hr style="border:none;border-top:1px solid #E9ECEC;margin:12px 0;">
            <p style="font-size:12px;color:#5C6B68;">&#10067; <?= count($questions) ?> questions</p>
            <p style="font-size:12px;color:#5C6B68;">&#127919; Note minimale : <strong><?= $formation['note_minimale'] ?> %</strong></p>
            <?php if ($formation['duree_minutes']): ?><p style="font-size:12px;color:#5C6B68;">&#9201; Durée : <?= $formation['duree_minutes'] ?> min</p><?php endif; ?>
        </div>

        <!-- Quiz -->
        <?php if (!empty($questions)): ?>
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:4px;">&#10067; Quiz de validation</h3>
            <p style="font-size:12px;color:#5C6B68;margin-bottom:16px;">Répondez à toutes les questions pour obtenir votre certification.</p>
            <form method="POST">
                <input type="hidden" name="action" value="passer_quiz">
                <?php foreach ($questions as $i => $q): ?>
                    <div style="margin-bottom:16px;">
                        <p style="font-weight:600;font-size:13px;margin-bottom:8px;"><?= $i+1 ?>. <?= htmlspecialchars($q['question']) ?></p>
                        <?php foreach (['a','b','c','d'] as $opt): ?>
                            <?php if (!empty($q['option_' . $opt])): ?>
                                <label style="display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:6px;cursor:pointer;margin-bottom:4px;border:1px solid #E9ECEC;font-size:13px;" onmouseover="this.style.background='#F5F7F6'" onmouseout="this.style.background=''">
                                    <input type="radio" name="reponse_<?= $q['id_question'] ?>" value="<?= $opt ?>" required>
                                    <strong style="color:<?= $couleur ?>;"><?= strtoupper($opt) ?>.</strong> <?= htmlspecialchars($q['option_' . $opt]) ?>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn-dash btn-dash-primary" style="width:100%;margin-top:8px;">
                    &#127919; Valider mes réponses
                </button>
            </form>
        </div>
        <?php else: ?>
        <div class="panel" style="padding:20px;text-align:center;color:#999;">
            <p>&#9888; Aucune question de quiz pour cette formation.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
