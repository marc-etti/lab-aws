#!/bin/bash
# Eseguito da EB dopo ogni deploy, su ogni istanza
set -u

# Le environment properties non sono nell'ambiente degli hook: si leggono con get-config
for k in DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD DB_SSL_CA \
         RDS_HOSTNAME RDS_PORT RDS_DB_NAME RDS_USERNAME RDS_PASSWORD; do
  v=$(/opt/elasticbeanstalk/bin/get-config environment -k "$k" 2>/dev/null || true)
  export "$k=$v"
done

if [ -z "$DB_HOST" ] && [ -z "$RDS_HOSTNAME" ]; then
  echo "[init_db] Nessun database configurato: salto l'inizializzazione"
  exit 0
fi

cd /var/app/current || exit 1
/usr/bin/php scripts/init-db.php || { echo "[init_db] ERRORE"; exit 1; }