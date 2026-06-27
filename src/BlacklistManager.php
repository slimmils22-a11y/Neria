<?php
/**
 * NERIA — BlacklistManager
 *
 * Gère la blacklist interne de templates : permet au marchand de désactiver
 * le rendu Neria pour un template donné, optionnellement pour une langue
 * spécifique. Quand un template est blacklisté, PrestaShop envoie l'email
 * natif (ou un autre outil prend la main) — Neria ne touche pas à l'email.
 *
 * lang = '' (chaîne vide) signifie : toutes les langues.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class BlacklistManager
{
    const TABLE = 'neria_blacklist';

    /** @var \Db */
    private \Db $db;

    /** @var int */
    private int $idShop;

    /** @var array|null Cache en mémoire pour la requête (durée de la requête HTTP) */
    private ?array $cache = null;

    public function __construct()
    {
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    /**
     * Vérifie si un template est blacklisté pour une langue donnée.
     * Retourne true si une règle "toutes langues" OU "cette langue" existe.
     *
     * @param string $template Nom du template (ex: "cart_reminder")
     * @param string $lang     Code langue Neria (ex: "en", "fr"), ou '' si inconnu
     * @return bool
     */
    public function isBlacklisted(string $template, string $lang): bool
    {
        $rules = $this->loadAll();
        foreach ($rules as $rule) {
            if ($rule['template'] !== $template) {
                continue;
            }
            // Règle "toutes langues" (lang = '') ou règle pour cette langue précise
            if ($rule['lang'] === '' || ($lang !== '' && $rule['lang'] === $lang)) {
                if (class_exists('WatchdogManager')) {
                    $mod = \Module::getInstanceByName('neria');
                    if ($mod) {
                        (new \WatchdogManager($mod))->info(
                            sprintf('Template blacklisté, email ignoré : %s (lang: %s)', $template, $lang ?: 'toutes'),
                            $template, 'BlacklistManager'
                        );
                    }
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Ajoute une règle de blacklist.
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue Neria, ou '' pour toutes les langues
     * @return bool
     */
    public function add(string $template, string $lang): bool
    {
        $template = pSQL(trim($template));
        $lang     = pSQL(trim($lang));
        if ($template === '') {
            return false;
        }

        $ok = $this->db->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . self::TABLE . '`
             (`id_shop`, `template`, `lang`, `date_add`)
             VALUES (' . (int) $this->idShop . ", '" . $template . "', '" . $lang . "', NOW())"
        );
        $this->cache = null;
        if ($ok && class_exists('WatchdogManager')) {
            $mod = \Module::getInstanceByName('neria');
            if ($mod) {
                (new \WatchdogManager($mod))->info(
                    sprintf('Blacklist : règle ajoutée pour %s (lang: %s)', $template, $lang ?: 'toutes'),
                    $template, 'BlacklistManager'
                );
            }
        }
        return (bool) $ok;
    }

    /**
     * Supprime une règle par son ID.
     *
     * @param int $id
     * @return bool
     */
    public function remove(int $id): bool
    {
        $ok = $this->db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_blacklist` = ' . (int) $id . '
               AND `id_shop` = ' . (int) $this->idShop
        );
        $this->cache = null;
        if ($ok && class_exists('WatchdogManager')) {
            $mod = \Module::getInstanceByName('neria');
            if ($mod) {
                (new \WatchdogManager($mod))->info(
                    sprintf('Blacklist : règle #%d supprimée', $id),
                    '', 'BlacklistManager'
                );
            }
        }
        return (bool) $ok;
    }

    public function reset(): bool
    {
        $ok = $this->db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_shop` = ' . (int) $this->idShop
        );
        $this->cache = null;
        return (bool) $ok;
    }

    /**
     * Retourne toutes les règles de la boutique courante.
     *
     * @return array [['id_blacklist', 'template', 'lang', 'date_add'], ...]
     */
    public function getAll(): array
    {
        return $this->loadAll();
    }

    private function loadAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = $this->db->executeS(
            'SELECT `id_blacklist`, `template`, `lang`, `date_add`
             FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_shop` = ' . (int) $this->idShop . '
             ORDER BY `template`, `lang`'
        );
        $this->cache = is_array($rows) ? $rows : [];
        return $this->cache;
    }
}
