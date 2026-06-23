#requires -Version 5
<#
  push.ps1 - commit local changes and push to GitHub, then remind you to deploy on the box.

  Run from the repo root on Windows (PowerShell):
    .\push.ps1 "your commit message"
    .\push.ps1 "msg" -Build      # also run a local 'npm run build' first as a sanity check

  The server builds the SPA itself (deploy.ps1/SCP is unused on the hotspot), so a local
  build here is optional - it only catches JSX/Vite errors before they reach the box.
#>
[CmdletBinding()]
param(
  [Parameter(Position = 0)]
  [string]$Message = "deploy: $(Get-Date -Format 'yyyy-MM-dd HH:mm')",
  [switch]$Build
)
$ErrorActionPreference = 'Stop'

# Repo root = the folder this script lives in.
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Root

if ($Build) {
  Write-Host "==> local build sanity check (web)" -ForegroundColor Cyan
  Push-Location (Join-Path $Root 'web')
  try {
    npm run build
    if ($LASTEXITCODE -ne 0) { throw "npm run build failed" }
  } finally { Pop-Location }
}

Write-Host "==> staging changes" -ForegroundColor Cyan
git add -A

if ([string]::IsNullOrWhiteSpace((git status --porcelain))) {
  Write-Host "nothing to commit; pushing any unpushed commits" -ForegroundColor Yellow
} else {
  git commit -m $Message
  if ($LASTEXITCODE -ne 0) { throw "git commit failed" }
}

$branch = (git rev-parse --abbrev-ref HEAD).Trim()
Write-Host "==> git push origin $branch" -ForegroundColor Cyan
git push origin $branch
if ($LASTEXITCODE -ne 0) { throw "git push failed" }

Write-Host ""
Write-Host "Pushed. Now on the EC2 box (EC2 Instance Connect), run:" -ForegroundColor Green
Write-Host "  cd ~/kofc && ./deploy.sh" -ForegroundColor Green