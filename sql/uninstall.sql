-- ============================================================
-- NERIA — Script de désinstallation SQL
-- Supprime toutes les tables créées par install.sql
-- ============================================================
-- ATTENTION : Cette opération est irréversible.
-- Toutes les traductions personnalisées, statistiques,
-- configurations et tests A/B seront définitivement perdus.
-- ============================================================
-- L'ordre de suppression respecte les contraintes de clés
-- étrangères : on supprime les tables enfants en premier.
-- ============================================================

-- Table enfant (FK vers neria_abtest)
DROP TABLE IF EXISTS `PREFIX_neria_abtest_translation`;

-- Tables indépendantes
DROP TABLE IF EXISTS `PREFIX_neria_stat`;
DROP TABLE IF EXISTS `PREFIX_neria_calendar_event`;
DROP TABLE IF EXISTS `PREFIX_neria_abtest`;
DROP TABLE IF EXISTS `PREFIX_neria_signature`;
DROP TABLE IF EXISTS `PREFIX_neria_custom_variable`;
DROP TABLE IF EXISTS `PREFIX_neria_config`;
DROP TABLE IF EXISTS `PREFIX_neria_translation`;
DROP TABLE IF EXISTS `PREFIX_neria_log`;
DROP TABLE IF EXISTS `PREFIX_neria_blacklist`;
DROP TABLE IF EXISTS `PREFIX_neria_behavioral_sent`;
DROP TABLE IF EXISTS `PREFIX_neria_webhook_queue`;
DROP TABLE IF EXISTS `PREFIX_neria_customer_segment`;
DROP TABLE IF EXISTS `PREFIX_neria_churn_score`;
DROP TABLE IF EXISTS `PREFIX_neria_translation_history`;DROP TABLE IF EXISTS `PREFIX_neria_attribution`;
DROP TABLE IF EXISTS `PREFIX_neria_upsell`;
DROP TABLE IF EXISTS `PREFIX_neria_loyalty_rewards`;
DROP TABLE IF EXISTS `PREFIX_neria_loyalty_points`;
DROP TABLE IF EXISTS `PREFIX_neria_seasonal_campaign`;
DROP TABLE IF EXISTS `PREFIX_neria_bounces`;
DROP TABLE IF EXISTS `PREFIX_neria_certificate`;
DROP TABLE IF EXISTS `PREFIX_neria_quote`;
DROP TABLE IF EXISTS `PREFIX_neria_reconciliation`;
DROP TABLE IF EXISTS `PREFIX_neria_product_lifespan`;
DROP TABLE IF EXISTS `PREFIX_neria_propensity_score`;

DROP TABLE IF EXISTS `PREFIX_neria_queue`;
