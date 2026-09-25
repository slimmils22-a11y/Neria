<?php
/**
 * Régression (décision du 25/09/2026) : plus aucun tutoiement dans
 *   - l'interface d'administration en italien et en espagnol (data/admin_translations.json) ;
 *   - les e-mails en italien (data/translations.json) — forme « Voi/Vostro ».
 * Avant : ~400 chaînes italiennes (« → Cosa fare: verifica… », « il tuo server », « Scopri la nostra selezione »)
 * et une trentaine d'espagnoles (« comprueba… en tu hosting », « Contacta con soporte ») tutoyaient.
 *
 * Périmètre de la vérification (volontairement sans faux positifs) :
 *   - pronoms/possessifs et verbes conjugués à la 2e personne du singulier, partout ;
 *   - impératif tutoyant juste après « Cosa fare : » / « Qué hacer : » (toujours un impératif) ;
 *   - e-mails italiens : aucun texte ne commence par un impératif au tu (boutons et phrases).
 * Les libellés de boutons d'administration (« Salva », « Elimina »…) sont la forme de commande neutre des interfaces
 * italiennes et ne sont pas contrôlés.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $root = _PS_MODULE_DIR_ . 'neria/data/';
    $admin = json_decode((string) file_get_contents($root . 'admin_translations.json'), true);
    $mail = json_decode((string) file_get_contents($root . 'translations.json'), true);
    neria_assert(is_array($admin) && is_array($mail), 'Dictionnaires illisibles');
    $plain = static function (string $s): string {
        return (string) preg_replace('/\{[^}]*\}|<[^>]+>/u', ' ', $s);
    };

    $itPron = '/\b(tu|tuo|tua|tuoi|tue|hai|puoi|vuoi|devi|sai|ricevi|riceverai|potrai|avrai|dovrai|vedrai|troverai|ricordati|riconnettiti)\b/iu';
    $esPron = '/\b(tú|contigo|tu|tus|tuyo|tuya|tuyos|tuyas|eres|tienes|puedes|quieres|debes|sabes|estás|podrás|tendrás)\b/iu';
    $itTu = 'verifica controlla reinstalla configura consulta contatta clicca aggiungi elimina completa correggi imposta attiva disattiva inserisci '
        . 'apri usa utilizza scegli seleziona ricalcola aggiorna riprova monitora aumenta rigenera ripristina rimuovi sostituisci risalva salva cerca '
        . 'attendi avvia passa vai leggi vedi prova testa migliora pulisci rilancia considera anticipa risolvi rendi ricarica copia crea invia '
        . 'esamina sospendi riapri assicurati disinstalla lascia definisci elenca collega applica limita';
    $esTu = 'comprueba verifica revisa añade elimina contacta configura activa desactiva reinstala reemplaza actualiza consulta selecciona elige '
        . 'haz ve vuelve abre completa corrige introduce inserta indica usa utiliza espera prueba copia crea busca guarda cambia recuerda evita '
        . 'asegúrate vigila limpia restaura sustituye añádelo edita exporta';
    $problems = [];
    $afterAction = static function (string $text, string $marker, array $tuSet) use ($plain): ?string {
        if (preg_match('/' . $marker . '\s*:?\s*([A-Za-zÀ-ÿ\']+)/u', $plain($text), $m) === 1 && in_array(mb_strtolower($m[1]), $tuSet, true)) {
            return $m[1];
        }
        return null;
    };
    $itTuSet = explode(' ', $itTu);
    $esTuSet = explode(' ', $esTu);

    foreach ($admin as $key => $byLang) {
        $it = (string) ($byLang['it'] ?? '');
        $es = (string) ($byLang['es'] ?? '');
        if (preg_match($itPron, $plain($it), $m) === 1) {
            $problems[] = "admin it {$key} : « {$m[0]} »";
        }
        if (($w = $afterAction($it, 'Cosa fare', $itTuSet)) !== null) {
            $problems[] = "admin it {$key} : impératif « {$w} » après « Cosa fare »";
        }
        if (preg_match($esPron, $plain($es), $m) === 1) {
            $problems[] = "admin es {$key} : « {$m[0]} »";
        }
        if (($w = $afterAction($es, 'Qué hacer', $esTuSet)) !== null) {
            $problems[] = "admin es {$key} : impératif « {$w} » après « Qué hacer »";
        }
    }
    $itStart = array_diff(explode(' ', 'scopri accedi visualizza vedi attiva procedi rispondi condividi contattaci scarica segui trova rimani tracci traccia unisciti convalida rinnova visita reimposta scansiona celebra completa consulta usa scegli annulla gestisci contatta conferma'), ['conferma']);
    foreach ($mail as $template => $byLang) {
        foreach (($byLang['it'] ?? []) as $key => $value) {
            $text = trim($plain((string) $value));
            if (preg_match($itPron, $text, $m) === 1) {
                $problems[] = "mail it {$template}.{$key} : « {$m[0]} »";
            }
            if (preg_match('/^([A-Za-zÀ-ÿ\']+)/u', $text, $m) === 1 && in_array(mb_strtolower($m[1]), $itStart, true)) {
                $problems[] = "mail it {$template}.{$key} : commence par l'impératif tutoyant « {$m[1]} »";
            }
            if (preg_match('/[:.,;—]\s+(scopri|accedi|visualizza|vedi|traccia|rimani|scansiona|contattaci|trova)\b/iu', $text, $m) === 1) {
                $problems[] = "mail it {$template}.{$key} : impératif tutoyant « {$m[1]} » dans la phrase";
            }
        }
    }
    neria_assert($problems === [], count($problems) . ' tutoiement(s) : ' . implode(' | ', array_slice($problems, 0, 8)));

    return [
        'pass'    => true,
        'message' => "Aucun tutoiement dans l'interface d'administration italienne et espagnole ni dans les e-mails italiens (pronoms, verbes, impératifs après « Cosa fare »/« Qué hacer », boutons et phrases des e-mails) — décision du 25/09/2026",
    ];
}
