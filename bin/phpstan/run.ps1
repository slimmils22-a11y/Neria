# Lance l'analyse statique PHPStan sur le module Neria.
# Usage : powershell -File bin\phpstan\run.ps1
# Pour régénérer la baseline après avoir accepté de nouveaux avertissements
# (jamais pour masquer un vrai bug, seulement du bruit type-hygiène confirmé) :
# powershell -File bin\phpstan\run.ps1 -GenerateBaseline

param(
    [switch]$GenerateBaseline
)

$php = "C:\laragon\bin\php\php-8.1.29-Win32-vs16-x64\php.exe"
$phpstan = Join-Path $PSScriptRoot "phpstan.phar"
$configPath = Join-Path (Split-Path $PSScriptRoot -Parent | Split-Path -Parent) "phpstan.neon"

if ($GenerateBaseline) {
    & $php -d memory_limit=2G $phpstan analyse --configuration=$configPath --no-progress --generate-baseline
} else {
    & $php -d memory_limit=2G $phpstan analyse --configuration=$configPath --no-progress
}
