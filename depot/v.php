<?php
/** Sert un fichier du conservatoire. Réservé à la session ouverte : rien ne sort sans mot de passe. */

declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!connecte()) {
    http_response_code(403);
    exit('Accès refusé.');
}

$p = preuve_par_id((string) ($_GET['piece'] ?? ''));
$nom = basename((string) ($_GET['f'] ?? ''));
if (!$p || $nom === '' || str_contains($nom, '..')) {
    http_response_code(404);
    exit('Introuvable.');
}

$connus = array_column($p['fichiers'] ?? [], 'nom');
if (!in_array($nom, $connus, true)) {
    http_response_code(404);
    exit('Introuvable.');
}

$chemin = preuve_dossier($p) . '/' . $nom;
if (!is_file($chemin)) {
    http_response_code(404);
    exit('Fichier absent du coffre.');
}

$types = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
    'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8', 'json' => 'application/json',
    'html' => 'text/plain; charset=utf-8', // volontaire : on montre le code, on ne l'exécute pas
    'mhtml' => 'application/octet-stream', 'warc' => 'application/octet-stream',
];
$ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($chemin));
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
header('X-Content-Type-Options: nosniff');
if (!isset($types[$ext]) || $ext === 'mhtml' || $ext === 'warc') {
    header('Content-Disposition: attachment; filename="' . $nom . '"');
}
readfile($chemin);
