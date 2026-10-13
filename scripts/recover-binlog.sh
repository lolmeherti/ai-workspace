#!/bin/sh
# Decode this machine's MySQL binlog with a throwaway container of the same image (nothing installed into any
# running container, nothing modified on disk), so rows deleted from the database can be read back out of the log
# instead of being declared lost. POSIX sh: the WSL docker distro has no bash.
set -eu

OUT=/tmp/binlog-recover
mkdir -p "$OUT"

IMG=$(docker inspect ai_mysql --format '{{.Config.Image}}')
echo "mysql image: $IMG"

docker cp ai_mysql:/var/lib/mysql/binlog.000288 "$OUT/binlog.000288"
ls -la "$OUT/binlog.000288"

docker run --rm -v "$OUT:/b" "$IMG" mysqlbinlog -v --base64-output=DECODE-ROWS \
  --start-datetime="2026-10-11 00:00:00" --stop-datetime="2026-10-11 00:25:00" \
  /b/binlog.000288 > "$OUT/decoded.txt"

echo "decoded bytes: $(wc -c < "$OUT/decoded.txt")"
echo "insert statements seen: $(grep -c 'INSERT INTO' "$OUT/decoded.txt" || true)"
cp "$OUT/decoded.txt" /mnt/c/Users/Admin-PC/Desktop/ai-workspace/src/tests/live/results/binlog-decoded.txt
echo "copied to src/tests/live/results/binlog-decoded.txt"
