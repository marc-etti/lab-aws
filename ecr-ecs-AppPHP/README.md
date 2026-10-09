# Amazon ECR & ECS con App PHP + MariaDB

## Fase 1 - Creazione di un'applicazione PHP + MariaDB e containerizzazione con Docker

1. Creare il file con le credenziali locali
    ```bash
    cp .env.example .env
    ```

2. Build e avvio
    ```bash
    docker compose up -d --build
    ```

3. Controllo stato e log
    ```bash
    docker compose ps
    docker compose logs -f web
    ```

4. Controlla che l'applicazione sia raggiungibile con:
    ```bash
    curl http://localhost:8080/health.php
    ```
5. Controlla che il database sia raggiungibile con:
    ```bash
    curl http://localhost:8080/health.php?deep=1
    ```
    poi da browser: http://localhost:8080

6. Verifica persistenza dei dati
    ```bash
    docker compose down
    docker compose up -d
    ```
    i dati restano nel volume

7. Pulizia completa (cancella anche il volume)
    ```bash
    docker compose down -v
    ```

## Fase 2 - Creazione di un repository ECR e push dell'immagine Docker

### 1. Login a aws cli
```bash
aws login
```

### 2. Variabili d'ambiente
```bash
export AWS_REGION=eu-central-1
export AWS_ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export ECR_URI=${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com
export REPO_NAME=php-todo
export IMAGE_TAG=1.0
```

### 3. Creazione del repository ECR
```bash
aws ecr create-repository \
    --repository-name $REPO_NAME \
    --region $AWS_REGION \
    --image-scanning-configuration scanOnPush=true \
    --image-tag-mutability IMMUTABLE \
    --encryption-configuration encryptionType=AES256
```
- `scanOnPush=true`: scansione delle vulnerabilità a ogni push
- `IMMUTABLE`: un tag già pubblicato non può essere sovrascritto (quindi 1.0 resta sempre la stessa immagine; niente tag latest riutilizzato)
- `AES256`: cifrazione a riposo gestita da AWS (è già il default)

Verifica:
```bash
aws ecr describe-repositories --repository-names $REPO_NAME --region $AWS_REGION
```

### 4. Lifecycle policy (pulizia automatica):
    
Creiamo il file `lifecycle-policy.json`:
```bash
cat > lifecycle-policy.json <<'EOF'
{
"rules": [
    {
    "rulePriority": 1,
    "description": "Elimina immagini senza tag dopo 1 giorno",
    "selection": {
        "tagStatus": "untagged",
        "countType": "sinceImagePushed",
        "countUnit": "days",
        "countNumber": 1
    },
    "action": { "type": "expire" }
    },
    {
    "rulePriority": 2,
    "description": "Mantieni solo le ultime 10 immagini",
    "selection": {
        "tagStatus": "any",
        "countType": "imageCountMoreThan",
        "countNumber": 10
    },
    "action": { "type": "expire" }
    }
    ]
}
EOF
```
La **lifecycle policy**  permette di eliminare automaticamente le immagini non taggate dopo 1 giorno e di mantenere solo le ultime 10 immagini per ogni tag.

### 5. Applicazione della lifecycle policy
```bash
aws ecr put-lifecycle-policy \
    --repository-name $REPO_NAME \
    --region $AWS_REGION \
    --lifecycle-policy-text file://lifecycle-policy.json
```

### 6. Autenticazione di Docker verso ECR
```bash
aws ecr get-login-password --region $AWS_REGION \
| docker login --username AWS --password-stdin $ECR_URI
```

### 7. Build, tag e push

```bash
docker build -t php-todo:1.0 .  # Già fatto in fase 1, ma lo ripetiamo per sicurezza
```
```bash
docker tag php-todo:1.0 ${ECR_URI}/${REPO_NAME}:${IMAGE_TAG}
```
```bash
docker push ${ECR_URI}/${REPO_NAME}:${IMAGE_TAG}
```

### 8. Verifica del contenuto del repository ECR

Elenco immagini:
```bash
aws ecr describe-images \
    --repository-name $REPO_NAME \
    --region $AWS_REGION \
    --query 'imageDetails[].{Tag:imageTags[0],Size:imageSizeInBytes,Pushed:imagePushedAt,Digest:imageDigest}' \
    --output table
```

Controllo stato scansione delle vulnerabilità (puo richiedere qualche minuto dopo il push):
```bash
aws ecr describe-image-scan-findings \
    --repository-name $REPO_NAME \
    --image-id imageTag=$IMAGE_TAG \
    --region $AWS_REGION \
    --query 'imageScanFindings.findingSeverityCounts'
```
Con questo comando si ottiene il numero di vulnerabilità per livello di gravità (Critical, High, Medium, Low, Informational, Undefined).
In questo caso avremo molte vulnerabilità perché l'immagine di base `php:8.3-apache` contiene pacchetti non aggiornati.

### 9. Test del pull (opzionale)
```bash
docker rmi ${ECR_URI}/${REPO_NAME}:${IMAGE_TAG}
```
```bash
docker pull ${ECR_URI}/${REPO_NAME}:${IMAGE_TAG}
```

## Fase 3: dal repository ECR al servizio in esecuzione con ECS Fargate
Una volta che l'immagine è stata pubblicata su ECR, possiamo creare un cluster ECS Fargate e un servizio che esegue l'applicazione PHP.

### 1. Variabili e rete (VPC di default)
```bash
export AWS_DEFAULT_REGION=$AWS_REGION      # così non serve --region ovunque
export CLUSTER=todo-cluster
export SERVICE=todo-service
```
```bash
export VPC_ID=$(aws ec2 describe-vpcs --filters Name=is-default,Values=true \
--query 'Vpcs[0].VpcId' --output text)
```
In questo esempio useremo la VPC di default, che ha già 3 subnet pubbliche in 3 availability zone diverse.
```bash
SUBNETS=$(aws ec2 describe-subnets \
--filters Name=vpc-id,Values=$VPC_ID Name=default-for-az,Values=true \
--query 'Subnets[].SubnetId' --output text)
read -r SUBNET_1 SUBNET_2 SUBNET_3 <<< "$SUBNETS"

echo $VPC_ID 
echo $SUBNET_1 
echo $SUBNET_2
echo $SUBNET_3
```

### 2. Security group (ALB, task, DB)
Creiamo 3 security group:
- `todo-alb-sg`: per l'ALB, permette traffico in ingresso sulla porta 80 da internet
- `todo-task-sg`: per i task Fargate, permette traffico in ingresso
  sulla porta 80 solo dall'ALB
- `todo-db-sg`: per il database RDS, permette traffico in ingresso
  sulla porta 3306 solo dai task Fargate
```bash
ALB_SG=$(aws ec2 create-security-group --group-name todo-alb-sg \
--description "ALB todo" --vpc-id $VPC_ID --query GroupId --output text)
aws ec2 authorize-security-group-ingress --group-id $ALB_SG \
--protocol tcp --port 80 --cidr 0.0.0.0/0

TASK_SG=$(aws ec2 create-security-group --group-name todo-task-sg \
--description "Task Fargate todo" --vpc-id $VPC_ID --query GroupId --output text)
aws ec2 authorize-security-group-ingress --group-id $TASK_SG \
--protocol tcp --port 80 --source-group $ALB_SG

DB_SG=$(aws ec2 create-security-group --group-name todo-db-sg \
--description "RDS todo" --vpc-id $VPC_ID --query GroupId --output text)
aws ec2 authorize-security-group-ingress --group-id $DB_SG \
--protocol tcp --port 3306 --source-group $TASK_SG

echo $ALB_SG
echo $TASK_SG
echo $DB_SG
```

### 3. Segreto e database RDS MariaDB
Creiamo un segreto per la password del database e lo salviamo in `AWS Secrets Manager`.
```bash
export DB_PASSWORD=$(openssl rand -hex 16)

export SECRET_ARN=$(aws secretsmanager create-secret \
    --name todo/db-password --secret-string "$DB_PASSWORD" \
    --query ARN --output text)

echo $DB_PASSWORD
echo $SECRET_ARN
```
Creazione del database RDS MariaDB in subnet private (non accessibile da internet)
```bash
aws rds create-db-subnet-group --db-subnet-group-name todo-subnets \
    --db-subnet-group-description "Subnet todo" \
    --subnet-ids $SUBNET_1 $SUBNET_2
```
Creazione dell'istanza RDS MariaDB (db.t4g.micro è la più piccola, 20 GB di storage)
```bash
aws rds create-db-instance \
    --db-instance-identifier todo-db \
    --engine mariadb \
    --db-instance-class db.t4g.micro \
    --allocated-storage 20 \
    --master-username todo \
    --master-user-password "$DB_PASSWORD" \
    --db-name todoapp \
    --db-subnet-group-name todo-subnets \
    --vpc-security-group-ids $DB_SG \
    --no-publicly-accessible \
    --no-multi-az \
    --backup-retention-period 0
```

### 4. Ruolo di esecuzione IAM

Definizione del ruolo IAM per ECS Fargate, che permette di fare pull dell'immagine da ECR, scrivere i log su CloudWatch e leggere il segreto della password del database.
```bash
cat > trust.json <<'EOF'
{
"Version": "2012-10-17",
"Statement": [{
    "Effect": "Allow",
    "Principal": { "Service": "ecs-tasks.amazonaws.com" },
    "Action": "sts:AssumeRole"
    }]
}
EOF
```
Creazione del ruolo IAM
```bash
aws iam create-role --role-name todoTaskExecutionRole \
--assume-role-policy-document file://trust.json
```
Attach del policy al ruolo
```bash
aws iam attach-role-policy --role-name todoTaskExecutionRole \
--policy-arn arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy
```

Definiamo una policy inline per permettere al ruolo di leggere il segreto della password del database.
```bash
cat > secret-policy.json <<EOF
{
"Version": "2012-10-17",
"Statement": [{
    "Effect": "Allow",
    "Action": "secretsmanager:GetSecretValue",
    "Resource": "$SECRET_ARN"
    }]
}
EOF
```
Applichiamo la policy al ruolo
```bash
aws iam put-role-policy --role-name todoTaskExecutionRole \
    --policy-name read-db-secret --policy-document file://secret-policy.json
```
Esportiamo l'ARN del ruolo per usarlo nella task definition
```bash
export EXEC_ROLE_ARN=arn:aws:iam::${AWS_ACCOUNT_ID}:role/todoTaskExecutionRole
echo $EXEC_ROLE_ARN
```
### 5. Log group e cluster
```bash
aws logs create-log-group --log-group-name /ecs/php-todo
aws logs put-retention-policy --log-group-name /ecs/php-todo --retention-in-days 7
```
```bash
aws ecs create-cluster --cluster-name $CLUSTER
```
### 6. Application Load Balancer
Creazione dell'Application Load Balancer con parametri:
- `internet-facing`: accessibile da internet
- `--subnets $SUBNET_1 $SUBNET_2`: almeno 2 subnet in availability zone diverse per avere alta disponibilità
- `--security-groups $ALB_SG`: il security group creato prima per l'ALB

Creazione del target group per i task Fargate con parametri:
- `--protocol HTTP --port 80`: il protocollo e la porta su cui i task ascoltano
- `--target-type ip`: i task Fargate hanno un IP privato, quindi il target type è `ip`
- `--health-check-path /health.php`: path per il controllo dello stato dei task
- `--health-check-interval-seconds 15`: intervallo tra i controlli di salute
- `--healthy-threshold-count 2`: numero di controlli di salute consecutivi per considerare il target sano

```bash
export ALB_ARN=$(aws elbv2 create-load-balancer --name todo-alb \
    --type application --scheme internet-facing \
    --subnets $SUBNET_1 $SUBNET_2 --security-groups $ALB_SG \
    --query 'LoadBalancers[0].LoadBalancerArn' --output text)

export TG_ARN=$(aws elbv2 create-target-group --name todo-tg \
    --protocol HTTP --port 80 --target-type ip --vpc-id $VPC_ID \
    --health-check-path /health.php \
    --health-check-interval-seconds 15 \
    --healthy-threshold-count 2 \
    --query 'TargetGroups[0].TargetGroupArn' --output text)
```

Applichiamo le seguenti impostazioni al target group:
- `stickiness.enabled=true`: abilita la stickiness ovvero la possibilità di mantenere lo stesso target per le richieste dello stesso client
- `stickiness.type=lb_cookie`: tipo di stickiness basato su cookie del load balancer
- `stickiness.lb_cookie.duration_seconds=3600`: durata della stickiness in secondi (1 ora)
- `deregistration_delay.timeout_seconds=30`: tempo di attesa prima di deregistrare un target dal target group (30 secondi)
```bash
aws elbv2 modify-target-group-attributes --target-group-arn $TG_ARN --attributes \
    Key=stickiness.enabled,Value=true \
    Key=stickiness.type,Value=lb_cookie \
    Key=stickiness.lb_cookie.duration_seconds,Value=3600 \
    Key=deregistration_delay.timeout_seconds,Value=30
```
Creiamo il listener per l'ALB sulla porta 80, che inoltra le richieste al target group creato prima.
```bash
aws elbv2 create-listener --load-balancer-arn $ALB_ARN \
    --protocol HTTP --port 80 \
    --default-actions Type=forward,TargetGroupArn=$TG_ARN
```
Controlliamo il DNS dell'ALB per poterlo usare nei test.
```bash
export ALB_DNS=$(aws elbv2 describe-load-balancers --load-balancer-arns $ALB_ARN \
    --query 'LoadBalancers[0].DNSName' --output text)
echo $ALB_DNS
```

### 7. Attesa di RDS e creazione delle tabelle
Aspettiamo che l'istanza RDS sia disponibile prima di eseguire lo script di inizializzazione del database.
```bash
aws rds wait db-instance-available --db-instance-identifier todo-db
```
Controlliamo l'endpoint del database.
```bash
export DB_ENDPOINT=$(aws rds describe-db-instances --db-instance-identifier todo-db \
    --query 'DBInstances[0].Endpoint.Address' --output text)
echo $DB_ENDPOINT
```
Creiamo un task ECS Fargate temporaneo per eseguire lo script di inizializzazione del database. La query `base64 < db/init.sql | tr -d '\n'` converte il contenuto del file `db/init.sql` in base64 e rimuove i caratteri di nuova linea, così possiamo passarlo come variabile d'ambiente al container.
```bash
export INIT_SQL_B64=$(base64 < db/init.sql | tr -d '\n')

cat > taskdef-init.json <<EOF
{
"family": "todo-dbinit",
"networkMode": "awsvpc",
"requiresCompatibilities": ["FARGATE"],
"cpu": "256",
"memory": "512",
"executionRoleArn": "$EXEC_ROLE_ARN",
"containerDefinitions": [{
    "name": "dbinit",
    "image": "mariadb:11",
    "essential": true,
    "entryPoint": ["sh", "-c"],
    "command": ["echo \$INIT_SQL_B64 | base64 -d | mariadb --ssl --skip-ssl-verify-server-cert -h \$DB_HOST -u \$DB_USER -p\$DB_PASSWORD \$DB_NAME"],
    "environment": [
    {"name": "DB_HOST", "value": "$DB_ENDPOINT"},
    {"name": "DB_USER", "value": "todo"},
    {"name": "DB_NAME", "value": "todoapp"},
    {"name": "INIT_SQL_B64", "value": "$INIT_SQL_B64"}
    ],
    "secrets": [{"name": "DB_PASSWORD", "valueFrom": "$SECRET_ARN"}],
    "logConfiguration": {
    "logDriver": "awslogs",
    "options": {
        "awslogs-group": "/ecs/php-todo",
        "awslogs-region": "$AWS_REGION",
        "awslogs-stream-prefix": "dbinit"
    }
    }
    }]
}
EOF
```
Registriamo la task definition e avviamo il task di inizializzazione del database. Poi aspettiamo che il task termini e controlliamo il codice di uscita (0 = successo).
```bash
aws ecs register-task-definition --cli-input-json file://taskdef-init.json

INIT_TASK=$(aws ecs run-task --cluster $CLUSTER --task-definition todo-dbinit \
--launch-type FARGATE \
--network-configuration "awsvpcConfiguration={subnets=[$SUBNET_1],securityGroups=[$TASK_SG],assignPublicIp=ENABLED}" \
--query 'tasks[0].taskArn' --output text)

aws ecs wait tasks-stopped --cluster $CLUSTER --tasks $INIT_TASK

# Deve stampare 0
aws ecs describe-tasks --cluster $CLUSTER --tasks $INIT_TASK \
--query 'tasks[0].containers[0].exitCode' --output text
```
### 8. Task definition dell'applicazione
```bash
cat > taskdef.json <<EOF
{
    "family": "php-todo",
    "networkMode": "awsvpc",
    "requiresCompatibilities": ["FARGATE"],
    "cpu": "256",
    "memory": "512",
    "runtimePlatform": { "cpuArchitecture": "X86_64", "operatingSystemFamily": "LINUX" },
    "executionRoleArn": "$EXEC_ROLE_ARN",
    "containerDefinitions": [{
        "name": "web",
        "image": "${ECR_URI}/${REPO_NAME}:${IMAGE_TAG}",
        "essential": true,
        "portMappings": [{ "containerPort": 80, "protocol": "tcp" }],
        "environment": [
            {"name": "DB_HOST", "value": "$DB_ENDPOINT"},
            {"name": "DB_PORT", "value": "3306"},
            {"name": "DB_NAME", "value": "todoapp"},
            {"name": "DB_USER", "value": "todo"},
            {"name": "DB_SSL_CA", "value": "/etc/ssl/certs/rds-global-bundle.pem"}
        ],
        "secrets": [{"name": "DB_PASSWORD", "valueFrom": "$SECRET_ARN"}],
        "healthCheck": {
        "command": ["CMD-SHELL", "curl -fs http://localhost/health.php || exit 1"],
        "interval": 30, "timeout": 5, "retries": 3, "startPeriod": 15
        },
        "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
            "awslogs-group": "/ecs/php-todo",
            "awslogs-region": "$AWS_REGION",
            "awslogs-stream-prefix": "web"
        }
        }
    }]
}
EOF
```
```bash
aws ecs register-task-definition --cli-input-json file://taskdef.json
```
### 9. Creazione del service
Creiamo il service ECS Fargate con 2 task, collegato all'ALB e al target group creati prima. Il service ha un **circuit breaker** abilitato, che annulla automaticamente un deploy che non riesce a diventare sano.
```bash
aws ecs create-service \
    --cluster $CLUSTER \
    --service-name $SERVICE \
    --task-definition php-todo \
    --desired-count 2 \
    --launch-type FARGATE \
    --network-configuration "awsvpcConfiguration={subnets=[$SUBNET_1,$SUBNET_2],securityGroups=[$TASK_SG],assignPublicIp=ENABLED}" \
    --load-balancers targetGroupArn=$TG_ARN,containerName=web,containerPort=80 \
    --health-check-grace-period-seconds 30 \
    --deployment-configuration "deploymentCircuitBreaker={enable=true,rollback=true},minimumHealthyPercent=100,maximumPercent=200"

aws ecs wait services-stable --cluster $CLUSTER --services $SERVICE
```
Il circuit breaker con rollback annulla automaticamente un deploy che non riesce a diventare sano.

### 10. Verifica

Stato del service e dei task
```bash
aws ecs describe-services --cluster $CLUSTER --services $SERVICE \
--query 'services[0].{Desired:desiredCount,Running:runningCount,Events:events[0:3].message}'
```

Salute dei target nell'ALB
```bash
aws elbv2 describe-target-health --target-group-arn $TG_ARN \
--query 'TargetHealthDescriptions[].{IP:Target.Id,State:TargetHealth.State}' --output table
```

Test applicazione
```bash
curl http://$ALB_DNS/health.php
curl "http://$ALB_DNS/health.php?deep=1"
echo "Apri nel browser: http://$ALB_DNS"
```

Log in tempo reale
```bash
aws logs tail /ecs/php-todo --follow
```

Prova di resilienza: ferma un task e osserva ECS che ne avvia uno nuovo.
```bash
TASK=$(aws ecs list-tasks --cluster $CLUSTER --service-name $SERVICE \
    --query 'taskArns[0]' --output text)

aws ecs stop-task --cluster $CLUSTER --task $TASK --reason "Demo resilienza"

aws ecs describe-services --cluster $CLUSTER --services $SERVICE \
    --query 'services[0].events[0:5].message'
```

### 11. Aggiornamento alla versione `1.1`

Dopo la modifica del codice, build e push
```bash
export IMAGE_TAG=1.1
docker build --platform linux/amd64 -t ${ECR_URI}/${REPO_NAME}:${IMAGE_TAG} .
docker push ${ECR_URI}/${REPO_NAME}:${IMAGE_TAG}
```
Rigenera `taskdef.json` (stesso comando "cat > taskdef.json <<EOF" del punto 8,
ora con IMAGE_TAG=1.1), poi:
```bash
aws ecs register-task-definition --cli-input-json file://taskdef.json

aws ecs update-service --cluster $CLUSTER --service $SERVICE --task-definition php-todo
aws ecs wait services-stable --cluster $CLUSTER --services $SERVICE
```
### 12. Pulizia
    
Service e cluster:
```bash
aws ecs update-service --cluster $CLUSTER --service $SERVICE --desired-count 0
aws ecs delete-service --cluster $CLUSTER --service $SERVICE --force
aws ecs wait services-inactive --cluster $CLUSTER --services $SERVICE
aws ecs delete-cluster --cluster $CLUSTER
```
ALB, listener e target group
```bash
aws elbv2 delete-load-balancer --load-balancer-arn $ALB_ARN
aws elbv2 wait load-balancers-deleted --load-balancer-arns $ALB_ARN
aws elbv2 delete-target-group --target-group-arn $TG_ARN
```
RDS
```bash
aws rds delete-db-instance --db-instance-identifier todo-db \
    --skip-final-snapshot --delete-automated-backups
aws rds wait db-instance-deleted --db-instance-identifier todo-db
aws rds delete-db-subnet-group --db-subnet-group-name todo-subnets
```
Security group (prima DB, poi task, poi ALB)
```bash
aws ec2 delete-security-group --group-id $DB_SG
aws ec2 delete-security-group --group-id $TASK_SG
aws ec2 delete-security-group --group-id $ALB_SG
```
Segreto, log, IAM, ECR
```bash
aws secretsmanager delete-secret --secret-id todo/db-password --force-delete-without-recovery
aws logs delete-log-group --log-group-name /ecs/php-todo
aws iam delete-role-policy --role-name todoTaskExecutionRole --policy-name read-db-secret
aws iam detach-role-policy --role-name todoTaskExecutionRole \
    --policy-arn arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy
aws iam delete-role --role-name todoTaskExecutionRole
aws ecr delete-repository --repository-name $REPO_NAME --force
```