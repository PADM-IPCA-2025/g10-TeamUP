#!/bin/bash

# Ordem definida dos microserviços (nomes de pasta reais)
servicos=("users-main" "event_manager" "chat" "notifications" "rabbit" "achievements" "rating" "websocket" "API_Gateway")

# Apenas os serviços Laravel que requerem migrate
laravel_servicos=("users-main" "event_manager" "chat" "notifications" "achievements" "rating")

echo "🚀 A iniciar microserviços na ordem definida..."

for s in "${servicos[@]}"; do
    if [ -f "$s/docker-compose.yml" ]; then
        echo "Iniciando $s..."
        cd "$s"
        docker compose up -d
        cd ..
    else
        echo "⚠️  Pasta $s ou docker-compose.yml não encontrado. A saltar..."
    fi
done

echo "🛠️ A correr migrations nos serviços Laravel..."

for s in "${laravel_servicos[@]}"; do
    if [ -f "$s/docker-compose.yml" ]; then
        echo "Migrating $s..."
        cd "$s"
        docker compose exec app php artisan migrate --seed
        cd ..
    else
        echo "⚠️  Pasta $s ou docker-compose.yml não encontrado. A saltar..."
    fi
done

echo "✅ Todos os serviços foram iniciados e migrados com sucesso!"
