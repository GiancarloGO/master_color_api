<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Http\Resources\UserResource;
use App\Classes\ApiResponseClass;
use App\Services\AuditService;
use App\Services\UserService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService,
        private AuditService $audit,
    ) {}
    public function index()
    {
        try {
            $users = $this->userService->getAllUsers(15);
            return ApiResponseClass::sendResponse(
                UserResource::collection($users),
                'Lista de usuarios',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching users: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500);
        }
    }

    public function store(UserStoreRequest $request)
    {
        try {
            $user = $this->userService->createUser($request);

            $actor = Auth::user();
            if ($actor) {
                $this->audit->logStaffAction($actor, 'user.created', 'User', $user->id, null, [
                    'name'    => $user->name,
                    'email'   => $user->email,
                    'role_id' => $user->role_id,
                ]);
            }

            return ApiResponseClass::sendResponse(
                new UserResource($user),
                'Usuario creado exitosamente',
                201
            );

        } catch (ValidationException $e) {
            return ApiResponseClass::errorResponse(
                'Error de validación: ' . $e->getMessage(),
                422
            );
        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());
            return ApiResponseClass::errorResponse(
                'Error interno del servidor',
                500
            );
        }
    }

    public function show(string $id)
    {
        try {
            $user = $this->userService->getUserById((int) $id);
            
            if (!$user) {
                return ApiResponseClass::errorResponse('Usuario no encontrado', 404);
            }

            return ApiResponseClass::sendResponse(
                new UserResource($user),
                'Detalle de usuario',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching user: ' . $e->getMessage(), ['user_id' => $id]);
            return ApiResponseClass::errorResponse('Error interno del servidor', 500);
        }
    }

    public function update(UserUpdateRequest $request, string $id)
    {
        try {
            $user = $this->userService->updateUser($request, (int) $id);

            if (!$user) {
                return ApiResponseClass::errorResponse('Usuario no encontrado', 404);
            }

            $actor = Auth::user();
            if ($actor) {
                $this->audit->logStaffAction($actor, 'user.updated', 'User', $user->id, null, [
                    'name'    => $user->name,
                    'email'   => $user->email,
                    'role_id' => $user->role_id,
                ]);
            }

            return ApiResponseClass::sendResponse(
                new UserResource($user),
                'Usuario actualizado correctamente',
                200
            );

        } catch (ValidationException $e) {
            return ApiResponseClass::errorResponse(
                'Error de validación: ' . $e->getMessage(),
                422
            );
        } catch (\Exception $e) {
            Log::error('Error updating user: ' . $e->getMessage());
            return ApiResponseClass::errorResponse(
                'Error interno del servidor',
                500
            );
        }
    }

    public function destroy(string $id)
    {
        try {
            $targetUser = $this->userService->getUserById((int) $id);
            $deleted = $this->userService->deleteUser((int) $id);

            if (!$deleted) {
                return ApiResponseClass::errorResponse('Usuario no encontrado', 404);
            }

            $actor = Auth::user();
            if ($actor && $targetUser) {
                $this->audit->logStaffAction($actor, 'user.deleted', 'User', (int) $id, [
                    'name'  => $targetUser->name,
                    'email' => $targetUser->email,
                ]);
            }

            return ApiResponseClass::sendResponse(
                null,
                'Usuario eliminado correctamente',
                200
            );

        } catch (\Exception $e) {
            Log::error('Error deleting user: ' . $e->getMessage());
            return ApiResponseClass::errorResponse(
                'Error interno del servidor',
                500
            );
        }
    }

    public function resetPassword(string $id)
    {
        try {
            $user = $this->userService->getUserById((int) $id);
            
            if (!$user) {
                return ApiResponseClass::errorResponse('Usuario no encontrado', 404);
            }

            $newPassword = $this->generateSecurePassword();

            $user->password = Hash::make($newPassword);
            $user->save();

            Log::info('Password reset for user: ' . $user->email, ['admin_user' => auth()->user()?->email]);

            $actor = Auth::user();
            if ($actor) {
                $this->audit->logStaffAction($actor, 'user.password_reset', 'User', $user->id, null, null, [
                    'target_email' => $user->email,
                ]);
            }

            return ApiResponseClass::sendResponse(
                ['new_password' => $newPassword],
                'Contraseña restablecida exitosamente',
                200
            );

        } catch (\Exception $e) {
            Log::error('Error resetting user password: ' . $e->getMessage());
            return ApiResponseClass::errorResponse(
                'Error interno del servidor',
                500
            );
        }
    }

    private function generateSecurePassword(): string
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $specialChars = '!@#$%&*';
        
        $password = '';
        
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $specialChars[random_int(0, strlen($specialChars) - 1)];
        
        $allChars = $uppercase . $lowercase . $numbers . $specialChars;
        for ($i = 4; $i < 12; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }
        
        return str_shuffle($password);
    }
}
