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