# Clínica Loreto · Saúde e Ação

Sistema de gestão para atendimentos médicos gratuitos. Laravel 13 / PHP 8.5, React 19 / TypeScript, PostgreSQL 17, FastAPI, Nginx e Docker Compose.

## Acesso local

URL: **http://localhost:8090**

CPF inicial: **529.982.247-25**. A senha inicial é definida em `ADMIN_PASSWORD` no `.env`. A seed não redefine senhas de contas já existentes. Altere a senha no menu Configurações → Administradores após os testes.

Os dados de demonstração são sintéticos, identificados por “(teste)”, sem autorização de WhatsApp. Os pontos no mapa são coordenadas de demonstração, não endereços verificados.

## Subir em outra máquina

Pré-requisito: Docker com Compose v2. Copie `.env.example` para `.env`, gere uma chave `APP_KEY` no formato `base64:` + 32 bytes aleatórios codificados em Base64 e configure `DB_PASSWORD` e `ADMIN_PASSWORD` (mínimo 10 caracteres).

```sh
docker compose build
docker compose up -d db cognition app web
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
# Opcional: dados sintéticos para avaliação; não utilizar em produção.
docker compose exec app php artisan db:seed --class=DemoSeeder --force
docker compose up -d scheduler
```

`docker compose down` para serviços sem apagar os volumes. **Não use `down -v` se houver dados que precisam ser mantidos.** As fotos ficam no volume privado `storage`, e o banco no volume `database`.

## Funcionalidades

- Dashboard com atendimentos por mês, clientes novos, clientes totais e total de consultas finalizadas; gráfico diário, ocupação e fila.
- Clientes com CPF obrigatório, validação dos dígitos verificadores, unicidade, dados pessoais/endereço, vínculo de liderança, foto privada e histórico.
- Médicos com múltiplas especialidades, lideranças, especialidades e usuários com senha hash, nível e status.
- Agendamento por CPF e calendário: verde disponível, vermelho lotado, amarelo sem oferta. Remarcação e cancelamento de consultas.
- Fila ordenada por horário da confirmação e ID; transições agendada → confirmada → finalizada; presenças e finalizações apenas no dia da consulta. Atualização automática a cada 15 segundos.
- Ofertas de vagas em múltiplas datas e meses. Repetição usa o número do dia do mês; dias inexistentes são informados e ignorados. Ofertas já existentes nunca são sobrescritas silenciosamente.
- Cancelamento de uma data com realocação para próximas vagas do mesmo médico/especialidade. Operação atômica: sem capacidade para todos, nada é alterado. Confirmados/finalizados impedem cancelamento do dia.
- Relatórios por liderança, bairro → rua → endereço/telefone com impressão, consultas por data/especialidade/status, mapa OpenStreetMap.
- Meta Cloud API: credenciais criptografadas, modelos aprovados, aniversários, lembretes, comunicados, fila persistente, estados de entrega e descadastro via SAIR.
- Menus persistidos por seed e filtrados por nível de acesso. Administrador: acesso completo; recepção: clientes e agenda, demais cadastros somente leitura; gestor: mesmos acessos da recepção e relatórios.
- Interface responsiva com navegação inferior no celular e manifesto de aplicativo. Requer conexão com o servidor; não armazena cadastros no navegador e não oferece atendimento offline.

## Arquitetura e regras

React é compilado pelo Vite e servido no mesmo domínio pelo Nginx. Laravel usa sessões no PostgreSQL, cookies HttpOnly/SameSite e proteção CSRF. As APIs exigem sessão ativa, validam dados no servidor e aplicam permissões independentemente do menu. Fotos só são servidas por rota autenticada; arquivos executáveis/SVG não são aceitos. Auditoria registra ator, ação, entidade e ID, sem duplicar dados pessoais.

`BookingService` usa transações e bloqueios de linha para capacidade, duplicidade e realocação. Banco possui chaves estrangeiras e índice parcial de unicidade por cliente/vaga para consultas não canceladas. Exclusões de registros vinculados são impedidas. Finalizações são contadas pelo momento efetivo de conclusão; relatórios de consultas filtram a data da agenda.

FastAPI recebe apenas contagens agregadas de ocupação e fila. A análise usa regras explicáveis para sugerir abertura/divulgação de vagas; não faz diagnósticos, triagem clínica nem previsões estatísticas. A indisponibilidade desse serviço não impede o dashboard ou os atendimentos.

## WhatsApp

É necessário fornecer ID do telefone, token permanente, App Secret, token de verificação e nomes de modelos aprovados. A conexão real não foi ativada com as credenciais de teste.

Configure o callback público HTTPS em `/api/whatsapp/webhook`, assine o evento `messages` e use o mesmo token de verificação salvo no sistema. POSTs exigem `X-Hub-Signature-256`. Aniversário usa um parâmetro (nome), lembrete usa três (nome, data, especialidade), comunicado usa zero ou um (nome), conforme a configuração do modelo.

O scheduler prepara mensagens às 07:00 em America/Sao_Paulo e processa a fila a cada minuto. Clientes sem consentimento não entram na fila. Antes de enviar, o consentimento e a validade do lembrete são rechecados. Chaves únicas impedem duplicação do mesmo aniversário/lembrete/campanha. Falhas HTTP 5xx, timeout ou envio interrompido ficam como `uncertain`, sem reenvio automático; verifique na Meta para evitar mensagens duplicadas. Mensagens agendadas após as 07h não recebem o lembrete automático daquela manhã. Consultas realocadas aparecem no resultado da operação; a recepção deve comunicar a nova data.

## Endereços e mapas

A ficha permite informar latitude/longitude ou consultar o endereço no Nominatim. A consulta é explícita, limitada a uma por minuto por usuário e usa o User-Agent configurável `GEOCODE_USER_AGENT`. Confira resultados aproximados. Alterações de endereço invalidam coordenadas antigas. O mapa usa tiles públicos do OpenStreetMap; para grande volume, configure provedores adequados à carga. Fonte tipográfica externa: Google Fonts, com fallback local.

## Testes

Banco de testes separado, obrigatoriamente `loreto_test`. As credenciais são as do ambiente; o banco de demonstração não é usado pelos testes.

```sh
docker compose exec db psql -U loreto -d loreto -c 'CREATE DATABASE loreto_test;'
# Instale dependências de desenvolvimento no diretório backend antes de usar o bind mount.
cd backend
composer install
cd ..
docker compose run --rm --no-deps -v ./backend:/var/www/html -e APP_ENV=testing -e DB_DATABASE=loreto_test app php vendor/bin/phpunit
```

Testes de integração cobrem CPF, sessões, permissões, foto privada, excesso de vagas, duplicidade, ordem da fila, transições, remarcação, capacidade, realocação atômica, repetição, relatórios, criptografia, consentimento, deduplicação e assinatura do webhook. Chamadas externas são simuladas nos testes.

## VPS

O Compose é reutilizável em VPS Linux com Docker. Configure `APP_ENV=production`, domínio em `APP_URL`, `SESSION_SECURE_COOKIE=true`, senhas próprias e HTTPS num proxy reverso. A porta 8090 fica vinculada a 127.0.0.1 por padrão; banco, PHP e FastAPI não são publicados. Para acesso direto apenas na rede local de testes, ajuste `APP_BIND=0.0.0.0`, a regra de firewall e use o IP do computador. Para instalar como aplicativo no celular, use HTTPS.

Mantenha a mesma `APP_KEY` (necessária para ler credenciais criptografadas). Configure backups do PostgreSQL e do volume de fotos e teste a restauração antes de usar com dados reais. Não rode DemoSeeder em produção. Esta entrega executa localmente; não foi implantada em uma VPS, pois não foram fornecidos acesso e domínio.

Documentação oficial: [Laravel](https://laravel.com/framework/docs), [Meta Cloud API](https://developers.facebook.com/docs/whatsapp/cloud-api/), [Nominatim](https://operations.osmfoundation.org/policies/nominatim/).
#   c l i n i c a l o r e t o  
 