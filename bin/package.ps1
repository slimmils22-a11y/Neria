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
