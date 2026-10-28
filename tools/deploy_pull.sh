#!/bin/bash
# ============================================================================
# Tourfecto - تحديث السيرفر تلقائيًا من GitHub
#
# بيتشغل من Cron في aaPanel كل 5 دقايق:
#   bash /www/wwwroot/tourfecto.pro/tools/deploy_pull.sh
#
# - بيسحب آخر نسخة من الفرع المحدد على GitHub (بمفتاح قراءة فقط)
# - قبل ما يطبّقها بيتأكد إن كل ملفات PHP اللي اتغيرت سليمة؛ لو فيه ملف فيه
#   خطأ مش بيطبّق حاجة والموقع بيفضل على النسخة الشغالة
# - الملفات اللي مش في GitHub (.env، الصور المرفوعة، الجلسات، vendor) مش بتتلمس
# - بيسجل كل تحديث في storage/logs/deploy.log
# ============================================================================
set -u

APP_DIR="${APP_DIR:-/www/wwwroot/tourfecto.pro}"
BRANCH="${DEPLOY_BRANCH:-claude/stoic-heisenberg-66srnk}"
KEY="${DEPLOY_KEY:-/root/.ssh/tourfecto_deploy}"
LOG="$APP_DIR/storage/logs/deploy.log"
PHP_BIN="${PHP_BIN:-$(command -v php || ls -d /www/server/php/*/bin/php 2>/dev/null | sort -V | tail -1)}"

log() { mkdir -p "$(dirname "$LOG")"; echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }

# تشغيل واحد بس في نفس الوقت
exec 9>/tmp/tourfecto-deploy.lock
flock -n 9 || exit 0

cd "$APP_DIR" || { echo "ERROR: $APP_DIR not found"; exit 1; }
mkdir -p "$(dirname "$LOG")"
export GIT_SSH_COMMAND="ssh -i $KEY -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
# فولدر الموقع ملك المستخدم www وإحنا شغالين root: git بيرفض من غير ده
git() { command git -c safe.directory="$APP_DIR" "$@"; }

if ! git fetch -q origin "$BRANCH" 2>>"$LOG"; then
    log "ERROR: git fetch failed (check the deploy key on GitHub)"
    exit 1
fi

NEW="$(git rev-parse FETCH_HEAD)"
OLD="$(git rev-parse -q --verify HEAD 2>/dev/null || echo none)"
[ "$NEW" = "$OLD" ] && exit 0

# فحص ملفات PHP اللي اتغيرت قبل التطبيق (أول مرة: كل الملفات)
if [ -n "$PHP_BIN" ]; then
    if [ "$OLD" = "none" ]; then
        FILES="$(git ls-tree -r --name-only "$NEW" | grep '\.php$')"
    else
        FILES="$(git diff --name-only --diff-filter=AM "$OLD" "$NEW" -- '*.php')"
    fi
    TMP="$(mktemp /tmp/tfchk.XXXXXX.php)"
    BAD=""
    for f in $FILES; do
        git show "$NEW:$f" > "$TMP" 2>/dev/null || continue
        if ! "$PHP_BIN" -l "$TMP" >/dev/null 2>&1; then BAD="$BAD $f"; fi
    done
    rm -f "$TMP"
    if [ -n "$BAD" ]; then
        log "SKIPPED $NEW: PHP syntax errors in:$BAD"
        exit 1
    fi
fi

git reset -q --hard "$NEW" || { log "ERROR: could not apply $NEW"; exit 1; }
chown -R www:www "$APP_DIR" 2>/dev/null

SQL=""
[ "$OLD" != "none" ] && SQL="$(git diff --name-only --diff-filter=A "$OLD" "$NEW" -- '*.sql' | tr '\n' ' ')"
log "DEPLOYED $NEW (was $OLD)${SQL:+ - new SQL to run in phpMyAdmin: $SQL}"
