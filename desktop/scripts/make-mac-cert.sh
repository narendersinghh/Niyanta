#!/usr/bin/env bash
# Creates the free, self-signed code-signing certificate that every macOS build of Niyanta Tracker is signed with, so the
# app keeps the same code identity (designated requirement) across updates and macOS keeps its Screen Recording permission.
# Run it ONCE (macOS or Linux, needs openssl), then add the two printed values as GitHub Actions secrets. Keep the output
# folder somewhere safe and private (a password manager): a new certificate means everyone has to allow Screen Recording
# once more. Never commit it. It does nothing for Windows (SmartScreen and Defender ignore self-signed certificates).
#   bash desktop/scripts/make-mac-cert.sh [output-folder]
set -euo pipefail

OUT="${1:-$HOME/niyanta-mac-cert}"
NAME="Niyanta Tracker Self-Signed"
DAYS=7300
BUNDLE_ID="app.niyanta.tracker"

command -v openssl >/dev/null 2>&1 || { echo "openssl is needed" >&2; exit 1; }
if [ -e "$OUT/cert.p12" ]; then echo "$OUT/cert.p12 already exists. Reuse it (same identity) or choose another folder." >&2; exit 1; fi
mkdir -p "$OUT"; chmod 700 "$OUT"
umask 077

cat > "$OUT/openssl.cnf" <<EOF
[req]
distinguished_name = dn
prompt = no
x509_extensions = ext
[dn]
CN = $NAME
O = Niyanta
[ext]
basicConstraints = critical, CA:false
keyUsage = critical, digitalSignature
extendedKeyUsage = critical, codeSigning
subjectKeyIdentifier = hash
EOF

PASS="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"
openssl req -new -x509 -newkey rsa:2048 -nodes -sha256 -days "$DAYS" -config "$OUT/openssl.cnf" -keyout "$OUT/key.pem" -out "$OUT/cert.pem" 2>/dev/null
# 3DES / SHA-1 protection: the format the macOS keychain (security import) reads; OpenSSL 3 would default to AES otherwise
if ! openssl pkcs12 -export -inkey "$OUT/key.pem" -in "$OUT/cert.pem" -name "$NAME" -out "$OUT/cert.p12" -passout "pass:$PASS" \
    -keypbe PBE-SHA1-3DES -certpbe PBE-SHA1-3DES -macalg sha1 2>/dev/null; then
  openssl pkcs12 -export -inkey "$OUT/key.pem" -in "$OUT/cert.pem" -name "$NAME" -out "$OUT/cert.p12" -passout "pass:$PASS" -keypbe PBE-SHA1-3DES -certpbe PBE-SHA1-3DES
fi
printf '%s' "$PASS" > "$OUT/password.txt"
SHA1="$(openssl x509 -in "$OUT/cert.pem" -noout -fingerprint -sha1 | sed 's/.*=//; s/://g' | tr 'A-F' 'a-f')"
base64 < "$OUT/cert.p12" | tr -d '\n' > "$OUT/cert.p12.base64"

cat <<EOF

Created in $OUT (private: keep it, never commit it):
  cert.p12, password.txt, cert.p12.base64, cert.pem, key.pem

Certificate SHA-1: $SHA1
Every macOS build signed with it has this designated requirement (the CI build checks it):
  identifier "$BUNDLE_ID" and certificate ... = H"$SHA1"

Add two repository secrets (GitHub > Settings > Secrets and variables > Actions > New repository secret):
  MAC_SELF_CERT_P12       = the content of $OUT/cert.p12.base64
  MAC_SELF_CERT_PASSWORD  = $PASS

or with the GitHub CLI, from the repository folder:
  gh secret set MAC_SELF_CERT_P12 < "$OUT/cert.p12.base64"
  gh secret set MAC_SELF_CERT_PASSWORD < "$OUT/password.txt"

The next release is then signed with it. People allow Screen Recording once more for that release (the identity changes
from ad hoc to this certificate); after that the permission stays through every update.
EOF
