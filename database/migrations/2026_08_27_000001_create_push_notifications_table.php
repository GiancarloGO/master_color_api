<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial in-app de las notificaciones enviadas (push): respaldo para el
     * "centro de notificaciones" de la app aunque el push en sí no haya llegado
     * al dispositivo (FCM no configurado, token vencido, app cerrada, etc.).
     * Se llena desde PushNotificationService::sendToModel(), único punto por
     * el que ya pasan los 4 disparadores existentes (status, mensaje,
     * asignación, recordatorio de visita).
     */
    public function up(): void
    {
        Schema::create('push_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->string('type')->nullable();
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_notifications');
    }
};
