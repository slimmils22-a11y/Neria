<?php
/**
 * Régression : les 2 constructions de {order_url} dans ManualSendManager
 * (send() et son pendant planifié) doivent passer l'id_shop explicite au
 * 6e argument de Link::getPageLink() — comme {shop_url}/{history_url}
 * juste au-dessus dans les deux mêmes méthodes.
 *
 * Bug réel corrigé le 08/08/2026 (round 129) : {order_url} retombait sur le
 * contexte BO de l'employé qui déclenche l'envoi, pas la boutique du client
 * destinataire — même bug historique déjà corrigé pour {shop_url}/
 * {history_url} (voisins immédiats), jamais répliqué sur {order_url}.
 *
 * Test structurel (send()/le chemin planifié nécessitent une commande +
 * client réels et un envoi Mail::Send complet, coûteux à fixturer pour la
 * valeur ajoutée ici) : vérifie que les 2 appels getPageLink('order-detail', ...)
 * passent bien un id_shop en 6e argument.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ManualSendManager.php');

    $count = preg_match_all(
        "/getPageLink\\(\\s*'order-detail',\\s*true,\\s*\\\$idLang,\\s*\\['id_order'\\s*=>\\s*\\(int\\)\\s*\\\$order\\['id_order'\\]\\],\\s*false,\\s*\\\$idShop(Manual)?\\s*\\)/",
        $src,
        $m
    );

    neria_assert(
        $count === 2,
        "{$count} occurrence(s) de getPageLink('order-detail', ...) avec un idShop explicite trouvée(s) sur 2 attendues — régression du bug corrigé le 08/08/2026 (round 129) : {order_url} retomberait de nouveau sur le contexte BO de l'employé au lieu de la boutique du client"
    );

    return [
        'pass'    => true,
        'message' => "ManualSendManager construit toujours {order_url} avec l'id_shop explicite du client, dans send() et son pendant planifié",
    ];
}
