<?php
/**
 * Point d'entrée pour les commandes du Mac (« partage » et « preuve »).
 * Le fichier est d'abord envoyé en SFTP ; cet appel ne fait que l'inscrire au catalogue.
 * Rappel maison : POST JSON avec un corps non vide, sinon le pare-feu OVH renvoie 403.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

function repondre(int $code, array $corps): never
{
    http_response_code($code);
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    repondre(405, ['erreur' => 'POST attendu']);
}

$brut = file_get_contents('php://input') ?: '';
$in = json_decode($brut, true);
if (!is_array($in)) {
    repondre(400, ['erreur' => 'corps JSON illisible']);
}

$cfg = config();

// Deux façons de s'identifier : la clé tirée au hasard à l'installation, ou le
// mot de passe de la page. La seconde évite d'avoir à transporter un secret
// d'une machine à l'autre — sur un nouveau Mac, il suffit de le retaper.
$presente = (string) ($in['cle'] ?? '');
$essais = verrou_essai();
if ($essais > 12) {
    usleep((int) min(5_000_000, 500_000 * ($essais - 12)));
    repondre(429, ['erreur' => "trop d'essais, réessaie dans un quart d'heure"]);
}
$reconnu = ($presente !== '' && !empty($cfg['cle_api']) && hash_equals($cfg['cle_api'], $presente))
    || ($presente !== '' && password_verify($presente, $cfg['empreinte_mdp']));
if (!$reconnu) {
    usleep(500000);
    repondre(403, ['erreur' => 'clé refusée']);
}
verrou_reussite();

$quoi = $in['quoi'] ?? '';

/* ------------------------------------------------- inscription d'un partage */

if ($quoi === 'partage' && ($in['type'] ?? '') === 'groupe') {
    $jeton = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['jeton'] ?? '')) ?? '';
    $dossier = __DIR__ . '/f/' . $jeton;
    if ($jeton === '' || !is_dir($dossier)) {
        repondre(404, ['erreur' => 'dossier absent du serveur — le transfert SFTP a-t-il abouti ?']);
    }
    $contenu = dossier_lister($dossier);
    if (!$contenu) {
        repondre(404, ['erreur' => 'le dossier est arrivé vide']);
    }
    // On ne recalcule pas d'empreinte ici : sur des dizaines de gigaoctets, le
    // mutualisé n'y survivrait pas. Le nombre de fichiers et le total suffisent
    // à repérer un transfert incomplet.
    $total = array_sum(array_column($contenu, 'taille'));
    if (!empty($in['nombre']) && (int) $in['nombre'] !== count($contenu)) {
        repondre(409, ['erreur' => 'il manque des fichiers', 'attendus' => (int) $in['nombre'], 'recus' => count($contenu)]);
    }
    if (!empty($in['taille']) && (int) $in['taille'] !== $total) {
        repondre(409, ['erreur' => 'le total ne correspond pas', 'attendu' => (int) $in['taille'], 'recu' => $total]);
    }
    $jours = max(1, min(365, (int) ($in['jours'] ?? $cfg['expiration_defaut'])));
    $p = partages();
    $p[] = [
        'jeton'    => $jeton,
        'type'     => 'groupe',
        'nom'      => mb_substr(trim((string) ($in['nom'] ?? 'Fichiers')), 0, 120),
        'taille'   => $total,
        'nombre'   => count($contenu),
        'note'     => trim((string) ($in['note'] ?? '')),
        'cree'     => date('c'),
        'expire'   => date('c', time() + $jours * 86400),
        'retraits' => 0,
        'origine'  => 'mac',
    ];
    partages_ecrire($p);
    repondre(200, ['lien' => lien_partage($jeton), 'fichiers' => count($contenu), 'taille' => $total]);
}

if ($quoi === 'partage') {
    $jeton = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['jeton'] ?? '')) ?? '';
    $nom = nom_sur((string) ($in['nom'] ?? ''));
    $chemin = __DIR__ . '/f/' . $jeton . '/' . $nom;
    if ($jeton === '' || !is_file($chemin)) {
        repondre(404, ['erreur' => 'fichier absent du serveur — le transfert SFTP a-t-il abouti ?']);
    }
    $sha = hash_file('sha256', $chemin);
    if (!empty($in['sha256']) && !hash_equals($sha, (string) $in['sha256'])) {
        repondre(409, ['erreur' => 'le fichier reçu ne correspond pas à celui envoyé', 'sha256_serveur' => $sha]);
    }
    $jours = max(1, min(365, (int) ($in['jours'] ?? $cfg['expiration_defaut'])));
    $p = partages();
    $p[] = [
        'jeton'    => $jeton,
        'type'     => 'fichier',
        'nom'      => $nom,
        'taille'   => filesize($chemin),
        'sha256'   => $sha,
        'note'     => trim((string) ($in['note'] ?? '')),
        'cree'     => date('c'),
        'expire'   => date('c', time() + $jours * 86400),
        'retraits' => 0,
        'origine'  => 'mac',
    ];
    partages_ecrire($p);
    repondre(200, ['lien' => lien_partage($jeton), 'expire' => date('c', time() + $jours * 86400), 'sha256' => $sha]);
}

/* -------------------------------------------------- inscription d'une pièce */

if ($quoi === 'preuve') {
    $m = $in['piece'] ?? null;
    if (!is_array($m) || empty($m['id']) || empty($m['url'])) {
        repondre(400, ['erreur' => 'description de pièce incomplète']);
    }
    if (!preg_match('~^https?://~i', (string) $m['url'])) {
        repondre(400, ['erreur' => "l'adresse doit commencer par http:// ou https://"]);
    }
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $m['id']) ?? '';
    $dossier = COFFRE . '/preuves/' . $id;
    if ($id === '' || !is_dir($dossier)) {
        repondre(404, ['erreur' => 'dossier de la pièce absent — le transfert SFTP a-t-il abouti ?']);
    }
    if (preuve_par_id($id)) {
        repondre(409, ['erreur' => 'pièce déjà inscrite']);
    }

    // On recalcule les empreintes côté serveur : c'est la vérification du transfert.
    $fichiers = [];
    $concat = '';
    foreach ($m['fichiers'] ?? [] as $f) {
        $nom = basename((string) ($f['nom'] ?? ''));
        $chemin = $dossier . '/' . $nom;
        if ($nom === '' || !is_file($chemin)) {
            repondre(404, ['erreur' => "fichier manquant dans le coffre : $nom"]);
        }
        $sha = hash_file('sha256', $chemin);
        if (!empty($f['sha256']) && !hash_equals($sha, (string) $f['sha256'])) {
            repondre(409, ['erreur' => "empreinte différente après transfert : $nom"]);
        }
        $fichiers[] = ['nom' => $nom, 'taille' => filesize($chemin), 'sha256' => $sha];
        $concat .= $sha;
    }
    if (!$fichiers) {
        repondre(400, ['erreur' => 'aucun fichier dans la pièce']);
    }

    $liste = preuves();
    $precedent = $liste ? (string) end($liste)['maillon'] : 'genese';
    $piece = [
        'id'                => $id,
        'url'               => (string) $m['url'],
        'titre'             => trim((string) ($m['titre'] ?? '')),
        'note'              => trim((string) ($m['note'] ?? '')),
        'date'              => (string) ($m['date'] ?? date('c')),
        'capture'           => basename((string) ($m['capture'] ?? '')),
        'capture_par'       => trim((string) ($m['capture_par'] ?? '')),
        'fichiers'          => $fichiers,
        'empreintes_concat' => hash('sha256', $concat),
        'precedent'         => $precedent,
    ];
    $piece['maillon'] = preuve_maillon($piece, $precedent);
    $liste[] = $piece;
    preuves_ecrire($liste);

    repondre(200, [
        'id'      => $id,
        'maillon' => $piece['maillon'],
        'fiche'   => rtrim($cfg['adresse'], '/') . '/index.php?section=preuves&piece=' . rawurlencode($id),
    ]);
}

repondre(400, ['erreur' => 'action inconnue']);
