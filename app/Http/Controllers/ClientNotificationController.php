<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\PushNotificationResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientNotificationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $client = Auth::guard('client')->user();
            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $query = $client->notifications();

            if ($request->boolean('unread_only')) {
                $query->whereNull('read_at');
            }

            $notifications = $query->paginate($request->input('per_page', 20));

            return ApiResponseClass::sendPaginatedResponse(
                PushNotificationResource::collection($notifications),
                $notifications,
                'Mis notificaciones',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener notificaciones', 500, [$e->getMessage()]);
        }
    }

    public function unreadCount(Request $request)
    {
        try {
            $client = Auth::guard('client')->user();
            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $count = $client->notifications()->whereNull('read_at')->count();

            return ApiResponseClass::sendResponse(['unread_count' => $count], 'Notificaciones sin leer', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al contar notificaciones', 500, [$e->getMessage()]);
        }
    }

    public function markRead(Request $request, string $id)
    {
        try {
            $client = Auth::guard('client')->user();
            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $notification = $client->notifications()->where('id', $id)->first();
            if (!$notification) {
                return ApiResponseClass::errorResponse('Notificación no encontrada', 404);
            }

            if ($notification->read_at === null) {
                $notification->update(['read_at' => now()]);
            }

            return ApiResponseClass::sendResponse(
                new PushNotificationResource($notification),
                'Notificación marcada como leída',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al marcar la notificación', 500, [$e->getMessage()]);
        }
    }

    public function markAllRead(Request $request)
    {
        try {
            $client = Auth::guard('client')->user();
            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $client->notifications()->whereNull('read_at')->update(['read_at' => now()]);

            return ApiResponseClass::sendResponse([], 'Notificaciones marcadas como leídas', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al marcar notificaciones', 500, [$e->getMessage()]);
        }
    }
}
