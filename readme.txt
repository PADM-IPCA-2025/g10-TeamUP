Como correr os microserviços

    Para cada microserviço, dentro da pasta do diretório, correr os seguintes comandos:

    docker compose up -d
    docker compose exec app php artisan migrate --seed

    No microserviço do RabbitMQ e API Gateway só é necessário correr o primeiro comando:

Como testar as funcionalidades

    Regista uma conta fazendo o pedido POST /api/auth/register.

    Vai a mailtrap.io.

    Faz login com os seguintes dados:

        Email: a12145@alunos.ipca.pt

        Password: MEI grupo10

    Abre a inbox e clica no link de confirmação do e-mail.

    Faz login na aplicação para obter o token de autenticação.

    Usa esse token para aceder aos restantes endpoints da API.

teste
