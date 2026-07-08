{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — customer_email_history.tpl
 * Bloc « Emails reçus » injecté sur la fiche client (hook displayAdminCustomers)
 * Timeline visuelle par défaut + bascule vers le tableau complet + export CSV
 * + aperçu fidèle (modale) + renvoi (snapshot Option C)
 *}
<div class="col-12 panel neria-section neria-customer-history" id="neria-customer-history">
  <div class="panel-heading neria-history__heading">
    <span><i class="icon-envelope"></i> {neria_admin key='history.title'}</span>
    <span class="neria-powered-by">{neria_admin key='history.powered_by'}</span>
  </div>

  {include file="module:neria/views/templates/admin/_customer_history_content.tpl"}
</div>
