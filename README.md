# Laboratorio Amazon Web Services

## Contenuti

- [ECR & ECS - App Python](ecr-ecs/README.md)

- [ECR & ECS - App PHP](ecr-ecs-AppPHP/README.md)

- [Elastic Beanstalk](Elastic-Beanstalk/README.md)

- [Lambda](lambda/README.md)

- [Amazon SQS](amazon-sqs/README.md)

- [Bedrock](bedrock/README.md)

- [Textract](textract/README.md)

## Configurazione AWS CLI

Per configurare l'AWS CLI, seguire i passaggi seguenti:

- Installare l'AWS CLI seguendo le istruzioni ufficiali: [Installazione AWS CLI](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html)

- Configurare le credenziali AWS utilizzando il comando `aws configure`. Verranno richiesti l'Access Key ID, il Secret Access Key, la regione predefinita e il formato di output predefinito. Assicurarsi di avere le credenziali corrette per il proprio account AWS.

- O in alternativa usare `aws login` se si utilizza AWS SSO per autenticarsi.

- Verificare la configurazione eseguendo il comando `aws sts get-caller-identity` per confermare che le credenziali siano corrette e che si stia utilizzando l'account AWS desiderato.

## Configurazione EB CLI (Elastic Beanstalk Command Line Interface)

Seguire le istruzioni ufficiali al link: [Installazione EB CLI](https://github.com/aws/aws-elastic-beanstalk-cli-setup)
```
git clone https://github.com/aws/aws-elastic-beanstalk-cli-setup.git
```
```
python ./aws-elastic-beanstalk-cli-setup/scripts/ebcli_installer.py
```
Dopo l'installazione, verificare che la EB CLI sia correttamente installata eseguendo il comando `eb --version`.
In caso contrario, assicurarsi che il percorso di installazione della EB CLI sia incluso nella variabile d'ambiente PATH.

In caso di problemi con la libreria `botocore`, eseguire il seguente comando per installare le dipendenze necessarie:
```
"$HOME/.ebcli-virtual-env/bin/python" -m pip install "botocore[crt]"
```

## Installazione Session Manager Plugin

Per accedere alle istanze EC2 tramite AWS Systems Manager Session Manager, è necessario installare il plugin Session Manager.
```
curl "https://s3.amazonaws.com/session-manager-downloads/plugin/latest/ubuntu_64bit/session-manager-plugin.deb" -o session-manager-plugin.deb
sudo dpkg -i session-manager-plugin.deb
```