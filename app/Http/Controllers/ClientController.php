<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $query = Client::with('addresses');
            
            // Filtros
            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhere('email', 'ILIKE', "%{$search}%")
                      ->orWhere('identity_document', 'ILIKE', "%{$search}%");
                });
            }
            
            if ($request->has('client_type')) {
                $query->where('client_type', $request->get('client_type'));
            }
            
            if ($request->has('document_type')) {
                $query->where('document_type', $request->get('document_type'));
            }
            
            if ($request->has('verified')) {
                $verified = filter_var($request->get('verified'), FILTER_VALIDATE_BOOLEAN);
                if ($verified) {
                    $query->whereNotNull('email_verified_at');
                } else {
                    $query->whereNull('email_verified_at');
                }
            }
            
            // Paginación
            $perPage = $request->get('per_page', 15);
            $clients = $query->orderBy('created_at', 'desc')->paginate($perPage);
            
            return ApiResponseClass::sendResponse([
                'clients' => ClientResource::collection($clients->items()),
                'pagination' => [
                    'current_page' => $clients->currentPage(),
                    'last_page' => $clients->lastPage(),
                    'per_page' => $clients->perPage(),
                    'total' => $clients->total(),
                    'from' => $clients->firstItem(),
                    'to' => $clients->lastItem(),
                ]
            ], 'Lista de clientes');
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener los clientes', 500, [$e->getMessage()]);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreClientRequest $request)
    {
        try {
            $data = $request->validated();
            $data['password'] = Hash::make($data['password']);
            $data['email_verified_at'] = now(); // Admin creates verified clients
            
            $client = Client::create($data);
            $client->load('addresses');
            
            return ApiResponseClass::sendResponse(
                new ClientResource($client), 
                'Cliente creado exitosamente', 
                201
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al crear el cliente', 500, [$e->getMessage()]);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $client = Client::with(['addresses', 'orders'])->findOrFail($id);
            
            return ApiResponseClass::sendResponse(
                new ClientResource($client), 
                'Detalle del cliente'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Cliente no encontrado', 404, [$e->getMessage()]);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClientRequest $request, string $id)
    {
        try {
            $client = Client::findOrFail($id);
            $data = $request->validated();
            
            // Only hash password if provided
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }
            
            $client->update($data);
            $client->load('addresses');
            
            return ApiResponseClass::sendResponse(
                new ClientResource($client), 
                'Cliente actualizado exitosamente'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al actualizar el cliente', 500, [$e->getMessage()]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $client = Client::findOrFail($id);
            $client->delete();
            
            return ApiResponseClass::sendResponse(
                [], 
                'Cliente eliminado exitosamente'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al eliminar el cliente', 500, [$e->getMessage()]);
        }
    }

    /**
     * Restore a soft deleted client
     */
    public function restore(string $id)
    {
        try {
            $client = Client::withTrashed()->findOrFail($id);
            $client->restore();
            $client->load('addresses');
            
            return ApiResponseClass::sendResponse(
                new ClientResource($client), 
                'Cliente restaurado exitosamente'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al restaurar el cliente', 500, [$e->getMessage()]);
        }
    }

    /**
     * Force delete a client (permanent deletion)
     */
    public function forceDestroy(string $id)
    {
        try {
            $client = Client::withTrashed()->findOrFail($id);
            $client->forceDelete();
            
            return ApiResponseClass::sendResponse(
                [], 
                'Cliente eliminado permanentemente'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al eliminar permanentemente el cliente', 500, [$e->getMessage()]);
        }
    }

    /**
     * Get deleted clients
     */
    public function deleted(Request $request)
    {
        try {
            $query = Client::onlyTrashed()->with('addresses');
            
            $perPage = $request->get('per_page', 15);
            $clients = $query->orderBy('deleted_at', 'desc')->paginate($perPage);
            
            return ApiResponseClass::sendResponse([
                'clients' => ClientResource::collection($clients->items()),
                'pagination' => [
                    'current_page' => $clients->currentPage(),
                    'last_page' => $clients->lastPage(),
                    'per_page' => $clients->perPage(),
                    'total' => $clients->total(),
                    'from' => $clients->firstItem(),
                    'to' => $clients->lastItem(),
                ]
            ], 'Lista de clientes eliminados');
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener los clientes eliminados', 500, [$e->getMessage()]);
        }
    }

    /**
     * Toggle client verification status
     */
    public function toggleVerification(string $id)
    {
        try {
            $client = Client::findOrFail($id);
            
            if ($client->email_verified_at) {
                $client->email_verified_at = null;
                $message = 'Cliente marcado como no verificado';
            } else {
                $client->email_verified_at = now();
                $message = 'Cliente verificado exitosamente';
            }
            
            $client->save();
            $client->load('addresses');
            
            return ApiResponseClass::sendResponse(
                new ClientResource($client), 
                $message
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al cambiar el estado de verificación', 500, [$e->getMessage()]);
        }
    }
}