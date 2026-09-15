# Produção — Clínica Loreto

Servidor: `2.25.130.51`. Código: `/var/www/html/clinicaloreto`.
URL: https://jbmj.io/clinicaloreto/

O Apache existente termina HTTPS e encaminha `/clinicaloreto/` para o Nginx
do Compose em `127.0.0.1:18090`. O banco PostgreSQL, PHP e o serviço de análise
ficam na rede interna do Compose. O scheduler executa as tarefas do Laravel.

## Configuração

Defina no `.env` do servidor:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://jbmj.io/clinicaloreto
APP_BASE_PATH=/clinicaloreto
APP_BIND=127.0.0.1
APP_PORT=18090
SESSION_SECURE_COOKIE=true
SESSION_PATH=/clinicaloreto
SESSION_COOKIE=clinicaloreto_session
TRUSTED_PROXIES=172.20.0.1
```

`APP_KEY`, `DB_PASSWORD` e `ADMIN_PASSWORD` são exclusivos e ficam no `.env`
protegido no servidor. Preserve a chave e os volumes nas atualizações.
O prefixo é incorporado à interface durante a compilação.

Configurações do Apache:

- `/etc/apache2/conf-available/clinicaloreto-proxy-vhost.conf`: proxy incluído no virtual host HTTPS de `jbmj.io`.
- `/etc/apache2/conf-available/clinicaloreto-source.conf`: bloqueia acesso direto ao código, inclusive por outros virtual hosts.
- Cópia anterior do virtual host em `/root/clinicaloreto-deploy-backup-20260915013210/`.

## Atualizações

Os ajustes para subdiretório estão no código local e no checkout do servidor.
Antes de atualizar, confira `git status` e preserve essas alterações; integre-as
ao repositório antes de uma atualização que altere os mesmos arquivos.

```sh
cd /var/www/html/clinicaloreto
git pull --ff-only
docker compose build
docker compose up -d db cognition app web
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan optimize
docker compose up -d scheduler
docker compose ps
```

Na instalação inicial, execute somente `php artisan db:seed --force`.
Ela cria os menus e o administrador; não execute `DemoSeeder` em produção.
Não utilize `migrate:fresh` nem `docker compose down -v`.

As credenciais e modelos da Meta ainda precisam ser informados no sistema
para habilitar WhatsApp. Callback: https://jbmj.io/clinicaloreto/api/whatsapp/webhook

## Verificação e backup

Instalação validada por HTTPS com login real no navegador e pelo script
`python3 deploy/verify-production.py`: um administrador e demais cadastros vazios,
cookies seguros com escopo `/clinicaloreto`, autenticação e proteção CSRF.
Os cinco serviços estão em execução; banco e serviço de análise estão saudáveis.

Backup inicial: `/var/backups/clinicaloreto/20260915T013741Z/`.
Inclui banco, armazenamento, ambiente e alterações da implantação. Os índices
do dump e do arquivo de armazenamento foram verificados. Para gerar outro:

```sh
cd /var/www/html/clinicaloreto
sh deploy/backup.sh
```

O backup está no próprio servidor; não foi configurada cópia externa nem
execução periódica. Uma restauração completa ainda não foi ensaiada.
