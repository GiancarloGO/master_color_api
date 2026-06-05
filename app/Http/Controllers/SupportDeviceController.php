<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupportDeviceController extends Controller
{
    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $validator = Validator::make($request->all(), [
                'token' => 'required|string',
                'platform' => 'required|in:android,ios',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            DeviceToken::updateOrCreate(
                ['token' => $request->token],
                [
                    'tokenable_type' => User::class,
                    'tokenable_id' => $user->id,
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
            $user = Auth::user();
            if (!$user) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $user->deviceTokens()->where('token', $token)->delete();

            return ApiResponseClass::sendResponse([], 'Token eliminado', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al eliminar token', 500, [$e->getMessage()]);
        }
    }
}
