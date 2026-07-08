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
     */
    public function getPreferredHour(int $idCustomer): ?int
    {
        // getRow() ajoute LIMIT 1 automatiquement — pas de LIMIT dans la requête.
        $row = $this->db->getRow(
            'SELECT HOUR(date_add) AS h, COUNT(*) AS cnt
             FROM `' . $this->prefix . 'orders`
             WHERE id_customer = ' . (int) $idCustomer . ' AND valid = 1
             GROUP BY HOUR(date_add)
             ORDER BY cnt DESC, h ASC'
        );

        if (!$row || (int) $row['cnt'] < self::MINIMUM_ORDERS) {
            return null;
        }

        return (int) $row['h'];
    }

    /**
     * Nombre de clients actifs ayant une fenêtre détectée (≥ MINIMUM_ORDERS à la même heure).
     */
    public function getWindowCoverageCount(): int
    {
        return (int) $this->db->getValue(
            'SELECT COUNT(*) FROM (
               SELECT id_customer
               FROM `' . $this->prefix . 'orders`
               WHERE valid = 1
               GROUP BY id_customer, HOUR(date_add)
               HAVING COUNT(*) >= ' . self::MINIMUM_ORDERS . '
             ) sub'
        );
    }

    /**
     * Distribution horaire globale (heure → nombre de clients ciblables).
     * Utile pour afficher un histogramme BO.
     *
     * @return array<int, int>  [hour => count]
     */
    public function getHourDistribution(): array
    {
        $rows = $this->db->executeS(
            'SELECT HOUR(date_add) AS h, COUNT(DISTINCT id_customer) AS cnt
             FROM `' . $this->prefix . 'orders`
             WHERE valid = 1
             GROUP BY HOUR(date_add)
             ORDER BY h ASC'
        );

        $dist = array_fill(0, 24, 0);
        foreach ((array) $rows as $row) {
            $dist[(int) $row['h']] = (int) $row['cnt'];
        }

        return $dist;
    }
}
