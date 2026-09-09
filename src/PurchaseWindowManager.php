<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — PurchaseWindowManager
 *
 * Détecte la fenêtre horaire d'achat naturelle de chaque client
 * à partir de l'historique des commandes validées.
 *
 * Logique : l'heure (0–23) la plus fréquente dans ps_orders doit apparaître
 * au moins MINIMUM_ORDERS fois pour être considérée comme un pattern fiable.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PurchaseWindowManager
{
    const MINIMUM_ORDERS = 2;

    private \Db $db;
    private string $prefix;

    public function __construct()
    {
        $this->db     = \Db::getInstance();
        $this->prefix = _DB_PREFIX_;
    }

    /**
     * Retourne l'heure préférée d'achat (0–23) d'un client,
     * ou null si les données sont insuffisantes (< MINIMUM_ORDERS commandes à la même heure).
     *
     * $idShop obligatoire : sans ce filtre, un client partagé entre
     * boutiques (compte client mutualisé) voit sa fenêtre d'achat
     * calculée sur TOUTES ses commandes toutes boutiques confondues —
     * un email envoyé par la boutique A pouvait être mis en file
     * d'attente jusqu'à l'heure où ce client achète habituellement sur
     * la boutique B. Même raisonnement que le scope id_shop déjà
     * appliqué partout ailleurs dans BehavioralCronManager.
     */
    public function getPreferredHour(int $idCustomer, int $idShop): ?int
    {
        // Regroupement par créneau de 2h (FLOOR(HOUR/2)) plutôt que par heure
        // exacte — un client commandant régulièrement en fin de matinée mais
        // à cheval sur deux heures entières (10h58, 11h34, 11h05) n'atteignait
        // jamais MINIMUM_ORDERS sur une même heure exacte malgré un vrai
        // pattern horaire cohérent, et retombait systématiquement sur l'envoi
        // immédiat par défaut. La borne basse du créneau (heure paire) sert
        // d'heure de référence retournée — approximation suffisante pour
        // programmer l'envoi dans la bonne fenêtre du client.
        // getRow() ajoute LIMIT 1 automatiquement — pas de LIMIT dans la requête.
        // Round 330 : $use_cache=false — même famille de bug que
        // WaitlistManager::isRegistered() (round 223) : le cache SQL de
        // PrestaShop n'est PAS invalidé quand une nouvelle commande est
        // insérée dans ps_orders. Sans ce paramètre, un client passant une
        // 2e commande dans le même créneau horaire (faisant enfin atteindre
        // MINIMUM_ORDERS) pouvait continuer à recevoir null (résultat
        // mis en cache lors d'un appel antérieur avec les mêmes
        // id_customer/id_shop), le privant indéfiniment de la fenêtre
        // d'achat pourtant désormais détectable.
        $row = $this->db->getRow(
            'SELECT FLOOR(HOUR(date_add) / 2) * 2 AS h, COUNT(*) AS cnt
             FROM `' . $this->prefix . 'orders`
             WHERE id_customer = ' . (int) $idCustomer . '
               AND id_shop = ' . (int) $idShop . '
               AND valid = 1
             GROUP BY FLOOR(HOUR(date_add) / 2)
             ORDER BY cnt DESC, h ASC',
            false
        );

        if (!$row || (int) $row['cnt'] < self::MINIMUM_ORDERS) {
            return null;
        }

        // Round 327 : +1 pour viser le MILIEU du créneau de 2h, pas sa borne
        // basse. QueueManager::nextOccurrence() traite cette valeur comme une
        // heure cible EXACTE (sprintf('%02d:00:00', $hour)), sans aucune
        // tolérance — retourner la borne basse programmait systématiquement
        // l'envoi jusqu'à ~2h AVANT l'heure d'achat réelle du client (ex.
        // commandes à 10h58/11h05/11h34 → créneau [10h-12h[ → email envoyé à
        // 10h00 pile, ~1h occurrence avant son heure habituelle réelle),
        // contredisant l'intention documentée ("programmer l'envoi dans la
        // bonne fenêtre du client").
        return (int) $row['h'] + 1;
    }

    /**
     * Nombre de clients actifs ayant une fenêtre détectée (≥ MINIMUM_ORDERS à la même heure).
     */
    public function getWindowCoverageCount(int $idShop): int
    {
        // COUNT(*) comptait les LIGNES de la sous-requête (une par couple
        // id_customer+heure satisfaisant le seuil), pas les clients
        // distincts — un client avec 2 créneaux horaires suffisamment
        // fréquents était compté deux fois, faussant à la hausse le nombre
        // affiché en BO.
        // Même regroupement par créneau de 2h que getPreferredHour(), pour que
        // ce compteur BO reflète bien le nombre de clients pour lesquels une
        // fenêtre sera effectivement détectée par getPreferredHour().
        // Round 330 : $use_cache=false — même raison que getPreferredHour() ci-dessus.
        return (int) $this->db->getValue(
            'SELECT COUNT(DISTINCT id_customer) FROM (
               SELECT id_customer
               FROM `' . $this->prefix . 'orders`
               WHERE valid = 1 AND id_shop = ' . (int) $idShop . '
               GROUP BY id_customer, FLOOR(HOUR(date_add) / 2)
               HAVING COUNT(*) >= ' . self::MINIMUM_ORDERS . '
             ) sub',
            false
        );
    }

    /**
     * Distribution horaire globale (heure → nombre de clients ciblables).
     * Utile pour afficher un histogramme BO.
     *
     * @return array<int, int>  [hour => count]
     */
    public function getHourDistribution(int $idShop): array
    {
        // Round 330 : executeS($sql, true, false) — piège de signature déjà
        // documenté (round 326 ailleurs dans le module) : le 2e argument
        // positionnel contrôle le MODE TABLEAU, pas le cache ; il faut donc
        // bien passer explicitement `true` en 2e position pour garder le
        // mode tableau ET `false` en 3e position pour bypasser le cache.
        $rows = $this->db->executeS(
            'SELECT HOUR(date_add) AS h, COUNT(DISTINCT id_customer) AS cnt
             FROM `' . $this->prefix . 'orders`
             WHERE valid = 1 AND id_shop = ' . (int) $idShop . '
             GROUP BY HOUR(date_add)
             ORDER BY h ASC',
            true,
            false
        );

        $dist = array_fill(0, 24, 0);
        foreach ((array) $rows as $row) {
            $dist[(int) $row['h']] = (int) $row['cnt'];
        }

        return $dist;
    }
}
