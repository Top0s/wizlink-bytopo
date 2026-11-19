#!/usr/bin/env bash
# Safety checks for a pull request (run by .github/workflows/review.yml, job "safety-checks").
#
#   bash .github/scripts/safety-checks.sh <base-sha> <head-sha>
#
# It looks only at what the pull request adds or changes:
#   1. no private files: .env, database files and dumps, backups and archives, private keys,
#      password files, service-account JSON;
#   2. no file bigger than 5 MB (backups and exports tend to be big);
#   3. no links (symlinks): Hostinger would copy them into the website folder, and one pointing
#      outside it could expose private settings;
#   4. no passwords, keys or tokens in the new commits (gitleaks, a free open-source scanner).
#      A pull request may not change gitleaks' own settings (.gitleaks.toml); changes to its list of
#      false alarms (.gitleaksignore) are listed in the summary so Iván sees them.
# Exit code 0 = nothing found, 1 = something must be fixed before merging, 2 = wrong use.
#
# A false alarm from gitleaks: add the fingerprint it prints to .gitleaksignore (one per line),
# in the same pull request, and say why in the pull request text.
set -uo pipefail

BASE="${1:-}"; HEAD="${2:-}"
[ -n "$BASE" ] && [ -n "$HEAD" ] || { echo "usage: $0 <base-sha> <head-sha>" >&2; exit 2; }
git rev-parse --verify -q "$HEAD^{commit}" >/dev/null || { echo "unknown commit: $HEAD" >&2; exit 2; }
git rev-parse --verify -q "$BASE^{commit}" >/dev/null || { echo "unknown commit: $BASE" >&2; exit 2; }

MAX_BYTES=5242880   # 5 MB
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"
FAIL=0
problem() { FAIL=1; echo "::error::$*"; echo "- ❌ $*" >> "$SUMMARY"; }
notice()  { echo "::warning::$*"; echo "- ⚠️ $*" >> "$SUMMARY"; }
fine()    { echo "OK: $*"; echo "- ✅ $*" >> "$SUMMARY"; }

echo "## Safety checks" >> "$SUMMARY"

MB=$(git merge-base "$BASE" "$HEAD") || { echo "no common history between $BASE and $HEAD" >&2; exit 2; }

# Files added, changed, renamed or retyped by the pull request, as they are at its last commit.
# -z and quotePath=false keep names with accents, spaces or quotes exactly as they are.
found_private=0; found_big=0; found_link=0
while IFS= read -r -d '' f; do
  name=$(basename "$f" | tr '[:upper:]' '[:lower:]')
  case "$name" in
    .env.example|.env.sample|.env.template|*.template.php) ;;   # templates without values are fine
    .env|.env.*|*.pem|*.key|*.p12|*.pfx|*.jks|*.kdbx|*.ppk|id_rsa*|id_ecdsa*|id_ed25519*|\
    .htpasswd|.npmrc|.pgpass|.my.cnf|\
    *.sql|*.sql.gz|*.sqlite|*.sqlite3|*.db|*.dump|*.bak|*.mdb|*.accdb|\
    *.zip|*.7z|*.rar|*.tar|*.tgz|*.tar.gz|\
    *service-account*.json|*credentials*.json)
      problem "Private file type, must not be in the repository: $f"; found_private=1 ;;
  esac
  case "$name" in
    .gitleaks.toml) problem "Changes the secret scanner's own settings (.gitleaks.toml): not allowed in a pull request" ;;
    .gitleaksignore) notice "Changes the list of gitleaks false alarms (.gitleaksignore): check why in the pull request text" ;;
  esac
  entry=$(git ls-tree "$HEAD" -- "$f")
  case "$entry" in
    120000*) problem "Link (symlink), not allowed: $f"; found_link=1; continue ;;
    160000*) continue ;;   # a submodule: no file to measure
  esac
  if ! size=$(git cat-file -s "$HEAD:$f" 2>/dev/null); then
    problem "Could not read this file to measure it: $f"; continue
  fi
  if [ "$size" -gt "$MAX_BYTES" ]; then
    problem "File over 5 MB (is it a backup or an export?): $f ($size bytes)"; found_big=1
  fi
done < <(git -c core.quotePath=false diff -z --name-only --diff-filter=ACMRT "$MB" "$HEAD")
[ "$found_private" = 0 ] && fine "No private files (.env, databases, backups, archives, keys)"
[ "$found_big" = 0 ] && fine "No file over 5 MB"
[ "$found_link" = 0 ] && fine "No links (symlinks)"

# Secrets in the new commits, with a pinned and checked copy of gitleaks.
GL_VERSION=8.30.1
GL_SHA256=551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
if curl -fsSL --retry 3 -o "$TMP/gitleaks.tgz" \
     "https://github.com/gitleaks/gitleaks/releases/download/v${GL_VERSION}/gitleaks_${GL_VERSION}_linux_x64.tar.gz" \
   && echo "${GL_SHA256}  $TMP/gitleaks.tgz" | sha256sum -c - >/dev/null \
   && tar -xzf "$TMP/gitleaks.tgz" -C "$TMP" gitleaks; then
  if "$TMP/gitleaks" git --no-banner --redact --log-opts="$MB..$HEAD" . ; then
    fine "No passwords, keys or tokens in the new commits"
  else
    problem "gitleaks found something that looks like a password, key or token (see the log above)"
  fi
else
  problem "Could not get the secret scanner (gitleaks): re-run this check"
fi

exit "$FAIL"
