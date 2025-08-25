<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Requests\DocumentLookupRequest;
use App\Http\Resources\DniResource;
use App\Http\Resources\RucResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DocumentLookupController extends Controller
{
    /**
     * Consultar información de DNI o RUC
     */
    public function lookup(DocumentLookupRequest $request): JsonResponse
    {
        $documentType = $request->validated('type');
        $documentNumber = $request->validated('document');
        $token = config('services.apisperu.token');

        if (!$token) {
            return ApiResponseClass::sendResponse(
                [],
                'Token de API no configurado',
                500
            );
        }

        try {
            // Construir URL según tipo de documento
            $baseUrl = 'https://dniruc.apisperu.com/api/v1';
            $url = "{$baseUrl}/{$documentType}/{$documentNumber}";

            // Realizar consulta a la API
            $response = Http::timeout(10)->get($url, [
                'token' => $token
            ]);

            // Verificar si la respuesta es exitosa
            if (!$response->successful()) {
                return $this->handleApiError($response, $documentType);
            }

            $data = $response->json();

            // Verificar si hay datos válidos
            if ($this->isValidResponse($data, $documentType)) {
                // Formatear respuesta según tipo de documento
                if ($documentType === 'dni') {
                    $resource = new DniResource($data);
                } else {
                    $resource = new RucResource($data);
                }

                return ApiResponseClass::sendResponse(
                    $resource->resolve(),
                    "Información de {$documentType} consultada exitosamente"
                );
            } else {
                return ApiResponseClass::sendResponse(
                    [],
                    "No se encontró información para el {$documentType} proporcionado",
                    404
                );
            }
        } catch (\Exception $e) {
            Log::error('Error en consulta de documento', [
                'type' => $documentType,
                'document' => $documentNumber,
                'error' => $e->getMessage()
            ]);

            return ApiResponseClass::sendResponse(
                [],
                'Error interno del servidor al consultar el documento',
                500
            );
        }
    }

    /**
     * Manejar errores de la API externa
     */
    private function handleApiError($response, $documentType): JsonResponse
    {
        $statusCode = $response->status();
        $errorMessage = 'Error al consultar la información';

        switch ($statusCode) {
            case 404:
                $errorMessage = "No se encontró información para el {$documentType} proporcionado";
                break;
            case 401:
                $errorMessage = "Token de autenticación inválido o expirado";
                break;
            case 429:
                $errorMessage = "Límite de consultas excedido, intente más tarde";
                break;
            case 500:
                $errorMessage = "Error en el servicio externo, intente más tarde";
                break;
        }

        Log::warning('Error en API externa', [
            'status_code' => $statusCode,
            'type' => $documentType,
            'response_body' => $response->body()
        ]);

        return ApiResponseClass::sendResponse([], $errorMessage, $statusCode);
    }

    /**
     * Verificar si la respuesta de la API es válida
     */
    private function isValidResponse(array $data, string $documentType): bool
    {
        if ($documentType === 'dni') {
            return !empty($data['dni']) && !empty($data['nombres']);
        } else {
            return !empty($data['ruc']) && !empty($data['razonSocial']);
        }
    }
}
