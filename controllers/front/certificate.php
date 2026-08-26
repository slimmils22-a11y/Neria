<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Front controller : page de traçabilité publique
 * URL : /module/neria/certificate?cert=SERIAL
 *
 * Cible du QR code imprimé sur chaque certificat d'authenticité PDF
 * (CertificateManager::generatePdf()). Page publique, sans authentification :
 * montre la fiche précise de LA pièce achetée (artisane, région, temps de
 * tissage, mot de l'artisane) plutôt qu'un certificat générique — un
 * numéro de série ne révèle rien d'exploitable en soi (pas de données
 * client affichées ici).
 *
 * @author  Neria
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NeriaCertificateModuleFrontController extends ModuleFrontController
{
    public $display_column_left  = false;
    public $display_column_right = false;

    public function initContent()
    {
        parent::initContent();

        $serial = trim((string) Tools::getValue('cert'));
        $cert   = [];

        // Round 212 : les numéros de série sont un simple compteur
        // séquentiel (CERT-ANNÉE-NNNNNN, cf. CertificateManager::
        // generateSerial()) — cette page publique, non authentifiée,
        // n'avait jusqu'ici AUCUNE limitation de débit, contrairement à
        // track.php (throttling APCu par IP+token, round 164). Un tiers
        // pouvait parcourir séquentiellement tous les numéros pour
        // aspirer produit/artisan/région/date d'émission de l'intégralité
        // du catalogue de production. Même mécanisme que track.php
        // (fail-open si APCu indisponible, jamais bloquant en soi), mais
        // scopé par IP SEULE : il n'y a pas de "destinataire réel" ici à
        // isoler comme pour le pixel de tracking, la cible du throttle
        // est le scraping en masse depuis une même source.
        $lookupAllowed = true;
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $ip  = (string) (Tools::getRemoteAddr() ?: '0.0.0.0');
            $key = 'neria_cert_rl_' . md5($ip);
            $hits = (int) apcu_fetch($key, $ok);
            if (!$ok) {
                apcu_store($key, 1, 10);
            } else {
                $hits++;
                apcu_store($key, $hits, 10);
                if ($hits > 20) {
                    $lookupAllowed = false;
                }
            }
        }

        if ($serial !== '' && $lookupAllowed && class_exists('CertificateManager')) {
            $cert = (new CertificateManager($this->module))->getBySerial($serial);
        }

        if (class_exists('AdminTranslator')) {
            AdminTranslator::register($this->context->smarty);
        }

        $this->context->smarty->assign([
            'neria_trace_found'    => !empty($cert),
            'neria_trace_serial'   => $serial,
            'neria_trace_product'  => $cert['product_name'] ?? '',
            'neria_trace_artisan'  => $cert['artisan_name'] ?? '',
            'neria_trace_region'   => $cert['region'] ?? '',
            'neria_trace_duration' => $cert['weaving_duration'] ?? '',
            'neria_trace_note'     => $cert['artisan_note'] ?? '',
            'neria_trace_date'     => !empty($cert['date_issued'])
                ? Tools::displayDate($cert['date_issued'])
                : '',
            'neria_shop_name' => (string) Configuration::get('PS_SHOP_NAME'),
            'neria_shop_url'  => $this->context->link->getBaseLink(),
            'neria_trace_dir' => class_exists('AdminTranslator') ? AdminTranslator::dir() : 'ltr',
        ]);

        $this->setTemplate('module:neria/views/templates/front/certificate.tpl');
    }
}
