# Genere le zip de distribution du module Neria a partir de l'etat
# COMMIT (pas du disque) : seuls les fichiers suivis par Git et non
# marques export-ignore dans .gitattributes finissent dans le zip.
# .claude/, .git/, les fichiers non commites, tests/ et autres outils
# de dev ne peuvent donc jamais s'y retrouver, quelle que soit leur
# presence sur le disque au moment ou ce script tourne.
#
# Usage : depuis la racine du depot, .\bin\package.ps1

$ErrorActionPreference = "Stop"
Set-Location (Split-Path $PSScriptRoot -Parent)

if ((git status --porcelain) -ne $null) {
    Write-Host "ATTENTION : des changements ne sont pas commit es. Le zip sera genere depuis le dernier commit (HEAD), pas depuis l'etat actuel du disque." -ForegroundColor Yellow
}

$version = (Select-String -Path config.xml -Pattern '<version><!\[CDATA\[(.*?)\]\]></version>').Matches[0].Groups[1].Value
if (-not $version) {
    throw "Impossible de lire la version depuis config.xml"
}

New-Item -ItemType Directory -Force -Path dist | Out-Null
$output = "dist/neria-$version.zip"

if (Test-Path $output) {
    Remove-Item $output -Force
}

git archive --format=zip --prefix=neria/ -o $output HEAD

Write-Host "Zip genere : $output" -ForegroundColor Green

# ── Audit post-generation : le Watchdog du module ne peut PAS voir ce
# genre de probleme (il tourne sur une boutique en vie, jamais sur le
# contenu d'un zip) - trouve une fois en verifiant le zip a la main
# (residus de test "Test Neria"/localhost dans mails/{lang}/, commites
# par erreur). On grep desormais le CONTENU LIVRE AU CLIENT a chaque
# generation, pour ne plus jamais le decouvrir apres coup.
#
# Perimetre volontairement restreint a ce qui part reellement chez le
# marchand/client (mails/themes = source des emails, views/templates
# et views/js = BO+front, meme perimetre que HealthCheckManager::
# checkDevToolResidue()) - PAS src/*.php ni data/*.json : le code source
# mentionne legitimement "mailpit"/"localhost" dans ses commentaires et
# ses propres listes de mots-cles de detection, ce n'est pas un residu.
Write-Host "Verification du contenu livre au client..." -ForegroundColor Cyan

$auditDir = "dist/_audit_tmp"
if (Test-Path $auditDir) { Remove-Item $auditDir -Recurse -Force }
Expand-Archive -Path $output -DestinationPath $auditDir -Force

$scanRoots = @(
    "$auditDir/neria/mails/themes",
    "$auditDir/neria/views/templates",
    "$auditDir/neria/views/js"
) | Where-Object { Test-Path $_ }

$suspects = @('Test Neria', 'localhost', '127\.0\.0\.1', 'mailpit', 'laragon', 'NERIA-VIP-2026')

$findings = @()
if ($scanRoots.Count -gt 0) {
    $files = Get-ChildItem -Path $scanRoots -Recurse -File | Select-Object -ExpandProperty FullName
    if ($files.Count -gt 0) {
        foreach ($pattern in $suspects) {
            $matches = Select-String -Path $files -Pattern $pattern -ErrorAction SilentlyContinue
            foreach ($m in $matches) {
                $findings += "  $($m.Path -replace [regex]::Escape((Resolve-Path $auditDir).Path), '') : `"$pattern`" (ligne $($m.LineNumber))"
            }
        }
    }
}

Remove-Item $auditDir -Recurse -Force

if ($findings.Count -gt 0) {
    Write-Host ""
    Write-Host "ECHEC : residus de dev/test trouves dans le zip - NE PAS DISTRIBUER :" -ForegroundColor Red
    $findings | Select-Object -Unique | ForEach-Object { Write-Host $_ -ForegroundColor Red }
    Remove-Item $output -Force
    throw "Zip supprime. Corriger la source (donnees de test committees par erreur) puis relancer."
}

Write-Host "Aucun residu de dev/test detecte. Zip pret." -ForegroundColor Green
