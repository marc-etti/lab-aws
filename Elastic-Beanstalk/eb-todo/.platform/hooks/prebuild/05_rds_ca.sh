#!/bin/bash
# Scarica il bundle CA di Amazon RDS (una volta sola per istanza)
set -eu
CA=/etc/pki/tls/certs/rds-global-bundle.pem
if [ ! -s "$CA" ]; then
  curl -fsSL https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem -o "$CA"
  chmod 644 "$CA"
fi
