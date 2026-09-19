<?php
// ============================================================
// FleetIoT — Simandou 2040
// Fichier : admin/fiche_formation.php
// Rôle    : Détail formation — quiz, assignation conducteurs
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$idFormation = (int) ($_GET['id'] ?? 0);
$message = '';
$messageType = '';

// Récupérer la formation
$stmt = $pdo->prepare("SELECT f.*, u.nom AS createur_nom, u.prenom AS createur_prenom FROM formations f LEFT JOIN utilisateurs u ON f.id_createur = u.id_user WHERE f.id_formation = ?");
$stmt->execute([$idFormation]);
$formation = $stmt->fetch();
if (!$formation) { header('Location: formations.php'); exit(); }

// ====== AJOUTER QUESTION ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter_question') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $stmt = $pdo->prepare("INSERT INTO formation_questions (id_formation, question, option_a, option_b, option_c, option_d, bonne_reponse, ordre) VALUES (?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(ordre),0)+1 FROM formation_questions fq WHERE fq.id_formation = ?))");
        $stmt->execute([$idFormation, clean($_POST['question']), clean($_POST['option_a']), clean($_POST['option_b']), clean($_POST['option_c'] ?? ''), clean($_POST['option_d'] ?? ''), $_POST['bonne_reponse'], $idFormation]);
        $message = 'Question ajoutée.'; $messageType = 'success';
    }
}

// ====== SUPPRIMER QUESTION ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer_question') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $pdo->prepare("DELETE FROM formation_questions WHERE id_question = ? AND id_formation = ?")->execute([(int)$_POST['id_question'], $idFormation]);
        $message = 'Question supprimée.'; $messageType = 'success';
    }
}

// ====== ASSIGNER CONDUCTEURS ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assigner') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $ids = $_POST['conducteurs'] ?? [];
        $nbAssignes = 0;
        foreach ($ids as $idC) {
            try {
                $pdo->prepare("INSERT INTO formation_inscriptions (id_formation, id_conducteur) VALUES (?, ?)")->execute([$idFormation, (int)$idC]);
                $nbAssignes++;
            } catch (Exception $e) { /* doublon ignoré */ }
        }
        $message = "$nbAssignes conducteur(s) assigné(s)."; $messageType = 'success';
    }
}

// ====== MODIFIER CONTENU ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'modifier_contenu') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $video_url = clean($_POST['contenu_url'] ?? '');
        $contenu_texte = $_POST['contenu_texte'] ?? '';
        $note_min = (int) ($_POST['note_minimale'] ?? 70);
        $duree = !empty($_POST['duree_minutes']) ? (int) $_POST['duree_minutes'] : null;

        $contenu_pdf = $formation['contenu_pdf'];
        if (!empty($_FILES['contenu_pdf']['name'])) {
            $dossier = '../assets/uploads/formations/';
            if (!is_dir($dossier)) mkdir($dossier, 0755, true);
            $ext = strtolower(pathinfo($_FILES['contenu_pdf']['name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $nomFichier = 'formation_' . time() . '.pdf';
                if (move_uploaded_file($_FILES['contenu_pdf']['tmp_name'], $dossier . $nomFichier)) {
                    $contenu_pdf = 'assets/uploads/formations/' . $nomFichier;
                }
            }
        }

        $pdo->prepare("UPDATE formations SET contenu_texte=?, contenu_pdf=?, contenu_url=?, note_minimale=?, duree_minutes=? WHERE id_formation=?")
            ->execute([$contenu_texte, $contenu_pdf, $video_url, $note_min, $duree, $idFormation]);

        // Recharger
        $stmt = $pdo->prepare("SELECT f.*, u.nom AS createur_nom, u.prenom AS createur_prenom FROM formations f LEFT JOIN utilisateurs u ON f.id_createur = u.id_user WHERE f.id_formation = ?");
        $stmt->execute([$idFormation]);
        $formation = $stmt->fetch();
        $message = 'Formation mise à jour.'; $messageType = 'success';
    }
}

// ====== DONNÉES ======
$questions = $pdo->prepare("SELECT * FROM formation_questions WHERE id_formation = ? ORDER BY ordre ASC");
$questions->execute([$idFormation]);
$questions = $questions->fetchAll();

$inscriptions = $pdo->prepare("
    SELECT fi.*, c.nom, c.prenom, c.photo
    FROM formation_inscriptions fi
    JOIN conducteurs c ON fi.id_conducteur = c.id_conducteur
    WHERE fi.id_formation = ?
    ORDER BY fi.statut ASC, c.nom ASC
");
$inscriptions->execute([$idFormation]);
$inscriptions = $inscriptions->fetchAll();

$idsConducteursInscrits = array_column($inscriptions, 'id_conducteur');

$conducteurs = $pdo->query("SELECT id_conducteur, nom, prenom FROM conducteurs WHERE statut = 'actif' ORDER BY nom ASC")->fetchAll();
$conducteursNonInscrits = array_filter($conducteurs, fn($c) => !in_array($c['id_conducteur'], $idsConducteursInscrits));

$typesLibelles = ['conduite_minier'=>'Conduite minier','ecoconduite'=>'Éco-conduite','maintenance'=>'Maintenance','securite'=>'Sécurité','reglementation'=>'Réglementation','environnement'=>'Environnement'];
$typesColors   = ['conduite_minier'=>'#0F6E56','ecoconduite'=>'#1D9E75','maintenance'=>'#BA7517','securite'=>'#E24B4A','reglementation'=>'#7C5CBF','environnement'=>'#2196F3'];
$couleur = $typesColors[$formation['categorie']] ?? '#0F6E56';

$nbCertifies = count(array_filter($inscriptions, fn($i) => $i['statut'] === 'certifie'));
$pctCertif   = count($inscriptions) > 0 ? round($nbCertifies / count($inscriptions) * 100) : 0;

$csrfToken = generateCsrfToken();
$pageTitle = 'Formation — ' . $formation['titre'];
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <span style="background:<?= $couleur ?>;color:#fff;font-size:11px;padding:3px 10px;border-radius:10px;font-weight:600;"><?= $typesLibelles[$formation['categorie']] ?></span>
        <h1 style="margin-top:6px;"><?= htmlspecialchars($formation['titre']) ?></h1>
        <p class="subtitle"><?= htmlspecialchars($formation['description'] ?? '') ?></p>
    </div>
    <div class="dash-actions">
        <a href="formations.php" class="btn-dash btn-dash-outline">&#8592; Toutes les formations</a>
    </div>
</div>

<?php if (isset($_GET['nouveau'])): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:#E1F5EE;color:#085041;border:none;">
        ✅ Formation créée ! Ajoutez maintenant les questions du quiz et assignez des conducteurs.
    </div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType==='success'?'#E1F5EE':'#FCEBEB' ?>;color:<?= $messageType==='success'?'#085041':'#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- KPI formation -->
<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card"><div class="kpi-info"><p class="label">Questions quiz</p><p class="value"><?= count($questions) ?></p><p class="trend">Note min : <?= $formation['note_minimale'] ?> %</p></div><div class="kpi-icon">&#10067;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Conducteurs inscrits</p><p class="value"><?= count($inscriptions) ?></p><p class="trend">Assignés</p></div><div class="kpi-icon">&#128104;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Certifiés</p><p class="value" style="color:#1D9E75;"><?= $nbCertifies ?></p><p class="trend"><?= $pctCertif ?> % de taux</p></div><div class="kpi-icon">&#127941;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Durée estimée</p><p class="value"><?= $formation['duree_minutes'] ? $formation['duree_minutes'] . ' min' : '—' ?></p><p class="trend">Par conducteur</p></div><div class="kpi-icon">&#9201;</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

    <!-- ====== QUIZ ====== -->
    <div style="display:flex;flex-direction:column;gap:16px;">
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:16px;">&#10067; Questions du quiz (<?= count($questions) ?>)</h3>

            <?php if (empty($questions)): ?>
                <p style="color:#999;font-size:13px;text-align:center;padding:20px 0;">Aucune question. Ajoutez-en ci-dessous.</p>
            <?php endif; ?>

            <?php foreach ($questions as $i => $q): ?>
                <div style="background:#F5F7F6;border-radius:8px;padding:14px;margin-bottom:10px;border-left:3px solid <?= $couleur ?>;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                        <p style="font-weight:600;font-size:13px;margin-bottom:8px;">Q<?= $i+1 ?>. <?= htmlspecialchars($q['question']) ?></p>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette question ?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="supprimer_question">
                            <input type="hidden" name="id_question" value="<?= $q['id_question'] ?>">
                            <button type="submit" style="background:none;border:none;color:#E24B4A;cursor:pointer;font-size:14px;">&#10005;</button>
                        </form>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px;">
                        <?php foreach (['a','b','c','d'] as $opt): ?>
                            <?php if (!empty($q['option_' . $opt])): ?>
                                <div style="padding:5px 8px;border-radius:4px;background:<?= $q['bonne_reponse']===$opt?'#E1F5EE':'#fff' ?>;border:1px solid <?= $q['bonne_reponse']===$opt?'#1D9E75':'#E9ECEC' ?>;color:<?= $q['bonne_reponse']===$opt?'#085041':'#1A1A1A' ?>;">
                                    <?= $q['bonne_reponse']===$opt?'✅ ':'' ?><?= strtoupper($opt) ?>. <?= htmlspecialchars($q['option_' . $opt]) ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Ajouter question -->
            <details style="margin-top:12px;">
                <summary style="cursor:pointer;color:#0F6E56;font-weight:600;font-size:13px;padding:8px 0;">+ Ajouter une question</summary>
                <form method="POST" style="margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="ajouter_question">
                    <div class="form-group"><label style="font-size:12px;">Question *</label><input type="text" name="question" required placeholder="Ex: Quelle est la vitesse max sur le corridor ?"></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div class="form-group" style="margin:0;"><label style="font-size:12px;">Option A *</label><input type="text" name="option_a" required></div>
                        <div class="form-group" style="margin:0;"><label style="font-size:12px;">Option B *</label><input type="text" name="option_b" required></div>
                        <div class="form-group" style="margin:0;"><label style="font-size:12px;">Option C</label><input type="text" name="option_c"></div>
                        <div class="form-group" style="margin:0;"><label style="font-size:12px;">Option D</label><input type="text" name="option_d"></div>
                    </div>
                    <div class="form-group"><label style="font-size:12px;">Bonne réponse *</label>
                        <select name="bonne_reponse" required>
                            <option value="a">A</option><option value="b">B</option><option value="c">C</option><option value="d">D</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-dash btn-dash-primary" style="width:100%;">Ajouter la question</button>
                </form>
            </details>
        </div>

        <!-- Modifier contenu -->
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:16px;">&#128218; Contenu de la formation</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="modifier_contenu">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                    <div class="form-group" style="margin:0;"><label style="font-size:12px;">Note minimale (%)</label><input type="number" name="note_minimale" value="<?= $formation['note_minimale'] ?>" min="0" max="100"></div>
                    <div class="form-group" style="margin:0;"><label style="font-size:12px;">Durée (minutes)</label><input type="number" name="duree_minutes" value="<?= $formation['duree_minutes'] ?>"></div>
                </div>
                <div class="form-group"><label style="font-size:12px;">&#127916; Lien vidéo</label><input type="url" name="contenu_url" value="<?= htmlspecialchars($formation['contenu_url'] ?? '') ?>" placeholder="https://youtube.com/..."></div>
                <div class="form-group"><label style="font-size:12px;">&#128196; PDF (remplace l'actuel)</label><input type="file" name="contenu_pdf" accept=".pdf">
                <?php if ($formation['contenu_pdf']): ?><p style="font-size:11px;color:#1D9E75;margin-top:4px;">✅ PDF actuel : <?= basename($formation['contenu_pdf']) ?></p><?php endif; ?></div>
                <div class="form-group"><label style="font-size:12px;">&#128218; Contenu texte</label><textarea name="contenu_texte" rows="6"><?= htmlspecialchars($formation['contenu_texte'] ?? '') ?></textarea></div>
                <button type="submit" class="btn-dash btn-dash-primary" style="width:100%;">Enregistrer le contenu</button>
            </form>
        </div>
    </div>

    <!-- ====== CONDUCTEURS ====== -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Assigner -->
        <?php if (!empty($conducteursNonInscrits)): ?>
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:14px;">&#128104; Assigner des conducteurs</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="assigner">
                <div style="max-height:200px;overflow-y:auto;border:1px solid #E9ECEC;border-radius:6px;padding:8px;margin-bottom:12px;">
                    <?php foreach ($conducteursNonInscrits as $c): ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:6px;cursor:pointer;border-radius:4px;" onmouseover="this.style.background='#F5F7F6'" onmouseout="this.style.background=''">
                            <input type="checkbox" name="conducteurs[]" value="<?= $c['id_conducteur'] ?>">
                            <span style="font-size:13px;"><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn-dash btn-dash-primary" style="width:100%;">Assigner les conducteurs sélectionnés</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Liste inscrits -->
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:14px;">&#128203; Conducteurs inscrits (<?= count($inscriptions) ?>)</h3>
            <?php if (empty($inscriptions)): ?>
                <p style="color:#999;font-size:13px;text-align:center;padding:20px 0;">Aucun conducteur assigné.</p>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($inscriptions as $ins): ?>
                        <?php
                            $statutColors = ['assigne'=>['#5C6B68','#F5F7F6'],'en_cours'=>['#BA7517','#FFFBF0'],'certifie'=>['#1D9E75','#E1F5EE']];
                            $sc = $statutColors[$ins['statut']] ?? ['#5C6B68','#F5F7F6'];
                            $statutLabels = ['assigne'=>'Assigné','en_cours'=>'En cours','certifie'=>'Certifié ✅'];
                        ?>
                        <div style="background:<?= $sc[1] ?>;border-radius:8px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                            <div>
                                <p style="font-weight:600;font-size:13px;margin-bottom:2px;"><?= htmlspecialchars($ins['prenom'] . ' ' . $ins['nom']) ?></p>
                                <p style="font-size:11px;color:#5C6B68;">
                                    Tentatives : <?= $ins['nb_tentatives'] ?>
                                    <?= $ins['score_quiz'] !== null ? ' — Score : ' . $ins['score_quiz'] . '%' : '' ?>
                                    <?= $ins['date_completion'] ? ' — ' . date('d/m/Y', strtotime($ins['date_completion'])) : '' ?>
                                </p>
                            </div>
                            <div style="text-align:right;">
                                <span style="background:<?= $sc[0] ?>;color:#fff;font-size:11px;padding:3px 10px;border-radius:10px;"><?= $statutLabels[$ins['statut']] ?></span>
                                <?php if ($ins['statut'] === 'certifie'): ?>
                                    <br><a href="../rapports/attestation_pdf.php?id_conducteur=<?= $ins['id_conducteur'] ?>&id_formation=<?= $idFormation ?>" style="font-size:11px;color:#0F6E56;margin-top:4px;display:inline-block;">&#128196; Attestation</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
