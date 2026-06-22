# Build the SPA and push code to the KofC EC2 box. Run from the project ROOT in PowerShell:
#   cd C:\xampp\htdocs\kofc
#   .\deploy\deploy.ps1 -KeyPath C:\Users\you\.ssh\kofc-advisor-key.pem -RemoteHost ec2-user@<elastic-ip>
# If PowerShell blocks the script: Set-ExecutionPolicy -Scope Process -Bypass
# Requires OpenSSH (scp/ssh) — built into Windows 10/11.
param(
  [Parameter(Mandatory=$true)][string]$KeyPath,      # c:\Users\reian\.ssh\kofc-advisor-key.pem
  [Parameter(Mandatory=$true)][string]$RemoteHost    # ec2-user@3.132.32.218
)

$ErrorActionPreference = "Stop"
$root = Split-Path $PSScriptRoot -Parent   # project root (parent of deploy\)

Write-Host "Building SPA..." -ForegroundColor Cyan
Push-Location "$root\web"; npm run build; Pop-Location

Write-Host "Uploading to staging..." -ForegroundColor Cyan
ssh -i $KeyPath $RemoteHost "mkdir -p ~/kofc-deploy"
scp -i $KeyPath -r "$root\web\dist" "$root\api" "$root\admin" "$root\sql" "${RemoteHost}:~/kofc-deploy/"

Write-Host "Installing on server..." -ForegroundColor Cyan
ssh -i $KeyPath $RemoteHost @"
sudo mkdir -p /var/www/kofc/web/dist /var/www/kofc/storage
sudo rsync -a --delete ~/kofc-deploy/dist/  /var/www/kofc/web/dist/
sudo rsync -a          ~/kofc-deploy/api/   /var/www/kofc/api/
sudo rsync -a          ~/kofc-deploy/admin/ /var/www/kofc/admin/
sudo rsync -a          ~/kofc-deploy/sql/   /var/www/kofc/sql/
sudo chown -R apache:apache /var/www/kofc
sudo chmod -R 0775 /var/www/kofc/storage
sudo systemctl reload httpd
echo 'Deployed.'
"@
Write-Host "Done." -ForegroundColor Green