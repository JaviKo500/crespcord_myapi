$src = Split-Path $PSScriptRoot -Parent

Set-Location $src

# core.hooksPath apunta a los hooks versionados en lugar de copiarlos a
# .git/hooks: no hay copia que mantener sincronizada.
git config core.hooksPath scripts/hooks

Write-Host "Hooks instalados: core.hooksPath = scripts/hooks"
Write-Host "Para desinstalarlos: git config --unset core.hooksPath"
