<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class AuthController extends Controller
{

    public function register(RegisterRequest $request)
    {
        try {
            $data = $request->validated();

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role_id' => $data['role_id'],
                'dni' => $data['dni'],
                'phone' => $data['phone'] ?? null,
            ]);

            if (!$user) {
                return ApiResponseClass::errorResponse('Error al crear el usuario');
            }
            $token = JWTAuth::claims([
                'token_version' => $user->token_version,
            ])->fromUser($user);

            return ApiResponseClass::sendResponse([
                'user' => new UserResource($user->load('role')),
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60
            ], 'Usuario creado exitosamente', 201);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error en el proceso de creación del usuario', 500, [$e->getMessage()]);
        }
    }
    public function login(LoginRequest $request)
    {
        try {
            $credentials = $request->validated();

            // Validar si el usuario esta activo y no esta eliminado
            $user = User::where('email', $credentials['email'])->first();
            if (!$user || !$user->is_active || $user->deleted_at) {
                return ApiResponseClass::errorResponse('Credenciales inválidas', 401);
            }


            // Verificar credenciales JWT
            if (!JWTAuth::attempt($credentials)) {
                return ApiResponseClass::errorResponse('Credenciales inválidas', 401);
            }


            $token = JWTAuth::claims(['token_version' => $user->token_version])->fromUser($user);

            return ApiResponseClass::sendResponse([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
                'user' => new UserResource($user),
            ], 'Usuario autenticado exitosamente', 200);
        } catch (\Exception $e) {

            return ApiResponseClass::errorResponse('Error al iniciar sesión', 500, [$e->getMessage()]);
        }
    }

    public function me()
    {
        $user = Auth::user();
        return $user
            ? ApiResponseClass::sendResponse(['user' => new UserResource($user->load('role'))], 'Usuario autenticado', 200)
            : ApiResponseClass::errorResponse('No autenticado', 401);
    }

    public function refresh()
    {
        try {
            $newToken = JWTAuth::parseToken()->refresh();
            $data = [
                'access_token' => $newToken,
                'token_type' => 'bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ];
            return ApiResponseClass::sendResponse($data, 'Token renovado exitosamente', 200);
        } catch (TokenInvalidException $e) {
            return ApiResponseClass::errorResponse('Token inválido.', 401);
        }
    }

    public function logout()
    {
        try {
            $user = Auth::user();
            $user->update(['token_version' => (int)$user->token_version + 1]);
            $data = ['message' => 'Sesión cerrada exitosamente.'];
            return ApiResponseClass::sendResponse($data, 'Sesión cerrada exitosamente.', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al cerrar sesión', 500, [$e->getMessage()]);
        }
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        try {
            $user = Auth::user();
            $data = $request->validated();

            Log::info('Cambio de contraseña iniciado', [
                'user_id' => $user->id,
                'current_password_provided' => !empty($data['current_password']),
                'new_password_provided' => !empty($data['password'])
            ]);

            Log::debug('Datos de cambio de contraseña', [
                'user_id' => $user->id,
                'current_password' => $data['current_password'] ?? null,
                'new_password' => $data['password'] ?? null
            ]);

            // Verificar que la contraseña actual sea correcta
            if (!Hash::check($data['current_password'], $user->password)) {
                Log::warning('Contraseña actual incorrecta', ['user_id' => $user->id]);
                return ApiResponseClass::errorResponse('La contraseña actual es incorrecta', 200);
            }

            // Actualizar la contraseña y incrementar token_version
            $user->update([
                'password' => Hash::make($data['password']),
                'token_version' => (int)$user->token_version + 1
            ]);

            Log::info('Contraseña actualizada exitosamente', ['user_id' => $user->id]);

            return ApiResponseClass::sendResponse(
                ['message' => 'Contraseña actualizada exitosamente'],
                'Contraseña actualizada exitosamente',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error al cambiar contraseña', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ApiResponseClass::errorResponse('Error al cambiar la contraseña', 500, [$e->getMessage()]);
        }
    }

    /**
     * Solicitar recuperación de contraseña para empleados
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Por seguridad, no revelamos si el email existe o no
            return ApiResponseClass::sendResponse([], 'Se ha enviado un enlace de recuperación si el correo existe en nuestro sistema.');
        }

        // Eliminar tokens anteriores para este email
        DB::table('password_resets')
            ->where('email', $request->email)
            ->delete();

        // Crear nuevo token
        $token = Str::random(60);

        // Guardar token en la base de datos
        DB::table('password_resets')->insert([
            'email' => $request->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now()
        ]);

        // Enviar correo con el token
        try {
            Mail::to($request->email)->send(new \App\Mail\UserResetPassword($token, $request->email, $user->name));

            return ApiResponseClass::sendResponse([], 'Se ha enviado un enlace de recuperación a tu correo electrónico.');
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al enviar el correo de recuperación.', 500, [$e->getMessage()]);
        }
    }

    /**
     * Validar token y restablecer contraseña para empleados
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed'
        ]);

        // Verificar si existe el token para el email
        $passwordReset = DB::table('password_resets')
            ->where('email', $request->email)
            ->first();

        if (!$passwordReset || !Hash::check($request->token, $passwordReset->token)) {
            return ApiResponseClass::errorResponse('Token inválido o expirado.', 400);
        }

        // Verificar si el token ha expirado (1 hora)
        if (Carbon::parse($passwordReset->created_at)->addHour()->isPast()) {
            DB::table('password_resets')->where('email', $request->email)->delete();
            return ApiResponseClass::errorResponse('El token ha expirado. Por favor solicita un nuevo enlace de recuperación.', 400);
        }

        // Actualizar la contraseña del usuario
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return ApiResponseClass::errorResponse('No se encontró ningún usuario con este correo electrónico.', 404);
        }

        $user->password = Hash::make($request->password);
        $user->token_version = (int)$user->token_version + 1;
        $user->save();

        // Eliminar el token usado
        DB::table('password_resets')->where('email', $request->email)->delete();

        return ApiResponseClass::sendResponse([], 'Contraseña actualizada correctamente.');
    }
}
