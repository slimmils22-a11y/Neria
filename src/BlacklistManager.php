<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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

    /**
     * $idShop optionnel — round 136 : ManualSendManager calcule explicitement
     * l'idShop réel du CLIENT (pas celui du contexte BO de l'opérateur, ni
     * du contexte d'exécution ambiant d'un cron) pour PreferencesManager,
     * mais instanciait BlacklistManager() sans ce paramètre, qui retombait
     * alors sur Context::getContext()->shop->id — la boutique de l'opérateur
     * en envoi manuel/planifié. Un opérateur en contexte Boutique A envoyant
     * à un client de Boutique B vérifiait la blacklist de la MAUVAISE
     * boutique : un template bloqué sur B pouvait partir quand même (ou
     * inversement une règle de A bloquer à tort un envoi vers B).
     */
    public function __construct(?int $idShop = null)
    {
        $this->db     = \Db::getInstance();
        $this->idShop = $idShop ?? (int) \Context::getContext()->shop->id;
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
        // Round 136 : comparaison insensible à la casse — EmailRenderer::
        // resolveTemplate() force systématiquement strtolower() avant
        // d'appeler isBlacklisted(), mais add() (ci-dessous) n'a jamais
        // normalisé la casse à l'écriture. Non exploitable aujourd'hui via
        // le <select> BO (déjà en minuscules), mais silencieusement cassé
        // dès qu'un template en casse mixte serait enregistré par un autre
        // chemin (import, texte libre futur).
        $template = mb_strtolower($template);
        $rules = $this->loadAll();
        foreach ($rules as $rule) {
            if (mb_strtolower($rule['template']) !== $template) {
                continue;
            }
            // Règle "toutes langues" (lang = '') ou règle pour cette langue précise
            if ($rule['lang'] === '' || ($lang !== '' && $rule['lang'] === $lang)) {
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
        $template = pSQL(mb_strtolower(trim($template)));
        $lang     = pSQL(trim($lang));
        if ($template === '') {
            return false;
        }

        // Vérifie Affected_Rows(), pas seulement le résultat de execute() :
        // INSERT IGNORE sur un doublon (contrainte UNIQUE id_shop+template+
        // lang) exécute la requête SANS erreur (0 ligne insérée) — execute()
        // retourne quand même true, faisant afficher un faux message de
        // succès côté BO pour une règle qui existait déjà.
        $ok = $this->db->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . self::TABLE . '`
             (`id_shop`, `template`, `lang`, `date_add`)
             VALUES (' . (int) $this->idShop . ", '" . $template . "', '" . $lang . "', NOW())"
        );
        $this->cache = null;
        return (bool) $ok && (int) $this->db->Affected_Rows() > 0;
    }

    /**
     * Supprime une règle par son ID.
     *
     * @param int $id
     * @return bool
     */
    public function remove(int $id): bool
    {
        // Vérifie Affected_Rows(), pas seulement le résultat de execute() :
        // un DELETE sur un id déjà supprimé (double clic, id d'une autre
        // boutique) s'exécute SANS erreur (0 ligne supprimée) — execute()
        // retourne quand même true, faisant afficher un faux message de
        // succès côté BO alors qu'aucune règle n'a réellement été retirée.
        $ok = $this->db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_blacklist` = ' . (int) $id . '
               AND `id_shop` = ' . (int) $this->idShop
        );
        $this->cache = null;
        return (bool) $ok && (int) $this->db->Affected_Rows() > 0;
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
        // Round 218 : $use_cache=false, même famille de bug que les rounds
        // 210-217 — cette lecture alimente isBlacklisted(), vérifié avant
        // CHAQUE envoi. Sans ce paramètre, un template tout juste
        // blacklisté par le marchand pourrait continuer à partir sous
        // cache SQL périmé (le blocage censé être immédiat ne l'est pas).
        $rows = $this->db->executeS(
            'SELECT `id_blacklist`, `template`, `lang`, `date_add`
             FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_shop` = ' . (int) $this->idShop . '
             ORDER BY `template`, `lang`',
            true,
            false
        );
        $this->cache = is_array($rows) ? $rows : [];
        return $this->cache;
    }
}
