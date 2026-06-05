<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Models\Client;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ClientDeviceController extends Controller
{
    public function store(Request $request)
    {
        try {
            $client = Auth::guard('client')->user();
            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $validator = Validator::make($request->all(), [
                'token' => 'required|string',
                'platform' => 'required|in:android,ios',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            // El token se (re)asigna al cliente actual aunque antes fuera de otro dueño.
            DeviceToken::updateOrCreate(
                ['token' => $request->token],
                [
                    'tokenable_type' => Client::class,
                    'tokenable_id' => $client->id,
                    'platform' => $request->platform,
                    'last_used_at' => now(),
                ]
            );

            return ApiResponseClass::sendResponse([], 'Token registrado', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al registrar token', 500, [$e->getMessage()]);
        }
    }

    public function destroy(Request $request, string $token)
    {
        try {
            $client = Auth::guard('client')->user();
            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $client->deviceTokens()->where('token', $token)->delete();

            return ApiResponseClass::sendResponse([], 'Token eliminado', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al eliminar token', 500, [$e->getMessage()]);
        }
    }
}
