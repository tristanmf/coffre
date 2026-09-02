<?php
/** Onglet « Preuves » : le conservatoire. Consultation seule — le dépôt se fait depuis le Mac. */

$toutes = preuves();
usort($toutes, fn($a, $b) => strcmp($b['date'], $a['date']));

/* --------------------------------------------------------- fiche détaillée */

if (isset($_GET['piece'])) {
    $p = preuve_par_id((string) $_GET['piece']);
    if (!$p) {
        echo '<div class="avis erreur">Pièce introuvable.</div>';
        return;
    }
    $retiree = !empty($p['retiree']);
    $dossier = preuve_dossier($p);

    $intact = true;
    if (!$retiree) {
        foreach ($p['fichiers'] ?? [] as $f) {
            $chemin = $dossier . '/' . $f['nom'];
            if (!is_file($chemin) || hash_file('sha256', $chemin) !== $f['sha256']) {
                $intact = false;
            }
        }
    }

    echo '<p style="margin-top:1.5rem"><a href="?section=preuves">← toutes les pièces</a></p>';
    echo '<h2 style="margin-top:.5rem">', h($p['titre'] ?: $p['url']), '</h2>';

    if ($retiree) {
        echo '<div class="avis"><strong>Pièce retirée le ', h(date_fr($p['retiree'])), '.</strong> ',
             'Ses fichiers ont été effacés du serveur. La fiche et les empreintes restent au registre : ',
             'la chaîne n\'est pas cassée, et le retrait se voit.',
             (!empty($p['motif']) ? '<br>Motif : ' . h($p['motif']) : ''), '</div>';
    } else {
        echo $intact
            ? '<div class="avis">Les fichiers correspondent exactement à leurs empreintes du jour de la capture.</div>'
            : '<div class="avis erreur">Attention : au moins un fichier ne correspond plus à son empreinte d\'origine.</div>';
    }

    echo '<table class="releve">';
    echo '<tr><th>Adresse d\'origine</th><td><a href="', h($p['url']), '" rel="noopener nofollow" target="_blank">', h($p['url']), '</a></td></tr>';
    echo '<tr><th>Capturé le</th><td>', h(date_fr($p['date'])), '</td></tr>';
    if (!empty($p['note'])) {
        echo '<tr><th>Note</th><td>', nl2br(h($p['note'])), '</td></tr>';
    }
    if (!empty($p['capture_par'])) {
        echo '<tr><th>Capturé depuis</th><td>', h($p['capture_par']), '</td></tr>';
    }
    echo '<tr><th>Identifiant</th><td class="mono">', h($p['id']), '</td></tr>';
    echo '<tr><th>Maillon de chaîne</th><td class="mono">', h($p['maillon'] ?? '—'), '</td></tr>';
    echo '</table>';

    echo '<h2>Pièces jointes</h2>';
    foreach ($p['fichiers'] ?? [] as $f) {
        echo '<div class="carte"><h3>', h($f['nom']), '</h3><div class="meta"><span>',
             h(taille_lisible((int) $f['taille'])), '</span><span class="mono">', h(substr($f['sha256'], 0, 16)), '…</span>',
             ($retiree ? '<span>effacé</span>' : ''), '</div>';
        if (!$retiree) {
            $u = 'v.php?piece=' . urlencode($p['id']) . '&f=' . urlencode($f['nom']);
            echo '<div class="actions"><a class="bouton discret" href="', h($u), '" target="_blank">Ouvrir</a></div>';
        }
        echo '</div>';
    }

    if (!$retiree && !empty($p['capture'])) {
        echo '<h2>Capture</h2><img style="max-width:100%;border:1px solid var(--trait);border-radius:6px" src="',
             h('v.php?piece=' . urlencode($p['id']) . '&f=' . urlencode($p['capture'])), '" alt="Capture de la page">';
    }

    if (!$retiree) {
        echo '<h2>Retirer cette pièce</h2>';
        echo '<p class="chapo">Les fichiers seront effacés du serveur. La fiche, les empreintes et le maillon '
           . 'de chaîne restent — un retrait ne s\'efface pas discrètement, il se constate.</p>';
        echo '<form method="post" onsubmit="return confirm(\'Effacer les fichiers de cette pièce ? La fiche restera au registre.\')">';
        echo '<input type="hidden" name="action" value="retirer_preuve">';
        echo '<input type="hidden" name="csrf" value="', h(csrf()), '">';
        echo '<input type="hidden" name="piece" value="', h($p['id']), '">';
        echo '<label for="motif">Pourquoi <span class="meta">(facultatif, restera au registre)</span></label>';
        echo '<input type="text" id="motif" name="motif" placeholder="capture ratée, page blanche">';
        echo '<div class="actions"><button class="danger">Retirer la pièce</button></div></form>';
    }
    return;
}

/* ------------------------------------------------------------- la galerie */

$voir_retirees = isset($_GET['retirees']);
$retirees = array_values(array_filter($toutes, fn($p) => !empty($p['retiree'])));
$actives  = array_values(array_filter($toutes, fn($p) => empty($p['retiree'])));
$toutes = $voir_retirees ? $retirees : $actives;

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $toutes = array_values(array_filter($toutes, function ($p) use ($q) {
        $foin = mb_strtolower(($p['titre'] ?? '') . ' ' . $p['url'] . ' ' . ($p['note'] ?? ''));
        return str_contains($foin, mb_strtolower($q));
    }));
}
?>
<h2>
  <?= $voir_retirees ? 'Pièces retirées' : 'Conservatoire' ?>
  <span class="meta">(<?= count($voir_retirees ? $retirees : $actives) ?>)</span>
</h2>
<form method="get" style="margin-bottom:1rem">
  <input type="hidden" name="section" value="preuves">
  <?php if ($voir_retirees): ?><input type="hidden" name="retirees" value="1"><?php endif; ?>
  <label for="q">Chercher dans les adresses, les titres et les notes</label>
  <div class="lien-boite">
    <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="ex. hydroxychloroquine">
    <button>Chercher</button>
  </div>
</form>
<?php if ($retirees): ?>
  <p class="meta" style="margin-bottom:1.5rem">
    <?php if ($voir_retirees): ?>
      <a href="?section=preuves">← revenir au conservatoire</a>
    <?php else: ?>
      <a href="?section=preuves&amp;retirees=1"><?= count($retirees) ?> pièce<?= count($retirees) > 1 ? 's' : '' ?> retirée<?= count($retirees) > 1 ? 's' : '' ?></a>
    <?php endif; ?>
  </p>
<?php endif; ?>

<?php if (!$toutes): ?>
  <p class="chapo">
    <?= $q !== '' ? 'Aucune pièce ne correspond.' : 'Le conservatoire est vide. Les captures s\'ajoutent depuis le Mac, avec la commande <code>preuve</code>.' ?>
  </p>
<?php else: ?>
  <div class="vignettes">
    <?php foreach ($toutes as $p): ?>
      <a class="vignette" href="?section=preuves&amp;piece=<?= h(urlencode($p['id'])) ?>">
        <?php if (!empty($p['capture']) && empty($p['retiree'])): ?>
          <img src="v.php?piece=<?= h(urlencode($p['id'])) ?>&amp;f=<?= h(urlencode($p['capture'])) ?>" alt="" loading="lazy">
        <?php endif; ?>
        <div class="bas">
          <div class="titre"><?= h(mb_strimwidth($p['titre'] ?: $p['url'], 0, 70, '…')) ?></div>
          <div class="meta"><?= h(date_fr($p['date'])) ?><?= !empty($p['retiree']) ? ' · retirée' : '' ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
