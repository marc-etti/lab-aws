# Elastic Beanstalk - App PHP + MariaDB

## 1. Nuova struttura del progetto
1. Albero delle cartelle:
    ```
    eb-todo/
    ├── .ebextensions/
    │   ├── 01-php.config
    │   └── 02-logs.config
    ├── .platform/
    │   └── hooks/postdeploy/10_init_db.sh
    ├── .ebignore
    ├── db/init.sql
    ├── public/          (index, login, register, logout, health .php)
    ├── scripts/init-db.php
    └── src/             (config.php, layout.php)
    ```

2. File cambiati/aggiunti:
    - src/config.php
    - scripts/init-db.php
    - .platform/hooks/postdeploy/10_init_db.sh
    - .ebextensions/01-php.config
    - .ebextensions/02-logs.config
    - .ebignore

## 2. Configurazione di Elastic Beanstalk

### 1. Ruoli IAM
```bash
# Service role
cat > eb-service-trust.json <<'EOF'
{"Version":"2012-10-17","Statement":[{"Effect":"Allow",
"Principal":{"Service":"elasticbeanstalk.amazonaws.com"},"Action":"sts:AssumeRole"}]}
EOF
```
```bash
aws iam create-role --role-name aws-elasticbeanstalk-service-role \
    --assume-role-policy-document file://eb-service-trust.json
aws iam attach-role-policy --role-name aws-elasticbeanstalk-service-role \
    --policy-arn arn:aws:iam::aws:policy/service-role/AWSElasticBeanstalkEnhancedHealth
aws iam attach-role-policy --role-name aws-elasticbeanstalk-service-role \
    --policy-arn arn:aws:iam::aws:policy/AWSElasticBeanstalkManagedUpdatesCustomerRolePolicy
```
Instance profile per le EC2
```bash
cat > eb-ec2-trust.json <<'EOF'
{"Version":"2012-10-17","Statement":[{"Effect":"Allow",
"Principal":{"Service":"ec2.amazonaws.com"},"Action":"sts:AssumeRole"}]}
EOF
```
```bash
aws iam create-role --role-name aws-elasticbeanstalk-ec2-role \
    --assume-role-policy-document file://eb-ec2-trust.json
aws iam attach-role-policy --role-name aws-elasticbeanstalk-ec2-role \
    --policy-arn arn:aws:iam::aws:policy/AWSElasticBeanstalkWebTier
aws iam attach-role-policy --role-name aws-elasticbeanstalk-ec2-role \
    --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore
aws iam create-instance-profile --instance-profile-name aws-elasticbeanstalk-ec2-role
aws iam add-role-to-instance-profile \
    --instance-profile-name aws-elasticbeanstalk-ec2-role \
    --role-name aws-elasticbeanstalk-ec2-role
```
### 2. Rete e RDS MariaDB (disaccoppiato)
```bash
export AWS_REGION=eu-central-1
export AWS_DEFAULT_REGION=$AWS_REGION
export APP_NAME=todo-app
export ENV_NAME=todo-env
```
```bash
export VPC_ID=$(aws ec2 describe-vpcs --filters Name=is-default,Values=true \
    --query 'Vpcs[0].VpcId' --output text)

read -r SUBNET_1 SUBNET_2 _ <<< "$(aws ec2 describe-subnets \
    --filters Name=vpc-id,Values=$VPC_ID Name=default-for-az,Values=true \
    --query 'Subnets[].SubnetId' --output text)"
```
Security group del database (nessuna regola in ingresso, per ora)
```bash
export DB_SG=$(aws ec2 create-security-group --group-name todo-eb-db-sg \
    --description "RDS per EB todo" --vpc-id $VPC_ID --query GroupId --output text)

export DB_PASSWORD=$(openssl rand -hex 16)
echo "$DB_PASSWORD" > .db-pass        # in .ebignore: serve se si chiude il terminale
```
```bash
aws rds create-db-subnet-group --db-subnet-group-name todo-eb-subnets \
    --db-subnet-group-description "Subnet todo EB" --subnet-ids $SUBNET_1 $SUBNET_2

aws rds create-db-instance \
    --db-instance-identifier todo-eb-db \
    --engine mariadb \
    --db-instance-class db.t4g.micro \
    --allocated-storage 20 \
    --master-username todo \
    --master-user-password "$DB_PASSWORD" \
    --db-name todoapp \
    --db-subnet-group-name todo-eb-subnets \
    --vpc-security-group-ids $DB_SG \
    --no-publicly-accessible --no-multi-az \
    --backup-retention-period 0
```
### 3. Inizializzazione EB e primo ambiente

Verifica prima la piattaforma PHP disponibile (cambia nel tempo):
```bash
aws elasticbeanstalk list-available-solution-stacks \
    --query "SolutionStacks[?contains(@, 'PHP') && contains(@, 'Amazon Linux 2023')]" \
    --output text | tr '\t' '\n'
```

Poi, dalla cartella eb-todo/:
```bash
cd eb-todo
```
```bash
export EB_PLATFORM="64bit Amazon Linux 2023 v4.13.9 running PHP 8.3"   # adatta all'elenco sopra
```
```bash
eb init $APP_NAME --platform "$EB_PLATFORM" --region $AWS_REGION
```
- Se chiede di configurare SSH rispondi "n": si usa SSM Session Manager

Creazione dell'ambiente EB (disaccoppiato dal DB, con hook postdeploy per inizializzare lo schema):
```bash
eb create $ENV_NAME --single -i t3.micro \
    --instance_profile aws-elasticbeanstalk-ec2-role \
    --service-role aws-elasticbeanstalk-service-role
```
- `--single` crea una sola istanza con IP pubblico, senza load balancer (più economico). La creazione richiede 5-8 minuti. Intanto mostra in console lo stack CloudFormation awseb-... con le risorse create.
```bash
eb status
eb health
```
```bash
export EB_URL=$(aws elasticbeanstalk describe-environments --environment-names $ENV_NAME \
    --query 'Environments[0].CNAME' --output text)
echo "URL ambiente: http://$EB_URL"
```
```bash
curl http://$EB_URL/health.php
```
A questo punto health.php risponde, ma login.php dà errore perché il database non è ancora collegato. L'hook ha stampato "Nessun database configurato".

### 4. Collegare l'ambiente al database
```bash
aws rds wait db-instance-available --db-instance-identifier todo-eb-db
export DB_ENDPOINT=$(aws rds describe-db-instances --db-instance-identifier todo-eb-db \
    --query 'DBInstances[0].Endpoint.Address' --output text)
```
Security group delle istanze EB
```bash
export INSTANCE_ID=$(aws elasticbeanstalk describe-environment-resources \
    --environment-name $ENV_NAME --query 'EnvironmentResources.Instances[0].Id' --output text)
export INSTANCE_SG=$(aws ec2 describe-instances --instance-ids $INSTANCE_ID \
    --query 'Reservations[0].Instances[0].SecurityGroups[0].GroupId' --output text)
```
Il DB accetta 3306 solo dalle istanze EB
```bash
aws ec2 authorize-security-group-ingress --group-id $DB_SG \
    --protocol tcp --port 3306 --source-group $INSTANCE_SG
```

Environment properties
```bash
eb setenv DB_HOST=$DB_ENDPOINT DB_PORT=3306 DB_NAME=todoapp DB_USER=todo DB_PASSWORD=$DB_PASSWORD
```
Imponiamo anche il certificato CA per la connessione TLS al DB (RDS global bundle):
```bash
eb setenv DB_SSL_CA=/etc/pki/tls/certs/rds-global-bundle.pem
```

Il solo setenv non esegue gli hook di postdeploy: serve un deploy
```bash
eb deploy
```
Controlla l'esito dell'hook:
```bash
eb logs --all
```
```bash
curl "http://$EB_URL/health.php?deep=1"     # deve rispondere {"status":"ok","db":"up"}
```
A questo punto l'applicazione è pronta per essere usata. Apri il browser:
```bash
eb open
```
Registra un utente dal browser, crea qualche attività, esci e rientra.

### 5.  Accesso all'istanza e troubleshooting
```bash
aws ssm start-session --target $INSTANCE_ID     # richiede il plugin Session Manager
```
oppure, se hai configurato una key pair: `eb ssh`

```bash
# Dentro l'istanza
sudo tail -n 50 /var/log/eb-hooks.log
sudo tail -n 50 /var/log/httpd/error_log
ls /var/app/current
```
### 6.  Aggiornamento e rollback
    
Modifica un testo in `public/index.php`, (se fossimo in un repository git `git add -A && git commit -m "v1.1"`) poi deploy:
```bash
eb deploy --label v1.1
```
```bash
eb open
```
Versioni disponibili
```bash
aws elasticbeanstalk describe-application-versions --application-name $APP_NAME \
    --query 'ApplicationVersions[].VersionLabel'
```
Rollback alla versione precedente
```bash
eb deploy --version <label-precedente>  # es. v1.0
```


### 7. Pulizia
1. Ambienti
    ```bash
    eb terminate $ENV_NAME --force
    ```
    controlla che l'ambiente sia terminato:
    ```bash
    aws elasticbeanstalk describe-environments --environment-names $ENV_NAME \
        --query 'Environments[0].Status' --output text
    ```

2. Regole e security group del DB (dopo la terminazione dei SG EB)
    ```bash
    aws rds delete-db-instance --db-instance-identifier todo-eb-db \
    --skip-final-snapshot --delete-automated-backups
    aws rds wait db-instance-deleted --db-instance-identifier todo-eb-db
    aws rds delete-db-subnet-group --db-subnet-group-name todo-eb-subnets
    aws ec2 delete-security-group --group-id $DB_SG
    ```
    
3. Applicazione e versioni (i bundle ZIP stanno in S3)
    ```bash
    aws elasticbeanstalk delete-application --application-name $APP_NAME --terminate-env-by-force
    ```
    bucket `elasticbeanstalk-<regione>-<account>`: controlla e svuota se vuoi
    ```bash
    aws s3 ls | grep elasticbeanstalk
    ```

4. IAM
    Rimozione dei ruoli e delle policy create per EB (se non servono più):
    ```bash
    aws iam remove-role-from-instance-profile --instance-profile-name aws-elasticbeanstalk-ec2-role \
        --role-name aws-elasticbeanstalk-ec2-role
    aws iam delete-instance-profile --instance-profile-name aws-elasticbeanstalk-ec2-role
    for p in AWSElasticBeanstalkWebTier AmazonSSMManagedInstanceCore; do
    aws iam detach-role-policy --role-name aws-elasticbeanstalk-ec2-role \
        --policy-arn arn:aws:iam::aws:policy/$p
    done
    ```
    Rimozione del service role e delle policy:
    ```bash
    aws iam detach-role-policy \
        --role-name aws-elasticbeanstalk-ec2-role \
        --policy-arn arn:aws:iam::aws:policy/AWSElasticBeanstalkMulticontainerDocker

    aws iam detach-role-policy \
        --role-name aws-elasticbeanstalk-ec2-role \
        --policy-arn arn:aws:iam::aws:policy/AWSElasticBeanstalkWorkerTier

    aws iam delete-role --role-name aws-elasticbeanstalk-ec2-role

    aws iam detach-role-policy --role-name aws-elasticbeanstalk-service-role \
        --policy-arn arn:aws:iam::aws:policy/service-role/AWSElasticBeanstalkEnhancedHealth

    aws iam detach-role-policy --role-name aws-elasticbeanstalk-service-role \
        --policy-arn arn:aws:iam::aws:policy/AWSElasticBeanstalkManagedUpdatesCustomerRolePolicy
        
    aws iam delete-role --role-name aws-elasticbeanstalk-service-role
    ```