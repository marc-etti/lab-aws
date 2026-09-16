# Amazon Textract tutorial

## Prerequisiti
### Creare un utente IAM
1. Accedere alla console di gestione AWS
2. Cercare "IAM" nella barra di ricerca e selezionare il servizio
3. Andare su "Utenti" e cliccare su "Crea utente"
4. Inserire un nome utente es. "textract-user"
5. Non abilitare l’accesso alla console AWS se l’utente verrà utilizzato soltanto da un’applicazione o dalla CLI.
6. Prosegui con "Successivo"
7. Seleziona Attach policies directly.
8. Cerca e seleziona la policy "AmazonTextractFullAccess"
9. Clicca su "Successivo" e poi su "Crea utente"
### Scaricare le credenziali dell’utente IAM
1. Apri l’utente appena creato.
2. Seleziona la scheda Security credentials.
3. Nella sezione Access keys, premi Create access key.
4. Seleziona il caso d’uso appropriato, ad esempio:
    - Command Line Interface (CLI), se utilizzerai AWS CLI;
    - Application running outside AWS, se le credenziali saranno usate da un’applicazione locale o da un server esterno.
5. Conferma l’avviso mostrato da AWS e premi Next.
6. Inserisci eventualmente una descrizione, per esempio sviluppo-textract.
7. Premi Create access key.
8. Salva:
    - Access Key ID
    - Secret Access Key
    
    Puoi copiarle oppure premere Download .csv.

La Secret Access Key viene mostrata una sola volta. Se la perdi, non può essere recuperata: devi disattivare la vecchia chiave e crearne una nuova.

### Installare e configurare AWS CLI e AWS SDK per python (boto3)
1. Installare AWS CLI: https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html
2. Configurare AWS CLI con le credenziali dell’utente IAM creato:
    ```bash
    aws configure --profile textract-user

    Tip: You can deliver temporary credentials to the AWS CLI using your AWS Console session by running the command 'aws login'.

    AWS Access Key ID [None]: ...
    AWS Secret Access Key [None]: ...
    Default region name [None]: eu-west-1
    Default output format [None]: json
    ```
3. Installare boto3:
    ```bash
    python -m venv venv
    source venv/bin/activate  # Linux/macOS
    pip install boto3
    ```
4. Creare un file `.env` nella root del progetto e inserire le credenziali dell’utente IAM:
    ```
    AWS_PROFILE=textract-user
    AWS_ACCESS_KEY_ID=123
    AWS_SECRET_ACCESS_KEY=123
    ```

## Estrarre coppie chiave-valore da un form document

Useremo le seguenti funzioni:
- get_kv_map : chiama l’API AnalyzeDocument e salva le coppie chiave-valore in un dizionario Python di tipo map
- get_kv_relationship e find_value_block : costruiscono le relazioni tra le chiavi e i valori a partire dal map

- `textract_python_kv_parser.py`:
    ```python
    import boto3
    import sys
    import re
    import json
    from collections import defaultdict


    def get_kv_map(file_name):
        with open(file_name, 'rb') as file:
            img_test = file.read()
            bytes_test = bytearray(img_test)
            print('Image loaded', file_name)

        # process using image bytes
        session = boto3.Session(profile_name='profile-name')
        client = session.client('textract', region_name='region')
        response = client.analyze_document(Document={'Bytes': bytes_test}, FeatureTypes=['FORMS'])

        # Get the text blocks
        blocks = response['Blocks']

        # get key and value maps
        key_map = {}
        value_map = {}
        block_map = {}
        for block in blocks:
            block_id = block['Id']
            block_map[block_id] = block
            if block['BlockType'] == "KEY_VALUE_SET":
                if 'KEY' in block['EntityTypes']:
                    key_map[block_id] = block
                else:
                    value_map[block_id] = block

        return key_map, value_map, block_map


    def get_kv_relationship(key_map, value_map, block_map):
        kvs = defaultdict(list)
        for block_id, key_block in key_map.items():
            value_block = find_value_block(key_block, value_map)
            key = get_text(key_block, block_map)
            val = get_text(value_block, block_map)
            kvs[key].append(val)
        return kvs


    def find_value_block(key_block, value_map):
        for relationship in key_block['Relationships']:
            if relationship['Type'] == 'VALUE':
                for value_id in relationship['Ids']:
                    value_block = value_map[value_id]
        return value_block


    def get_text(result, blocks_map):
        text = ''
        if 'Relationships' in result:
            for relationship in result['Relationships']:
                if relationship['Type'] == 'CHILD':
                    for child_id in relationship['Ids']:
                        word = blocks_map[child_id]
                        if word['BlockType'] == 'WORD':
                            text += word['Text'] + ' '
                        if word['BlockType'] == 'SELECTION_ELEMENT':
                            if word['SelectionStatus'] == 'SELECTED':
                                text += 'X '

        return text


    def print_kvs(kvs):
        for key, value in kvs.items():
            print(key, ":", value)


    def search_value(kvs, search_key):
        for key, value in kvs.items():
            if re.search(search_key, key, re.IGNORECASE):
                return value


    def main(file_name):
        key_map, value_map, block_map = get_kv_map(file_name)

        # Get Key Value relationship
        kvs = get_kv_relationship(key_map, value_map, block_map)
        print("\n\n== FOUND KEY : VALUE pairs ===\n")
        print_kvs(kvs)

        # Start searching a key value
        while input('\n Do you want to search a value for a key? (enter "n" for exit) ') != 'n':
            search_key = input('\n Enter a search key:')
            print('The value is:', search_value(kvs, search_key))

    if __name__ == "__main__":
        file_name = sys.argv[1]
        main(file_name)
    ```

- Modificare `profile-name` e `region` con i valori associati all'utente textract-user.
    - `profile-name` è `default`
    - `region` è `eu-west-1`.

- Nel terminale lanciare lo script passando come argomento il path del file PDF o immagine da analizzare:
    ```bash
    python textract_python_kv_parser.py path/to/file.pdf
    ```
