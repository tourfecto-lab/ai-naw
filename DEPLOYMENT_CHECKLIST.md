# Production checklist

- [ ] PHP >= 8.0
- [ ] Required PHP extensions: PDO, mbstring, openssl, curl, gd
- [ ] `.env` configured on server (do not upload real secrets to Git/archives)
- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] Database backup completed
- [ ] `php database/repair_production.php --dry-run`
- [ ] `php database/repair_production.php --apply`
- [ ] `php tools/lint.php`
- [ ] Cron jobs configured
- [ ] Queue worker configured if asynchronous queue is enabled
- [ ] SMTP/API/payment credentials tested
- [ ] HTTPS enabled
- [ ] Public diagnostics are not accessible
