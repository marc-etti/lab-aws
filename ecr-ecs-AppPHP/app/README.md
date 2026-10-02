# AppPHP

## Albero progetto
```
progetto/
├── db/
│   └── init.sql
└── app/
    ├── config.php
    ├── layout.php
    ├── index.php
    ├── login.php
    ├── register.php
    ├── logout.php
    └── health.php
```

## Variabili d'ambiente usate
- DB_HOST: db (nome del servizio Compose)
- DB_PORT: 3306
- DB_NAME: todoapp
- DB_USER: todo
- DB_PASSWORD: da .env / Secrets Manager su AWS