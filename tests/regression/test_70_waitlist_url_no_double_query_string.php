<?php
/**
 * Régression : les liens waitlist subscribe/unsubscribe doivent rester des
 * URLs valides, avec 'action'/'id_product'/'back' comme paramètres
 * distincts, quel que soit le mode d'URL (rewriting activé ou non).
 *
 * Bug réel corrigé le 06/08/2026 (round 67, piste identifiée le 05/08/2026
 * round 54) : neria.php et le template waitlist_button.tpl concaténaient
 * en dur '?action=...&id_product=...&back=...' sur le résultat de
 * getModuleLink(). Avec l'URL rewriting désactivé (PS_REWRITING_SETTINGS=0),
 * getModuleLink() retourne déjà une URL porteuse d'un '?' — le second '?'
 * concaténé fusionnait 'action=...' dans la VALEUR du paramètre précédent
 * au lieu d'être une clé distincte : le contrôleur ne voyait jamais
 * l'action demandée, le lien d'inscription/désinscription ne faisait plus
 * rien, silencieusement.
 *
 * Ce test vérifie DIRECTEMENT le mécanisme corrigé (Link::getModuleLink()
 * avec les params passés en 3e argument, exactement comme le fait
 * désormais neria.php) plutôt que de rendre le hook via Smarty — le rendu
 * Smarty passe par un cache de templates compilés qui, sur cet
 * environnement de dev, ne s'invalide pas de façon fiable après un
 * remplacement direct du fichier .tpl sur disque (comportement de
 * l'environnement, pas du code testé). Complété par une vérification
 * structurelle : neria.php ne doit plus contenir de concaténation brute
 * "?action=".
 *
 * Bascule temporairement PS_REWRITING_SETTINGS à 0 (cas qui reproduisait
 * le bug), PUIS restaure la valeur d'origine dans un finally — même sous
 * exception.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');
    neria_assert(
        strpos($src, "'?action=unsubscribe&id_product='") === false
        && strpos($src, "'?action=subscribe&id_product='") === false,
        "neria.php concatène de nouveau '?action=...' en dur sur le résultat de getModuleLink() — régression du bug corrigé le 06/08/2026 (round 67)"
    );

    $originalRewriting = Configuration::get('PS_REWRITING_SETTINGS');

    try {
        foreach ([1, 0] as $rewriting) {
            Configuration::updateValue('PS_REWRITING_SETTINGS', $rewriting);
            $link = new Link();

            $commonParams = ['id_product' => 42, 'back' => 'http://localhost/shop/some-product.html'];
            $subscribeUrl   = $link->getModuleLink('neria', 'waitlist', array_merge($commonParams, ['action' => 'subscribe']));
            $unsubscribeUrl = $link->getModuleLink('neria', 'waitlist', array_merge($commonParams, ['action' => 'unsubscribe']));

            foreach (['subscribe' => $subscribeUrl, 'unsubscribe' => $unsubscribeUrl] as $label => $url) {
                neria_assert(
                    substr_count($url, '?') === 1,
                    "l'URL {$label} contient " . substr_count($url, '?') . " '?' au lieu d'un seul (rewriting={$rewriting}) : {$url} — régression du bug corrigé le 06/08/2026 (round 67)"
                );

                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                neria_assert(
                    ($query['action'] ?? null) === $label,
                    "le paramètre 'action' n'est pas correctement isolé dans l'URL {$label} (rewriting={$rewriting}) : " . json_encode($query)
                );
                neria_assert(
                    ($query['id_product'] ?? null) === '42',
                    "le paramètre 'id_product' n'est pas correctement isolé dans l'URL {$label} (rewriting={$rewriting}) : " . json_encode($query)
                );
            }
        }

        return [
            'pass'    => true,
            'message' => "getModuleLink('neria', 'waitlist', [...]) produit des URLs valides (un seul '?', action/id_product distincts) avec et sans URL rewriting",
        ];
    } finally {
        Configuration::updateValue('PS_REWRITING_SETTINGS', $originalRewriting);
    }
}
