<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\TicketVisitResource;
use App\Models\SupportTicket;
use App\Services\TicketVisitService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupportVisitController extends Controller
{
    public function __construct(private TicketVisitService $visits) {}

    /**
     * Registrar la llegada del técnico a la visita (check-in con geolocalización).
     */
    public function checkIn(Request $request, string $id)
    {
        return $this->geoAction($request, $id, fn ($ticket, $data) =>
            $this->visits->checkIn($ticket, $data, Auth::user()), 'Check-in registrado', 201);
    }

    /**
     * Registrar la salida del técnico (check-out).
     */
    public function checkOut(Request $request, string $id)
    {
        return $this->geoAction($request, $id, fn ($ticket, $data) =>
            $this->visits->checkOut($ticket, $data, Auth::user()), 'Check-out registrado', 200);
    }

    /**
     * Reporte de servicio / acta de conformidad con firma del cliente.
     */
    public function serviceReport(Request $request, string $id)
    {
        try {
            $ticket = SupportTicket::find($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'work_done' => 'required|string',
                'client_signed_name' => 'nullable|string|max:150',
                'client_signature' => 'nullable',
                'client_signature_file' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
                'parts' => 'nullable|array',
                'parts.*.stock_id' => 'required_with:parts|integer|exists:stocks,id',
                'parts.*.qty' => 'required_with:parts|integer|min:1',
                'photos' => 'nullable|array|max:10',
                'photos.*' => 'file|mimes:jpg,jpeg,png,webp|max:5120',
                'resolve' => 'nullable|boolean',
            ], [
                'client_signature_file.mimes' => 'La firma debe ser una imagen JPG, PNG o WEBP.',
                'client_signature_file.max' => 'La firma no debe superar los 5 MB.',
                'client_signature_file.uploaded' => 'No se pudo subir la firma. Intenta nuevamente.',
                'photos.max' => 'Puedes adjuntar un máximo de 10 fotos en el reporte.',
                'photos.*.mimes' => 'Las fotos del servicio deben estar en formato JPG, PNG o WEBP.',
                'photos.*.max' => 'Cada foto del servicio no debe superar los 5 MB.',
                'photos.*.uploaded' => 'No se pudo subir una de las fotos. Verifica que pese menos de 5 MB e intenta nuevamente.',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            // La firma puede llegar como archivo (multipart) o como cadena base64.
            $signature = $request->file('client_signature_file')
                ?? ($request->filled('client_signature') ? $request->input('client_signature') : null);

            $visit = $this->visits->createServiceReport(
                $ticket,
                $validator->validated(),
                $signature,
                $request->file('photos') ?? [],
                $request->input('parts', []),
                Auth::user(),
            );

            return ApiResponseClass::sendResponse(
                new TicketVisitResource($visit->load('technician')),
                'Reporte de servicio registrado',
                201
            );
        } catch (DomainException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al registrar el reporte', 500, [$e->getMessage()]);
        }
    }

    /**
     * Maneja check-in/check-out, que comparten validación de geolocalización.
     */
    private function geoAction(Request $request, string $id, callable $action, string $message, int $code)
    {
        try {
            $ticket = SupportTicket::find($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'at' => 'nullable|date',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $visit = $action($ticket, $validator->validated());

            return ApiResponseClass::sendResponse(
                new TicketVisitResource($visit->load('technician')),
                $message,
                $code
            );
        } catch (DomainException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al registrar la visita', 500, [$e->getMessage()]);
        }
    }
}
