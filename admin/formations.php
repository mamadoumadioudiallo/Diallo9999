<?php
// ============================================================
// FleetIoT — Simandou 2040
// Fichier : admin/formations.php
// Rôle    : Liste et création des formations Simandou Academy
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$message = '';
$messageType = '';

// ====== CRÉER UNE FORMATION ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'creer') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée.'; $messageType = 'error';
    } else {
        $titre       = clean($_POST['titre'] ?? '');
        $description = clean($_POST['description'] ?? '');
        $categorie     = $_POST['categorie'] ?? 'securite';
        $note_min      = (int) ($_POST['note_minimale'] ?? 70);
        $duree         = !empty($_POST['duree_minutes']) ? (int) $_POST['duree_minutes'] : null;
        $video_url     = clean($_POST['contenu_url'] ?? '');
        $contenu_texte = $_POST['contenu_texte'] ?? '';

        // Upload PDF
        $contenu_pdf = null;
        if (!empty($_FILES['contenu_pdf']['name'])) {
            $dossier = '../assets/uploads/formations/';
            if (!is_dir($dossier)) mkdir($dossier, 0755, true);
            $ext = strtolower(pathinfo($_FILES['contenu_pdf']['name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf' && $_FILES['contenu_pdf']['size'] <= 10 * 1024 * 1024) {
                $nomFichier = 'formation_' . time() . '_' . uniqid() . '.pdf';
                if (move_uploaded_file($_FILES['contenu_pdf']['tmp_name'], $dossier . $nomFichier)) {
                    $contenu_pdf = 'assets/uploads/formations/' . $nomFichier;
                }
            }
        }

        if (empty($titre)) {
            $message = 'Le titre est obligatoire.'; $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO formations (titre, description, categorie, contenu_texte, contenu_pdf, contenu_url, note_minimale, duree_minutes, id_createur)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$titre, $description, $categorie, $contenu_texte, $contenu_pdf, $video_url, $note_min, $duree, $_SESSION['user_id']]);
            $idFormation = $pdo->lastInsertId();
            $message = 'Formation créée avec succès.'; $messageType = 'success';
            header("Location: fiche_formation.php?id=$idFormation&nouveau=1");
            exit();
        }
    }
}

// ====== ARCHIVER ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archiver') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $pdo->prepare("UPDATE formations SET statut = 'archivee' WHERE id_formation = ?")->execute([(int)$_POST['id_formation']]);
        $message = 'Formation archivée.'; $messageType = 'success';
    }
}

// ====== LISTE FORMATIONS ======
$filtreStatut = $_GET['statut'] ?? 'active';
$filtreType   = $_GET['categorie']   ?? '';

$conditions = [];
$params = [];
if ($filtreStatut !== 'toutes') { $conditions[] = "f.statut = ?"; $params[] = $filtreStatut; }
if ($filtreType) { $conditions[] = "f.categorie = ?"; $params[] = $filtreType; }
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $pdo->prepare("
    SELECT f.*,
           u.nom AS createur_nom, u.prenom AS createur_prenom,
           COUNT(DISTINCT fi.id_conducteur) AS nb_inscrits,
           COUNT(DISTINCT CASE WHEN fi.statut = 'certifie' THEN fi.id_conducteur END) AS nb_certifies,
           COUNT(DISTINCT fq.id_question) AS nb_questions
    FROM formations f
    LEFT JOIN utilisateurs u ON f.id_createur = u.id_user
    LEFT JOIN formation_inscriptions fi ON f.id_formation = fi.id_formation
    LEFT JOIN formation_questions fq ON f.id_formation = fq.id_formation
    $where
    GROUP BY f.id_formation
    ORDER BY f.date_creation DESC
");
$stmt->execute($params);
$formations = $stmt->fetchAll();

// KPI
$kpiStmt = $pdo->query("
    SELECT
        COUNT(DISTINCT f.id_formation) AS total,
        COUNT(DISTINCT CASE WHEN f.statut='active' THEN f.id_formation END) AS actives,
        COUNT(DISTINCT fi.id_conducteur) AS inscrits,
        COUNT(DISTINCT CASE WHEN fi.statut='certifie' THEN fi.id_conducteur END) AS certifies
    FROM formations f
    LEFT JOIN formation_inscriptions fi ON f.id_formation = fi.id_formation
");
$kpi = $kpiStmt->fetch();

$typesLibelles = ['conduite_minier'=>'Conduite minier','ecoconduite'=>'Éco-conduite','maintenance'=>'Maintenance','securite'=>'Sécurité','reglementation'=>'Réglementation','environnement'=>'Environnement'];
$typesColors   = ['conduite_minier'=>'#0F6E56','ecoconduite'=>'#1D9E75','maintenance'=>'#BA7517','securite'=>'#E24B4A','reglementation'=>'#7C5CBF','environnement'=>'#2196F3'];

$csrfToken = generateCsrfToken();
$pageTitle = 'Formations — Simandou Academy';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#127979; Simandou Academy</h1>
        <p class="subtitle">Gestion des formations des conducteurs</p>
    </div>
    <div class="dash-actions">
        <button class="btn-dash btn-dash-primary" onclick="document.getElementById('modalCreer').style.display='flex'">
            + Nouvelle formation
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType==='success'?'#E1F5EE':'#FCEBEB' ?>;color:<?= $messageType==='success'?'#085041':'#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- KPI -->
<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card">
        <div class="kpi-info"><p class="label">Formations actives</p><p class="value"><?= $kpi['actives'] ?></p><p class="trend">Disponibles</p></div>
        <div class="kpi-icon">&#127979;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info"><p class="label">Total formations</p><p class="value"><?= $kpi['total'] ?></p><p class="trend">Créées</p></div>
        <div class="kpi-icon">&#128218;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info"><p class="label">Conducteurs inscrits</p><p class="value"><?= $kpi['inscrits'] ?></p><p class="trend">En formation</p></div>
        <div class="kpi-icon">&#128104;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info"><p class="label">Certifiés</p><p class="value" style="color:#1D9E75;"><?= $kpi['certifies'] ?></p><p class="trend">Quiz validé ✅</p></div>
        <div class="kpi-icon">&#127941;</div>
    </div>
</div>

<!-- Filtres -->
<div class="panel" style="padding:14px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
            <label style="font-size:12px;">Statut</label>
            <select name="statut" onchange="this.form.submit()">
                <option value="active"  <?= $filtreStatut==='active'  ?'selected':'' ?>>Actives</option>
                <option value="archivee"<?= $filtreStatut==='archivee'?'selected':'' ?>>Archivées</option>
                <option value="toutes"  <?= $filtreStatut==='toutes'  ?'selected':'' ?>>Toutes</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
            <label style="font-size:12px;">Type</label>
            <select name="categorie" onchange="this.form.submit()">
                <option value="">Tous les types</option>
                <?php foreach ($typesLibelles as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filtreType===$k?'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <a href="formations.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<!-- Liste formations -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-bottom:16px;">
    <?php if (empty($formations)): ?>
        <div class="panel" style="padding:40px;text-align:center;color:#999;grid-column:1/-1;">
            <p style="font-size:24px;margin-bottom:8px;">&#127979;</p>
            <p>Aucune formation. Créez la première !</p>
        </div>
    <?php endif; ?>
    <?php foreach ($formations as $f): ?>
        <?php
            $couleur = $typesColors[$f['categorie']] ?? '#0F6E56';
            $pctCertif = $f['nb_inscrits'] > 0 ? round($f['nb_certifies'] / $f['nb_inscrits'] * 100) : 0;
        ?>
        <div class="panel" style="padding:0;overflow:hidden;">
            <!-- Bandeau type -->
            <div style="background:<?= $couleur ?>;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;">
                <span style="color:#fff;font-size:11px;font-weight:600;text-transform:uppercase;"><?= $typesLibelles[$f['categorie']] ?></span>
                <?php if ($f['statut'] === 'archivee'): ?>
                    <span style="background:rgba(0,0,0,0.2);color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;">Archivée</span>
                <?php endif; ?>
            </div>
            <div style="padding:16px;">
                <h3 style="margin-bottom:6px;font-size:15px;"><?= htmlspecialchars($f['titre']) ?></h3>
                <p style="font-size:12px;color:#5C6B68;margin-bottom:12px;"><?= htmlspecialchars(mb_substr($f['description'] ?? '', 0, 100)) ?>...</p>

                <!-- Badges contenu -->
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
                    <?php if ($f['contenu_texte']): ?><span style="background:#E8F4F1;color:#0F6E56;font-size:11px;padding:3px 8px;border-radius:10px;">&#128196; Cours</span><?php endif; ?>
                    <?php if ($f['contenu_pdf']): ?><span style="background:#FFF0E8;color:#BA7517;font-size:11px;padding:3px 8px;border-radius:10px;">&#128196; PDF</span><?php endif; ?>
                    <?php if ($f['contenu_url']): ?><span style="background:#F0E8FF;color:#7C5CBF;font-size:11px;padding:3px 8px;border-radius:10px;">&#127916; Vidéo</span><?php endif; ?>
                    <span style="background:#F5F7F6;color:#5C6B68;font-size:11px;padding:3px 8px;border-radius:10px;">&#10067; <?= $f['nb_questions'] ?> questions</span>
                    <?php if ($f['duree_minutes']): ?><span style="background:#F5F7F6;color:#5C6B68;font-size:11px;padding:3px 8px;border-radius:10px;">&#9201; <?= $f['duree_minutes'] ?> min</span><?php endif; ?>
                </div>

                <!-- Progression -->
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:#5C6B68;margin-bottom:4px;">
                        <span><?= $f['nb_certifies'] ?> / <?= $f['nb_inscrits'] ?> certifiés</span>
                        <span><?= $pctCertif ?> %</span>
                    </div>
                    <div style="background:#E9ECEC;border-radius:4px;height:6px;">
                        <div style="background:<?= $couleur ?>;width:<?= $pctCertif ?>%;height:6px;border-radius:4px;"></div>
                    </div>
                </div>

                <!-- Note minimale -->
                <p style="font-size:11px;color:#5C6B68;margin-bottom:14px;">Note minimale : <strong><?= $f['note_minimale'] ?> %</strong></p>

                <!-- Actions -->
                <div style="display:flex;gap:8px;">
                    <a href="fiche_formation.php?id=<?= $f['id_formation'] ?>" class="btn-dash btn-dash-primary" style="flex:1;text-align:center;font-size:12px;">
                        Gérer la formation
                    </a>
                    <?php if ($f['statut'] === 'active'): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Archiver cette formation ?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="archiver">
                        <input type="hidden" name="id_formation" value="<?= $f['id_formation'] ?>">
                        <button type="submit" class="btn-dash btn-dash-outline" style="font-size:12px;padding:6px 10px;">&#128451;</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal création formation -->
<div id="modalCreer" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:680px;max-height:90vh;overflow-y:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h2 style="margin:0;">&#127979; Nouvelle formation</h2>
            <button onclick="document.getElementById('modalCreer').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;">&#10005;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="creer">

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-bottom:12px;">
                <div class="form-group" style="margin:0;">
                    <label>Titre de la formation *</label>
                    <input type="text" name="titre" placeholder="Ex: Sécurité sur le corridor Simandou" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Type</label>
                    <select name="categorie">
                        <?php foreach ($typesLibelles as $k => $v): ?>
                            <option value="<?= $k ?>"><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="2" placeholder="Description courte de la formation..."></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div class="form-group" style="margin:0;">
                    <label>Note minimale pour certifier (%)</label>
                    <input type="number" name="note_minimale" value="70" min="0" max="100">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Durée estimée (minutes)</label>
                    <input type="number" name="duree_minutes" placeholder="Ex: 45">
                </div>
            </div>

            <div class="form-group">
                <label>&#127916; Lien vidéo (YouTube/Vimeo)</label>
                <input type="url" name="contenu_url" placeholder="https://youtube.com/watch?v=...">
            </div>

            <div class="form-group">
                <label>&#128196; Support PDF (max 10 Mo)</label>
                <input type="file" name="contenu_pdf" accept=".pdf">
            </div>

            <div class="form-group">
                <label>&#128218; Contenu du cours (texte)</label>
                <textarea name="contenu_texte" rows="5" placeholder="Rédigez ici le contenu du cours..."></textarea>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;"
                        onclick="document.getElementById('modalCreer').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Créer la formation →</button>
            </div>
            <p style="font-size:12px;color:#5C6B68;margin-top:8px;text-align:center;">Vous pourrez ajouter les questions du quiz après la création.</p>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
