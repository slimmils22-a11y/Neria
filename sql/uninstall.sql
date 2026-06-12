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