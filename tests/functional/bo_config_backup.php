<?php
/**
 * Écrit sur la sortie standard, en JSON, toutes les lignes de la table configuration dont le nom commence par NERIA
 * (sauvegarde avant l'exécution des actions du back-office — voir bo_run_actions.php).
 */
require_once __DIR__ . '/../regression/bootstrap.php';
echo json_encode(Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . "configuration WHERE name LIKE 'NERIA%'"), JSON_UNESCAPED_UNICODE);
