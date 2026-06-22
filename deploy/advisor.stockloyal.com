# Apache vhost for advisor.stockloyal.com (Amazon Linux 2023, httpd).
# Place at /etc/httpd/conf.d/advisor.stockloyal.com.conf
# certbot will add the matching :443 vhost + HTTP->HTTPS redirect automatically.

<VirtualHost *:80>
    ServerName advisor.stockloyal.com
    DocumentRoot /var/www/kofc/web/dist

    # Built React SPA (static files)
    <Directory /var/www/kofc/web/dist>
        Require all granted
        AllowOverride None
    </Directory>

    # PHP API, mapped to /api (lives outside the docroot)
    Alias /api /var/www/kofc/api
    <Directory /var/www/kofc/api>
        Require all granted
        AllowOverride None
    </Directory>

    # Admin pages, mapped to /admin (protect further in production)
    Alias /admin /var/www/kofc/admin
    <Directory /var/www/kofc/admin>
        Require all granted
        AllowOverride None
    </Directory>

    # /var/www/kofc/storage holds uploaded source originals. It is NOT under the
    # docroot and has no Alias, so it is unreachable over HTTP by design.

    ErrorLog  /var/log/httpd/kofc_error.log
    CustomLog /var/log/httpd/kofc_access.log combined
</VirtualHost>