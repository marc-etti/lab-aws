# Amazon SQS Tutorial

## Prerequisiti
Verifica della configurazione aws cli:
```bash
aws configure
```
o in alternativa:
```bash
aws login
```
```bash
aws sts get-caller-identity
```
Settare la regione di default:
```bash
export AWS_REGION=eu-central-1
```

## 1. Coda Standard: operazioni di base

1. Creazione della coda
    ```bash
    aws sqs create-queue \
    --queue-name lab-standard-queue \
    --region $AWS_REGION
    ```

2. Salviamo l'URL restituito (necessario per tutte le operazioni successive):
    ```bash
    QUEUE_URL=$(aws sqs get-queue-url --queue-name lab-standard-queue --query 'QueueUrl' --output text)
    echo $QUEUE_URL
    ```

3. Invio di un messaggio
    ```bash
    aws sqs send-message \
        --queue-url $QUEUE_URL \
        --message-body "Primo messaggio di test - lab SQS"
    ```
    Vedremo un messaggio di conferma con il MessageId e l'MD5 del messaggio inviato.

4. Ricezione del messaggio
    ```bash
    aws sqs receive-message \
        --queue-url $QUEUE_URL \
        --max-number-of-messages 1 \
        --wait-time-seconds 0
    ```
    Vedremo il messaggio ricevuto, ma non sarà ancora eliminato dalla coda.

5. Eliminazione del messaggio
    Il messaggio NON viene rimosso automaticamente alla ricezione. Va cancellato esplicitamente usando il ReceiptHandle restituito:
    ```bash
    RECEIPT_HANDLE=$(aws sqs receive-message \
        --queue-url $QUEUE_URL \
        --max-number-of-messages 1 \
        --query 'Messages[0].ReceiptHandle' --output text)
    echo $RECEIPT_HANDLE
    ```
    ```bash
    aws sqs delete-message \
        --queue-url $QUEUE_URL \
        --receipt-handle "$RECEIPT_HANDLE"
    ```

## 2. Visibility Timeout
1. Osservare il comportamento della coda con il Visibility Timeout. Inviamo un messaggio e lo riceviamo con un timeout di 20 secondi:
    ```bash
    aws sqs send-message --queue-url $QUEUE_URL --message-body "Messaggio con timeout"
    ```
    ```bash
    aws sqs receive-message --queue-url $QUEUE_URL --visibility-timeout 20
    ```
    Subito dopo, riprovare a ricevere (senza cancellare): il messaggio non comparirà per 20 secondi. Attendere e ripetere dopo 20s: il messaggio torna visibile perché non è stato eliminato.
    ```
    aws sqs receive-message --queue-url $QUEUE_URL
    ```

2. Modificare dinamicamente il timeout

    Utile quando l'elaborazione richiede più tempo del previsto:
    ```bash
    aws sqs change-message-visibility \
        --queue-url $QUEUE_URL \
        --receipt-handle "$RECEIPT_HANDLE" \
        --visibility-timeout 60
    ```
    Simulare un consumer "lento" che riceve un messaggio, dorme 15 secondi (sleep 15), poi prova a cancellarlo. Se il visibility timeout della coda è impostato a 10s, il messaggio non sarà più visibile e non potrà essere cancellato. Invece, se il timeout è impostato a 60s, il messaggio sarà ancora visibile e potrà essere cancellato.
    Modificare il timeout della coda a 10 secondi:
    ```
    aws sqs set-queue-attributes \
        --queue-url $QUEUE_URL \
        --attributes VisibilityTimeout=10
    ```
    Osservare il comportamento del messaggio con il timeout di 10 secondi e poi con quello di 60 secondi.

## 3. Long Polling vs Short Polling
1. Short polling (default con wait-time 0)
    ```bash
    time aws sqs receive-message --queue-url $QUEUE_URL --wait-time-seconds 0
    ```
    Con coda vuota, la chiamata ritorna quasi immediatamente senza messaggi (possibile falso negativo: SQS interroga solo un sottoinsieme di server).

2. Long polling (wait-time > 0)
    ```bash
    time aws sqs receive-message --queue-url $QUEUE_URL --wait-time-seconds 20
    ```
    Aprire un secondo terminale e inviare un messaggio mentre il comando è in attesa:
    ```bash
    aws sqs send-message --queue-url $QUEUE_URL --message-body "Arrivato durante il long poll"
    ```
    Impostare il long polling come default della coda:
    ```bash
    aws sqs set-queue-attributes \
        --queue-url $QUEUE_URL \
        --attributes ReceiveMessageWaitTimeSeconds=20
    ```
## 4. Dead-Letter Queue (DLQ)
Scenario: un consumer che fallisce ripetutamente non deve bloccare la coda all'infinito; dopo N tentativi il messaggio va isolato per analisi.

1. Creare la DLQ
    ```bash
    aws sqs create-queue --queue-name lab-dlq --region $AWS_REGION
    ```
    ```bash
    DLQ_URL=$(aws sqs get-queue-url --queue-name lab-dlq --query 'QueueUrl' --output text)
    echo $DLQ_URL
    ```
    ```bash
    DLQ_ARN=$(aws sqs get-queue-attributes --queue-url $DLQ_URL \
        --attribute-names QueueArn --query 'Attributes.QueueArn' --output text)
    echo $DLQ_ARN
    ```
2. Collegare la DLQ alla coda principale
    ```bash
    aws sqs set-queue-attributes \
        --queue-url $QUEUE_URL \
        --attributes '{
            "RedrivePolicy": "{\"deadLetterTargetArn\":\"'"$DLQ_ARN"'\",\"maxReceiveCount\":\"3\"}"
        }'
    ```
    maxReceiveCount=3 significa: dopo 3 ricezioni senza cancellazione, il messaggio migra automaticamente nella DLQ.

3. Simulare un consumer che fallisce sempre
    ```bash
    aws sqs send-message --queue-url $QUEUE_URL --message-body "Messaggio destinato al fallimento"
    ```
    ```bash
    for i in 1 2 3; do
        echo "Tentativo $i"
        aws sqs receive-message --queue-url $QUEUE_URL --visibility-timeout 5
        sleep 6   
    done
    ```
    Lasciamo scadere il timeout senza cancellare -> simula elaborazione fallita

    Verifichiamo che il messaggio sia finito nella DLQ
    ```bash
    aws sqs receive-message --queue-url $DLQ_URL
    ```

## 5. Coda FIFO e ordinamento garantito
1. Creazione (il nome deve terminare in .fifo)
    ```bash
    aws sqs create-queue \
        --queue-name lab-queue.fifo \
        --attributes '{
            "FifoQueue": "true",
            "ContentBasedDeduplication": "true"
        }'
    ```
    Salviamo l'URL della coda FIFO:
    ```bash
    FIFO_URL=$(aws sqs get-queue-url --queue-name lab-queue.fifo --query 'QueueUrl' --output text)
    echo $FIFO_URL
    ```
2. Invio con MessageGroupId

    I messaggi con lo stesso MessageGroupId vengono consegnati in stretto ordine FIFO; gruppi diversi possono essere elaborati in parallelo.
    ```bash
    aws sqs send-message --queue-url $FIFO_URL \
        --message-body "Ordine 1" --message-group-id "cliente-A"

    aws sqs send-message --queue-url $FIFO_URL \
        --message-body "Ordine 2" --message-group-id "cliente-A"

    aws sqs send-message --queue-url $FIFO_URL \
        --message-body "Ordine 3" --message-group-id "cliente-A"
    ```
    Ricevendo in sequenza, verificare che l'ordine sia sempre 1-2-3, a differenza della coda Standard (dove l'ordine è best-effort).

## 6. Invio/ricezione in batch e attributi dei messaggi
1. Invio batch (riduce chiamate API e costi)
    ```bash
    aws sqs send-message-batch \
        --queue-url $QUEUE_URL \
        --entries '[
            {"Id":"1","MessageBody":"Batch msg 1"},
            {"Id":"2","MessageBody":"Batch msg 2"},
            {"Id":"3","MessageBody":"Batch msg 3"}
        ]'
    ```
2. Message attributes (metadati strutturati)
    ```bash
    aws sqs send-message \
        --queue-url $QUEUE_URL \
        --message-body "Ordine con priorità" \
        --message-attributes '{
            "Priority": {"DataType":"String","StringValue":"High"},
            "OrderId": {"DataType":"Number","StringValue":"1042"}
        }'
    ```
    ```bash
    aws sqs receive-message \
        --queue-url $QUEUE_URL \
        --message-attribute-names All
    ```
    Gli attributi permettono filtraggio/routing lato applicazione senza deserializzare l'intero body — utile per confrontare con SNS message filtering in laboratori successivi.

## 7. Pulizia delle risorse
```bash
aws sqs delete-queue --queue-url $QUEUE_URL
aws sqs delete-queue --queue-url $DLQ_URL
aws sqs delete-queue --queue-url $FIFO_URL
```
Verifica
```bash
aws sqs list-queues
```