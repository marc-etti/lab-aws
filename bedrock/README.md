# Amazon Bedrock Tutorial

## Prerequisiti
- Un account AWS attivo.
- Python 3.9 o superiore installato sul tuo sistema.

## Procedimento
- Generare una Short-term API key per autenticarsi con Amazon Bedrock.
    - Accedi all'AWS Management Console con un'identità IAM che ha i permessi per utilizzare la console di Amazon Bedrock. Poi, apri la console di Amazon Bedrock all'indirizzo https://console.aws.amazon.com/bedrock.
    - Nella barra di navigazione sinistra, seleziona Rileva -> API keys.
    - Nella scheda delle API keys a breve termine, scegli Genera API keys a breve termine.
- Crea un ambiente virtuale e attivalo.
    ```bash
    python -m venv venv
    source venv/bin/activate
    ```
- Installa il SDK necessario per le API che hai intenzione di utilizzare.
    ```bash
    pip install boto3 openai
    ```

- Imposta le seguenti variabili d'ambiente per utilizzare la chiave API per l'autenticazione.
Nel nostro caso, utilizzeremo il modello OpenAI GPT-OSS-20B-1:0, quindi dobbiamo impostare le variabili d'ambiente per l'API OpenAI.
    ```bash
    export OPENAI_API_KEY="<provide your Bedrock API key>"
    export OPENAI_BASE_URL="https://bedrock-runtime.eu-central-1.amazonaws.com/openai/v1"
    ```

- Usa il seguente codice Python per eseguire la tua prima richiesta di inferenza.
    ```python
    from openai import OpenAI

    client = OpenAI()

    response = client.chat.completions.create(
        model="openai.gpt-oss-20b-1:0",
        messages=[
            {
                "role": "user",
                "content": "Puoi spiegare le features di Amazon Bedrock?",
            }
        ],
    )

    print(response.choices[0].message.content)
    ```

- Salva il file come `bedrock-first-request.py`.
- Esegui il file per ottenere la risposta del modello:
    ```bash
    python bedrock-first-request.py
    ```

## Esempi:
- [Esempio 0](00_bedrock-openai.py): Script di base per l'inferenza con Amazon Bedrock
- [Esempio 1](01_bedrock-openai.py): System prompt e parametri di generazione
- [Esempio 2](02_bedrock-openai.py): Conversazione multi-turn
- [Esempio 3](03_bedrock-openai.py): Output strutturato JSON
- [Esempio 4](04_bedrock-openai.py): Streaming della risposta