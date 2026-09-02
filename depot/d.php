<?php
/** Page de retrait : c'est l'adresse qu'on envoie au destinataire. Aucun mot de passe. */

declare(strict_types=1);
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');

$jeton = (string) ($_GET['j'] ?? '');
if ($jeton === '' && !empty($_SERVER['PATH_INFO'])) {
    $jeton = ltrim($_SERVER['PATH_INFO'], '/');
}
$jeton = preg_replace('/[^A-Za-z0-9_-]/', '', $jeton) ?? '';

partages_nettoyer();
$p = $jeton === '' ? null : partage_par_jeton($jeton);

$groupe = $p && ($p['type'] ?? 'fichier') === 'groupe';
$contenu = [];
$valable = false;

if ($p) {
    if ($groupe) {
        $contenu = is_dir(partage_dossier($p)) ? dossier_lister(partage_dossier($p)) : [];
        $valable = $contenu !== [];
    } else {
        $valable = is_file(partage_dossier($p) . '/' . $p['nom']);
    }
}

if (!$valable) {
    http_response_code(404);
} else {
    // WhatsApp, Signal, Slack et les clients mail vont chercher la page pour
    // en fabriquer un aperçu. Ce n'est pas un retrait : on ne le compte pas.
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $robot = $ua === '' || preg_match(
        '/bot|crawler|spider|preview|whatsapp|telegram|slack|discord|twitter|facebookexternalhit|linkedin|skype|embed|curl|wget|python-requests/i',
        $ua
    );
    if (!$robot) {
        partage_compter($p['jeton']);
    }
}

$base = 'f/' . rawurlencode((string) ($p['jeton'] ?? ''));
$qui = (string) (config()['proprietaire'] ?? '');
$prenom = (string) (config()['prenom'] ?? '');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($valable ? $p['nom'] : 'Lien expiré') ?></title>
<link rel="icon" href="<?= h(rtrim(config()['adresse'], '/')) ?>/favicon.png" type="image/png">
<link rel="stylesheet" href="<?= h(rtrim(config()['adresse'], '/') . '/' . css()) ?>">
</head>
<body>
<div class="page <?= $groupe ? '' : 'etroit' ?> retrait">
<img class="logo" src="<?= h(rtrim(config()['adresse'], '/')) ?>/logo.png" alt="" width="96" height="96">
<?php if (!$valable): ?>
  <h1>Ce lien n'est plus valable</h1>
  <p class="chapo">Le fichier a expiré ou a été retiré du serveur. Redemande-le<?= $prenom !== '' ? ' à ' . h($prenom) : '' ?> : il peut être redéposé en une minute.</p>

<?php elseif ($groupe): ?>
  <p class="meta">Des fichiers partagés<?= $qui !== '' ? ' par ' . h($qui) : '' ?></p>
  <div class="nom"><?= h($p['nom']) ?></div>
  <p class="meta">
    <span><?= count($contenu) ?> fichier<?= count($contenu) > 1 ? 's' : '' ?></span>
    <span><?= h(taille_lisible(array_sum(array_column($contenu, 'taille')))) ?> en tout</span>
    <span>disponible jusqu'au <?= h(date_fr($p['expire'])) ?></span>
  </p>
  <table class="releve" style="text-align:left;margin-top:2rem">
    <?php foreach ($contenu as $f): ?>
      <tr>
        <th style="width:auto;color:var(--encre)">
          <a href="<?= h($base . '/' . url_chemin($f['chemin'])) ?>" download><?= h($f['chemin']) ?></a>
        </th>
        <td style="width:6rem;text-align:right;color:var(--gris)"><?= h(taille_lisible((int) $f['taille'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="meta" style="margin-top:1.5rem">Chaque ligne se télécharge séparément : on prend ce dont on a besoin.</p>

<?php else: ?>
  <p class="meta">Un fichier partagé<?= $qui !== '' ? ' par ' . h($qui) : '' ?></p>
  <div class="nom"><?= h($p['nom']) ?></div>
  <p class="meta">
    <span><?= h(taille_lisible((int) $p['taille'])) ?></span>
    <span>disponible jusqu'au <?= h(date_fr($p['expire'])) ?></span>
  </p>
  <a class="bouton" href="<?= h($base . '/' . rawurlencode($p['nom'])) ?>" download>Télécharger</a>
  <?php if (!empty($p['sha256'])): ?>
    <p class="meta" style="margin-top:2rem">Empreinte SHA-256, pour vérifier que le fichier reçu est bien celui envoyé :<br>
      <span class="mono"><?= h($p['sha256']) ?></span>
    </p>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
