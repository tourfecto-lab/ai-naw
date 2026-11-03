#!/usr/bin/env bash
# يربط مجلد النسخة القديمة (اللي بيخدم tourfectoai.pro) بالكود المنشور على main، من غير ما يمس الدومين الأساسي.
# الاستخدام (كـ root على السيرفر):
#   bash tools/ops/relink_old_domain.sh            # فحص بس، مفيش تغيير
#   bash tools/ops/relink_old_domain.sh --apply    # التنفيذ (نسخة احتياطية + استنساخ + تبديل)
#   bash tools/ops/relink_old_domain.sh --rollback # الرجوع للنسخة القديمة
# كل المسارات تتغيّر بمتغيرات بيئة للتجربة المحلية. مفيش أي cron بيتضاف، ومفيش حذف لأي ملف.
set -euo pipefail

OLD="${OLD:-/www/wwwroot/tourfecto.pro}"
SRC="${SRC:-/www/wwwroot/tourfectoai.pro}"
REMOTE="${REMOTE:-https://github.com/tourfecto-lab/ai-naw.git}"
OWNER="${OWNER:-www}"
PHP_BIN="${PHP_BIN:-/www/server/php/84/bin/php}"
SKIP_SERVICES="${SKIP_SERVICES:-0}"   # 1 = من غير chown/nginx/php (للتجربة المحلية بس)
MODE="${1:-plan}"
NEW="${OLD}.new"
OLD_KEEP="${OLD}.old"
G=(git -c safe.directory='*')

say() { printf '%s\n' "$*"; }
die() { printf 'خطأ: %s\n' "$*" >&2; exit 1; }
reload_nginx() { [ "$SKIP_SERVICES" = 1 ] && return 0; nginx -t >/dev/null 2>&1 && nginx -s reload; }

if [ "$MODE" = "--rollback" ]; then
  [ -d "$OLD_KEEP" ] || die "مفيش نسخة قديمة للرجوع ليها ($OLD_KEEP)"
  mv "$OLD" "${OLD}.failed-$(date +%s)"
  mv "$OLD_KEEP" "$OLD"
  reload_nginx || die "nginx -t فشل بعد الرجوع؛ راجع الإعداد"
  say "تم الرجوع للنسخة القديمة."
  exit 0
fi

[ "$SKIP_SERVICES" = 1 ] || [ "$(id -u)" = 0 ] || die "شغّله كـ root"
[ -d "$OLD" ] || die "المجلد القديم مش موجود: $OLD"
[ -d "$SRC/.git" ] || die "مجلد المصدر مش مستودع git: $SRC"
[ -d "$SRC/vendor" ] || die "مفيش vendor في $SRC"
[ ! -d "$OLD/.git" ] || die "المجلد القديم متربوط بـgit أصلًا؛ مفيش حاجة تتعمل"
[ -f "$OLD/.env" ] || die "مفيش .env في $OLD (هو مطلوب ومش هيتنقل من غيره)"
[ ! -e "$NEW" ] || die "$NEW موجود من محاولة قبل كده؛ شوفه واحذفه بنفسك بعد ما تتأكد"
[ ! -e "$OLD_KEEP" ] || die "$OLD_KEEP موجود؛ شيله أو غيّر اسمه الأول"

need_kb=$(du -sk "$OLD" | cut -f1)
free_kb=$(df -Pk "$(dirname "$OLD")" | awk 'NR==2{print $4}')
[ "$free_kb" -gt $((need_kb * 3)) ] || die "المساحة الفاضية ($((free_kb/1024)) MB) أقل من 3 أضعاف حجم المجلد القديم ($((need_kb/1024)) MB)"

say "الفحص تمام:"
say "  المجلد القديم : $OLD ($((need_kb/1024)) MB، مش مربوط بـgit)"
say "  مصدر الكود    : $SRC ($("${G[@]}" -C "$SRC" log -1 --format='%h %s' | cut -c1-70))"
say "  المساحة الفاضية: $((free_kb/1024)) MB"
[ "$MODE" = "--apply" ] || { say "ده فحص بس. للتنفيذ: bash $0 --apply"; exit 0; }

BAK="${OLD}.bak-$(date +%F-%H%M)"
say "1/6 نسخة احتياطية كاملة: $BAK"
cp -a "$OLD" "$BAK"
say "2/6 استنساخ الكود المنشور"
"${G[@]}" clone --local --no-hardlinks --quiet "$SRC" "$NEW"
"${G[@]}" -C "$NEW" remote set-url origin "$REMOTE"
say "3/6 نقل الملفات اللي مش على git (.env, storage, uploads) ونسخ vendor من المصدر"
cp -a "$OLD/.env" "$NEW/.env"
[ -d "$OLD/storage" ] && { rm -rf "$NEW/storage"; cp -a "$OLD/storage" "$NEW/storage"; }
mkdir -p "$NEW/public_html"
[ -d "$OLD/public_html/uploads" ] && { rm -rf "$NEW/public_html/uploads"; cp -a "$OLD/public_html/uploads" "$NEW/public_html/uploads"; }
cp -a "$SRC/vendor" "$NEW/vendor"
[ "$SKIP_SERVICES" = 1 ] || chown -R "$OWNER:$OWNER" "$NEW"
say "4/6 فحص النسخة الجديدة قبل التبديل"
[ -f "$NEW/public_html/index.php" ] || die "public_html/index.php ناقص في النسخة الجديدة؛ التبديل اتلغى (القديم سليم)"
if [ "$SKIP_SERVICES" != 1 ]; then "$PHP_BIN" -l "$NEW/public_html/index.php" >/dev/null || die "فحص PHP فشل؛ التبديل اتلغى (القديم سليم)"; fi
say "   الكود الجديد: $("${G[@]}" -C "$NEW" log -1 --format='%h %s' | cut -c1-70)"
say "5/6 التبديل"
mv "$OLD" "$OLD_KEEP"
mv "$NEW" "$OLD"
say "6/6 إعادة تحميل nginx"
if ! reload_nginx; then
  say "nginx فشل؛ رجوع تلقائي"
  mv "$OLD" "${OLD}.failed-$(date +%s)"; mv "$OLD_KEEP" "$OLD"; reload_nginx || true
  die "اتعمل رجوع للنسخة القديمة"
fi
say "تم. النسخة القديمة محفوظة في $OLD_KEEP، والنسخة الاحتياطية في $BAK."
say "للرجوع: bash $0 --rollback"
say "ملاحظة: ماتضيفش cron على المجلد ده؛ الكرون يفضل على $SRC بس."
