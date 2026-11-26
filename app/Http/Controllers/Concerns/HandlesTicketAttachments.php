<?php

namespace App\Http\Controllers\Concerns;

use App\Classes\ApiResponseClass;
use App\Http\Resources\TicketAttachmentResource;
use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

trait HandlesTicketAttachments
{
    /**
     * Valida y guarda los adjuntos de un ticket, compartiendo reglas y mensajes
     * amigables entre los endpoints de cliente y de personal.
     */
    protected function handleAttachmentUpload(Request $request, SupportTicket $ticket, Model $uploader)
    {
        $validator = Validator::make($request->all(), [
            'files' => 'required|array|min:1|max:5',
            'files.*' => 'file|mimes:jpg,jpeg,png,webp|max:5120',
            'message_id' => [
                'nullable',
                'integer',
                Rule::exists('ticket_messages', 'id')->where('ticket_id', $ticket->id),
            ],
        ], [
            'files.required' => 'Selecciona al menos una imagen para adjuntar.',
            'files.array' => 'El formato de archivos adjuntos no es válido.',
            'files.min' => 'Selecciona al menos una imagen para adjuntar.',
            'files.max' => 'Puedes adjuntar un máximo de 5 imágenes a la vez.',
            'files.*.file' => 'El archivo adjunto no es válido.',
            'files.*.mimes' => 'Las imágenes deben estar en formato JPG, PNG o WEBP.',
            'files.*.max' => 'Cada imagen no debe superar los 5 MB.',
            'files.*.uploaded' => 'No se pudo subir una de las imágenes. Verifica que pese menos de 5 MB e intenta nuevamente.',
            'message_id.exists' => 'El mensaje asociado no pertenece a este ticket.',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
        }

        try {
            $attachments = $this->tickets->addAttachments(
                $ticket,
                $request->file('files'),
                $uploader,
                $request->input('message_id')
            );

            return ApiResponseClass::sendResponse(
                TicketAttachmentResource::collection(collect($attachments)),
                'Adjuntos cargados',
                201
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 422);
        }
    }
}
