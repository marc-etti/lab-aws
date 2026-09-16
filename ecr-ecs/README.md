# Amazon ECR & ECS tutorial

## Prerequisiti
- Account AWS con crediti promozionali (100 USD)
- AWS CLI installata e configurata con le credenziali
- Docker installato e funzionante

## Fase 1 — Applicazione di esempio Applicazione di esempio

Creare una piccola API REST (es. Flask/Python) che risponde su / con un messaggio JSON, utile per verificare il deploy.

```py
# app.py
from flask import Flask, jsonify
import socket

app = Flask(__name__)

@app.route("/")
def home():
    return jsonify({
        "message": "Hello from ECS Fargate!",
        "hostname": socket.gethostname()
    })

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8080)
```

Creare un `Dockerfile` per l'applicazione.
```dockerfile
# Dockerfile
FROM python:3.12-slim
WORKDIR /app
COPY app.py .
RUN pip install flask
EXPOSE 8080
CMD ["python", "app.py"]
```

### Test in locale:
```bash
docker build -t lab-ecs-app .
docker run -d --rm --name lab-test -p 8080:8080 lab-ecs-app
curl http://localhost:8080
docker stop lab-test
```

## Fase 2 — Creazione del repository ECR

Da terminale, creare un repository ECR per l'applicazione.

Variabili utili:
```bash
export AWS_REGION=eu-central-1
export AWS_ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export REPO_NAME=lab-ecs-app
```
Creazione repository:
```bash
aws ecr create-repository \
  --repository-name $REPO_NAME \
  --region $AWS_REGION
```

Login al registry ECR:
```bash
aws ecr get-login-password --region $AWS_REGION | \
  docker login --username AWS --password-stdin \
  $AWS_ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com
```

Taggare l'immagine Docker locale con il tag del repository ECR:
```bash
docker tag lab-ecs-app:latest \
  $AWS_ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com/$REPO_NAME:latest
```

Push dell'immagine nel repository ECR:
```bash
docker push $AWS_ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com/$REPO_NAME:latest
```

## Fase 3 — Creazione del cluster ECS
Creare un cluster ECS Fargate:
```bash
aws ecs create-cluster --cluster-name lab-cluster
```
Su Fargate non serve gestire istanze EC2: è il modello "serverless" per i container, mentre il launch type EC2 richiede la gestione delle istanze.

## Fase 4 — Creazione del task definition
Creare un file JSON per la task definition, ad esempio `task-definition.json`:
```json
{
  "family": "lab-ecs-task",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "256",
  "memory": "512",
  "executionRoleArn": "arn:aws:iam::ACCOUNT_ID:role/ecsTaskExecutionRole",
  "containerDefinitions": [
    {
      "name": "lab-ecs-app",
      "image": "ACCOUNT_ID.dkr.ecr.REGION.amazonaws.com/lab-ecs-app:latest",
      "portMappings": [
        {
          "containerPort": 8080,
          "protocol": "tcp"
        }
      ],
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group": "/ecs/lab-ecs-task",
          "awslogs-region": "REGION",
          "awslogs-stream-prefix": "ecs"
        }
      }
    }
  ]
}
```
Sostituire `ACCOUNT_ID` e `REGION` con le variabili appropriate:
```bash
sed -i "s/ACCOUNT_ID/$AWS_ACCOUNT_ID/g; s/REGION/$AWS_REGION/g" task-definition.json
```

Creare il file `trust-policy.json` con il seguente contenuto:
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": { "Service": "ecs-tasks.amazonaws.com" },
      "Action": "sts:AssumeRole"
    }
  ]
}
```

Creare il ruolo IAM per l'esecuzione dei task ECS:
```bash
aws iam create-role \
  --role-name ecsTaskExecutionRole \
  --assume-role-policy-document file://trust-policy.json
```

Creare il log group in CloudWatch per i log dei task:
```bash
aws logs create-log-group --log-group-name /ecs/lab-ecs-task --region $AWS_REGION
```

Registrare la task definition:
```bash
aws ecs register-task-definition --cli-input-json file://task-definition.json
```

A seguire, allegare la policy gestita AmazonECSTaskExecutionRolePolicy al ruolo appena creato:
```bash
aws iam attach-role-policy \
  --role-name ecsTaskExecutionRole \
  --policy-arn arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy
```
se il comando create-role restituisce EntityAlreadyExists, va bene così, significa che il ruolo esiste già (magari da una sessione precedente) e si può passare direttamente ad attach-role-policy.

## Fase 5 — Rete e Load Balancer

Per rendere il servizio accessibile pubblicamente usaremo la VPC di default:
```bash
export VPC_ID=$(aws ec2 describe-vpcs \
    --filters "Name=is-default,Values=true" \
    --query "Vpcs[0].VpcId" --output text \
    --region $AWS_REGION)

echo $VPC_ID
```
Trovare le subnet pubbliche della VPC di default:
```bash
aws ec2 describe-subnets \
    --filters "Name=vpc-id,Values=$VPC_ID" \
    --query "Subnets[].{ID:SubnetId,AZ:AvailabilityZone,Public:MapPublicIpOnLaunch}" \
    --output table \
    --region $AWS_REGION
```
Salvare gli ID delle subnet pubbliche in due variabili:
```bash
export SUBNET_1=subnet-XXXX
export SUBNET_2=subnet-YYYY
```

Creare un Security Group che apra la porta 8080 (o 80 se si mette dietro ALB)
```bash
export SG_ALB=$(aws ec2 create-security-group \
    --group-name lab-alb-sg \
    --description "SG per ALB del laboratorio ECS" \
    --vpc-id $VPC_ID \
    --query "GroupId" --output text \
    --region $AWS_REGION)
echo $SG_ALB
```
Aggiungere regola per permettere traffico HTTP (porta 8080):
```bash
aws ec2 authorize-security-group-ingress \
    --group-id $SG_ALB \
    --protocol tcp --port 80 \
    --cidr 0.0.0.0/0 \
    --region $AWS_REGION
```
Conviene distinguere due Security Group diversi, uno per l'ALB (aperto su 80 verso internet) e uno per i task Fargate (aperto su 8080, ma solo da parte dell'ALB, non da internet)
```bash
export SG_TASK=$(aws ec2 create-security-group \
    --group-name lab-task-sg \
    --description "SG per i task ECS del laboratorio" \
    --vpc-id $VPC_ID \
    --query "GroupId" --output text \
    --region $AWS_REGION)

echo $SG_TASK
```
Aggiungere regola per permettere traffico dalla porta 8080 solo dal Security Group dell'ALB:
```bash
aws ec2 authorize-security-group-ingress \
    --group-id $SG_TASK \
    --protocol tcp --port 8080 \
    --source-group $SG_ALB \
    --region $AWS_REGION
```


Creare un Application Load Balancer con target group di tipo ip (richiesto da Fargate awsvpc)

```bash
aws elbv2 create-load-balancer \
  --name lab-alb \
  --subnets $SUBNET_1 $SUBNET_2 \
  --security-groups $SG_ALB \
  --region $AWS_REGION
```

Creare un target group per i task ECS:
```bash
aws elbv2 create-target-group \
  --name lab-tg \
  --protocol HTTP --port 8080 \
  --vpc-id $VPC_ID \
  --target-type ip \
  --region $AWS_REGION
```

Salvare gli ARN del Load Balancer e del Target Group:
```bash
export ALB_ARN=$(aws elbv2 describe-load-balancers \
  --names lab-alb --query "LoadBalancers[0].LoadBalancerArn" \
  --output text --region $AWS_REGION)
echo $ALB_ARN
```

```bash
export TG_ARN=$(aws elbv2 describe-target-groups \
  --names lab-tg --query "TargetGroups[0].TargetGroupArn" \
  --output text --region $AWS_REGION)
echo $TG_ARN
```

Per completare l'ALB serve anche un listener che colleghi la porta 80 dell'ALB al target group
```bash
aws elbv2 create-listener \
  --load-balancer-arn $ALB_ARN \
  --protocol HTTP --port 80 \
  --default-actions Type=forward,TargetGroupArn=$TG_ARN \
  --region $AWS_REGION
```

## Fase 6 — Creare il Service ECS
Creare un service ECS che esegue 2 task Fargate, collegati al Load Balancer:
```bash
aws ecs create-service \
  --cluster lab-cluster \
  --service-name lab-service \
  --task-definition lab-ecs-task \
  --desired-count 2 \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[$SUBNET_1,$SUBNET_2],securityGroups=[$SG_TASK],assignPublicIp=ENABLED}" \
  --load-balancers "targetGroupArn=$TG_ARN,containerName=lab-ecs-app,containerPort=8080"
```

## Fase 7 — Verifica
Attendere qualche minuto che i task diventino RUNNING e healthy, poi verificare che l'applicazione sia raggiungibile tramite l'ALB.

Eseguire il comando per ottenere il DNS dell'ALB e testare l'applicazione:
```bash
export ALB_DNS=$(aws elbv2 describe-load-balancers \
  --names lab-alb \
  --query "LoadBalancers[0].DNSName" \
  --output text \
  --region $AWS_REGION)
echo $ALB_DNS
```
Eseguire il comando curl per testare l'applicazione:
```bash
curl http://$ALB_DNS
```
Osservare i log in CloudWatch (/ecs/lab-ecs-task) e lo stato dei task nella console ECS.

## Fase 8 — Scaling, Rolling Deployment e Self-Healing
### Scaling: da 2 a 4 task
- Aumentare il numero di task desiderati a 4:
    ```bash
    aws ecs update-service \
    --cluster lab-cluster \
    --service lab-service \
    --desired-count 4 \
    --region $AWS_REGION
    ```

- Osservazione del tempo di avvio

    Per misurare quanto ci mette ECS a portare i nuovi task in stato RUNNING e healthy, lancia un piccolo polling in loop:
    ```bash
    watch -n 5 'aws ecs describe-services \
    --cluster lab-cluster \
    --services lab-service \
    --region '"$AWS_REGION"' \
    --query "services[0].{running:runningCount,desired:desiredCount,pending:pendingCount}"'
    ```
- In parallelo, verifica quando i nuovi target diventano healthy nel target group:
    ```bash
    watch -n 5 'aws elbv2 describe-target-health \
    --target-group-arn '"$TG_ARN"' \
    --region '"$AWS_REGION"' \
    --query "TargetHealthDescriptions[].{IP:Target.Id,State:TargetHealth.State}"'
    ```
- Verifica del load balancing su 4 task
    ```bash
    for i in {1..10}; do curl -s http://$ALB_DNS | python3 -m json.tool; sleep 1; done
    ```
    Verranno restituiti 4 hostname diversi, corrispondenti ai 4 task in esecuzione.

### Rolling deployment senza downtime
1. Modificare l'applicazione
    ```py
    # app.py — modifica il messaggio
    return jsonify({
        "message": "Hello from ECS Fargate! (versione 2)",
        "hostname": socket.gethostname()
    })
    ```
2. Nuovo build e push su ECR
    ```bash
    docker build -t lab-ecs-app .
    ```
    ```bash
    docker tag lab-ecs-app:latest \
    $AWS_ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com/lab-ecs-app:latest
    ```
    ```bash
    docker push $AWS_ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com/lab-ecs-app:latest
    ```
    Nota: il tag dell'immagine nella task definition è :latest, ma ECS non rileva automaticamente che l'immagine dietro quel tag è cambiata, poiché un service ECS non fa polling sul registry. Serve forzare esplicitamente un nuovo deployment, ed è proprio questo il motivo per cui esiste il flag --force-new-deployment.
3. Avviare il rolling deployment

    ```bash
    aws ecs update-service \
    --cluster lab-cluster \
    --service lab-service \
    --force-new-deployment \
    --region $AWS_REGION
    ```
4. osservare il rollout senza interruzioni

    Mentre il deployment è in corso, lancia in un terminale separato un loop continuo di richieste per dimostrare che il servizio resta sempre disponibile:
    ```bash
    while true; do
    curl -s -o /dev/null -w "%{http_code} - %{time_total}s\n" http://$ALB_DNS
    sleep 0.5
    done
    ```
    Ci si aspetta di vedere sempre 200, senza mai un errore, anche mentre i vecchi task vengono sostituiti da quelli nuovi.

    In un altro terminale, segui l'evoluzione del deployment:
    ```bash
    aws ecs describe-services \
        --cluster lab-cluster \
        --services lab-service \
        --region $AWS_REGION \
        --query "services[0].deployments"
    ```
    Si vedranno due deployment attivi contemporaneamente per qualche decina di secondi: quello PRIMARY (nuovo) che sale gradualmente in runningCount, e quello vecchio che scende fino a 0 e sparisce. Questo è il comportamento di default della strategia rolling update di ECS, regolata dai parametri minimumHealthyPercent (default 100%) e maximumPercent (default 200%): ECS avvia prima i task nuovi e solo dopo che sono healthy rimuove quelli vecchi, garantendo che la capacità non scenda mai sotto il 100% di quella desiderata.
5. Verificare che la nuova versione sia live
    ```bash
    curl http://$ALB_DNS
    ```
    Deve comparire il nuovo messaggio "versione 2"

### Self-healing
1. Individuare l'ID di un task in esecuzione
    ```bash
    aws ecs list-tasks \
    --cluster lab-cluster \
    --service-name lab-service \
    --region $AWS_REGION
    ```
    Copia uno degli ARN restituiti:
    ```bash
    export TASK_ARN=<uno-degli-arn-ottenuti>
    ```
2. Terminarlo manualmente
    ```bash
    aws ecs stop-task \
    --cluster lab-cluster \
    --task $TASK_ARN \
    --reason "Test di self-healing per il laboratorio" \
    --region $AWS_REGION
    ```
3. Osservare la reazione di ECS
    ```bash
    watch -n 3 'aws ecs describe-services \
    --cluster lab-cluster \
    --services lab-service \
    --region '"$AWS_REGION"' \
    --query "services[0].{running:runningCount,desired:desiredCount,pending:pendingCount,events:events[0:3]}"'
    ```
Ci si aspetta di vedere per qualche secondo running: 3 (il task appena fermato), seguito quasi immediatamente da un nuovo task in pending e poi di nuovo running: 4. Negli events comparirà un messaggio esplicito, tipo "has started 1 tasks".

## Fase 9 — Pulizia

0. Ripristinare le variabili di ambiente nel caso il terminale sia stato chiuso:
    ```bash
    export AWS_REGION=eu-central-1
    export AWS_ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
    export VPC_ID=$(aws ec2 describe-vpcs --filters "Name=is-default,Values=true" \
    --query "Vpcs[0].VpcId" --output text --region $AWS_REGION)
    export ALB_ARN=$(aws elbv2 describe-load-balancers --names lab-alb \
    --query "LoadBalancers[0].LoadBalancerArn" --output text --region $AWS_REGION)
    export TG_ARN=$(aws elbv2 describe-target-groups --names lab-tg \
    --query "TargetGroups[0].TargetGroupArn" --output text --region $AWS_REGION)
    export SG_ALB=$(aws ec2 describe-security-groups \
    --filters "Name=vpc-id,Values=$VPC_ID" "Name=group-name,Values=lab-alb-sg" \
    --query "SecurityGroups[0].GroupId" --output text --region $AWS_REGION)
    export SG_TASK=$(aws ec2 describe-security-groups \
    --filters "Name=vpc-id,Values=$VPC_ID" "Name=group-name,Values=lab-task-sg" \
    --query "SecurityGroups[0].GroupId" --output text --region $AWS_REGION)
    ```
1. Service ECS e attesa dello stop dei task
    ```
    aws ecs delete-service --cluster lab-cluster --service lab-service --force --region $AWS_REGION
    aws ecs wait services-inactive --cluster lab-cluster --services lab-service --region $AWS_REGION
    ```
2. ALB (il listener viene eliminato insieme all'ALB), poi target group
    ```
    aws elbv2 delete-load-balancer --load-balancer-arn $ALB_ARN --region $AWS_REGION
    aws elbv2 wait load-balancers-deleted --load-balancer-arns $ALB_ARN --region $AWS_REGION
    ```
    ```
    aws elbv2 delete-target-group --target-group-arn $TG_ARN --region $AWS_REGION
    ```
3. Cluster e tutte le revisioni della task definition
    ```
    aws ecs delete-cluster --cluster lab-cluster --region $AWS_REGION
    ```
    ```
    for TD in $(aws ecs list-task-definitions --family-prefix lab-ecs-task \
    --query "taskDefinitionArns[]" --output text --region $AWS_REGION); do
    aws ecs deregister-task-definition --task-definition $TD --region $AWS_REGION > /dev/null
    done
    ```
4. Security group: prima quello dei task, poi quello dell'ALB
    ```
    aws ec2 delete-security-group --group-id $SG_TASK --region $AWS_REGION
    aws ec2 delete-security-group --group-id $SG_ALB --region $AWS_REGION
    ```

5. Repository ECR e log group
    ```
    aws ecr delete-repository --repository-name lab-ecs-app --force --region $AWS_REGION
    aws logs delete-log-group --log-group-name /ecs/lab-ecs-task --region $AWS_REGION
    ```

6. Ruolo IAM (solo se creato in questo laboratorio)
    ```
    aws iam detach-role-policy --role-name ecsTaskExecutionRole \
      --policy-arn arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy
    aws iam delete-role --role-name ecsTaskExecutionRole
    ```

7. Pulizia locale
    ```
    docker rmi lab-ecs-app:latest $AWS_ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com/lab-ecs-app:latest
    docker logout $AWS_ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com
    ```



