# KofC AI Advisor — AWS Deployment Runbook

Target: a single **EC2** instance (Amazon Linux 2023, Apache `httpd` + PHP) serving the built
React SPA and the PHP API, with the database as a **new database on the existing StockLoyal RDS**
(isolated by a KofC-scoped DB user), HTTPS via **Let's Encrypt**, on **advisor.stockloyal.com**.

Server layout:

```
/var/www/kofc/
├── web/dist/     ← DocumentRoot (built SPA)
├── api/          ← Alias /api   (PHP endpoints)
├── admin/        ← Alias /admin (admin pages)
├── sql/          ← schema files (not web-served)
├── storage/      ← uploaded source originals (NOT web-reachable)
└── vendor/       ← composer (smalot/pdfparser for PDF text)
/etc/kofc/config.local.php   ← secrets, outside the webroot
```

---

## 1. Database on the existing StockLoyal RDS

Connect to the StockLoyal RDS as the **master** user (from your machine or a bastion) and run
`deploy/rds-setup.sql` after setting a strong password in it:

```sql
CREATE DATABASE IF NOT EXISTS kofc_advisor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'kofc_app'@'%' IDENTIFIED BY 'STRONG-DB-PASSWORD';
GRANT ALL PRIVILEGES ON kofc_advisor.* TO 'kofc_app'@'%';
FLUSH PRIVILEGES;
```

The `kofc_app` user is granted **only** on `kofc_advisor.*`, so it cannot touch StockLoyal data —
logical isolation even though the RDS instance is shared.

**Security group:** the new EC2 must reach RDS on 3306. In the RDS security group, add an inbound
rule for MySQL/Aurora (3306) whose source is the **EC2 instance's security group** (preferred) or
its private IP. Do not open 3306 to the world.

---

## 2. Launch the EC2 instance

- AMI: **Amazon Linux 2023**, t3.small (or t3.micro for a light demo).
- Security group inbound: 22 (SSH, your IP), 80 (HTTP, anywhere), 443 (HTTPS, anywhere).
- Allocate and associate an **Elastic IP** (stable address for DNS + Let's Encrypt).
- Key pair: create a new KofC key (`.pem`); keep it alongside your StockLoyal key.

---

## 3. DNS

In whatever manages stockloyal.com DNS, add an **A record**:

```
advisor.stockloyal.com  →  <EC2 Elastic IP>
```

Wait for it to resolve (`nslookup advisor.stockloyal.com`) before running certbot in step 8.

---

## 4. Install the server stack

SSH in (`ssh -i kofc.pem ec2-user@<elastic-ip>`), then:

```bash
sudo dnf update -y
sudo dnf install -y httpd php php-mysqlnd php-pdo php-mbstring php-xml php-curl php-zip rsync
sudo systemctl enable --now httpd

# Composer (for PDF text extraction)
php -r "copy('https://getcomposer.org/installer','composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
```

---

## 5. Push the code

From your **Windows** machine, in the project root:

```powershell
.\deploy\deploy.ps1 -KeyPath C:\path\to\kofc.pem -Host ec2-user@<elastic-ip>
```

This builds `web/dist`, uploads `dist/`, `api/`, `admin/`, `sql/`, and installs them under
`/var/www/kofc` with the right ownership. (First run also creates the dirs.)

Then on the server, install the PDF parser once:

```bash
cd /var/www/kofc && sudo composer require smalot/pdfparser && sudo chown -R apache:apache vendor
```

---

## 6. Secrets (outside the webroot)

Copy `deploy/config.local.php.prod.example` to the server, fill in the RDS endpoint, the
`kofc_app` password, and your OpenAI key, then install it:

```bash
sudo mkdir -p /etc/kofc
sudo install -o root -g apache -m 0640 config.local.php.prod.example /etc/kofc/config.local.php
sudo nano /etc/kofc/config.local.php   # fill in real values; ai_mock=false, auth_disabled=false
```

`config.php` automatically prefers `/etc/kofc/config.local.php` over anything in the webroot.

---

## 7. Apache vhost

Install the vhost and reload:

```bash
sudo cp /home/ec2-user/kofc-deploy/.../advisor.stockloyal.com.conf /etc/httpd/conf.d/  # or scp it
sudo apachectl configtest && sudo systemctl reload httpd
```

(If you didn't include the conf in the upload, paste `deploy/advisor.stockloyal.com.conf` into
`/etc/httpd/conf.d/advisor.stockloyal.com.conf` by hand.)

Make the storage dir writable for uploads:

```bash
sudo mkdir -p /var/www/kofc/storage && sudo chown -R apache:apache /var/www/kofc/storage
sudo chmod -R 0775 /var/www/kofc/storage
```

---

## 8. HTTPS with Let's Encrypt

```bash
sudo dnf install -y certbot python3-certbot-apache
sudo certbot --apache -d advisor.stockloyal.com
```

Certbot provisions the cert, adds the :443 vhost, and sets the HTTP→HTTPS redirect. Confirm
auto-renewal:

```bash
sudo systemctl enable --now certbot-renew.timer
sudo certbot renew --dry-run
```

---

## 9. Harden the PHP session (for HTTPS cookie auth)

Edit `/etc/php.ini` (or a file in `/etc/php.d/`) and set:

```ini
session.cookie_httponly = 1
session.cookie_secure   = 1
session.cookie_samesite = "Lax"
```

Then `sudo systemctl restart httpd`.

---

## 10. Load the schema into RDS

From the server (or anywhere that can reach RDS), load all schema files in order:

```bash
cd /var/www/kofc/sql
for f in schema advisor_questions kb_chunks kb_collections conversations feedback users users_must_change; do
  mysql -h <RDS-ENDPOINT> -u kofc_app -p kofc_advisor < $f.sql
done
```

---

## 11. Create the first admin

```bash
cd /var/www/kofc/api
sudo -u apache php user-create.php robert 'STRONG-ADMIN-PASSWORD' admin
```

(Run as the apache user so it reads `/etc/kofc/config.local.php` the same way the web app does.)

---

## 12. Smoke test

- `https://advisor.stockloyal.com/api/me.php` → `{"error":"not authenticated"}` (API alive).
- `https://advisor.stockloyal.com/` → login screen; sign in as `robert`.
- Advisor tab → ask a question → grounded answer (requires `ai_mock=false` + working key).
- `https://advisor.stockloyal.com/admin/users.html` → user management (admin session).

If the SPA loads but API calls fail: check `/var/log/httpd/kofc_error.log` and confirm
`/etc/kofc/config.local.php` has the right RDS endpoint and the EC2→RDS security group rule exists.

---

## 13. Ongoing deploys

Code changes after launch:

```powershell
.\deploy\deploy.ps1 -KeyPath C:\path\to\kofc.pem -Host ec2-user@<elastic-ip>
```

This never touches `/etc/kofc/config.local.php`, `vendor/`, or `storage/`, so secrets, the PDF
parser, and uploaded documents survive every deploy. Backend changes take effect on reload;
SPA changes ship because `dist/` is rebuilt and synced.

---

## Notes / hardening backlog

- The admin pages (`/admin/*`) require an admin session but have no login screen of their own —
  log in via the SPA first in the same browser. Consider an Apache-level restriction on `/admin`
  (IP allow-list or basic auth) as an extra layer for production.
- KofC data lives in its own database with its own DB user; keep the OpenAI key and all KofC
  credentials separate from StockLoyal's.
- For real production scale, the next steps are an ALB + ACM cert (instead of Let's Encrypt on the
  box) and moving the SPA to S3 + CloudFront — neither needed for the demo.
