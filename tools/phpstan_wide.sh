#!/usr/bin/env bash
# تحليل PHPStan الموسَّع على أجزاء (Controllers + Services) عشان الذاكرة.
#   tools/phpstan_wide.sh                     -> يفشل لو في خطأ مش في phpstan-wide-baseline.neon
#   tools/phpstan_wide.sh --generate-baseline -> يعيد توليد phpstan-wide-baseline.neon من كل الأجزاء
set -u
cd "$(dirname "$0")/.."
PHP="${PHP:-php}"
CHUNK="${PHPSTAN_CHUNK:-10}"
MEM="${PHPSTAN_MEMORY:-2G}"
LEVEL_ARG=""; [ -n "${PHPSTAN_LEVEL:-}" ] && LEVEL_ARG="--level=${PHPSTAN_LEVEL}"
GEN=0; [ "${1:-}" = "--generate-baseline" ] && GEN=1
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

chunks=()
# 1) كل مجلد فرعي في Services جزء لوحده
for d in app/Services/*/; do
  d="${d%/}"; [ "$(basename "$d")" = "AutoFix" ] && continue
  chunks+=("$d")
done
# 2) ملفات Services المباشرة جزء واحد
chunks+=("$(ls app/Services/*.php)")
# 3) Controllers على دفعات من $CHUNK ملف
mapfile -t ctl < <(ls app/Controllers/*.php)
for ((i=0; i<${#ctl[@]}; i+=CHUNK)); do chunks+=("$(printf '%s
' "${ctl[@]:i:CHUNK}")"); done

fail=0; n=0
for c in "${chunks[@]}"; do
  n=$((n+1))
  mapfile -t files <<<"$c"   # مسارات بسطر لكل واحد: الأسماء ممكن يكون فيها مسافات
  if [ $GEN -eq 1 ]; then
    "$PHP" vendor/bin/phpstan analyse -c phpstan-wide.neon --no-progress --memory-limit="$MEM" \
      --generate-baseline="$TMP/b$n.neon" --allow-empty-baseline "${files[@]}" >"$TMP/o$n.txt" 2>&1 || true
    if grep -qi "memory limit" "$TMP/o$n.txt"; then echo "CHUNK $n ran out of memory: ${files[*]}"; fail=1; fi
  else
    "$PHP" vendor/bin/phpstan analyse -c phpstan-wide.neon --no-progress --memory-limit="$MEM" $LEVEL_ARG "${files[@]}" || fail=1
  fi
done
if [ $GEN -eq 1 ]; then
  "$PHP" tools/phpstan_merge_baseline.php phpstan-wide-baseline.neon "$TMP"/b*.neon
fi
exit $fail
