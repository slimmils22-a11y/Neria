<?php
/**
 * NERIA — getpreview.php
 * Sert un aperçu email pour les iframes de la prévisualisation multi-client.
 * Fichier PHP minimal — ne charge PAS le framework PrestaShop.
 */

$root     = dirname(__FILE__, 3); // /laragon/www/shop
$token    = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_GET['token'] ?? ''));
$clientId = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_GET['client'] ?? ''));

if ($token === '' || $clientId === '') {
    http_response_code(400);
    exit;
}

$previewDir = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR
            . 'cache' . DIRECTORY_SEPARATOR . 'neria_previews' . DIRECTORY_SEPARATOR;

$file = realpath($previewDir . $clientId . '_' . $token . '.html');

// Sécurité : le fichier doit rester dans le répertoire attendu
if ($file === false || strpos($file, $previewDir) !== 0) {
    http_response_code(404);
    exit;
}

$html = file_get_contents($file);

// Injecte un zoom 50 % pour voir l'email en entier dans la carte
$zoomStyle = '<style>html{zoom:0.5;}</style>';
if (stripos($html, '</head>') !== false) {
    $html = str_ireplace('</head>', $zoomStyle . '</head>', $html);
} else {
    $html = $zoomStyle . $html;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: private, max-age=3600');
echo $html;
