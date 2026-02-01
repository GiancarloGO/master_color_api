#!/bin/bash
# Script para verificar el estado del queue worker en producción (Render)

echo "🔍 Verificando configuración de Queue Worker en Docker..."
echo ""

# Verificar que Supervisor esté corriendo
echo "1️⃣ Verificando Supervisor..."
if supervisorctl status > /dev/null 2>&1; then
    echo "✅ Supervisor está corriendo"
    echo ""
    supervisorctl status
else
    echo "❌ Supervisor NO está corriendo"
    exit 1
fi

echo ""
echo "2️⃣ Verificando logs del queue worker..."
if [ -f /var/www/html/storage/logs/worker.log ]; then
    echo "✅ Log del worker encontrado"
    echo "Últimas 20 líneas:"
    tail -n 20 /var/www/html/storage/logs/worker.log
else
    echo "⚠️ Log del worker no encontrado aún (puede ser que no haya procesado trabajos)"
fi

echo ""
echo "3️⃣ Verificando trabajos en la cola..."
cd /var/www/html
php artisan queue:monitor

echo ""
echo "4️⃣ Verificando trabajos fallidos..."
php artisan queue:failed

echo ""
echo "✅ Verificación completada"
echo ""
echo "💡 Comandos útiles:"
echo "  - Ver estado: supervisorctl status"
echo "  - Reiniciar worker: supervisorctl restart laravel-worker"
echo "  - Ver logs en tiempo real: tail -f /var/www/html/storage/logs/worker.log"
echo "  - Procesar un trabajo manualmente: php artisan queue:work --once"
