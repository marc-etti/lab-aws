# Laboratorio AWS LAMBDA

### Struttura del progetto
```
lambda-lab/
├── pom.xml
└── src/main/java/it/example/lab/
    ├── ImportiHandler.java
    ├── RichiestaImporti.java
    ├── RisultatoImporti.java
    └── ReportSchedulatoHandler.java
```

## 1. Build del progetto
```bash
cd lambda-lab
```
```bash
mvn clean package
```
Output: `target/lambda-lab-1.0.0.jar`

## 2. Deploy con AWS CLI
```bash
export AWS_REGION=eu-central-1
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
```

## 3. Creazione del ruolo di esecuzione per la Lambda
Creare un file `trust-policy.json` con il seguente contenuto:
```bash
cat > trust-policy.json <<'EOF'
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": { "Service": "lambda.amazonaws.com" },
    "Action": "sts:AssumeRole"
  }]
}
EOF
```
Questo file definisce la policy di trust per il ruolo, consentendo al servizio Lambda di assumere il ruolo. Una volta creato il file, eseguire i comandi per creare il ruolo e allegare la policy di esecuzione base per Lambda:
```bash
aws iam create-role \
  --role-name lambda-lab-role \
  --assume-role-policy-document file://trust-policy.json

aws iam attach-role-policy \
  --role-name lambda-lab-role \
  --policy-arn arn:aws:iam::aws:policy/service-role/AWSLambdaBasicExecutionRole
```
Dopo la creazione del ruolo, attendere 10-15 secondi prima di creare la funzione.

## 4. Funzione stateless
Creare la funzione Lambda `lab-importi` con il seguente comando:
```bash
aws lambda create-function \
  --function-name lab-importi \
  --runtime java21 \
  --handler it.example.lab.ImportiHandler::handleRequest \
  --role arn:aws:iam::${ACCOUNT_ID}:role/lambda-lab-role \
  --zip-file fileb://target/lambda-lab-1.0.0.jar \
  --memory-size 512 \
  --timeout 15
```
La funzione `lab-importi` è ora disponibile per essere invocata. Si occupa di ricevere una richiesta con un importo e restituire un risultato con l'importo calcolato. 

## 5. Funzione schedulata
Creare la funzione Lambda `lab-report-schedulato` con il seguente comando:
```bash
aws lambda create-function \
  --function-name lab-report-schedulato \
  --runtime java21 \
  --handler it.example.lab.ReportSchedulatoHandler::handleRequest \
  --role arn:aws:iam::${ACCOUNT_ID}:role/lambda-lab-role \
  --zip-file fileb://target/lambda-lab-1.0.0.jar \
  --memory-size 512 \
  --timeout 30 \
  --environment "Variables={AMBIENTE=demo,SIMULA_ERRORE=false}"
```
La funzione `lab-report-schedulato` è ora disponibile per essere invocata. Si occupa di generare un report schedulato e può simulare un errore se la variabile d'ambiente `SIMULA_ERRORE` è impostata a `true`.

## 6. Test
Creare un file `evento-importi.json`:
```bash
cat > evento-importi.json <<'EOF'
{
  "importi": [10.50, 20.00, 5.25],
  "aliquotaIva": 22
}
EOF
```
Il file `evento-importi.json` contiene un array di importi e un'aliquota IVA. Questo file sarà utilizzato come payload per testare la funzione Lambda `lab-importi`.

### Invocazione sincrona:
Il comando seguente invoca la funzione Lambda `lab-importi` in modalità sincrona, passando il payload dal file `evento-importi.json`. La risposta viene salvata nel file `risposta.json` e i log vengono visualizzati in tempo reale.
```bash
aws lambda invoke \
  --function-name lab-importi \
  --cli-binary-format raw-in-base64-out \
  --payload file://evento-importi.json \
  --log-type Tail --query 'LogResult' --output text \
  risposta.json | base64 --decode
```
```bash
cat risposta.json
```
Risposta attesa: `{"numeroImporti":3,"imponibile":35.75,"iva":7.87,"totale":43.62,"requestId":"..."}`

### Invocazione asincrona
Il comando seguente invoca la funzione Lambda `lab-importi` in modalità asincrona, passando il payload dal file `evento-importi.json`.
```bash
aws lambda invoke \
  --function-name lab-importi \
  --invocation-type Event \
  --cli-binary-format raw-in-base64-out \
  --payload file://evento-importi.json \
  risposta-async.json
```
La CLI risponde subito con status 202 e non attende il risultato.
Il file `risposta-async.json` è vuoto, ma la funzione viene eseguita in background.

Log in tempo reale:
```bash
aws logs tail /aws/lambda/lab-importi --follow
```
### Retry e gestione errori

Si può attivare l'errore simulato e osservare i tentativi nei log:
```bash
aws lambda update-function-configuration \
  --function-name lab-report-schedulato \
  --environment "Variables={AMBIENTE=demo,SIMULA_ERRORE=true}"
```
Invocazione asincrona e visualizzazione dei log:
```bash
aws lambda invoke \
  --function-name lab-report-schedulato \
  --invocation-type Event \
  --cli-binary-format raw-in-base64-out \
  --payload '{"source":"test-manuale"}' \
  out.json

# Visualizzazione dei log in tempo reale:

aws logs tail /aws/lambda/lab-report-schedulato --follow
```
Per le invocazioni asincrone Lambda esegue di default 2 retry dopo il primo tentativo, quindi nei log compaiono 3 esecuzioni. Per limitarli o configurare una destinazione di fallimento:
```bash
aws lambda put-function-event-invoke-config \
  --function-name lab-report-schedulato \
  --maximum-retry-attempts 1 \
  --maximum-event-age-in-seconds 3600
```
A questo punto, se si invoca nuovamente la funzione con errore simulato, nei log compaiono solo 2 esecuzioni.

## 7. Schedulazione con EventBridge (alternativa al crontab)
### 1. Regola schedulata: ogni 5 minuti
```bash
aws events put-rule \
  --name lab-report-ogni-5-minuti \
  --schedule-expression "rate(5 minutes)"
```

### 2. Permesso a EventBridge di invocare la funzione
```bash
aws lambda add-permission \
  --function-name lab-report-schedulato \
  --statement-id eventbridge-invoke \
  --action lambda:InvokeFunction \
  --principal events.amazonaws.com \
  --source-arn arn:aws:events:${AWS_REGION}:${ACCOUNT_ID}:rule/lab-report-ogni-5-minuti
```

### 3. Collegamento regola → funzione
```bash
aws events put-targets \
  --rule lab-report-ogni-5-minuti \
  --targets "Id"="1","Arn"="arn:aws:lambda:${AWS_REGION}:${ACCOUNT_ID}:function:lab-report-schedulato"
```

## 8. Pulizia
```bash
aws events remove-targets --rule lab-report-ogni-5-minuti --ids 1
aws events delete-rule --name lab-report-ogni-5-minuti
aws lambda delete-function --function-name lab-importi
aws lambda delete-function --function-name lab-report-schedulato
aws logs delete-log-group --log-group-name /aws/lambda/lab-importi
aws logs delete-log-group --log-group-name /aws/lambda/lab-report-schedulato
aws iam detach-role-policy --role-name lambda-lab-role \
  --policy-arn arn:aws:iam::aws:policy/service-role/AWSLambdaBasicExecutionRole
aws iam delete-role --role-name lambda-lab-role
```