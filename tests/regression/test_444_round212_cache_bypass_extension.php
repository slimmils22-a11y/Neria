<?php
/**
 * Régression round 212 (25/08/2026) — extension de la famille de bug
 * systémique découverte au round 210 : Db::getValue()/getRow() du cœur
 * PrestaShop utilise $use_cache=true par défaut. Quand le cache SQL BO
 * est actif (Performances → Cache SQL, backends Memcache/Memcached/Apc/
 * Xcache), une requête à SQL identique dans le même process retourne un
 * résultat mémoïsé au lieu de ré-interroger MySQL — neutralisant tout
 * verrou GET_LOCK() ou toute lecture "check-then-act" (COUNT/EXISTS) qui
 * ne passe pas explicitement false en 2e paramètre.
 *
 * Ce test structurel vérifie que le round 212 a bien ajouté $use_cache=
 * false sur 7 occurrences supplémentaires dans 7 fichiers (dédup
 * comportementale, garde anti-conflit, garde anti-doublon financier), et
 * couvre également 3 bugs distincts additionnels traités le même round :
 *  - CertificateManager : Configuration::get() sans id_shop explicite
 *    (titre/sous-titre/corps PDF, préfixe de numéro de série) pouvait
 *    lire la config de la mauvaise boutique en contexte multi-boutique.
 *  - controllers/front/certificate.php : absence de limitation de débit
 *    permettant l'énumération par force brute de l'espace des numéros de
 *    série publics.
 *  - CertificateManager : un certificat orphelin (PDF généré mais insert
 *    SQL échoué) n'était jamais nettoyé du disque.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $base = _PS_MODULE_DIR_ . 'neria/';

    $needles = [
        'src/BehavioralCronManager.php' => "AND id_shop = \" . (int) \$customer['id_shop'],\n                        false\n                    );",
        'src/LoyaltyManager.php'        => "AND id_shop = \" . \$reservationShopId,\n                false\n            );",
        'src/UpsellManager.php'         => "AND id_customer        = \" . (int) \$row['id_customer'],\n                    false\n                );",
        'src/WaitlistManager.php'       => "AND id_product_attribute = {\$idProductAttribute} AND id_shop = {\$rowShopId}\",\n                false\n            ) > 0;",
        'src/WatchdogManager.php'       => "pSQL(\$message)\n            ), false);",
        'src/CertificateManager.php'    => "SELECT GET_LOCK('\" . pSQL(\$serialLockName) . \"', 5)\",\n            false\n        )) === 1;",
    ];

    foreach ($needles as $rel => $needle) {
        $src = file_get_contents($base . $rel);
        neria_assert($src !== false, "Impossible de lire {$rel}");
        $norm = str_replace("\r", '', $src);
        $needleNorm = str_replace("\r", '', $needle);
        neria_assert(
            strpos($norm, $needleNorm) !== false,
            "{$rel} n'a plus \$use_cache=false sur son check-then-act — régression du bug corrigé le 25/08/2026 (round 212, même famille que les rounds 210-211)"
        );
    }

    $msm = file_get_contents($base . 'src/ManualSendManager.php');
    neria_assert($msm !== false, 'Impossible de lire src/ManualSendManager.php');
    neria_assert(
        substr_count($msm, "AND id_shop = ' . \$idShopConflict,\n            false\n        );") >= 2,
        "ManualSendManager.php n'a plus les 2 occurrences de \$use_cache=false sur ses gardes anti-conflit anniversaire — régression du bug corrigé le 25/08/2026 (round 212)"
    );

    $cm = file_get_contents($base . 'src/CertificateManager.php');
    neria_assert($cm !== false, 'Impossible de lire src/CertificateManager.php');
    neria_assert(
        strpos($cm, "WHERE `serial_number` = \\'' . pSQL(\$serial) . '\\'',\n            false\n        );") !== false,
        "CertificateManager::serialExists() n'a plus \$use_cache=false — régression du bug corrigé le 25/08/2026 (round 212)"
    );
    neria_assert(
        strpos($cm, '\Configuration::get(self::CFG_TITLE, null, null, (int) $order->id_shop)') !== false,
        "CertificateManager ne scope plus CFG_TITLE par id_shop — régression du bug de config multi-boutique corrigé le 25/08/2026 (round 212)"
    );
    neria_assert(
        strpos($cm, '\Configuration::get(self::CFG_SERIAL_PREFIX, null, null, $idShop)') !== false,
        "CertificateManager ne scope plus CFG_SERIAL_PREFIX par id_shop — régression du bug de config multi-boutique corrigé le 25/08/2026 (round 212)"
    );
    neria_assert(
        strpos($cm, '$orphanPath = _PS_MODULE_DIR_ . \'neria/\' . $pdfPath;') !== false,
        "CertificateManager ne nettoie plus le PDF orphelin en cas d'échec d'insertion — régression du bug corrigé le 25/08/2026 (round 212)"
    );

    $certCtrl = file_get_contents($base . 'controllers/front/certificate.php');
    neria_assert($certCtrl !== false, 'Impossible de lire controllers/front/certificate.php');
    neria_assert(
        strpos($certCtrl, "\$key = 'neria_cert_rl_' . md5(\$ip);") !== false,
        "controllers/front/certificate.php n'a plus de limitation de débit par IP — régression du bug d'énumération corrigé le 25/08/2026 (round 212)"
    );

    return [
        'pass'    => true,
        'message' => 'Round 212 : 7 occurrences supplémentaires $use_cache=false, scoping id_shop CertificateManager, rate-limit certificate.php et nettoyage PDF orphelin tous présents',
    ];
}
