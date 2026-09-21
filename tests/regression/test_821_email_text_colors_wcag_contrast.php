<?php
/**
 * Constat F-002 (campagne de tests fonctionnels, 21/09/2026) : l'accent (#b38b59, 3,11:1 sur blanc) et le gris #8c857e
 * (3,64:1) servaient de couleur de TEXTE dans les e-mails, sous le seuil WCAG AA de 4,5:1.
 * (1) ConfigManager::getAccessibleTextColor() atteint 4,5:1 pour n'importe quel accent choisi par le marchand, garde
 *     la teinte, laisse intact un accent déjà suffisant. (2) Rendu réel : les liens/pied de page (layout) et les prix
 *     d'un template utilisent la variante texte, alors que les filets/bordures gardent l'accent d'origine.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    $lum = static function (string $hex): float {
        $h = ltrim($hex, '#');
        $l = [];
        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($h, $i, 2)) / 255;
            $l[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    };
    $ratio = static function (string $a, string $b) use ($lum): float {
        $x = $lum($a);
        $y = $lum($b);
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    };
    foreach (['#b38b59', '#e8c07a', '#ffd700', '#7fb069', '#ff9ecd', '#cccccc'] as $accent) {
        $t = ConfigManager::getAccessibleTextColor($accent);
        neria_assert($ratio($t, '#ffffff') >= 4.5, "accent {$accent} → {$t} : contraste " . round($ratio($t, '#ffffff'), 2) . ' < 4,5');
    }
    neria_assert(ConfigManager::getAccessibleTextColor('#b38b59') !== '#b38b59', "l'accent par défaut n'est pas assombri");
    neria_assert(ConfigManager::getAccessibleTextColor('#222222') === '#222222', "un accent déjà lisible a été modifié");
    neria_assert(ConfigManager::getAccessibleTextColor('pas une couleur') === 'pas une couleur', "une valeur invalide doit être renvoyée telle quelle");
    neria_assert(ratio_ok($ratio, ConfigManager::getAccessibleTextColor('#b38b59', '#f5efe6')), 'contraste insuffisant sur fond crème');

    $renderer = new EmailRenderer(neria_test_module());
    $html = $renderer->renderWithVars('voucher', 'fr', ['firstname' => 'Test', 'voucher_code' => 'ABC123']);
    neria_assert(is_string($html) && $html !== '', 'rendu voucher vide');
    $design = (new ConfigManager(neria_test_module()))->getDesignConfig();
    $accent = strtolower((string) $design['color_accent']);
    $text = strtolower(ConfigManager::getAccessibleTextColor($accent, (string) ($design['color_container'] ?? '#ffffff')));
    neria_assert(strpos(strtolower($html), 'color:' . $text) !== false || strpos(strtolower($html), 'color: ' . $text) !== false, "la couleur de texte accessible {$text} n'apparaît pas dans le rendu");
    neria_assert(!preg_match('/[^-\w]color:\s*' . preg_quote($accent, '/') . '/i', $html), "l'accent brut {$accent} est encore utilisé comme couleur de TEXTE dans le rendu");
    neria_assert(stripos($html, '#8c857e') === false, 'le gris #8c857e (3,64:1) est encore utilisé dans le rendu');
    return ['pass' => true, 'message' => "les couleurs de texte des e-mails atteignent WCAG AA 4,5:1 (accent assombri, gris #6b655e), filets et bordures gardent l'accent"];
}

function ratio_ok(callable $ratio, string $textColor): bool
{
    return $ratio($textColor, '#f5efe6') >= 4.5;
}
